<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class MiddlewarePageController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('generate/middleware', [
            'title' => 'Generate middleware',
            'subtitle' => 'Writes a pass-through Middleware class — the same as orbit make:middleware.',
            'currentPath' => '/generate/middleware',
            'csrfToken' => Csrf::token($this->session),
            'old' => ['name' => ''],
            'result' => null,
            'error' => null,
        ]);
    }
}
