<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Mail;

use InvalidArgumentException;
use PhpOrbit\Config\Environment;
use PhpOrbit\Mail\Address;
use PhpOrbit\Mail\ArrayMailer;
use PhpOrbit\Mail\MailFailed;
use PhpOrbit\Mail\Message;
use PhpOrbit\Mail\SmtpEncryption;
use PhpOrbit\Mail\SmtpMailer;
use PhpOrbit\Mail\SmtpSettings;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    // --- the in-memory mailer -------------------------------------------------

    public function test_the_array_mailer_collects_instead_of_sending(): void
    {
        $mailer = new ArrayMailer();

        $mailer->send(
            Message::to('ada@example.test')
                ->from('no-reply@example.test')
                ->subject('Welcome')
                ->text('Hello'),
        );

        self::assertSame(1, $mailer->count());
        self::assertTrue($mailer->sentTo('ada@example.test'));
        self::assertTrue($mailer->sentTo('ADA@EXAMPLE.TEST'), 'addresses compare case-insensitively');
        self::assertFalse($mailer->sentTo('nobody@example.test'));
        self::assertSame('Welcome', $mailer->last()?->subjectLine);
    }

    public function test_a_blind_recipient_still_counts_as_sent_to(): void
    {
        $mailer = new ArrayMailer();

        $mailer->send(
            Message::to('ada@example.test')
                ->from('no-reply@example.test')
                ->text('Hello')
                ->addBcc('audit@example.test'),
        );

        self::assertTrue($mailer->sentTo('audit@example.test'));
    }

    /**
     * A test double that accepts anything hides the mistakes a real send would
     * have caught.
     */
    public function test_the_array_mailer_still_validates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ArrayMailer())->send(Message::to('ada@example.test')->text('no sender'));
    }

    // --- settings -------------------------------------------------------------

    public function test_ports_default_by_encryption(): void
    {
        self::assertSame(587, (new SmtpSettings('mail.test'))->effectivePort());
        self::assertSame(
            465,
            (new SmtpSettings('mail.test', encryption: SmtpEncryption::Implicit))->effectivePort(),
        );
        self::assertSame(
            25,
            (new SmtpSettings('mail.test', encryption: SmtpEncryption::None))->effectivePort(),
        );
        self::assertSame(2525, (new SmtpSettings('mail.test', 2525))->effectivePort());
    }

    /**
     * SMTP AUTH base64-encodes the password, which is encoding rather than
     * encryption — anyone on the path can read it.
     */
    public function test_credentials_over_an_unencrypted_connection_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not encryption/');

        new SmtpSettings(
            'mail.test',
            username: 'ada',
            password: 'hunter2',
            encryption: SmtpEncryption::None,
        );
    }

    public function test_insecure_auth_can_be_opted_into_explicitly(): void
    {
        $settings = new SmtpSettings(
            'localhost',
            username: 'ada',
            password: 'hunter2',
            encryption: SmtpEncryption::None,
            allowInsecureAuth: true,
        );

        self::assertTrue($settings->needsAuthentication());
    }

    public function test_a_username_without_a_password_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/without MAIL_PASSWORD/');

        new SmtpSettings('mail.test', username: 'ada');
    }

    public function test_settings_are_read_from_the_environment(): void
    {
        $settings = SmtpSettings::fromEnvironment(Environment::fromArray([
            'MAIL_HOST' => 'smtp.example.test',
            'MAIL_PORT' => '2525',
            'MAIL_USERNAME' => 'ada',
            'MAIL_PASSWORD' => 'hunter2',
            'MAIL_ENCRYPTION' => 'tls',
            'MAIL_FROM_ADDRESS' => 'no-reply@example.test',
            'MAIL_FROM_NAME' => 'Orbit',
        ]));

        self::assertSame('smtp.example.test', $settings->host);
        self::assertSame(2525, $settings->effectivePort());
        self::assertTrue($settings->needsAuthentication());
        self::assertNotNull($settings->from);
        self::assertSame('no-reply@example.test', $settings->from->email);
        self::assertSame('Orbit', $settings->from->name);
    }

    public function test_an_unknown_encryption_names_the_supported_ones(): void
    {
        $this->expectExceptionMessageMatches('/tls, ssl, none/');

        SmtpSettings::fromEnvironment(Environment::fromArray([
            'MAIL_HOST' => 'smtp.example.test',
            'MAIL_ENCRYPTION' => 'quantum',
        ]));
    }

    /**
     * Settings travel into logs and stack traces the moment something fails.
     */
    public function test_the_password_is_redacted_from_debug_output(): void
    {
        $dumped = print_r(
            new SmtpSettings('mail.test', username: 'ada', password: 'hunter2'),
            true,
        );

        self::assertStringNotContainsString('hunter2', $dumped);
        self::assertStringContainsString('redacted', $dumped);
    }

    // --- transport ------------------------------------------------------------

    /**
     * The failure names the target, which is what makes it fixable, and
     * carries no credentials.
     */
    public function test_an_unreachable_server_reports_the_target(): void
    {
        $mailer = new SmtpMailer(new SmtpSettings(
            '127.0.0.1',
            // Almost certainly nothing listening here.
            port: 9,
            encryption: SmtpEncryption::None,
            from: new Address('no-reply@example.test'),
            timeoutSeconds: 1,
        ));

        try {
            $mailer->send(Message::to('ada@example.test')->subject('Hi')->text('Hello'));
            self::fail('the send should have failed');
        } catch (MailFailed $e) {
            self::assertStringContainsString('127.0.0.1:9', $e->getMessage());
        }
    }

    /**
     * A mailer with a default sender lets a message omit from() entirely.
     */
    public function test_the_configured_sender_is_applied_when_the_message_has_none(): void
    {
        $settings = new SmtpSettings(
            '127.0.0.1',
            port: 9,
            encryption: SmtpEncryption::None,
            from: new Address('no-reply@example.test', 'Orbit'),
            timeoutSeconds: 1,
        );

        // It reaches the transport rather than failing validation, which is
        // what proves the default was applied.
        $this->expectException(MailFailed::class);

        (new SmtpMailer($settings))->send(
            Message::to('ada@example.test')->subject('Hi')->text('Hello'),
        );
    }
}
