<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

/**
 * Keeps messages in memory instead of sending them.
 *
 * The default in tests, and a reasonable `MAIL_DRIVER` in development: nothing
 * leaves the machine, and what would have been sent is inspectable.
 *
 * This one *is* stateful, which makes it the exception that proves the rule —
 * register it as `scoped()` rather than `singleton()` if anything in the
 * application depends on the collected messages, or a worker will accumulate
 * every message it has ever sent and show one request's mail to the next.
 */
final class ArrayMailer implements Mailer
{
    /** @var list<Message> */
    private array $sent = [];

    public function send(Message $message): void
    {
        // Still validated, so a test catches the same mistakes a real send
        // would — a mailer that accepts anything is a mailer that hides bugs.
        $message->assertSendable();

        $this->sent[] = $message;
    }

    /**
     * @return list<Message>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function last(): ?Message
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }

    /**
     * Whether any message went to this address, on any of the three lines.
     */
    public function sentTo(string $email): bool
    {
        foreach ($this->sent as $message) {
            foreach ($message->envelopeRecipients() as $recipient) {
                if (strcasecmp($recipient->email, $email) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    public function clear(): void
    {
        $this->sent = [];
    }
}
