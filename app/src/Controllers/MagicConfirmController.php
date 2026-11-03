<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\AuthPage;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;
use PhpOrbit\Security\Csrf;

final class MagicConfirmController implements Handler
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
            $this->session->set('_magic_token', preg_match('/^[a-f0-9]{64}$/D', $token) === 1 ? $token : '');

            return Response::redirect('/magic/confirm')->withHeader('Cache-Control', 'no-store');
        }

        return $this->page->render('auth/magic-confirm', 'Confirm sign-in');
    }
}
