<?php

declare(strict_types=1);

namespace PhpOrbit\Session;

use JsonException;
use RuntimeException;

/**
 * Session storage as one JSON file per session.
 *
 * Ids are validated against a strict hex pattern before ever reaching the
 * filesystem, so a crafted cookie cannot steer a read or a write outside the
 * session directory.
 *
 * Writes go to a temporary file and are then renamed, which is atomic on POSIX
 * filesystems. A reader therefore sees either the previous session or the new
 * one, never a half-written file — which matters under the built-in server and
 * FrankenPHP, where several requests can touch one session in quick
 * succession.
 */
final class FileSessionStore implements SessionStore
{
    public function __construct(
        private readonly string $directory,
    ) {
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create the session directory "%s".', $directory));
        }

        if (!is_writable($directory)) {
            throw new RuntimeException(sprintf('The session directory "%s" is not writable.', $directory));
        }
    }

    public function read(string $id): ?array
    {
        $path = $this->pathFor($id);

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A corrupt session file is treated as no session at all; the user
            // gets a fresh one rather than an error page.
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['expires'], $decoded['data'])) {
            return null;
        }

        $expires = $decoded['expires'];
        $data = $decoded['data'];

        if (!is_int($expires) || $expires < time()) {
            $this->destroy($id);

            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        return $this->narrowToScalars($data);
    }

    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        $path = $this->pathFor($id);

        $payload = json_encode(
            ['expires' => time() + $lifetimeSeconds, 'data' => $data],
            JSON_THROW_ON_ERROR,
        );

        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Cannot write the session file for "%s".', $id));
        }

        // Sessions are bearer credentials at rest; keep them off other accounts.
        @chmod($temporary, 0o600);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(sprintf('Cannot commit the session file for "%s".', $id));
        }
    }

    public function destroy(string $id): void
    {
        $path = $this->pathFor($id);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function collectGarbage(): int
    {
        $removed = 0;

        foreach (glob($this->directory . '/sess_*') ?: [] as $path) {
            $raw = @file_get_contents($path);

            if ($raw === false) {
                continue;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            $expires = is_array($decoded) ? ($decoded['expires'] ?? null) : null;

            if (!is_int($expires) || $expires < time()) {
                @unlink($path);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Keeps only the value types a session is allowed to hold.
     *
     * The file is a deserialisation boundary, so its contents are narrowed
     * here and nowhere later.
     *
     * @param array<array-key, mixed> $data
     * @return array<string, scalar>
     */
    private function narrowToScalars(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function pathFor(string $id): string
    {
        if (!Session::isValidId($id)) {
            throw new RuntimeException(sprintf('Refusing to touch a session file for the invalid id "%s".', $id));
        }

        return $this->directory . '/sess_' . $id;
    }
}
