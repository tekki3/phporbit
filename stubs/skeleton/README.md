# A phporbit application

## Getting started

You need PHP 8.3 or newer with `pdo_sqlite`, `mbstring`, `fileinfo` and
`sodium`, plus [Composer](https://getcomposer.org/). SQLite is a file-based
database, so no separate database server is needed.

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

```bash
php orbit serve --port=9000 --debug  # local error details and template recompilation
php orbit routes                    # print the compiled route table
php orbit make:class Notes/Repo      # a plain App\ class (--singleton, --scoped)
php orbit make:controller Reports    # a controller (--view adds a template)
php orbit migrate:status             # applied and pending migrations
php orbit migrate:rollback           # reverse the most recent batch

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
| `storage/` | Sessions, compiled templates, SQLite file (git-ignored) |

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
