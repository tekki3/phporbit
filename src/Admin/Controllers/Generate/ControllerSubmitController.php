<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use InvalidArgumentException;
use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Console\ControllerMaker;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;
use RuntimeException;

final class ControllerSubmitController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly ProjectPaths $paths,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $name = trim($request->form('name') ?? '');
        $withView = $request->form('withView') === '1';
        $force = $request->form('force') === '1';

        $old = ['name' => $name, 'withView' => $withView];

        try {
            $result = (new ControllerMaker($this->paths->root))->create($name, $withView, $force);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->view->respond('generate/controller', [
                'title' => 'Generate a controller',
                'subtitle' => 'Writes a controller, and optionally its template — the same as orbit make:controller.',
                'currentPath' => '/generate/controller',
                'csrfToken' => Csrf::token($this->session),
                'old' => $old,
                'result' => null,
                'error' => $e->getMessage(),
            ], Status::UnprocessableEntity);
        }

        return $this->view->respond('generate/controller', [
            'title' => 'Generate a controller',
            'subtitle' => 'Writes a controller, and optionally its template — the same as orbit make:controller.',
            'currentPath' => '/generate/controller',
            'csrfToken' => Csrf::token($this->session),
            'old' => ['name' => '', 'withView' => $withView],
            'result' => $result,
            'error' => null,
        ]);
    }
}
