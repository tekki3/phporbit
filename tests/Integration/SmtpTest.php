<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Integration;

use PhpOrbit\Mail\Address;
use PhpOrbit\Mail\Attachment;
use PhpOrbit\Mail\MailFailed;
use PhpOrbit\Mail\Message;
use PhpOrbit\Mail\SmtpEncryption;
use PhpOrbit\Mail\SmtpMailer;
use PhpOrbit\Mail\SmtpSettings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Mail against a real SMTP server, read back out the other side.
 *
 * The unit tests drive the protocol over a socket pair with scripted replies —
 * they prove phporbit says the right things, not that a server understands
 * them. This sends through Mailpit and then fetches the delivered message over
 * its HTTP API, so the whole path is exercised: the conversation, the MIME
 * structure, the encodings, and the headers a recipient actually receives.
 */
final class SmtpTest extends TestCase
{
    use RequiresService;

    private string $host = '';

    private int $port = 0;

    private string $apiBase = '';

    protected function setUp(): void
    {
        $env = $this->requireEnvironment(['MAILPIT_HOST', 'MAILPIT_SMTP_PORT', 'MAILPIT_API_PORT'], 'SMTP server');

        $this->host = $env['MAILPIT_HOST'];
        $this->port = (int) $env['MAILPIT_SMTP_PORT'];
        $this->apiBase = sprintf('http://%s:%d/api/v1', $this->host, (int) $env['MAILPIT_API_PORT']);

        $this->requireReachable($this->host, $this->port, 'SMTP server');

        $this->deleteAllMessages();
    }

    public function test_a_plain_message_is_delivered_intact(): void
    {
        $subject = 'Integration ' . bin2hex(random_bytes(4));

        $this->mailer()->send(
            Message::to(new Address('ada@example.test', 'Ada Lovelace'))
                ->subject($subject)
                ->text("Hello.\nThis came through a real server."),
        );

        $message = $this->fetchDelivered($subject);
        $recipients = $this->addresses($message, 'To');

        self::assertSame([['ada@example.test', 'Ada Lovelace']], $recipients);
        self::assertSame($subject, $this->text($message, 'Subject'));
        self::assertStringContainsString('This came through a real server.', $this->text($message, 'Text'));
    }

    /**
     * The header encoder is the part most likely to produce something a server
     * accepts but a client renders as mojibake.
     */
    public function test_a_non_ascii_subject_survives_the_round_trip(): void
    {
        $marker = bin2hex(random_bytes(4));
        $subject = "Grüße aus München 🚀 {$marker}";

        $this->mailer()->send(
            Message::to('ada@example.test')->subject($subject)->text('hi'),
        );

        // Mailpit decodes the encoded-word, so this compares what a mail client
        // would show — not what went over the wire.
        self::assertSame($subject, $this->text($this->fetchDelivered($marker), 'Subject'));
    }

    public function test_html_and_text_arrive_as_alternatives(): void
    {
        $subject = 'Alternative ' . bin2hex(random_bytes(4));

        $this->mailer()->send(
            Message::to('ada@example.test')
                ->subject($subject)
                ->html('<p>Rich <strong>content</strong></p>', 'Plain content'),
        );

        $message = $this->fetchDelivered($subject);

        self::assertStringContainsString('Plain content', $this->text($message, 'Text'));
        self::assertStringContainsString('<strong>content</strong>', $this->text($message, 'HTML'));
    }

    public function test_an_attachment_arrives_with_its_bytes_intact(): void
    {
        $subject = 'Attachment ' . bin2hex(random_bytes(4));
        $contents = random_bytes(2048);

        $this->mailer()->send(
            Message::to('ada@example.test')
                ->subject($subject)
                ->text('Attached.')
                ->attach(Attachment::fromString('report.bin', $contents, 'application/octet-stream')),
        );

        $attachments = $this->rows($this->fetchDelivered($subject), 'Attachments');

        self::assertCount(1, $attachments);
        self::assertSame('report.bin', $this->text($attachments[0], 'FileName'));

        // Base64 that lost or gained a byte still decodes; the length is what
        // catches a transfer encoding applied to the wrong part.
        self::assertSame((string) strlen($contents), $this->text($attachments[0], 'Size'));
    }

