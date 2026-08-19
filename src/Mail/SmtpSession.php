<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

/**
 * The SMTP conversation, over a stream someone else opened.
 *
 * Taking an already-connected stream rather than a host and port is what makes
 * this testable: a test hands it one end of a socket pair with the server's
 * replies already queued, and asserts on the commands that come back. The
 * protocol is where the bugs are, and it can be exercised without a server.
 *
 * Every read is bounded by a timeout set on the stream. A server that accepts a
 * connection and then says nothing would otherwise hold a worker's process for
 * as long as it liked.
 */
final class SmtpSession
{
    /** @var resource */
    private $stream;

    /** @var list<string> capabilities the server advertised to EHLO */
    private array $capabilities = [];

    /**
     * @param resource $stream
     */
    public function __construct($stream, private readonly string $clientName = 'localhost')
    {
        $this->stream = $stream;
    }

    /**
     * Reads the greeting and introduces us.
     */
    public function open(): void
    {
        $this->expect('greeting', 220);
        $this->ehlo();
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function supports(string $capability): bool
    {
        foreach ($this->capabilities as $line) {
            if (stripos($line, $capability) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Asks the server to switch the connection to TLS.
     *
     * The caller performs the handshake, because only it knows which crypto
     * options and peer verification to use.
     */
    public function startTls(): void
    {
        $this->command('STARTTLS');
        $this->expect('STARTTLS', 220);
    }

    /**
     * EHLO again after a successful TLS handshake.
     *
     * Required, not optional: capabilities advertised before encryption cannot
     * be trusted, and servers commonly withhold AUTH until the channel is
     * secure.
     */
    public function ehlo(): void
    {
        $this->command('EHLO ' . $this->clientName);

        [$code, $lines] = $this->readReply();

        if ($code !== 250) {
            // A server too old for EHLO still answers HELO.
            $this->command('HELO ' . $this->clientName);
            $this->expect('HELO', 250);
            $this->capabilities = [];

            return;
        }

        // Drop the greeting line; the rest are capabilities.
        $this->capabilities = array_slice($lines, 1);
    }

    public function authenticate(string $username, string $password): void
    {
        if ($this->supports('AUTH LOGIN') || !$this->supports('AUTH')) {
            $this->authenticateLogin($username, $password);

            return;
        }

        $this->authenticatePlain($username, $password);
    }

    /**
     * @param list<Address> $recipients
     */
    public function sendMessage(Address $from, array $recipients, string $data): void
    {
        $this->command(sprintf('MAIL FROM:<%s>', $from->envelope()));
        $this->expect('MAIL FROM', 250);

        foreach ($recipients as $recipient) {
            $this->command(sprintf('RCPT TO:<%s>', $recipient->envelope()));
            $this->expect('RCPT TO', 250, 251);
        }

        $this->command('DATA');
        $this->expect('DATA', 354);

        // Dot-stuffing here rather than in the writer: it is a property of this
        // transport, not of the message.
        $this->write(Mime::stuffDots(Mime::normaliseLineEndings($data)) . "\r\n.\r\n");
        $this->expect('message body', 250);
    }

    /**
     * Says goodbye politely. Failure is ignored — the mail is already accepted.
     */
    public function close(): void
    {
        try {
            $this->command('QUIT');
            $this->readReply();
        } catch (MailFailed) {
            // The server hung up first, which is common and harmless.
        }
    }

    private function authenticatePlain(string $username, string $password): void
    {
        $this->command('AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password));
        $this->expect('authentication', 235);
    }

    private function authenticateLogin(string $username, string $password): void
    {
        $this->command('AUTH LOGIN');
        $this->expect('authentication', 334);

        $this->command(base64_encode($username));
        $this->expect('authentication', 334);

        $this->command(base64_encode($password));
        $this->expect('authentication', 235);
    }

    /**
     * Reads a reply and requires one of the given codes.
     */
    private function expect(string $step, int ...$codes): string
    {
        [$code, $lines] = $this->readReply();

        if (!in_array($code, $codes, true)) {
            throw MailFailed::atStep($step, implode(' ', $lines));
        }

        return implode(' ', $lines);
    }

    /**
     * Reads a complete reply, following multi-line continuations.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function readReply(): array
    {
        $lines = [];
        $code = 0;

        do {
            $line = fgets($this->stream, 1024);

            if ($line === false) {
                throw MailFailed::atStep('read', 'the server closed the connection');
            }

            $line = rtrim($line, "\r\n");
            $lines[] = substr($line, 4);
            $code = (int) substr($line, 0, 3);

            // "250-" continues, "250 " ends.
            $more = ($line[3] ?? ' ') === '-';
        } while ($more);

        return [$code, $lines];
    }

    private function command(string $command): void
    {
        // A newline inside a command would let a crafted address or username
        // issue commands of its own. Values reaching here are already
        // validated, so this is the assertion that keeps it that way.
        Mime::assertNoInjection($command);

        $this->write($command . "\r\n");
    }

    private function write(string $data): void
    {
        $total = strlen($data);

        for ($written = 0; $written < $total;) {
            $result = fwrite($this->stream, substr($data, $written));

            if ($result === false || $result === 0) {
                throw MailFailed::atStep('write', 'the connection was lost');
            }

            $written += $result;
        }
    }
}
