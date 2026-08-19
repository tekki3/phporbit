<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Console;

use FilesystemIterator;
use InvalidArgumentException;
use PhpOrbit\Console\ControllerMaker;
use PhpOrbit\Console\Scaffold;
use PhpOrbit\Console\Variant;
use PhpOrbit\Http\Headers;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Http\Uri;
use PhpOrbit\Routing\Handler;
use PhpOrbit\View\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Generated code is only worth generating if it runs, so the last test here
 * loads what was written and asks it to handle a request.
 */
final class ControllerMakerTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/orbit-make-' . bin2hex(random_bytes(6));
        mkdir($this->project, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->project);
    }

    public function test_it_writes_a_controller(): void
    {
        $made = $this->maker()->create('Reports');

        self::assertSame('App\Controllers\ReportsController', $made->className);
        self::assertSame('app/src/Controllers/ReportsController.php', $made->controllerPath);
        self::assertNull($made->templatePath);
        self::assertFileExists($this->project . '/app/src/Controllers/ReportsController.php');
    }

    /**
     * Both spellings are what people type; neither should produce
     * `ReportsControllerController`.
     */
    #[DataProvider('equivalentNames')]
    public function test_the_controller_suffix_is_added_once(string $name): void
    {
        self::assertSame('App\Controllers\ReportsController', $this->maker()->create($name)->className);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentNames(): iterable
    {
        yield 'bare' => ['Reports'];
        yield 'suffixed' => ['ReportsController'];
    }

    public function test_nested_names_become_nested_namespaces(): void
    {
        $made = $this->maker()->create('Admin/UserProfile', withView: true);

        self::assertSame('App\Controllers\Admin\UserProfileController', $made->className);
        self::assertSame('app/src/Controllers/Admin/UserProfileController.php', $made->controllerPath);
        self::assertSame('app/templates/admin/user-profile.orbit.php', $made->templatePath);

        self::assertStringContainsString(
            'namespace App\Controllers\Admin;',
            (string) file_get_contents($this->project . '/' . $made->controllerPath),
        );
    }

    public function test_it_suggests_a_route_rather_than_editing_routes_php(): void
    {
        $made = $this->maker()->create('Admin/UserProfile');

        self::assertSame(
            "\$routes->get('/admin/user-profile', UserProfileController::class, 'admin.user-profile');",
            $made->routeSnippet,
        );
        self::assertSame('use App\Controllers\Admin\UserProfileController;', $made->importSnippet);

        // Rewriting a file the developer owns means parsing and re-emitting
        // their code; the command leaves that one line to them.
        self::assertFileDoesNotExist($this->project . '/app/routes.php');
    }

    public function test_the_view_flag_injects_the_engine_and_writes_a_template(): void
    {
        $made = $this->maker()->create('Dashboard', withView: true);

        $source = (string) file_get_contents($this->project . '/' . $made->controllerPath);

        self::assertStringContainsString('use PhpOrbit\View\TemplateEngine;', $source);
        self::assertStringContainsString('private readonly TemplateEngine $view', $source);
        self::assertStringContainsString("respond('dashboard'", $source);

        self::assertNotNull($made->templatePath);
        self::assertFileExists($this->project . '/' . $made->templatePath);
    }

    public function test_without_the_view_flag_nothing_is_injected(): void
    {
        $source = (string) file_get_contents(
            $this->project . '/' . $this->maker()->create('Ping')->controllerPath,
        );

        self::assertStringNotContainsString('TemplateEngine', $source);
        self::assertStringNotContainsString('__construct', $source);
    }

    #[DataProvider('generatedShapes')]
    public function test_what_it_writes_parses_and_is_indented(bool $withView): void
    {
        $made = $this->maker()->create('Reports', withView: $withView);
        $path = $this->project . '/' . $made->controllerPath;

        $output = [];
        $status = 0;
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($path)), $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        // The first version emitted the method at column zero when a
        // constructor preceded it — valid PHP, but obviously generated.
        self::assertStringContainsString(
            '    public function handle(ServerRequest $request): Response',
            (string) file_get_contents($path),
        );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function generatedShapes(): iterable
    {
        yield 'plain' => [false];
        yield 'with view' => [true];
    }

    // --- refusals -------------------------------------------------------------

    /**
     * A name arriving from a script argument must never place a file outside
     * app/src/Controllers, so it is validated rather than sanitised.
     */
    #[DataProvider('badNames')]
    public function test_it_refuses_a_name_that_is_not_a_plain_identifier(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->maker()->create($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'traversal' => ['../../evil'];
        yield 'lowercase' => ['home'];
        yield 'hyphenated' => ['User-Profile'];
        yield 'with extension' => ['Home.php'];
        yield 'empty' => [''];
        yield 'spaces' => ['My Controller'];
    }

    public function test_it_refuses_to_overwrite_without_force(): void
    {
        $this->maker()->create('Reports');
        file_put_contents($this->project . '/app/src/Controllers/ReportsController.php', '<?php // mine');

        try {
            $this->maker()->create('Reports');
            self::fail('an existing controller should not be overwritten');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('--force', $e->getMessage());
        }

        self::assertStringContainsString(
            '// mine',
            (string) file_get_contents($this->project . '/app/src/Controllers/ReportsController.php'),
        );

        $this->maker()->create('Reports', force: true);

        self::assertStringNotContainsString(
            '// mine',
            (string) file_get_contents($this->project . '/app/src/Controllers/ReportsController.php'),
        );
    }

    // --- it actually runs -----------------------------------------------------

    /**
     * Generates a controller into a real scaffolded project, loads it, and
     * handles a request with it — template and all.
     */
    public function test_a_generated_controller_handles_a_request(): void
    {
        (new Scaffold(dirname(__DIR__, 3)))->create($this->project, Variant::Blank, force: true);

        // A name unique to this test: the class is loaded into this process and
        // cannot be redeclared by another.
        $made = (new ControllerMaker($this->project))->create('GeneratedProbe', withView: true);

        require $this->project . '/' . $made->controllerPath;

        self::assertTrue(class_exists($made->className, false));

        $view = new TemplateEngine(
            $this->project . '/app/templates',
            $this->project . '/storage/cache/views',
            alwaysRecompile: true,
            shared: ['sapi' => PHP_SAPI, 'phpVersion' => PHP_VERSION],
        );

        /** @var Handler $controller */
        $controller = new ($made->className)($view);

        $response = $controller->handle(new ServerRequest(
            Method::Get,
            Uri::fromRequestTarget('/generated-probe', 'http', 'localhost', 8080),
            Headers::empty(),
        ));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Status::Ok, $response->status);
        // The default title is humanised from the class name.
        self::assertStringContainsString('Generated Probe', $response->body);
    }

    // --- helpers --------------------------------------------------------------

    private function maker(): ControllerMaker
    {
        return new ControllerMaker($this->project);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
