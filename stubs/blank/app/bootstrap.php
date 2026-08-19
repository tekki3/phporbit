<?php

declare(strict_types=1);

/**
 * The boot phase, shared by every deployment target.
 *
 * All four entrypoints — the built-in server, FrankenPHP, nginx+FPM and
 * Apache — require this one file and get an identical application back. Keep it
 * free of anything environment-specific; that belongs in a SAPI adapter.
 *
 * Everything here runs exactly once per process. Under a worker that means the
 * `.env` is read, connections opened and templates located once, then reused
 * for every request the process goes on to serve. When this callback returns,
 * the route table is compiled and the container frozen — permanently.
 */

use PhpOrbit\Config\Environment;
use PhpOrbit\Crypto\CryptoFactory;
use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Crypto\Signer;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\DatabaseSettings;
use PhpOrbit\Database\Migrator;
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
use PhpOrbit\Session\SessionMiddleware;
use PhpOrbit\View\TemplateEngine;

$root = dirname(__DIR__);

// Read once, at boot. Values already present in the real environment win over
// the file, so a platform-injected secret is never shadowed by a stale .env.
$env = Environment::load($root . '/.env');

$debug = $env->bool('APP_DEBUG', false);

return Application::boot(
    static function (Blueprint $app) use ($env, $debug, $root): void {
        $storage = $root . '/storage';

        foreach (["$storage/sessions", "$storage/cache/views"] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Cannot create storage directory "%s".', $directory));
            }
        }

        // Built once per process and shared. Opening a connection or locating
        // templates per request would waste the worker model entirely.
        $database = Connection::connect(DatabaseSettings::fromEnvironment($env, $root));

        // Not the STDERR constant: it exists only under the CLI SAPI, so using
        // it here would fatal at boot under FPM, Apache and php -S.
        $logger = StreamLogger::standardError(Level::fromName($env->string('LOG_LEVEL', 'info')));

        $templates = new TemplateEngine(
            $root . '/app/templates',
            $storage . '/cache/views',
            // In production a template compiles once; in debug every render
            // picks up edits without clearing the cache by hand.
            alwaysRecompile: $debug,
            // Values every page needs, supplied at construction rather than
            // through a setter — a mutable bag on a process-lifetime service
            // would leak one request's values into the next.
            shared: [
                'appUrl' => rtrim($env->string('APP_URL', 'http://localhost:8080'), '/'),
                'sapi' => PHP_SAPI,
                'phpVersion' => PHP_VERSION,
            ],
        );

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
        // Also bound under its concrete type: orbit mail:resend needs resend(),
        // which Mailer does not declare.
        $app->container->singleton(PersistingMailer::class, static fn (): PersistingMailer => $mailer);
        $app->container->singleton(Connection::class, static fn (): Connection => $database);
        $app->container->singleton(Logger::class, static fn (): Logger => $logger);
        $app->container->singleton(TemplateEngine::class, static fn (): TemplateEngine => $templates);
        $app->container->singleton(
            Migrator::class,
            static fn (): Migrator => new Migrator($database, $root . '/database/migrations'),
        );

        // Order matters. Logging is outermost so it times everything; static
        // files short-circuit before any session work; CSRF must follow the
        // session that holds its token.
        $app->middleware(
            new LogRequests($logger),
            new ServeStaticFiles($root . '/public', maxAgeSeconds: $debug ? 0 : 3600),
            new SessionMiddleware(
                new FileSessionStore($storage . '/sessions'),
                cookieName: $env->string('SESSION_COOKIE', 'orbit_session'),
                lifetimeSeconds: $env->int('SESSION_LIFETIME', 7200),
            ),
            new CsrfMiddleware(),
            // The connection outlives the request, so a transaction a handler
            // forgot to commit would otherwise be inherited by the next one.
            new TransactionGuard(static function (string $message) use ($logger): void {
                $logger->log(Level::Error, $message);
            }),
        );

        // Routes live in their own file, loaded here inside the boot callback
        // so they still land before the table is compiled.
        $app->loadRoutes($root . '/app/routes.php');
    },
    debug: $debug,
);
