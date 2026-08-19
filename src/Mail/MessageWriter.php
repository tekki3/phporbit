<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

/**
 * Renders a {@see Message} to the bytes that follow SMTP's DATA command.
 *
 * Separate from both the message and the transport so the MIME structure can be
 * asserted directly in a test — no socket, no server. That is where the awkward
 * parts are: which multipart shape to use, what gets encoded how, and which
 * headers must not appear.
 */
final class MessageWriter
{
    public function __construct(
        private readonly string $hostname = 'localhost',
    ) {
    }

    public function render(Message $message): string
    {
        $message->assertSendable();

        $headers = $this->headers($message);
        [$contentHeaders, $body] = $this->body($message);

        return implode("\r\n", [...$headers, ...$contentHeaders]) . "\r\n\r\n" . $body;
    }

    /**
     * @return list<string>
     */
    private function headers(Message $message): array
    {
        $lines = [];

        // A Date and Message-ID are not optional in practice: their absence is
        // one of the strongest spam signals there is.
        $lines[] = Mime::headerLine('Date', date('r'));
        $lines[] = Mime::headerLine('Message-ID', sprintf('<%s@%s>', bin2hex(random_bytes(16)), $this->hostname));
        $lines[] = Mime::headerLine('MIME-Version', '1.0');

        if ($message->from !== null) {
            $lines[] = Mime::headerLine('From', $message->from->toHeaderValue());
        }

        if ($message->to !== []) {
            $lines[] = Mime::headerLine('To', $this->addressList($message->to));
        }

        if ($message->cc !== []) {
            $lines[] = Mime::headerLine('Cc', $this->addressList($message->cc));
        }

        // Bcc is deliberately absent. It stays in the SMTP envelope, where the
        // server routes from — putting it here would show every blind
        // recipient to all the others, which is the one thing it must not do.

        if ($message->replyTo !== null) {
            $lines[] = Mime::headerLine('Reply-To', $message->replyTo->toHeaderValue());
        }

        $lines[] = Mime::headerLine('Subject', Mime::encodeHeaderWord($message->subjectLine));

        foreach ($message->headers as $name => $value) {
            $lines[] = Mime::headerLine($name, Mime::encodeHeaderWord($value));
        }

        return $lines;
    }

    /**
     * Picks the simplest MIME structure the message actually needs.
     *
     * @return array{0: list<string>, 1: string} content headers, body
     */
    private function body(Message $message): array
    {
        $hasText = $message->textBody !== null;
        $hasHtml = $message->htmlBody !== null;

        if ($message->attachments !== []) {
            $boundary = Mime::boundary();

            $parts = [$this->contentPart($message)];

            foreach ($message->attachments as $attachment) {
                $parts[] = $this->attachmentPart($attachment);
            }

            return [
                [Mime::headerLine('Content-Type', sprintf('multipart/mixed; boundary="%s"', $boundary))],
                $this->joinParts($boundary, $parts),
            ];
        }

        if ($hasText && $hasHtml) {
            $boundary = Mime::boundary();

            return [
                [Mime::headerLine('Content-Type', sprintf('multipart/alternative; boundary="%s"', $boundary))],
                $this->joinParts($boundary, [
                    $this->textPart((string) $message->textBody),
                    $this->htmlPart((string) $message->htmlBody),
                ]),
            ];
        }

        // A single part needs no boundary at all; its headers go on the message.
        $single = $hasHtml
            ? $this->htmlPart((string) $message->htmlBody)
            : $this->textPart((string) $message->textBody);

        [$partHeaders, $partBody] = $single;

        return [$partHeaders, $partBody];
    }

    /**
     * The human-readable part of a message that also carries attachments.
     *
     * @return array{0: list<string>, 1: string}
     */
    private function contentPart(Message $message): array
    {
        $hasText = $message->textBody !== null;
        $hasHtml = $message->htmlBody !== null;

        if ($hasText && $hasHtml) {
            $boundary = Mime::boundary();

            return [
                [Mime::headerLine('Content-Type', sprintf('multipart/alternative; boundary="%s"', $boundary))],
                $this->joinParts($boundary, [
                    $this->textPart((string) $message->textBody),
                    $this->htmlPart((string) $message->htmlBody),
                ]),
            ];
        }

        if ($hasHtml) {
            return $this->htmlPart((string) $message->htmlBody);
        }

        return $this->textPart($message->textBody ?? '');
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function textPart(string $body): array
    {
        return [
            [
                Mime::headerLine('Content-Type', 'text/plain; charset=UTF-8'),
                Mime::headerLine('Content-Transfer-Encoding', 'quoted-printable'),
            ],
            Mime::quotedPrintable($body),
        ];
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function htmlPart(string $body): array
    {
        return [
            [
                Mime::headerLine('Content-Type', 'text/html; charset=UTF-8'),
                Mime::headerLine('Content-Transfer-Encoding', 'quoted-printable'),
            ],
            Mime::quotedPrintable($body),
        ];
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function attachmentPart(Attachment $attachment): array
    {
        return [
            [
                Mime::headerLine('Content-Type', sprintf(
                    '%s; name="%s"',
                    $attachment->mediaType,
                    $attachment->filename,
                )),
                Mime::headerLine('Content-Transfer-Encoding', 'base64'),
                Mime::headerLine('Content-Disposition', sprintf(
                    '%s; filename="%s"',
                    $attachment->inline ? 'inline' : 'attachment',
                    $attachment->filename,
                )),
            ],
            Mime::base64($attachment->contents),
        ];
    }

    /**
     * @param list<array{0: list<string>, 1: string}> $parts
     */
    private function joinParts(string $boundary, array $parts): string
    {
        $rendered = '';

        foreach ($parts as [$headers, $body]) {
            $rendered .= '--' . $boundary . "\r\n"
                . implode("\r\n", $headers) . "\r\n\r\n"
                . $body . "\r\n";
        }

        return $rendered . '--' . $boundary . "--\r\n";
    }

    /**
     * @param list<Address> $addresses
     */
    private function addressList(array $addresses): string
    {
        return implode(', ', array_map(
            static fn (Address $address): string => $address->toHeaderValue(),
            $addresses,
        ));
    }
}
