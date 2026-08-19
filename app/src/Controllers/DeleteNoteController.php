<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Notes\NoteRepository;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;

final class DeleteNoteController implements Handler
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        // The route constrains {id} to digits, so this cast cannot silently
        // turn a word into 0.
        $id = (int) $request->attribute('id');

        $this->session->flash(
            'notice',
            $this->notes->delete($id) ? sprintf('Note #%d deleted.', $id) : 'That note no longer exists.',
        );

        return Response::redirect('/notes');
    }
}
