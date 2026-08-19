<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\User;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Config\Environment;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class AvatarController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Session $session,
        private readonly Authenticator $auth,
        private readonly Environment $config,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $user = $this->auth->user();

        assert($user instanceof User, 'the route is behind RequireAuthentication');

        return $this->view->respond('avatar', [
            'title' => 'Avatar',
            // Lets the layout mark the active navigation item.
            'currentPath' => '/avatar',
            'csrfToken' => Csrf::token($this->session),
            'currentUser' => $user,
            'avatar' => $user->avatarPath,
            'notice' => $this->session->takeFlash('notice'),
            'error' => $this->session->takeFlash('error'),
            'maxBytes' => $this->config->int('UPLOAD_MAX_BYTES', 1024 * 1024),
            'allowed' => implode(', ', array_keys(StoreAvatarController::ALLOWED_TYPES)),
        ]);
    }
}
