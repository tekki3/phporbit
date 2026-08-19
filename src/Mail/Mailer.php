<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

/**
 * Sends a message.
 *
 * Implementations are constructed at boot and shared by every request a worker
 * serves, so they must hold no mutable state. {@see SmtpMailer} opens and closes
 * its connection inside `send()` for exactly that reason.
 */
interface Mailer
{
    /**
     * @throws MailFailed when the message could not be handed to the server
     */
    public function send(Message $message): void;
}
