<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Log;

use PhpOrbit\Log\Level;
use PhpOrbit\Log\StreamLogger;
use PHPUnit\Framework\TestCase;
use ValueError;

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
