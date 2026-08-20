# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What phporbit is

A PHP framework whose defining constraint is that **one application runs unchanged across four deployment targets**:

| Target | Process model | Adapter |
| --- | --- | --- |
| phporbit's own server (`./orbit serve`) | long-lived, self-hosted | `Sapi\OrbitServer` |
| FrankenPHP worker mode | long-lived | `Sapi\FrankenPhpSapi` |
| nginx + PHP-FPM | per-request | `Sapi\FpmSapi` |
| Apache (mod_php or PHP-FPM) | per-request | `Sapi\FpmSapi` |

"Runs on itself" is literal: `OrbitServer` is a real HTTP/1.1 server built on `stream_socket_server`, sharing the exact request pipeline used in production — not a router shim in front of `php -S`.

## Commands

```bash
./orbit new my-app                   # scaffold a blank project
./orbit new my-app --demo            # or one containing this demo application
./orbit make:class Notes/NoteRepository      # generate a plain App\ class
./orbit make:class Support/Clock --singleton # ...registered for the whole process
./orbit make:controller Admin/Users --view   # generate a controller (+ template)
./orbit make:form Contact --controllers      # a form + the two pages that use it
./orbit make:middleware RequestId            # a Middleware class (registration printed, not inserted)
./orbit make:migration create_articles_table # generate a migration
./orbit make:model Note --fields=title:string,body:string # a Model subclass (typed properties + fromRow/toRow)

composer install
cp .env.example .env                 # first run: configuration
./orbit migrate && ./orbit db:seed   # first run: schema + demo account

./orbit serve                       # own server on 127.0.0.1:8080 (applies pending migrations first)
./orbit ui                          # admin dashboard on 127.0.0.1:8081 — no login, loopback by default
#   covers migrate/mail/routes/sessions/storage AND every make:* generator
./orbit serve --port=9000 --debug   # --debug exposes exception detail and recompiles templates
./orbit routes                      # print the compiled route table
./orbit migrate:status              # applied/pending, with batch numbers
./orbit migrate:rollback [--batches=1]
./orbit storage:clear               # wipe storage/cache/views (safe: recompiles on next render)
./orbit sessions:gc                 # delete expired session files
./orbit mail:test --to=x@example.test       # sends one real message via MAIL_DRIVER
./orbit mail:list [--status=failed]         # every send is logged to mail_log
./orbit mail:resend <id>|--failed           # resend, in place — attempts grows

composer test                       # full suite
composer stan                       # PHPStan, max level

vendor/bin/phpunit --testsuite unit         # unit tests only
vendor/bin/phpunit --testsuite worker       # worker/process-model tests only
vendor/bin/phpunit --testsuite integration  # needs real servers; skips without them
vendor/bin/phpunit --filter test_name       # single test
vendor/bin/phpunit tests/Unit/Http/UriTest.php
```

Both gates pass clean: **772 tests** (20 of them integration tests that skip without a server), PHPStan max with no baseline and no `ignoreErrors`, over `src`, `tests`, `app`, `database`, `docs`, `orbit` and `public`.

`php -S localhost:8000 -t public` also works — it exercises the per-request path through `FpmSapi`, which makes it a quick way to check the *other* process model without installing nginx. It is not a supported deployment target.

Visit `/` on a running server for a live self-check page (**19 checks**) that exercises routing, sessions, CSRF, migrations, the query builder, SQL-injection resistance, password hashing, upload type sniffing, escaping, validation and worker isolation.

## The architectural pressure that shapes everything

The four targets split into **two incompatible process models**, and this is the single fact that most influences design decisions:

- **Per-request SAPIs** (Apache, nginx+FPM) tear down the process after every response. Global state is free — it cannot leak, because nothing survives.
- **Long-lived workers** (own server, FrankenPHP) boot once and serve thousands of requests in one process. Anything mutable that outlives a request leaks across users.

Consequence: **worker-safety is a correctness invariant for all framework code, not a deployment concern.** Worker-safe code is automatically correct on per-request SAPIs; the reverse is false. Assume the worker model when in doubt.

### How the invariant is enforced structurally

- `Kernel\Application::boot()` takes a callback receiving a `Kernel\Blueprint`, then **compiles the route table and freezes the container**. It returns an `Application` whose properties are all `readonly`.
- `Container::singleton()` / `scoped()` throw `ContainerFrozen` after boot.
- `Application::handle()` opens a `RequestScope` and closes it **in a `finally` block** — the most important line in the framework.
- **Autowiring lives only on `RequestScope`, never on `Container`.** An autowired instance cached at container level would be shared by every later request.
- **Scoped factories receive the `RequestScope`, not the `Container`.** Handing them the container tempts a factory into opening a second scope, which silently misses everything middleware published into the real one.
- Objects that would otherwise hold per-request state are split so the shared half is immutable: `TemplateEngine`/`Renderer`, `SessionMiddleware`/`Session`, `UserProvider`/`Authenticator`.
- `Database\TransactionGuard` rolls back any transaction a handler left open on the singleton connection.
- **Uploads are discarded by the kernel**, not by middleware — see below.

### Where environment-specific code is allowed

**Only in `src/Sapi/` and the entrypoints** (`orbit`, `public/index.php`). Above that boundary the request object is identical on all four targets.

