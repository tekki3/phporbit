<?php

declare(strict_types=1);

namespace PhpOrbit\View;

/**
 * Turns template source into plain PHP.
 *
 * The output of `{{ }}` is escaped; showing raw markup needs `{!! !!}`, which
 * is visually loud enough that it stands out in review. That asymmetry is the
 * whole point — the safe form has to be the one that is shorter and easier to
 * type, or people will not use it.
 *
 * Literal `<?` is neutralised, so a template cannot smuggle in PHP of its own
 * and every executable construct comes from a directive this compiler knows.
 */
final class Compiler
{
    /**
     * Directives that open a PHP block ending in a colon.
     *
     * @var array<string, string>
     */
    private const BLOCK_OPENERS = [
        'if' => 'if',
        'elseif' => 'elseif',
        'foreach' => 'foreach',
        'for' => 'for',
        'while' => 'while',
    ];

    /**
     * Directives that close one.
     *
     * @var array<string, string>
     */
    private const BLOCK_CLOSERS = [
        'endif' => 'endif;',
        'endforeach' => 'endforeach;',
        'endfor' => 'endfor;',
        'endwhile' => 'endwhile;',
    ];

    /**
     * Stand-ins for `@{{` and `@{!!` while the echo patterns run.
     *
     * A NUL cannot appear in a template that any editor produced, so these
     * cannot collide with real content.
     *
     * @var array<string, string> escape sequence => placeholder
     */
    private const LITERALS = [
        '@{!!' => "\0orbit:literal-raw\0",
        '@{{' => "\0orbit:literal-braces\0",
    ];

    public function compile(string $source): string
    {
        $source = $this->neutralisePhpTags($source);
        $source = $this->stripComments($source);
        $source = $this->protectLiteralBraces($source);
        $source = $this->compileDirectives($source);
        $source = $this->compileRawEchoes($source);
        $source = $this->compileEscapedEchoes($source);

        return $this->restoreLiteralBraces($source);
    }

    /**
     * `@{{` renders a literal `{{`, and `@{!!` a literal `{!!`.
     *
     * Needed by any page that documents the template syntax itself, and by
     * pages sharing the delimiters with a client-side framework. Without them
     * the echo patterns match the inner delimiters and emit broken PHP — which
     * is exactly what this framework's own starter template hit.
     *
     * Listed longest first, so a shorter escape can never consume the opening
     * of a longer one as more are added.
     */
    private function protectLiteralBraces(string $source): string
    {
        foreach (self::LITERALS as $escape => $placeholder) {
            $source = str_replace($escape, $placeholder, $source);
        }

        return $source;
    }

    private function restoreLiteralBraces(string $source): string
    {
        foreach (self::LITERALS as $escape => $placeholder) {
            // Drops the leading "@", leaving the delimiter it was protecting.
            $source = str_replace($placeholder, substr($escape, 1), $source);
        }

        return $source;
    }

    /**
     * Renders a literal `<?` as text instead of letting it open a PHP block.
     */
    private function neutralisePhpTags(string $source): string
    {
        return str_replace('<?', '<?php echo "<?"; ?>', $source);
    }

    private function stripComments(string $source): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $source);
    }

    /**
     * `{!! $value !!}` — unescaped, opt-in.
     *
     * Compiled before `{{ }}` so that the raw delimiters are consumed first
     * and their inner braces are never mistaken for an escaped echo.
     */
    private function compileRawEchoes(string $source): string
    {
        return (string) preg_replace(
            '/\{!!\s*(.+?)\s*!!\}/s',
            '<?php echo $__r->raw($1); ?>',
            $source,
        );
    }

    /**
     * `{{ $value }}` — escaped, the default.
     */
    private function compileEscapedEchoes(string $source): string
    {
        return (string) preg_replace(
            '/\{\{\s*(.+?)\s*\}\}/s',
            '<?php echo $__r->escape($1); ?>',
            $source,
        );
    }

    private function compileDirectives(string $source): string
    {
        // (?2) recurses into the parenthesised group so nested parens in an
        // expression — @if(count($a) > 0) — are matched as one argument.
        //
        // The whitespace allowance sits *inside* the optional group: outside
        // it, `@else text` would have its leading space swallowed and the
        // rendered output would silently lose it.
        $pattern = '/@(\w+)(?:\s*(\(((?:[^()\'"]++|\'[^\']*\'|"[^"]*"|(?2))*)\)))?/';

        $compiled = preg_replace_callback(
            $pattern,
            function (array $match): string {
                $name = is_string($match[1] ?? null) ? $match[1] : '';
                $arguments = is_string($match[3] ?? null) ? $match[3] : '';
                $hasParens = ($match[2] ?? '') !== '';

                return $this->compileDirective($name, $arguments, $hasParens, $match[0]);
            },
            $source,
        );

        return $compiled ?? $source;
    }

    private function compileDirective(
        string $name,
        string $arguments,
        bool $hasParens,
        string $original,
    ): string {
        if (isset(self::BLOCK_OPENERS[$name])) {
            if (!$hasParens) {
                throw new TemplateError(sprintf('@%s requires a condition, e.g. @%s($x).', $name, $name));
            }

            return sprintf('<?php %s (%s): ?>', self::BLOCK_OPENERS[$name], $arguments);
        }

        if (isset(self::BLOCK_CLOSERS[$name])) {
            return sprintf('<?php %s ?>', self::BLOCK_CLOSERS[$name]);
        }

        return match ($name) {
            'else' => '<?php else: ?>',
            'extends' => sprintf('<?php $__r->extend(%s); ?>', $arguments),
            'section' => sprintf('<?php $__r->startSection(%s); ?>', $arguments),
            'endsection' => '<?php $__r->endSection(); ?>',
            'yield' => sprintf('<?php echo $__r->yieldSection(%s); ?>', $arguments),
            'include' => sprintf('<?php echo $__r->includePartial(%s); ?>', $arguments),
            // An unknown @word is far more likely to be prose — an email
            // address, a decorator in a code sample — than a typo'd directive,
            // so it passes through untouched.
            default => $original,
        };
    }
}
