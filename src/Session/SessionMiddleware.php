<?php

declare(strict_types=1);

namespace PhpOrbit\Session;

use Closure;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Http\Cookie;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\SameSite;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Middleware\Middleware;

/**
 * Loads a session before the handler and writes it back afterwards.
 *
 * A session file is only created once something is actually stored, so an
 * anonymous visitor who touches a page does not leave one behind.
 *
 * The middleware object itself is shared across every request in a worker; all
 * per-request state lives in the {@see Session} it publishes to the scope.
 */
final class SessionMiddleware implements Middleware
{
    public function __construct(
        private readonly SessionStore $store,
        private readonly string $cookieName = 'orbit_session',
        private readonly int $lifetimeSeconds = 7200,
        private readonly SameSite $sameSite = SameSite::Lax,
    ) {
    }

    public function process(ServerRequest $request, RequestScope $scope, Closure $next): Response
    {
        $session = $this->load($request);
        $originalId = $session->id();

        $scope->provide(Session::class, $session);

        $response = $next($request);

        return $this->persist($request, $response, $session, $originalId);
    }

    private function load(ServerRequest $request): Session
    {
        $id = $request->cookie($this->cookieName);

        // An id that fails the format check never reaches the store, so a
        // crafted cookie cannot be used to probe the filesystem.
        if ($id === null || !Session::isValidId($id)) {
            return Session::started();
        }

        $data = $this->store->read($id);

        // A cookie pointing at a session that no longer exists must not adopt
        // that id: honouring it is what makes session fixation possible.
        return $data === null
            ? Session::started()
            : new Session($id, $data, isNew: false);
    }

    private function persist(
        ServerRequest $request,
        Response $response,
        Session $session,
        string $originalId,
    ): Response {
        if ($session->isDestroyed()) {
            $this->store->destroy($originalId);

            return $response->withCookie(Cookie::expired(
                $this->cookieName,
                secure: $request->uri->isSecure(),
            ));
        }

        // Regeneration leaves the old file behind unless it is removed here,
        // which would keep the pre-login id valid.
        if ($session->id() !== $originalId) {
            $this->store->destroy($originalId);
        }

        if (!$session->isDirty()) {
            return $response;
        }

        $this->store->write($session->id(), $session->all(), $this->lifetimeSeconds);

        return $response->withCookie(Cookie::forRequest(
            $request,
            $this->cookieName,
            $session->id(),
            expires: time() + $this->lifetimeSeconds,
            sameSite: $this->sameSite,
        ));
    }
}