This includes CLI-only constants. **`STDERR`, `STDOUT` and `STDIN` exist only under the CLI SAPI** — referencing one in `app/bootstrap.php` or anywhere in `src/` is a fatal error at boot under FPM, Apache and `php -S`. Use `StreamLogger::standardError()`, which opens `php://stderr`: available on every SAPI, and it lands in the terminal under the CLI, the pool error log under FPM, and the server error log under Apache.

`tests/Unit/PortabilityTest.php` enforces all of this by tokenising the source — CLI-only constants, superglobals outside the SAPI boundary, and any use of PHP's session extension. It tokenises rather than pattern-matches so the prose explaining *why* a construct is banned does not trip the check, and it has a test proving the scanner is not simply blind. **The whole suite runs under the CLI, so nothing else catches this class of bug.**

## Layout

```
src/Http/         immutable values — Method, Status, Headers, Uri, ServerRequest, Response, Cookie, FormBody
src/Http/Upload/  MultipartParser, UploadedFile, UploadQuotas, UploadError
src/Container/    Container (boot, freezable) + RequestScope (per-request, disposable, autowiring)
src/Routing/      RouteCollection -> Router (compiled); Handler interface; URL generation
src/Middleware/   Middleware interface, Pipeline, ServeStaticFiles
src/Kernel/       Application (two-phase boundary) + Blueprint
src/Session/      Session, SessionStore, FileSessionStore, SessionMiddleware
src/Security/     Escaper, Csrf, CsrfMiddleware
src/Auth/         Identity, UserProvider, PasswordHasher, Authenticator, LoginThrottle, RequireAuthentication
src/Database/     Connection, Query + Identifier, Model + ModelQuery, Migrator + Migration, TransactionGuard
src/View/         Compiler, Renderer, TemplateEngine
src/Validation/   Validator
src/Log/          Logger, StreamLogger, LogRequests
src/Mail/         Address, Message, MessageWriter, Mime, SmtpSession, SmtpMailer, ArrayMailer, PersistingMailer + MailLog(Repository)
src/Crypto/       Key, Encrypter, Signer, CryptoFactory
src/Form/         Field, Form, Submission, Honeypot, Captcha + MathCaptcha
src/Sapi/         four adapters + RequestParser + Emitter
src/Admin/        AdminApplication (orbit ui) — Controllers/ (+Generate/), templates/, assets/
app/              demo application: bootstrap.php, routes.php, src/, templates/
database/migrations/
tests/Unit/       per-component
tests/Worker/     process-model tests
```

## Configuration

`Config\Environment` is read **once at boot** and registered as a singleton — a worker touches the filesystem once per process, and configuration cannot drift mid-process.

**The real environment wins over `.env`.** The file is a development convenience and a source of defaults; in production the values injected by systemd, Docker or Kubernetes must apply, and a stale `.env` left on a server must never override them. `./orbit serve --debug` works by setting `APP_DEBUG` in the process environment, which is the same rule rather than an exception to it.

Access is through typed accessors (`string`, `int`, `bool`, `strings`, `path`, `required`), never a `get(): mixed`. Everything in a `.env` is a string and the conversion has to happen somewhere; doing it here means `APP_DEBUG=treu` fails at boot with a readable message instead of quietly evaluating as true. `required()` additionally rejects a blank value, because `APP_KEY=` is exactly as unusable as omitting it. `path()` resolves relative values against the project root, so a setting does not depend on the working directory the process started in.

Two secrecy rules, both load-bearing:

- **Parse errors name the key and line, never the value.** Exception messages travel into logs and bug reports.
- **`Environment::__debugInfo()` redacts values**, so a `var_dump` or stack trace cannot spill every credential the object holds.

`.env` lives beside `composer.json`, outside `public/`, so it is unreachable even if a rewrite rule is misconfigured — and `ServeStaticFiles` refuses dotfiles regardless. `.env` is git-ignored; `.env.example` is committed and documents every setting.

Supported syntax is deliberately small: `KEY=value`, `"double quotes"` with escapes and `${VAR}` expansion, `'single quotes'` that are wholly literal, `export` prefixes, `#` comments, and quoted values spanning lines. Expansion runs *before* unescaping so `\${VAR}` stays literal, and an undefined `${VAR}` is an error rather than an empty string — silently expanding a password to nothing fails far from its cause.

## Request lifecycle

Routing happens **before** the middleware pipeline, so a layer can see which route matched (how CSRF honours per-route exemptions) while still running for unmatched requests (so logging sees 404s). The matched `Route`, the `RequestScope` and the final `ServerRequest` are published into the scope, which is what makes constructor autowiring work in controllers.

Middleware order is registration order, outermost first: `CsrfMiddleware` must follow `SessionMiddleware` because it reads the token the session holds.

## Routes

Declared in `app/routes.php`, which returns `Closure(RouteCollection $routes, bool $debug): void` and is pulled in by `Blueprint::loadRoutes()` from inside the boot callback. Living in its own file changes *where* routes are written, not *when* they take effect — they still land before the table is compiled and the container frozen. `loadRoutes()` checks what the file returned, so a file that forgets its `return` says so instead of failing with "value of type null is not callable".

