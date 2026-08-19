<?php

declare(strict_types=1);

namespace PhpOrbit\Sapi;

use Closure;
use PhpOrbit\Http\Exception\MalformedRequest;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Status;
use PhpOrbit\Kernel\Application;
use RuntimeException;
use Throwable;

/**
 * phporbit serving itself over a socket.
 *
 * This is a real HTTP server sharing the exact request pipeline used in
 * production, not a router shim in front of `php -S`. It is also a long-lived
 * process, which is the point: development runs under the same process model
 * as FrankenPHP, so a state leak shows up on the developer's machine rather
 * than in production.
 *
 * Connections are served sequentially in one process. That is deliberate for a
 * development server — it keeps behaviour reproducible and stack traces
 * intact — and is the reason this adapter is not a production target.
 */
final class OrbitServer implements Sapi
{
    private const IDLE_TIMEOUT_SECONDS = 5;
    private const MAX_REQUESTS_PER_CONNECTION = 100;

    private bool $running = false;

    /** @var Closure(string): void */
    private readonly Closure $log;

    /**
     * @param (Closure(string): void)|null $log
     */
    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8080,
        private readonly RequestParser $parser = new RequestParser(),
        ?Closure $log = null,
    ) {
        // php://stderr rather than the STDERR constant. This adapter only runs
        // under the CLI today, where both work, but the constant is undefined
        // on every other SAPI and that is a trap for whoever reuses this next.
        $this->log = $log ?? static function (string $line): void {
            $stream = fopen('php://stderr', 'wb');

            if ($stream !== false) {
                fwrite($stream, $line . PHP_EOL);
                fclose($stream);
            }
        };
    }

    public function run(Application $app): void
    {
        $socket = @stream_socket_server(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf(
                'Cannot bind %s:%d — %s (%d).',
                $this->host,
                $this->port,
                $errstr,
                $errno,
            ));
        }

        $this->running = true;
        $this->installSignalHandlers();

        ($this->log)(sprintf(
            'phporbit listening on http://%s:%d (%s mode) — Ctrl-C to stop',
            $this->host,
            $this->port,
            $app->isDebug() ? 'debug' : 'production',
        ));

        try {
            while ($this->running) {
                // A timeout on accept keeps the loop responsive to signals
                // instead of blocking indefinitely.
                $connection = @stream_socket_accept($socket, 1);

                if ($connection === false) {
                    if (function_exists('pcntl_signal_dispatch')) {
                        pcntl_signal_dispatch();
                    }

                    continue;
                }

                $this->serveConnection($app, $connection);
            }
        } finally {
            fclose($socket);
            ($this->log)('phporbit stopped.');
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * @param resource $connection
     */
    private function serveConnection(Application $app, $connection): void
    {
        stream_set_timeout($connection, self::IDLE_TIMEOUT_SECONDS);

        try {
            for ($served = 0; $served < self::MAX_REQUESTS_PER_CONNECTION; $served++) {
                $request = null;

                try {
                    $request = $this->parser->parse($connection, 'http', $this->host, $this->port);
                } catch (MalformedRequest $e) {
                    $this->write($connection, Response::text(
                        $app->isDebug() ? $e->getMessage() : 'Bad Request',
                        Status::BadRequest,
                    ), keepAlive: false);

                    return;
                }

                // Clean close by the peer.
                if ($request === null) {
                    return;
                }

                $response = $app->handle($request);
                $keepAlive = $this->shouldKeepAlive($request);

                $this->write($connection, $response, $keepAlive);
                ($this->log)(sprintf(
                    '%s %s -> %d',
                    $request->method->value,
                    $request->uri->path,
                    $response->status->value,
                ));

                if (!$keepAlive) {
                    return;
                }
            }
        } catch (Throwable $e) {
            // A failure here is in the transport, not the application —
            // Application::handle() has already dealt with handler errors.
            ($this->log)('connection error: ' . $e->getMessage());
        } finally {
            fclose($connection);
        }
    }

    private function shouldKeepAlive(ServerRequest $request): bool
    {
        $connection = $request->headers->first('Connection');

        return $connection === null || strtolower($connection) !== 'close';
    }

    /**
     * @param resource $connection
     */
    private function write($connection, Response $response, bool $keepAlive): void
    {
        $body = $response->wireBody();

        $head = sprintf(
            "HTTP/1.1 %d %s\r\n",
            $response->status->value,
            $response->status->reasonPhrase(),
        );

        foreach ($response->headers->toWire() as [$name, $value]) {
            $head .= $name . ': ' . $value . "\r\n";
        }

        // Content-Length is always written so keep-alive can frame the next
        // request; without it the client cannot tell where this body ends.
        if ($response->status->allowsBody()) {
            $head .= 'Content-Length: ' . strlen($body) . "\r\n";
        }

        $head .= 'Connection: ' . ($keepAlive ? 'keep-alive' : 'close') . "\r\n\r\n";

        $this->writeAll($connection, $head . $body);
    }

    /**
     * @param resource $connection
     */
    private function writeAll($connection, string $data): void
    {
        $total = strlen($data);

        for ($written = 0; $written < $total;) {
            $result = fwrite($connection, substr($data, $written));

            if ($result === false || $result === 0) {
                throw new RuntimeException('Failed writing the response to the socket.');
            }

            $written += $result;
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $stop = function (): void {
            $this->stop();
        };

        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }
}
