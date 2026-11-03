<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use PhpOrbit\Auth\EmailTokens;
use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Config\Environment;
use PhpOrbit\Log\Level;
use PhpOrbit\Log\Logger;
use PhpOrbit\Mail\MailFailed;
use PhpOrbit\Mail\Mailer;
use PhpOrbit\Mail\Message;

final class AuthEmails
{
    public function __construct(
        private readonly EmailTokens $tokens,
        private readonly UserProvider $users,
        private readonly LoginThrottle $throttle,
        private readonly Mailer $mailer,
        private readonly Environment $env,
        private readonly Logger $logger,
    ) {
        $url = $env->string('APP_URL', 'http://localhost:8080');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)))
            || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new InvalidArgumentException('Authentication emails require an absolute HTTPS APP_URL (HTTP allowed on loopback).');
        }
    }

    public function request(string $email, string $purpose): void
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        $key = 'email:' . $email;
        if ($this->throttle->tooManyAttempts($key)) {
            return;
        }
        $this->throttle->record($key);
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return;
        }
        if ($this->env->string('MAIL_DRIVER', 'array') === 'array') {
            $this->logger->log(Level::Warning, 'Authentication email was not delivered: configure MAIL_DRIVER=smtp.');

            return;
        }
        $token = $this->tokens->issue($user, $purpose);
        $path = $purpose === 'password-reset' ? '/password/reset' : '/magic/confirm';
        $url = rtrim($this->env->string('APP_URL', 'http://localhost:8080'), '/') . $path . '?token=' . $token;
        $subject = $purpose === 'password-reset' ? 'Reset your phporbit password' : 'Your phporbit sign-in link';
        try {
            $this->mailer->send(Message::to($email)
                ->from($this->env->string('MAIL_FROM_ADDRESS', 'noreply@example.test'))
                ->subject($subject)
                ->text($subject . "\n\n" . $url . "\n\nThis link expires in 15 minutes and works once. If you did not request it, ignore this email."));
        } catch (MailFailed) {
            $this->logger->log(Level::Error, 'Authentication email delivery failed.');
            // Same browser response for missing accounts and delivery errors.
        }
    }
}
