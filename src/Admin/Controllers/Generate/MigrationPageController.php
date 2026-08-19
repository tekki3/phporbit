<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class MigrationPageController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('generate/migration', [
            'title' => 'Generate a migration',
            'subtitle' => 'Writes a file into database/migrations — the same as orbit make:migration.',
            'currentPath' => '/generate/migration',
            'csrfToken' => Csrf::token($this->session),
            'old' => ['name' => '', 'table' => '', 'sequential' => false],
            'result' => null,
            'error' => null,
        ]);
    }
}
