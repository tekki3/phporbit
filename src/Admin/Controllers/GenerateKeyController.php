<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use PhpOrbit\Config\Environment;
use PhpOrbit\Crypto\Key;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

/**
 * The same as `orbit key:generate`: prints a key rather than writing one, so
 * generating one here never touches .env either. Nothing stops a developer
 * from clicking this and not using the result — that is exactly the point.
 */
final class GenerateKeyController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Environment $env,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('tools', [
            'title' => 'Tools',
            'subtitle' => 'orbit key:generate and orbit mail:test, without leaving the browser.',
            'currentPath' => '/tools',
            'csrfToken' => Csrf::token($this->session),
            'mailDriver' => $this->env->string('MAIL_DRIVER', 'array'),
            'defaultFrom' => $this->env->raw('MAIL_FROM_ADDRESS') ?? '',
            'generatedKey' => Key::generate()->exportForConfiguration(),
            'mailTo' => '',
            'mailFrom' => '',
            'mailResult' => null,
            'error' => null,
        ]);
    }
}
