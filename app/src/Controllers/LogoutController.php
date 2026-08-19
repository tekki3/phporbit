<?php

declare(strict_types=1);

namespace App\Controllers;

use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

/**
 * Signs the user out.
 *
 * A POST, not a GET: a link that logs someone out can be triggered from
 * anywhere, and CSRF protection only applies to state-changing methods.
 */
final class LogoutController implements Handler
{
    public function __construct(
        private readonly Authenticator $auth,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $this->auth->logout();

        return Response::redirect('/login');
    }
}
