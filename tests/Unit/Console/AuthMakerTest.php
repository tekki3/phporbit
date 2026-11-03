<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Console;

use FilesystemIterator;
use PhpOrbit\Console\AuthMaker;
use PhpOrbit\Console\Scaffold;
use PhpOrbit\Console\Variant;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class AuthMakerTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/orbit-auth-maker-' . bin2hex(random_bytes(6));
        (new Scaffold(dirname(__DIR__, 3)))->create($this->project, Variant::Blank);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->project, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($this->project);
    }

    public function test_it_generates_complete_editable_auth_without_rewriting_project_files(): void
    {
        $bootstrap = file_get_contents($this->project . '/app/bootstrap.php');
        $routes = file_get_contents($this->project . '/app/routes.php');
        $files = (new AuthMaker($this->project))->create();
        foreach ([
            'app/auth.php', 'app/auth-routes.php', 'app/src/AuthKit/Accounts.php',
            'app/src/AuthKit/Controllers/RegisterController.php', 'app/src/AuthKit/Controllers/MfaVerifyController.php',
            'app/src/AuthKit/Controllers/UsersController.php', 'app/templates/auth-kit/login.orbit.php',
            'public/assets/css/auth.css', 'tests/AuthKitTest.php',
        ] as $path) {
            self::assertContains($path, $files);
            self::assertFileExists($this->project . '/' . $path);
        }
        self::assertSame($bootstrap, file_get_contents($this->project . '/app/bootstrap.php'));
        self::assertSame($routes, file_get_contents($this->project . '/app/routes.php'));
        foreach ($files as $file) {
            if (str_ends_with($file, '.php') && !str_ends_with($file, '.orbit.php')) {
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($this->project . '/' . $file) . ' 2>&1', $output, $status);
                self::assertSame(0, $status, implode("\n", $output));
            }
        }
    }

    public function test_existing_target_is_detected_before_any_write(): void
    {
        mkdir($this->project . '/app/src/AuthKit/Controllers', 0o755, true);
        $target = $this->project . '/app/src/AuthKit/Controllers/MfaVerifyController.php';
        file_put_contents($target, 'existing user code');
        try {
            (new AuthMaker($this->project))->create();
            self::fail('Existing code must never be replaced.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Refusing to overwrite', $exception->getMessage());
        }
        self::assertSame('existing user code', file_get_contents($target));
        self::assertFileDoesNotExist($this->project . '/app/auth.php');
        self::assertSame([], glob($this->project . '/database/migrations/*_create_auth_kit.php'));
    }

    public function test_regeneration_is_refused_without_creating_a_second_migration(): void
    {
        $maker = new AuthMaker($this->project);
        $maker->create();
        try {
            $maker->create();
            self::fail('Regeneration must be refused.');
        } catch (RuntimeException) {
            self::assertCount(1, glob($this->project . '/database/migrations/*_create_auth_kit.php') ?: []);
        }
    }

    public function test_symlinked_target_directories_are_refused(): void
    {
        symlink($this->project . '/storage', $this->project . '/app/src/AuthKit');
        $this->expectException(RuntimeException::class);
        (new AuthMaker($this->project))->create();
    }

    public function test_the_demo_is_not_overlaid_with_duplicate_authentication(): void
    {
        mkdir($this->project . '/app/src/Auth');
        file_put_contents($this->project . '/app/src/Auth/DatabaseUserProvider.php', '<?php');
        $this->expectException(RuntimeException::class);
        (new AuthMaker($this->project))->create();
    }

    public function test_generated_authentication_boots_and_passes_its_worker_journeys(): void
    {
        (new AuthMaker($this->project))->create();
        $framework = dirname(__DIR__, 3);
        $bootstrap = $this->project . '/test-bootstrap.php';
        file_put_contents($bootstrap, '<?php require ' . var_export($framework . '/vendor/autoload.php', true) . ';'
            . 'spl_autoload_register(static function (string $class): void {'
            . 'if (str_starts_with($class, "App\\\\")) {'
            . '$path = __DIR__ . "/app/src/" . str_replace("\\\\", "/", substr($class, 4)) . ".php";'
            . 'if (is_file($path)) { require $path; }'
            . '}}, true, true);');
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($framework . '/vendor/bin/phpunit')
            . ' --no-configuration --bootstrap ' . escapeshellarg($bootstrap)
            . ' --cache-directory ' . escapeshellarg($this->project . '/.phpunit.cache')
            . ' ' . escapeshellarg($this->project . '/tests/AuthKitTest.php') . ' 2>&1';
        exec($command, $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('OK (4 tests', implode("\n", $output));
    }

    public function test_scaffolded_cli_generates_without_booting_or_touching_the_database(): void
    {
        mkdir($this->project . '/vendor');
        file_put_contents($this->project . '/vendor/autoload.php',
            '<?php return require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';');
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->project . '/orbit');
        exec($command . ' make:auth 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString(AuthMaker::WIRING, implode("\n", $output));
        self::assertFileExists($this->project . '/app/auth.php');
        self::assertFileDoesNotExist($this->project . '/storage/app.sqlite');
        exec($command . ' make:auth --force 2>&1', $rejected, $exit);
        self::assertSame(1, $exit);
        self::assertStringContainsString('no options', implode("\n", $rejected));
    }
}
