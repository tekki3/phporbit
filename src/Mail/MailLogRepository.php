<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

use JsonException;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Direction;
use PhpOrbit\Database\QueryFailed;

/**
 * Persistence for `mail_log`, written against the query builder.
 *
 * Autowired per request from the request scope; the {@see Connection} it
 * receives is the process-wide singleton — the same pattern as
 * {@see \App\Notes\NoteRepository}.
 */
final class MailLogRepository
{
    private const TABLE = 'mail_log';

    public function __construct(
        private readonly Connection $database,
    ) {
    }

    /**
     * Records one delivery attempt for a freshly sent message.
     *
     * @throws JsonException if a header, address or attachment somehow contains
     *                        invalid UTF-8 — the same failure mode `json_encode`
     *                        has everywhere else in the framework
     * @throws QueryFailed if the write itself fails — most commonly because
     *                      `mail_log` does not exist yet on a project that has
     *                      never run `orbit migrate`
     */
    public function record(Message $message, MailStatus $status, ?string $error = null): int
    {
        $now = gmdate('c');

        return $this->database->query(self::TABLE)->insert([
            'to_addresses' => self::encodeAddresses($message->to),
            'cc_addresses' => self::encodeAddresses($message->cc),
            'bcc_addresses' => self::encodeAddresses($message->bcc),
            'from_address' => $message->from?->toHeaderValue(),
            'reply_to' => $message->replyTo?->toHeaderValue(),
            'subject' => $message->subjectLine,
            'text_body' => $message->textBody,
            'html_body' => $message->htmlBody,
            'attachments' => self::encodeAttachments($message->attachments),
            'headers' => json_encode($message->headers, JSON_THROW_ON_ERROR),
            'status' => $status->value,
            'error' => $error,
            'attempts' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Updates the outcome of a resend, in place — a resend is another attempt
     * at the same logical message, not a new one, so `attempts` grows on the
     * existing row rather than a fresh row being written each time.
     */
    public function recordResend(int $id, MailStatus $status, ?string $error, int $attempts): void
    {
        $this->database->query(self::TABLE)->where('id', '=', $id)->update([
            'status' => $status->value,
            'error' => $error,
            'attempts' => $attempts,
            'updated_at' => gmdate('c'),
        ]);
    }

    public function find(int $id): ?MailLog
    {
        $row = $this->database->query(self::TABLE)->where('id', '=', $id)->first();

        return $row === null ? null : MailLog::fromRow($row);
    }

    /**
     * Most recent first, optionally narrowed to one status — what `mail:list`
     * and a bulk `mail:resend --failed` both read from.
     *
     * @return list<MailLog>
     */
    public function list(?MailStatus $status = null, int $limit = 50): array
    {
        $query = $this->database->query(self::TABLE)
            ->orderBy('id', Direction::Descending)
            ->limit($limit);

        if ($status !== null) {
            $query = $query->where('status', '=', $status->value);
        }

        return array_map(MailLog::fromRow(...), $query->get());
    }

    /**
     * The count behind the admin overview's mail tile — cheaper than fetching
     * rows just to `count()` the array.
     */
    public function count(?MailStatus $status = null): int
    {
        $query = $this->database->query(self::TABLE);

        if ($status !== null) {
            $query = $query->where('status', '=', $status->value);
        }

        return $query->count();
    }

    /**
     * @param list<Address> $addresses
     */
    private static function encodeAddresses(array $addresses): string
    {
        return json_encode(
            array_map(static fn (Address $address): string => $address->toHeaderValue(), $addresses),
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param list<Attachment> $attachments
     */
    private static function encodeAttachments(array $attachments): string
    {
        return json_encode(
            array_map(static fn (Attachment $attachment): array => [
                'filename' => $attachment->filename,
                'mediaType' => $attachment->mediaType,
                'contents' => base64_encode($attachment->contents),
            ], $attachments),
            JSON_THROW_ON_ERROR,
        );
    }
}
