<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use Closure;
use Throwable;

/**
 * Applies pending migrations and records what has run.
 *
 * Migrations are files named `<sortable-prefix>_<description>.php`, each
 * returning a {@see Migration} instance. Ordering comes from the filename, so
 * two developers adding migrations on separate branches get a deterministic
 * order once merged rather than one that depends on class discovery.
 *
 * Each migration runs inside its own transaction, so a failure leaves the
 * schema as it was and the ledger unchanged. That guarantee depends on the
 * engine supporting transactional DDL: SQLite and PostgreSQL do, MySQL does
 * not, and on MySQL a failed migration can leave partial changes behind.
 *
 * Applied migrations are grouped into batches so a rollback undoes one
 * deployment's worth of changes rather than a single arbitrary step.
 */
final class Migrator
{
    public const LEDGER_TABLE = 'orbit_migrations';

    /** @var Closure(string): void */
    private readonly Closure $report;

    /**
     * @param (Closure(string): void)|null $report progress notifications
     */
    public function __construct(
        private readonly Connection $database,
        private readonly string $directory,
        ?Closure $report = null,
    ) {
        $this->report = $report ?? static function (string $line): void {
        };
    }

    /**
     * Creates the ledger if it is absent.
     *
     * Called by every public method so a fresh database needs no setup step.
     */
    public function prepare(): void
    {
        $this->database->executeSchema(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                name TEXT PRIMARY KEY,
                batch INTEGER NOT NULL,
                applied_at TEXT NOT NULL
            )',
            self::LEDGER_TABLE,
        ));
    }

    /**
     * Every migration file present, in the order they will run.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $names = [];

        foreach (glob($this->directory . '/*.php') ?: [] as $path) {
            $names[] = basename($path, '.php');
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @return list<string>
     */
    public function applied(): array
    {
        $this->prepare();

        $names = [];
        foreach ($this->database->select(sprintf('SELECT name FROM %s ORDER BY name', self::LEDGER_TABLE)) as $row) {
            $names[] = (string) ($row['name'] ?? '');
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->available(),
            static fn (string $name): bool => !in_array($name, $applied, true),
        ));
    }

    /**
     * Runs every pending migration.
     *
     * @return list<string> the migrations applied, in order
     */
    public function migrate(): array
    {
        $pending = $this->pending();

        if ($pending === []) {
            ($this->report)('Nothing to migrate.');

            return [];
        }

        $batch = $this->nextBatch();
        $applied = [];

        foreach ($pending as $name) {
            $migration = $this->load($name);

            try {
                $this->database->transaction(function (Connection $database) use ($migration, $name, $batch): void {
                    $migration->up($database);

                    $database->execute(
                        sprintf(
                            'INSERT INTO %s (name, batch, applied_at) VALUES (:name, :batch, :applied_at)',
                            self::LEDGER_TABLE,
                        ),
                        ['name' => $name, 'batch' => $batch, 'applied_at' => gmdate('c')],
                    );
                });
            } catch (Throwable $e) {
                // Stop at the first failure: later migrations almost certainly
                // assume this one's changes exist.
                throw MigrationFailed::running($name, $e);
            }

            $applied[] = $name;
            ($this->report)(sprintf('Applied %s', $name));
        }

        return $applied;
    }

    /**
     * Reverses the most recent batches.
     *
     * Every migration in the batch is loaded and checked for reversibility
     * before anything is undone, so a batch containing an irreversible
     * migration fails without having half-rolled-back the rest.
     *
     * @return list<string> the migrations reversed, most recent first
     */
    public function rollback(int $batches = 1): array
    {
        $this->prepare();

        $names = $this->namesInLastBatches($batches);

        if ($names === []) {
            ($this->report)('Nothing to roll back.');

            return [];
        }

        // Load everything up front so a missing file is caught before any
        // schema change happens.
        $migrations = [];
        foreach ($names as $name) {
            $migrations[$name] = $this->load($name);
        }

        $reversed = [];

        foreach ($migrations as $name => $migration) {
            try {
                $this->database->transaction(function (Connection $database) use ($migration, $name): void {
                    $migration->down($database);

                    $database->execute(
                        sprintf('DELETE FROM %s WHERE name = :name', self::LEDGER_TABLE),
                        ['name' => $name],
                    );
                });
            } catch (IrreversibleMigration $e) {
                throw $e;
            } catch (Throwable $e) {
                throw MigrationFailed::running($name, $e);
            }

            $reversed[] = $name;
            ($this->report)(sprintf('Reversed %s', $name));
        }

        return $reversed;
    }

    /**
     * @return array<string, int> migration name => batch, for status output
     */
    public function batches(): array
    {
        $this->prepare();

        $batches = [];
        foreach ($this->database->select(sprintf('SELECT name, batch FROM %s', self::LEDGER_TABLE)) as $row) {
            $batches[(string) ($row['name'] ?? '')] = (int) ($row['batch'] ?? 0);
        }

        return $batches;
    }

    private function nextBatch(): int
    {
        $this->prepare();

        return (int) $this->database->selectValue(
            sprintf('SELECT COALESCE(MAX(batch), 0) + 1 FROM %s', self::LEDGER_TABLE),
        );
    }

    /**
     * Names from the last N batches, newest first.
     *
     * @return list<string>
     */
    private function namesInLastBatches(int $batches): array
    {
        $rows = $this->database->select(
            sprintf(
                'SELECT name FROM %s WHERE batch > (
                    SELECT COALESCE(MAX(batch), 0) - :batches FROM %s
                ) ORDER BY name DESC',
                self::LEDGER_TABLE,
                self::LEDGER_TABLE,
            ),
            ['batches' => max(1, $batches)],
        );

        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) ($row['name'] ?? '');
        }

        return $names;
    }

    /**
     * Loads a migration file and checks what it returned.
     *
     * The name is validated against a strict pattern before being used to
     * build a path: a migration name that arrived from anywhere but the
     * filesystem must not be able to `require` an arbitrary file.
     */
    private function load(string $name): Migration
    {
        if (preg_match('/^[0-9]{4,}_[a-z0-9_]+$/', $name) !== 1) {
            throw MigrationFailed::invalidFile(
                $name,
                'names must look like "0001_create_users" — digits, an underscore, then lowercase words',
            );
        }

        $path = $this->directory . '/' . $name . '.php';

        if (!is_file($path)) {
            throw MigrationFailed::invalidFile($path, 'the file does not exist');
        }

        /** @var mixed $migration */
        $migration = require $path;

        if (!$migration instanceof Migration) {
            throw MigrationFailed::invalidFile(
                $path,
                sprintf('it must return a %s instance, got %s', Migration::class, get_debug_type($migration)),
            );
        }

        return $migration;
    }
}
