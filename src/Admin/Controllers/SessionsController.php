<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class SessionsController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly ProjectPaths $paths,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $files = glob($this->paths->root . '/storage/sessions/sess_*') ?: [];

        return $this->view->respond('sessions', [
            'title' => 'Sessions',
            'subtitle' => 'Session files on disk, in storage/sessions.',
            'currentPath' => '/sessions',
            'csrfToken' => Csrf::token($this->session),
            'fileCount' => count($files),
            // Own session included deliberately: it is one more file in the
            // same pool, the same as any visitor's, and sessions:gc treats it
            // no differently.
            'flash' => $this->session->takeFlash('admin.notice'),
            'error' => $this->session->takeFlash('admin.error'),
        ]);
    }
}
