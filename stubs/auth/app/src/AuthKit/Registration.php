<?php

declare(strict_types=1);

namespace App\AuthKit;

use InvalidArgumentException;
use PhpOrbit\Auth\AuthenticationFailed;
use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Config\Environment;
use PhpOrbit\Database\Connection;
use PhpOrbit\Log\Level;
use PhpOrbit\Log\Logger;
use PhpOrbit\Mail\Mailer;
use PhpOrbit\Mail\MailFailed;
use PhpOrbit\Mail\Message;
use SensitiveParameter;

final class Registration
{
    public function __construct(
        private readonly Connection $database, private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle, private readonly Mailer $mailer,
        private readonly Environment $env, private readonly Logger $logger,
    ) {
    }

    public function request(string $email, string $name, #[SensitiveParameter] string $password): void
    {
        $email = mb_strtolower(trim($email));
        $name = trim($name);
        if (strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $name === '' || mb_strlen($name) > 100 || strlen($password) < 12 || strlen($password) > 72) {
            throw new InvalidArgumentException('Enter a valid email, a display name of 1–100 characters and a password of 12–72 bytes.');
        }
        if ($this->env->string('MAIL_DRIVER', 'array') === 'array') {
            throw new AuthenticationFailed('Registration email is unavailable. The site operator must configure SMTP.');
        }
        $base = rtrim($this->env->required('APP_URL'), '/');
        $host = parse_url($base, PHP_URL_HOST);
        $scheme = parse_url($base, PHP_URL_SCHEME);
        if ($host === null || ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)))
            || parse_url($base, PHP_URL_QUERY) !== null || parse_url($base, PHP_URL_FRAGMENT) !== null) {
            throw new AuthenticationFailed('Registration requires a valid HTTPS APP_URL (HTTP allowed on loopback).');
        }
        $key = 'register:' . $email;
        if ($this->throttle->tooManyAttempts($key)) {
            return;
        }
        $this->throttle->record($key);
        $hash = $this->hasher->hash($password);
        $emailKey = Accounts::emailKey($email);
        if ($this->database->query('auth_users')->where('email_key', '=', $emailKey)->count() !== 0) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $this->database->query('auth_registrations')->where('expires_at', '<=', time())->delete();
        $this->database->query('auth_registrations')->insert([
            'email' => $email, 'email_key' => $emailKey, 'display_name' => $name, 'password_hash' => $hash,
            'token_hash' => $tokenHash, 'expires_at' => time() + 1800,
        ]);
        try {
            $this->mailer->send(Message::to($email)->from($this->env->string('MAIL_FROM_ADDRESS', 'noreply@example.test'))
                ->subject('Verify your account')
                ->text("Confirm your email to create your account:\n\n" . $base . '/register/verify?token=' . $token
                    . "\n\nThis link works once and expires in 30 minutes. Ignore it if you did not register."));
        } catch (MailFailed) {
            $this->database->query('auth_registrations')->where('token_hash', '=', $tokenHash)->delete();
            $this->logger->log(Level::Error, 'Registration verification email delivery failed.');
        }
    }

    public function verify(#[SensitiveParameter] string $token): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new AuthenticationFailed('Verification link invalid, expired or already used.');
        }
        $this->database->transaction(function () use ($token): void {
            // Serializes account creation and administration across workers.
            if ($this->database->query('auth_management')->where('id', '=', 1)->update(['lock_version' => bin2hex(random_bytes(16))]) !== 1) {
                throw new AuthenticationFailed('The authentication schema is incomplete.');
            }
            $query = $this->database->query('auth_registrations')->where('token_hash', '=', hash('sha256', $token));
            $row = $query->first();
            if ($row === null || (int) $row['expires_at'] <= time()) {
                throw new AuthenticationFailed('Verification link invalid, expired or already used.');
            }
            if ($query->delete() !== 1 || $this->database->query('auth_users')->where('email_key', '=', $row['email_key'])->count() !== 0) {
                throw new AuthenticationFailed('Verification link invalid, expired or account already exists.');
            }
            $this->database->query('auth_users')->insert([
                'email' => $row['email'], 'email_key' => $row['email_key'],
                'display_name' => $row['display_name'], 'password_hash' => $row['password_hash'],
                'is_admin' => 0, 'blocked' => 0,
            ]);
            $this->database->query('auth_registrations')->where('email_key', '=', $row['email_key'])->delete();
        });
    }
}
