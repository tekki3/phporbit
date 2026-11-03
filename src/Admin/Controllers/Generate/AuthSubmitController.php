<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Console\AuthMaker;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;
use RuntimeException;

final class AuthSubmitController implements Handler
{
    public function __construct(private readonly TemplateEngine $view, private readonly ProjectPaths $paths, private readonly Session $session)
    {
    }

    public function handle(ServerRequest $request): Response
    {
        $files = null;
        $error = null;
        try {
            $files = (new AuthMaker($this->paths->root))->create();
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        return $this->view->respond('generate/auth', [
            'title' => 'Generate authentication', 'subtitle' => 'Login, verified registration, MFA and user management.',
            'currentPath' => '/generate/auth', 'csrfToken' => Csrf::token($this->session), 'files' => $files, 'error' => $error,
            'wiring' => AuthMaker::WIRING,
        ], $error === null ? Status::Ok : Status::UnprocessableEntity);
    }
}
