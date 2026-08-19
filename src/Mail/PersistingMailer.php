<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

use InvalidArgumentException;
use PhpOrbit\Database\QueryFailed;

/**
 * Wraps a {@see Mailer} to record every send in `mail_log`, and to resend one
 * that failed.
 *
 * This is a decorator around delivery, not a replacement for it: `send()`
 * still hands the message to the real mailer synchronously and still throws
 * {@see MailFailed} on the same failures it always did — nothing about the
 * calling code needs to change. What is added is an audit row, written after
 * the attempt resolves, since there is nothing to record before then.
 *
 * The write is best-effort. A project that has never run `orbit migrate` has
 * no `mail_log` table yet, and a missing audit table must not be the reason a
 * real, successful send gets reported to the caller as a failure — nor may it
 * turn an already-thrown `MailFailed` into something a caller's `catch
 * (MailFailed)` no longer matches. `QueryFailed` around the write is swallowed
 * for exactly that reason; delivery's own outcome is untouched either way.
 *

 * `resend()` is the other half. It refuses to resend anything that is not
 * currently `Failed`, because resending a message already marked `Sent` would
 * deliver it twice with no record that it happened — the row exists precisely
 * so that "sent" means sent. A resend updates that same row rather than
 * writing a new one: it is another attempt at the same logical message.
 *
 * Deliberately not a queue. Every send here is still synchronous and every
 * resend is a command a developer runs — `orbit mail:resend` — not a retry
 * loop the framework runs on its own. See the "Not built" note in the mail
 * documentation for why that line is not crossed.
 */
final class PersistingMailer implements Mailer
{
    public function __construct(
        private readonly Mailer $inner,
        private readonly MailLogRepository $log,
    ) {
    }

    public function send(Message $message): void
    {
        try {
            $this->inner->send($message);
        } catch (MailFailed $e) {
            $this->recordQuietly($message, MailStatus::Failed, $e->getMessage());

            throw $e;
        }

        $this->recordQuietly($message, MailStatus::Sent);
    }

    /**
     * Resends a logged message by id, and updates its row with the outcome.
     *
     * @throws InvalidArgumentException if the id is unknown or not currently Failed
     * @throws MailFailed if delivery fails again
     */
    public function resend(int $id): MailLog
    {
        $entry = $this->log->find($id);

        if ($entry === null) {
            throw new InvalidArgumentException(sprintf('No mail logged with id %d.', $id));
        }

        if ($entry->status !== MailStatus::Failed) {
            throw new InvalidArgumentException(sprintf(
                'Mail #%d has status "%s"; only failed mail can be resent.',
                $id,
                $entry->status->value,
            ));
        }

        $attempts = $entry->attempts + 1;

        try {
            $this->inner->send($entry->toMessage());
        } catch (MailFailed $e) {
            $this->log->recordResend($id, MailStatus::Failed, $e->getMessage(), $attempts);

            throw $e;
        }

        $this->log->recordResend($id, MailStatus::Sent, null, $attempts);

        return $this->log->find($id) ?? $entry;
    }

    /**
     * @return list<MailLog>
     */
    public function history(?MailStatus $status = null, int $limit = 50): array
    {
        return $this->log->list($status, $limit);
    }

    private function recordQuietly(Message $message, MailStatus $status, ?string $error = null): void
    {
        try {
            $this->log->record($message, $status, $error);
        } catch (QueryFailed) {
            // See the class docblock: the send already happened (or already
            // failed, and the caller already has that exception); losing the
            // audit row is a lesser problem than losing or masking either.
        }
    }
}
