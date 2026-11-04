<?php

declare(strict_types=1);

use PhpOrbit\Ai\Agent;
use PhpOrbit\Ai\Providers\OpenAI;
use PhpOrbit\Http\Response;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Log\Logger;
use PhpOrbit\Log\StreamLogger;
use PhpOrbit\Sapi\FpmSapi;
use PhpOrbit\Sapi\OrbitServer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$endpoint = getenv('AI_TEST_ENDPOINT');
$port = filter_var(getenv('AI_TEST_ORBIT_PORT'), FILTER_VALIDATE_INT);
if (!is_string($endpoint)) {
    throw new RuntimeException('AI_TEST_ENDPOINT is required.');
}
$app = Application::boot(static function (Blueprint $app) use ($endpoint): void {
    $app->container->singleton(Logger::class, static fn (): Logger => StreamLogger::standardError());
    $base = Agent::using(OpenAI::class)->withKey('synthetic-test-key')->model('test-model')->endpoint($endpoint)->prompt('Synthetic test prompt');
    $app->routes->get('/stream', static fn (): Response => $base->withStreaming()->response());
    $app->routes->get('/health', static fn (): Response => Response::text('healthy'));
});
($port !== false && $port > 0 ? new OrbitServer(port: $port) : new FpmSapi())->run($app);
