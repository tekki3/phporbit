<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Database;

use PhpOrbit\Database\Connection;
use PhpOrbit\Database\SeedFailed;
use PhpOrbit\Database\SeedRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SeedRunnerTest extends TestCase
{
    private Connection $database;
    private string $directory;

    protected function setUp(): void
    {
        $this->database = Connection::sqlite(':memory:');
        $this->database->executeSchema('CREATE TABLE items (name TEXT NOT NULL)');
        $this->directory = sys_get_temp_dir() . '/orbit-seeders-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function test_it_runs_seeders_in_filename_order_and_ignores_other_files(): void
    {
        $this->write('0002_second', '$database->query("items")->insert(["name" => "second"]);');
        $this->write('0001_first', '$database->query("items")->insert(["name" => "first"]);');
        file_put_contents($this->directory . '/README.txt', 'Not a seeder');

        self::assertSame(['0001_first', '0002_second'], $this->runner()->run());
        self::assertSame([['name' => 'first'], ['name' => 'second']], $this->database->query('items')->get());
        self::assertFalse($this->database->inTransaction());
    }

    public function test_it_runs_only_the_named_seeder(): void
    {
        $this->write('0001_first', '$database->query("items")->insert(["name" => "first"]);');
        $this->write('0002_second', '$database->query("items")->insert(["name" => "second"]);');
        file_put_contents($this->directory . '/0003_invalid.php', '<?php return null;');

        self::assertSame(['0002_second'], $this->runner()->run('0002_second'));
        self::assertSame([['name' => 'second']], $this->database->query('items')->get());
    }

    public function test_a_requested_seeder_must_exist(): void
    {
        $this->write('0001_first', '$database->query("items")->insert(["name" => "first"]);');

        $this->expectException(SeedFailed::class);
        $this->expectExceptionMessage('Seeder "0002_missing" was not found');

        $this->runner()->run('0002_missing');
    }

    public function test_every_run_loads_fresh_seeders_and_repeats_them(): void
    {
        $this->write('0001_items', <<<'PHP'
            private int $calls = 0;

            public function run(Connection $database): void
            {
                ++$this->calls;
                if ($this->calls !== 1) {
                    throw new \RuntimeException('Seeder instance was reused.');
                }
                if ($database->query('items')->where('name', '=', 'starter')->first() === null) {
                    $database->query('items')->insert(['name' => 'starter']);
                }
            }
            PHP, completeBody: true);
        $runner = $this->runner();

        self::assertSame(['0001_items'], $runner->run());
        self::assertSame(['0001_items'], $runner->run());
        self::assertSame(1, $this->database->query('items')->count());
    }

    public function test_a_failure_rolls_back_only_that_seeder_and_stops_the_run(): void
    {
        $this->write('0001_good', '$database->query("items")->insert(["name" => "good"]);');
        $this->write('0002_bad', <<<'PHP'
            $database->query('items')->insert(['name' => 'bad']);
            throw new \RuntimeException('Broken seed');
            PHP);
        $this->write('0003_later', '$database->query("items")->insert(["name" => "later"]);');

        try {
            $this->runner()->run();
            self::fail('Expected a seeding failure.');
        } catch (SeedFailed $e) {
            self::assertStringContainsString('0002_bad', $e->getMessage());
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }

        self::assertSame([['name' => 'good']], $this->database->query('items')->get());
        self::assertFalse($this->database->inTransaction());
    }

    public function test_all_files_are_validated_before_running_any_seeder(): void
    {
        $this->write('0001_good', '$database->query("items")->insert(["name" => "good"]);');
        file_put_contents($this->directory . '/0002_invalid.php', '<?php return null;');

        try {
            $this->runner()->run();
            self::fail('Expected an invalid seeder failure.');
        } catch (SeedFailed $e) {
            self::assertStringContainsString('must return a PhpOrbit\Database\Seeder', $e->getMessage());
        }

        self::assertSame(0, $this->database->query('items')->count());
    }

    public function test_load_errors_identify_the_file_and_keep_the_cause(): void
    {
        file_put_contents($this->directory . '/broken.php', '<?php throw new \RuntimeException("Load failed");');

        try {
            $this->runner()->run();
            self::fail('Expected a load failure.');
        } catch (SeedFailed $e) {
            self::assertStringContainsString('broken.php', $e->getMessage());
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    public function test_empty_or_missing_directories_have_nothing_to_seed(): void
    {
        self::assertSame([], $this->runner()->run());
        self::assertSame([], (new SeedRunner($this->database, $this->directory . '/missing'))->run());
    }

    public function test_a_file_instead_of_a_directory_is_an_error(): void
    {
        $path = $this->directory . '/not-a-directory';
        file_put_contents($path, '');

        $this->expectException(SeedFailed::class);
        $this->expectExceptionMessage('is not readable');

        (new SeedRunner($this->database, $path))->run();
    }

    private function runner(): SeedRunner
    {
        return new SeedRunner($this->database, $this->directory);
    }

    private function write(string $name, string $body, bool $completeBody = false): void
    {
        if (!$completeBody) {
            $body = 'public function run(Connection $database): void {' . $body . '}';
        }

        file_put_contents($this->directory . '/' . $name . '.php', sprintf(
            '<?php use PhpOrbit\Database\Connection; use PhpOrbit\Database\Seeder; return new class implements Seeder {%s};',
            $body,
        ));
    }
}
