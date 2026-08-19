<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

/**
 * One row of `mail_log`, mapped from a database row.
 *
 * The driver hands back `array<string, scalar|null>`; this is where that
 * becomes a typed object, so the rest of the application never handles a loose
 * row — the same convention {@see \App\Notes\Note} follows for notes.
 *
 * Addresses are stored as their header-value strings (`Address::toHeaderValue()`)
 * and reconstructed with `Address::parse()`, the same round trip the transport
 * already relies on. Attachment contents are base64-encoded, so `toMessage()`
 * can hand a resend byte-for-byte the same file it recorded.
 */
final class MailLog
{
    /**
     * @param list<Address>         $to
     * @param list<Address>         $cc
     * @param list<Address>         $bcc
     * @param list<Attachment>      $attachments
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $id,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly ?Address $from,
        public readonly ?Address $replyTo,
        public readonly string $subjectLine,
        public readonly ?string $textBody,
        public readonly ?string $htmlBody,
        public readonly array $attachments,
        public readonly array $headers,
        public readonly MailStatus $status,
        public readonly ?string $error,
        public readonly int $attempts,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            self::decodeAddresses($row['to_addresses'] ?? null),
            self::decodeAddresses($row['cc_addresses'] ?? null),
            self::decodeAddresses($row['bcc_addresses'] ?? null),
            self::decodeAddress($row['from_address'] ?? null),
            self::decodeAddress($row['reply_to'] ?? null),
            (string) ($row['subject'] ?? ''),
            self::decodeNullableString($row['text_body'] ?? null),
            self::decodeNullableString($row['html_body'] ?? null),
            self::decodeAttachments($row['attachments'] ?? null),
            self::decodeHeaders($row['headers'] ?? null),
            // A status the enum does not recognise is a schema mismatch, not a
            // recoverable state — Failed is the safer of the two to assume,
            // since it is the one that under-counts nothing as delivered.
            MailStatus::tryFrom((string) ($row['status'] ?? '')) ?? MailStatus::Failed,
            self::decodeNullableString($row['error'] ?? null),
            (int) ($row['attempts'] ?? 1),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }

    /**
     * Rebuilds the message this row recorded, ready to hand to a {@see Mailer}
     * again — what {@see PersistingMailer::resend()} sends.
     */
    public function toMessage(): Message
    {
        $message = Message::create();

        foreach ($this->to as $address) {
            $message = $message->addTo($address);
        }

        foreach ($this->cc as $address) {
            $message = $message->addCc($address);
        }

        foreach ($this->bcc as $address) {
            $message = $message->addBcc($address);
        }

        if ($this->from !== null) {
            $message = $message->from($this->from);
        }

        if ($this->replyTo !== null) {
            $message = $message->replyTo($this->replyTo);
        }

        $message = $message->subject($this->subjectLine);

        // text() first: html()'s alternative-text parameter falls back to
        // whatever text() already set, so the order here preserves both
        // bodies independently rather than one overwriting the other.
        if ($this->textBody !== null) {
            $message = $message->text($this->textBody);
        }

        if ($this->htmlBody !== null) {
            $message = $message->html($this->htmlBody);
        }

        foreach ($this->attachments as $attachment) {
            $message = $message->attach($attachment);
        }

        foreach ($this->headers as $name => $value) {
            $message = $message->header($name, $value);
        }

        return $message;
    }

    /**
     * @return list<Address>
     */
    private static function decodeAddresses(string|int|float|bool|null $json): array
    {
        /** @var mixed $decoded */
        $decoded = is_string($json) ? json_decode($json, true) : null;

        if (!is_array($decoded)) {
            return [];
        }

        $addresses = [];

        foreach ($decoded as $value) {
            if (is_string($value) && $value !== '') {
                $addresses[] = Address::parse($value);
            }
        }

        return $addresses;
    }

    private static function decodeAddress(string|int|float|bool|null $value): ?Address
    {
        return is_string($value) && $value !== '' ? Address::parse($value) : null;
    }

    private static function decodeNullableString(string|int|float|bool|null $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<Attachment>
     */
    private static function decodeAttachments(string|int|float|bool|null $json): array
    {
        /** @var mixed $decoded */
        $decoded = is_string($json) ? json_decode($json, true) : null;

        if (!is_array($decoded)) {
            return [];
        }

        $attachments = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            /** @var mixed $filename */
            $filename = $entry['filename'] ?? null;
            /** @var mixed $mediaType */
            $mediaType = $entry['mediaType'] ?? null;
            /** @var mixed $contents */
            $contents = $entry['contents'] ?? null;

            if (!is_string($filename) || !is_string($mediaType) || !is_string($contents)) {
                continue;
            }

            $raw = base64_decode($contents, true);

            if ($raw === false) {
                continue;
            }

            $attachments[] = Attachment::fromString($filename, $raw, $mediaType);
        }

        return $attachments;
    }

    /**
     * @return array<string, string>
     */
    private static function decodeHeaders(string|int|float|bool|null $json): array
    {
        /** @var mixed $decoded */
        $decoded = is_string($json) ? json_decode($json, true) : null;

        if (!is_array($decoded)) {
            return [];
        }

        $headers = [];

        foreach ($decoded as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
