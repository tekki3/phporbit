<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Log;

use PhpOrbit\Log\Level;
use PhpOrbit\Log\StreamLogger;
use PHPUnit\Framework\TestCase;
use ValueError;
use InvalidArgumentException;
use RuntimeException;

final class StreamLoggerTest extends TestCase
{
    public function test_it_writes_one_json_object_per_line(): void
    {
        [$logger, $read] = $this->logger();

        $logger->log(Level::Info, 'request handled', ['status' => 200]);
        $logger->log(Level::Error, 'request failed');

        $lines = array_values(array_filter(explode("\n", $read())));

        self::assertCount(2, $lines);

        $first = json_decode($lines[0], true);

        self::assertIsArray($first);
        self::assertSame('info', $first['level']);
        self::assertSame('request handled', $first['message']);
        self::assertSame(['status' => 200], $first['context']);
    }

    /**
     * A newline in user input would otherwise forge a second log entry.
     */
    public function test_a_newline_in_a_message_cannot_forge_an_entry(): void
    {
        [$logger, $read] = $this->logger();

        $logger->log(Level::Info, "real\nlevel\":\"error\",\"message\":\"forged");

        self::assertCount(1, array_values(array_filter(explode("\n", $read()))));
    }

    public function test_entries_below_the_minimum_are_dropped(): void
    {
        [$logger, $read] = $this->logger(Level::Warning);

        $logger->log(Level::Debug, 'noisy');
        $logger->log(Level::Info, 'ordinary');
        $logger->log(Level::Error, 'important');

        $lines = array_values(array_filter(explode("\n", $read())));

        self::assertCount(1, $lines);
        self::assertStringContainsString('important', $lines[0]);
    }

    public function test_context_is_omitted_when_empty(): void
    {
        [$logger, $read] = $this->logger();

        $logger->log(Level::Info, 'bare');

        self::assertStringNotContainsString('context', $read());
    }

    public function test_nested_values_keep_types_and_secrets_are_redacted(): void
    {
        [$logger, $read] = $this->logger();
        $logger->log(Level::Info, 'nested', [
            'order' => ['id' => 42, 'paid' => true, 'total' => 12.5, 'note' => null, 'items' => ['a', 'b']],
            'headers' => ['Authorization' => 'Bearer secret', 'Set-Cookie' => ['private']],
            'credentials' => ['user' => 'private', 'password' => 'private'],
            'nested' => ['db_password' => 'private', 'clientSecret' => 'private', '_token' => 'private'],
        ]);
        $decoded = json_decode($read(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame([
            'order' => ['id' => 42, 'paid' => true, 'total' => 12.5, 'note' => null, 'items' => ['a', 'b']],
            'headers' => ['Authorization' => '[REDACTED]', 'Set-Cookie' => '[REDACTED]'],
            'credentials' => '[REDACTED]',
            'nested' => ['db_password' => '[REDACTED]', 'clientSecret' => '[REDACTED]', '_token' => '[REDACTED]'],
        ], $decoded['context']);
        self::assertStringNotContainsString('Bearer secret', $read());
    }

    public function test_bound_context_cannot_be_overwritten_by_a_log_call(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $logger = new StreamLogger($stream, context: ['service' => 'billing', 'password' => 'secret']);
        $logger->log(Level::Info, 'event', ['service' => 'forged', 'count' => 2]);
        rewind($stream);
        $line = stream_get_contents($stream);
        self::assertIsString($line);
        self::assertStringContainsString('"service":"billing"', $line);
        self::assertStringContainsString('"password":"[REDACTED]"', $line);
        self::assertStringContainsString('"count":2', $line);
        fclose($stream);
    }

    public function test_invalid_utf8_does_not_break_request_logging(): void
    {
        [$logger, $read] = $this->logger();
        $logger->log(Level::Info, "invalid \xff\nmessage", ['path' => "/\xff"]);
        self::assertCount(1, array_filter(explode("\n", $read())));
        self::assertIsArray(json_decode($read(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_objects_in_nested_context_are_rejected_without_serializing_them(): void
    {
        [$logger] = $this->logger();
        $this->expectException(InvalidArgumentException::class);
        $logger->log(Level::Info, 'invalid', ['data' => ['object' => new \stdClass()]]);
    }

    public function test_recursive_context_is_rejected_explicitly(): void
    {
        [$logger] = $this->logger();
        $values = [];
        $values['self'] = &$values;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('32 nested array levels');
        $logger->log(Level::Info, 'recursive', ['data' => $values]);
    }

    public function test_closed_stream_failure_is_explicit(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $logger = new StreamLogger($stream);
        fclose($stream);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('closed log stream');
        $logger->log(Level::Info, 'cannot write');
    }

    /**
     * The regression this exists for.
     *
     * `STDERR` is defined only under the CLI SAPI, so a logger built on the
     * constant takes the whole application down at boot under FPM, Apache and
     * the built-in web server. The wrapper is available everywhere.
     */
    public function test_standard_error_does_not_depend_on_a_cli_only_constant(): void
    {
        // Opening the stream is where a CLI-only constant would fatal, so
        // constructing the logger is the assertion. The call below is filtered
        // out by the minimum level, which keeps the suite's output clean while
        // still exercising the write path.
        $logger = StreamLogger::standardError(Level::Error);

        $logger->log(Level::Debug, 'filtered out');

        self::assertInstanceOf(StreamLogger::class, $logger);
    }

    public function test_levels_parse_from_configuration(): void
    {
        self::assertSame(Level::Warning, Level::fromName('warning'));
        self::assertSame(Level::Debug, Level::fromName('  DEBUG '));
    }

    /**
     * A typo'd level that silently became `debug` would put request detail into
     * production logs; one that became `error` would hide warnings someone
     * deliberately asked for.
     */
    public function test_an_unknown_level_is_rejected(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessageMatches('/Unknown log level/');

        Level::fromName('warn');
    }

    /**
     * @return array{0: StreamLogger, 1: callable(): string}
     */
    private function logger(Level $minimum = Level::Debug): array
    {
        $stream = fopen('php://memory', 'r+');

        self::assertIsResource($stream);

        $read = static function () use ($stream): string {
            rewind($stream);

            return (string) stream_get_contents($stream);
        };

        return [new StreamLogger($stream, $minimum), $read];
    }
}
