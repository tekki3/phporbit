<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the rule that framework and application code boots on every target.
 *
 * A previous release referenced the `STDERR` constant in `app/bootstrap.php`.
 * It worked under `orbit serve` and the whole test suite — both CLI — and then
 * took the application down with a fatal error the first time anyone pointed a
 * web server at it. Unit tests could not catch that, because the suite itself
 * runs under the CLI where the constant exists.
 *
 * These are static scans instead. They tokenise the source rather than
 * pattern-matching the text, so the prose explaining *why* a construct is
 * banned does not itself trip the check — the first draft of this file flagged
 * its own documentation.
 */
final class PortabilityTest extends TestCase
{
    /** @var list<string> paths that legitimately run only under the CLI */
    private const CLI_ONLY = [
        'orbit',
        'src/Sapi/OrbitServer.php',
    ];

    /** @var list<string> the SAPI boundary, where reading the environment is the job */
    private const SAPI_BOUNDARY = [
        'src/Sapi/FpmSapi.php',
        'src/Config/Environment.php',
        'orbit',
        'public/index.php',
    ];

    /**
     * `STDIN`, `STDOUT` and `STDERR` are defined only under the CLI SAPI.
     */
    public function test_no_cli_only_stream_constants_outside_cli_entrypoints(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $path => $contents) {
            if ($this->pathIsOneOf($path, self::CLI_ONLY)) {
                continue;
            }

            foreach ($this->constants($contents) as [$name, $line]) {
                if (in_array($name, ['STDERR', 'STDOUT', 'STDIN'], true)) {
                    $offenders[] = sprintf('%s:%d uses %s', $path, $line, $name);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These are undefined on every SAPI except the CLI, so they fatal at boot under "
            . "FPM, Apache and the built-in web server. Use StreamLogger::standardError(), "
            . "or fopen('php://stderr').\n\n" . implode("\n", $offenders),
        );
    }

    /**
     * Superglobals belong to the SAPI adapters, which is what makes the request
     * pipeline identical across targets. Above that boundary they are stale
     * under FrankenPHP and absent under the built-in server.
     */
    public function test_superglobals_are_confined_to_the_sapi_boundary(): void
    {
        $offenders = [];
        $superglobals = ['$_GET', '$_POST', '$_SERVER', '$_COOKIE', '$_FILES', '$_SESSION', '$_REQUEST', '$_ENV'];

        foreach ($this->sourceFiles() as $path => $contents) {
            if ($this->pathIsOneOf($path, self::SAPI_BOUNDARY)) {
                continue;
            }

            foreach ($this->variables($contents) as [$name, $line]) {
                if (in_array($name, $superglobals, true)) {
                    $offenders[] = sprintf('%s:%d touches %s', $path, $line, $name);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Superglobals may only be read by a SAPI adapter.\n\n" . implode("\n", $offenders),
        );
    }

    /**
     * PHP's session extension is process-global: under a worker it would carry
     * one visitor's data into the next request the process serves.
     */
    public function test_the_php_session_extension_is_not_used(): void
    {
        $offenders = [];
        $banned = ['session_start', 'session_id', 'session_regenerate_id', 'session_destroy', 'session_name'];

        foreach ($this->sourceFiles() as $path => $contents) {
            foreach ($this->calls($contents) as [$name, $line]) {
                if (in_array($name, $banned, true)) {
                    $offenders[] = sprintf('%s:%d calls %s()', $path, $line, $name);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "phporbit implements its own sessions precisely because PHP's are process-global.\n\n"
            . implode("\n", $offenders),
        );
    }

    /**
     * Proves the scanner reads code rather than comments — without this, the
     * three tests above could pass by being blind.
     */
    public function test_the_scanner_ignores_comments_and_strings(): void
    {
        $source = <<<'PHP'
            <?php
            // STDERR in a line comment
            /** STDERR and $_SESSION in a docblock, calling session_start() */
            $message = 'STDERR inside a string';
            $real = STDOUT;
            PHP;

        self::assertSame([['STDOUT', 5]], $this->constants($source));
        self::assertSame([], $this->calls($source));
        self::assertSame(
            [],
            array_values(array_filter(
                $this->variables($source),
                static fn (array $found): bool => $found[0] === '$_SESSION',
            )),
        );
    }

    /**
     * Bare constant tokens, excluding anything after `::` or `->`.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function constants(string $contents): array
    {
        $found = [];
        $tokens = token_get_all($contents);

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $previous = $this->previousMeaningful($tokens, $index);

            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
                continue;
            }

            $found[] = [$token[1], $token[2]];
        }

        return $found;
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function variables(string $contents): array
    {
        $found = [];

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $found[] = [$token[1], $token[2]];
            }
        }

        return $found;
    }

    /**
     * Function names immediately followed by an opening parenthesis.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function calls(string $contents): array
    {
        $found = [];
        $tokens = token_get_all($contents);
        $count = count($tokens);

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            for ($next = $index + 1; $next < $count; $next++) {
                $candidate = $tokens[$next];

                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if ($candidate === '(') {
                    $found[] = [$token[1], $token[2]];
                }

                break;
            }
        }

        return $found;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function previousMeaningful(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @return array<string, string> repo-relative path => contents
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (['src', 'app', 'public', 'database'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[substr($file->getPathname(), strlen($root) + 1)] =
                        (string) file_get_contents($file->getPathname());
                }
            }
        }

        // The CLI entrypoint has no extension but is still source.
        $files['orbit'] = (string) file_get_contents($root . '/orbit');

        return $files;
    }

    /**
     * @param list<string> $paths
     */
    private function pathIsOneOf(string $path, array $paths): bool
    {
        foreach ($paths as $candidate) {
            if (str_ends_with($path, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
