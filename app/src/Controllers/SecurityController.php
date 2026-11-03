<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AuthPage;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\Mfa;
use PhpOrbit\Auth\Oidc\ExternalAccounts;
use PhpOrbit\Auth\Oidc\OidcProviders;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class SecurityController implements Handler
{
    public function __construct(
        private readonly AuthPage $page, private readonly Authenticator $auth, private readonly Mfa $mfa,
        private readonly ExternalAccounts $accounts, private readonly OidcProviders $providers,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $id = $this->auth->user()?->authIdentifier() ?? '';

        return $this->page->render('auth/security', 'Account security', [
            'enabled' => $this->mfa->enabled($id), 'linked' => $this->accounts->linked($id),
            'providers' => $this->providers->names(),
        ]);
    }
}
