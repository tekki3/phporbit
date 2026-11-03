<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use PhpOrbit\Console\AuthMaker;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class AuthPageController implements Handler
{
    public function __construct(private readonly TemplateEngine $view, private readonly Session $session)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('generate/auth', [
            'title' => 'Generate authentication', 'subtitle' => 'Login, verified registration, MFA and user management.',
            'currentPath' => '/generate/auth', 'csrfToken' => Csrf::token($this->session), 'files' => null, 'error' => null,
            'wiring' => AuthMaker::WIRING,
        ]);
    }
}