    /**
     * A blind copy must route without appearing in the headers every other
     * recipient can read.
     */
    public function test_a_blind_copy_is_delivered_but_not_disclosed(): void
    {
        $subject = 'Blind ' . bin2hex(random_bytes(4));

        $this->mailer()->send(
            Message::to('ada@example.test')
                ->subject($subject)
                ->text('Hello')
                ->addBcc('audit@example.test'),
        );

        $summaries = $this->search($subject);
        self::assertNotEmpty($summaries, 'the message was not delivered');

        $id = $this->text($summaries[0], 'ID');

        // Mailpit derives Bcc by subtracting the To and Cc headers from the
        // envelope recipients, so this is evidence the blind copy was really
        // carried in RCPT TO rather than merely written into a header.
        $blind = array_column($this->addresses($this->fetchMessage($id), 'Bcc'), 0);

        self::assertContains('audit@example.test', $blind, 'the blind copy should have been delivered');

        // ... and the headers the visible recipient receives do not name it.
        $headers = explode("\r\n\r\n", $this->fetchRaw($id), 2)[0];

        self::assertStringNotContainsString('audit@example.test', $headers);
    }

    /**
     * A server that is not there must fail loudly.
     *
     * Silent non-delivery is the worst outcome an application can have: the
     * password reset simply never arrives and nothing anywhere says so. Port 1
     * is reserved and never listening, so this is deterministic rather than
     * timing-dependent.
     */
    public function test_an_unreachable_server_raises_rather_than_dropping_the_message(): void
    {
        $mailer = new SmtpMailer(new SmtpSettings(
            $this->host,
            1,
            encryption: SmtpEncryption::None,
            from: new Address('no-reply@example.test'),
            timeoutSeconds: 2,
        ));

        $this->expectException(MailFailed::class);
        $this->expectExceptionMessageMatches('/Could not connect/');

        $mailer->send(Message::to('ada@example.test')->subject('nowhere')->text('x'));
    }

    private function mailer(): SmtpMailer
    {
        return new SmtpMailer(new SmtpSettings(
            $this->host,
            $this->port,
            // Mailpit accepts unauthenticated plaintext, which is what makes it
            // usable as a test relay.
            encryption: SmtpEncryption::None,
            from: new Address('no-reply@example.test', 'phporbit'),
            timeoutSeconds: 5,
            clientName: 'orbit.test',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDelivered(string $searchTerm): array
    {
        $summaries = $this->search($searchTerm);

        self::assertNotEmpty($summaries, 'the message was not delivered');

        return $this->fetchMessage($this->text($summaries[0], 'ID'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(string $term): array
    {
        // Delivery is quick but not instantaneous; poll rather than sleep a
        // fixed amount, so the test is neither flaky nor slow.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $messages = $this->rows($this->get('/search?query=' . rawurlencode($term)), 'messages');

            if ($messages !== []) {
                return $messages;
            }

            usleep(100_000);
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMessage(string $id): array
    {
        return $this->get('/message/' . rawurlencode($id));
    }

    private function fetchRaw(string $id): string
    {
        $raw = @file_get_contents($this->apiBase . '/message/' . rawurlencode($id) . '/raw');

        return $raw === false ? '' : $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $body = @file_get_contents($this->apiBase . $path);

        if ($body === false) {
            throw new RuntimeException('Mailpit API request failed: ' . $path);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Mailpit returned something that is not a JSON object: ' . $path);
        }

        $narrowed = [];

        /** @var mixed $value */
        foreach ($decoded as $key => $value) {
            $narrowed[(string) $key] = $value;
        }

        return $narrowed;
    }

    /**
     * Reads a scalar out of Mailpit's JSON as a string.
     *
     * Decoded JSON is a `mixed` boundary like every other one in this framework
     * — narrowed once, here, rather than cast at each use. A key that is absent
     * or holds an array fails the test that asked for it, which is the useful
     * outcome: it means Mailpit's shape is not what this test assumes.
     *
     * @param array<string, mixed> $data
     */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        self::assertTrue(
            is_string($value) || is_int($value) || is_float($value),
            sprintf('Expected a scalar at "%s" in the Mailpit response.', $key),
        );

        /** @var string|int|float $value */
        return (string) $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function rows(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        self::assertIsArray($value, sprintf('Expected a list at "%s" in the Mailpit response.', $key));

        $rows = [];

        /** @var mixed $row */
        foreach ($value as $row) {
            self::assertIsArray($row, sprintf('Expected objects inside "%s".', $key));

            $entry = [];

            /** @var mixed $item */
            foreach ($row as $field => $item) {
                $entry[(string) $field] = $item;
            }

            $rows[] = $entry;
        }

        return $rows;
    }

    /**
     * Mailpit's address lists, as `[email, name]` pairs.
     *
     * @param array<string, mixed> $data
     * @return list<array{0: string, 1: string}>
     */
    private function addresses(array $data, string $key): array
    {
        return array_map(
            fn (array $entry): array => [$this->text($entry, 'Address'), $this->text($entry, 'Name')],
            $this->rows($data, $key),
        );
    }

    private function deleteAllMessages(): void
    {
        $context = stream_context_create(['http' => ['method' => 'DELETE', 'ignore_errors' => true]]);

        @file_get_contents($this->apiBase . '/messages', false, $context);
    }
}
