<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Mail;

use PhpOrbit\Mail\Address;
use PhpOrbit\Mail\MailFailed;
use PhpOrbit\Mail\SmtpSession;
use PHPUnit\Framework\TestCase;

/**
 * The SMTP conversation, driven over a socket pair.
 *
 * One end is handed to the session; the other is the "server". Replies are
 * queued before the session runs, so it reads them in order as it goes, and the
 * commands it wrote can be read back afterwards. No server process, no network,
 * and the protocol — which is where the bugs are — is exercised for real.
 */
final class SmtpSessionTest extends TestCase
{
    /** @var resource */
    private $client;

    /** @var resource */
    private $server;

    protected function setUp(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        self::assertIsArray($pair);

        [$this->client, $this->server] = $pair;
    }

    protected function tearDown(): void
    {
        foreach ([$this->client, $this->server] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function test_it_greets_and_records_capabilities(): void
    {
        $this->queue(
            "220 mail.example.test ESMTP\r\n",
            "250-mail.example.test\r\n",
            "250-SIZE 35882577\r\n",
            "250-STARTTLS\r\n",
            "250 AUTH LOGIN PLAIN\r\n",
        );

        $session = new SmtpSession($this->client, 'orbit.test');
        $session->open();

        self::assertStringContainsString('EHLO orbit.test', $this->written());
        self::assertTrue($session->supports('STARTTLS'));
        self::assertTrue($session->supports('AUTH'));
        self::assertFalse($session->supports('CHUNKING'));
    }

    /**
     * A server too old for EHLO answers 500; the session falls back rather
     * than giving up.
     */
    public function test_it_falls_back_to_helo(): void
    {
        $this->queue(
            "220 old.example.test ESMTP\r\n",
            "500 unrecognised command\r\n",
            "250 old.example.test\r\n",
        );

        (new SmtpSession($this->client, 'orbit.test'))->open();

        $written = $this->written();

        self::assertStringContainsString('EHLO orbit.test', $written);
        self::assertStringContainsString('HELO orbit.test', $written);
    }

    public function test_it_sends_the_envelope_and_body(): void
    {
        $this->queue(
            "220 ready\r\n",
            "250 ok\r\n",          // EHLO
            "250 sender ok\r\n",   // MAIL FROM
            "250 rcpt ok\r\n",     // RCPT TO
            "250 rcpt ok\r\n",     // RCPT TO (second)
            "354 go ahead\r\n",    // DATA
            "250 queued\r\n",      // body
            "221 bye\r\n",         // QUIT
        );

        $session = new SmtpSession($this->client, 'orbit.test');
        $session->open();
        $session->sendMessage(
            new Address('ada@example.test'),
            [new Address('bob@example.test'), new Address('cee@example.test')],
            "Subject: Hi\r\n\r\nBody",
        );
        $session->close();

        $written = $this->written();

        self::assertStringContainsString('MAIL FROM:<ada@example.test>', $written);
        self::assertStringContainsString('RCPT TO:<bob@example.test>', $written);
        self::assertStringContainsString('RCPT TO:<cee@example.test>', $written);
        self::assertStringContainsString("DATA\r\n", $written);
        // The body is terminated by a lone dot on its own line.
        self::assertStringContainsString("\r\n.\r\n", $written);
        self::assertStringContainsString("QUIT\r\n", $written);
    }

    /**
     * A line of "." ends DATA. An unescaped one lets a body truncate the
     * message — or, with the right bytes after it, inject a second one.
     */
    public function test_a_leading_dot_in_the_body_is_stuffed(): void
    {
        $this->queue(
            "220 ready\r\n",
            "250 ok\r\n",
            "250 sender ok\r\n",
            "250 rcpt ok\r\n",
            "354 go ahead\r\n",
            "250 queued\r\n",
        );

        $session = new SmtpSession($this->client, 'orbit.test');
        $session->open();
        $session->sendMessage(
            new Address('ada@example.test'),
            [new Address('bob@example.test')],
            "Subject: Hi\r\n\r\n.\r\nInjected: header\r\n",
        );

        $written = $this->written();

        self::assertStringContainsString("\r\n..\r\nInjected", $written);
        // Exactly one terminator: the one the session appended.
        self::assertSame(1, substr_count($written, "\r\n.\r\n"));
    }

    public function test_auth_login_sends_the_credentials_base64_encoded(): void
    {
        $this->queue(
            "220 ready\r\n",
            "250-mail\r\n250 AUTH LOGIN\r\n",
            "334 VXNlcm5hbWU6\r\n",
            "334 UGFzc3dvcmQ6\r\n",
            "235 authenticated\r\n",
        );

        $session = new SmtpSession($this->client, 'orbit.test');
        $session->open();
        $session->authenticate('ada@example.test', 'hunter2');

        $written = $this->written();

        self::assertStringContainsString('AUTH LOGIN', $written);
        self::assertStringContainsString(base64_encode('ada@example.test'), $written);
        self::assertStringContainsString(base64_encode('hunter2'), $written);
        // base64 is encoding, not encryption — the point of requiring TLS.
        self::assertStringNotContainsString('hunter2', $written);
    }

    public function test_auth_plain_is_used_when_login_is_not_offered(): void
    {
        $this->queue(
            "220 ready\r\n",
            "250-mail\r\n250 AUTH PLAIN\r\n",
            "235 authenticated\r\n",
        );

        $session = new SmtpSession($this->client, 'orbit.test');
        $session->open();
        $session->authenticate('ada', 'hunter2');

        self::assertStringContainsString(
            'AUTH PLAIN ' . base64_encode("\0ada\0hunter2"),
            $this->written(),
        );
    }

    public function test_a_rejected_recipient_names_the_step_and_the_reply(): void
    {
        $this->queue(
            "220 ready\r\n",
            "250 ok\r\n",
            "250 sender ok\r\n",
            "550 no such user here\r\n",
        );

        $session = new SmtpSession($this->client, 'orbit.test');
        $session->open();

        $this->expectException(MailFailed::class);
        $this->expectExceptionMessageMatches('/RCPT TO.*no such user/s');

        $session->sendMessage(
            new Address('ada@example.test'),
            [new Address('nobody@example.test')],
            'body',
        );
    }

    public function test_failed_authentication_does_not_echo_the_password(): void
    {
        $this->queue(
            "220 ready\r\n",
            "250-mail\r\n250 AUTH PLAIN\r\n",
            "535 authentication failed\r\n",
        );

        $session = new SmtpSession($this->client, 'orbit.test');
        $session->open();

        try {
            $session->authenticate('ada', 'hunter2');
            self::fail('authentication should have failed');
        } catch (MailFailed $e) {
            self::assertStringContainsString('authentication failed', $e->getMessage());
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertStringNotContainsString(base64_encode("\0ada\0hunter2"), $e->getMessage());
        }
    }

    public function test_a_server_that_hangs_up_is_reported(): void
    {
        fclose($this->server);

        $this->expectException(MailFailed::class);
        $this->expectExceptionMessageMatches('/closed the connection/');

        (new SmtpSession($this->client))->open();
    }

    /**
     * Queues the server's side of the conversation.
     */
    private function queue(string ...$replies): void
    {
        foreach ($replies as $reply) {
            fwrite($this->server, $reply);
        }
    }

    /**
     * Everything the session wrote.
     */
    private function written(): string
    {
        stream_set_blocking($this->server, false);

        return (string) stream_get_contents($this->server);
    }
}
