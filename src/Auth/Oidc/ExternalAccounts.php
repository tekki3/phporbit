<?php

declare(strict_types=1);

namespace PhpOrbit\Auth\Oidc;

use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Database\Connection;

final class ExternalAccounts
{
    public function __construct(private readonly Connection $database, private readonly UserProvider $users)
    {
    }

    public function user(ExternalIdentity $external): ?Identity
    {
        $id = $this->database->query('auth_external_accounts')->where('external_key', '=', $external->key())->value('user_identifier');

        return is_string($id) ? $this->users->findByIdentifier($id) : null;
    }

    public function link(Identity $user, ExternalIdentity $external): void
    {
        if ($this->database->query('auth_external_accounts')->where('external_key', '=', $external->key())->first() !== null) {
            throw new AuthenticationFailed('This external account is already linked.');
        }
        $this->database->query('auth_external_accounts')->insert([
            'external_key' => $external->key(), 'user_identifier' => $user->authIdentifier(),
            'provider' => $external->provider, 'issuer' => $external->issuer, 'subject' => $external->subject,
        ]);
    }

    /** @return list<array<string, scalar|null>> */
    public function linked(string $user): array
    {
        return $this->database->query('auth_external_accounts')->where('user_identifier', '=', $user)->get();
    }

    public function unlink(string $user, int $id): void
    {
        if ($this->database->query('auth_external_accounts')->where('user_identifier', '=', $user)->where('id', '=', $id)->delete() !== 1) {
            throw new AuthenticationFailed('The linked account was not found.');
        }
    }
}
