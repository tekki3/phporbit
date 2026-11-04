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

        $stream = $response->status->allowsBody() ? $response->stream : null;
        if ($stream !== null && ob_get_level() > 1) {
            throw new RuntimeException('Streaming requires at most one PHP output buffer. Disable nested buffering for this route.');
        }
        $buffer = ob_get_status();
        $flags = $buffer['flags'] ?? null;
        if ($stream !== null && $buffer !== [] && (!is_int($flags) || ($flags & PHP_OUTPUT_HANDLER_FLUSHABLE) === 0)) {
            throw new RuntimeException('Streaming requires a flushable PHP output buffer.');
        }
        $chunks = $stream?->chunks();
        // Start the producer before committing headers, so setup failures are not a partial 200.
        $chunks?->rewind();
        $body = $stream === null ? $response->wireBody() : '';

        http_response_code($response->status->value);

        $seen = [];
        foreach ($response->headers->toWire() as [$name, $value]) {
            $key = strtolower($name);
            if ($stream !== null && in_array($key, ['content-length', 'transfer-encoding'], true)) {
                continue;
            }
            // First write replaces, subsequent writes of the same field append,
            // which is how multi-value headers such as Set-Cookie survive.
            header($name . ': ' . $value, replace: !isset($seen[$key]));
            $seen[$key] = true;
        }

        if ($response->status->allowsBody() && $stream === null) {
            header('Content-Length: ' . strlen($body));
        }

        if ($chunks === null) {
            echo $body;
            return;
        }
        $previous = ignore_user_abort(true);
        try {
            foreach ($chunks as $chunk) {
                echo $chunk;
                if (ob_get_level() > 0 && !ob_flush()) {
                    throw new RuntimeException('Cannot flush the response output buffer.');
                }
                flush();
                if (connection_aborted() !== 0) {
                    throw new RuntimeException('The client disconnected during response streaming.');
                }
            }
        } finally {
            ignore_user_abort($previous !== 0);
        }
    }
}
