<?php

declare(strict_types=1);

namespace PhpOrbit\Auth\Oidc;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use InvalidArgumentException;
use JsonException;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Session\Session;
use SensitiveParameter;
use stdClass;
use UnexpectedValueException;

final class OidcClient
{
    public function __construct(
        private readonly OidcProviders $providers,
        private readonly OidcHttp $http,
        private readonly Session $session,
    ) {
    }

    public function start(string $providerName, ?string $linkUser = null, ?string $linkStamp = null): string
    {
        $provider = $this->providers->get($providerName);
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $this->session->regenerate();
        foreach ([
            'provider' => $providerName, 'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
            'expires' => time() + 300, 'link_user' => $linkUser ?? '', 'link_stamp' => $linkStamp ?? '',
        ] as $key => $value) {
            $this->session->set('_oidc_' . $key, $value);
        }

        return $provider->authorizationEndpoint . '?' . http_build_query([
            'client_id' => $provider->clientId, 'response_type' => 'code', 'scope' => 'openid profile email',
            'redirect_uri' => $provider->redirectUri, 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function finish(string $providerName, #[SensitiveParameter] string $state, #[SensitiveParameter] string $code): ExternalIdentity
    {
        $saved = [];
        foreach (['provider', 'state', 'nonce', 'verifier', 'expires', 'link_user', 'link_stamp'] as $key) {
            $saved[$key] = $this->session->get('_oidc_' . $key) ?? '';
            $this->session->remove('_oidc_' . $key);
        }
        if ($saved['provider'] !== $providerName || $state === '' || !hash_equals($saved['state'], $state)
            || (int) $saved['expires'] <= time() || $code === '' || strlen($code) > 8192) {
            throw new AuthenticationFailed('The sign-in request expired or does not match this browser. Start again.');
        }
        $provider = $this->providers->get($providerName);
        $form = [
            'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $provider->clientId,
            'redirect_uri' => $provider->redirectUri, 'code_verifier' => $saved['verifier'],
        ];
        if ($provider->clientSecret() !== '') {
            $form['client_secret'] = $provider->clientSecret();
        }
        $response = $this->object($this->http->request($provider->tokenEndpoint, $form));
        if (!is_string($response->id_token ?? null)) {
            throw new AuthenticationFailed('The provider did not return an ID token.');
        }
        $claims = $this->verify($provider, $response->id_token, $saved['nonce']);

        return new ExternalIdentity(
            $providerName, $claims->issuer, $claims->subject,
            $saved['link_user'] === '' ? null : $saved['link_user'],
            $saved['link_stamp'] === '' ? null : $saved['link_stamp'],
        );
    }

    private function verify(OidcProvider $provider, #[SensitiveParameter] string $token, #[SensitiveParameter] string $nonce): ExternalIdentity
    {
        $jwks = $this->object($this->http->request($provider->jwksEndpoint));
        $keys = [];
        $issuers = [];
        if (!is_array($jwks->keys ?? null)) {
            throw new AuthenticationFailed('The provider returned an invalid signing key set.');
        }
        foreach ($jwks->keys as $key) {
            if ($key instanceof stdClass && ($key->kty ?? null) === 'RSA'
                && ($key->alg ?? 'RS256') === 'RS256' && ($key->use ?? 'sig') === 'sig'
                && is_string($key->n ?? null) && is_string($key->e ?? null)
                && is_string($key->kid ?? null)) {
                $keys[] = (array) $key;
                $issuers[$key->kid] = $key->issuer ?? null;
            }
        }
        try {
            $headers = new stdClass();
            $claims = JWT::decode($token, JWK::parseKeySet(['keys' => $keys], 'RS256'), $headers);
        } catch (UnexpectedValueException | DomainException | InvalidArgumentException $exception) {
            throw new AuthenticationFailed('The provider returned an invalid ID token.', previous: $exception);
        }

        $issuer = $claims->iss ?? null;
        $subject = $claims->sub ?? null;
        $audience = $claims->aud ?? null;
        $audiences = is_string($audience) ? [$audience] : $audience;
        $kid = $headers?->kid;
        $keyIssuer = is_string($kid) ? ($issuers[$kid] ?? null) : null;
        if (!is_string($issuer) || !in_array($issuer, $provider->issuers, true)
            || !is_string($subject) || $subject === '' || strlen($subject) > 191
            || !is_array($audiences) || !in_array($provider->clientId, $audiences, true)
            || (isset($claims->azp) && $claims->azp !== $provider->clientId)
            || (count($audiences) > 1 && ($claims->azp ?? null) !== $provider->clientId)
            || !is_int($claims->exp ?? null) || $claims->exp <= time()
            || !is_int($claims->iat ?? null) || $claims->iat > time() || $claims->iat < time() - 600
            || !is_string($claims->nonce ?? null) || !hash_equals($nonce, $claims->nonce)) {
            throw new AuthenticationFailed('The provider returned an ID token for a different or expired sign-in.');
        }
        if ($provider->tenants !== []) {
            $tenant = $claims->tid ?? null;
            if (!is_string($tenant) || !in_array($tenant, $provider->tenants, true)
                || $issuer !== 'https://login.microsoftonline.com/' . $tenant . '/v2.0'
                || !is_string($keyIssuer) || str_replace('{tenantid}', $tenant, $keyIssuer) !== $issuer) {
                throw new AuthenticationFailed('This Microsoft Entra tenant is not allowed.');
            }
        }
        // Google historically uses both issuer spellings for the same identity.
        return new ExternalIdentity(
            $provider->name, $provider->name === 'google' ? 'https://accounts.google.com' : $issuer, $subject,
        );
    }

    private function object(string $json): stdClass
    {
        try {
            $value = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AuthenticationFailed('The provider returned invalid JSON.', previous: $exception);
        }
        if (!$value instanceof stdClass) {
            throw new AuthenticationFailed('The provider returned an invalid JSON object.');
        }

        return $value;
    }
}
