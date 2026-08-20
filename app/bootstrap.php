<?php

declare(strict_types=1);

/**
 * The boot phase, shared by every deployment target.
 *
 * All four entrypoints — the built-in server, FrankenPHP, nginx+FPM and
 * Apache — require this one file and get an identical application back. Keep
 * it free of anything environment-specific; that belongs in the SAPI adapter.
 *
 * Everything here runs exactly once per process. Under a worker that means
 * the `.env` file is read, connections opened and templates located once, then
 * reused for every request the process goes on to serve.
 *
 * Note what is *not* here: schema changes. Migrations are applied by
 * `./orbit migrate`, because several workers booting at once would otherwise
 * race to alter the same tables.
 */

use App\Auth\DatabaseUserProvider;
use App\Controllers\StoreAvatarController;
use App\Support\ScopedProbe;
use App\Support\WorkerStats;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Config\Environment;
use PhpOrbit\Container\RequestScope;
use PhpOrbit\Crypto\CryptoFactory;
use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Crypto\Signer;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\DatabaseSettings;
use PhpOrbit\Database\Migrator;
use PhpOrbit\Database\Model;
use PhpOrbit\Database\TransactionGuard;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Log\Level;
use PhpOrbit\Log\LogRequests;
use PhpOrbit\Log\Logger;
use PhpOrbit\Log\StreamLogger;
use PhpOrbit\Mail\Mailer;
use PhpOrbit\Mail\MailerFactory;
use PhpOrbit\Mail\MailLogRepository;
use PhpOrbit\Mail\PersistingMailer;
use PhpOrbit\Middleware\ServeStaticFiles;
use PhpOrbit\Security\CsrfMiddleware;
use PhpOrbit\Session\FileSessionStore;
use PhpOrbit\Session\Session;
use PhpOrbit\Session\SessionMiddleware;
use PhpOrbit\View\TemplateEngine;

$root = dirname(__DIR__);

// Read once, at boot. Values already present in the real environment win over
// the file, so a platform-injected secret is never shadowed by a stale .env.
$env = Environment::load($root . '/.env');

$debug = $env->bool('APP_DEBUG', false);

