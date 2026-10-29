<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use InvalidArgumentException;
use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Console\ModelMaker;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;
use RuntimeException;

final class ModelSubmitController implements Handler
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
        $fields = trim($request->form('fields') ?? '');
        $force = $request->form('force') === '1';
        $old = ['name' => $name, 'table' => $table, 'fields' => $fields];

        try {
            $result = (new ModelMaker($this->paths->root))->create(
                $name,
                table: $table === '' ? null : $table,
                fields: $fields,
                force: $force,
            );
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->view->respond('generate/model', [
                'title' => 'Generate a model',
                'subtitle' => 'Writes a typed Model subclass, the same as orbit make:model.',
                'currentPath' => '/generate/model',
                'csrfToken' => Csrf::token($this->session),
                'old' => $old,
                'result' => null,
                'error' => $e->getMessage(),
            ], Status::UnprocessableEntity);
        }

        return $this->view->respond('generate/model', [
            'title' => 'Generate a model',
            'subtitle' => 'Writes a typed Model subclass, the same as orbit make:model.',
            'currentPath' => '/generate/model',
            'csrfToken' => Csrf::token($this->session),
            'old' => ['name' => '', 'table' => $table, 'fields' => $fields],
            'result' => $result,
            'error' => null,
        ]);
    }
}
