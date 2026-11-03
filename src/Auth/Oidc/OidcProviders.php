<?php

declare(strict_types=1);

namespace PhpOrbit\Auth\Oidc;

use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Config\Environment;

final class OidcProviders
{
    /** @param list<OidcProvider> $providers */
    public function __construct(private readonly array $providers = [])
    {
    }

    public static function fromEnvironment(Environment $env): self
    {
        $providers = [];
        $base = rtrim($env->string('APP_URL', 'http://localhost:8080'), '/');
        if ($env->string('GOOGLE_CLIENT_ID', '') !== '') {
            $providers[] = new OidcProvider(
                'google', $env->required('GOOGLE_CLIENT_ID'), $env->required('GOOGLE_CLIENT_SECRET'),
                $base . '/auth/google/callback', 'https://accounts.google.com/o/oauth2/v2/auth',
                'https://oauth2.googleapis.com/token', 'https://www.googleapis.com/oauth2/v3/certs',
                ['https://accounts.google.com', 'accounts.google.com'],
            );
        }
        if ($env->string('ENTRA_CLIENT_ID', '') !== '') {
            $tenants = $env->strings('ENTRA_TENANT_IDS');
            if ($tenants === []) {
                throw new \InvalidArgumentException('ENTRA_TENANT_IDS must allow at least one tenant UUID.');
            }
            $providers[] = new OidcProvider(
                'entra', $env->required('ENTRA_CLIENT_ID'), $env->required('ENTRA_CLIENT_SECRET'),
                $base . '/auth/entra/callback', 'https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize',
                'https://login.microsoftonline.com/organizations/oauth2/v2.0/token',
                'https://login.microsoftonline.com/common/discovery/v2.0/keys',
                array_map(static fn (string $tenant): string => 'https://login.microsoftonline.com/' . $tenant . '/v2.0', $tenants),
                $tenants,
            );
        }

        return new self($providers);
    }

    public function get(string $name): OidcProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->name === $name) {
                return $provider;
            }
        }

        throw new AuthenticationFailed('This identity provider is not configured.');
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (OidcProvider $provider): string => $provider->name, $this->providers);
    }
}
