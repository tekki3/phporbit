<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use Throwable;

/**
 * Runs project seeders in filename order, without a migration ledger.
 * Seeders run every time and must make their own writes idempotent.
 */
final readonly class SeedRunner
{
    public function __construct(
        private Connection $database,
        private string $directory,
    ) {
    }

    /**
     * @return list<string> the seeders run, in order
     * @phpstan-impure
     */
    public function run(?string $name = null): array
    {
        if (!file_exists($this->directory)) {
            if ($name !== null) {
                throw new SeedFailed(sprintf('Seeder "%s" was not found in "%s".', $name, $this->directory));
            }

            return [];
        }

        if (!is_dir($this->directory) || !is_readable($this->directory)) {
            throw new SeedFailed(sprintf('Seeder directory "%s" is not readable.', $this->directory));
        }

        $paths = glob($this->directory . '/*.php');

        if ($paths === false) {
            throw new SeedFailed(sprintf('Could not list seeders in "%s".', $this->directory));
        }

        sort($paths, SORT_STRING);

        if ($name !== null) {
            $paths = array_values(array_filter(
                $paths,
                static fn (string $path): bool => basename($path, '.php') === $name,
            ));

            if ($paths === []) {
                throw new SeedFailed(sprintf('Seeder "%s" was not found in "%s".', $name, $this->directory));
            }
        }

        $seeders = [];
        foreach ($paths as $path) {
            try {
                $seeder = (static fn (): mixed => require $path)();
            } catch (Throwable $e) {
                throw new SeedFailed(sprintf('Could not load seeder "%s": %s', $path, $e->getMessage()), 0, $e);
            }

            if (!$seeder instanceof Seeder) {
                throw new SeedFailed(sprintf('Seeder file "%s" must return a %s instance.', $path, Seeder::class));
            }

            $seeders[basename($path, '.php')] = $seeder;
        }

        $completed = [];
        foreach ($seeders as $name => $seeder) {
            try {
                $this->database->transaction(static function (Connection $database) use ($seeder): void {
                    $seeder->run($database);
                });
            } catch (Throwable $e) {
                throw new SeedFailed(sprintf('Seeder "%s" failed: %s', $name, $e->getMessage()), 0, $e);
            }

            $completed[] = (string) $name;
        }

        return $completed;
    }
}
