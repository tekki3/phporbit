<?php

declare(strict_types=1);

namespace App\Controllers;

use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Auth\LoginFlow;
use App\Support\AuthPage;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Session\Session;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Validation\Validator;

/**
 * Processes a sign-in.
 *
 * The failure message never distinguishes "no such account" from "wrong
 * password". Saying which would turn the form into a way to discover who has
 * an account here.
 */
final class LoginAttemptController implements Handler
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
        private readonly LoginFlow $flow,
        private readonly AuthPage $page,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $validator = Validator::forRequest($request)
            ->required('email')
            ->email('email')
            ->required('password');

        $email = $validator->value('email') ?? '';

        if ($validator->fails()) {
            return $this->back(implode(' ', $validator->errors()), $email);
        }

        $key = $this->throttleKey($email);

        if ($this->throttle->tooManyAttempts($key)) {
            return $this->back(sprintf(
                'Too many attempts. Try again in %d seconds.',
                $this->throttle->retryAfter($key),
            ), $email);
        }

        $this->throttle->record($key);
        $user = $this->auth->validateCredentials($email, $request->form('password') ?? '');
        if ($user === null) {

            return $this->back('Those credentials do not match our records.', $email);
        }

        $this->throttle->clear($key);
        $this->flow->begin($user);

        return $this->flow->pending() ? Response::redirect('/mfa') : $this->page->signedIn();
    }

    /**
     * Account-wide so changing clients or spoofing forwarding headers cannot reset it.
     */
    private function throttleKey(string $email): string
    {
        return 'password:' . mb_strtolower($email);
    }

    private function back(string $error, string $email): Response
    {
        $this->session->flash('error', $error);
        $this->session->flash('old_email', $email);

        return Response::redirect('/login');
    }
}
