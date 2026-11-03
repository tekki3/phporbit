<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

use Closure;
use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Database\Connection;
use SensitiveParameter;

final class Mfa
{
    /** @param Encrypter|Closure(): Encrypter $encrypter */
    public function __construct(
        private readonly Connection $database,
        private readonly SecurityState $state,
        private readonly Encrypter|Closure $encrypter,
    ) {
    }

    public function enabled(string $user): bool
    {
        return is_string($this->state->row($user)['totp_secret'] ?? null);
    }

    /** @return list<string> Recovery codes, shown once after a successful enrollment. */
    public function enable(string $user, #[SensitiveParameter] string $secret, string $code): array
    {
        $step = Totp::match($secret, $code, time());

        if ($step === null || $this->enabled($user)) {
            throw new AuthenticationFailed('Cannot enable MFA. Check the code and enrollment status.');
        }

        $codes = [];
        for ($index = 0; $index < 10; $index++) {
            $codes[] = bin2hex(random_bytes(10));
        }

        $this->database->transaction(function () use ($user, $secret, $step, $codes): void {
            $this->state->revoke($user);
            $updated = $this->database->query('auth_security')
                ->where('user_key', '=', SecurityState::key($user))->whereNull('totp_secret')
                ->update([
                    'totp_secret' => $this->encrypter()->encrypt($secret, 'totp:' . $user),
                    'last_step' => $step,
                ]);
            if ($updated !== 1) {
                throw new AuthenticationFailed('MFA enrollment changed. Start again.');
            }
            foreach ($codes as $recovery) {
                $this->database->query('auth_recovery_codes')->insert([
                    'user_key' => SecurityState::key($user), 'code_hash' => hash('sha256', $recovery),
                ]);
            }
        });

        return $codes;
    }

    /** Checks TOTP or atomically consumes a recovery code. */
    public function verify(string $user, #[SensitiveParameter] string $code): bool
    {
        $row = $this->state->row($user);
        $encrypted = $row['totp_secret'] ?? null;
        $version = $row['version'] ?? null;

        if (!is_string($encrypted) || !is_string($version)) {
            return false;
        }

        if (preg_match('/^[a-f0-9]{20}$/D', $code) === 1) {
            return $this->database->query('auth_recovery_codes')->where('user_key', '=', SecurityState::key($user))
                ->where('code_hash', '=', hash('sha256', $code))->delete() === 1;
        }

        $secret = $this->encrypter()->decrypt($encrypted, 'totp:' . $user);
        $step = Totp::match($secret, $code, time());
        if ($step === null) {
            return false;
        }

        return $this->database->query('auth_security')
            ->where('user_key', '=', SecurityState::key($user))
            ->where('version', '=', $version)
            ->where('last_step', '<', $step)->update(['last_step' => $step]) === 1;
    }

    public function disable(string $user, #[SensitiveParameter] string $code): void
    {
        $this->database->transaction(function () use ($user, $code): void {
            if (!$this->verify($user, $code)) {
                throw new AuthenticationFailed('The authenticator or recovery code is invalid or has already been used.');
            }
            $this->state->revoke($user);
            $this->database->query('auth_security')->where('user_key', '=', SecurityState::key($user))
                ->update(['totp_secret' => null, 'last_step' => -1]);
            $this->database->query('auth_recovery_codes')->where('user_key', '=', SecurityState::key($user))->delete();
        });
    }

    private function encrypter(): Encrypter
    {
        return $this->encrypter instanceof Encrypter ? $this->encrypter : ($this->encrypter)();
    }
}
