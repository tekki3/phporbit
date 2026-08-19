<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use PhpOrbit\Config\Environment;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class ToolsController implements Handler
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
            'generatedKey' => null,
            'mailTo' => '',
            'mailFrom' => '',
            'mailResult' => null,
            'error' => null,
        ]);
    }
}
