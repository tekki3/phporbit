<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

use PhpOrbit\Database\Connection;

/**
 * Persistent security versions invalidate sessions and pending challenges.
 * User identifiers are hashed so any Identity implementation fits the schema.
 */
final class SecurityState
{
    public function __construct(private readonly Connection $database)
    {
    }

    public function version(string $user): string
    {
        return (string) ($this->row($user)['version'] ?? 'initial');
    }

    /** @return array<string, scalar|null>|null */
    public function row(string $user): ?array
    {
        return $this->database->query('auth_security')->where('user_key', '=', self::key($user))->first();
    }

    public function revoke(string $user): void
    {
        $row = $this->row($user);

        if ($row === null) {
            $this->database->query('auth_security')->insert([
                'user_key' => self::key($user), 'version' => bin2hex(random_bytes(16)),
                'totp_secret' => null, 'last_step' => -1,
            ]);
        } else {
            $this->database->query('auth_security')->where('user_key', '=', self::key($user))
                ->update(['version' => bin2hex(random_bytes(16))]);
        }
    }

    public static function key(string $user): string
    {
        return hash('sha256', $user);
    }
}
