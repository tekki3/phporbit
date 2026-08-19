<?php

declare(strict_types=1);

namespace App\Notes;

use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Direction;

/**
 * Note storage, written against the query builder.
 *
 * Autowired per request from the request scope; the {@see Connection} it
 * receives is the process-wide singleton.
 */
final class NoteRepository
{
    private const TABLE = 'notes';

    public function __construct(
        private readonly Connection $database,
    ) {
    }

    /**
     * @return list<Note>
     */
    public function latest(int $limit = 20): array
    {
        $rows = $this->database->query(self::TABLE)
            ->orderBy('id', Direction::Descending)
            ->limit($limit)
            ->get();

        return array_map(Note::fromRow(...), $rows);
    }

    public function find(int $id): ?Note
    {
        $row = $this->database->query(self::TABLE)->where('id', '=', $id)->first();

        return $row === null ? null : Note::fromRow($row);
    }

    /**
     * Looks a note up by exact title.
     *
     * Used by the self-check page to demonstrate that a value containing SQL
     * is matched as text, not executed.
     */
    public function findByTitle(string $title): ?Note
    {
        $row = $this->database->query(self::TABLE)->where('title', '=', $title)->first();

        return $row === null ? null : Note::fromRow($row);
    }

    public function create(string $title, string $body): int
    {
        return $this->database->query(self::TABLE)->insert([
            'title' => $title,
            'body' => $body,
            'created_at' => gmdate('c'),
        ]);
    }

    public function delete(int $id): bool
    {
        return $this->database->query(self::TABLE)->where('id', '=', $id)->delete() > 0;
    }

    public function count(): int
    {
        return $this->database->query(self::TABLE)->count();
    }
}
