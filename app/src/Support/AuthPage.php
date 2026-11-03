<?php

declare(strict_types=1);

namespace App\Support;

use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Http\Response;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class AuthPage
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Session $session,
        private readonly Authenticator $auth,
    ) {
    }

    /** @param array<string, mixed> $data Template data is narrowed by Renderer. */
    public function render(string $template, string $title, array $data = []): Response
    {
        return $this->view->respond($template, $data + [
            'title' => $title, 'currentPath' => $this->auth->check() ? '/account/security' : '/login',
            'currentUser' => $this->auth->user(),
            'csrfToken' => Csrf::token($this->session),
            'error' => $this->session->takeFlash('error'), 'notice' => $this->session->takeFlash('notice'),
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function back(string $path, string $message, bool $error = false): Response
    {
        $this->session->flash($error ? 'error' : 'notice', $message);

        return Response::redirect($path)->withHeader('Cache-Control', 'no-store');
    }

    public function signedIn(): Response
    {
        $intended = $this->session->get('_auth_intended');
        $this->session->remove('_auth_intended');
        $path = $intended !== null && preg_match('~^/(?!/)[^\x00-\x20\\\\]*$~D', $intended) === 1 ? $intended : '/notes';

        return $this->back($path, 'Signed in.');
    }
}
