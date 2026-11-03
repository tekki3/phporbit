<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AccountSecurity;
use App\Support\AuthPage;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\Mfa;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;

final class MfaDisableController implements Handler
{
    public function __construct(
        private readonly AccountSecurity $security, private readonly Mfa $mfa,
        private readonly Authenticator $auth, private readonly AuthPage $page,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        try {
            $user = $this->security->confirm($request, factor: false);
            $this->mfa->disable($user->authIdentifier(), trim($request->form('code') ?? ''));
        } catch (AuthenticationFailed $exception) {
            return $this->page->back('/account/security', $exception->getMessage(), true);
        }
        $this->auth->login($user);

        return $this->page->back('/account/security', 'Authenticator disabled and recovery codes revoked. Other sessions must sign in again.');
    }
}
