<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Mail;

use InvalidArgumentException;
use PhpOrbit\Mail\Address;
use PhpOrbit\Mail\Attachment;
use PhpOrbit\Mail\Message;
use PhpOrbit\Mail\MessageWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    // --- addresses ------------------------------------------------------------

    public function test_it_parses_a_named_address(): void
    {
        $address = Address::parse('Ada Lovelace <ada@example.test>');

        self::assertSame('ada@example.test', $address->email);
        self::assertSame('Ada Lovelace', $address->name);
        self::assertSame('Ada Lovelace <ada@example.test>', $address->toHeaderValue());
    }

    public function test_it_parses_a_bare_address(): void
    {
        self::assertNull(Address::parse('ada@example.test')->name);
    }

    /**
     * Everything after a CR or LF becomes headers of the sender's choosing —
     * extra recipients, a forged From, a second body.
     */
    #[DataProvider('injectionPayloads')]
    public function test_addresses_refuse_header_injection(string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Address('ada@example.test', $payload);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectionPayloads(): iterable
    {
        yield 'crlf' => ["Ada\r\nBcc: victim@example.test"];
        yield 'lf' => ["Ada\nBcc: victim@example.test"];
        yield 'cr' => ["Ada\rBcc: victim@example.test"];
        yield 'nul' => ["Ada\0"];
    }

    public function test_an_invalid_address_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Address('not-an-address');
    }

    public function test_a_subject_refuses_header_injection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Message::to('ada@example.test')->subject("Hi\r\nBcc: victim@example.test");
    }

    // --- building -------------------------------------------------------------

    public function test_it_builds_immutably(): void
    {
        $base = Message::to('ada@example.test')->subject('Base');
        $derived = $base->subject('Derived')->addTo('bob@example.test');

        self::assertSame('Base', $base->subjectLine);
        self::assertCount(1, $base->to);
        self::assertSame('Derived', $derived->subjectLine);
        self::assertCount(2, $derived->to);
    }

    public function test_reserved_headers_must_go_through_their_own_method(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dedicated method/');

        Message::to('ada@example.test')->header('Bcc', 'victim@example.test');
    }

    #[DataProvider('incompleteMessages')]
    public function test_an_incomplete_message_is_refused_before_sending(Message $message, string $expected): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($expected);

        $message->assertSendable();
    }

    /**
     * @return iterable<string, array{Message, string}>
     */
    public static function incompleteMessages(): iterable
    {
        yield 'no sender' => [
            Message::to('ada@example.test')->text('hi'),
            '/no sender/',
        ];

        yield 'no recipients' => [
            Message::create()->from('me@example.test')->text('hi'),
            '/no recipients/',
        ];

        yield 'no body' => [
            Message::to('ada@example.test')->from('me@example.test'),
            '/no body/',
        ];
    }

    // --- rendering ------------------------------------------------------------

    public function test_a_text_message_is_a_single_part(): void
    {
        $rendered = $this->render(
            Message::to('Ada <ada@example.test>')
                ->from('Orbit <no-reply@example.test>')
                ->subject('Welcome')
                ->text("Hello.\nThanks for signing up."),
        );

        self::assertStringContainsString('To: Ada <ada@example.test>', $rendered);
        self::assertStringContainsString('From: Orbit <no-reply@example.test>', $rendered);
        self::assertStringContainsString('Subject: Welcome', $rendered);
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $rendered);
        self::assertStringNotContainsString('multipart', $rendered);

        // Absent headers are a strong spam signal.
        self::assertStringContainsString('Date: ', $rendered);
        self::assertStringContainsString('Message-ID: <', $rendered);
    }

    public function test_text_and_html_become_multipart_alternative(): void
    {
        $rendered = $this->render(
            Message::to('ada@example.test')
                ->from('no-reply@example.test')
                ->subject('Welcome')
                ->html('<p>Hello</p>', 'Hello'),
        );

        self::assertStringContainsString('Content-Type: multipart/alternative; boundary=', $rendered);
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $rendered);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $rendered);
    }

    public function test_an_attachment_becomes_multipart_mixed(): void
    {
        $rendered = $this->render(
            Message::to('ada@example.test')
                ->from('no-reply@example.test')
                ->subject('Invoice')
                ->text('Attached.')
                ->attach(Attachment::fromString('invoice.pdf', '%PDF-1.4 fake', 'application/pdf')),
        );

        self::assertStringContainsString('Content-Type: multipart/mixed; boundary=', $rendered);
        self::assertStringContainsString('Content-Disposition: attachment; filename="invoice.pdf"', $rendered);
        self::assertStringContainsString(base64_encode('%PDF-1.4 fake'), $rendered);
    }

    /**
     * The whole point of a blind copy: it routes in the envelope, and must not
     * appear in the headers where every other recipient would read it.
     */
    public function test_bcc_never_reaches_the_headers(): void
    {
        $message = Message::to('ada@example.test')
            ->from('no-reply@example.test')
            ->subject('Hi')
            ->text('Hello')
            ->addBcc('secret@example.test');

        $rendered = $this->render($message);

        self::assertStringNotContainsString('secret@example.test', $rendered);
        self::assertStringNotContainsString('Bcc', $rendered);

        // Still delivered to, though.
        $recipients = array_map(
            static fn (Address $a): string => $a->email,
            $message->envelopeRecipients(),
        );

        self::assertContains('secret@example.test', $recipients);
    }

    public function test_non_ascii_headers_are_mime_encoded(): void
    {
        $rendered = $this->render(
            Message::to(new Address('ada@example.test', 'Ada Lovelace'))
                ->from('no-reply@example.test')
                ->subject('Grüße aus München 🚀')
                ->text('hi'),
        );

        // Encoded, and the raw bytes are gone from the header block.
        self::assertStringContainsString('=?UTF-8?B?', $rendered);
        self::assertStringNotContainsString('Grüße', explode("\r\n\r\n", $rendered, 2)[0]);

        // Plain ASCII is left readable rather than needlessly encoded.
        self::assertStringContainsString('Ada Lovelace <ada@example.test>', $rendered);
    }

    /**
     * SMTP forbids lines over 998 bytes; quoted-printable is what keeps a long
     * paragraph inside that without mangling it.
     */
    public function test_long_lines_are_wrapped(): void
    {
        $rendered = $this->render(
            Message::to('ada@example.test')
                ->from('no-reply@example.test')
                ->subject('Long')
                ->text(str_repeat('abcdefgh ', 400)),
        );

        foreach (explode("\r\n", $rendered) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
        }
    }

    private function render(Message $message): string
    {
        return (new MessageWriter('orbit.test'))->render($message);
    }
}
