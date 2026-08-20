# Working on this project

A **phporbit** application. phporbit is not Laravel, Symfony or Slim — several of
its conventions differ in ways that matter, and guessing from other PHP
frameworks produces code that fails the build or leaks user data.

Read this file before changing anything. Run `composer stan && composer test`
before reporting work as done.

## The rule everything else follows

This application runs on four targets, in two process models:

- **Per-request** (Apache, nginx+FPM) — the process dies after every response.
- **Long-lived worker** (`./orbit serve`, FrankenPHP) — one process serves
  thousands of requests.

Anything mutable that outlives a request is visible to **the next user**. Write
for the worker model and you are correct on both; the reverse is not true.

## Never

- **`$_SESSION`, `session_start()`** — PHP's sessions are process-global, so under
  a worker one visitor's data is still in memory when the next request arrives.
  Use the injected `Session`.
- **Superglobals** (`$_GET`, `$_POST`, `$_SERVER`, `$_FILES`) — read the injected
  `ServerRequest` instead. They are stale under FrankenPHP and absent under the
  built-in server.
- **`STDERR` / `STDOUT` / `STDIN`** outside `orbit` — undefined on every SAPI
  except the CLI, so they fatal at boot under a web server. Use
  `StreamLogger::standardError()`.
- **`static` mutable properties, or caching request data on a singleton** — that
  is the leak described above.
- **String-interpolated SQL** — no API accepts it. Bind values; whitelist
  identifiers.

## Service lifetimes

| Need | Use |
| --- | --- |
| Config, connections, compiled tables | `$app->container->singleton(...)` — shared by the whole process, must be stateless |
| Anything remembering *this* request | `$app->container->scoped(...)`, or just autowire it |
| A controller's dependencies | Nothing — constructor parameters are autowired per request |

Registering a service after boot throws. That is deliberate.

Generate rather than hand-write: `./orbit make:class Notes/NoteRepository` writes
an autowired class (nothing to register), and `--singleton` or `--scoped` prints
the `app/bootstrap.php` line to paste.

## Controllers and routes

- One class per route, implementing `PhpOrbit\Routing\Handler` with
  `handle(ServerRequest $request): Response`. There is no `[Controller, 'method']`
  form — a dynamic call returns `mixed` and defeats the type guarantees.
- Routes live in `app/routes.php`, declared on `$routes`. Run `./orbit routes` to
  see the compiled table.
- Generate rather than hand-write: `./orbit make:controller Admin/Users --view`.

## Templates

- `{{ $value }}` escapes. Use it for everything.
- `{!! $value !!}` does not escape — only for markup the application itself built.
- `@{{ ... }}` and `@{!! ... !!}` print the delimiters literally.
- Directives: `@extends`, `@section`/`@endsection`, `@yield`, `@include`, `@if`,
  `@foreach`, `@for`, `@while`. `{# comment #}`.

## Database

- `$database->query('table')` for the builder, `$database->select($sql, $params)`
  for hand-written SQL. Both bind values.
- An `UPDATE`/`DELETE` with no `where()` throws unless you call
  `affectingEveryRow()`.
- Migrations: `./orbit make:migration create_things_table`. Use
  `$database->driver()->autoIncrementPrimaryKey()` — the three supported engines
  spell it differently. Index `VARCHAR`, not `TEXT` (MySQL cannot index `TEXT`).
- `down()` is required. Throw `IrreversibleMigration` if a change cannot be undone.

## Models

`Note::find(1)`, `$note->save()`, `$note->delete()`, `Note::where(...)->get()` —
`./orbit make:model Note --fields=title:string,views:int` writes one. Built
entirely on the query builder above; nothing on `Model` composes SQL of its
own. No relationships, no eager loading, no scopes, no events — anything past
one table's typed columns is still `Connection::select()`. Not registered in
`app/bootstrap.php` and never resolved from the container — a model is reached
through its own static finders, not injected.

`Model::useConnection($database)` is called once, in `app/bootstrap.php`, right
next to where `Connection` is registered as a singleton — `make:model` prints
the line rather than writing it. That is the **one** exception to "no static
mutable properties" above: it is safe for the same reason the singleton
registration next to it is safe — one `Connection`, shared by every request in
the process either way. Do not call it a second time, and do not add a second
static anywhere else on `Model`'s account.

