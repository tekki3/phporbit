<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers\Generate;

use InvalidArgumentException;
use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Console\FormFieldSpec;
use PhpOrbit\Console\FormMaker;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;
use RuntimeException;

final class FormSubmitController implements Handler
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
        $fields = trim($request->form('fields') ?? '');
        $captcha = $request->form('captcha') === '1';
        $honeypot = $request->form('honeypot') === '1';
        $controllers = $request->form('controllers') === '1';
        $force = $request->form('force') === '1';

        $old = [
            'name' => $name,
            'fields' => $fields,
            'captcha' => $captcha,
            'honeypot' => $honeypot,
            'controllers' => $controllers,
        ];

        try {
            $result = (new FormMaker($this->paths->root))->create(
                $name,
                $fields === '' ? null : $fields,
                $captcha,
                $honeypot,
                $controllers,
                $force,
            );
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->view->respond('generate/form', [
                'title' => 'Generate a form',
                'subtitle' => 'Writes a form definition, and optionally its two pages — the same as orbit make:form.',
                'currentPath' => '/generate/form',
                'csrfToken' => Csrf::token($this->session),
                'availableTypes' => implode(', ', FormFieldSpec::available()),
                'old' => $old,
                'result' => null,
                'routesBlock' => '',
                'error' => $e->getMessage(),
            ], Status::UnprocessableEntity);
        }

        return $this->view->respond('generate/form', [
            'title' => 'Generate a form',
            'subtitle' => 'Writes a form definition, and optionally its two pages — the same as orbit make:form.',
            'currentPath' => '/generate/form',
            'csrfToken' => Csrf::token($this->session),
            'availableTypes' => implode(', ', FormFieldSpec::available()),
            'old' => [
                'name' => '',
                'fields' => 'name:text,email:email,message:textarea',
                'captcha' => $captcha,
                'honeypot' => $honeypot,
                'controllers' => $controllers,
            ],
            'result' => $result,
            // Joined here rather than looped in the template: @foreach does
            // not preserve the line breaks a <pre> block needs between
            // iterations.
            'routesBlock' => implode("\n", [...$result->importSnippets, ...$result->routeSnippets]),
            'error' => null,
        ]);
    }
}
