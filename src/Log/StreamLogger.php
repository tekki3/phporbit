<?php

declare(strict_types=1);

namespace PhpOrbit\Log;

use RuntimeException;

/**
 * Writes one JSON object per line to a stream.
 *
 * Line-delimited JSON rather than prose: a log line containing a newline from
 * user input would otherwise forge a second entry, and structured output
 * survives being piped into anything that aggregates it.
 */
final class StreamLogger implements Logger
{
    /** @var resource */
    private $stream;

    /**
     * @param resource $stream
     */
    public function __construct($stream, private readonly Level $minimum = Level::Info)
    {
        $this->stream = $stream;
    }

    /**
     * Logs to the process's standard error, on any SAPI.
     *
     * The `STDERR` constant only exists under the CLI SAPI — referring to it
     * anywhere that also runs under FPM, Apache or the built-in web server is
     * a fatal error at boot. The `php://stderr` wrapper is always available and
     * lands where each host expects: the terminal under the CLI, the pool's
     * error log under FPM, the server's error log under Apache.
     *
     * The handle is deliberately never closed. It is the process's own stream,
     * shared with anything else that writes to it, and closing it would take
     * error reporting away from the rest of the request.
     */
    public static function standardError(Level $minimum = Level::Info): self
    {
        $stream = fopen('php://stderr', 'wb');

        if ($stream === false) {
            throw new RuntimeException('Cannot open php://stderr for logging.');
        }

        return new self($stream, $minimum);
    }

    public function log(Level $level, string $message, array $context = []): void
    {
        if ($level->severity() < $this->minimum->severity()) {
            return;
        }

        $line = json_encode(
            [
                'time' => gmdate('c'),
                'level' => $level->value,
                'message' => $message,
                ...($context === [] ? [] : ['context' => $context]),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        fwrite($this->stream, $line . PHP_EOL);
    }
}
