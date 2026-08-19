<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\FileSessionStore;
use PhpOrbit\Session\Session;

final class GcSessionsController implements Handler
{
    public function __construct(
        private readonly ProjectPaths $paths,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        // A fresh store rather than an injected one: collectGarbage() is a
        // one-off filesystem sweep, not something worth a container
        // registration for the one route that calls it.
        $removed = (new FileSessionStore($this->paths->root . '/storage/sessions'))->collectGarbage();

        $this->session->flash(
            'admin.notice',
            sprintf('Removed %d expired session%s.', $removed, $removed === 1 ? '' : 's'),
        );

        return Response::redirect('/sessions');
    }
}
