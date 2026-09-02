<?php

declare(strict_types=1);

namespace App\Middleware;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Middleware\Middleware;
use PhpOrbit\View\TemplateEngine;
use Throwable;

/**
 * Replaces the framework's plain-text error responses with the demo's own
 * HTML pages.
 *
 * The framework itself never renders HTML for a 404, a CSRF rejection or an
 * uncaught exception — see {@see \PhpOrbit\Kernel\Application::dispatch()}
 * and {@see \PhpOrbit\Kernel\Application::serverError()} — because `src/` has
 * no knowledge of an application's templates. This is purely a demo-app
 * concern, layered on as ordinary middleware.
 *
 * Registered first, ahead of {@see \PhpOrbit\Log\LogRequests}, so a thrown
 * exception is still logged with its full detail by that layer before this
 * one turns it into an opaque page. Because this middleware always returns a
 * `Response`, `Application::handle()`'s own catch block never has to.
 */
final class HtmlErrorPages implements Middleware
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly bool $debug,
    ) {
    }

    public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            return $this->render(Status::InternalServerError, $e);
        }

        if ($this->page($response->status) === null) {
            return $response;
        }

        $rendered = $this->render($response->status);
        $allow = $response->headers->first('Allow');

        return $allow === null ? $rendered : $rendered->withHeader('Allow', $allow);
    }

    /**
     * The heading and message for a status this page dresses up, or null for
     * everything else — including the JSON 401 `RequireAuthentication` sends
     * to non-browser clients, which is deliberately left alone.
     *
     * @return array{string, string}|null
     */
    private function page(Status $status): ?array
    {
        return match ($status) {
            Status::BadRequest => ['Bad request', 'The server could not understand this request.'],
            Status::Forbidden => ['Request rejected', 'This request could not be verified. Go back and try again.'],
            Status::NotFound => ['Page not found', 'There is nothing at this address.'],
            Status::MethodNotAllowed => ['Method not allowed', 'This address does not accept that request method.'],
            Status::InternalServerError => ['Something went wrong', 'An unexpected error stopped this page from loading.'],
            default => null,
        };
    }

    private function render(Status $status, ?Throwable $e = null): Response
    {
        $page = $this->page($status);

        assert($page !== null, 'render() is only called for a status page() recognises.');

        [$heading, $message] = $page;

        return $this->view->respond('errors/error', [
            'title' => $heading,
            'heading' => $heading,
            'message' => $message,
            // Never shown outside debug: exception messages routinely carry
            // file paths, SQL and credentials. Same format as the framework's
            // own opaque-outside-debug fallback in Application::serverError().
            'debugDetail' => $this->debug && $e !== null ? $this->describe($e) : null,
        ], $status);
    }

    private function describe(Throwable $e): string
    {
        return sprintf(
            "%s: %s\n\nin %s:%d\n\n%s",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );
    }
}
