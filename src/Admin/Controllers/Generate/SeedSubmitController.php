<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use InvalidArgumentException;
use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Console\SeedMaker;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;
use RuntimeException;

final class SeedSubmitController implements Handler
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
            $result = (new SeedMaker($this->paths->root))->create($name, force: $force);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->view->respond('generate/seed', [
                'title' => 'Generate a seeder',
                'subtitle' => 'Writes a database seeder, the same as orbit make:seed.',
                'currentPath' => '/generate/seed',
                'csrfToken' => Csrf::token($this->session),
                'old' => ['name' => $name],
                'result' => null,
                'error' => $e->getMessage(),
            ], Status::UnprocessableEntity);
        }

        return $this->view->respond('generate/seed', [
            'title' => 'Generate a seeder',
            'subtitle' => 'Writes a database seeder, the same as orbit make:seed.',
            'currentPath' => '/generate/seed',
            'csrfToken' => Csrf::token($this->session),
            'old' => ['name' => ''],
            'result' => $result,
            'error' => null,
        ]);
    }
}
