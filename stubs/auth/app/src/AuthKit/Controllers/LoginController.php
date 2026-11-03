<?php

declare(strict_types=1);

namespace App\AuthKit\Controllers;

use App\AuthKit\AuthPage;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\Oidc\OidcProviders;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class LoginController implements Handler
{
    public function __construct(private readonly AuthPage $page, private readonly Authenticator $auth, private readonly OidcProviders $providers)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->auth->check() ? Response::redirect('/account')
            : $this->page->render('login', 'Sign in', ['providers' => $this->providers->names()]);
    }
}