## Mail

`Message::to(...)->subject(...)->text(...)`, sent through the injected `Mailer`.
CR/LF in a subject, display name or address is refused — that is header
injection, and subjects are usually user-supplied. `MAIL_DRIVER=array` (the
default) collects messages instead of sending them; use `ArrayMailer` in tests
and assert with `sentTo()` / `last()`.

## Forms

`Form::post('/x')->add(Field::text('name')->required()->max(80))`. One
declaration produces the markup *and* the validation, so they cannot disagree —
do not re-state rules in the controller. A POST form gets a CSRF token
automatically. Everything rendered is escaped; there is no raw-HTML method.
`->protectWith(new Honeypot($signer))` and `->withCaptcha(new MathCaptcha($encrypter))`
add spam protection. `$submission->values()` throws unless it passed; use
`old()` and `errors()` to redisplay, and log `rejectedAs` rather than showing it.

## Encryption and signing

`Encrypter` for values that must stay secret, `Signer` for values that must not
change but are not secret, `PasswordHasher` for passwords. Encryption is
authenticated and the algorithm is not selectable. Pass a context to
`encrypt($value, 'users.email:42')` so a ciphertext cannot be moved to another
row. Both derive their keys from `APP_KEY` — generate one with
`orbit key:generate`, never invent one.

## Configuration

`Environment` has typed accessors — `string()`, `int()`, `bool()`, `required()`,
`path()`. There is no `get(): mixed` and no global `env()` helper. Read settings
at boot in `app/bootstrap.php` and pass concrete values into services.

## Things that do not exist

Do not invent these; they are not part of the framework:

- Facades or global helpers (`Route::get()`, `view()`, `config()`, `env()`, `dd()`)
- Relationships, eager loading, query scopes, or events on `Model` — see Models above
- Encrypted model attributes, or asymmetric crypto
- Blade, Twig, or `@csrf` / `@auth` directives
- Service providers, events, queues, or a scheduler
- Mail queuing or DKIM signing — `send()` is synchronous
- Joins or aggregates in the query builder beyond `count()` — drop to
  `Connection::select()` for those
- A login on `orbit ui` — it has none; binding to `127.0.0.1` (the default) is
  the only protection it has

## Commands

```bash
./orbit serve --debug                    # long-lived dev server
./orbit ui                               # admin dashboard, 127.0.0.1:8081, no login
#   /generate has a form for every make:* command; /tools has key:generate + mail:test
./orbit routes                           # compiled route table
./orbit make:class Name [--singleton|--scoped]
./orbit make:controller Name [--view]
./orbit make:form Name [--fields=a:text] [--captcha] [--controllers]
./orbit make:middleware Name             # prints the $app->middleware(...) entry to place
./orbit make:migration create_x_table
./orbit make:model Name [--fields=a:string,b:int]  # prints the Model::useConnection() line to place
./orbit migrate | migrate:status | migrate:rollback
./orbit storage:clear                    # wipe storage/cache/views (safe: recompiles on next render)
./orbit sessions:gc                      # delete expired session files
./orbit mail:test --to=x@example.test    # sends one real message via MAIL_DRIVER
./orbit mail:list [--status=failed]      # every send is logged to mail_log
./orbit mail:resend <id>|--failed        # resend, in place — attempts grows

composer test
composer stan                            # PHPStan at max level, no baseline
```

## Verification

Both gates must pass. PHPStan runs at max level with **no baseline and no
`ignoreErrors`** — a finding is a defect to fix, not a warning to record. Do not
suppress one with `@phpstan-ignore`, a cast, or a widened type.

Note what the gates *cannot* see: `$_SESSION`, a superglobal or a static mutable
property are all valid, well-typed PHP. Nothing here will fail because of them.
The rules above are yours to keep.

If you add behaviour that holds state, test it under the worker model: boot the
application **once** and handle **at least twice**, asserting the second request
is unaffected by the first. A state leak is invisible in a single-request test.
