<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use InvalidArgumentException;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Mail\MailFailed;
use PhpOrbit\Mail\PersistingMailer;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;

final class ResendMailController implements Handler
{
    public function __construct(
        private readonly PersistingMailer $mailer,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        // The route constrains {id} to digits, so this cast cannot silently
        // turn a word into 0.
        $id = (int) $request->attribute('id');

        try {
            $entry = $this->mailer->resend($id);
            $this->session->flash(
                'admin.notice',
                sprintf('Resent #%d to %s.', $entry->id, implode(', ', array_map(
                    static fn ($address): string => $address->envelope(),
                    $entry->to,
                ))),
            );
        } catch (InvalidArgumentException | MailFailed $e) {
            $this->session->flash('admin.error', $e->getMessage());
        }

        return Response::redirect('/mail');
    }
}
