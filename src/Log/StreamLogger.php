<?php

declare(strict_types=1);

namespace PhpOrbit\Log;

use RuntimeException;
use InvalidArgumentException;

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
    public function __construct(
        $stream,
        private readonly Level $minimum = Level::Info,
        /** @var array<string, scalar|null|array<array-key, mixed>> */
        private readonly array $context = [],
        private readonly bool $ownsStream = false,
    ) {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('A logger requires an open stream.');
        }
        $this->stream = $stream;
    }

    public function __destruct()
    {
        if ($this->ownsStream && is_resource($this->stream)) {
            fclose($this->stream);
        }
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

        $context = $this->redact([...$context, ...$this->context]);
        $line = json_encode(
            [
                'time' => gmdate('c'),
                'level' => $level->value,
                'message' => $message,
                ...($context === [] ? [] : ['context' => $context]),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if (!is_resource($this->stream)) {
            throw new RuntimeException('Cannot write to a closed log stream.');
        }
        $stat = fstat($this->stream);
        $regularFile = stream_get_meta_data($this->stream)['wrapper_type'] === 'plainfile'
            && $stat !== false && ($stat['mode'] & 0170000) === 0100000;
        if ($regularFile && !flock($this->stream, LOCK_EX)) {
            throw new RuntimeException('Cannot lock log output.');
        }
        try {
            $line .= "\n";
            $offset = 0;
            do {
                $written = fwrite($this->stream, substr($line, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write log entry.');
                }
                $offset += $written;
            } while ($offset < strlen($line));
            if ($regularFile && !fflush($this->stream)) {
                throw new RuntimeException('Cannot flush log output.');
            }
        } finally {
            if ($regularFile && !flock($this->stream, LOCK_UN)) {
                throw new RuntimeException('Cannot unlock log output.');
            }
        }
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, scalar|null|array<array-key, mixed>>
     */
    private function redact(array $values, int $depth = 0): array
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('Log context must not exceed 32 nested array levels.');
        }
        $result = [];
        foreach ($values as $key => $value) {
            $normalised = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string) $key) ?? '');
            if (preg_match('/(?:password|passwordhash|passwordconfirmation|secret|token|apikey|sessionid|privatekey|recoverycodes?)$/D', $normalised) === 1 || in_array($normalised, [
                'appkey', 'authorization', 'proxyauthorization', 'cookie', 'setcookie',
                'session', 'credentials', 'apppreviouskeys',
            ], true)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->redact($value, $depth + 1);
            } elseif (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            } else {
                throw new InvalidArgumentException('Log context may contain only scalars, null and arrays.');
            }
        }

        return $result;
    }
}
