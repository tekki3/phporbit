<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\SecurityState;
use PhpOrbit\Database\Connection;

final class AuthAdmin
{
    public function __construct(private readonly Connection $database)
    {
    }

    public function promote(string $email): void
    {
        $this->database->transaction(function () use ($email): void {
            if ($this->database->query('auth_management')->where('id', '=', 1)->update(['lock_version' => bin2hex(random_bytes(16))]) !== 1) {
                throw new AuthenticationFailed('The authentication schema is incomplete. Run orbit migrate.');
            }
            $user = $this->database->query('auth_users')->where('email_key', '=', hash('sha256', mb_strtolower(trim($email))))
                ->where('blocked', '=', 0)->first();
            if ($user === null) {
                throw new AuthenticationFailed('Register and verify this account before promoting it.');
            }
            $id = (int) $user['id'];
            $this->database->query('auth_users')->where('id', '=', $id)->update(['is_admin' => 1]);
            (new SecurityState($this->database))->revoke((string) $id);
        });
    }
}
