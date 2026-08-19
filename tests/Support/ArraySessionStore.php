<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Support;

use PhpOrbit\Session\SessionStore;

/**
 * An in-memory session store for tests.
 *
 * Keeps session assertions about the middleware's behaviour rather than about
 * the filesystem; {@see \PhpOrbit\Tests\Unit\Session\FileSessionStoreTest}
 * covers the real one.
 */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string, array{expires: int, data: array<string, scalar>}> */
    private array $sessions = [];

    public function read(string $id): ?array
    {
        $entry = $this->sessions[$id] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expires'] < time()) {
            unset($this->sessions[$id]);

            return null;
        }

        return $entry['data'];
    }

    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        $this->sessions[$id] = ['expires' => time() + $lifetimeSeconds, 'data' => $data];
    }

    public function destroy(string $id): void
    {
        unset($this->sessions[$id]);
    }

    public function collectGarbage(): int
    {
        $removed = 0;

        foreach ($this->sessions as $id => $entry) {
            if ($entry['expires'] < time()) {
                unset($this->sessions[$id]);
                $removed++;
            }
        }

        return $removed;
    }

    public function count(): int
    {
        return count($this->sessions);
    }

    /**
     * Every stored session's data, for assertions about what was written.
     *
     * @return list<array<string, scalar>>
     */
    public function all(): array
    {
        return array_values(array_map(
            static fn (array $entry): array => $entry['data'],
            $this->sessions,
        ));
    }

    public function has(string $id): bool
    {
        return isset($this->sessions[$id]);
    }
}
