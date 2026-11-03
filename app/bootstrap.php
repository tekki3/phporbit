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
use App\Middleware\HtmlErrorPages;
use App\Support\ScopedProbe;
use App\Support\WorkerStats;
use App\Support\AuthEmails;
use PhpOrbit\Auth\Authenticator;
use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Auth\PasswordHasher;
use PhpOrbit\Auth\UserProvider;
use PhpOrbit\Auth\Identity;
use PhpOrbit\Auth\SecurityState;
use PhpOrbit\Auth\Mfa;
use PhpOrbit\Auth\EmailTokens;
use PhpOrbit\Auth\Oidc\OidcProviders;
use PhpOrbit\Auth\Oidc\OidcHttp;
use PhpOrbit\Auth\Oidc\CurlOidcHttp;
use PhpOrbit\Cache\Cache;
use PhpOrbit\Cache\CacheFactory;
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
use PhpOrbit\Log\LoggerFactory;
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
        // DB_DRIVER selects sqlite, mysql, pgsql or sqlsrv; the settings are validated
        // here so a bad host or a missing database name stops the application
        // starting rather than failing on whichever request queries first.
        $database = Connection::connect(DatabaseSettings::fromEnvironment($env, $root));

        // Points every Model subclass at the same connection registered
        // below. Safe under a worker for the same reason the singleton
        // registration is: one Connection, shared by every request in the
        // process, never a per-request value cached statically.
        Model::useConnection($database);

        // Stream wrappers work on every SAPI; CLI-only constants do not.
        $logger = LoggerFactory::fromEnvironment($env, $root);
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

        // MAIL_QUEUE defers delivery while still recording complete messages
        // in mail_log; orbit mail:send-pending performs the actual sends.
        $mailer = new PersistingMailer(
            MailerFactory::fromEnvironment($env),
            new MailLogRepository($database),
            $env->bool('MAIL_QUEUE', false),
        );

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
        $app->container->singleton(
            Cache::class,
            static fn (): Cache => CacheFactory::fromEnvironment($env, $root),
        );
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
        $securityState = new SecurityState($database);
        $providers = OidcProviders::fromEnvironment($env);
        $app->container->singleton(SecurityState::class, static fn (): SecurityState => $securityState);
        $app->container->singleton(OidcProviders::class, static fn (): OidcProviders => $providers);
        $app->container->singleton(OidcHttp::class, static fn (): OidcHttp => new CurlOidcHttp());
        $app->container->singleton(Mfa::class, static fn (): Mfa => new Mfa(
            $database, $securityState, static fn (): Encrypter => CryptoFactory::encrypterFromEnvironment($env),
        ));
        $app->container->scoped(Authenticator::class, static fn (RequestScope $scope): Authenticator => new Authenticator(
            $scope->get(Session::class), $users, $hasher,
            static fn (Identity $user): string => hash('sha256', $user->passwordHash() . ':' . $securityState->version($user->authIdentifier())),
        ));
        // Login links must not enter the ordinary, readable mail audit/queue.
        $app->container->scoped(AuthEmails::class, static fn (RequestScope $scope): AuthEmails => new AuthEmails(
            $scope->get(EmailTokens::class), $users, $scope->get(LoginThrottle::class),
            MailerFactory::fromEnvironment($env), $env, $logger,
        ));
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

        // Order matters. HtmlErrorPages is outermost so LogRequests — next in
        // line — still records a thrown exception's full detail before it is
        // turned into an opaque page; static files short-circuit before any
        // session work; CSRF must follow the session that holds its token.
        $middleware = [
            new HtmlErrorPages($templates, $debug),
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
