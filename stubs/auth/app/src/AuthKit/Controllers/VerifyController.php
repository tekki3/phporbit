<?php

declare(strict_types=1);

namespace App\AuthKit\Controllers;

use App\AuthKit\AuthPage;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;

final class VerifyController implements Handler
{
    public function __construct(private readonly AuthPage $page, private readonly Session $session)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        $token = $request->uri->queryParam('token');
        if ($token !== null) {
            $this->session->regenerate();
            Csrf::rotate($this->session);
            $this->session->set('_registration_token', preg_match('/^[a-f0-9]{64}$/D', $token) === 1 ? $token : '');

            return Response::redirect('/register/verify')->withHeader('Cache-Control', 'no-store');
        }

        return $this->page->render('verify', 'Verify your email');
    }
}
