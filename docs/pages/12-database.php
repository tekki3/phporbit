<?php

declare(strict_types=1);

return [
    'slug' => 'database',
    'title' => 'Database',
    'summary' => 'Prepared statements only, typed rows, transactions, and why the connection is a singleton.',
    'body' => <<<'HTML'
<p><code>Connection</code> wraps PDO and only speaks in prepared statements. There is no method that takes a fully-built query with values in it.</p>

<h2>Choosing an engine</h2>

<p>SQLite, MySQL/MariaDB and PostgreSQL are supported. Pick one in <code>.env</code>; nothing in your application changes.</p>

[[ini]]
# sqlite | mysql | pgsql   ("postgres", "postgresql" and "mariadb" also work)
DB_DRIVER=sqlite

# sqlite: a file path, resolved against the project root. ":memory:" works too.
# mysql/pgsql: the database name, and required.
DB_DATABASE=storage/app.sqlite

# Ignored by sqlite. Port defaults to 3306 (mysql) or 5432 (pgsql).
#DB_HOST=127.0.0.1
#DB_PORT=3306
#DB_USERNAME=orbit
#DB_PASSWORD=
#DB_CHARSET=utf8mb4
#DB_SOCKET=/var/run/mysqld/mysqld.sock
[[/ini]]

[[php]]
<?php
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\DatabaseSettings;

// Read and validated once, at boot.
$database = Connection::connect(DatabaseSettings::fromEnvironment($env, $root));

// Or explicitly, without an .env
$database = Connection::connect(DatabaseSettings::postgres('orbit', 'db.internal'));
$database = Connection::connect(DatabaseSettings::mysql('orbit', 'db.internal'));
$database = Connection::sqlite($root . '/storage/app.sqlite');
[[/php]]

<p>Settings are validated at boot, so a missing database name or an out-of-range port stops the application starting rather than surfacing on whichever request queries first:</p>

[[bash]]
$ ./orbit routes
Configuration error: Setting "DB_DRIVER" is not a valid database driver.
Accepted values: sqlite, mysql, pgsql.
[[/bash]]

<h2>What the framework smooths over</h2>

<div class="scroller">
<table>
<thead><tr><th>Difference between engines</th><th>How it is handled</th></tr></thead>
<tbody>
<tr><td>MySQL reads <code>"x"</code> as a string, not an identifier</td><td>Identifiers are quoted with backticks there, double quotes elsewhere.</td></tr>
<tr><td><code>OFFSET</code> without <code>LIMIT</code> is a syntax error on two of the three</td><td>The builder emits each engine's idiom.</td></tr>
<tr><td>Auto-increment keys are spelled three ways</td><td><code>$database-&gt;driver()-&gt;autoIncrementPrimaryKey()</code>, for migrations.</td></tr>
<tr><td>PostgreSQL's <code>lastInsertId()</code> is session-wide</td><td><code>insert()</code> uses <code>RETURNING</code> there.</td></tr>
<tr><td>MySQL truncates over-long values by default</td><td>A strict <code>sql_mode</code> is set per connection.</td></tr>
<tr><td>SQLite ignores foreign keys by default</td><td><code>PRAGMA foreign_keys</code> is set per connection.</td></tr>
</tbody>
</table>
</div>

<div class="warn">
<b>Quoting is per connection, not a server setting</b>
<p>Switching MySQL's <code>sql_mode</code> to <code>ANSI_QUOTES</code> would be the other way to make double quotes work, and a worse one: it changes how every other statement on that connection is parsed, including SQL you wrote yourself.</p>
</div>

<p>Registered as a singleton at boot, then injected wherever it is needed:</p>

[[php]]
<?php
$app->container->singleton(Connection::class, static fn (): Connection => $database);
[[/php]]

<h2>Reading</h2>

[[php]]
<?php
// list<array<string, scalar|null>>
$rows = $database->select(
    'SELECT id, title FROM articles WHERE author_id = :author ORDER BY created_at DESC',
    ['author' => $authorId],
);

// array<string, scalar|null>|null
$article = $database->selectOne(
    'SELECT * FROM articles WHERE id = :id',
    ['id' => $id],
);

// A single scalar
$count = (int) $database->selectValue('SELECT COUNT(*) FROM articles');
[[/php]]

<h2>Writing</h2>

[[php]]
<?php
$changed = $database->execute(
    'UPDATE articles SET title = :title WHERE id = :id',
    ['title' => $title, 'id' => $id],
);

$id = (int) $database->lastInsertId();
[[/php]]

<h2>Schema statements</h2>

[[php]]
<?php
$database->executeSchema('CREATE TABLE articles (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
[[/php]]

<p>Separate from <code>execute()</code> because it binds nothing. Reaching for a method with no parameter binding should be a visible decision, not a convenient shortcut for interpolating a value.</p>

<h2>Why values are always bound</h2>

[[php]]
<?php
// There is no API that accepts this. Deliberately.
$database->select("SELECT * FROM users WHERE email = '{$email}'");   // no such thing

// This is the only way, and a value can never be parsed as SQL.
$database->select('SELECT * FROM users WHERE email = :email', ['email' => $email]);
[[/php]]

<p>Emulated prepares are switched <strong>off</strong>. With emulation on, PDO interpolates values itself before sending the query, which reintroduces exactly the class of bug prepared statements exist to remove.</p>

<div class="warn">
<b>Identifiers are the exception</b>
<p>No driver can bind a table or column name — placeholders only work for values. Anything dynamic there must come from a list your application controls, never from a request. The <a href="queries.html">query builder</a> whitelists identifiers for you.</p>
</div>

<h2>Transactions</h2>

[[php]]
<?php
$articleId = $database->transaction(function (Connection $database) use ($data): int {
    $id = (int) $database->query('articles')->insert($data);

    $database->query('audit')->insert([
        'action' => 'article.created',
        'article_id' => $id,
    ]);

    return $id;   // committed, and returned
});
[[/php]]

<p>The closure's return value is passed through. If it throws, the transaction rolls back and the exception propagates.</p>

[[php]]
<?php
$database->inTransaction();     // bool
$database->rollBackIfOpen();    // for cleanup, not control flow
[[/php]]

<div class="note">
<b>The connection is shared, so transactions are guarded</b>
<p>Under a worker the connection outlives the request. A handler that opens a transaction and throws would hand the next request a connection inside someone else's transaction — their writes would commit or roll back with work they never made. <code>TransactionGuard</code> rolls back anything left open and logs it, so the bug is reported rather than silently corrupting an unrelated request.</p>
<p>Per-request SAPIs hide this entirely: the process dies and the driver cleans up. It only appears under a worker, which is why the cleanup is explicit rather than left to the runtime.</p>
</div>

<h2>Typed rows</h2>

<p>PDO returns untyped arrays. <code>Connection</code> narrows them once, at the driver boundary, so nothing downstream deals in <code>mixed</code>:</p>

[[php]]
<?php
$row = $database->selectOne('SELECT id, title, published FROM articles WHERE id = :id', ['id' => $id]);

// array<string, scalar|null> — narrow to what you need
$id = (int) ($row['id'] ?? 0);
$title = (string) ($row['title'] ?? '');
$published = (bool) ($row['published'] ?? false);
[[/php]]

<h2>Errors</h2>

[[php]]
<?php
use PhpOrbit\Database\QueryFailed;

try {
    $database->execute('INSERT INTO articles (title) VALUES (:title)', ['title' => $title]);
} catch (QueryFailed $e) {
    // "Query failed: UNIQUE constraint failed: articles.title
    //  -- SQL: INSERT INTO articles (title) VALUES (:title)"
}
[[/php]]

<div class="good">
<b>The SQL is in the message; the parameters are not</b>
<p>The SQL is written by you and is what makes a failure diagnosable. The bound parameters are user data, and routinely contain passwords, tokens and personal information that would then travel into logs and bug reports.</p>
</div>

<h2>Bringing your own PDO</h2>

<p>Any PDO instance works, provided it is configured the same way and told which engine it is talking to:</p>

[[php]]
<?php
use PhpOrbit\Database\Driver;

$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$database = new Connection($pdo, Driver::PostgreSql);
[[/php]]

<p>The driver is what the builder consults for quoting and paging, so passing the wrong one produces SQL the server will reject.</p>

<h2>Portable migrations</h2>

<p>Most DDL is accepted by all three. The primary key is not:</p>

[[php]]
<?php
public function up(Connection $database): void
{
    $database->executeSchema(sprintf(
        'CREATE TABLE notes (
            id %s,
            title TEXT NOT NULL,
            created_at TEXT NOT NULL
        )',
        $database->driver()->autoIncrementPrimaryKey(),
    ));
}
[[/php]]

<div class="warn">
<b>Two things to watch when targeting MySQL</b>
<p><strong>Index a <code>VARCHAR</code>, not a <code>TEXT</code>.</strong> MySQL cannot build a unique index on a <code>TEXT</code> column without a prefix length, so columns you intend to index should be declared <code>VARCHAR(n)</code>.</p>
<p><strong>DDL is not transactional.</strong> SQLite and PostgreSQL roll a failed migration back cleanly; MySQL commits implicitly on most schema changes, so a failure there can leave a half-applied migration behind. Keep MySQL migrations small for that reason — <code>$database-&gt;driver()-&gt;supportsTransactionalDdl()</code> reports which you are on.</p>
</div>
HTML,
];
