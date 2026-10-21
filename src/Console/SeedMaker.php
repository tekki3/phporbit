<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use InvalidArgumentException;
use RuntimeException;

final readonly class SeedMaker
{
    public function __construct(private string $root)
    {
    }

    /** @return string project-relative path of the generated seeder */
    public function create(string $name, bool $force = false): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid seed name "%s". Use lowercase words and underscores, optionally prefixed '
                . 'with digits for ordering, e.g. 0002_categories.',
                $name,
            ));
        }

        $relative = 'database/seeders/' . $name . '.php';
        $path = $this->root . '/' . $relative;

        if (file_exists($path) && !$force) {
            throw new RuntimeException(sprintf('%s already exists. Pass --force to overwrite it.', $relative));
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create directory "%s".', $directory));
        }

        $source = <<<'PHP'
            <?php

            declare(strict_types=1);

            use PhpOrbit\Database\Connection;
            use PhpOrbit\Database\Seeder;

            return new class implements Seeder {
                public function run(Connection $database): void
                {
                    // Check for existing data before inserting: seeders run on every db:seed invocation.
                }
            };

            PHP;

        if (@file_put_contents($path, $source) === false) {
            throw new RuntimeException(sprintf('Cannot write "%s".', $relative));
        }

        return $relative;
    }
}
