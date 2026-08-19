<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Console;

use FilesystemIterator;
use InvalidArgumentException;
use PhpOrbit\Console\ClassMaker;
use PhpOrbit\Console\Lifetime;
use PhpOrbit\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Generated code is only worth generating if it runs, so the last tests here
 * load what was written and resolve it the way the application would.
 */
final class ClassMakerTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/orbit-class-' . bin2hex(random_bytes(6));
        mkdir($this->project, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->project);
    }

    public function test_it_writes_a_class_under_the_app_namespace(): void
    {
        $made = $this->maker()->create('Clock');

        self::assertSame('App\Clock', $made->className);
        self::assertSame('app/src/Clock.php', $made->path);
        self::assertFileExists($this->project . '/app/src/Clock.php');
        self::assertStringContainsString(
            'final class Clock',
            (string) file_get_contents($this->project . '/' . $made->path),
        );
    }

    public function test_nested_names_become_nested_namespaces(): void
    {
        $made = $this->maker()->create('Notes/NoteRepository');

        self::assertSame('App\Notes\NoteRepository', $made->className);
        self::assertSame('app/src/Notes/NoteRepository.php', $made->path);
        self::assertStringContainsString(
            'namespace App\Notes;',
            (string) file_get_contents($this->project . '/' . $made->path),
        );
    }

    /**
     * A fully qualified name is how people refer to a class, so pasting one is
     * expected — and `App\App\Notes` would be nobody's intent.
     */
    #[DataProvider('equivalentNames')]
    public function test_a_leading_app_segment_is_not_repeated(string $name): void
    {
        self::assertSame('App\Notes\NoteRepository', $this->maker()->create($name, force: true)->className);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentNames(): iterable
    {
        yield 'relative' => ['Notes/NoteRepository'];
        yield 'qualified' => ['App\Notes\NoteRepository'];
        yield 'qualified with slashes' => ['App/Notes/NoteRepository'];
    }

    /**
     * `App` alone is a legitimate class name; only a *prefix* is dropped.
     */
    public function test_a_class_actually_named_app_still_works(): void
    {
        self::assertSame('App\App', $this->maker()->create('App')->className);
    }

    // --- the lifetime decision -------------------------------------------------

    public function test_the_default_lifetime_needs_no_registration(): void
    {
        $made = $this->maker()->create('Tagger');

        self::assertSame(Lifetime::Autowired, $made->lifetime);
        self::assertNull($made->registrationSnippet);
        self::assertStringContainsString(
            'request scope',
            (string) file_get_contents($this->project . '/' . $made->path),
        );
    }

    public function test_a_singleton_gets_a_bootstrap_line_and_a_stateless_warning(): void
    {
        $made = $this->maker()->create('Clock', Lifetime::Singleton);

        self::assertSame(
            '$app->container->singleton(Clock::class, static fn (): Clock => new Clock());',
            $made->registrationSnippet,
        );

        // The reason the flag exists: a singleton holding one request's data is
        // how an application that works under FPM breaks under a worker.
        self::assertStringContainsString(
            'must be stateless',
            (string) file_get_contents($this->project . '/' . $made->path),
        );
    }

    public function test_a_scoped_class_gets_the_scoped_registration(): void
    {
        $made = $this->maker()->create('Basket', Lifetime::Scoped);

        self::assertSame(
            '$app->container->scoped(Basket::class, static fn (): Basket => new Basket());',
            $made->registrationSnippet,
        );
    }

    public function test_it_suggests_the_injection_rather_than_editing_bootstrap(): void
    {
        $made = $this->maker()->create('Notes/NoteRepository', Lifetime::Singleton);

        self::assertSame('use App\Notes\NoteRepository;', $made->importSnippet);
        self::assertSame('private readonly NoteRepository $noteRepository,', $made->injectionSnippet);

        // Rewriting a file the developer owns means parsing and re-emitting
        // their code; the command leaves those lines to them.
        self::assertFileDoesNotExist($this->project . '/app/bootstrap.php');
    }

    /**
     * The flags name one lifetime each, so passing both is a question, not a
     * preference to resolve silently.
     */
    public function test_the_two_lifetime_flags_are_mutually_exclusive(): void
    {
        self::assertSame(Lifetime::Autowired, Lifetime::fromFlags(singleton: false, scoped: false));
        self::assertSame(Lifetime::Singleton, Lifetime::fromFlags(singleton: true, scoped: false));
        self::assertSame(Lifetime::Scoped, Lifetime::fromFlags(singleton: false, scoped: true));

        $this->expectException(InvalidArgumentException::class);

        Lifetime::fromFlags(singleton: true, scoped: true);
    }

    #[DataProvider('lifetimes')]
    public function test_what_it_writes_parses(Lifetime $lifetime): void
    {
        $path = $this->project . '/' . $this->maker()->create('Reports', $lifetime)->path;

        $output = [];
        $status = 0;
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($path)), $output, $status);

        self::assertSame(0, $status, implode("\n", $output));

        // A comment carrying trailing whitespace on its blank lines is the kind
        // of detail that shows generated code was never read.
        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString(" \n", $source);
        self::assertStringEndsWith("}\n", $source);
    }

    /**
     * @return iterable<string, array{Lifetime}>
     */
    public static function lifetimes(): iterable
    {
        foreach (Lifetime::cases() as $lifetime) {
            yield $lifetime->value => [$lifetime];
        }
    }

    // --- refusals -------------------------------------------------------------

    /**
     * A name arriving from a script argument must never place a file outside
     * app/src, so it is validated rather than sanitised.
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
        yield 'lowercase' => ['clock'];
        yield 'hyphenated' => ['Note-Repository'];
        yield 'with extension' => ['Clock.php'];
        yield 'empty' => [''];
        yield 'spaces' => ['Note Repository'];
    }

    /**
     * Writing a file PHP cannot parse is worse than refusing the name: the error
     * would surface as a syntax error in generated code instead of an answer.
     */
    #[DataProvider('reservedNames')]
    public function test_it_refuses_a_reserved_word(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->maker()->create($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedNames(): iterable
    {
        yield 'keyword' => ['List'];
        yield 'match' => ['Match'];
        yield 'type name' => ['String'];
        yield 'in the namespace' => ['Static/Helper'];
    }

    public function test_it_refuses_to_overwrite_without_force(): void
    {
        $made = $this->maker()->create('Clock');
        file_put_contents($this->project . '/' . $made->path, '<?php // mine');

        try {
            $this->maker()->create('Clock');
            self::fail('an existing class should not be overwritten');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('--force', $e->getMessage());
        }

        self::assertStringContainsString('// mine', (string) file_get_contents($this->project . '/' . $made->path));

        $this->maker()->create('Clock', force: true);

        self::assertStringNotContainsString('// mine', (string) file_get_contents($this->project . '/' . $made->path));
    }

    // --- it actually runs -----------------------------------------------------

    /**
     * The point of the default lifetime: a generated class is usable from a
     * controller with no registration at all, because the request scope builds
     * what it does not recognise.
     */
    public function test_a_generated_class_is_autowired_by_the_request_scope(): void
    {
        $made = (new ClassMaker($this->project))->create('GeneratedProbeService');

        require $this->project . '/' . $made->path;

        self::assertTrue(class_exists($made->className, false));

        $container = new Container();
        $container->freeze();
        $scope = $container->enterRequest();

        $instance = $scope->get($made->className);

        self::assertInstanceOf($made->className, $instance);
        // Per request, not per process — the property that makes autowiring the
        // safe default under a worker.
        self::assertNotSame($instance, $container->enterRequest()->get($made->className));
    }

    /**
     * And the point of the printed registration line: pasted into a boot
     * callback it resolves, with the lifetime the flag promised.
     */
    public function test_the_singleton_registration_line_resolves_once_per_process(): void
    {
        $made = (new ClassMaker($this->project))->create('GeneratedProbeClock', Lifetime::Singleton);

        require $this->project . '/' . $made->path;

        self::assertTrue(class_exists($made->className, false));

        $class = $made->className;

        $container = new Container();
        $container->singleton($class, static fn (): object => new $class());
        $container->freeze();

        $first = $container->get($class);

        self::assertInstanceOf($class, $first);
        self::assertSame($first, $container->enterRequest()->get($class));
        self::assertSame($first, $container->enterRequest()->get($class));
    }

    // --- helpers --------------------------------------------------------------

    private function maker(): ClassMaker
    {
        return new ClassMaker($this->project);
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
