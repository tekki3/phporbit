<?php

declare(strict_types=1);

namespace PhpOrbit\Sapi;

use PhpOrbit\Kernel\Application;
use RuntimeException;

/**
 * FrankenPHP worker mode.
 *
 * The application boots once and then this loop serves request after request
 * in the same process. Everything the framework does to stay worker-safe
 * exists because of this file: a leak here is visible to the next user to hit
 * the same worker, not merely a memory problem.
 *
 * Request capture reuses {@see FpmSapi} because FrankenPHP repopulates the
 * superglobals for each iteration of the loop.
 */
final class FrankenPhpSapi implements Sapi
{
    public function __construct(
        private readonly FpmSapi $capture = new FpmSapi(),
        private readonly Emitter $emitter = new Emitter(),
        private readonly ?int $maxRequests = null,
    ) {
    }

    public static function isAvailable(): bool
    {
        return function_exists('frankenphp_handle_request');
    }

    public function run(Application $app): void
    {
        if (!self::isAvailable()) {
            throw new RuntimeException(
                'frankenphp_handle_request() is unavailable. This adapter only runs under '
                . 'FrankenPHP worker mode; use FpmSapi or OrbitServer instead.',
            );
        }

        $served = 0;

        $handle = function () use ($app): void {
            $this->emitter->emit($app->handle($this->capture->captureRequest()));
        };

        do {
            /** @var callable(): void $handle */
            $keepRunning = frankenphp_handle_request($handle);
            $served++;

            // Cycles are collected explicitly: a long-lived worker that never
            // collects will grow steadily even without a genuine leak.
            gc_collect_cycles();

            // Recycling after a bounded number of requests bounds the blast
            // radius of any leak that does slip through.
            if ($this->maxRequests !== null && $served >= $this->maxRequests) {
                break;
            }
        } while ($keepRunning);
    }
}
