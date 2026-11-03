<?php

declare(strict_types=1);

namespace PhpOrbit\Api;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key as JwtKey;
use InvalidArgumentException;
use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\SecurityState;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Crypto\Key;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Direction;
use PhpOrbit\Http\Status;
use SensitiveParameter;
use UnexpectedValueException;
use DomainException;

final class Tokens
{
    /** @param Key|Closure(): Key $key */
    public function __construct(
        private readonly Connection $database,
        private readonly UserProvider $users,
        private readonly SecurityState $state,
        private readonly Key|Closure $key,
        private readonly string $issuer,
        private readonly string $audience,
    ) {
        if ($issuer === '' || $audience === '') {
            throw new InvalidArgumentException('API issuer and audience must not be empty.');
        }
    }

    /** @param list<string> $scopes */
    public function jwt(Identity $user, array $scopes = [], int $ttlSeconds = 900, ?string $securityVersion = null): IssuedToken
    {
        return $this->issue($user, 'jwt', 'Access token', $scopes, $ttlSeconds, $securityVersion);
    }

    /** @param list<string> $scopes */
    public function personal(Identity $user, string $name, array $scopes = [], int $ttlSeconds = 2592000): IssuedToken
    {
        return $this->issue($user, 'personal', $name, $scopes, $ttlSeconds);
    }

    public function authenticate(#[SensitiveParameter] string $token): Principal
    {
        if ($token === '' || strlen($token) > 8192) {
            throw $this->invalid();
        }
        $row = $this->database->query('api_tokens')->where('token_hash', '=', hash('sha256', $token))->first();
        if ($row === null || (int) $row['expires_at'] <= time()) {
            throw $this->invalid();
        }
        if ($row['token_kind'] === 'jwt') {
            try {
                $claims = JWT::decode($token, new JwtKey($this->signingKey()->bytes(), 'HS256'));
            } catch (UnexpectedValueException | DomainException) {
                throw $this->invalid();
            }
            if (($claims->iss ?? null) !== $this->issuer || ($claims->aud ?? null) !== $this->audience
                || !is_string($claims->sub ?? null) || ($claims->jti ?? null) !== $row['token_id']
                || !is_int($claims->iat ?? null) || $claims->iat > time()
                || !is_int($claims->exp ?? null) || $claims->exp !== (int) $row['expires_at']) {
                throw $this->invalid();
            }
            $identifier = $claims->sub;
        } elseif ($row['token_kind'] === 'personal' && preg_match('/^orbit_pat_([a-f0-9]{32})_[a-f0-9]{64}$/D', $token, $match) === 1) {
            $identifier = $this->identifier($row);
            if ($match[1] !== $row['token_id']) {
                throw $this->invalid();
            }
        } else {
            throw $this->invalid();
        }
        $user = $this->users->findByIdentifier($identifier);
        if ($user === null || SecurityState::key($identifier) !== $row['user_key']
            || !hash_equals((string) $row['security_stamp'], $this->stamp($user))) {
            throw $this->invalid();
        }
        $scopes = $this->scopes((string) $row['scopes']);

        return new Principal($user, (string) $row['token_id'], $scopes);
    }

    public function revoke(string $user, string $tokenId): void
    {
        $this->database->query('api_tokens')->where('user_key', '=', SecurityState::key($user))
            ->where('token_id', '=', $tokenId)->delete();
    }

    /** @return list<array<string, scalar|null>> */
    public function listed(string $user): array
    {
        return $this->database->query('api_tokens')->select('token_id', 'token_kind', 'token_name', 'created_at', 'expires_at')
            ->where('user_key', '=', SecurityState::key($user))->where('expires_at', '>', time())
            ->orderBy('created_at', Direction::Descending)->limit(100)->get();
    }

    /** @param list<string> $scopes */
    private function issue(Identity $user, string $kind, string $name, array $scopes, int $ttl, ?string $securityVersion = null): IssuedToken
    {
        if ($ttl < 1 || $ttl > ($kind === 'jwt' ? 3600 : 7776000) || trim($name) === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Use a token name of 1–100 characters and a valid token lifetime.');
        }
        foreach ($scopes as $scope) {
            if (preg_match('/^[a-z][a-z0-9:._-]{0,99}$/D', $scope) !== 1) {
                throw new InvalidArgumentException('Invalid API scope.');
            }
        }
        $id = bin2hex(random_bytes(16));
        $securityVersion ??= $this->state->version($user->authIdentifier());
        if ($securityVersion !== $this->state->version($user->authIdentifier())) {
            throw new ApiError(Status::Unauthorized, 'Account security changed. Authenticate again.');
        }
        $now = time();
        $expires = $now + $ttl;
        $value = $kind === 'jwt'
            ? JWT::encode(['iss' => $this->issuer, 'aud' => $this->audience, 'sub' => $user->authIdentifier(),
                'iat' => $now, 'nbf' => $now, 'exp' => $expires, 'jti' => $id, 'scope' => implode(' ', $scopes)],
                $this->signingKey()->bytes(), 'HS256')
            : 'orbit_pat_' . $id . '_' . bin2hex(random_bytes(32));
        $this->database->query('api_tokens')->where('expires_at', '<=', $now)->delete();
        if ($this->database->query('api_tokens')->where('user_key', '=', SecurityState::key($user->authIdentifier()))->count() >= 100) {
            throw new ApiError(Status::TooManyRequests, 'Revoke unused tokens before creating another.');
        }
        $this->database->query('api_tokens')->insert([
            'token_id' => $id, 'token_hash' => hash('sha256', $value), 'user_key' => SecurityState::key($user->authIdentifier()),
            'token_kind' => $kind, 'token_name' => trim($name),
            'scopes' => json_encode(['subject' => $user->authIdentifier(), 'scopes' => $scopes], JSON_THROW_ON_ERROR),
            'security_stamp' => hash('sha256', $user->passwordHash() . ':' . $securityVersion),
            'created_at' => $now, 'expires_at' => $expires,
        ]);

        return new IssuedToken($value, $id, $expires);
    }

    private function stamp(Identity $user): string
    {
        return hash('sha256', $user->passwordHash() . ':' . $this->state->version($user->authIdentifier()));
    }

    private function signingKey(): Key
    {
        $key = $this->key instanceof Key ? $this->key : ($this->key)();

        return $key->derive('api-jwt-hs256-v1');
    }

    /** @param array<string, scalar|null> $row */
    private function identifier(array $row): string
    {
        $data = json_decode((string) $row['scopes'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['subject'] ?? null)) {
            throw new \RuntimeException('Stored API token metadata is invalid.');
        }

        return $data['subject'];
    }

    /** @return list<string> */
    private function scopes(string $encoded): array
    {
        $data = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['scopes'] ?? null) || !array_is_list($data['scopes'])) {
            throw new \RuntimeException('Stored API scopes are invalid.');
        }
        $scopes = [];
        foreach ($data['scopes'] as $scope) {
            if (!is_string($scope)) {
                throw new \RuntimeException('Stored API scope is invalid.');
            }
            $scopes[] = $scope;
        }

        return $scopes;
    }

    private function invalid(): ApiError
    {
        return new ApiError(Status::Unauthorized, 'Invalid or expired Bearer token.');
    }
}
