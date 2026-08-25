# phporbit

A PHP framework built around one rule: **the same application runs unchanged
on four different deployment targets**, from its own built-in server to
Apache. You write your app once — phporbit handles the differences.

| Target | What it is |
| --- | --- |
| `./orbit serve` | phporbit's own server, great for local development |
| FrankenPHP (worker mode) | long-lived process, for production |
| nginx + PHP-FPM | classic per-request hosting |
| Apache (mod_php or PHP-FPM) | classic per-request hosting |

Secure defaults are built in: escaped output, CSRF protection, safe sessions,
Argon2id password hashing, and a query builder that won't let you write an
`UPDATE`/`DELETE` with no `WHERE` clause by accident.

## Requirements

- **PHP 8.3 or newer**, with `pdo_sqlite`, `mbstring` and `fileinfo`
- **[Composer](https://getcomposer.org)**
- `sockets` and `pcntl` for the built-in server (present in most CLI builds)

## Running this repository

This is the framework's own source, and it doubles as a working demo app.
To get it running locally:

```bash
composer install
cp .env.example .env
./orbit migrate
./orbit db:seed        # creates a demo account
./orbit serve
```

Visit `http://127.0.0.1:8080` — you'll land on a live self-check page that
verifies routing, sessions, CSRF, the database, uploads, and more are all
working.

## Starting your own project

phporbit projects are scaffolded with the `orbit` CLI, not `composer create-project`.
Install it globally once:

```bash
composer global require phporbit/phporbit
export PATH="$(composer global config bin-dir --absolute):$PATH"   # add to your shell profile
```

Then create a project:

```bash
orbit new my-app          # a minimal blank project
orbit new my-app --demo   # ...or one with a working demo app to learn from

cd my-app
composer install
./orbit migrate
./orbit serve
```

From here on, always run `./orbit` (not the global `orbit`) — it's the copy
inside your project, and every command besides `orbit new` and
`orbit key:generate` needs to resolve paths against a real project.

## Everyday commands

```bash
./orbit serve                              # run the app locally (applies pending migrations first)
./orbit ui                                 # admin dashboard at 127.0.0.1:8081 — migrations, mail, routes, storage
./orbit routes                             # print every registered route
./orbit migrate                            # run pending database migrations
./orbit migrate:status                     # see what's applied and what's pending
./orbit make:controller Blog/Posts --view  # generate a controller (+ template)
./orbit make:model Post --fields=title:string,body:string
./orbit make:migration create_posts_table
./orbit make:form Contact --controllers
```

Run `./orbit` with no arguments, or `./orbit help`, to see everything it can
generate and manage — controllers, models, migrations, forms, middleware, and
plain classes are all one command away.

## Running the tests

```bash
composer test     # full test suite
composer stan      # static analysis (PHPStan, max level)
```

## Learning more

- The built-in docs (`docs/index.html`, or `/docs` on a running app) cover
  every part of the framework in depth: routing, the database layer,
  authentication, mail, forms, encryption, and deployment to each of the
  four targets
- [CLAUDE.md](CLAUDE.md) explains the design decisions behind phporbit itself,
  if you're curious why things work the way they do

## License

MIT — see [LICENSE](LICENSE).
