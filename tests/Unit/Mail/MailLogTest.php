<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Mail;

use PhpOrbit\Mail\Address;
use PhpOrbit\Mail\Attachment;
use PhpOrbit\Mail\MailLog;
use PhpOrbit\Mail\MailStatus;
use PHPUnit\Framework\TestCase;

final class MailLogTest extends TestCase
{
    public function test_toMessage_rebuilds_an_equivalent_message(): void
    {
        $log = new MailLog(
            1,
            [new Address('ada@example.test', 'Ada Lovelace')],
            [new Address('team@example.test')],
            [new Address('audit@example.test')],
            new Address('no-reply@example.test'),
            new Address('support@example.test'),
            'Your invoice',
            'Attached.',
            '<p>Attached.</p>',
            [Attachment::fromString('invoice.pdf', 'contents', 'application/pdf')],
            ['X-Campaign' => 'invoices'],
            MailStatus::Failed,
            'SMTP DATA failed',
            1,
            '2026-01-01T00:00:00+00:00',
            '2026-01-01T00:00:00+00:00',
        );

        $message = $log->toMessage();

        self::assertSame('ada@example.test', $message->to[0]->email);
        self::assertSame('Ada Lovelace', $message->to[0]->name);
        self::assertSame('team@example.test', $message->cc[0]->email);
        self::assertSame('audit@example.test', $message->bcc[0]->email);
        self::assertSame('no-reply@example.test', $message->from?->email);
        self::assertSame('support@example.test', $message->replyTo?->email);
        self::assertSame('Your invoice', $message->subjectLine);
        // Both bodies survive independently — the case that breaks if text()
        // and html() are applied in the wrong order.
        self::assertSame('Attached.', $message->textBody);
        self::assertSame('<p>Attached.</p>', $message->htmlBody);
        self::assertCount(1, $message->attachments);
        self::assertSame('invoice.pdf', $message->attachments[0]->filename);
        self::assertSame('contents', $message->attachments[0]->contents);
        self::assertSame('invoices', $message->headers['X-Campaign'] ?? null);
    }

    /**
     * An HTML-only message must not gain a phantom text body just because
     * toMessage() calls text() and html() in sequence.
     */
    public function test_toMessage_does_not_invent_a_text_body(): void
    {
        $log = $this->logWith(textBody: null, htmlBody: '<p>Hi</p>');

        self::assertNull($log->toMessage()->textBody);
        self::assertSame('<p>Hi</p>', $log->toMessage()->htmlBody);
    }

    public function test_fromRow_tolerates_missing_and_malformed_json(): void
    {
        $log = MailLog::fromRow([
            'id' => 7,
            'to_addresses' => 'not json at all',
            'cc_addresses' => null,
            'bcc_addresses' => '[]',
            'from_address' => null,
            'reply_to' => null,
            'subject' => 'Hi',
            'text_body' => null,
            'html_body' => null,
            'attachments' => '[{"filename": 5, "mediaType": "text/plain"}]',
            'headers' => '{"X-A": "1", "X-B": 2}',
            'status' => 'not-a-real-status',
            'error' => null,
            'attempts' => 1,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'updated_at' => '2026-01-01T00:00:00+00:00',
        ]);

        self::assertSame(7, $log->id);
        self::assertSame([], $log->to);
        self::assertSame([], $log->cc);
        self::assertSame([], $log->bcc);
        // An attachment missing a usable filename is dropped rather than
        // fatalling the whole row.
        self::assertSame([], $log->attachments);
        // Only the string-valued header survives.
        self::assertSame(['X-A' => '1'], $log->headers);
        // An unrecognised status is treated as Failed, never as Sent — that is
        // the direction that under-counts nothing as delivered.
        self::assertSame(MailStatus::Failed, $log->status);
    }

    private function logWith(?string $textBody, ?string $htmlBody): MailLog
    {
        return new MailLog(
            1,
            [new Address('ada@example.test')],
            [],
            [],
            new Address('a@b.test'),
            null,
            'Hi',
            $textBody,
            $htmlBody,
            [],
            [],
            MailStatus::Sent,
            null,
            1,
            '2026-01-01T00:00:00+00:00',
            '2026-01-01T00:00:00+00:00',
        );
    }
}
