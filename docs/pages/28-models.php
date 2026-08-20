<?php

declare(strict_types=1);

return [
    'slug' => 'models',
    'title' => 'Models',
    'summary' => 'A typed, mutable view of one table — Note::find(1), $note->save() — built entirely on the query builder.',
    'body' => <<<'HTML'
<p><code>PhpOrbit\Database\Model</code> maps one table to one class: typed properties, static finders, and <code>save()</code>/<code>delete()</code> on the instance. It adds no SQL capability the <a href="queries.html">query builder</a> does not already have — every method here is built on <code>Query</code>, the same builder <code>$database->query('table')</code> returns. Joins, aggregates beyond <code>count()</code>, and relationships are still <code>Connection::select()</code>'s job.</p>

[[bash]]
./orbit make:model Note --fields=title:string,body:string,views:int
[[/bash]]

[[php]]
<?php

namespace App\Models;

use PhpOrbit\Database\Model;

final class Note extends Model
{
    public string $title = '';
    public string $body = '';
    public int $views = 0;

    protected static function table(): string
    {
        return 'notes';
    }

    protected static function fromRow(array $row): static
    {
        $model = new static();
        $model->title = (string) ($row['title'] ?? '');
        $model->body = (string) ($row['body'] ?? '');
        $model->views = (int) ($row['views'] ?? 0);

        return $model;
    }

    public function toRow(): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'views' => $this->views];
    }
}
[[/php]]

<p><code>fromRow()</code> and <code>toRow()</code> are the only two methods a model writes by hand — the same &ldquo;narrow once, at the boundary&rdquo; shape as <code>Connection::narrowRow()</code>, just per table instead of per driver. <code>--fields</code> writes both from a <code>name:type</code> list; edit them freely afterwards.</p>

<h2>Wiring it up</h2>

<p>Static finders need a connection without a container to resolve one from. Point every model at one, once, in <code>app/bootstrap.php</code> — right where <code>Connection</code> is already registered as a singleton:</p>

[[php]]
<?php
$database = Connection::connect(DatabaseSettings::fromEnvironment($env, $root));

Model::useConnection($database);

$app->container->singleton(Connection::class, static fn (): Connection => $database);
[[/php]]

<p>A second call throws — the same shape as registering a container service twice. This is boot-time wiring, not something a request should ever reach.</p>

<h2>Reading</h2>

[[php]]
<?php
Note::find(1);                              // ?Note
Note::findOrFail(1);                        // Note, or throws ModelNotFound
Note::all();                                // list<Note>
Note::count();                              // int

Note::where('title', '=', 'Hello')->get();  // list<Note>
Note::where('views', '>', 100)->first();    // ?Note

Note::query()
    ->where('views', '>', 10)
    ->orderBy('views', Direction::Descending)
    ->limit(5)
    ->get();
[[/php]]

<p><code>where()</code> and <code>query()</code> return a <code>ModelQuery</code> — the same fluent chain as <code>Query</code> (<code>whereNull()</code>, <code>whereIn()</code>, <code>orderBy()</code>, <code>limit()</code>, <code>offset()</code>, <code>affectingEveryRow()</code>), except <code>get()</code> and <code>first()</code> hydrate instances instead of handing back arrays. <code>update()</code> and <code>delete()</code> on a chain stay row-based — a bulk write over many rows has no single instance to return.</p>

<h2>Writing</h2>

[[php]]
<?php
$note = new Note();
$note->title = 'Hello';
$note->body = 'World';
$note->save();          // INSERT — $note->id is now set

$note->title = 'Updated';
$note->save();          // UPDATE, keyed on id — exists() is now true

$note->delete();        // DELETE, keyed on id
[[/php]]

<p><code>exists()</code> tells the two cases apart: a freshly-<code>new</code>d instance inserts on <code>save()</code>, one loaded through <code>find()</code>/<code>all()</code>/<code>where()</code> updates. Calling <code>delete()</code> on an instance that was never saved throws — there is no row to remove.</p>

<h2>Worker safety</h2>

<p>A model's one static is the shared connection set by <code>useConnection()</code>. That is not the hazard <a href="architecture.html">the rest of this framework</a> forbids static state for: under a worker, <code>Connection</code> is already one object shared by every request in the process, and registering it as a container singleton carries the same sharing. Pointing <code>Model</code> at that same instance adds no new risk, because nothing <em>per request</em> is ever cached here &mdash; <code>find()</code>, <code>all()</code> and every <code>ModelQuery</code> terminal method build a fresh instance on every call. What would reintroduce the hazard &mdash; memoising a row, or a query result, on a static property &mdash; this class never does.</p>

<h2>What a model is not</h2>

<p>There are no relationships, no eager loading, no query scopes, and no events. A model past one table's worth of typed columns is <code>Connection::select()</code>'s job, the same as with the query builder:</p>

[[php]]
<?php
$rows = $database->select(
    'SELECT n.*, COUNT(c.id) AS comment_count
       FROM notes n
       LEFT JOIN comments c ON c.note_id = n.id
      GROUP BY n.id',
);
[[/php]]
HTML,
];
