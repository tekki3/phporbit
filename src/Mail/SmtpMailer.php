<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

/**
 * Sends over SMTP.
 *
 * The connection is opened and closed inside `send()`. Holding one open across
 * requests would be faster, and would also mean a worker carrying a stateful
 * socket — mid-transaction if a previous send failed — into the next user's
 * request. Reconnecting costs a round trip and removes a whole class of bug;
 * that is the trade this framework makes everywhere else too.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly SmtpSettings $settings,
        private readonly MessageWriter $writer = new MessageWriter(),
    ) {
    }

    public function send(Message $message): void
    {
        // Default sender applied before validation, so a message without an
        // explicit from() still works when the mailer has one configured.
        if ($message->from === null && $this->settings->from !== null) {
            $message = $message->from($this->settings->from);
        }

        $message->assertSendable();

        $stream = $this->connect();

        try {
            $session = new SmtpSession($stream, $this->settings->clientName);
            $session->open();

            if ($this->settings->encryption === SmtpEncryption::StartTls) {
                $this->upgrade($session, $stream);
            }

            if ($this->settings->needsAuthentication()) {
                $session->authenticate(
                    (string) $this->settings->username,
                    (string) $this->settings->password,
                );
            }

            $session->sendMessage(
                // assertSendable() has already established this.
                $message->from ?? throw MailFailed::atStep('validation', 'no sender'),
                $message->envelopeRecipients(),
                $this->writer->render($message),
            );

            $session->close();
        } finally {
            // Closed on the error path too: a half-finished conversation must
            // not leave a socket open for the life of the worker.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $transport = $this->settings->encryption->isImplicit() ? 'ssl' : 'tcp';
        $target = sprintf('%s://%s:%d', $transport, $this->settings->host, $this->settings->effectivePort());

        $stream = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            $this->settings->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $this->tlsOptions()]),
        );

        if ($stream === false) {
            // stream_socket_client leaves $errstr null when it fails before the
            // transport reports anything — an unknown scheme, for instance.
            throw MailFailed::connecting(
                $target,
                $errstr === null || $errstr === '' ? 'connection refused' : $errstr,
            );
        }

        // Bounds every read. Without it a server that accepts and then goes
        // quiet holds this process indefinitely.
        stream_set_timeout($stream, $this->settings->timeoutSeconds);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function upgrade(SmtpSession $session, $stream): void
    {
        $session->startTls();

        $crypto = @stream_socket_enable_crypto(
            $stream,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT,
        );

        if ($crypto !== true) {
            throw MailFailed::atStep(
                'TLS handshake',
                'the server accepted STARTTLS but the encrypted session could not be established',
            );
        }

        // Capabilities announced before encryption cannot be trusted, and most
        // servers withhold AUTH until now.
        $session->ehlo();
    }

    /**
     * @return array<string, scalar>
     */
    private function tlsOptions(): array
    {
        // Verification is on, and there is no setting to turn it off. An
        // unverified TLS connection stops any attacker on the path from being
        // detected, which is most of what TLS was for — and a mail server's
        // certificate is exactly what an interceptor would forge to collect
        // the credentials sent moments later.
        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'peer_name' => $this->settings->host,
        ];
    }
}
