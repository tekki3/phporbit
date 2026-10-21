<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Console;

use InvalidArgumentException;
use PhpOrbit\Console\SeedMaker;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Seeder;
use PhpOrbit\Database\SeedRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SeedMakerTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/orbit-seed-maker-' . bin2hex(random_bytes(6));
        mkdir($this->project, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->project . '/database/seeders')) {
            foreach (glob($this->project . '/database/seeders/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->project . '/database/seeders');
        }
        if (is_dir($this->project . '/database')) {
            rmdir($this->project . '/database');
        }
        rmdir($this->project);
    }

    public function test_it_creates_a_valid_runnable_seeder_and_missing_directories(): void
    {
        $path = (new SeedMaker($this->project))->create('0002_categories');

        self::assertSame('database/seeders/0002_categories.php', $path);
        self::assertFileExists($this->project . '/' . $path);
        self::assertInstanceOf(Seeder::class, require $this->project . '/' . $path);

        $runner = new SeedRunner(Connection::sqlite(':memory:'), $this->project . '/database/seeders');
        self::assertSame(['0002_categories'], $runner->run());
    }

    public function test_it_preserves_an_existing_file_unless_force_is_passed(): void
    {
        $maker = new SeedMaker($this->project);
        $path = $maker->create('categories');
        file_put_contents($this->project . '/' . $path, 'User-owned contents');

        try {
            $maker->create('categories');
            self::fail('Expected an overwrite refusal.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('--force', $e->getMessage());
        }

        self::assertSame('User-owned contents', file_get_contents($this->project . '/' . $path));
        self::assertSame($path, $maker->create('categories', force: true));
        self::assertInstanceOf(Seeder::class, require $this->project . '/' . $path);
    }

    #[DataProvider('invalidNames')]
    public function test_it_refuses_invalid_names_before_creating_directories(string $name): void
    {
        try {
            (new SeedMaker($this->project))->create($name);
            self::fail('Expected an invalid name refusal.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid seed name', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->project . '/database');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        foreach (['', '../evil', '..\\evil', 'nested/seed', 'nested\\seed', 'seed.php', 'SeedName', 'seed name', "seed\n", '_seed', 'CON:seed'] as $name) {
            yield $name => [$name];
        }
    }

    public function test_directory_creation_errors_are_reported(): void
    {
        file_put_contents($this->project . '/database', 'Not a directory');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot create directory');
            (new SeedMaker($this->project))->create('categories');
        } finally {
            unlink($this->project . '/database');
        }
    }
}
