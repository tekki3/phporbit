<?php

declare(strict_types=1);

namespace App\AuthKit\Controllers;

use App\AuthKit\AuthPage;
use App\AuthKit\Registration;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;

final class VerifySubmitController implements Handler
{
    public function __construct(private readonly AuthPage $page, private readonly Registration $registration, private readonly Session $session)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        $token = $this->session->get('_registration_token') ?? '';
        $this->session->remove('_registration_token');
        try {
            $this->registration->verify($token);
        } catch (AuthenticationFailed $exception) {
            return $this->page->back('/register', $exception->getMessage(), true);
        }

        return $this->page->back('/login', 'Email verified. You can now sign in.');
    }
}
