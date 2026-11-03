<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Log;

use PhpOrbit\Config\Environment;
use PhpOrbit\Log\Level;
use PhpOrbit\Log\LoggerFactory;
use PHPUnit\Framework\TestCase;
use ValueError;

final class LoggerFactoryTest extends TestCase
{
    public function test_file_output_appends_json_and_creates_directories_relative_to_root(): void
    {
        $root = sys_get_temp_dir() . '/orbit-log-' . bin2hex(random_bytes(6));
        $env = Environment::fromArray([
            'LOG_OUTPUT' => 'file', 'LOG_LEVEL' => 'warning',
            'LOG_SERVICE' => 'test-service', 'APP_ENV' => 'testing',
        ]);
        try {
            $logger = LoggerFactory::fromEnvironment($env, $root);
            $logger->log(Level::Debug, 'filtered');
            $logger->log(Level::Warning, 'first');
            unset($logger);
            $logger = LoggerFactory::fromEnvironment($env, $root);
            $logger->log(Level::Error, 'second');
            unset($logger);
            $contents = file_get_contents($root . '/storage/logs/app.log');
            self::assertIsString($contents);
            $lines = array_values(array_filter(explode("\n", $contents)));
            self::assertCount(2, $lines);
            foreach ($lines as $line) {
                $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($entry);
                self::assertSame(['service' => 'test-service', 'environment' => 'testing'], $entry['context']);
            }
            self::assertStringContainsString('"message":"first"', $lines[0]);
            self::assertStringContainsString('"message":"second"', $lines[1]);
        } finally {
            if (is_file($root . '/storage/logs/app.log')) {
                unlink($root . '/storage/logs/app.log');
            }
            foreach ([$root . '/storage/logs', $root . '/storage', $root] as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
        }
    }

    public function test_stderr_and_stdout_can_be_opened_without_cli_constants(): void
    {
        foreach (['stderr', 'stdout'] as $output) {
            $logger = LoggerFactory::fromEnvironment(Environment::fromArray(['LOG_OUTPUT' => $output, 'LOG_LEVEL' => 'error']), __DIR__);
            $logger->log(Level::Debug, 'filtered');
        }
        $this->addToAssertionCount(1);
    }

    public function test_unknown_destination_is_rejected_at_boot(): void
    {
        $this->expectException(ValueError::class);
        LoggerFactory::fromEnvironment(Environment::fromArray(['LOG_OUTPUT' => 'stderrr']), __DIR__);
    }

    public function test_stdout_and_stderr_emit_json_on_the_selected_channel(): void
    {
        foreach (['stdout', 'stderr'] as $output) {
            $code = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
                . '$logger = PhpOrbit\\Log\\LoggerFactory::fromEnvironment('
                . 'PhpOrbit\\Config\\Environment::fromArray(["LOG_OUTPUT" => ' . var_export($output, true) . ']), __DIR__);'
                . '$logger->log(PhpOrbit\\Log\\Level::Info, "channel test", ["password" => "secret"]);';
            $process = proc_open([PHP_BINARY, '-r', $code], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
            self::assertSame('', $output === 'stdout' ? $stderr : $stdout);
            $line = $output === 'stdout' ? $stdout : $stderr;
            self::assertIsString($line);
            self::assertCount(1, array_filter(explode("\n", $line)));
            self::assertStringContainsString('"password":"[REDACTED]"', $line);
            self::assertStringContainsString('"service":"phporbit"', $line);
            self::assertStringContainsString('"environment":"production"', $line);
            self::assertIsArray(json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        }
    }
}
