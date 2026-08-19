<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Mail;

use InvalidArgumentException;
use PhpOrbit\Database\Connection;
use PhpOrbit\Mail\ArrayMailer;
use PhpOrbit\Mail\MailFailed;
use PhpOrbit\Mail\MailLogRepository;
use PhpOrbit\Mail\Mailer;
use PhpOrbit\Mail\MailStatus;
use PhpOrbit\Mail\Message;
use PhpOrbit\Mail\PersistingMailer;
use PHPUnit\Framework\TestCase;

final class PersistingMailerTest extends TestCase
{
    private Connection $db;

    private MailLogRepository $repository;

    protected function setUp(): void
    {
        $this->db = Connection::sqlite(':memory:');
        $this->db->executeSchema(
            'CREATE TABLE mail_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                to_addresses TEXT NOT NULL,
                cc_addresses TEXT NOT NULL,
                bcc_addresses TEXT NOT NULL,
                from_address TEXT NULL,
                reply_to TEXT NULL,
                subject TEXT NOT NULL,
                text_body TEXT NULL,
                html_body TEXT NULL,
                attachments TEXT NOT NULL,
                headers TEXT NOT NULL,
                status TEXT NOT NULL,
                error TEXT NULL,
                attempts INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
        );

        $this->repository = new MailLogRepository($this->db);
    }

    public function test_a_successful_send_still_reaches_the_inner_mailer_and_is_logged(): void
    {
        $inner = new ArrayMailer();
        $mailer = new PersistingMailer($inner, $this->repository);
        $message = Message::to('ada@example.test')->from('a@b.test')->subject('Hi')->text('Hi');

        $mailer->send($message);

        self::assertSame(1, $inner->count());
        self::assertTrue($inner->sentTo('ada@example.test'));

        $logged = $this->repository->list();
        self::assertCount(1, $logged);
        self::assertSame(MailStatus::Sent, $logged[0]->status);
    }

    /**
     * The decorator must not swallow the failure: calling code that catches
     * MailFailed today has to keep working unchanged.
     */
    public function test_a_failed_send_is_logged_and_the_exception_still_propagates(): void
    {
        $mailer = new PersistingMailer($this->alwaysFails(), $this->repository);
        $message = Message::to('ada@example.test')->from('a@b.test')->subject('Hi')->text('Hi');

        try {
            $mailer->send($message);
            self::fail('MailFailed should have propagated');
        } catch (MailFailed $e) {
            self::assertStringContainsString('refused', $e->getMessage());
        }

        $logged = $this->repository->list();
        self::assertCount(1, $logged);
        self::assertSame(MailStatus::Failed, $logged[0]->status);
        self::assertStringContainsString('refused', (string) $logged[0]->error);
    }

    /**
     * A programming error — no recipients, no body — is the caller's bug, not
     * a delivery failure, so it must not be recorded as one.
     */
    public function test_a_validation_failure_is_not_logged(): void
    {
        $mailer = new PersistingMailer(new ArrayMailer(), $this->repository);

        try {
            $mailer->send(Message::create()->subject('No recipients'));
            self::fail('an unsendable message should have thrown');
        } catch (InvalidArgumentException) {
        }

        self::assertSame([], $this->repository->list());
    }

    public function test_resend_delivers_the_reconstructed_message_and_updates_the_row_in_place(): void
    {
        $inner = new ArrayMailer();
        $mailer = new PersistingMailer($inner, $this->repository);

        $id = $this->repository->record(
            Message::to('ada@example.test')->from('a@b.test')->subject('Hi')->text('Hi'),
            MailStatus::Failed,
            'earlier failure',
        );

        $result = $mailer->resend($id);

        self::assertSame(MailStatus::Sent, $result->status);
        self::assertSame(2, $result->attempts);
        self::assertNull($result->error);
        self::assertSame(1, $inner->count());
        self::assertTrue($inner->sentTo('ada@example.test'));

        // One row throughout — a resend is another attempt, not a new message.
        self::assertSame(1, $this->db->query('mail_log')->count());
    }

    public function test_resend_refuses_an_unknown_id(): void
    {
        $mailer = new PersistingMailer(new ArrayMailer(), $this->repository);

        $this->expectException(InvalidArgumentException::class);

        $mailer->resend(999);
    }

    /**
     * Resending mail already marked Sent would deliver it twice with no
     * record that it happened — the one guarantee the log exists to keep.
     */
    public function test_resend_refuses_mail_that_is_not_currently_failed(): void
    {
        $mailer = new PersistingMailer(new ArrayMailer(), $this->repository);

        $id = $this->repository->record(
            Message::to('ada@example.test')->from('a@b.test')->subject('Hi')->text('Hi'),
            MailStatus::Sent,
        );

        try {
            $mailer->resend($id);
            self::fail('resending an already-sent message should be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('only failed mail can be resent', $e->getMessage());
        }
    }

    public function test_resend_that_fails_again_increments_attempts_and_keeps_the_error(): void
    {
        $mailer = new PersistingMailer($this->alwaysFails(), $this->repository);

        $id = $this->repository->record(
            Message::to('ada@example.test')->from('a@b.test')->subject('Hi')->text('Hi'),
            MailStatus::Failed,
            'first failure',
        );

        try {
            $mailer->resend($id);
            self::fail('MailFailed should have propagated');
        } catch (MailFailed) {
        }

        $entry = $this->repository->find($id);
        self::assertNotNull($entry);
        self::assertSame(MailStatus::Failed, $entry->status);
        self::assertSame(2, $entry->attempts);
        self::assertStringContainsString('refused', (string) $entry->error);
    }

    public function test_history_delegates_to_the_repository(): void
    {
        $mailer = new PersistingMailer(new ArrayMailer(), $this->repository);

        $mailer->send(Message::to('ada@example.test')->from('a@b.test')->subject('Hi')->text('Hi'));

        self::assertCount(1, $mailer->history());
        self::assertCount(1, $mailer->history(MailStatus::Sent));
        self::assertCount(0, $mailer->history(MailStatus::Failed));
    }

    /**
     * A project that has never run `orbit migrate` has no `mail_log` table
     * yet. That must not be the reason a real, successful send is reported to
     * the caller as a failure — nor may it replace an already-thrown
     * MailFailed with something a caller's `catch (MailFailed)` no longer
     * matches.
     */
    public function test_a_missing_mail_log_table_does_not_break_a_successful_send(): void
    {
        $inner = new ArrayMailer();
        $mailer = new PersistingMailer($inner, new MailLogRepository(Connection::sqlite(':memory:')));

        $mailer->send(Message::to('ada@example.test')->from('a@b.test')->subject('Hi')->text('Hi'));

        self::assertSame(1, $inner->count());
    }

    public function test_a_missing_mail_log_table_does_not_change_what_a_failed_send_throws(): void
    {
        $mailer = new PersistingMailer($this->alwaysFails(), new MailLogRepository(Connection::sqlite(':memory:')));

        $this->expectException(MailFailed::class);

        $mailer->send(Message::to('ada@example.test')->from('a@b.test')->subject('Hi')->text('Hi'));
    }

    private function alwaysFails(): Mailer
    {
        return new class implements Mailer {
            public function send(Message $message): void
            {
                throw MailFailed::atStep('DATA', '550 refused');
            }
        };
    }
}
