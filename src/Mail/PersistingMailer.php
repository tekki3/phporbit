<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

use InvalidArgumentException;
use PhpOrbit\Database\QueryFailed;

/**
 * Wraps a {@see Mailer} to record every send in `mail_log`, optionally defers
 * delivery, and can resend or deliver logged messages.
 *
 * With queuing disabled, `send()` hands the message to the real mailer
 * synchronously and records the resolved outcome. With queuing enabled, it
 * validates and persists the message as Pending without contacting the driver.
 *
 * The write is best-effort. A project that has never run `orbit migrate` has
 * no `mail_log` table yet, and a missing audit table must not be the reason a
 * real, successful send gets reported to the caller as a failure — nor may it
 * turn an already-thrown `MailFailed` into something a caller's `catch
 * (MailFailed)` no longer matches. `QueryFailed` around synchronous audit
 * writes is swallowed for exactly that reason. Queue writes are not best-effort:
 * reporting success without retaining the message would lose it.
 *
 * `resend()` refuses anything not currently `Failed`, because resending a
 * message already marked `Sent` would deliver it twice with no record that it
 * happened. A resend updates that same row rather than writing a new one.
 *
 * Deferred messages are delivered only when `orbit mail:send-pending` runs;
 * this class does not start background workers or retry automatically.
 */
final class PersistingMailer implements Mailer
{
    public function __construct(
        private readonly Mailer $inner,
        private readonly MailLogRepository $log,
        private readonly bool $queue = false,
    ) {
    }

    public function send(Message $message): void
    {
        if ($this->queue) {
            if ($this->inner instanceof SmtpMailer) {
                $message = $this->inner->applyDefaultSender($message);
            }

            $message->assertSendable();
            $this->log->record($message, MailStatus::Pending, attempts: 0);

            return;
        }

        try {
            $this->inner->send($message);
        } catch (MailFailed $e) {
            $this->recordQuietly($message, MailStatus::Failed, $e->getMessage());

            throw $e;
        }

        $this->recordQuietly($message, MailStatus::Sent);
    }

    public function queuesMessages(): bool
    {
        return $this->queue;
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
     * Delivers a message that was stored without an attempt.
     *
     * @throws InvalidArgumentException if the id is unknown or not Pending
     * @throws MailFailed if delivery fails
     */
    public function deliverPending(int $id): MailLog
    {
        $entry = $this->log->find($id);

        if ($entry === null) {
            throw new InvalidArgumentException(sprintf('No mail logged with id %d.', $id));
        }

        if ($entry->status !== MailStatus::Pending) {
            throw new InvalidArgumentException(sprintf(
                'Mail #%d has status "%s"; only pending mail can be sent.',
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
