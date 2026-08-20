<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use RuntimeException;

/**
 * A row, mapped to and from one table — `Note::find(1)`, `$note->save()`.
 *
 * Built entirely on {@see Query}: nothing here composes SQL of its own, and
 * nothing here adds a capability `Query` does not already have. Joins,
 * aggregates beyond `count()`, and relationships are still
 * {@see Connection::select()}'s job — a `Model` is a typed, mutable view of
 * one table's rows, not a query language.
 *
 * The static finders (`find()`, `all()`, `where()`) need a {@see Connection}
 * without a container to resolve one from, so there is exactly one piece of
 * static mutable state here: the shared connection, set once by
 * {@see useConnection()} at boot. That is not the same hazard the rest of
 * this framework forbids static state for. Under a worker, `Connection` is
 * already one object shared by every request in the process — registering it
 * as a container singleton and pointing `Model` at that same instance carries
 * no additional risk, because nothing *per request* is ever stored here.
 * `find()`, `all()` and `where()` build a fresh instance on every call; nothing
 * is cached across requests. What would reintroduce the hazard — memoising a
 * row, or a query result, on a static property — this class never does.
 */
abstract class Model
{
    private static ?Connection $connection = null;

    public ?int $id = null;

    private bool $exists = false;

    /**
     * The table this model reads and writes.
     */
    abstract protected static function table(): string;

    /**
     * Maps every column except the primary key into a new instance.
     * {@see hydrate()} assigns `id` afterwards, so this never has to repeat
     * that parsing.
     *
     * @param array<string, scalar|null> $row
     */
    abstract protected static function fromRow(array $row): static;

    /**
     * The columns {@see save()} writes — `fromRow()`'s inverse. Never
     * includes the primary key; `save()` manages that column itself.
     *
     * @return array<string, scalar|null>
     */
    abstract public function toRow(): array;

    /**
     * Points every model at the connection to use.
     *
     * Called once, in `app/bootstrap.php`, right after `Connection` is built
     * — typically the same line that registers it as a container singleton.
     * A second call throws, the same way registering a container service
     * twice would: this is boot-time wiring, not something a request path
     * should ever reach.
     */
    final public static function useConnection(Connection $database): void
    {
        if (self::$connection !== null) {
            throw new RuntimeException(
                'Model::useConnection() was already called for this process. Call it once, at boot.',
            );
        }

        self::$connection = $database;
    }

    /**
     * Test-only escape hatch so a suite can point models at a fresh
     * connection between cases. Application code should never call this —
     * production boots once, and `useConnection()` runs exactly that once.
     */
    final public static function resetConnectionForTesting(): void
    {
        self::$connection = null;
    }

    private static function connection(): Connection
    {
        if (self::$connection === null) {
            throw new RuntimeException(sprintf(
                'Model::useConnection() was not called. Call it once in app/bootstrap.php, '
                . 'after the Connection is built — %s needs it before %s can run any query.',
                static::class,
                static::class,
            ));
        }

        return self::$connection;
    }

    /**
     * The query builder for this model's table, wrapped so every terminal
     * method returns instances instead of rows.
     *
     * @return ModelQuery<static>
     */
    final public static function query(): ModelQuery
    {
        return ModelQuery::for(static::class, self::connection()->query(static::table()));
    }

    final public static function find(int $id): ?static
    {
        $row = self::connection()->query(static::table())->where('id', '=', $id)->first();

        return $row === null ? null : static::hydrate($row);
    }

    /**
     * Like {@see find()}, but a missing row is a defect to surface rather
     * than a null the caller might forget to check.
     */
    final public static function findOrFail(int $id): static
    {
        return static::find($id) ?? throw new ModelNotFound(sprintf(
            '%s with id %d does not exist.',
            static::class,
            $id,
        ));
    }

    /**
     * Every row, unpaged. Fine for small reference tables; page anything else
     * with `query()->limit()->offset()`.
     *
     * @return list<static>
     */
    final public static function all(): array
    {
        return array_map(static::hydrate(...), self::connection()->query(static::table())->get());
    }

    final public static function count(): int
    {
        return self::connection()->query(static::table())->count();
    }

    /**
     * @return ModelQuery<static>
     */
    final public static function where(
        string $column,
        string $operator,
        string|int|float|bool|null $value,
    ): ModelQuery {
        return static::query()->where($column, $operator, $value);
    }

    /**
     * Turns a raw row into a persisted instance. Public because
     * {@see ModelQuery} hydrates through it too; a subclass never overrides
     * it — {@see fromRow()} is the seam for that.
     *
     * @param array<string, scalar|null> $row
     */
    final public static function hydrate(array $row): static
    {
        $model = static::fromRow($row);
        $model->id = isset($row['id']) ? (int) $row['id'] : null;
        $model->exists = true;

        return $model;
    }

    /**
     * Whether this instance was loaded from, or already saved to, the
     * database — as opposed to `new`, with nothing written yet.
     */
    final public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Inserts a new row, or updates the one this instance was loaded from.
     */
    final public function save(): void
    {
        $database = self::connection();
        $table = static::table();

        if ($this->exists) {
            $database->query($table)->where('id', '=', $this->requireId())->update($this->toRow());

            return;
        }

        $this->id = $database->query($table)->insert($this->toRow());
        $this->exists = true;
    }

    /**
     * Removes the row this instance represents. Refuses an instance that was
     * never saved — there is no row to remove.
     */
    final public function delete(): void
    {
        self::connection()->query(static::table())->where('id', '=', $this->requireId())->delete();
        $this->exists = false;
    }

    private function requireId(): int
    {
        if ($this->id === null) {
            throw new RuntimeException(sprintf('%s has no id — it was never saved.', static::class));
        }

        return $this->id;
    }
}