Group a guard over several routes with `RouteCollection::withMiddleware([...], fn)` rather than repeating it per line — a guard repeated on each line is one that eventually gets left off. It is a prefix-less `group()` under the hood, named separately because "these require a signed-in user" and "these live under /admin" are different statements. `tests/Unit/App/RoutesFileTest.php` asserts which routes carry the auth guard, since that is what quietly regresses when someone adds a route next to an existing one.

`./orbit routes` prints the compiled table.

## Safety model

**Secure by default** — the safe path is the default path:

- `Escaper` is context-aware; templates escape by default (`{{ }}`), raw output needs the loud `{!! !!}`, and `@{{` renders literal braces.
- CSRF is on by default, opted out per route, compared with `hash_equals`.
- Sessions are ours, not PHP's — `$_SESSION` is process-global and would leak across a worker's requests. 256-bit ids, strict hex validation before touching the filesystem, atomic writes at mode 0600, and a cookie naming an unknown session is never adopted (fixation defence). Expired files are refused on read regardless; `./orbit sessions:gc` only reclaims the disk space, so a deployment without a scheduler loses nothing but tidiness by skipping it.
- **Passwords** use Argon2id (bcrypt fallback), reject input over 72 bytes rather than letting bcrypt truncate it, and rehash on login when parameters change. A failed lookup still runs a decoy verification so response time does not reveal whether an address is registered. `LoginThrottle` limits attempts, keyed on email *and* client address together and stored hashed.
- **Uploads**: nothing about a client's claim is trusted. `detectedType()` sniffs the real bytes; the filename and declared type are never consulted for a decision. `moveTo()` refuses any name containing a path separator rather than quietly reducing it, and stores at mode 0640. Quotas are required to parse at all — an upload endpoint without them is a DoS primitive.
- **Query builder**: identifiers are whitelisted (no driver can bind them), operators come from a fixed list, `where(..., null)` is refused in favour of `whereNull()`, `whereIn([])` matches nothing rather than everything, and an **UPDATE/DELETE with no conditions throws `UnsafeQuery`** unless `affectingEveryRow()` was called.
- Security headers come from `Response::secureDefaults()`, not middleware, so error-path responses still carry them.
- `Uri` decodes path segments **after** splitting on `/` and matches dot segments **after** decoding, so `%2E%2E` is caught as traversal and encoded separators are rejected.

**Type-safe**: `declare(strict_types=1)` everywhere, no `mixed` in public APIs, PHPStan max as a hard gate with **no baseline and no `ignoreErrors`**. Deliberate `mixed` boundaries, narrowed immediately: template data (`Renderer::stringify()`), driver rows (`Connection::narrowRow()`), session files, and `$_FILES`.

## The upload cleanup contract

Temporary files are discarded by `Application::scheduleUploadCleanup()` when the request scope closes — **not** by middleware. Uploads exist from the moment the request is built, so an application that forgot to register an upload-handling layer would otherwise fill its temp directory. Files a handler moved are left alone; the scope closes in a `finally`, so cleanup also runs when a handler throws.

If you construct a `MultipartParser` directly outside an `Application`, nothing owns that cleanup and you must call `discard()` yourself.

## Database engines

SQLite, MySQL/MariaDB and PostgreSQL, selected by `DB_DRIVER` in `.env` and validated at boot by `DatabaseSettings::fromEnvironment()` — a bad driver name or port stops the process rather than failing on the first query.

**Every engine difference lives in `Database\Driver`**, not scattered through the query builder as conditionals. Adding an engine means implementing those methods and nothing else. The differences that actually matter: MySQL quotes identifiers with backticks (double quotes are a *string literal* there), a bare `OFFSET` is a syntax error on SQLite and MySQL, auto-increment keys are spelled three ways, and PostgreSQL's `lastInsertId()` returns the last value from *any* sequence in the session — so `insert()` uses `RETURNING` there.

Quoting is per connection rather than by setting MySQL's `sql_mode` to `ANSI_QUOTES`: that would change how every other statement on the connection parses, including hand-written SQL.

`Identifier::quote()` takes a `Driver`, but its **validation does not vary by engine** — that is the security property, and one engine getting a laxer rule would make it the weak one. Only the delimiter changes.

Migrations targeting MySQL need `VARCHAR(n)` rather than `TEXT` on indexed columns (MySQL cannot index `TEXT` without a prefix length), and cannot rely on transactional DDL.

**Only `pdo_sqlite` is installed on this machine.** The per-engine SQL and DSN generation is unit-tested by labelling a connection with a driver and inspecting `toSql()` without executing it; connecting to a real MySQL or PostgreSQL server has not been exercised here.

## Forms

`Form\Form` renders a form and checks its submissions from **one declaration** — `Field::email('email')->required()` yields both the attribute and the rule. Declaring them separately is the ordinary way a field ends up unvalidated. Forms and fields are immutable, so one may be built at boot and rendered per request; the demo's `/contact` defines it in `App\Support\ContactForm` so the render and submit controllers cannot disagree.

A `post()` form emits a CSRF token automatically, and nothing on the class emits raw HTML.

Two protections, deliberately layered:

