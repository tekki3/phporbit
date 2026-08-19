<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use InvalidArgumentException;
use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Console\ClassMaker;
use PhpOrbit\Console\Lifetime;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;
use RuntimeException;

/**
 * Runs `ClassMaker`, the same one `orbit make:class` calls, and renders the
 * form's own template again with either the result or the error — never a
 * redirect. A generator's output is a set of paths and snippets meant to be
 * read and copied; a flash-and-redirect would show one line and lose the
 * rest the moment the page was refreshed.
 */
final class ClassSubmitController implements Handler
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
        $lifetime = Lifetime::tryFrom($request->form('lifetime') ?? '') ?? Lifetime::Autowired;
        $force = $request->form('force') === '1';

        $old = ['name' => $name, 'lifetime' => $lifetime->value];

        try {
            $result = (new ClassMaker($this->paths->root))->create($name, $lifetime, $force);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->view->respond('generate/class', [
                'title' => 'Generate a class',
                'subtitle' => 'Writes a plain class under App\, the same as orbit make:class.',
                'currentPath' => '/generate/class',
                'csrfToken' => Csrf::token($this->session),
                'lifetimes' => Lifetime::cases(),
                'old' => $old,
                'result' => null,
                'error' => $e->getMessage(),
            ], Status::UnprocessableEntity);
        }

        return $this->view->respond('generate/class', [
            'title' => 'Generate a class',
            'subtitle' => 'Writes a plain class under App\, the same as orbit make:class.',
            'currentPath' => '/generate/class',
            'csrfToken' => Csrf::token($this->session),
            'lifetimes' => Lifetime::cases(),
            // Cleared rather than kept: the class that was just written can't
            // be written again, so a repeat submission is never useful.
            'old' => ['name' => '', 'lifetime' => $lifetime->value],
            'result' => $result,
            'error' => null,
        ]);
    }
}
