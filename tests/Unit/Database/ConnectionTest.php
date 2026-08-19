<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Database;

use PhpOrbit\Database\Connection;
use PhpOrbit\Database\QueryFailed;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectionTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = Connection::sqlite(':memory:');
        $this->db->executeSchema('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $this->db->execute('INSERT INTO users (name) VALUES (:name)', ['name' => 'ada']);
        $this->db->execute('INSERT INTO users (name) VALUES (:name)', ['name' => 'grace']);
    }

    public function test_it_selects_rows(): void
    {
        $rows = $this->db->select('SELECT name FROM users ORDER BY name');

        self::assertSame([['name' => 'ada'], ['name' => 'grace']], $rows);
    }

    public function test_it_binds_parameters(): void
    {
        $row = $this->db->selectOne('SELECT name FROM users WHERE name = :name', ['name' => 'ada']);

        self::assertSame(['name' => 'ada'], $row);
    }

    public function test_select_one_returns_null_when_nothing_matches(): void
    {
        self::assertNull($this->db->selectOne('SELECT name FROM users WHERE name = :n', ['n' => 'nobody']));
    }

    public function test_select_value_returns_a_scalar(): void
    {
        self::assertSame(2, (int) $this->db->selectValue('SELECT COUNT(*) FROM users'));
    }

    /**
     * The central guarantee: a value containing SQL is data, never syntax.
     *
     * Interpolated, this payload would return every row.
     */
    public function test_an_injection_payload_is_treated_as_data(): void
    {
        $rows = $this->db->select(
            'SELECT name FROM users WHERE name = :name',
            ['name' => "' OR '1'='1"],
        );

        self::assertSame([], $rows);
    }

    public function test_a_payload_that_would_drop_a_table_does_not(): void
    {
        $this->db->execute('INSERT INTO users (name) VALUES (:name)', [
            'name' => "x'); DROP TABLE users; --",
        ]);

        self::assertSame(3, (int) $this->db->selectValue('SELECT COUNT(*) FROM users'));
    }

    public function test_execute_reports_affected_rows(): void
    {
        self::assertSame(1, $this->db->execute('DELETE FROM users WHERE name = :n', ['n' => 'ada']));
        self::assertSame(0, $this->db->execute('DELETE FROM users WHERE name = :n', ['n' => 'ada']));
    }

    public function test_a_broken_query_reports_the_sql_but_not_the_values(): void
    {
        try {
            $this->db->select('SELECT * FROM missing_table WHERE secret = :secret', ['secret' => 'hunter2']);

            self::fail('the query should have failed');
        } catch (QueryFailed $e) {
            self::assertStringContainsString('missing_table', $e->getMessage());
            self::assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function test_a_transaction_commits_on_success(): void
    {
        $this->db->transaction(function (Connection $db): void {
            $db->execute('INSERT INTO users (name) VALUES (:n)', ['n' => 'hopper']);
        });

        self::assertSame(3, (int) $this->db->selectValue('SELECT COUNT(*) FROM users'));
        self::assertFalse($this->db->inTransaction());
    }

    public function test_a_transaction_rolls_back_when_the_work_throws(): void
    {
        try {
            $this->db->transaction(function (Connection $db): void {
                $db->execute('INSERT INTO users (name) VALUES (:n)', ['n' => 'hopper']);

                throw new RuntimeException('changed my mind');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(2, (int) $this->db->selectValue('SELECT COUNT(*) FROM users'));
        self::assertFalse($this->db->inTransaction());
    }

    public function test_roll_back_if_open_reports_whether_it_acted(): void
    {
        self::assertFalse($this->db->rollBackIfOpen());
    }

    /**
     * SQLite ignores foreign keys unless the pragma is set per connection.
     */
    public function test_foreign_keys_are_enforced(): void
    {
        $this->db->executeSchema(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id))',
        );

        $this->expectException(QueryFailed::class);

        $this->db->execute('INSERT INTO posts (user_id) VALUES (:id)', ['id' => 9999]);
    }
}
