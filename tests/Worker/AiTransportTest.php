<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Worker;

use PhpOrbit\Ai\Agent;
use PhpOrbit\Ai\AiFailed;
use PhpOrbit\Ai\Providers\OpenAI;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AiTransportTest extends TestCase
{
    /** @var list<resource> */
    private array $processes = [];
    /** @var list<resource> */
    private array $pipes = [];
    private int $providerPort;

    protected function setUp(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('AI transport requires ext-curl.');
        }
        $this->providerPort = $this->port();
        $this->start([PHP_BINARY, '-d', 'output_buffering=0', '-S', '127.0.0.1:' . $this->providerPort, dirname(__DIR__) . '/Support/ai-provider.php'], $this->providerPort);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    public function test_curl_returns_incremental_text_and_a_buffered_answer(): void
    {
        $agent = $this->agent();
        self::assertSame('Buffered answer', $agent->send());
        $stream = $agent->withStreaming()->send();
        $started = microtime(true);
        $stream->rewind();
        self::assertSame('First ', $stream->current());
        self::assertLessThan(.55, microtime(true) - $started, 'The first chunk must arrive before the delayed second chunk.');
        $stream->next();
        self::assertSame('Second', $stream->current());
        self::assertGreaterThan(.6, microtime(true) - $started);
        $stream->next();
        self::assertFalse($stream->valid());
        self::assertSame('Buffered answer', $agent->send());
    }

    public function test_provider_http_errors_truncation_bounds_and_timeouts_fail_without_body_leaks(): void
    {
        foreach (['/fail', '/truncated', '/large', '/timeout'] as $path) {
            try {
                $agent = $this->agent($path);
                if ($path === '/timeout') {
                    $agent->timeout(1)->send();
                } else {
                    iterator_to_array($agent->withStreaming()->send());
                }
                self::fail('Invalid provider response accepted.');
            } catch (AiFailed $error) {
                self::assertStringNotContainsString('synthetic private', $error->getMessage());
                self::assertStringNotContainsString('synthetic-test-key', $error->getMessage());
            }
        }
    }

    public function test_live_http_streaming_is_incremental_under_both_server_models(): void
    {
        foreach ([false, true] as $orbit) {
            $port = $this->port();
            $environment = ['AI_TEST_ENDPOINT' => 'http://127.0.0.1:' . $this->providerPort . '/openai'];
            $fixture = dirname(__DIR__) . '/Support/ai-application.php';
            if ($orbit) {
                $environment['AI_TEST_ORBIT_PORT'] = (string) $port;
                $command = [PHP_BINARY, $fixture];
            } else {
                $command = [PHP_BINARY, '-d', 'output_buffering=4096', '-S', '127.0.0.1:' . $port, $fixture];
            }
            $this->start($command, $port, $environment);
            for ($request = 0; $request < 2; $request++) {
                $started = microtime(true);
                $stream = fopen('http://127.0.0.1:' . $port . '/stream', 'rb');
                self::assertIsResource($stream);
                try {
                    $metadata = stream_get_meta_data($stream);
                    $headers = $metadata['wrapper_data'] ?? [];
                    self::assertIsArray($headers);
                    $wire = implode("\n", array_filter($headers, is_string(...)));
                    self::assertStringNotContainsString('Content-Length:', $wire);
                    self::assertStringContainsString('X-Accel-Buffering: no', $wire);
                    self::assertSame('First ', fread($stream, 6));
                    self::assertLessThan(.55, microtime(true) - $started, 'The SAPI buffered the provider stream.');
                    self::assertSame('Second', stream_get_contents($stream));
                    self::assertGreaterThan(.6, microtime(true) - $started);
                } finally {
                    fclose($stream);
                }
            }
            self::assertSame('healthy', file_get_contents('http://127.0.0.1:' . $port . '/health'));
        }
    }

    private function agent(string $path = '/openai'): Agent
    {
        return Agent::using(OpenAI::class)->withKey('synthetic-test-key')->model('test-model')
            ->prompt('Synthetic prompt')->endpoint('http://127.0.0.1:' . $this->providerPort . $path);
    }

    public function test_orbit_chunk_framing_keepalive_and_disconnect_recovery(): void
    {
        $port = $this->port();
        $this->start([PHP_BINARY, dirname(__DIR__) . '/Support/ai-application.php'], $port, [
            'AI_TEST_ENDPOINT' => 'http://127.0.0.1:' . $this->providerPort . '/openai',
            'AI_TEST_ORBIT_PORT' => (string) $port,
        ]);
        foreach ([false, true] as $disconnect) {
            $socket = stream_socket_client('tcp://127.0.0.1:' . $port);
            self::assertIsResource($socket);
            stream_set_timeout($socket, 3);
            try {
                $request = "GET /stream HTTP/1.1\r\nHost: localhost\r\n\r\n";
                self::assertSame(strlen($request), fwrite($socket, $request));
                $headers = '';
                while (($line = fgets($socket)) !== false && $line !== "\r\n") {
                    $headers .= $line;
                }
                self::assertStringContainsString('HTTP/1.1 200 OK', $headers);
                self::assertStringContainsString('Transfer-Encoding: chunked', $headers);
                self::assertStringNotContainsString('Content-Length:', $headers);
                self::assertSame("6\r\nFirst \r\n", $this->readExactly($socket, 11));
                if (!$disconnect) {
                    self::assertSame("6\r\nSecond\r\n0\r\n\r\n", $this->readExactly($socket, 16));
                    $request = "GET /health HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
                    self::assertSame(strlen($request), fwrite($socket, $request));
                    $response = stream_get_contents($socket);
                    self::assertIsString($response);
                    self::assertStringContainsString('Content-Length: 7', $response);
                    self::assertStringEndsWith("\r\n\r\nhealthy", $response);
                }
            } finally {
                fclose($socket);
            }
            self::assertSame('healthy', file_get_contents('http://127.0.0.1:' . $port . '/health'));
        }
    }

    /** @param resource $socket */
    private function readExactly($socket, int $length): string
    {
        $body = '';
        while (($remaining = $length - strlen($body)) > 0) {
            $chunk = fread($socket, $remaining);
            self::assertNotFalse($chunk);
            self::assertNotSame('', $chunk, 'The stream ended or timed out before its frame completed.');
            $body .= $chunk;
        }
        return $body;
    }

    private function port(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);
        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    private function start(array $command, int $port, array $environment = []): void
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__, 2), $environment === [] ? null : [...getenv(), ...$environment]);
        self::assertIsResource($process);
        $this->processes[] = $process;
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
            $this->pipes[] = $pipe;
        }
        $deadline = microtime(true) + 5;
        do {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, .1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Test server did not start.');
    }
}
