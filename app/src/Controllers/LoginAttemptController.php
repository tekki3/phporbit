<?php

declare(strict_types=1);

namespace App\Controllers;

use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Auth\RequireAuthentication;
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

        $key = $this->throttleKey($request, $email);

        if ($this->throttle->tooManyAttempts($key)) {
            return $this->back(sprintf(
                'Too many attempts. Try again in %d seconds.',
                $this->throttle->retryAfter($key),
            ), $email);
        }

        if (!$this->auth->attempt($email, $validator->validated('password'))) {
            $this->throttle->record($key);

            return $this->back('Those credentials do not match our records.', $email);
        }

        $this->throttle->clear($key);

        // attempt() regenerated the session, so anything written before this
        // point belongs to the pre-login session and is gone.
        $this->session->flash('notice', 'Signed in.');

        $intended = $this->session->get(RequireAuthentication::INTENDED_KEY);
        $this->session->remove(RequireAuthentication::INTENDED_KEY);

        // Only ever redirect to a path of our own; an absolute URL here would
        // be an open redirect.
        $destination = $intended !== null && str_starts_with($intended, '/') && !str_starts_with($intended, '//')
            ? $intended
            : '/notes';

        return Response::redirect($destination);
    }

    /**
     * Keys the throttle on the account *and* the caller.
     */
    private function throttleKey(ServerRequest $request, string $email): string
    {
        return mb_strtolower($email) . '|' . ($request->headers->first('X-Forwarded-For') ?? 'local');
    }

    private function back(string $error, string $email): Response
    {
        $this->session->flash('error', $error);
        $this->session->flash('old_email', $email);

        return Response::redirect('/login');
    }
}
