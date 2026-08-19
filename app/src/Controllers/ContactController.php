<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\ContactForm;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

/**
 * Renders the contact form.
 *
 * The form itself is defined once in {@see ContactForm} and shared by this
 * controller and the one that handles the submission — which is the point of it
 * being immutable. Two declarations would eventually disagree, and the one that
 * validates is the one that matters.
 */
final class ContactController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly ContactForm $form,
        private readonly Session $session,
        private readonly Authenticator $auth,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('contact', [
            'title' => 'Contact',
            'currentPath' => '/contact',
            'form' => $this->form->build()->render($this->session),
            'sent' => $this->session->takeFlash('contact.sent'),
            'currentUser' => $this->auth->user(),
        ]);
    }
}
