<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Support;

use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use PhpOrbit\Auth\Oidc\OidcHttp;
use PhpOrbit\Auth\Oidc\OidcProvider;
use RuntimeException;

final class OidcFixture implements OidcHttp
{
    private readonly OpenSSLAsymmetricKey $key;
    private readonly string $modulus;
    private readonly string $exponent;
    public string $nonce = '';
    public string $issuer = 'https://id.example.test';
    public string $algorithm = 'RS256';
    public string $kid = 'test-key';
    public ?string $keyIssuer = null;
    /** @var array<string, scalar|list<string>|null> */
    public array $claims = [];
    /** @var list<array{url: string, form: array<string, string>}> */
    public array $requests = [];

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('Cannot generate test signing key.');
        }
        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['rsa']) || !is_array($details['rsa'])
            || !is_string($details['rsa']['n'] ?? null) || !is_string($details['rsa']['e'] ?? null)) {
            throw new RuntimeException('Cannot inspect test signing key.');
        }
        $this->key = $key;
        $this->modulus = JWT::urlsafeB64Encode($details['rsa']['n']);
        $this->exponent = JWT::urlsafeB64Encode($details['rsa']['e']);
    }

    public function provider(string $name = 'test'): OidcProvider
    {
        return new OidcProvider($name, 'test-client', 'test-secret', 'https://app.example.test/auth/' . $name . '/callback',
            'https://id.example.test/authorize', 'https://id.example.test/token', 'https://id.example.test/keys',
            [$this->issuer]);
    }

    public function request(string $url, array $form = []): string
    {
        $this->requests[] = ['url' => $url, 'form' => $form];
        if ($form !== []) {
            $claims = $this->claims + [
                'iss' => $this->issuer, 'sub' => 'external-user', 'aud' => 'test-client',
                'exp' => time() + 300, 'iat' => time(), 'nonce' => $this->nonce,
                'email' => 'ada@example.test',
            ];
            return json_encode(['id_token' => JWT::encode($claims,
                $this->algorithm === 'RS256' ? $this->key : str_repeat('x', 64), $this->algorithm, $this->kid)], JSON_THROW_ON_ERROR);
        }

        return json_encode(['keys' => [[
            'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-key',
            'n' => $this->modulus, 'e' => $this->exponent, 'issuer' => $this->keyIssuer,
        ]]], JSON_THROW_ON_ERROR);
    }
}
