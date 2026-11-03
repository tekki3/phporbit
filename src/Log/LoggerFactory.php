<?php

declare(strict_types=1);

namespace PhpOrbit\Log;

use PhpOrbit\Config\Environment;
use RuntimeException;
use ValueError;

final class LoggerFactory
{
    public static function fromEnvironment(Environment $env, string $root): StreamLogger
    {
        $minimum = Level::fromName($env->string('LOG_LEVEL', 'info'));
        $destination = $env->string('LOG_OUTPUT', 'stderr');
        $path = match ($destination) {
            'stderr' => 'php://stderr',
            'stdout' => 'php://stdout',
            'file' => $env->path('LOG_PATH', $root, 'storage/logs/app.log'),
            default => throw new ValueError('Unknown LOG_OUTPUT. Use stderr, stdout or file.'),
        };
        if ($destination === 'file') {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
                throw new RuntimeException('Cannot create log directory.');
            }
        }
        $stream = fopen($path, 'ab');
        if ($stream === false) {
            throw new RuntimeException('Cannot open configured log output.');
        }

        return new StreamLogger($stream, $minimum, [
            'service' => $env->string('LOG_SERVICE', 'phporbit'),
            'environment' => $env->string('APP_ENV', 'production'),
        ], ownsStream: true);
    }
}
