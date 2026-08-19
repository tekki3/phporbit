<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

use RuntimeException;

/**
 * The message could not be delivered to the server.
 *
 * Messages carry the server's reply, which is what makes a failure
 * diagnosable, but never the credentials used to authenticate — those travel
 * into logs and bug reports exactly like a database password would.
 */
final class MailFailed extends RuntimeException
{
    public static function atStep(string $step, string $reply): self
    {
        return new self(sprintf('SMTP %s failed: %s', $step, trim($reply)));
    }

    public static function connecting(string $target, string $reason): self
    {
        return new self(sprintf('Could not connect to %s: %s', $target, $reason));
    }
}
