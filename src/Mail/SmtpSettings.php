<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

use InvalidArgumentException;
use PhpOrbit\Config\Environment;
use PhpOrbit\Config\MissingConfiguration;
use ValueError;

/**
 * Everything needed to reach an SMTP server, validated once at boot.
 */
final class SmtpSettings
{
    public function __construct(
        public readonly string $host,
        public readonly ?int $port = null,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly SmtpEncryption $encryption = SmtpEncryption::StartTls,
        public readonly ?Address $from = null,
        public readonly int $timeoutSeconds = 10,
        public readonly string $clientName = 'localhost',
        /**
         * Permits sending credentials over an unencrypted connection.
         *
         * Off by default because SMTP AUTH puts the password on the wire in
         * base64 — which is encoding, not encryption. Only reasonable when the
         * server is on localhost or a private network you control.
         */
        public readonly bool $allowInsecureAuth = false,
    ) {
        if ($host === '') {
            throw new InvalidArgumentException('MAIL_HOST cannot be empty.');
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException(sprintf('MAIL_PORT must be between 1 and 65535, got %d.', $port));
        }

        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('MAIL_TIMEOUT must be at least 1 second.');
        }

        if ($username !== null && $password === null) {
            throw new InvalidArgumentException('MAIL_USERNAME was given without MAIL_PASSWORD.');
        }

        if ($username !== null && $encryption === SmtpEncryption::None && !$allowInsecureAuth) {
            throw new InvalidArgumentException(
                'Refusing to send credentials over an unencrypted connection. SMTP AUTH '
                . 'base64-encodes the password, which is not encryption — anyone on the path '
                . 'can read it. Set MAIL_ENCRYPTION=tls, or MAIL_ALLOW_INSECURE_AUTH=true if '
                . 'the server is on localhost.',
            );
        }
    }

    public function effectivePort(): int
    {
        return $this->port ?? $this->encryption->defaultPort();
    }

    public function needsAuthentication(): bool
    {
        return $this->username !== null && $this->password !== null;
    }

    public static function fromEnvironment(Environment $config): self
    {
        try {
            $encryption = SmtpEncryption::fromName($config->string('MAIL_ENCRYPTION', 'tls'));
        } catch (ValueError) {
            throw MissingConfiguration::notOfType('MAIL_ENCRYPTION', 'mail encryption', 'tls, ssl, none');
        }

        $username = $config->raw('MAIL_USERNAME');
        $fromAddress = $config->raw('MAIL_FROM_ADDRESS');

        return new self(
            $config->required('MAIL_HOST'),
            $config->has('MAIL_PORT') ? $config->int('MAIL_PORT') : null,
            $username === '' ? null : $username,
            $config->raw('MAIL_PASSWORD'),
            $encryption,
            $fromAddress === null || $fromAddress === ''
                ? null
                : new Address($fromAddress, $config->raw('MAIL_FROM_NAME')),
            $config->int('MAIL_TIMEOUT', 10),
            $config->string('MAIL_CLIENT_NAME', 'localhost'),
            $config->bool('MAIL_ALLOW_INSECURE_AUTH', false),
        );
    }

    /**
     * Redacts the password, so settings can be logged or dumped safely.
     *
     * @return array<string, scalar|null>
     */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->effectivePort(),
            'encryption' => $this->encryption->value,
            'username' => $this->username,
            'password' => $this->password === null ? null : '<redacted>',
            'from' => $this->from?->email,
        ];
    }
}
