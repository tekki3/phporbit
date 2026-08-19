<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

use PhpOrbit\Config\Environment;
use PhpOrbit\Config\MissingConfiguration;

/**
 * Builds the mailer named by `MAIL_DRIVER`.
 *
 * `array` is the default, and deliberately so: a development machine that
 * silently starts delivering real mail to real people is a worse failure than
 * one that sends nothing. Choosing `smtp` is something you write down.
 */
final class MailerFactory
{
    public static function fromEnvironment(Environment $config): Mailer
    {
        $driver = strtolower(trim($config->string('MAIL_DRIVER', 'array')));

        return match ($driver) {
            'array', 'memory', 'null' => new ArrayMailer(),
            'smtp' => new SmtpMailer(SmtpSettings::fromEnvironment($config)),
            default => throw MissingConfiguration::notOfType('MAIL_DRIVER', 'mail driver', 'array, smtp'),
        };
    }
}
