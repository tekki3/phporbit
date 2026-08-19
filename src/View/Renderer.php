<?php

declare(strict_types=1);

namespace PhpOrbit\View;

use PhpOrbit\Security\Escaper;
use Stringable;
use Throwable;

/**
 * One render pass.
 *
 * A fresh instance is created for every {@see TemplateEngine::render()} call
 * because section contents and the chosen layout are state. Keeping them on
 * the engine would mean a worker's second request could inherit the first
 * request's sections — the same leak the framework guards against everywhere
 * else, dressed up as a caching optimisation.
 */
final class Renderer
{
    /** @var array<string, string> */
    private array $sections = [];

    /** @var list<string> */
    private array $openSections = [];

    private ?string $layout = null;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly TemplateEngine $engine,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data): string
    {
        $this->data = $data;

        $content = $this->evaluate($template, $data);

        // @extends defers the real output to a layout, which is rendered with
        // the sections this pass collected. The child's own output becomes the
        // implicit "content" section.
        while ($this->layout !== null) {
            $layout = $this->layout;
            $this->layout = null;

            $this->sections['content'] ??= $content;

            $content = $this->evaluate($layout, $this->data);
        }

        if ($this->openSections !== []) {
            throw new TemplateError(sprintf(
                'Template "%s" left @section(%s) unclosed; add @endsection.',
                $template,
                json_encode(end($this->openSections), JSON_THROW_ON_ERROR),
            ));
        }

        return $content;
    }

    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }

    public function startSection(string $name): void
    {
        $this->openSections[] = $name;

        ob_start();
    }

    public function endSection(): void
    {
        $name = array_pop($this->openSections);

        if ($name === null) {
            throw new TemplateError('@endsection without a matching @section.');
        }

        $buffer = ob_get_clean();

        // A child's section wins over a layout's default, so the first
        // definition seen — the child's, since it renders first — is kept.
        $this->sections[$name] ??= $buffer === false ? '' : $buffer;
    }

    public function yieldSection(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * @param array<string, mixed> $data extra values merged over the current ones
     */
    public function includePartial(string $template, array $data = []): string
    {
        return $this->evaluate($template, [...$this->data, ...$data]);
    }

    /**
     * Converts a value for HTML output, escaping it.
     *
     * This is the narrowing point for template data: arrays and plain objects
     * have no single correct textual form, so rendering one is an error rather
     * than a silent "Array" or a fatal from a bare cast.
     */
    public function escape(mixed $value): string
    {
        return Escaper::html($this->stringify($value));
    }

    /**
     * Output without escaping, for markup the application itself built.
     */
    public function raw(mixed $value): string
    {
        return $this->stringify($value);
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof Stringable => (string) $value,
            default => throw new TemplateError(sprintf(
                'Cannot render a value of type %s. Convert it in the handler before passing it '
                . 'to the template.',
                get_debug_type($value),
            )),
        };
    }

    /**
     * Executes a compiled template and captures its output.
     *
     * @param array<string, mixed> $data
     */
    private function evaluate(string $template, array $data): string
    {
        $compiled = $this->engine->compiledPath($template);

        $level = ob_get_level();

        ob_start();

        try {
            (static function (string $__path, array $__data, Renderer $__r): void {
                // EXTR_SKIP so template data can never overwrite the two
                // variables the compiled code itself depends on.
                extract($__data, EXTR_SKIP);

                require $__path;
            })($compiled, $data, $this);

            $output = ob_get_clean();

            return $output === false ? '' : $output;
        } catch (Throwable $e) {
            // A template that throws mid-section leaves buffers open; unwinding
            // them here keeps the failure from corrupting the response body.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }
    }
}
