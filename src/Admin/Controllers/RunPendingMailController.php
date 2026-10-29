<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use PhpOrbit\Database\QueryFailed;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Mail\MailFailed;
use PhpOrbit\Mail\MailStatus;
use PhpOrbit\Mail\PersistingMailer;
use PhpOrbit\Routing\Handler;
use PhpOrbit\Session\Session;

final class RunPendingMailController implements Handler
{
    public function __construct(
        private readonly PersistingMailer $mailer,
        private readonly Session $session,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        try {
            $pending = $this->mailer->history(MailStatus::Pending, 50);
        } catch (QueryFailed) {
            $this->session->flash('admin.error', 'The mail_log table does not exist yet. Run pending migrations.');

            return Response::redirect('/mail');
        }

        $sent = 0;
        $failed = 0;

        foreach ($pending as $entry) {
            try {
                $this->mailer->deliverPending($entry->id);
                $sent++;
            } catch (MailFailed) {
                $failed++;
            }
        }

        $this->session->flash('admin.notice', sprintf('%d sent, %d failed.', $sent, $failed));

        return Response::redirect('/mail');
    }
}
