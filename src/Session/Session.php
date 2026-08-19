<?php

declare(strict_types=1);

namespace PhpOrbit\Session;

/**
 * One user's session, loaded for one request.
 *
 * Deliberately not built on PHP's session extension: `session_start()` and
 * `$_SESSION` are process-global, so under a worker they would carry one
 * user's data into the next request the process serves. This object is created
 * per request, lives in the {@see \PhpOrbit\Container\RequestScope}, and is
 * written back by {@see SessionMiddleware}.
 *
 * Values are restricted to scalars. Storing objects would mean serialising
 * user-influenced data and unserialising it later, which is a well-known route
 * to remote code execution.
 */
final class Session
{
    private const FLASH_PREFIX = '_flash.';

    /** @var array<string, scalar> */
    private array $data;

    private bool $dirty = false;

    private bool $destroyed = false;

    /**
     * @param array<string, scalar> $data
     */
    public function __construct(
        private string $id,
        array $data = [],
        private bool $isNew = true,
    ) {
        $this->data = $data;
    }

    public static function started(): self
    {
        return new self(self::generateId());
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    public function getInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return is_int($value) || is_numeric($value) ? (int) $value : null;
    }

    public function getBool(string $key): bool
    {
        return (bool) ($this->data[$key] ?? false);
    }

    public function set(string $key, string|int|float|bool $value): void
    {
        if (array_key_exists($key, $this->data) && $this->data[$key] === $value) {
            return;
        }

        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function remove(string $key): void
    {
        if (!array_key_exists($key, $this->data)) {
            return;
        }

        unset($this->data[$key]);
        $this->dirty = true;
    }

    /**
     * @return array<string, scalar>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Stores a value to be read exactly once, on a later request.
     */
    public function flash(string $key, string $value): void
    {
        $this->set(self::FLASH_PREFIX . $key, $value);
    }

    /**
     * Reads and removes a flashed value.
     */
    public function takeFlash(string $key): ?string
    {
        $value = $this->get(self::FLASH_PREFIX . $key);

        if ($value !== null) {
            $this->remove(self::FLASH_PREFIX . $key);
        }

        return $value;
    }

    /**
     * Issues a new session id, keeping the data.
     *
     * Must be called whenever the privilege level changes — logging in above
     * all. Without it, an attacker who fixes a victim's session id before
     * login still holds a valid id afterwards.
     */
    public function regenerate(): string
    {
        $previous = $this->id;

        $this->id = self::generateId();
        $this->isNew = true;
        $this->dirty = true;

        return $previous;
    }

    /**
     * Empties the session and marks it for removal from the store.
     */
    public function destroy(): void
    {
        $this->data = [];
        $this->destroyed = true;
        $this->dirty = true;
    }

    /**
     * 256 bits from the CSPRNG.
     *
     * Session ids are bearer credentials, so this must never fall back to a
     * predictable source — `random_bytes()` throws rather than degrading.
     */
    public static function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $id) === 1;
    }
}
