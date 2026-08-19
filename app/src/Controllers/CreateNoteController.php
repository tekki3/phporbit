<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Notes\NoteRepository;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;
use PhpOrbit\Validation\Validator;

/**
 * Creates a note.
 *
 * Reaching this class at all means the CSRF token was valid — the middleware
 * rejects the request otherwise, so there is no token check to forget here.
 */
final class CreateNoteController implements Handler
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $validator = Validator::forRequest($request)
            ->required('title')
            ->maxLength('title', 80)
            ->required('body')
            ->maxLength('body', 2000);

        if ($validator->fails()) {
            // Flashed back so the form can be repopulated after the redirect.
            $this->session->flash('error', implode(' ', $validator->errors()));
            $this->session->flash('old_title', $validator->value('title') ?? '');
            $this->session->flash('old_body', $validator->value('body') ?? '');

            return Response::redirect('/notes');
        }

        $id = $this->notes->create($validator->validated('title'), $validator->validated('body'));

        $this->session->flash('notice', sprintf('Note #%d saved.', $id));

        // POST-redirect-GET: a refresh re-runs the GET, not the insert.
        return Response::redirect('/notes');
    }
}
