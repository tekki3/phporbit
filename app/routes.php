<?php

declare(strict_types=1);

/**
 * The application's routes.
 *
 * Loaded by `app/bootstrap.php` during the boot phase, so these still land
 * before the route table is compiled and the container frozen. Living in their
 * own file changes where they are written, not when they take effect.
 *
 * Route names are the second argument and are worth setting: they are what
 * `Router::urlFor()` builds links from, so a path can change in one place.
 * `./orbit routes` prints the compiled table.
 */

use App\Controllers\AvatarController;
use App\Controllers\ContactController;
use App\Controllers\CreateNoteController;
use App\Controllers\DeleteNoteController;
use App\Controllers\SubmitContactController;
use App\Controllers\HealthController;
use App\Controllers\HelloController;
use App\Controllers\LoginAttemptController;
use App\Controllers\LoginController;
use App\Controllers\LogoutController;
use App\Controllers\NotesController;
use App\Controllers\SelfCheckController;
use App\Controllers\StoreAvatarController;
use PhpOrbit\Auth\RequireAuthentication;
use PhpOrbit\Http\Response;
use PhpOrbit\Routing\RouteCollection;

return static function (RouteCollection $routes, bool $debug): void {

    // ---------------------------------------------------------------- public
    // Readable by anyone. Note that /notes is public but writing to it is not:
    // the guarded block below covers the POSTs to the same path.

    $routes->get('/', SelfCheckController::class, 'self-check');
    $routes->get('/notes', NotesController::class, 'notes.index');
    $routes->get('/hello/{name}', HelloController::class, 'hello');

    // The generated form: one declaration renders it, validates it, and carries
    // its honeypot and captcha.
    $routes->get('/contact', ContactController::class, 'contact');
    $routes->post('/contact', SubmitContactController::class, 'contact.submit');
    $routes->get('/health', HealthController::class, 'health');

    // ------------------------------------------------------------------ auth
    // Signing in and out. Sign-out is a POST rather than a link because a link
    // can be triggered from anywhere, and CSRF only guards state-changing
    // methods.

    $routes->get('/login', LoginController::class, 'login');
    $routes->post('/login', LoginAttemptController::class, 'login.attempt');
    $routes->post('/logout', LogoutController::class, 'logout');

    // ------------------------------------------------------- requires a user
    // Stated once for the whole block. A guard repeated on each line is a
    // guard that eventually gets left off one of them.

    $routes->withMiddleware([new RequireAuthentication()], static function (RouteCollection $routes): void {
        $routes->post('/notes', CreateNoteController::class, 'notes.create');
        $routes->post('/notes/{id:\d+}/delete', DeleteNoteController::class, 'notes.delete');

        $routes->get('/avatar', AvatarController::class, 'avatar');
        $routes->post('/avatar', StoreAvatarController::class, 'avatar.store');
    });

    // ----------------------------------------------------------- debug only
    // Never registered when APP_DEBUG is off, so it cannot be reached in
    // production even by guessing the path. It exists to demonstrate that a
    // handler failure returns an opaque 500.

    if ($debug) {
        $routes->get('/boom', static function (): Response {
            throw new RuntimeException('deliberate failure with a secret: hunter2');
        }, 'boom');
    }
};
