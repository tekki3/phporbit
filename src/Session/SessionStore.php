<?php

declare(strict_types=1);

namespace PhpOrbit\Session;

interface SessionStore
{
    /**
     * Loads a session's data, or null when it is absent or expired.
     *
     * @return array<string, scalar>|null
     */
    public function read(string $id): ?array;

    /**
     * @param array<string, scalar> $data
     */
    public function write(string $id, array $data, int $lifetimeSeconds): void;

    public function destroy(string $id): void;

    /**
     * Removes expired sessions. Returns how many were deleted.
     */
    public function collectGarbage(): int;
}
