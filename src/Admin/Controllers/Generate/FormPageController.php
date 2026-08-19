<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use PhpOrbit\Console\FormFieldSpec;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

final class FormPageController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('generate/form', [
            'title' => 'Generate a form',
            'subtitle' => 'Writes a form definition, and optionally its two pages — the same as orbit make:form.',
            'currentPath' => '/generate/form',
            'csrfToken' => Csrf::token($this->session),
            'availableTypes' => implode(', ', FormFieldSpec::available()),
            'old' => [
                'name' => '',
                'fields' => 'name:text,email:email,message:textarea',
                'captcha' => false,
                'honeypot' => true,
                'controllers' => true,
            ],
            'result' => null,
            'routesBlock' => '',
            'error' => null,
        ]);
    }
}
