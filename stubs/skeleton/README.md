# A phporbit application

## Getting started

You need PHP 8.3 or newer with `pdo_sqlite`, `mbstring`, `fileinfo` and
`sodium`, plus [Composer](https://getcomposer.org/). SQLite is a file-based
database, so no separate database server is needed.

For MySQL/MariaDB, PostgreSQL or Microsoft SQL Server, install the matching
PDO driver (`pdo_mysql`, `pdo_pgsql` or `pdo_sqlsrv`) and configure `.env`.

Open a terminal in this folder (the one containing `composer.json`).
Run these commands one at a time:

```bash
composer install
php orbit migrate
php orbit serve --debug
```

Then open <http://127.0.0.1:8080>. A blank project shows **It works**; a demo
project shows a self-check page. `composer install` downloads dependencies,
`migrate` applies database table changes, and `serve` starts the local server.
A blank project has no application tables yet; that is normal.

`php orbit` works in Windows PowerShell, macOS and Linux. `./orbit` in other
examples is the macOS/Linux shorthand for the same script.

The project generator already created `.env` and a unique `APP_KEY`.
Do not overwrite it with `.env.example`, share its secrets or commit it to Git.

Leave the server terminal running and open a second terminal in this folder
for other commands. Press **Ctrl+C** in the first terminal to stop the server.
**Restart after changing routes, controllers, other PHP classes or `.env`.**
Template-only changes appear on refresh when debug mode is enabled. Never
enable debug mode on a public server.

For a project generated with `--demo`, run `php orbit db:seed` before starting
the server to create the local demo login:
`demo@example.test` / `correct-horse-battery`.

## Seed data

Run `php orbit migrate` before `php orbit db:seed`. Seeders are PHP files in
`database/seeders/`, each returning a `PhpOrbit\Database\Seeder` instance with
`run(PhpOrbit\Database\Connection $database): void`.

Create a starter with `php orbit make:seed 0002_categories`. Names use lowercase
letters, digits and underscores and become filenames exactly; a numeric prefix
controls the run order. Existing files require `--force` to overwrite.

Run every seeder in filename order with `php orbit db:seed`, or select one by
its filename without `.php`, such as `php orbit db:seed 0002_categories`.
Seeders run every time, so check for existing data before inserting it. Each
seeder runs in its own transaction: a failure rolls back that seeder and stops
the run, but earlier seeders stay committed.
Keep file-level code free of side effects and use seeders for data, not schema
changes. There is no seeding ledger or automatic run when the application boots.
An empty or absent seed directory prints `Nothing to seed.`.

## Caching

Inject `PhpOrbit\Cache\Cache` into a controller or service. Use
`$cache->remember('key', fn (): array => ['result' => 'value'], 300)` to compute
on a miss; `get`, `set`, `has`, `delete` and `clear` are also available.
Values are JSON-compatible scalars, null and nested arrays, not PHP objects.
Include the user or tenant id in keys for private data.

`CACHE_DRIVER=file` (also `storage`) is the default and uses
`storage/cache/data`. Select `redis` or `memcached` and install the matching
PHP extension for a remote cache. Set `CACHE_HOST`, `CACHE_PORT` and a unique
`CACHE_PREFIX`; use a stable prefix across all instances of one application.
Restart workers after changing configuration.

TTLs are seconds; omitted uses `CACHE_TTL=300`, and zero never expires.
Backend failures throw `PhpOrbit\Cache\CacheFailed`, never silently fall back.
`php orbit cache:clear` clears this application's configured cache namespace;
`php orbit storage:clear` still clears compiled templates only.

## Your first page

A **route** maps a URL to a **controller** (the PHP class handling the request).
A **template** supplies the page's HTML.

In your second terminal:

```bash
php orbit make:controller About --view
```

This creates `app/src/Controllers/AboutController.php` and
`app/templates/about.orbit.php`. It does **not** edit your routes.
In `app/routes.php`, add this import beside the existing `use` lines,
above `return static function`:

```php
use App\Controllers\AboutController;
```

Inside that file's existing route function, before its closing `};`, add:

```php
$routes->get('/about', AboutController::class, 'about');
```

Keep the existing routes. Restart the server and open
<http://127.0.0.1:8080/about>. You should see **About**.
Edit `app/templates/about.orbit.php` to change the page content. Use
`{{ $value }}` when displaying data: it escapes HTML automatically.

For a longer walkthrough and troubleshooting, open the framework's
[getting-started guide](vendor/phporbit/phporbit/docs/getting-started.html)
in your browser. Documentation lives in the dependency; this application
does not include a `/docs` route.

## If something goes wrong

- **Cannot open `orbit`:** check that your terminal is in this project folder.
- **Missing autoloader:** run `composer install` and resolve any reported errors.
- **Could not find driver:** use `php --ini` to locate your PHP configuration,
  enable `pdo_sqlite`, check `php -m`, and restart the server.
- **Port already in use:** run `php orbit serve --port=9000 --debug` and open
  <http://127.0.0.1:9000>.
- **404 after adding a page:** check `php orbit routes`, verify the URL,
  and restart the server.
- **500 error:** use `--debug` locally and read the response and server terminal
  for details. Check imports, file names and semicolons.

## Commands

### Optional authentication

In a blank project, run `php orbit make:auth` to generate login, registration
with mandatory email verification, MFA/recovery codes, profile pages and
administrator user management. Existing files are never overwritten.

Configure `APP_URL` and SMTP (`MAIL_DRIVER=smtp`) in `.env`, run
`php orbit migrate`, restart the server and visit `/register`. After verifying
your email, appoint yourself from a trusted terminal:

```bash
php orbit auth:admin you@example.com
```

Sign in again, then visit `/account`, `/account/security` and, as an
administrator, `/account/users`. Members cannot grant themselves privileges.
All primary sign-in methods still require MFA when enabled. Google/Entra
remain optional. Registration/reset/login emails bypass the readable mail log.

New blank projects load `app/auth.php` automatically. Older projects need the
boot callback line printed by the generator. Demo projects already have
authentication and cannot be overlaid. Edit the generated files in
`app/src/AuthKit/` and `app/templates/auth-kit/` directly; run the generated
tests with `composer test`. See the framework's
`vendor/phporbit/phporbit/docs/advanced-auth.html` for configuration and limits.

```bash
php orbit serve --port=9000 --debug  # local error details and template recompilation
php orbit routes                    # print the compiled route table
php orbit make:class Notes/Repo      # a plain App\ class (--singleton, --scoped)
php orbit make:controller Reports    # a controller (--view adds a template)
php orbit migrate:status             # applied and pending migrations
php orbit migrate:rollback           # reverse the most recent batch
php orbit cache:clear                # clear configured application data cache

composer test                       # PHPUnit
composer stan                       # PHPStan at max level
```

## Layout

| Path | What lives there |
| --- | --- |
| `app/routes.php` | Route declarations |
| `app/bootstrap.php` | Boot phase: services, middleware, configuration |
| `app/src/` | Your classes, under the `App\` namespace |
| `app/templates/` | `*.orbit.php` templates |
| `database/migrations/` | Schema changes |
| `public/` | Document root: front controller and assets |
| `storage/` | Sessions, application cache, compiled templates, SQLite file (git-ignored) |

## The one thing to know

phporbit runs the same application on four targets, and they split into two
process models:

- **Per-request** (Apache, nginx+FPM) tears the process down after every
  response. Nothing survives, so nothing can leak.
- **Long-lived workers** (`php orbit serve`, FrankenPHP) boot once and serve
  thousands of requests in one process. Anything mutable that outlives a
  request is visible to the *next user*.

Write for the worker model and you are correct on both. The framework is built
to make the leak hard: the container freezes after boot, autowiring only
produces per-request instances, and the request scope is closed in a `finally`.

`php orbit serve` deliberately uses the worker model, so a state leak shows up on
your machine rather than in production.

## Deployment

Point the document root at `public/`. Run `php orbit migrate` as a deploy step —
the production entrypoints never touch the schema, because several workers
booting at once would race.

Set `APP_DEBUG=false`, and supply configuration through the environment rather
than a `.env` on the server.
