<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Console;

use FilesystemIterator;
use InvalidArgumentException;
use PhpOrbit\Console\MiddlewareMaker;
use PhpOrbit\Container\Container;
use PhpOrbit\Http\Headers;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Uri;
use PhpOrbit\Middleware\Middleware;
use PhpOrbit\Middleware\Pipeline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Generated code is only worth generating if it runs, so the last test here
 * loads what was written and threads it through a real {@see Pipeline}.
 */
final class MiddlewareMakerTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/orbit-middleware-' . bin2hex(random_bytes(6));
        mkdir($this->project, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->project);
    }

    public function test_it_writes_a_middleware_class(): void
    {
        $made = $this->maker()->create('RequestId');

        self::assertSame('App\Middleware\RequestIdMiddleware', $made->className);
        self::assertSame('app/src/Middleware/RequestIdMiddleware.php', $made->path);
        self::assertFileExists($this->project . '/app/src/Middleware/RequestIdMiddleware.php');
    }

    /**
     * Both spellings are what people type; neither should produce
     * `RequestIdMiddlewareMiddleware`.
     */
    #[DataProvider('equivalentNames')]
    public function test_the_middleware_suffix_is_added_once(string $name): void
    {
        self::assertSame('App\Middleware\RequestIdMiddleware', $this->maker()->create($name)->className);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentNames(): iterable
    {
        yield 'bare' => ['RequestId'];
        yield 'suffixed' => ['RequestIdMiddleware'];
    }

    public function test_nested_names_become_nested_namespaces(): void
    {
        $made = $this->maker()->create('Admin/RequireApiKey');

        self::assertSame('App\Middleware\Admin\RequireApiKeyMiddleware', $made->className);
        self::assertSame('app/src/Middleware/Admin/RequireApiKeyMiddleware.php', $made->path);

        self::assertStringContainsString(
            'namespace App\Middleware\Admin;',
            (string) file_get_contents($this->project . '/' . $made->path),
        );
    }

    public function test_it_suggests_the_registration_rather_than_editing_bootstrap(): void
    {
        $made = $this->maker()->create('RequestId');

        self::assertSame('use App\Middleware\RequestIdMiddleware;', $made->importSnippet);
        self::assertSame('new RequestIdMiddleware(),', $made->registrationSnippet);

        // Where in the list it goes is a decision only the developer can make
        // (session before CSRF, logging outermost), so nothing is inserted.
        self::assertFileDoesNotExist($this->project . '/app/bootstrap.php');
    }

    public function test_what_it_writes_parses_and_passes_through_by_default(): void
    {
        $made = $this->maker()->create('RequestId');
        $path = $this->project . '/' . $made->path;

        $output = [];
        $status = 0;
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($path)), $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        $source = (string) file_get_contents($path);
        self::assertStringContainsString('implements Middleware', $source);
        self::assertStringContainsString('return $next($request);', $source);
    }

    // --- refusals -------------------------------------------------------------

    /**
     * A name arriving from a script argument must never place a file outside
     * app/src/Middleware, so it is validated rather than sanitised.
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
        yield 'lowercase' => ['requestId'];
        yield 'hyphenated' => ['Request-Id'];
        yield 'with extension' => ['RequestId.php'];
        yield 'empty' => [''];
        yield 'reserved word' => ['List'];
    }

    public function test_it_refuses_to_overwrite_without_force(): void
    {
        $made = $this->maker()->create('RequestId');
        file_put_contents($this->project . '/' . $made->path, '<?php // mine');

        try {
            $this->maker()->create('RequestId');
            self::fail('an existing middleware should not be overwritten');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('--force', $e->getMessage());
        }

        self::assertStringContainsString('// mine', (string) file_get_contents($this->project . '/' . $made->path));

        $this->maker()->create('RequestId', force: true);

        self::assertStringNotContainsString(
            '// mine',
            (string) file_get_contents($this->project . '/' . $made->path),
        );
    }

    // --- it actually runs -----------------------------------------------------

    /**
     * Loads what was written and runs it through a real Pipeline — proving the
     * default body is a genuine pass-through, not just a class that parses.
     */
    public function test_a_generated_middleware_passes_a_request_through_a_pipeline(): void
    {
        // A name unique to this test: the class is loaded into this process and
        // cannot be redeclared by another.
        $made = (new MiddlewareMaker($this->project))->create('GeneratedProbe');

        require $this->project . '/' . $made->path;

        self::assertTrue(class_exists($made->className, false));

        /** @var Middleware $middleware */
        $middleware = new ($made->className)();

        self::assertInstanceOf(Middleware::class, $middleware);

        $scope = (new Container())->enterRequest();
        $request = new ServerRequest(
            Method::Get,
            Uri::fromRequestTarget('/', 'http', 'localhost', 8080),
            Headers::empty(),
        );

        $response = Pipeline::run(
            [$middleware],
            $request,
            $scope,
            static fn (ServerRequest $r): Response => Response::text('reached the handler'),
        );

        self::assertSame('reached the handler', $response->body);
    }

    // --- helpers --------------------------------------------------------------

    private function maker(): MiddlewareMaker
    {
        return new MiddlewareMaker($this->project);
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
