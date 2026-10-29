<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class ModelPageController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('generate/model', [
            'title' => 'Generate a model',
            'subtitle' => 'Writes a typed Model subclass, the same as orbit make:model.',
            'currentPath' => '/generate/model',
            'csrfToken' => Csrf::token($this->session),
            'old' => ['name' => '', 'table' => '', 'fields' => ''],
            'result' => null,
            'error' => null,
        ]);
    }
}
