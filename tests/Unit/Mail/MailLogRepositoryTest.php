<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Mail;

use PhpOrbit\Database\Connection;
use PhpOrbit\Mail\Attachment;
use PhpOrbit\Mail\MailLogRepository;
use PhpOrbit\Mail\MailStatus;
use PhpOrbit\Mail\Message;
use PHPUnit\Framework\TestCase;

final class MailLogRepositoryTest extends TestCase
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

    public function test_it_records_a_message_and_reads_it_back(): void
    {
        $message = Message::to('Ada Lovelace <ada@example.test>')
            ->addCc('team@example.test')
            ->from('no-reply@example.test')
            ->replyTo('support@example.test')
            ->subject('Welcome')
            ->html('<p>Hi</p>', 'Hi')
            ->header('X-Campaign', 'onboarding');

        $id = $this->repository->record($message, MailStatus::Sent);
        $entry = $this->repository->find($id);

        self::assertNotNull($entry);
        self::assertSame(MailStatus::Sent, $entry->status);
        self::assertNull($entry->error);
        self::assertSame(1, $entry->attempts);
        self::assertSame('Welcome', $entry->subjectLine);
        self::assertSame('Hi', $entry->textBody);
        self::assertSame('<p>Hi</p>', $entry->htmlBody);
        self::assertSame('onboarding', $entry->headers['X-Campaign'] ?? null);

        self::assertCount(1, $entry->to);
        self::assertSame('ada@example.test', $entry->to[0]->email);
        self::assertSame('Ada Lovelace', $entry->to[0]->name);
        self::assertCount(1, $entry->cc);
        self::assertSame('team@example.test', $entry->cc[0]->email);
        self::assertSame('no-reply@example.test', $entry->from?->email);
        self::assertSame('support@example.test', $entry->replyTo?->email);
    }

    public function test_a_failed_send_is_recorded_with_its_reason(): void
    {
        $message = Message::to('ada@example.test')->from('a@b.test')->subject('Hi')->text('Hi');

        $id = $this->repository->record($message, MailStatus::Failed, 'SMTP DATA failed: 550 refused');
        $entry = $this->repository->find($id);

        self::assertNotNull($entry);
        self::assertSame(MailStatus::Failed, $entry->status);
        self::assertSame('SMTP DATA failed: 550 refused', $entry->error);
    }

    public function test_attachments_round_trip_byte_for_byte(): void
    {
        $message = Message::to('ada@example.test')
            ->from('a@b.test')
            ->subject('Invoice')
            ->text('Attached.')
            ->attach(Attachment::fromString('invoice.pdf', "not really a pdf\x00\xff", 'application/pdf'));

        $id = $this->repository->record($message, MailStatus::Sent);
        $entry = $this->repository->find($id);

        self::assertNotNull($entry);
        self::assertCount(1, $entry->attachments);
        self::assertSame('invoice.pdf', $entry->attachments[0]->filename);
        self::assertSame('application/pdf', $entry->attachments[0]->mediaType);
        self::assertSame("not really a pdf\x00\xff", $entry->attachments[0]->contents);
    }

    public function test_finding_an_unknown_id_returns_null(): void
    {
        self::assertNull($this->repository->find(999));
    }

    public function test_recordResend_updates_the_same_row_rather_than_writing_a_new_one(): void
    {
        $message = Message::to('ada@example.test')->from('a@b.test')->subject('Hi')->text('Hi');
        $id = $this->repository->record($message, MailStatus::Failed, 'first failure');

        $this->repository->recordResend($id, MailStatus::Sent, null, 2);

        $entry = $this->repository->find($id);

        self::assertNotNull($entry);
        self::assertSame(MailStatus::Sent, $entry->status);
        self::assertNull($entry->error);
        self::assertSame(2, $entry->attempts);
        self::assertSame(1, $this->db->query('mail_log')->count());
    }

    public function test_list_is_most_recent_first_and_can_be_filtered_by_status(): void
    {
        $message = static fn (string $to): Message => Message::to($to)->from('a@b.test')->subject('Hi')->text('Hi');

        $this->repository->record($message('one@example.test'), MailStatus::Sent);
        $failedId = $this->repository->record($message('two@example.test'), MailStatus::Failed, 'boom');
        $this->repository->record($message('three@example.test'), MailStatus::Sent);

        $all = $this->repository->list();
        self::assertSame(['three@example.test', 'two@example.test', 'one@example.test'], array_map(
            static fn ($entry) => $entry->to[0]->email,
            $all,
        ));

        $failedOnly = $this->repository->list(MailStatus::Failed);
        self::assertCount(1, $failedOnly);
        self::assertSame($failedId, $failedOnly[0]->id);
    }

    public function test_list_respects_the_limit(): void
    {
        $message = Message::to('ada@example.test')->from('a@b.test')->subject('Hi')->text('Hi');

        for ($i = 0; $i < 5; $i++) {
            $this->repository->record($message, MailStatus::Sent);
        }

        self::assertCount(2, $this->repository->list(limit: 2));
    }
}
