<?php

declare(strict_types=1);

namespace PhpOrbit\Admin;

use Closure;
use PhpOrbit\Admin\Controllers\ClearStorageController;
use PhpOrbit\Admin\Controllers\GcSessionsController;
use PhpOrbit\Admin\Controllers\Generate\ClassPageController;
use PhpOrbit\Admin\Controllers\Generate\ClassSubmitController;
use PhpOrbit\Admin\Controllers\Generate\ControllerPageController;
use PhpOrbit\Admin\Controllers\Generate\ControllerSubmitController;
use PhpOrbit\Admin\Controllers\Generate\FormPageController;
use PhpOrbit\Admin\Controllers\Generate\FormSubmitController;
use PhpOrbit\Admin\Controllers\Generate\IndexController as GenerateIndexController;
use PhpOrbit\Admin\Controllers\Generate\MiddlewarePageController;
use PhpOrbit\Admin\Controllers\Generate\MiddlewareSubmitController;
use PhpOrbit\Admin\Controllers\Generate\MigrationPageController;
use PhpOrbit\Admin\Controllers\Generate\MigrationSubmitController;
use PhpOrbit\Admin\Controllers\GenerateKeyController;
use PhpOrbit\Admin\Controllers\MailController;
use PhpOrbit\Admin\Controllers\MigrationsController;
use PhpOrbit\Admin\Controllers\OverviewController;
use PhpOrbit\Admin\Controllers\ResendFailedMailController;
use PhpOrbit\Admin\Controllers\ResendMailController;
use PhpOrbit\Admin\Controllers\RollbackMigrationsController;
use PhpOrbit\Admin\Controllers\RoutesController;
use PhpOrbit\Admin\Controllers\RunMigrationsController;
use PhpOrbit\Admin\Controllers\SendTestMailController;
use PhpOrbit\Admin\Controllers\SessionsController;
use PhpOrbit\Admin\Controllers\StorageController;
use PhpOrbit\Admin\Controllers\ToolsController;
use PhpOrbit\Config\Environment;
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
use PhpOrbit\Routing\Route;
use PhpOrbit\Routing\RouteCollection;
use PhpOrbit\Security\CsrfMiddleware;
use PhpOrbit\Session\FileSessionStore;
use PhpOrbit\Session\SessionMiddleware;
use PhpOrbit\View\TemplateEngine;
use RuntimeException;

/**
 * Boots the admin dashboard — a second, self-contained application reading and
 * operating on the same project, never wired into `app/routes.php`.
 *
 * That separation is deliberate, not an oversight. Merging these routes into
 * the real route table would mean every deployed application ships a page that
 * can run migrations, resend mail and wipe the template cache unless a
 * developer remembers to strip it back out. Keeping it a second application,
 * started only by `orbit ui`, means the capability exists exactly when someone
 * chose to run it and never otherwise.
 *
 * There is deliberately no login here. What stands in for it: `orbit ui`
 * binds to `127.0.0.1` by default, the same as `orbit serve`, and the CLI
 * warns loudly if told to bind anywhere else. Treat this the way you would a
 * database console left open on your own machine — no different, no less
 * dangerous, and not something to put behind a public host or port forward.
 */
final class AdminApplication
{
    public static function boot(string $root): Application
    {
        $env = Environment::load($root . '/.env');
        // Same rule the real application follows: the real environment wins
        // over .env, so `orbit ui --debug` (which sets APP_DEBUG in the
        // process environment) behaves identically to APP_DEBUG=true in .env.
        $debug = $env->bool('APP_DEBUG', false);
        $storage = $root . '/storage';

        foreach (["$storage/sessions", "$storage/cache/admin-views"] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Cannot create storage directory "%s".', $directory));
            }
        }

        // The same database the project itself uses — administering a second,
        // disconnected copy would show stale numbers and resend mail nobody
        // could find afterwards.
        $database = Connection::connect(DatabaseSettings::fromEnvironment($env, $root));
        $logger = StreamLogger::standardError(Level::fromName($env->string('LOG_LEVEL', 'info')));

        $templates = new TemplateEngine(
            __DIR__ . '/templates',
            $storage . '/cache/admin-views',
            alwaysRecompile: $debug,
            shared: ['sapi' => PHP_SAPI, 'phpVersion' => PHP_VERSION],
        );

        // Wrapped exactly as app/bootstrap.php wraps it, so a resend triggered
        // from a button here behaves identically to `orbit mail:resend` — the
        // same driver, the same log, the same row updated in place.
        $mailer = new PersistingMailer(MailerFactory::fromEnvironment($env), new MailLogRepository($database));

