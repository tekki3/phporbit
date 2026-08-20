<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Console;

use PhpOrbit\Console\Scaffold;
use PhpOrbit\Console\Variant;
use PhpOrbit\Database\Model;
use PhpOrbit\Http\Headers;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Http\Uri;
use PhpOrbit\Kernel\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ValueError;

/**
 * A scaffold is only useful if what it writes actually runs, so the blank
 * variant is booted here and asked to serve a request — not merely inspected
 * for the presence of files.
 */
final class ScaffoldTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/orbit-scaffold-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    // --- structure ------------------------------------------------------------

    #[DataProvider('variants')]
    public function test_it_writes_a_runnable_project(Variant $variant): void
    {
        $target = $this->workspace . '/my-app';

        $this->scaffold()->create($target, $variant);

        foreach ([
            'composer.json',
            '.env',
            '.env.example',
            '.gitignore',
            'orbit',
            'phpunit.xml',
            'phpstan.neon',
            'README.md',
            'app/bootstrap.php',
            'app/routes.php',
            'public/index.php',
            'public/.htaccess',
        ] as $file) {
            self::assertFileExists($target . '/' . $file, $variant->value);
        }

        foreach (['storage/sessions', 'storage/cache/views', 'database/migrations'] as $directory) {
            self::assertDirectoryExists($target . '/' . $directory, $variant->value);
        }
    }

    /**
     * @return iterable<string, array{Variant}>
     */
    public static function variants(): iterable
    {
        yield 'blank' => [Variant::Blank];
        yield 'demo' => [Variant::Demo];
    }

    /**
     * phporbit is in no model's training data, so an assistant working on a
     * scaffolded project will otherwise guess from Laravel — and those guesses
     * are wrong in precisely the places this framework cares about.
     */
    #[DataProvider('variants')]
    public function test_it_ships_instructions_for_ai_assistants(Variant $variant): void
    {
        $target = $this->workspace . '/my-app';

        $this->scaffold()->create($target, $variant);

        foreach (['AGENTS.md', 'CLAUDE.md', '.github/copilot-instructions.md'] as $file) {
            self::assertFileExists($target . '/' . $file, $variant->value);
        }

        $rules = (string) file_get_contents($target . '/AGENTS.md');

        // The constraints that differ from every framework it will be confused
        // with. If these stop being stated, the file has lost its purpose.
        foreach (['$_SESSION', 'STDERR', 'singleton', '{!!', 'affectingEveryRow'] as $constraint) {
            self::assertStringContainsString($constraint, $rules);
        }
    }

    /**
     * One source, pointed at from the others. Two copies of the same rules
     * drift, and then disagree exactly when it matters.
     */
    #[DataProvider('variants')]
    public function test_the_other_assistant_files_point_at_agents_md(Variant $variant): void
    {
        $target = $this->workspace . '/my-app';

        $this->scaffold()->create($target, $variant);

        foreach (['CLAUDE.md', '.github/copilot-instructions.md'] as $pointer) {
            $contents = (string) file_get_contents($target . '/' . $pointer);

            self::assertStringContainsString('AGENTS.md', $contents, $pointer);

            // A pointer, not a second copy: it must stay far shorter than the
            // file it defers to.
            self::assertLessThan(
                40,
                substr_count($contents, "\n"),
                $pointer . ' looks like a copy of AGENTS.md rather than a pointer to it',
            );
        }
    }

    /**
     * Documentation belongs to the framework. A copy inside every application
     * is a copy that goes stale, and `/docs` would be a route the project never
     * asked for.
     */
    #[DataProvider('variants')]
    public function test_no_documentation_is_copied_into_a_project(Variant $variant): void
    {
        $target = $this->workspace . '/my-app';

        $this->scaffold()->create($target, $variant);

        self::assertDirectoryDoesNotExist($target . '/docs');

        // And the application must not try to mount it either.
        $bootstrap = (string) file_get_contents($target . '/app/bootstrap.php');

        if (str_contains($bootstrap, "'/docs'")) {
            self::assertStringContainsString(
                'hasDocs',
                $bootstrap,
                'the docs mount must be conditional, or a scaffolded project fails to boot',
            );
        }
    }

    #[DataProvider('variants')]
    public function test_every_php_file_it_writes_parses(Variant $variant): void
    {
        $target = $this->workspace . '/my-app';

        $this->scaffold()->create($target, $variant);

        foreach ($this->phpFiles($target) as $file) {
            $output = [];
            $status = 0;
            exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $status);

            self::assertSame(0, $status, $file . "\n" . implode("\n", $output));
        }
    }

    public function test_the_manifest_takes_its_name_from_the_directory(): void
    {
        $this->scaffold()->create($this->workspace . '/My Shiny App!', Variant::Blank);

        /** @var array{name: string, require: array<string, string>, autoload: array{'psr-4': array<string, string>}} $manifest */
        $manifest = json_decode(
            (string) file_get_contents($this->workspace . '/My Shiny App!/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        // Composer requires a lowercase vendor/package with a narrow charset.
        self::assertSame('app/my-shiny-app', $manifest['name']);
        self::assertArrayHasKey('phporbit/phporbit', $manifest['require']);
        self::assertSame('app/src/', $manifest['autoload']['psr-4']['App\\']);
    }

    // --- safety ---------------------------------------------------------------

    /**
     * Writing over an existing project would replace its bootstrap and routes
     * without warning.
     */
    public function test_it_refuses_a_directory_that_is_not_empty(): void
    {
        $target = $this->workspace . '/occupied';
        mkdir($target);
        file_put_contents($target . '/keep-me.txt', 'important');

        try {
            $this->scaffold()->create($target, Variant::Blank);
            self::fail('an occupied directory should have been refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('--force', $e->getMessage());
        }

        self::assertFileExists($target . '/keep-me.txt');
        self::assertFileDoesNotExist($target . '/app/bootstrap.php');
    }

    public function test_force_writes_into_an_occupied_directory(): void
    {
        $target = $this->workspace . '/occupied';
        mkdir($target);
        file_put_contents($target . '/keep-me.txt', 'important');

        $this->scaffold()->create($target, Variant::Blank, force: true);

        self::assertFileExists($target . '/app/bootstrap.php');
        // Existing files that the scaffold does not write are left alone.
        self::assertFileExists($target . '/keep-me.txt');
    }

    public function test_variant_names_people_actually_type(): void
    {
        self::assertSame(Variant::Blank, Variant::fromName('empty'));
        self::assertSame(Variant::Blank, Variant::fromName('blank'));
        self::assertSame(Variant::Demo, Variant::fromName('demo'));
        self::assertSame(Variant::Demo, Variant::fromName('  TestSite '));

        $this->expectException(ValueError::class);
        Variant::fromName('kitchen-sink');
    }

    // --- it actually runs -----------------------------------------------------

    /**
     * The point of the whole command: boot what was written and serve a page.
     *
     * The project's own autoloader would come from `composer install`, which
     * needs a network. Registering the same PSR-4 mapping by hand is equivalent
     * for this purpose and keeps the test offline.
     */
    public function test_a_blank_project_boots_and_serves(): void
    {
        $target = $this->workspace . '/my-app';

        $this->scaffold()->create($target, Variant::Blank);

        $autoloader = static function (string $class) use ($target): void {
            if (!str_starts_with($class, 'App\\')) {
                return;
            }

            $path = $target . '/app/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';

            if (is_file($path)) {
                require $path;
            }
        };

        spl_autoload_register($autoloader);

        // The real environment beats the scaffolded .env, which is both the
        // documented precedence rule and what keeps request logs out of the
        // test output.
        $previousLevel = getenv('LOG_LEVEL');
        putenv('LOG_LEVEL=error');

        // Model::useConnection() is boot-time wiring; a real deployment calls
        // it once, but this test process boots several scaffolded apps in a
        // row, and each is entitled to point Model at its own connection.
        Model::resetConnectionForTesting();

        try {
            /** @var Application $application */
            $application = require $target . '/app/bootstrap.php';

            $home = $application->handle($this->get('/'));

            self::assertSame(Status::Ok, $home->status);
            self::assertStringContainsString('It works', $home->body);
            self::assertSame('nosniff', $home->headers->first('X-Content-Type-Options'));

            $health = $application->handle($this->get('/health'));
            self::assertSame(Status::Ok, $health->status);
            self::assertStringContainsString('"status":"ok"', $health->body);

            self::assertSame(Status::NotFound, $application->handle($this->get('/nope'))->status);

            // The stylesheet is served by the static middleware the stub wires up.
            self::assertSame(Status::Ok, $application->handle($this->get('/assets/app.css'))->status);

            // Booted once, handled several times — no state carried between them.
            self::assertSame($home->body, $application->handle($this->get('/'))->body);
        } finally {
            spl_autoload_unregister($autoloader);

            $previousLevel === false ? putenv('LOG_LEVEL') : putenv('LOG_LEVEL=' . $previousLevel);
        }
    }

    // --- helpers --------------------------------------------------------------

    private function scaffold(): Scaffold
    {
        return new Scaffold(dirname(__DIR__, 3));
    }

    private function get(string $path): ServerRequest
    {
        return new ServerRequest(
            Method::Get,
            Uri::fromRequestTarget($path, 'http', 'localhost', 8080),
            Headers::empty(),
        );
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $found = [];

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isFile() && ($item->getExtension() === 'php' || $item->getFilename() === 'orbit')) {
                $found[] = $item->getPathname();
            }
        }

        return $found;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
