<?php

declare(strict_types=1);

namespace App\Controllers;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\View\TemplateEngine;

/**
 * One class per route.
 *
 * A `[Controller::class, 'method']` pair can only be called dynamically, and a
 * dynamic call returns `mixed` — which defeats the type guarantees the rest of
 * the framework relies on. An interface keeps the signature checkable.
 *
 * Constructor dependencies are resolved from the request scope, per request,
 * so nothing this object holds can outlive the request it was built for.
 */
final class WelcomeController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('welcome', [
            'title' => 'It works',
        ]);
    }
}
