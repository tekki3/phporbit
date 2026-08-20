<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Database;

use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Direction;
use PhpOrbit\Database\Model;
use PhpOrbit\Database\ModelNotFound;
use PhpOrbit\Tests\Support\Widget;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModelTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = Connection::sqlite(':memory:');
        $this->db->executeSchema(
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, quantity INTEGER NOT NULL)',
        );

        // A fresh test process would only ever call this once, at boot; a
        // shared PHPUnit process runs many test classes, so each one resets
        // rather than fighting over the same static connection.
        Model::resetConnectionForTesting();
        Widget::useConnection($this->db);
    }

    protected function tearDown(): void
    {
        Model::resetConnectionForTesting();
    }

    // --- wiring ----------------------------------------------------------

    public function test_a_query_before_useconnection_explains_what_is_missing(): void
    {
        Model::resetConnectionForTesting();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/useConnection/');

        Widget::all();
    }

    public function test_useconnection_refuses_a_second_call(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already called/');

        Widget::useConnection($this->db);
    }

    // --- save() : insert and update ---------------------------------------

    public function test_save_inserts_a_new_row_and_assigns_the_id(): void
    {
        $widget = new Widget();
        $widget->name = 'Sprocket';
        $widget->quantity = 3;

        self::assertFalse($widget->exists());
        self::assertNull($widget->id);

        $widget->save();

        self::assertTrue($widget->exists());
        self::assertSame(1, $widget->id);
        self::assertSame(1, $this->db->query('widgets')->count());
    }

    public function test_save_on_an_existing_instance_updates_in_place(): void
    {
        $widget = new Widget();
        $widget->name = 'Sprocket';
        $widget->quantity = 3;
        $widget->save();

        $widget->quantity = 9;
        $widget->save();

        self::assertSame(1, $this->db->query('widgets')->count());
        self::assertSame('Sprocket', $this->db->query('widgets')->value('name'));
        self::assertSame(9, $this->db->query('widgets')->value('quantity'));
    }

    // --- find() / findOrFail() ---------------------------------------------

    public function test_find_returns_null_for_a_missing_row(): void
    {
        self::assertNull(Widget::find(999));
    }

    public function test_find_hydrates_a_matching_row(): void
    {
        $this->insert('Bolt', 5);

        $widget = Widget::find(1);

        self::assertNotNull($widget);
        self::assertTrue($widget->exists());
        self::assertSame(1, $widget->id);
        self::assertSame('Bolt', $widget->name);
        self::assertSame(5, $widget->quantity);
    }

    public function test_findorfail_throws_a_named_exception_when_nothing_matches(): void
    {
        $this->expectException(ModelNotFound::class);
        $this->expectExceptionMessageMatches('/999/');

        Widget::findOrFail(999);
    }

    public function test_findorfail_returns_the_row_when_it_exists(): void
    {
        $this->insert('Bolt', 5);

        self::assertSame('Bolt', Widget::findOrFail(1)->name);
    }

    // --- all() / count() ----------------------------------------------------

    public function test_all_returns_every_row_hydrated(): void
    {
        $this->insert('Bolt', 5);
        $this->insert('Nut', 2);

        $widgets = Widget::all();

        self::assertCount(2, $widgets);
        self::assertSame(['Bolt', 'Nut'], array_map(static fn (Widget $w): string => $w->name, $widgets));
    }

    public function test_count(): void
    {
        self::assertSame(0, Widget::count());

        $this->insert('Bolt', 5);

        self::assertSame(1, Widget::count());
    }

    // --- where() / query() chains -------------------------------------------

    public function test_where_hydrates_matching_rows(): void
    {
        $this->insert('Bolt', 5);
        $this->insert('Nut', 2);

        $matches = Widget::where('name', '=', 'Nut')->get();

        self::assertCount(1, $matches);
        self::assertInstanceOf(Widget::class, $matches[0]);
        self::assertSame(2, $matches[0]->quantity);
    }

    public function test_query_chains_like_the_query_builder(): void
    {
        $this->insert('Bolt', 5);
        $this->insert('Nut', 2);
        $this->insert('Washer', 8);

        $first = Widget::query()
            ->where('quantity', '>', 1)
            ->orderBy('quantity', Direction::Descending)
            ->limit(1)
            ->first();

        self::assertNotNull($first);
        self::assertSame('Washer', $first->name);
    }

    public function test_query_first_returns_null_when_nothing_matches(): void
    {
        self::assertNull(Widget::query()->where('name', '=', 'nope')->first());
    }

    public function test_query_bulk_update_and_delete_do_not_hydrate(): void
    {
        $this->insert('Bolt', 5);
        $this->insert('Nut', 2);

        $changed = Widget::query()->where('quantity', '<', 3)->update(['quantity' => 0]);
        self::assertSame(1, $changed);
        self::assertSame(0, Widget::findOrFail(2)->quantity);

        $removed = Widget::query()->where('quantity', '=', 0)->delete();
        self::assertSame(1, $removed);
        self::assertSame(1, Widget::count());
    }

    // --- delete() ------------------------------------------------------------

    public function test_delete_removes_the_row_and_flips_exists(): void
    {
        $this->insert('Bolt', 5);
        $widget = Widget::findOrFail(1);

        $widget->delete();

        self::assertFalse($widget->exists());
        self::assertNull(Widget::find(1));
    }

    public function test_delete_on_an_unsaved_instance_refuses(): void
    {
        $widget = new Widget();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/never saved/');

        $widget->delete();
    }

    private function insert(string $name, int $quantity): void
    {
        $this->db->query('widgets')->insert(['name' => $name, 'quantity' => $quantity]);
    }
}
