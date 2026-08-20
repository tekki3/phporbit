<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Console;

use FilesystemIterator;
use InvalidArgumentException;
use PhpOrbit\Console\ModelMaker;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Model;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use RuntimeException;
use SplFileInfo;

final class ModelMakerTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/orbit-model-' . bin2hex(random_bytes(6));
        mkdir($this->project, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->project);
        Model::resetConnectionForTesting();
    }

    // --- naming and table inference -----------------------------------------

    public function test_it_writes_a_model_under_app_models(): void
    {
        $made = $this->maker()->create('Note');

        self::assertSame('App\Models\Note', $made->className);
        self::assertSame('app/src/Models/Note.php', $made->path);
        self::assertFileExists($this->project . '/' . $made->path);
    }

    public function test_nested_names_become_nested_namespaces(): void
    {
        $made = $this->maker()->create('Blog/Post');

        self::assertSame('App\Models\Blog\Post', $made->className);
        self::assertSame('app/src/Models/Blog/Post.php', $made->path);
    }

    /**
     * A leading `App` or `Models` segment is how people paste a fully
     * qualified name; `App\Models\Models\Note` would be nobody's intent.
     */
    public function test_leading_app_and_models_segments_are_not_repeated(): void
    {
        foreach (['Note', 'Models/Note', 'App\Models\Note', 'App/Models/Note'] as $name) {
            self::assertSame('App\Models\Note', $this->maker()->create($name, force: true)->className);
        }
    }

    public function test_the_table_name_is_guessed_and_pluralised(): void
    {
        self::assertSame('notes', $this->maker()->create('Note')->table);
        self::assertSame('categories', $this->maker()->create('Category')->table);
        self::assertSame('boxes', $this->maker()->create('Box')->table);
    }

    public function test_an_explicit_table_overrides_the_guess(): void
    {
        self::assertSame('archive', $this->maker()->create('Note', table: 'archive')->table);
    }

    public function test_it_refuses_an_invalid_table_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->maker()->create('Note', table: 'bad table');
    }

    // --- fields --------------------------------------------------------------

    public function test_it_writes_typed_properties_for_each_field(): void
    {
        $made = $this->maker()->create('Note', fields: 'title:string,views:int,archived_at:?string');

        self::assertSame(['title', 'views', 'archived_at'], $made->fieldNames);

        $source = (string) file_get_contents($this->project . '/' . $made->path);
        self::assertStringContainsString("public string \$title = '';", $source);
        self::assertStringContainsString('public int $views = 0;', $source);
        self::assertStringContainsString('public ?string $archivedAt = null;', $source);
    }

    public function test_a_model_with_no_fields_still_produces_valid_code(): void
    {
        $made = $this->maker()->create('Category');

        self::assertSame([], $made->fieldNames);

        $source = (string) file_get_contents($this->project . '/' . $made->path);
        self::assertStringContainsString('return [];', $source);
    }

    public function test_it_refuses_a_field_declared_twice(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->maker()->create('Note', fields: 'title:string,title:int');
    }

    public function test_it_refuses_an_unknown_field_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->maker()->create('Note', fields: 'title:email');
    }

    // --- refusals --------------------------------------------------------------

    public function test_it_refuses_to_overwrite_without_force(): void
    {
        $made = $this->maker()->create('Note');
        file_put_contents($this->project . '/' . $made->path, '<?php // mine');

        try {
            $this->maker()->create('Note');
            self::fail('an existing model should not be overwritten');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('--force', $e->getMessage());
        }

        $this->maker()->create('Note', force: true);
        self::assertStringNotContainsString(
            '// mine',
            (string) file_get_contents($this->project . '/' . $made->path),
        );
    }

    // --- it actually runs -------------------------------------------------------

    public function test_a_generated_model_parses_and_performs_a_full_crud_roundtrip(): void
    {
        $made = (new ModelMaker($this->project))->create(
            'Widget',
            fields: 'name:string,quantity:int',
        );

        $path = $this->project . '/' . $made->path;

        $output = [];
        $status = 0;
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($path)), $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        require $path;
        self::assertTrue(class_exists($made->className, false));

        $db = Connection::sqlite(':memory:');
        $db->executeSchema(
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, quantity INTEGER NOT NULL)',
        );

        /** @var class-string<Model> $class */
        $class = $made->className;
        $class::useConnection($db);

        // A generated class's fields are not statically known here, so this
        // sets them through Reflection and reads them back through toRow() —
        // itself generated code, and the more meaningful check: it proves the
        // class this run actually wrote, not just the Model base it extends.
        $widget = new $class();
        (new ReflectionProperty($class, 'name'))->setValue($widget, 'Bolt');
        (new ReflectionProperty($class, 'quantity'))->setValue($widget, 4);
        $widget->save();

        self::assertSame(1, $widget->id);
        self::assertTrue($widget->exists());

        $found = $class::find(1);
        self::assertNotNull($found);
        self::assertSame(['name' => 'Bolt', 'quantity' => 4], $found->toRow());

        (new ReflectionProperty($class, 'quantity'))->setValue($found, 10);
        $found->save();

        self::assertSame(['name' => 'Bolt', 'quantity' => 10], $class::findOrFail(1)->toRow());
        self::assertSame(1, $class::count());

        $found->delete();
        self::assertSame(0, $class::count());
    }

    // --- helpers ------------------------------------------------------------

    private function maker(): ModelMaker
    {
        return new ModelMaker($this->project);
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
