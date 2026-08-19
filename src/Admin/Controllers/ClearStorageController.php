<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;

/**
 * Safe at any time: TemplateEngine recompiles a template the moment it finds
 * no compiled file waiting for it, so the very next render — including this
 * request's own — replaces whatever this deletes.
 */
final class ClearStorageController implements Handler
{
    public function __construct(
        private readonly ProjectPaths $paths,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $directory = $this->paths->root . '/storage/cache/views';
        $removed = 0;

        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file) && @unlink($file)) {
                $removed++;
            }
        }

        $this->session->flash(
            'admin.notice',
            sprintf('Removed %d compiled template%s.', $removed, $removed === 1 ? '' : 's'),
        );

        return Response::redirect('/storage');
    }
}
