<?php

declare(strict_types=1);

namespace PhpOrbit\Config;

/**
 * Application settings, read once at boot.
 *
 * Immutable and registered as a singleton, so a worker reads the filesystem
 * once per process rather than once per request. It also means configuration
 * cannot drift mid-process: every request in a worker sees the same values.
 *
 * Values are exposed through typed accessors rather than a `get(): mixed`.
 * Everything in a `.env` file is a string, and the conversion has to happen
 * somewhere; doing it here means `APP_DEBUG=maybe` fails at boot with a clear
 * message instead of quietly evaluating as true somewhere downstream.
 *
 * **The real environment wins over the file.** A `.env` file is a convenience
 * for development and a source of defaults; in production the values injected
 * by the platform — systemd, Docker, Kubernetes — are the ones that must
 * apply, and a stale `.env` left on a server must never override them.
 */
final class Environment
{
    /** @var array<string, string> */
    private readonly array $values;

    /**
     * @param array<string, string> $values
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Loads a `.env` file, overlaid by the real environment.
     *
     * A missing file is not an error: production deployments frequently have
     * no `.env` at all because everything is injected. Pass `required: true`
     * where its absence genuinely is a misconfiguration.
     */
    public static function load(string $path, bool $required = false): self
    {
        $system = self::systemEnvironment();

        if (!is_file($path)) {
            if ($required) {
                throw new InvalidEnvFile(sprintf('Expected an environment file at %s.', $path));
            }

            return new self($system);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidEnvFile(sprintf('Could not read %s.', $path));
        }

        $fromFile = EnvFile::parse($contents, $system, $path);

        return new self([...$fromFile, ...$system]);
    }

    /**
     * @param array<string, string> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    /**
     * Returns a copy with extra values layered on top.
     *
     * Used by the CLI so that a flag such as `--debug` can override the file
     * without mutating anything.
     *
     * @param array<string, string> $overrides
     */
    public function with(array $overrides): self
    {
        return new self([...$this->values, ...$overrides]);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * The raw value, or null when the key is absent.
     *
     * An explicitly blank `KEY=` yields `''`, which is distinct from absent.
     */
    public function raw(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->values[$key] ?? null;

        if ($value === null) {
            return $default ?? throw MissingConfiguration::absent($key);
        }

        return $value;
    }

    /**
     * A setting that must be present *and* non-empty.
     *
     * The distinction matters for secrets: `APP_KEY=` satisfies "is it set?"
     * while being exactly as unusable as omitting it.
     */
    public function required(string $key): string
    {
        $value = $this->values[$key] ?? throw MissingConfiguration::absent($key);

        if (trim($value) === '') {
            throw MissingConfiguration::empty($key);
        }

        return $value;
    }

    public function int(string $key, ?int $default = null): int
    {
        $value = $this->values[$key] ?? null;

        if ($value === null || trim($value) === '') {
            return $default ?? throw MissingConfiguration::absent($key);
        }

        if (preg_match('/^-?\d+$/', trim($value)) !== 1) {
            throw MissingConfiguration::notOfType($key, 'integer', 'digits, optionally signed');
        }

        return (int) trim($value);
    }

    /**
     * Parses a boolean from the spellings people actually write.
     *
     * Anything else throws rather than defaulting: a typo'd `APP_DEBUG=treu`
     * silently meaning false is how debug output ends up in production, and
     * silently meaning true is worse.
     */
    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->values[$key] ?? null;

        if ($value === null || trim($value) === '') {
            return $default ?? throw MissingConfiguration::absent($key);
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw MissingConfiguration::notOfType(
                $key,
                'boolean',
                'true/false, 1/0, yes/no, on/off',
            ),
        };
    }

    /**
     * A comma-separated list, with blank entries dropped.
     *
     * @param list<string> $default
     * @return list<string>
     */
    public function strings(string $key, array $default = []): array
    {
        $value = $this->values[$key] ?? null;

        if ($value === null || trim($value) === '') {
            return $default;
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Resolves a path setting, treating relative values as relative to $root.
     *
     * Without this, whether `DB_DATABASE=storage/app.sqlite` works depends on
     * the working directory the process happened to start in — which differs
     * between the CLI, a web server and a cron job.
     */
    public function path(string $key, string $root, ?string $default = null): string
    {
        $value = $this->values[$key] ?? $default;

        if ($value === null || trim($value) === '') {
            throw MissingConfiguration::absent($key);
        }

        $value = trim($value);

        // Absolute, or a driver-specific value such as SQLite's :memory:.
        if (str_starts_with($value, '/') || str_starts_with($value, ':')) {
            return $value;
        }

        return $root . '/' . $value;
    }

    /**
     * The keys present, for diagnostics. Values are never returned.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->values);
        sort($keys);

        return $keys;
    }

    /**
     * Redacts values from `var_dump()` and friends.
     *
     * Configuration is mostly credentials. A stack trace or a debug dump that
     * printed this object would put every secret it holds into whatever reads
     * that output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'keys' => implode(', ', $this->keys()),
            'values' => '<redacted>',
        ];
    }

    /**
     * The process environment as strings.
     *
     * `getenv()` rather than `$_ENV`, which is empty unless `variables_order`
     * includes E, and which does not see values set by `putenv()`.
     *
     * @return array<string, string>
     */
    private static function systemEnvironment(): array
    {
        return getenv();
    }
}
