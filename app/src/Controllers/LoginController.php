<?php

declare(strict_types=1);

namespace App\Controllers;

use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class LoginController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Session $session,
        private readonly Authenticator $auth,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/notes');
        }

        return $this->view->respond('login', [
            'title' => 'Sign in',
            // Lets the layout mark the active navigation item.
            'currentPath' => '/login',
            'csrfToken' => Csrf::token($this->session),
            'error' => $this->session->takeFlash('error'),
            'notice' => $this->session->takeFlash('notice'),
            'oldEmail' => $this->session->takeFlash('old_email') ?? '',
        ]);
    }
}