- **`Honeypot`** — a decoy field plus a *signed* render timestamp (too fast, or too stale, is refused). The decoy is hidden with the HTML `hidden` attribute, **not a CSS class**: this framework ships no inline CSS, and a rule someone forgets to copy would leave the trap visible and reject real people.
- **`MathCaptcha`** — arithmetic, no JavaScript, no third party, readable by a screen reader. The answer is **encrypted rather than signed** (a signed answer is readable in the page source) and bound to the session so it cannot be solved elsewhere and pasted in.

**`MathCaptcha` does not stop a targeted attacker** — a language model solves arithmetic. It stops undirected scripts. Say so rather than implying otherwise; the `Captcha` interface exists for swapping in a real service.

A rejection tells the page one generic message; `Submission::rejectedAs` carries the specific reason **for logs only**, because naming the check that fired tells a script author what to change.

## Encryption

`Crypto\Encrypter` (XChaCha20-Poly1305) for secrecy, `Crypto\Signer` (HMAC-SHA256) for values that must not change but are not secret, `Auth\PasswordHasher` for passwords. Choosing between them is most of the work; the docs page leads with that table.

- **The algorithm is not selectable and authentication cannot be disabled.** A cipher selector is a way for a future reader to pick a worse one.
- **`encrypt()` takes an optional context**, authenticated but not encrypted. Binding a ciphertext to `users.email:42` stops an attacker who can write to the database from moving one row's value into another and having it decrypt.
- **One exception, one message, for every failure** — wrong key, tampering, truncation, wrong context. Distinguishing them tells an attacker which part of a forgery was closest.
- **Signing and encryption derive independent keys** from `APP_KEY` via `Key::derive()`, so one configured secret never becomes one shared key.
- `APP_KEY` is read with `required()` and resolved **lazily**, so an application that never encrypts never needs one. `APP_PREVIOUS_KEYS` decrypts after a rotation but never encrypts.
- `Key` refuses `__toString`, `print_r` and `serialize`. There is deliberately **no `sodium_memzero`**: it would wipe one buffer while PHP has already copied the bytes, buying a false sense of having scrubbed the process — and it nulls a reference a typed property cannot hold.

`orbit key:generate` prints a key rather than writing one; `orbit new` generates a distinct key per project, and the committed `.env.example` keeps `APP_KEY=` blank so no template ever ships a shared secret.

## Mail

`Mail\Message` builds immutably; `Mail\Mailer` sends. `MAIL_DRIVER` picks the implementation and **defaults to `array`**, which collects messages in memory — a development machine that silently starts delivering real mail is a worse failure than one that sends none.

Three things are structural rather than configurable:

- **Header injection is refused, not stripped.** CR/LF/NUL in a subject, display name or address throws. Subjects are routinely user-supplied, and everything after a newline becomes headers of the sender's choosing.
- **Credentials require encryption.** `SmtpSettings` throws if a username is set with `MAIL_ENCRYPTION=none`, unless `MAIL_ALLOW_INSECURE_AUTH=true`. SMTP AUTH base64-encodes the password, which is not encryption. TLS peer verification has no setting at all.
- **The connection is per-send.** Holding one open across requests would hand a worker a stateful socket, possibly mid-transaction after a failure.

The protocol lives in `SmtpSession`, which takes an **already-connected stream** rather than a host and port. That is what makes it testable: `SmtpSessionTest` drives it over a `stream_socket_pair()` with the server's replies queued in advance, so the conversation — dot-stuffing, multi-line replies, HELO fallback, AUTH — is exercised without a server. **No real SMTP server has been contacted from this machine**; delivery against a live relay is unverified. `./orbit mail:test --to=you@example.test` is the command that would prove it — it sends one real message through whatever `MAIL_DRIVER` is configured — but running it against a live relay has not actually been done here, only against `array` and against a deliberately unreachable host to exercise the failure path.

`ArrayMailer` is the one deliberately stateful service in the framework. Register it `scoped()` rather than `singleton()` if the application reads back what it collected.

**Every send is persisted.** Both bootstraps wrap the driver-selected mailer in `Mail\PersistingMailer`, which records one row per `send()` call to the `mail_log` table — full message content, the outcome (`sent`/`failed`), and the server's reply on failure — then rethrows `MailFailed` exactly as before. It is a decorator, not a replacement: calling code that already catches `MailFailed` needs no changes. A validation failure (`InvalidArgumentException` from `assertSendable()`) is a caller bug, not a delivery failure, and is deliberately **not** logged — only what the `Mailer` interface's `@throws MailFailed` actually promises gets recorded. `resend()` refuses anything not currently `Failed` — resending a `Sent` row would deliver it twice with no record that it happened — and updates that row in place (`attempts` grows) rather than writing a new one, because a resend is another attempt at the same logical message, not a new one. `./orbit mail:list` and `./orbit mail:resend <id>|--failed` are thin CLI wrappers around this. Both bootstraps bind the same instance under `PersistingMailer::class` as well as `Mailer::class` — the interface has no `resend()`, so the CLI resolves the concrete type, the same pattern `DatabaseUserProvider` uses alongside `UserProvider`. **The audit write itself is best-effort** — `send()` swallows a `QueryFailed` from the write (most commonly `mail_log` not existing yet), because losing the log row is a smaller problem than a successful send being reported as a failure, or a real `MailFailed` being replaced by an exception the caller isn't catching.

This is still the "not built: queuing" line holding — every send and every resend stays synchronous, and a resend is a command a developer runs, never a retry loop the framework runs on its own.

