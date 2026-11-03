<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AccountSecurity;
use App\Support\AuthPage;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\Mfa;
use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;

final class MfaEnableController implements Handler
{
    public function __construct(
        private readonly AccountSecurity $security, private readonly Mfa $mfa, private readonly AuthPage $page,
        private readonly Session $session, private readonly Encrypter $encrypter, private readonly Authenticator $auth,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        try {
            $user = $this->security->confirm($request);
            $encrypted = $this->session->get('_totp_pending') ?? '';
            if (($this->session->getInt('_totp_expires') ?? 0) <= time() || $encrypted === '') {
                throw new AuthenticationFailed('Enrollment expired. Start again from account security.');
            }
            $secret = $this->encrypter->decrypt($encrypted, 'enroll:' . $user->authIdentifier());
            $codes = $this->mfa->enable($user->authIdentifier(), $secret, trim($request->form('code') ?? ''));
        } catch (AuthenticationFailed $exception) {
            return $this->page->back('/account/security', $exception->getMessage(), true);
        }
        $this->session->remove('_totp_pending');
        $this->session->remove('_totp_expires');
        $this->auth->login($user);

        return $this->page->render('auth/recovery', 'Save your recovery codes', ['codes' => $codes]);
    }
}
