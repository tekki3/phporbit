<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AccountSecurity;
use App\Support\AuthPage;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\Mfa;
use PhpOrbit\Auth\Totp;
use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;

final class MfaEnrollController implements Handler
{
    public function __construct(
        private readonly AccountSecurity $security, private readonly Mfa $mfa, private readonly AuthPage $page,
        private readonly Session $session, private readonly Encrypter $encrypter,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        try {
            $user = $this->security->confirm($request);
            if ($this->mfa->enabled($user->authIdentifier())) {
                throw new AuthenticationFailed('MFA is already enabled. Disable it before enrolling another authenticator.');
            }
        } catch (AuthenticationFailed $exception) {
            return $this->page->back('/account/security', $exception->getMessage(), true);
        }
        $secret = Totp::secret();
        $this->session->set('_totp_pending', $this->encrypter->encrypt($secret, 'enroll:' . $user->authIdentifier()));
        $this->session->set('_totp_expires', time() + 600);

        return $this->page->render('auth/enroll', 'Set up an authenticator', [
            'secret' => $secret, 'authenticatorUri' => Totp::uri($secret, $user->authIdentifier()),
        ]);
    }
}
