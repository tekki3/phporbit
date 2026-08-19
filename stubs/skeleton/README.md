# A phporbit application

## Getting started

```bash
composer install
cp .env.example .env      # already done if you scaffolded this project
./orbit migrate
./orbit serve
```

Then open <http://127.0.0.1:8080>.

## Commands

```bash
./orbit serve --port=9000 --debug   # --debug shows exceptions, recompiles templates
./orbit routes                      # print the compiled route table
./orbit make:class Notes/Repo       # a plain App\ class (--singleton, --scoped)
./orbit make:controller Reports     # a controller (--view adds a template)
./orbit migrate:status              # applied and pending migrations
./orbit migrate:rollback            # reverse the most recent batch

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
- **Long-lived workers** (`./orbit serve`, FrankenPHP) boot once and serve
  thousands of requests in one process. Anything mutable that outlives a
  request is visible to the *next user*.

Write for the worker model and you are correct on both. The framework is built
to make the leak hard: the container freezes after boot, autowiring only
produces per-request instances, and the request scope is closed in a `finally`.

`./orbit serve` deliberately uses the worker model, so a state leak shows up on
your machine rather than in production.

## Deployment

Point the document root at `public/`. Run `./orbit migrate` as a deploy step —
the production entrypoints never touch the schema, because several workers
booting at once would race.

Set `APP_DEBUG=false`, and supply configuration through the environment rather
than a `.env` on the server.
