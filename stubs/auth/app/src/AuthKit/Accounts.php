<?php

declare(strict_types=1);

namespace App\AuthKit;

use InvalidArgumentException;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\SecurityState;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Database\Connection;

final class Accounts implements UserProvider
{
    public function __construct(private readonly Connection $database, private readonly SecurityState $state)
    {
    }

    public function findByIdentifier(string $identifier): ?User
    {
        if (preg_match('/^[1-9][0-9]*$/D', $identifier) !== 1) {
            return null;
        }

        return $this->hydrate($this->database->query('auth_users')->where('id', '=', (int) $identifier)->where('blocked', '=', 0)->first());
    }

    public function findByEmail(string $email): ?User
    {
        return $this->hydrate($this->database->query('auth_users')->where('email_key', '=', self::emailKey($email))->where('blocked', '=', 0)->first());
    }

    public function updatePasswordHash(Identity $user, string $hash): void
    {
        $this->database->query('auth_users')->where('id', '=', (int) $user->authIdentifier())->update(['password_hash' => $hash]);
    }

    public function rename(string $identifier, string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Choose a display name between 1 and 100 characters.');
        }
        $this->database->query('auth_users')->where('id', '=', (int) $identifier)->update(['display_name' => $name]);
    }

    /** @return list<array<string, scalar|null>> */
    public function page(int $page): array
    {
        return $this->database->query('auth_users')->select('id', 'email', 'display_name', 'is_admin', 'blocked')
            ->orderBy('id')->limit(25)->offset((max(1, $page) - 1) * 25)->get();
    }

    public function manage(string $actorId, int $targetId, string $action): void
    {
        $this->database->transaction(function () use ($actorId, $targetId, $action): void {
            $this->lock();
            $actor = $this->findByIdentifier($actorId);
            if ($actor === null || !$actor->isAdmin) {
                throw new AuthenticationFailed('Administrator access is required.');
            }
            if ($actor->id === $targetId) {
                throw new AuthenticationFailed('You cannot change your own administrator or blocked status.');
            }
            $target = $this->database->query('auth_users')->where('id', '=', $targetId)->first();
            if ($target === null) {
                throw new AuthenticationFailed('The user was not found.');
            }
            $changes = match ($action) {
                'block' => ['blocked' => 1],
                'unblock' => ['blocked' => 0],
                'promote' => ['is_admin' => 1],
                'demote' => ['is_admin' => 0],
                default => throw new InvalidArgumentException('Choose block, unblock, promote or demote.'),
            };
            if ((int) $target['is_admin'] === 1 && (int) $target['blocked'] === 0
                && in_array($action, ['block', 'demote'], true)
                && $this->database->query('auth_users')->where('is_admin', '=', 1)->where('blocked', '=', 0)->count() <= 1) {
                throw new AuthenticationFailed('The last active administrator cannot be removed.');
            }
            $this->database->query('auth_users')->where('id', '=', $targetId)->update($changes);
            $this->state->revoke((string) $targetId);
        });
    }

    private function lock(): void
    {
        if ($this->database->query('auth_management')->where('id', '=', 1)->update(['lock_version' => bin2hex(random_bytes(16))]) !== 1) {
            throw new AuthenticationFailed('The administrator lock row is missing. Restore the authentication schema.');
        }
    }

    public static function emailKey(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    /** @param array<string, scalar|null>|null $row */
    private function hydrate(?array $row): ?User
    {
        return $row === null ? null : new User(
            (int) $row['id'], (string) $row['email'], (string) $row['display_name'],
            (int) $row['is_admin'] === 1, (string) $row['password_hash'],
        );
    }
}
