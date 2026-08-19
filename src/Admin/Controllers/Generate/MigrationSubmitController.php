<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use InvalidArgumentException;
use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Console\MigrationMaker;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;
use RuntimeException;

final class MigrationSubmitController implements Handler
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
        $table = trim($request->form('table') ?? '');
        $sequential = $request->form('sequential') === '1';
        $force = $request->form('force') === '1';

        $old = ['name' => $name, 'table' => $table, 'sequential' => $sequential];

        try {
            $result = (new MigrationMaker($this->paths->root))->create(
                $name,
                $table === '' ? null : $table,
                $sequential,
                $force,
            );
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->view->respond('generate/migration', [
                'title' => 'Generate a migration',
                'subtitle' => 'Writes a file into database/migrations — the same as orbit make:migration.',
                'currentPath' => '/generate/migration',
                'csrfToken' => Csrf::token($this->session),
                'old' => $old,
                'result' => null,
                'error' => $e->getMessage(),
            ], Status::UnprocessableEntity);
        }

        return $this->view->respond('generate/migration', [
            'title' => 'Generate a migration',
            'subtitle' => 'Writes a file into database/migrations — the same as orbit make:migration.',
            'currentPath' => '/generate/migration',
            'csrfToken' => Csrf::token($this->session),
            'old' => ['name' => '', 'table' => '', 'sequential' => $sequential],
            'result' => $result,
            'error' => null,
        ]);
    }
}
