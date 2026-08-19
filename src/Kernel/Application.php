<?php

declare(strict_types=1);

namespace PhpOrbit\Kernel;

use Closure;
use PhpOrbit\Container\Container;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Exception\MalformedRequest;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Middleware\Middleware;
use PhpOrbit\Middleware\Pipeline;
use PhpOrbit\Routing\MatchResult;
use PhpOrbit\Routing\Outcome;
use PhpOrbit\Routing\Route;
use PhpOrbit\Routing\RouteCollection;
use PhpOrbit\Routing\Router;
use Throwable;

/**
 * The application, booted once and then used to serve many requests.
 *
 * The two phases are separated by construction: everything mutable is consumed
 * by {@see boot()} and only immutable state survives into {@see handle()}. A
 * worker process therefore cannot accumulate anything across requests, because
 * after boot there is nothing left to accumulate into.
 */
final class Application
{
    /**
     * @param list<Middleware> $middleware
     */
    private function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly array $middleware,
        private readonly bool $debug,
    ) {
    }

    /**
     * Runs the boot phase.
     *
     * The callback registers services, middleware and routes on the
     * {@see Blueprint}. When it returns, the container is frozen and the route
     * table compiled — both permanently.
     *
     * @param Closure(Blueprint): void $define
     */
    public static function boot(Closure $define, bool $debug = false): self
    {
        $container = new Container();
        $blueprint = new Blueprint($container, new RouteCollection(), $debug);

        $define($blueprint);

        $router = $blueprint->routes->compile();
        $middleware = $blueprint->middlewareStack();

        $container->freeze();

        return new self($container, $router, $middleware, $debug);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Serves one request.
     *
     * The request scope is closed in a finally block, so resources are released
     * and per-request state is dropped even when the handler throws. This is
     * the single most important line in the framework for worker safety.
     */
    public function handle(ServerRequest $request): Response
    {
        // Captured before middleware can hand a modified request down the
        // chain, because the body-stripping rule follows the original method.
        $isHead = $request->method === Method::Head;

        $scope = $this->container->enterRequest();

        try {
            $response = $this->pipeline($request, $scope);
        } catch (MalformedRequest $e) {
            $response = $this->clientError($e);
        } catch (Throwable $e) {
            $response = $this->serverError($e);
        } finally {
            $scope->close();
        }

        // A HEAD response must be byte-identical to the GET one minus the body.
        return $isHead ? $response->withBody('') : $response;
    }

    /**
     * Routes first, then runs middleware around the dispatch.
     *
     * Matching before the pipeline lets a layer see which route was chosen —
     * that is how CSRF honours a route's exemption — while still running for
     * requests that matched nothing, so logging and auditing see 404s too.
     */
    private function pipeline(ServerRequest $request, RequestScope $scope): Response
    {
        // Handlers and middleware may depend on the scope itself; providing it
        // here means they can declare it as a constructor dependency.
        $scope->provide(RequestScope::class, $scope);

        $this->scheduleUploadCleanup($request, $scope);

        $match = $this->router->match($request);
        $route = $match->route;

        if ($route !== null) {
            $scope->provide(Route::class, $route);
            $request = $request->withAttributes($match->parameters);
        }

        $middleware = $route === null
            ? $this->middleware
            : [...$this->middleware, ...$route->middleware];

        return Pipeline::run(
            $middleware,
            $request,
            $scope,
            fn (ServerRequest $r): Response => $this->dispatch($r, $scope, $match),
        );
    }

    /**
     * Arranges for temporary upload files to be removed when the request ends.
     *
     * Done here rather than in middleware because uploads exist from the
     * moment the request is built: an application that forgot to register an
     * upload-handling layer would otherwise fill its temp directory. Files a
     * handler moved are left alone, and the scope closes in a finally block,
     * so the cleanup also runs when a handler throws.
     */
    private function scheduleUploadCleanup(ServerRequest $request, RequestScope $scope): void
    {
        $files = $request->files();

        if ($files === []) {
            return;
        }

        $scope->onClose(static function () use ($files): void {
            foreach ($files as $file) {
                $file->discard();
            }
        });
    }

    private function dispatch(ServerRequest $request, RequestScope $scope, MatchResult $match): Response
    {
        if ($match->outcome === Outcome::NotFound) {
            return Response::text('Not Found', Status::NotFound);
        }

        if ($match->outcome === Outcome::MethodNotAllowed) {
            return Response::text('Method Not Allowed', Status::MethodNotAllowed)
                ->withHeader('Allow', implode(', ', array_map(
                    static fn (Method $m): string => $m->value,
                    $match->allowedMethods,
                )));
        }

        $route = $match->route;

        assert($route !== null, 'Outcome::Found always carries a route.');

        // Published last so it carries the route parameters and any changes
        // middleware made on the way in.
        if (!$scope->provided(ServerRequest::class)) {
            $scope->provide(ServerRequest::class, $request);
        }

        return $route->invoke($request, $scope);
    }

    private function clientError(MalformedRequest $e): Response
    {
        return Response::text(
            $this->debug ? $e->getMessage() : 'Bad Request',
            Status::BadRequest,
        );
    }

    /**
     * Renders an unexpected failure.
     *
     * Outside debug mode the response says nothing about the cause: exception
     * messages routinely contain file paths, SQL and credentials.
     */
    private function serverError(Throwable $e): Response
    {
        if (!$this->debug) {
            return Response::text('Internal Server Error', Status::InternalServerError);
        }

        return Response::text(
            sprintf(
                "%s: %s\n\nin %s:%d\n\n%s",
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
            ),
            Status::InternalServerError,
        );
    }
}
