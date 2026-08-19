<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use PhpOrbit\Console\Lifetime;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

/**
 * The blank form. `ClassSubmitController` renders the same template again for
 * both a validation error and a successful result — see there for why: this
 * page never needs to read a flash, because nothing ever redirects to it.
 */
final class ClassPageController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('generate/class', [
            'title' => 'Generate a class',
            'subtitle' => 'Writes a plain class under App\, the same as orbit make:class.',
            'currentPath' => '/generate/class',
            'csrfToken' => Csrf::token($this->session),
            'lifetimes' => Lifetime::cases(),
            'old' => ['name' => '', 'lifetime' => Lifetime::Autowired->value],
            'result' => null,
            'error' => null,
        ]);
    }
}
