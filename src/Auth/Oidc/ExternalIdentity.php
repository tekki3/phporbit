<?php

declare(strict_types=1);

namespace PhpOrbit\Auth\Oidc;

final class ExternalIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $issuer,
        public readonly string $subject,
        public readonly ?string $linkUser = null,
        public readonly ?string $linkStamp = null,
    ) {
    }

    public function key(): string
    {
        return hash('sha256', $this->provider . "\0" . $this->issuer . "\0" . $this->subject);
    }
}
