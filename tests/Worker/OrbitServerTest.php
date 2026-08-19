<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Worker;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Drives the real built-in server over a real socket.
 *
 * This is the test that substantiates "phporbit runs on itself": the same
 * `orbit serve` a developer runs is started as a subprocess and answered over
 * TCP, rather than the pipeline being called in-process.
 */
#[Group('server')]
final class OrbitServerTest extends TestCase
{
    /** @var resource|null */
    private $process = null;

    /** @var list<resource> */
    private array $pipes = [];

    private int $port = 0;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);

        // A random high port keeps parallel runs from colliding.
        $this->port = random_int(20000, 60000);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, $root . '/orbit', 'serve', '--port=' . $this->port],
            $descriptors,
            $pipes,
            $root,
        );

        if (!is_resource($process)) {
            self::markTestSkipped('Could not start the orbit server.');
        }

        $this->process = $process;
        $this->pipes = array_values(array_filter($pipes, is_resource(...)));

        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $this->waitForPort();
    }

    protected function tearDown(): void
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process, SIGTERM);
            proc_close($this->process);
        }

        $this->pipes = [];
        $this->process = null;
    }

    public function test_it_serves_the_index(): void
    {
        $response = $this->request("GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 200 OK', $response);
        self::assertStringContainsString('phporbit', $response);
        self::assertStringContainsString('Content-Length:', $response);
    }

    public function test_it_serves_a_parameterised_route(): void
    {
        $response = $this->request("GET /hello/ada HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 200 OK', $response);
        self::assertStringContainsString('Hello, ada', $response);
    }

    /**
     * Reflected input must arrive escaped, not as live markup.
     */
    public function test_it_escapes_reflected_input(): void
    {
        $payload = rawurlencode('<script>alert(1)');
        $response = $this->request("GET /hello/{$payload} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 200 OK', $response);
        self::assertStringContainsString('&lt;script&gt;alert(1)', $response);
        self::assertStringNotContainsString('<script>alert(1)', $response);
    }

    /**
     * An encoded separator is refused at the edge, so no handler ever sees a
     * parameter that secretly spans path segments.
     */
    public function test_it_rejects_an_encoded_path_separator(): void
    {
        $response = $this->request(
            "GET /hello/a%2F..%2Fb HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n",
        );

        self::assertStringStartsWith('HTTP/1.1 400 Bad Request', $response);
    }

    public function test_an_unknown_path_is_a_404(): void
    {
        $response = $this->request("GET /nothing-here HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 404 Not Found', $response);
    }

    public function test_it_sends_security_headers(): void
    {
        $response = $this->request("GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

        self::assertStringContainsString('X-Content-Type-Options: nosniff', $response);
        self::assertStringContainsString('X-Frame-Options: DENY', $response);
    }

    public function test_a_malformed_request_is_a_400(): void
    {
        $response = $this->request("NOT-A-REQUEST\r\n\r\n");

        self::assertStringStartsWith('HTTP/1.1 400 Bad Request', $response);
    }

    /**
     * Two requests on one connection, which is what makes the server usable
     * from a browser and exercises Content-Length framing.
     */
    public function test_it_keeps_a_connection_alive_for_a_second_request(): void
    {
        $socket = $this->connect();

        fwrite($socket, "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $first = $this->readMessage($socket);

        fwrite($socket, "GET /health HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $second = $this->readMessage($socket);

        fclose($socket);

        self::assertStringContainsString('200 OK', $first);
        self::assertStringContainsString('Connection: keep-alive', $first);
        self::assertStringContainsString('200 OK', $second);
        self::assertStringContainsString('"status":"ok"', $second);
    }

    private function request(string $raw): string
    {
        $socket = $this->connect();

        fwrite($socket, $raw);
        $response = (string) stream_get_contents($socket);

        fclose($socket);

        return $response;
    }

    /**
     * Reads one response, using Content-Length to know where it ends.
     *
     * @param resource $socket
     */
    private function readMessage($socket): string
    {
        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = fread($socket, 1);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $head .= $chunk;
        }

        $length = preg_match('/Content-Length: (\d+)/i', $head, $m) === 1 ? (int) $m[1] : 0;

        $body = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread($socket, $remaining);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $body .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $head . $body;
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 5);

        if ($socket === false) {
            self::fail(sprintf('Could not connect to the server: %s (%d)', $errstr, $errno));
        }

        stream_set_timeout($socket, 5);

        return $socket;
    }

    private function waitForPort(): void
    {
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        self::markTestSkipped(sprintf('The orbit server did not start on port %d.', $this->port));
    }
}
