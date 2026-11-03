<?php

declare(strict_types=1);

namespace PhpOrbit\Auth\Oidc;

use InvalidArgumentException;
use SensitiveParameter;

final class OidcProvider
{
    /**
     * @param list<string> $issuers Exact trusted issuer URLs.
     * @param list<string> $tenants Entra tenant UUIDs; never "common" or "organizations".
     */
    public function __construct(
        public readonly string $name,
        public readonly string $clientId,
        #[SensitiveParameter] private readonly string $clientSecret,
        public readonly string $redirectUri,
        public readonly string $authorizationEndpoint,
        public readonly string $tokenEndpoint,
        public readonly string $jwksEndpoint,
        public readonly array $issuers,
        public readonly array $tenants = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{0,30}$/D', $name) !== 1 || trim($clientId) === '' || $issuers === []) {
            throw new InvalidArgumentException('OIDC requires a provider name, client ID and trusted issuers.');
        }
        foreach ([$authorizationEndpoint, $tokenEndpoint, $jwksEndpoint] as $url) {
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) === null) {
                throw new InvalidArgumentException('OIDC provider endpoints must be absolute HTTPS URLs.');
            }
        }
        $host = parse_url($redirectUri, PHP_URL_HOST);
        $scheme = parse_url($redirectUri, PHP_URL_SCHEME);
        if ($host === null || ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)))) {
            throw new InvalidArgumentException('OIDC callbacks require HTTPS except on loopback.');
        }
        foreach ($tenants as $tenant) {
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $tenant) !== 1) {
                throw new InvalidArgumentException('Entra tenants must be explicit lowercase tenant UUIDs.');
            }
        }
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'clientId' => $this->clientId, 'clientSecret' => '[redacted]'];
    }
}
