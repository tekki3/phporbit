<?php

declare(strict_types=1);

/**
 * Route declarations.
 *
 * Loaded from app/bootstrap.php during the boot phase, so these still land
 * before the route table is compiled and the container frozen. Living in their
 * own file changes *where* routes are written, not *when* they take effect.
 */

use App\Controllers\WelcomeController;
use PhpOrbit\Http\Response;
use PhpOrbit\Routing\RouteCollection;

return static function (RouteCollection $routes, bool $debug): void {
    $routes->get('/', WelcomeController::class, 'home');

    $routes->get('/health', static fn (): Response => Response::json([
        'status' => 'ok',
        'sapi' => PHP_SAPI,
    ]), 'health');
};
