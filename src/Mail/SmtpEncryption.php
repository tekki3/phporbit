<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

use ValueError;

enum SmtpEncryption: string
{
    /** Upgrade a plaintext connection with STARTTLS. The usual choice, port 587. */
    case StartTls = 'tls';

    /** TLS from the first byte. Port 465. */
    case Implicit = 'ssl';

    /** No encryption at all. */
    case None = 'none';

    public static function fromName(string $name): self
    {
        $normalised = strtolower(trim($name));

        $normalised = match ($normalised) {
            'starttls' => 'tls',
            'tls-implicit', 'smtps' => 'ssl',
            '', 'off', 'false' => 'none',
            default => $normalised,
        };

        return self::tryFrom($normalised) ?? throw new ValueError(sprintf(
            'Unknown mail encryption "%s". Use one of: tls, ssl, none.',
            $name,
        ));
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::StartTls => 587,
            self::Implicit => 465,
            self::None => 25,
        };
    }

    /**
     * Whether the socket is encrypted before anything is written.
     */
    public function isImplicit(): bool
    {
        return $this === self::Implicit;
    }
}
