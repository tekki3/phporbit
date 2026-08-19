<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Config;

use PhpOrbit\Config\Environment;
use PhpOrbit\Config\InvalidEnvFile;
use PhpOrbit\Config\MissingConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/orbit-env-' . bin2hex(random_bytes(6));

        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        putenv('ORBIT_TEST_VALUE');
    }

    public function test_it_reads_a_file(): void
    {
        $env = $this->load("APP_DEBUG=true\nPORT=9000");

        self::assertTrue($env->bool('APP_DEBUG'));
        self::assertSame(9000, $env->int('PORT'));
    }

    /**
     * A missing file is normal in production, where everything is injected by
     * the platform.
     */
    public function test_a_missing_file_is_not_an_error_by_default(): void
    {
        $env = Environment::load($this->directory . '/absent.env');

        self::assertFalse($env->has('ANYTHING_AT_ALL_12345'));
    }

    public function test_a_missing_file_can_be_required(): void
    {
        $this->expectException(InvalidEnvFile::class);

        Environment::load($this->directory . '/absent.env', required: true);
    }

    /**
     * The decisive precedence rule: a stale .env on a server must never
     * override what the platform injected.
     */
    public function test_the_real_environment_wins_over_the_file(): void
    {
        putenv('ORBIT_TEST_VALUE=from-environment');

        $env = $this->load('ORBIT_TEST_VALUE=from-file');

        self::assertSame('from-environment', $env->string('ORBIT_TEST_VALUE'));
    }

    public function test_the_file_supplies_values_the_environment_lacks(): void
    {
        self::assertSame('from-file', $this->load('ORBIT_TEST_VALUE=from-file')->string('ORBIT_TEST_VALUE'));
    }

    public function test_with_layers_overrides_without_mutating(): void
    {
        $base = Environment::fromArray(['A' => '1']);
        $overridden = $base->with(['A' => '2', 'B' => '3']);

        self::assertSame('1', $base->string('A'));
        self::assertSame('2', $overridden->string('A'));
        self::assertSame('3', $overridden->string('B'));
        self::assertFalse($base->has('B'));
    }

    // --- typed access --------------------------------------------------------

    public function test_string_falls_back_to_a_default(): void
    {
        self::assertSame('fallback', Environment::fromArray([])->string('ABSENT', 'fallback'));
    }

    public function test_a_missing_setting_without_a_default_throws(): void
    {
        $this->expectException(MissingConfiguration::class);
        $this->expectExceptionMessageMatches('/not set/');

        Environment::fromArray([])->string('DB_PASSWORD');
    }

    /**
     * `KEY=` is set but unusable; for a secret those are the same problem.
     */
    public function test_required_rejects_a_blank_value(): void
    {
        $this->expectException(MissingConfiguration::class);
        $this->expectExceptionMessageMatches('/present but empty/');

        Environment::fromArray(['APP_KEY' => '   '])->required('APP_KEY');
    }

    public function test_raw_distinguishes_blank_from_absent(): void
    {
        $env = Environment::fromArray(['BLANK' => '']);

        self::assertSame('', $env->raw('BLANK'));
        self::assertNull($env->raw('ABSENT'));
    }

    #[DataProvider('truthyValues')]
    public function test_bool_accepts_the_usual_spellings(string $value, bool $expected): void
    {
        self::assertSame($expected, Environment::fromArray(['FLAG' => $value])->bool('FLAG'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function truthyValues(): iterable
    {
        yield 'true' => ['true', true];
        yield 'TRUE' => ['TRUE', true];
        yield 'one' => ['1', true];
        yield 'yes' => ['yes', true];
        yield 'on' => ['on', true];
        yield 'false' => ['false', false];
        yield 'zero' => ['0', false];
        yield 'no' => ['no', false];
        yield 'off' => ['off', false];
    }

    /**
     * A typo'd APP_DEBUG that silently became false would hide errors; one
     * that became true would expose stack traces in production.
     */
    public function test_an_unrecognised_boolean_throws(): void
    {
        $this->expectException(MissingConfiguration::class);
        $this->expectExceptionMessageMatches('/not a valid boolean/');

        Environment::fromArray(['APP_DEBUG' => 'treu'])->bool('APP_DEBUG');
    }

    public function test_int_parses_signed_integers(): void
    {
        self::assertSame(8080, Environment::fromArray(['P' => '8080'])->int('P'));
        self::assertSame(-1, Environment::fromArray(['P' => '-1'])->int('P'));
    }

    public function test_a_non_numeric_int_throws(): void
    {
        $this->expectException(MissingConfiguration::class);
        $this->expectExceptionMessageMatches('/not a valid integer/');

        Environment::fromArray(['PORT' => '80a'])->int('PORT');
    }

    public function test_blank_settings_use_the_default(): void
    {
        $env = Environment::fromArray(['A' => '', 'B' => '   ']);

        self::assertSame(5, $env->int('A', 5));
        self::assertTrue($env->bool('B', true));
    }

    public function test_strings_splits_on_commas(): void
    {
        $env = Environment::fromArray(['PROXIES' => '10.0.0.1, 10.0.0.2 ,, 10.0.0.3']);

        self::assertSame(['10.0.0.1', '10.0.0.2', '10.0.0.3'], $env->strings('PROXIES'));
        self::assertSame([], $env->strings('ABSENT'));
        self::assertSame(['fallback'], $env->strings('ABSENT', ['fallback']));
    }

    /**
     * Relative paths must not depend on the working directory, which differs
     * between the CLI, a web server and cron.
     */
    public function test_path_resolves_relative_values_against_the_root(): void
    {
        $env = Environment::fromArray([
            'REL' => 'storage/app.sqlite',
            'ABS' => '/var/lib/app.sqlite',
            'MEM' => ':memory:',
        ]);

        self::assertSame('/srv/app/storage/app.sqlite', $env->path('REL', '/srv/app'));
        self::assertSame('/var/lib/app.sqlite', $env->path('ABS', '/srv/app'));
        self::assertSame(':memory:', $env->path('MEM', '/srv/app'));
        self::assertSame('/srv/app/fallback.sqlite', $env->path('ABSENT', '/srv/app', 'fallback.sqlite'));
    }

    // --- secrecy -------------------------------------------------------------

    /**
     * Configuration is mostly credentials, and a var_dump in a stack trace
     * would put every one of them into whatever reads that output.
     */
    public function test_debug_output_redacts_values(): void
    {
        $env = Environment::fromArray(['DB_PASSWORD' => 'hunter2', 'API_KEY' => 'sk-secret']);

        $dumped = print_r($env, true);

        self::assertStringNotContainsString('hunter2', $dumped);
        self::assertStringNotContainsString('sk-secret', $dumped);
        self::assertStringContainsString('redacted', $dumped);
        self::assertStringContainsString('DB_PASSWORD', $dumped, 'key names are still useful');
    }

    public function test_keys_lists_names_without_values(): void
    {
        $env = Environment::fromArray(['B' => 'secret', 'A' => 'secret']);

        self::assertSame(['A', 'B'], $env->keys());
    }

    private function load(string $contents): Environment
    {
        $path = $this->directory . '/.env';

        file_put_contents($path, $contents);

        return Environment::load($path);
    }
}
