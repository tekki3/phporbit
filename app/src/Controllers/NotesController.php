<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Notes\NoteRepository;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\View\TemplateEngine;

/**
 * Lists notes and renders the create form.
 */
final class NotesController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly NoteRepository $notes,
        private readonly Session $session,
        private readonly Authenticator $auth,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        return $this->view->respond('notes', [
            'title' => 'Notes',
            // Lets the layout mark the active navigation item.
            'currentPath' => '/notes',
            'notes' => $this->notes->latest(),
            'csrfToken' => Csrf::token($this->session),
            'currentUser' => $this->auth->user(),
            // Read once and cleared, so a refresh does not repeat the message.
            'flash' => $this->session->takeFlash('notice'),
            'error' => $this->session->takeFlash('error'),
            'oldTitle' => $this->session->takeFlash('old_title') ?? '',
            'oldBody' => $this->session->takeFlash('old_body') ?? '',
        ]);
    }
}
