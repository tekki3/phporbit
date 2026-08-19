<?php

declare(strict_types=1);

return [
    'slug' => 'migrations',
    'title' => 'Migrations',
    'summary' => 'Versioned schema changes, batches, rollbacks, and why down() is not optional.',
    'body' => <<<'HTML'
<p>Migrations live in <code>database/migrations/</code>. Each file returns a <code>Migration</code>:</p>

[[php]]
<?php
// database/migrations/0003_create_articles.php
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;

return new class implements Migration {
    public function up(Connection $database): void
    {
        $database->executeSchema(
            'CREATE TABLE articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                body TEXT NOT NULL,
                author_id INTEGER NOT NULL REFERENCES users(id),
                created_at TEXT NOT NULL
            )',
        );

        $database->executeSchema('CREATE INDEX articles_author ON articles(author_id)');
    }

    public function down(Connection $database): void
    {
        $database->executeSchema('DROP TABLE articles');
    }
};
[[/php]]

<h2>Naming</h2>

<p><code>&lt;digits&gt;_&lt;lowercase_words&gt;.php</code> — for example <code>0003_create_articles.php</code> or <code>20260810143000_add_articles_slug.php</code>.</p>

<p>Ordering is by filename, so two developers adding migrations on separate branches get a deterministic order once merged, rather than one that depends on class discovery. A timestamp prefix makes collisions unlikely; a counter is fine for a small team.</p>

[[text]]
MigrationFailed: Migration file "AddArticles" is unusable: names must look like
  "0001_create_users" — digits, an underscore, then lowercase words
[[/text]]

<h2>Running them</h2>

[[bash]]
$ ./orbit migrate
Applying pending migrations...
  0003_create_articles
  0004_add_articles_slug

$ ./orbit migrate:status
  applied   0001_create_users            batch 1
  applied   0002_create_auth_attempts    batch 1
  applied   0003_create_articles         batch 2
  pending   0004_add_articles_slug

$ ./orbit migrate:rollback
Reversed 0003_create_articles

$ ./orbit migrate:rollback --batches=2
[[/bash]]

<p><code>./orbit serve</code> applies pending migrations first, as a development convenience. <strong>The production entrypoints never touch the schema</strong> — several workers booting at once would race. Run <code>./orbit migrate</code> as a deploy step.</p>

<h2>Batches</h2>

<p>Everything applied by one <code>migrate</code> run shares a batch number. A rollback reverses the most recent batch — one deployment's worth of changes — rather than one arbitrary step.</p>

[[php]]
<?php
$migrator->batches();   // ['0001_create_users' => 1, '0003_create_articles' => 2]
[[/php]]

<p>Every migration in the batch is loaded and checked <em>before</em> anything is undone, so a batch containing a missing file fails without having half-rolled-back the rest.</p>

<h2>Transactions</h2>

<p>Each migration runs inside its own transaction. A failure leaves the schema as it was and the ledger unchanged, and stops the run — later migrations almost certainly assume this one's changes exist.</p>

<div class="warn">
<b>This depends on your engine</b>
<p>SQLite and PostgreSQL support transactional DDL, so the guarantee holds. <strong>MySQL does not</strong>: a failed migration there can leave partial changes behind, and you will need to clean up by hand. Keep MySQL migrations small for that reason.</p>
</div>

<h2>down() is required</h2>

<p>The interface demands it, so writing a migration forces a moment's thought about undoing it. When a change genuinely cannot be reversed, say so:</p>

[[php]]
<?php
use PhpOrbit\Database\IrreversibleMigration;

return new class implements Migration {
    public function up(Connection $database): void
    {
        $database->executeSchema('ALTER TABLE users DROP COLUMN legacy_token');
    }

    public function down(Connection $database): void
    {
        throw IrreversibleMigration::because('the legacy_token values were not retained.');
    }
};
[[/php]]

<p>That records the decision in the migration itself, instead of an empty method that silently &ldquo;succeeds&rdquo; and leaves the schema wrong.</p>

<h2>Data migrations</h2>

<p>Schema and data change together in one transaction:</p>

[[php]]
<?php
public function up(Connection $database): void
{
    $database->executeSchema('ALTER TABLE users ADD COLUMN display_name TEXT');

    foreach ($database->select('SELECT id, email FROM users') as $user) {
        $database->execute(
            'UPDATE users SET display_name = :name WHERE id = :id',
            [
                'name' => explode('@', (string) $user['email'])[0],
                'id' => $user['id'],
            ],
        );
    }
}
[[/php]]

<p>For a large table, prefer a single <code>UPDATE</code> over a loop — it is one statement instead of one per row.</p>

<h2>The ledger</h2>

<p>Applied migrations are recorded in <code>orbit_migrations</code>:</p>

<div class="scroller">
<table>
<thead><tr><th>Column</th><th>Meaning</th></tr></thead>
<tbody>
<tr><td><code>name</code></td><td>Filename without <code>.php</code>. Primary key.</td></tr>
<tr><td><code>batch</code></td><td>Which run applied it.</td></tr>
<tr><td><code>applied_at</code></td><td>UTC timestamp.</td></tr>
</tbody>
</table>
</div>

<p>The table is created on demand, so a fresh database needs no setup step.</p>

<h2>Using the Migrator directly</h2>

[[php]]
<?php
use PhpOrbit\Database\Migrator;

$migrator = new Migrator(
    $database,
    $root . '/database/migrations',
    report: static fn (string $line) => print($line . PHP_EOL),
);

$migrator->pending();     // list<string>
$migrator->applied();     // list<string>
$migrator->available();   // every file, in run order
$migrator->migrate();     // returns what it applied
$migrator->rollback(1);   // returns what it reversed
[[/php]]

<p>This is what makes migrations testable — run them against an in-memory SQLite database in a test and assert the resulting schema.</p>

<h2>Seeding</h2>

<p>Seed data is not a migration. It belongs in <code>./orbit db:seed</code>, which is idempotent and safe to re-run:</p>

[[bash]]
$ ./orbit db:seed
Seeded demo account: demo@example.test / correct-horse-battery
[[/bash]]
HTML,
];
