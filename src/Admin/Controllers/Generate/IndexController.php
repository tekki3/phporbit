<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\View\TemplateEngine;

final class IndexController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('generate/index', [
            'title' => 'Generate',
            'subtitle' => 'One form per orbit make:* command — writes real files into this project.',
            'currentPath' => '/generate',
        ]);
    }
}
