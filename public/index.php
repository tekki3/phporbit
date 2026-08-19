<?php

declare(strict_types=1);

/**
 * Production entrypoint for FrankenPHP, nginx+PHP-FPM and Apache.
 *
 * The adapter is chosen from the runtime, but the application above it is the
 * same object in every case. Point your web server's document root at this
 * directory and route all non-file requests here.
 *
 * The `.env` file lives one level up, outside the document root, so it cannot
 * be requested even if a rewrite rule is misconfigured.
 */

use PhpOrbit\Config\Environment;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Sapi\FpmSapi;
use PhpOrbit\Sapi\FrankenPhpSapi;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var Application $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

// Which proxies may be believed is configuration, not a constant: trusting
// X-Forwarded-Proto from anyone lets a client claim its plaintext request
// arrived over HTTPS and unlock Secure-only cookies.
$fpm = new FpmSapi(
    trustedProxies: $app->container()->get(Environment::class)->strings('TRUSTED_PROXIES'),
);

$sapi = FrankenPhpSapi::isAvailable() ? new FrankenPhpSapi($fpm) : $fpm;

$sapi->run($app);
