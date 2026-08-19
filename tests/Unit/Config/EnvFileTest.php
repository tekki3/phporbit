<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Config;

use PhpOrbit\Config\EnvFile;
use PhpOrbit\Config\InvalidEnvFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    public function test_it_parses_plain_assignments(): void
    {
        self::assertSame(
            ['APP_DEBUG' => 'false', 'PORT' => '8080'],
            EnvFile::parse("APP_DEBUG=false\nPORT=8080"),
        );
    }

    public function test_it_ignores_blank_lines_and_comments(): void
    {
        $contents = <<<'ENV'
            # leading comment

            A=1

            # another
            B=2
            ENV;

        self::assertSame(['A' => '1', 'B' => '2'], EnvFile::parse($contents));
    }

    public function test_it_accepts_the_export_prefix(): void
    {
        self::assertSame(['A' => '1'], EnvFile::parse('export A=1'));
    }

    public function test_it_trims_unquoted_values(): void
    {
        self::assertSame(['A' => 'value'], EnvFile::parse('A =  value   '));
    }

    /**
     * A trailing comment needs whitespace before it, so a `#` inside a
     * password is not treated as one.
     */
    public function test_it_strips_trailing_comments_but_keeps_hashes_in_values(): void
    {
        self::assertSame(['A' => 'value'], EnvFile::parse('A=value # a comment'));
        self::assertSame(['A' => 'pass#word'], EnvFile::parse('A=pass#word'));
    }

    public function test_an_empty_value_is_an_empty_string(): void
    {
        self::assertSame(['A' => ''], EnvFile::parse('A='));
    }

    public function test_double_quotes_allow_escapes(): void
    {
        self::assertSame(
            ['A' => "line1\nline2\ttabbed \"quoted\""],
            EnvFile::parse('A="line1\nline2\ttabbed \"quoted\""'),
        );
    }

    /**
     * Single quotes are entirely literal, which is what makes them the right
     * choice for a password containing backslashes or dollar signs.
     */
    public function test_single_quotes_are_literal(): void
    {
        self::assertSame(['A' => 'no \n escape ${NOPE} $VAR'], EnvFile::parse("A='no \\n escape \${NOPE} \$VAR'"));
    }

    public function test_quoted_values_keep_surrounding_whitespace(): void
    {
        self::assertSame(['A' => '  padded  '], EnvFile::parse('A="  padded  "'));
    }

    /**
     * Needed for anything that is genuinely multi-line, such as a PEM key.
     */
    public function test_a_quoted_value_may_span_lines(): void
    {
        $contents = "KEY=\"-----BEGIN-----\nline two\n-----END-----\"\nNEXT=1";

        self::assertSame(
            ['KEY' => "-----BEGIN-----\nline two\n-----END-----", 'NEXT' => '1'],
            EnvFile::parse($contents),
        );
    }

    public function test_an_unterminated_quote_is_reported(): void
    {
        $this->expectException(InvalidEnvFile::class);
        $this->expectExceptionMessageMatches('/unterminated/');

        EnvFile::parse('A="never closed');
    }

    public function test_it_expands_earlier_values(): void
    {
        self::assertSame(
            ['ROOT' => '/srv/app', 'DB' => '/srv/app/db.sqlite'],
            EnvFile::parse("ROOT=/srv/app\nDB=\"\${ROOT}/db.sqlite\""),
        );
    }

    public function test_expansion_can_read_the_surrounding_context(): void
    {
        self::assertSame(
            ['GREETING' => 'hello world'],
            EnvFile::parse('GREETING="hello ${WHO}"', ['WHO' => 'world']),
        );
    }

    /**
     * Expanding an undefined reference to "" produces a failure far from its
     * cause — an empty password reaching the database driver, say.
     */
    public function test_an_undefined_expansion_is_an_error(): void
    {
        $this->expectException(InvalidEnvFile::class);
        $this->expectExceptionMessageMatches('/not defined/');

        EnvFile::parse('A="${NOT_SET_ANYWHERE_12345}"');
    }

    /**
     * A bare `$` appears constantly in passwords, so only the braced form is
     * treated as a reference.
     */
    public function test_a_bare_dollar_is_left_alone(): void
    {
        self::assertSame(['A' => 'p$ssw0rd$'], EnvFile::parse('A=p$ssw0rd$'));
    }

    public function test_escaped_dollars_are_not_expanded(): void
    {
        self::assertSame(['A' => '${LITERAL}'], EnvFile::parse('A="\${LITERAL}"'));
    }

    /**
     * An unknown escape survives, so a Windows path is not silently mangled.
     */
    public function test_unknown_escapes_are_preserved(): void
    {
        self::assertSame(['A' => 'C:\\Users\\me'], EnvFile::parse('A="C:\Users\me"'));
    }

    #[DataProvider('malformedLines')]
    public function test_it_rejects_malformed_input(string $contents, string $expectedMessage): void
    {
        $this->expectException(InvalidEnvFile::class);
        $this->expectExceptionMessageMatches($expectedMessage);

        EnvFile::parse($contents);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedLines(): iterable
    {
        yield 'no equals' => ['JUST_A_NAME', '/expected KEY=value/'];
        yield 'key with space' => ['MY KEY=1', '/not a valid name/'];
        yield 'key with dash' => ['MY-KEY=1', '/not a valid name/'];
        yield 'key starting with digit' => ['1KEY=1', '/not a valid name/'];
    }

    /**
     * Errors name the key and line but never the value: this file holds
     * secrets, and messages travel into logs and bug reports.
     */
    public function test_error_messages_do_not_leak_values(): void
    {
        try {
            EnvFile::parse("GOOD=1\nDB_PASSWORD=\"hunter2-super-secret", context: [], path: '/app/.env');

            self::fail('expected a parse failure');
        } catch (InvalidEnvFile $e) {
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertStringContainsString('/app/.env', $e->getMessage());
            self::assertStringContainsString('line 2', $e->getMessage());
        }
    }

    public function test_it_reports_the_line_number(): void
    {
        try {
            EnvFile::parse("A=1\nB=2\nBROKEN LINE\n");

            self::fail('expected a parse failure');
        } catch (InvalidEnvFile $e) {
            self::assertStringContainsString('line 3', $e->getMessage());
        }
    }
}