## Migrations

Files in `database/migrations/` named `<digits>_<lowercase_words>.php`, each returning a `Migration`. Ordering is by filename so branches merge deterministically. Each migration runs in its own transaction — a guarantee that holds on SQLite and PostgreSQL but **not MySQL**, which lacks transactional DDL.

`down()` is required rather than optional; throw `IrreversibleMigration` when a change genuinely cannot be undone, so the decision is recorded instead of being an empty method that silently "succeeds". Applied migrations are grouped into batches, and a rollback loads every migration in the batch before undoing any of them.

`orbit serve` applies pending migrations as a development convenience. The production entrypoints never touch the schema: several workers booting at once would race.

## Models

`Database\Model` is an ActiveRecord-shaped layer over one table — `Note::find(1)`, `$note->save()`, `$note->delete()` — generated with `./orbit make:model Note --fields=title:string,views:int`. It is built entirely on `Query`: nothing on `Model` composes SQL of its own, and nothing here adds a capability `Query` does not already have. Joins, aggregates beyond `count()`, and relationships are still `Connection::select()`'s job — a model is a typed, mutable view of one table's rows, not a query language. This is a deliberate reversal of the query builder's own framing ("deliberately not an ORM"): that description is still true of `Query` itself, and stays true — `Model` is a second, optional layer on top of it, not a replacement.

A generated model writes exactly two hand-editable methods, the same "narrow once, at the boundary" shape as `Connection::narrowRow()`: `fromRow(array $row): static` maps a database row onto typed properties, and `toRow(): array` is its inverse, read by `save()`. The primary key is always `id`, handled entirely by the base class — `fromRow()`/`toRow()` never touch it — matching the one spelling every generated migration already uses.

The static finders (`find()`, `all()`, `where()`) need a `Connection` without a container to resolve one from, so there is exactly one static mutable property here: the shared connection, set once by `Model::useConnection($database)` — called in `app/bootstrap.php`, right where `Connection` is registered as a container singleton. A second call throws, the same shape as registering a container service twice. This is **not** the static-state hazard the rest of this framework forbids: under a worker, `Connection` is already one object shared by every request in the process, and pointing `Model` at that same instance adds no risk, because nothing *per request* is ever cached statically — `find()`, `all()` and every `ModelQuery` terminal method (`get()`, `first()`) build a fresh instance on every call. What would reintroduce the hazard — memoising a row or a query result on a static property — `Model` never does. `tests/Worker/StateIsolationTest.php` proves the second half of that claim (fresh instances, not shared ones) the same way it proves it for every other stateful piece here. `Model::resetConnectionForTesting()` exists solely so a test process that boots several applications in one PHP process (`ScaffoldTest`, `FormMakerTest`) is not stuck fighting over the one static — application code should never call it.

`where()` and `query()` return a `ModelQuery`, the fluent counterpart to `Query` — same chain, but `get()`/`first()` hydrate instances instead of handing back arrays. `update()`/`delete()` on a chain stay row-based; a bulk write over many rows has no single instance to return.

## Admin UI

`orbit ui` starts `Admin\AdminApplication` — a **second, independent `Kernel\Application`**, built by its own `boot()` in `src/Admin/AdminApplication.php`, never merged into `app/routes.php`. That separation is the whole design: a page that can run migrations, resend mail and wipe the template cache, if it were a few extra lines in the real route table, ships with every deployment unless a developer remembers to strip it back out — and "remembers to remove it" is not a security boundary. Its templates ship with the framework in `src/Admin/templates`, not `app/templates`, and its own `TemplateEngine`/cache directory (`storage/cache/admin-views`) never touches the project's compiled views.

Twenty-six single-action controllers under `src/Admin/Controllers/` — the same "one class per route" rule as everywhere else — cover eight pages (overview, migrations, mail, routes, sessions, storage, generate, tools) and their POST actions. All of it reuses the project's own services and the CLI's own generator classes rather than reimplementing anything: the same `Migrator`, the same `PersistingMailer` (so a resend from a button and `orbit mail:resend` are the identical call), the same `Console\ClassMaker`/`ControllerMaker`/`FormMaker`/`MiddlewareMaker`/`MigrationMaker` every `orbit make:*` command calls. `ProjectPaths` exists solely because autowired classes may only take object-typed constructor parameters — it is the object that carries the plain project-root string.

**Generate and Tools cover the rest of the CLI.** `src/Admin/Controllers/Generate/` has a GET/POST pair per generator — the GET renders a blank form, and the POST *re-renders the same template*, never a redirect: on success with the written paths and snippets, on failure with the error and what was typed. A generator's output is meant to be read and copied, and a flash-and-redirect would show one line and lose the rest on refresh — the same reasoning `SubmitContactController` already follows for a failed validation. `Tools` does the same for `orbit key:generate` (prints, writes nothing) and `orbit mail:test` (a real send through `PersistingMailer`, so it is one more row in `mail_log` and resendable like any other failure).

