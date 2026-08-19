<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Mail\MailFailed;
use PhpOrbit\Mail\MailStatus;
use PhpOrbit\Mail\PersistingMailer;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;

/**
 * Works through every currently-failed entry rather than stopping at the
 * first failure — the same shape `orbit mail:resend --failed` has, so a
 * server that is still down for one message does not block the rest.
 */
final class ResendFailedMailController implements Handler
{
    public function __construct(
        private readonly PersistingMailer $mailer,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $resent = 0;
        $stillFailing = 0;

        foreach ($this->mailer->history(MailStatus::Failed, limit: 50) as $entry) {
            try {
                $this->mailer->resend($entry->id);
                $resent++;
            } catch (MailFailed) {
                $stillFailing++;
            }
        }

        $this->session->flash('admin.notice', match (true) {
            $resent === 0 && $stillFailing === 0 => 'Nothing to resend.',
            default => sprintf('%d resent, %d still failing.', $resent, $stillFailing),
        });

        return Response::redirect('/mail');
    }
}
