<?php

declare(strict_types=1);

namespace App\AuthKit\Controllers;

use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class LogoutController implements Handler
{
    public function __construct(private readonly Authenticator $auth)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        $this->auth->logout();

        return Response::redirect('/login')->withHeader('Cache-Control', 'no-store');
    }
}
