<?php

declare(strict_types=1);

namespace App\Notes;

/**
 * A note, mapped from a database row.
 *
 * The driver hands back `array<string, scalar|null>`; this is where that
 * becomes a typed object, so the rest of the app never handles a loose row.
 */
final class Note
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $body,
        public readonly string $createdAt,
    ) {
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['title'] ?? ''),
            (string) ($row['body'] ?? ''),
            (string) ($row['created_at'] ?? ''),
        );
    }
}
