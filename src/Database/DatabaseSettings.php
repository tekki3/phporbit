<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use InvalidArgumentException;
use PhpOrbit\Config\Environment;
use PhpOrbit\Config\MissingConfiguration;
use ValueError;

/**
 * Everything needed to open a connection, validated once.
 *
 * Built from the environment at boot, so a missing password or a nonsense port
 * stops the application starting rather than surfacing as a failed query on
 * whichever request happens to touch the database first.
 */
final class DatabaseSettings
{
    private function __construct(
        public readonly Driver $driver,
        public readonly string $database,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly string $charset = 'utf8mb4',
        public readonly ?string $socket = null,
    ) {
        if ($database === '') {
            throw new InvalidArgumentException('DB_DATABASE cannot be empty.');
        }

        if ($driver->needsServer() && $host === null && $socket === null) {
            throw new InvalidArgumentException(sprintf(
                'The %s driver needs DB_HOST (or DB_SOCKET).',
                $driver->value,
            ));
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException(sprintf('DB_PORT must be between 1 and 65535, got %d.', $port));
        }
    }

    /**
     * A file-backed SQLite database, or `:memory:`.
     */
    public static function sqlite(string $path): self
    {
        return new self(Driver::Sqlite, $path);
    }

    public static function mysql(
        string $database,
        string $host = '127.0.0.1',
        int $port = 3306,
        ?string $username = null,
        ?string $password = null,
        string $charset = 'utf8mb4',
    ): self {
        return new self(Driver::MySql, $database, $host, $port, $username, $password, $charset);
    }

    public static function postgres(
        string $database,
        string $host = '127.0.0.1',
        int $port = 5432,
        ?string $username = null,
        ?string $password = null,
    ): self {
        return new self(Driver::PostgreSql, $database, $host, $port, $username, $password, 'utf8');
    }

    /**
     * Reads DB_* settings.
     *
     * SQLite resolves its path against the project root, so a relative
     * `storage/app.sqlite` means the same thing from the CLI, from cron and
     * from a web server, none of which share a working directory.
     */
    public static function fromEnvironment(Environment $config, string $root): self
    {
        // Reported as a configuration mistake naming the key, so it reads like
        // every other bad setting rather than an internal ValueError.
        try {
            $driver = Driver::fromName($config->string('DB_DRIVER', 'sqlite'));
        } catch (ValueError) {
            throw MissingConfiguration::notOfType('DB_DRIVER', 'database driver', 'sqlite, mysql, pgsql');
        }

        if ($driver === Driver::Sqlite) {
            $path = $config->string('DB_DATABASE', 'storage/app.sqlite');

            return new self(
                $driver,
                // ":memory:" and other driver-specific values are not paths.
                str_starts_with($path, ':') ? $path : $config->path('DB_DATABASE', $root, 'storage/app.sqlite'),
            );
        }

        return new self(
            $driver,
            $config->required('DB_DATABASE'),
            $config->string('DB_HOST', '127.0.0.1'),
            $config->int('DB_PORT', $driver->defaultPort() ?? 0),
            $config->string('DB_USERNAME', ''),
            // Blank is legitimate: a local trust-authenticated PostgreSQL, or a
            // MySQL socket login, both have no password.
            $config->string('DB_PASSWORD', ''),
            $config->string('DB_CHARSET', $driver === Driver::PostgreSql ? 'utf8' : 'utf8mb4'),
            $config->raw('DB_SOCKET'),
        );
    }

    /**
     * The PDO connection string.
     *
     * Values are placed verbatim, which is safe because each has already been
     * validated: a host or database name containing a `;` would otherwise be
     * able to append DSN parameters of its own.
     */
    public function dsn(): string
    {
        return match ($this->driver) {
            Driver::Sqlite => 'sqlite:' . $this->database,

            Driver::MySql => $this->socket !== null && $this->socket !== ''
                ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $this->socket, $this->database, $this->charset)
                : sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $this->host,
                    $this->port ?? 3306,
                    $this->database,
                    $this->charset,
                ),

            Driver::PostgreSql => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $this->host,
                $this->port ?? 5432,
                $this->database,
            ),
        };
    }

    /**
     * Redacts the password, so settings can be logged or dumped safely.
     *
     * @return array<string, scalar|null>
     */
    public function __debugInfo(): array
    {
        return [
            'driver' => $this->driver->value,
            'database' => $this->database,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password === null || $this->password === '' ? null : '<redacted>',
            'charset' => $this->charset,
        ];
    }
}