return Application::boot(
    static function (Blueprint $app) use ($env, $debug, $root): void {
        // Documentation is optional: a scaffolded project has none.
        $hasDocs = is_dir($root . '/docs');

        $storage = $root . '/storage';
        $avatars = $root . '/public/avatars';

        foreach (["$storage/sessions", "$storage/cache/views", $avatars] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Cannot create storage directory "%s".', $directory));
            }
        }

        // Built once per process and shared. Opening a database connection,
        // locating templates or deriving a password-hash decoy per request
        // would waste the worker model — the hasher's constructor alone costs
        // a full Argon2 computation.
        // DB_DRIVER selects sqlite, mysql or pgsql; the settings are validated
        // here so a bad host or a missing database name stops the application
        // starting rather than failing on whichever request queries first.
        $database = Connection::connect(DatabaseSettings::fromEnvironment($env, $root));

        // Points every Model subclass at the same connection registered
        // below. Safe under a worker for the same reason the singleton
        // registration is: one Connection, shared by every request in the
        // process, never a per-request value cached statically.
        Model::useConnection($database);

        // Not the STDERR constant: it exists only under the CLI SAPI, and this
        // file has to boot identically on all four targets.
        $logger = StreamLogger::standardError(Level::fromName($env->string('LOG_LEVEL', 'info')));
        $hasher = new PasswordHasher();
        $templates = new TemplateEngine(
            $root . '/app/templates',
            $storage . '/cache/views',
            // In production a template is compiled once; in debug every render
            // picks up edits without clearing the cache by hand.
            alwaysRecompile: $debug,
            // Site-wide values every page needs. APP_URL has to be absolute
            // because link-preview scrapers do not resolve relative image paths.
            shared: [
                'appUrl' => rtrim($env->string('APP_URL', 'http://localhost:8080'), '/'),
                // Lets the layout omit the documentation link entirely
                // rather than render one that 404s.
                'hasDocs' => $hasDocs,
                'sapi' => PHP_SAPI,
                'phpVersion' => PHP_VERSION,
            ],
        );
        $users = new DatabaseUserProvider($database, $hasher);

        // MAIL_DRIVER picks the implementation; "array" collects messages in
        // memory rather than sending them, which is the default. Wrapped in
        // PersistingMailer so every send — and its outcome — lands in
        // mail_log regardless of driver, and a failed one can be resent with
        // `orbit mail:resend`.
        $mailer = new PersistingMailer(MailerFactory::fromEnvironment($env), new MailLogRepository($database));

        // Resolved on first use, not at boot: an application that never encrypts
        // anything never needs an APP_KEY.
        $app->container->singleton(
            Encrypter::class,
            static fn (): Encrypter => CryptoFactory::encrypterFromEnvironment($env),
        );
        $app->container->singleton(
            Signer::class,
            static fn (): Signer => CryptoFactory::signerFromEnvironment($env),
        );

        $app->container->singleton(Environment::class, static fn (): Environment => $env);
        $app->container->singleton(Mailer::class, static fn (): Mailer => $mailer);
        // Also bound under its concrete type, the same way DatabaseUserProvider
        // is below — orbit mail:resend needs resend(), which Mailer does not
        // declare.
        $app->container->singleton(PersistingMailer::class, static fn (): PersistingMailer => $mailer);
        $app->container->singleton(Connection::class, static fn (): Connection => $database);
        $app->container->singleton(Logger::class, static fn (): Logger => $logger);
        $app->container->singleton(TemplateEngine::class, static fn (): TemplateEngine => $templates);
        $app->container->singleton(PasswordHasher::class, static fn (): PasswordHasher => $hasher);
        $app->container->singleton(WorkerStats::class, static fn (): WorkerStats => new WorkerStats());
        $app->container->singleton(
            Migrator::class,
            static fn (): Migrator => new Migrator($database, $root . '/database/migrations'),
        );

        // Interfaces can never be autowired, so the binding is explicit.
        $app->container->singleton(UserProvider::class, static fn (): UserProvider => $users);
        $app->container->singleton(DatabaseUserProvider::class, static fn (): DatabaseUserProvider => $users);
        $app->container->singleton(
            LoginThrottle::class,
            static fn (): LoginThrottle => new LoginThrottle(
                $database,
                maxAttempts: $env->int('LOGIN_MAX_ATTEMPTS', 5),
                windowSeconds: $env->int('LOGIN_WINDOW_SECONDS', 900),
            ),
        );

        // Scoped: a fresh instance per request, which is what makes the
        // isolation check on the self-check page meaningful.
        $app->container->scoped(ScopedProbe::class, static fn (): ScopedProbe => new ScopedProbe());

        // Takes a directory path, which autowiring cannot invent. The factory
        // resolves its collaborators from the live request scope, so it sees
        // the session that middleware published.
        $uploadLimit = $env->int('UPLOAD_MAX_BYTES', 1024 * 1024);

        $app->container->scoped(
            StoreAvatarController::class,
            static fn (RequestScope $scope): StoreAvatarController => new StoreAvatarController(
                $scope->get(Authenticator::class),
                $users,
                $scope->get(Session::class),
                $avatars,
                $uploadLimit,
            ),
        );

        $cacheSeconds = $debug ? 0 : 3600;

        // Order matters. Logging is outermost so it times everything; static
        // files short-circuit before any session work; CSRF must follow the
        // session that holds its token.
        $middleware = [
            new LogRequests($logger),
            new ServeStaticFiles($root . '/public', maxAgeSeconds: $cacheSeconds),
        ];

        // The developer documentation is served from docs/ rather than copied
        // into public/: one source of truth, and no generated HTML duplicated
        // into the document root. Mounted only when the directory is present,
        // so a project scaffolded without documentation has no /docs route and
        // no dead link to it.
        if ($hasDocs) {
            $middleware[] = new ServeStaticFiles($root . '/docs', maxAgeSeconds: $cacheSeconds, prefix: '/docs');
        }

        $middleware[] = new SessionMiddleware(
            new FileSessionStore($storage . '/sessions'),
            cookieName: $env->string('SESSION_COOKIE', 'orbit_session'),
            lifetimeSeconds: $env->int('SESSION_LIFETIME', 7200),
        );
        $middleware[] = new CsrfMiddleware();
        $middleware[] = new TransactionGuard(static function (string $message) use ($logger): void {
            $logger->log(Level::Error, $message);
        });

        $app->middleware(...$middleware);

        // Routes live in their own file. Loaded here, inside the boot callback,
        // so they still land before the table is compiled and the container
        // frozen.
        $app->loadRoutes($root . '/app/routes.php');
    },
    debug: $debug,
);