        return Application::boot(
            static function (Blueprint $app) use ($root, $storage, $database, $logger, $templates, $mailer, $env, $debug): void {
                $app->container->singleton(ProjectPaths::class, static fn (): ProjectPaths => new ProjectPaths($root));
                $app->container->singleton(Environment::class, static fn (): Environment => $env);
                $app->container->singleton(Connection::class, static fn (): Connection => $database);
                $app->container->singleton(Logger::class, static fn (): Logger => $logger);
                $app->container->singleton(TemplateEngine::class, static fn (): TemplateEngine => $templates);
                $app->container->singleton(
                    Migrator::class,
                    static fn (): Migrator => new Migrator($database, $root . '/database/migrations'),
                );
                $app->container->singleton(Mailer::class, static fn (): Mailer => $mailer);
                $app->container->singleton(PersistingMailer::class, static fn (): PersistingMailer => $mailer);

                // A distinct cookie name, not the project's SESSION_COOKIE: cookies
                // are scoped by host, not port, so `orbit serve` and `orbit ui`
                // running side by side on 127.0.0.1 would otherwise silently
                // share — and overwrite — one another's session cookie.
                $app->middleware(
                    new LogRequests($logger),
                    new ServeStaticFiles(__DIR__ . '/assets', maxAgeSeconds: $debug ? 0 : 3600, prefix: '/assets'),
                    new SessionMiddleware(
                        new FileSessionStore($storage . '/sessions'),
                        cookieName: 'orbit_admin_session',
                        lifetimeSeconds: $env->int('SESSION_LIFETIME', 7200),
                    ),
                    new CsrfMiddleware(),
                    new TransactionGuard(static function (string $message) use ($logger): void {
                        $logger->log(Level::Error, $message);
                    }),
                );

                $routes = $app->routes;

                $routes->get('/', OverviewController::class, 'admin.overview');

                $routes->get('/migrations', MigrationsController::class, 'admin.migrations');
                $routes->post('/migrations/run', RunMigrationsController::class, 'admin.migrations.run');
                $routes->post('/migrations/rollback', RollbackMigrationsController::class, 'admin.migrations.rollback');

                $routes->get('/mail', MailController::class, 'admin.mail');
                $routes->post('/mail/{id:\d+}/resend', ResendMailController::class, 'admin.mail.resend');
                $routes->post('/mail/resend-failed', ResendFailedMailController::class, 'admin.mail.resend-failed');

                $routes->get('/routes', RoutesController::class, 'admin.routes');

                $routes->get('/sessions', SessionsController::class, 'admin.sessions');
                $routes->post('/sessions/gc', GcSessionsController::class, 'admin.sessions.gc');

                $routes->get('/storage', StorageController::class, 'admin.storage');
                $routes->post('/storage/clear', ClearStorageController::class, 'admin.storage.clear');

                $routes->get('/generate', GenerateIndexController::class, 'admin.generate');
                $routes->get('/generate/class', ClassPageController::class, 'admin.generate.class');
                $routes->post('/generate/class', ClassSubmitController::class, 'admin.generate.class.submit');
                $routes->get('/generate/controller', ControllerPageController::class, 'admin.generate.controller');
                $routes->post('/generate/controller', ControllerSubmitController::class, 'admin.generate.controller.submit');
                $routes->get('/generate/form', FormPageController::class, 'admin.generate.form');
                $routes->post('/generate/form', FormSubmitController::class, 'admin.generate.form.submit');
                $routes->get('/generate/middleware', MiddlewarePageController::class, 'admin.generate.middleware');
                $routes->post('/generate/middleware', MiddlewareSubmitController::class, 'admin.generate.middleware.submit');
                $routes->get('/generate/migration', MigrationPageController::class, 'admin.generate.migration');
                $routes->post('/generate/migration', MigrationSubmitController::class, 'admin.generate.migration.submit');

                $routes->get('/tools', ToolsController::class, 'admin.tools');
                $routes->post('/tools/key', GenerateKeyController::class, 'admin.tools.key');
                $routes->post('/tools/mail-test', SendTestMailController::class, 'admin.tools.mail-test');
            },
            debug: $debug,
        );
    }

    /**
     * Reads the project's real route table without ever serving it — a fresh
     * RouteCollection is compiled from app/routes.php and thrown away, so the
     * admin app's own router never risks colliding with it.
     *
     * @return list<Route>
     */
    public static function projectRoutes(ProjectPaths $paths, bool $debug): array
    {
        $path = $paths->routesFile();

        if (!is_file($path)) {
            return [];
        }

        /** @var mixed $define */
        $define = require $path;

        if (!$define instanceof Closure) {
            return [];
        }

        $routes = new RouteCollection();
        $define($routes, $debug);

        return $routes->compile()->routes();
    }
}