**`PersistingMailer::send()`'s audit write is best-effort.** `MailLogRepository::record()` can throw `QueryFailed` — most commonly because `mail_log` doesn't exist yet — and that must never be the reason a real, successful send gets reported as a failure, or the reason a real `MailFailed` gets replaced by an exception a caller's `catch` no longer matches. This surfaced through `Tools`' mail-test form against an unmigrated project and is now covered by `PersistingMailerTest`. Watch for `treatPhpDocTypesAsCertain: true` here specifically: an incomplete `@throws` tag on a method PHPStan can fully resolve makes it treat a real, reachable exception as unreachable — `record()`'s tag named `JsonException` but not `QueryFailed`, and PHPStan trusted that as complete.

**No authentication.** What stands in for it: `--host` defaults to `127.0.0.1`, matching `orbit serve`, and the CLI warns loudly if told to bind anywhere else. This is deliberate rather than an oversight — see "Not built" on the admin-ui docs page. Treat a bound admin UI the way you would a database console left open on a machine: fine on localhost or behind an SSH tunnel, never behind a public host or a port forward.

**A distinct session cookie**, `orbit_admin_session` rather than the project's `SESSION_COOKIE`. Cookies are scoped by host, not port, so `orbit serve` and `orbit ui` running side by side on `127.0.0.1` — an entirely ordinary way to run both in development — would otherwise silently overwrite one another's session.

The routes page reads `app/routes.php` by compiling a throwaway `RouteCollection` (`AdminApplication::projectRoutes()`) purely to enumerate it — the project's routes are never registered on the admin app's own router, so they are listed but never actually served from this process. The mail and overview pages catch `QueryFailed` around anything touching `mail_log`, so a project that has never run `orbit migrate` sees "run pending migrations" instead of a raw database error; `Migrator` needs no such guard, since its own bookkeeping table is created on first use regardless of project state.

## Testing expectations

`tests/Worker/` exists because state-leak bugs are **invisible** under per-request execution. Every test there boots once and handles at least twice. `StateIsolationTest` covers service lifetimes, sessions not bleeding between visitors, teardown on the error path, and memory growth over 2000 requests. `UploadAndAuthIsolationTest` covers the upload cleanup contract (including the throwing path) and that authentication never leaks between interleaved visitors. `OrbitServerTest` drives the real `./orbit serve` over TCP.

When adding framework-level behavior, test it under both process models.

`tests/Integration/` covers what only a real server can answer. The unit tests assert the SQL the query builder *generates* (by inspecting `toSql()` without executing it) and the SMTP commands the mailer *sends* (over a `stream_socket_pair()` with scripted replies) — neither proves a server accepts them, and the engine differences are exactly where that gap bites. Each test asks for its service through `RequiresService` and **skips** when the environment variables are unset or nothing is listening, so `composer test` stays useful on a machine with only `pdo_sqlite`. That makes a green local run weak evidence by design, which is why CI runs the suite with `--fail-on-skipped`: a missing service is a failure there, not a quiet pass.

## Continuous integration

`.github/workflows/ci.yml`. Every job exists because something is otherwise only asserted on this machine, never verified:

| Job | Closes the gap |
| --- | --- |
| **Tests** | Both gates on PHP 8.3/8.4/8.5. 8.3 is the floor in `composer.json` and the version newer syntax breaks silently. |
| **Integration** | MySQL 8.4, PostgreSQL 17 and Mailpit as service containers, `--fail-on-skipped`. |
| **Per-request SAPI** | Boots the demo under a web SAPI and fetches real pages. **The suite runs under the CLI**, so this is where a CLI-only construct actually surfaces rather than being caught statically. |
| **Long-lived worker** | The other process model. |
| **Documentation** | Rebuilds `docs/` and fails if the committed pages differ. |

Two details worth keeping if you edit it:

- **`orbit key:generate` prints a key and writes nothing**, deliberately. CI puts it in `$GITHUB_ENV` rather than a file, which is the same "real environment wins over `.env`" rule production follows.
- The per-request job greps for **`check-fail`** — the class a failing self-check row carries — and *first* confirms the page rendered at all, since an empty response also contains no failures.

`composer.lock` is git-ignored (this is a library), so CI resolves dependencies fresh. That is intentional: it means a transitive break in phpstan or phpunit shows up here rather than the first time someone installs.

## Learning curve

Convention over configuration, minimum ceremony between install and a route returning a response. This trades against nothing in the safety model — defaults are both convenient *and* safe. **If a proposed convenience requires weakening a security or type guarantee, the convenience is wrong.** Controllers are single-action classes implementing `Routing\Handler` for exactly this reason: a `[Controller::class, 'method']` pair can only be called dynamically, which returns `mixed`.

## Code generation (`orbit make:*`)

`Console\MigrationMaker` writes a migration. The filename prefix is a **timestamp by default** — the migrator orders by filename, and timestamps are what let two branches add migrations without coordinating; `--sequential` gives the `0001` counter style instead. The name picks the starting contents (`create_x_table` → CREATE TABLE, `add_x_to_y` → ALTER, anything else → a blank pair), which is a convenience and never load-bearing. **Generated migrations use `$database->driver()->autoIncrementPrimaryKey()`**, so they are portable across all three engines from the start.

Names are normalised freely (`CreateArticlesTable`, `create articles table`) because a name is words — but a path separator is refused rather than rewritten, since it means the caller was aiming somewhere. `--table` is validated as an identifier, because no driver can bind one.

