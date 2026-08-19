<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Database;

use InvalidArgumentException;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Direction;
use PhpOrbit\Database\UnsafeQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = Connection::sqlite(':memory:');
        $this->db->executeSchema(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, age INTEGER, city TEXT NULL)',
        );

        foreach ([['ada', 36, 'London'], ['grace', 45, 'New York'], ['alan', 41, null]] as [$name, $age, $city]) {
            $this->db->query('users')->insert(['name' => $name, 'age' => $age, 'city' => $city]);
        }
    }

    public function test_it_selects_all_rows(): void
    {
        self::assertCount(3, $this->db->query('users')->get());
    }

    public function test_it_filters_and_orders(): void
    {
        $rows = $this->db->query('users')
            ->select('name')
            ->where('age', '>', 38)
            ->orderBy('name', Direction::Descending)
            ->get();

        self::assertSame([['name' => 'grace'], ['name' => 'alan']], $rows);
    }

    public function test_values_become_placeholders_not_literals(): void
    {
        $query = $this->db->query('users')->where('name', '=', "' OR 1=1 --");

        self::assertStringContainsString(':p0', $query->toSql());
        self::assertStringNotContainsString('OR 1=1', $query->toSql());
        self::assertSame(["p0" => "' OR 1=1 --"], $query->bindings());
        self::assertSame([], $query->get());
    }

    public function test_identifiers_are_quoted(): void
    {
        self::assertSame(
            'SELECT "name" FROM "users"',
            $this->db->query('users')->select('name')->toSql(),
        );
    }

    /**
     * Identifiers cannot be bound by any driver, so they are whitelisted
     * rather than escaped.
     */
    #[DataProvider('hostileIdentifiers')]
    public function test_it_rejects_a_hostile_identifier(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->db->query('users')->select($identifier)->toSql();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileIdentifiers(): iterable
    {
        yield 'statement terminator' => ['name; DROP TABLE users'];
        yield 'quote' => ['name" --'];
        yield 'subquery' => ['(SELECT 1)'];
        yield 'space' => ['name AS x'];
        yield 'too many parts' => ['db.schema.table.column'];
    }

    public function test_it_rejects_an_operator_outside_the_whitelist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->db->query('users')->where('name', 'IS NOT', 'ada');
    }

    /**
     * `= NULL` silently matches nothing in SQL, so it is refused rather than
     * quietly returning an empty result.
     */
    public function test_comparing_to_null_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/whereNull/');

        $this->db->query('users')->where('city', '=', null);
    }

    public function test_null_checks(): void
    {
        self::assertSame(1, $this->db->query('users')->whereNull('city')->count());
        self::assertSame(2, $this->db->query('users')->whereNotNull('city')->count());
    }

    public function test_where_in(): void
    {
        $rows = $this->db->query('users')->whereIn('name', ['ada', 'alan'])->get();

        self::assertCount(2, $rows);
    }

    /**
     * `IN ()` is a syntax error, and dropping the clause would turn "none of
     * these" into "all rows" — the more dangerous reading.
     */
    public function test_where_in_with_no_values_matches_nothing(): void
    {
        self::assertSame([], $this->db->query('users')->whereIn('name', [])->get());
    }

    public function test_first_and_value(): void
    {
        $row = $this->db->query('users')->where('name', '=', 'ada')->first();

        self::assertSame('ada', $row['name'] ?? null);
        self::assertSame(36, (int) $this->db->query('users')->where('name', '=', 'ada')->value('age'));
        self::assertNull($this->db->query('users')->where('name', '=', 'nobody')->first());
    }

    public function test_exists_and_count(): void
    {
        self::assertTrue($this->db->query('users')->where('name', '=', 'ada')->exists());
        self::assertFalse($this->db->query('users')->where('name', '=', 'nobody')->exists());
        self::assertSame(3, $this->db->query('users')->count());
    }

    /**
     * Ordering and paging make a count fail on some engines and change nothing
     * on the rest, so they are dropped.
     */
    public function test_count_ignores_ordering_and_paging(): void
    {
        self::assertSame(3, $this->db->query('users')->orderBy('name')->limit(1)->count());
    }

    public function test_limit_and_offset(): void
    {
        $rows = $this->db->query('users')->select('name')->orderBy('name')->limit(1)->offset(1)->get();

        self::assertSame([['name' => 'alan']], $rows);
    }

    public function test_insert_returns_the_new_id(): void
    {
        $id = $this->db->query('users')->insert(['name' => 'hopper', 'age' => 85]);

        self::assertGreaterThan(0, $id);
        self::assertSame('hopper', $this->db->query('users')->where('id', '=', $id)->value('name'));
    }

    public function test_update_returns_affected_rows(): void
    {
        $changed = $this->db->query('users')->where('name', '=', 'ada')->update(['age' => 37]);

        self::assertSame(1, $changed);
        self::assertSame(37, (int) $this->db->query('users')->where('name', '=', 'ada')->value('age'));
    }

    public function test_delete_returns_affected_rows(): void
    {
        self::assertSame(1, $this->db->query('users')->where('name', '=', 'ada')->delete());
        self::assertSame(2, $this->db->query('users')->count());
    }

    /**
     * A forgotten where() on an UPDATE or DELETE is one of the most expensive
     * mistakes available in a few keystrokes.
     */
    public function test_an_unqualified_delete_is_refused(): void
    {
        $this->expectException(UnsafeQuery::class);

        $this->db->query('users')->delete();
    }

    public function test_an_unqualified_update_is_refused(): void
    {
        $this->expectException(UnsafeQuery::class);

        $this->db->query('users')->update(['age' => 0]);
    }

    public function test_a_whole_table_write_is_allowed_when_acknowledged(): void
    {
        self::assertSame(3, $this->db->query('users')->affectingEveryRow()->delete());
    }

    /**
     * Each builder method returns a copy, so a partially-built query can be
     * reused as the base for several others.
     */
    public function test_the_builder_is_immutable(): void
    {
        $base = $this->db->query('users');
        $filtered = $base->where('name', '=', 'ada');

        self::assertSame(3, $base->count());
        self::assertSame(1, $filtered->count());
    }
}
