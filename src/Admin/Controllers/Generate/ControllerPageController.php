<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class ControllerPageController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('generate/controller', [
            'title' => 'Generate a controller',
            'subtitle' => 'Writes a controller, and optionally its template — the same as orbit make:controller.',
            'currentPath' => '/generate/controller',
            'csrfToken' => Csrf::token($this->session),
            'old' => ['name' => '', 'withView' => false],
            'result' => null,
            'error' => null,
        ]);
    }
}
