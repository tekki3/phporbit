<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\ContactForm;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Log\Level;
use PhpOrbit\Log\Logger;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

/**
 * Handles the contact submission.
 *
 * Note what is *not* here: no CSRF check (middleware did it), no escaping (the
 * form renders escaped), and no repetition of the validation rules (they are
 * declared on the fields).
 */
final class SubmitContactController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly ContactForm $form,
        private readonly Session $session,
        private readonly Logger $logger,
        private readonly Authenticator $auth,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $form = $this->form->build();
        $submission = $form->handle($request, $this->session);

        if ($submission->failed()) {
            // The reason a submission looked automated goes to the log, never
            // to the page — telling a script author which check fired tells
            // them what to change.
            if ($submission->looksAutomated()) {
                $this->logger->log(Level::Warning, 'contact form rejected', [
                    'reason' => $submission->rejectedAs,
                ]);
            }

            return $this->view->respond('contact', [
                'title' => 'Contact',
                'currentPath' => '/contact',
                'form' => $form->render($this->session, $submission->old(), $submission->errors()),
                'formError' => $submission->error('_form'),
                'currentUser' => $this->auth->user(),
            ], Status::UnprocessableEntity);
        }

        // A real application would email or store it here; the demo only needs
        // to prove the message arrived intact.
        $this->logger->log(Level::Info, 'contact form accepted', [
            'topic' => $submission->value('topic'),
        ]);

        $this->session->flash('contact.sent', sprintf(
            'Thanks, %s — your message about "%s" was received.',
            $submission->value('name'),
            $submission->value('topic'),
        ));

        // Redirect after a successful write, so a refresh does not repost.
        return Response::redirect('/contact');
    }
}
