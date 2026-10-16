# phporbit

phporbit is a PHP framework for building web applications. It connects URLs to
your PHP code, renders HTML pages, and provides tools for forms, databases,
sessions and authentication.

**New to phporbit? Start with the [step-by-step getting-started guide](docs/getting-started.html).**
It explains the commands and takes you from installation to a page that greets
a visitor by name. Open the HTML file in your browser after downloading this
repository; no documentation server or build step is needed.

## Requirements

- **PHP 8.3 or newer**, with `pdo_sqlite`, `mbstring`, `fileinfo` and `sodium`
- **[Composer](https://getcomposer.org/)**, PHP's package manager
- A terminal, text editor and browser

Check your tools with `php --version`, `composer --version` and `php -m`
(which lists enabled extensions). The default database is SQLite: it stores
data in a file, so you do not need to install a database server.

Commands below use `php orbit`, which works in Windows PowerShell, macOS and
Linux. Other documentation uses `./orbit` as the macOS/Linux shorthand.
The built-in server uses PHP streams; `sockets` and `pcntl` are not required.

## Starting your own project

Run each command in your terminal, one at a time. Install the project generator
once:

```bash
composer global require phporbit/phporbit
composer global config bin-dir --absolute
```

The second command prints the directory containing the global `orbit` command.
If `orbit` is not found, add that directory to your user's `PATH` and open a new
terminal. On Windows, edit your account's **Path** environment variable. On
macOS/Linux, use this line and add it to your shell profile:

```bash
export PATH="$(composer global config bin-dir --absolute):$PATH"
```

Create a blank application in a new folder:

```bash
orbit new my-app
cd my-app
composer install
php orbit migrate
php orbit serve --debug
```

Open <http://127.0.0.1:8080>. You should see **It works**.
Leave the server terminal running; use a second terminal in `my-app` for other
commands. Press **Ctrl+C** in the server terminal to stop it.

- `orbit new` writes the starter files, `.env` configuration and a unique
  application key. Do not overwrite that `.env` with the example file.
- `composer install` downloads dependencies into `vendor/`.
- `migrate` applies database table changes. A blank project has no application
  tables yet; that is expected.
- `serve --debug` starts the local development server with error details.
  **Never enable debug mode on a public deployment.**

After creating the project, use its local `php orbit` script, not the global
`orbit`, for application commands. Run it from the folder containing
`composer.json`. phporbit uses `orbit new`, not `composer create-project`.

**Want a working demo instead?** Use `orbit new my-demo --demo`, then run the
same installation commands inside `my-demo`, adding `php orbit db:seed` after
`migrate`. The demo includes sign-in, notes, uploads and a self-check page.
Its local demo login is `demo@example.test` / `correct-horse-battery`.

## Build your first page

The [getting-started tutorial](docs/getting-started.html#first-route) walks
through complete, copyable examples:

1. Add a **route** in `app/routes.php`: the rule connecting a URL to PHP code.
2. Generate a **controller** with `php orbit make:controller Greet --view`:
   the class that handles a request.
3. Register the controller's import and route manually; the generator prints
   them but does not edit your routes.
4. Edit the **template** in `app/templates/greet.orbit.php`: the HTML displayed
   in the browser.

**Restart the server after changing routes, controllers, other PHP classes or
`.env`.** It keeps the application in memory; refreshing the browser does not
reload that code. Template-only edits appear on refresh when debug is enabled.

## Where to put your code

| Path | Purpose |
| --- | --- |
| `app/routes.php` | URL-to-handler rules |
| `app/src/Controllers/` | Request-handling classes |
| `app/templates/` | HTML templates, ending in `.orbit.php` |
| `app/bootstrap.php` | Startup configuration, services and middleware |
| `database/migrations/` | Versioned database table changes |
| `public/` | Browser-accessible assets and the production entrypoint |
| `.env` | Local settings and secrets; never commit this file |
| `storage/` | Sessions, compiled templates and the SQLite database |
| `vendor/` | Composer-managed dependencies; do not edit |

## Everyday commands

Run these from your project folder, in a second terminal while the server runs:

```bash
php orbit routes
php orbit make:controller Blog/Posts --view
php orbit make:form Contact --controllers
php orbit make:migration create_posts_table
php orbit migrate
php orbit migrate:status
php orbit help
```

`routes` lists registered URLs, `make:*` generates starter files, and
`migrate:status` shows applied and pending database changes. Generated files
are yours to edit; read each command's output for wiring you need to add.

`php orbit ui` opens a separate admin dashboard at <http://127.0.0.1:8081>
for generators, migrations, mail and storage. **It has no login: keep the
default loopback binding and never expose it publicly.**

To run automated tests and static checks:

```bash
composer test
composer stan
```

If a command fails, follow the error before continuing. The
[troubleshooting table](docs/getting-started.html#troubleshooting) covers
missing commands, dependencies, database extensions, busy ports and 404s.

## Running this repository's demo

If you cloned phporbit itself, rather than creating an application:

1. Run `composer install` in the repository root.
2. If `.env` does not exist, copy `.env.example` to `.env` in your editor.
   In PowerShell: `Copy-Item .env.example .env`; on macOS/Linux:
   `cp .env.example .env`.
3. If `APP_KEY` in `.env` is blank, run `php orbit key:generate` and paste the
   entire printed `base64:...` value after `APP_KEY=`. The command prints a key;
   it does not write the file. Keep the key private, and do not replace an
   existing key: previously encrypted data still needs it.
4. Run:

```bash
php orbit migrate
php orbit db:seed
php orbit serve --debug
```

Visit <http://127.0.0.1:8080> for the demo self-check page, and
<http://127.0.0.1:8080/docs> for the documentation.

## Learning more

- [Getting started](docs/getting-started.html) — installation, your first page
  and troubleshooting
- [Forms](docs/forms.html) — inputs, validation and CSRF protection
- [Database](docs/database.html), [migrations](docs/migrations.html) and
  [models](docs/models.html) — storing and retrieving data
- [Documentation overview](docs/index.html) — all framework topics
- [Deployment](docs/deployment.html) — production setup

Generated projects do not include a `/docs` route or a copy of the
documentation. In an installed project, the framework's docs are available
at `vendor/phporbit/phporbit/docs/index.html`.

## One application, four deployment targets

| Target | Used for |
| --- | --- |
| `php orbit serve` | Local development with phporbit's own server |
| FrankenPHP worker mode | Production |
| nginx + PHP-FPM | Production |
| Apache (mod_php or PHP-FPM) | Production |

Your application runs unchanged across these targets. The built-in server and
FrankenPHP keep the process alive across requests, so **never keep the current
visitor's data in static properties or singleton services**. Use request-scoped
services and phporbit's sessions, not PHP's global session functions.

Secure defaults include escaped template output, CSRF protection, safe
sessions, password hashing, and a query builder that refuses an accidental
`UPDATE` or `DELETE` without conditions.

For work on the framework itself, [CLAUDE.md](CLAUDE.md) explains its design
constraints and contributor expectations.

## License

MIT — see [LICENSE](LICENSE).
