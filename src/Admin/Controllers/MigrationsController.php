<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use PhpOrbit\Database\Migrator;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class MigrationsController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Migrator $migrator,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $batches = $this->migrator->batches();
        $pending = $this->migrator->pending();

        $rows = [];
        foreach ($this->migrator->available() as $name) {
            $rows[] = [
                'name' => $name,
                'applied' => isset($batches[$name]),
                'batch' => $batches[$name] ?? null,
            ];
        }

        return $this->view->respond('migrations', [
            'title' => 'Migrations',
            'subtitle' => 'Every file in database/migrations, applied or pending, with its batch.',
            'currentPath' => '/migrations',
            'csrfToken' => Csrf::token($this->session),
            'rows' => $rows,
            'pendingCount' => count($pending),
            'flash' => $this->session->takeFlash('admin.notice'),
            'error' => $this->session->takeFlash('admin.error'),
        ]);
    }
}
