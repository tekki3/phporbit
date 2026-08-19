<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use InvalidArgumentException;
use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Console\MiddlewareMaker;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;
use RuntimeException;

final class MiddlewareSubmitController implements Handler
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
        $force = $request->form('force') === '1';

        try {
            $result = (new MiddlewareMaker($this->paths->root))->create($name, $force);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->view->respond('generate/middleware', [
                'title' => 'Generate middleware',
                'subtitle' => 'Writes a pass-through Middleware class — the same as orbit make:middleware.',
                'currentPath' => '/generate/middleware',
                'csrfToken' => Csrf::token($this->session),
                'old' => ['name' => $name],
                'result' => null,
                'error' => $e->getMessage(),
            ], Status::UnprocessableEntity);
        }

        return $this->view->respond('generate/middleware', [
            'title' => 'Generate middleware',
            'subtitle' => 'Writes a pass-through Middleware class — the same as orbit make:middleware.',
            'currentPath' => '/generate/middleware',
            'csrfToken' => Csrf::token($this->session),
            'old' => ['name' => ''],
            'result' => $result,
            'error' => null,
        ]);
    }
}
