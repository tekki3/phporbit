<?php

declare(strict_types=1);

namespace PhpOrbit\Sapi;

use PhpOrbit\Http\Response;
use RuntimeException;

/**
 * Writes a response using PHP's output layer.
 *
 * Shared by the FPM/Apache and FrankenPHP adapters, which differ in how they
 * obtain a request but not in how they answer one.
 */
final class Emitter
{
    public function emit(Response $response): void
    {
        if (headers_sent($file, $line)) {
            throw new RuntimeException(sprintf(
                'Output was already sent at %s:%d, so response headers cannot be written. '
                . 'Something echoed outside a handler — check for stray output before the entrypoint.',
                $file,
                $line,
            ));
        }

        $body = $response->wireBody();

        http_response_code($response->status->value);

        $seen = [];
        foreach ($response->headers->toWire() as [$name, $value]) {
            $key = strtolower($name);
            // First write replaces, subsequent writes of the same field append,
            // which is how multi-value headers such as Set-Cookie survive.
            header($name . ': ' . $value, replace: !isset($seen[$key]));
            $seen[$key] = true;
        }

        if ($response->status->allowsBody()) {
            header('Content-Length: ' . strlen($body));
        }

        echo $body;
    }
}