`Console\ModelMaker` writes a `Database\Model` subclass under `App\Models`, and — like `MigrationMaker` — only *guesses* the table name (a naive pluralisation of the class name); `--table=` overrides it, and `table()` in the generated class is the one line to edit if the guess is wrong. `--fields=name:type` is parsed by `Console\ModelFieldSpec` against a fixed set of PHP scalar types (`string`, `int`, `float`, `bool`, `?`-prefixable), a different vocabulary from `FormFieldSpec`'s on purpose: a model field names storage, not an input. Each field writes three matching lines — a typed, defaulted property; one `fromRow()` line; one `toRow()` line — so a property can never exist without both halves of the mapping that fills it. Like `ClassMaker`, there is no lifetime and no registration: a model is reached through its own static finders, never resolved from the container, so the command prints the one thing it does not write instead — the `Model::useConnection($database)` line for `app/bootstrap.php`.

`Console\ClassMaker` writes a plain class under `App\` — the everything-else case, since most of an application is neither a controller nor a migration. **The lifetime is an argument, not an afterthought**: `Console\Lifetime` defaults to `Autowired`, which registers nothing and therefore cannot leak, while `--singleton` and `--scoped` print the `app/bootstrap.php` line and put the constraint that choice carries into the generated class comment. The two flags are mutually exclusive, and `Lifetime::fromFlags()` refuses the pair — in `src/`, so both copies of the `orbit` script cannot disagree about it.

Names are validated like a controller's (StudlyCase, nested by `/`), with two additions: a leading `App` is dropped rather than doubled, because pasting a fully qualified name is how people refer to a class; and **reserved words are refused**, since `class Match {}` and `namespace App\List;` are parse errors, and naming the word beats a syntax error inside generated code. That rule lives in `Console\PhpName` and is shared with `FormMaker` rather than copied — one generator validating while another sanitised would make the laxer one the weakness.

`Console\FormMaker` writes a form definition — one class whose `build()` returns the `Form`, which is the shape that keeps the render and the submit from disagreeing. `--controllers` adds both controllers and the template, built against that method; **all four targets are checked before the first is written**, since a half-generated slice neither compiles nor reruns cleanly. `--fields=name:type` is parsed by `Console\FormFieldSpec` against `Form\FieldType`, so an unknown type is answered at the command line rather than as "undefined method `Field::hidden()`" in generated code.

**Protections default to on** (`Honeypot`; `--captcha` adds `MathCaptcha`, `--no-honeypot` removes both), because one that has to be added afterwards gets added after the first spam run. The field names the form itself emits — `_token`, the decoy `website`, `_rendered`, and the captcha's pair — are **refused as field names**: that clash is silent at runtime and rejects every genuine submission with a message explaining nothing.

`Console\MiddlewareMaker` writes a class implementing `Middleware`, with a pass-through `process()` body. **It does not touch `$app->middleware(...)`** — unlike a controller or an autowired class, middleware is never resolved by the container; the list takes constructed objects directly, in the exact order they run, and that order is meaning rather than plumbing (session before CSRF, logging outermost). The `new X(),` entry is printed for the developer to place, the same reasoning as the route line `make:controller` leaves to paste.

`Console\ControllerMaker` writes a controller, and with `--view` the template plus the `TemplateEngine` injection. `Home` and `HomeController` both yield `HomeController`; `Admin/Users` nests both the namespace and the template path (`admin/users`).

**It does not edit `app/routes.php`** — it returns the route line and import for the developer to paste. Rewriting a file the developer owns means parsing and re-emitting their code, and getting that subtly wrong is worse than leaving one line to add.

Names are validated against StudlyCase, not sanitised: a name from a shell argument must never place a file outside `app/src/Controllers`. Existing files are never overwritten without `--force`.

The generator lives in `src/` (not the CLI script) so scaffolded projects get the command too — **`stubs/skeleton/orbit` carries the same case block, so a new command has to be added in both places.**

## Instructions for AI assistants

phporbit is in no model's training data, so an assistant will otherwise guess from Laravel — and those guesses are wrong in exactly the places this framework cares about (`$_SESSION`, container caching, unescaped output, engine-specific DDL).

**The canonical file differs by repository, deliberately:**

| Repository | Canonical | Pointers |
| --- | --- | --- |
| phporbit itself | `CLAUDE.md` (this file) | `AGENTS.md`, `.github/copilot-instructions.md` |
| A scaffolded project | `AGENTS.md` | `CLAUDE.md`, `.github/copilot-instructions.md` |

Here, CLAUDE.md wins because Claude Code loads it automatically — the rules are in context before the first edit rather than after a file read. In generated projects the broadest convention wins instead, since those are worked on by every kind of assistant. **Pointers, never copies:** two sets of the same rules drift and then disagree exactly when it matters, which `ScaffoldTest` guards by asserting the pointers stay short.

The scaffold's `stubs/skeleton/AGENTS.md` is a constraints file, not a tutorial. It states what never to do, the three service lifetimes, the template delimiters, and — importantly — a "things that do not exist" list, because inventing `Route::get()` or `Model::find()` is the most common failure mode. Keep it accurate: it claimed a static scan enforced the `$_SESSION` rule in user projects, which was false, since `PortabilityTest` is a framework test and does not ship.

## Scaffolding (`orbit new`)

`Console\Scaffold` writes a project from `stubs/`. Two variants in `Console\Variant`: **blank** (one route, one controller, one template, a starter test) and **demo** (`--demo`).

The two variants have different sources on purpose. Blank comes from `stubs/blank/`; **the demo is copied from this repository's own `app/`, `database/` and `public/`**, so it cannot drift from the thing it is a copy of. `stubs/skeleton/` holds what both share — entrypoints, `.env.example`, tooling, README. Only `composer.json` is generated, because it carries the project's own name.

**Neither variant includes `docs/`.** `app/bootstrap.php` mounts `/docs` only when the directory exists (`$hasDocs`), and the layout hides the nav link the same way — so a scaffolded project has no dead route and needs no post-copy surgery. Keep that conditional if you touch the middleware list.

An occupied target directory is refused unless `--force`; `.env` is never overwritten.

`stubs/` is deliberately outside the PHPStan and `PortabilityTest` path lists — the stubs reference `App\` classes that do not exist at framework level. `ScaffoldTest` covers them instead: it `php -l`s every file it writes, and **boots the blank project and serves real requests through it**, registering the `App\` PSR-4 mapping by hand so the test stays offline.

## Developer documentation

`docs/` holds 26 self-contained HTML pages — open `docs/index.html` directly, no server or toolchain needed. Regenerate with `php docs/build.php`.

They are also served by the running app at **`/docs`**, linked from the masthead. That works via a second `ServeStaticFiles` mounted with `prefix: '/docs'` pointing at `docs/`, rather than copying generated HTML into `public/` — one source of truth, nothing duplicated into the document root. A symlink would not work: `ServeStaticFiles` resolves with `realpath()` and refuses anything outside its root.

Page sources are `docs/pages/*.php`, each returning `['slug', 'title', 'nav'?, 'summary', 'body']`. Bodies are nowdoc HTML with fenced code blocks (`[[php]]…[[/php]]`, plus `bash`, `ini`, `html`, `text`). **PHP samples are highlighted with `token_get_all()`**, not a regex — what you see coloured is what the parser sees. The build fails if `NAV` in `build.php` names a page that does not exist, so the sidebar cannot develop dead links.

When you change an API, update the page that documents it. Several examples in these docs were wrong on the first pass (a `LoginThrottle::keyFor()` that never existed, a PHPStan path list that overstated coverage) — both caught by checking the source rather than trusting memory, which is the habit to keep.

## Demo interface

**All CSS lives in two external stylesheets and there is no JavaScript at all** — no `<style>` blocks, no `style=` attributes, no `<script>`. Keep it that way: presentation belongs in `public/assets/app.css` (application) or `docs/assets/docs.css` (documentation). Where a value is data-driven, reach for an element that carries it natively — the self-check meter is a `<progress value max>` rather than a `<div>` with an inline width, which also means assistive technology reads it without an `aria-label` describing a `<div>`.

`public/assets/app.css` is a small design system sharing tokens with `docs/assets/docs.css`, so the application and its documentation read as one thing. **Dark is the base palette; light is a full token swap under `prefers-color-scheme`, not an inversion.** Anything new should use the tokens rather than literal colours.

Two layout widths: `main` is 74rem for the self-check page, whose tiles and check rows earn it, and `.prose` narrows text-and-form pages to 46rem. Wrap a new content page in `.prose` unless it has a reason not to.

The masthead logo is a `<picture>` with a `prefers-color-scheme` source, because the wordmark is a fixed colour in each variant. The CSS media query and the picture source key off the same signal, so they cannot disagree — but note that forcing dark *tokens* in a screenshot harness without also emulating the media feature will show the wrong logo. That is a test artifact, not a bug.

Self-check results are grouped by subsystem in `SelfCheckController::handle()`; add new checks to the group they belong to rather than to a flat list. Pass/fail is conveyed by the chip's label as well as its colour.

The five page controllers pass `currentPath` so the layout can mark the active nav item. A new page that appears in the nav needs it too.

## Brand assets

Sources live in `brand/`; `python3 brand/render.py` regenerates every PNG, the `.ico`, the two fixed-colour lockups, and copies the served vectors into `public/assets/brand/`. See `brand/README.md` for the concept, the palette and the verification command.

The demo site uses them: the lockup in the masthead, the mark on the sign-in page, the favicon set and web manifest in `<head>`, and the social card behind the Open Graph tags. `og:image` needs an absolute URL, which is why `APP_URL` is in `.env` and reaches templates through `TemplateEngine`'s shared data — supplied at construction, not through a setter, so a process-lifetime service never holds per-request state.

## Not built yet

- **Array-style file inputs** (`name="photos[]"`) — `FpmSapi` skips them rather than half-supporting PHP's transposed `$_FILES` arrays.
- **Streaming uploads in the built-in server** — the body is decoded from memory, bounding upload size there. Production targets stream to disk before PHP sees the request.
- **Chunked transfer encoding** in `RequestParser` — rejected with a clear 400.
- **Registration, password reset, email** — the demo seeds its account via `./orbit db:seed`.
- **Joins/aggregates in the query builder** — deliberately thin; `Connection::select()` with hand-written SQL is the escape hatch and the better tool for anything complex.
- **Concurrency in `OrbitServer`** — connections are served sequentially. Deliberate for a dev server, and the reason it is not a production target.
