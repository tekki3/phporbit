<?php

declare(strict_types=1);

return [
    'slug' => 'queries',
    'title' => 'Query builder',
    'summary' => 'A deliberately thin fluent builder — with two safety rules that will stop you at least once.',
    'body' => <<<'HTML'
<p>The builder composes SQL with bound placeholders so common cases read well. It is not an ORM, and it does not try to be: hand-written SQL through <code>Connection::select()</code> is the escape hatch and the better tool for joins, window functions and CTEs.</p>

[[php]]
<?php
$articles = $database->query('articles')
    ->select('id', 'title', 'created_at')
    ->where('published', '=', true)
    ->where('author_id', '=', $authorId)
    ->orderBy('created_at', Direction::Descending)
    ->limit(20)
    ->get();
[[/php]]

<h2>Reading</h2>

[[php]]
<?php
$query = $database->query('articles');

$query->get();                    // list<array<string, scalar|null>>
$query->first();                  // array|null — adds LIMIT 1
$query->value('title');           // a single column from the first row
$query->count();                  // int
$query->exists();                 // bool
[[/php]]

<h2>Conditions</h2>

[[php]]
<?php
$database->query('articles')
    ->where('title', 'LIKE', '%orbit%')
    ->where('views', '>=', 100)
    ->whereNotNull('published_at')
    ->whereIn('status', ['live', 'featured'])
    ->get();
[[/php]]

<p>Conditions combine with <code>AND</code>. Permitted operators: <code>=</code>, <code>!=</code>, <code>&lt;&gt;</code>, <code>&lt;</code>, <code>&lt;=</code>, <code>&gt;</code>, <code>&gt;=</code>, <code>LIKE</code>, <code>NOT LIKE</code>.</p>

<p>An operator is concatenated into the SQL, so it comes from a whitelist rather than being passed through:</p>

[[text]]
InvalidArgumentException: Operator "IS" is not permitted.
  Use one of: =, !=, <>, <, <=, >, >=, LIKE, NOT LIKE.
[[/text]]

<h3>Nulls</h3>

[[php]]
<?php
$database->query('articles')->where('published_at', '=', null);
// InvalidArgumentException: Comparing to null never matches.
//   Use whereNull() or whereNotNull().

$database->query('articles')->whereNull('published_at')->get();
$database->query('articles')->whereNotNull('published_at')->get();
[[/php]]

<p><code>= NULL</code> is never true in SQL. Callers almost always mean <code>IS NULL</code>, so the builder says so instead of quietly returning nothing.</p>

<h3>Empty sets</h3>

[[php]]
<?php
$database->query('articles')->whereIn('id', [])->get();   // []
[[/php]]

<p>An empty <code>whereIn</code> becomes a condition that matches nothing. <code>IN ()</code> is a syntax error, and dropping the condition would turn &ldquo;none of these&rdquo; into &ldquo;all rows&rdquo; — the more dangerous of the two readings.</p>

<h2>Ordering and paging</h2>

[[php]]
<?php
use PhpOrbit\Database\Direction;

$database->query('articles')
    ->orderBy('created_at', Direction::Descending)
    ->orderBy('id')                          // Ascending by default
    ->limit(20)
    ->offset(40)
    ->get();
[[/php]]

<p>Direction is an enum, so <code>"ASC"</code>/<code>"DESC"</code> is never a caller-supplied string concatenated into SQL.</p>

<h2>Writing</h2>

[[php]]
<?php
// Returns the generated id
$id = $database->query('articles')->insert([
    'title' => $title,
    'body' => $body,
    'created_at' => gmdate('c'),
]);

// Returns rows changed
$changed = $database->query('articles')
    ->where('id', '=', $id)
    ->update(['title' => $newTitle]);

$removed = $database->query('articles')
    ->where('id', '=', $id)
    ->delete();
[[/php]]

<h2>The two rules that will stop you</h2>

<h3>1. An unqualified UPDATE or DELETE throws</h3>

[[php]]
<?php
$database->query('articles')->delete();
// UnsafeQuery: This DELETE has no conditions and would affect every row of
//   "articles". Add a where(), or call affectingEveryRow() if that is genuinely
//   the intent.

// When you do mean it, say so:
$database->query('sessions')->affectingEveryRow()->delete();
[[/php]]

<p>A forgotten <code>where()</code> is one of the most expensive mistakes available in a few keystrokes, and it looks identical to a deliberate whole-table change unless the caller states which they meant.</p>

<h3>2. Identifiers are whitelisted</h3>

[[php]]
<?php
$database->query('articles')->orderBy($request->uri->queryParam('sort') ?? 'id');
// InvalidArgumentException: Identifier "title; DROP TABLE users" is not a plain
//   table or column name. Identifiers cannot be bound as parameters, so only
//   letters, digits and underscores are accepted.
[[/php]]

<p>No driver can bind an identifier, so it is always concatenated. A whitelist has one answer; escaping invites the question &ldquo;escaped well enough?&rdquo;</p>

<p>Sorting by user input is still fine — map it yourself:</p>

[[php]]
<?php
$sortable = ['title', 'created_at', 'views'];
$requested = $request->uri->queryParam('sort') ?? 'created_at';
$column = in_array($requested, $sortable, true) ? $requested : 'created_at';

$database->query('articles')->orderBy($column, Direction::Descending)->get();
[[/php]]

<h2>Immutability</h2>

<p>Every method returns a new builder, so a base query is safe to reuse:</p>

[[php]]
<?php
$published = $database->query('articles')->where('published', '=', true);

$recent = $published->orderBy('created_at', Direction::Descending)->limit(5)->get();
$total = $published->count();     // unaffected by the ordering above
[[/php]]

<h2>Inspecting the SQL</h2>

[[php]]
<?php
$query = $database->query('articles')
    ->select('id', 'title')
    ->where('published', '=', true)
    ->limit(10);

$query->toSql();
// SELECT "id", "title" FROM "articles" WHERE "published" = :p0 LIMIT 10

$query->bindings();
// ['p0' => true]
[[/php]]

<p>Useful in tests, and for confirming what a chain actually produced.</p>

<h2>When to drop to SQL</h2>

<p>There are no joins or aggregates beyond <code>count()</code>. That is deliberate — a half-built join API is worse than none, because it looks like it should handle the next case and does not.</p>

[[php]]
<?php
$rows = $database->select(
    'SELECT a.id, a.title, u.name AS author, COUNT(c.id) AS comments
       FROM articles a
       JOIN users u ON u.id = a.author_id
       LEFT JOIN comments c ON c.article_id = a.id
      WHERE a.published = :published
      GROUP BY a.id
      ORDER BY comments DESC
      LIMIT 10',
    ['published' => true],
);
[[/php]]

<p>Still fully parameterised, still typed rows. Reach for it without hesitation.</p>
HTML,
];
