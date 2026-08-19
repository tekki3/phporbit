<?php

declare(strict_types=1);

namespace PhpOrbit\View;

use PhpOrbit\Http\Response;
use PhpOrbit\Http\Status;

/**
 * Locates, compiles and caches templates.
 *
 * Safe to share across a worker's requests: it holds only configuration. All
 * per-render state lives in a {@see Renderer} created per call.
 */
final class TemplateEngine
{
    /** @var array<string, mixed> */
    private readonly array $shared;

    /**
     * @param array<string, mixed> $shared values available to every template
     */
    public function __construct(
        private readonly string $templateDirectory,
        private readonly string $cacheDirectory,
        private readonly bool $alwaysRecompile = false,
        array $shared = [],
    ) {
        $this->shared = $shared;
    }

    /**
     * Renders a template.
     *
     * Shared values are merged underneath the per-render data, so a page can
     * override one without affecting any other page. They are supplied at
     * construction rather than through a setter: a mutable bag on an object
     * this shared would be per-request state living on a process-lifetime
     * service, which is the leak the framework exists to prevent.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        return (new Renderer($this))->render($template, [...$this->shared, ...$data]);
    }

    /**
     * Renders straight into an HTML response.
     *
     * @param array<string, mixed> $data
     */
    public function respond(string $template, array $data = [], Status $status = Status::Ok): Response
    {
        return Response::html($this->render($template, $data), $status);
    }

    public function exists(string $template): bool
    {
        return is_file($this->sourcePath($template));
    }

    /**
     * Returns the compiled file for a template, compiling it if stale.
     */
    public function compiledPath(string $template): string
    {
        $source = $this->sourcePath($template);

        if (!is_file($source)) {
            throw new TemplateError(sprintf(
                'Template "%s" not found at %s.',
                $template,
                $source,
            ));
        }

        $compiled = $this->cacheDirectory . '/' . hash('xxh128', $template) . '.php';

        if ($this->isStale($source, $compiled)) {
            $this->write($compiled, (new Compiler())->compile((string) file_get_contents($source)));
        }

        return $compiled;
    }

    private function isStale(string $source, string $compiled): bool
    {
        if ($this->alwaysRecompile || !is_file($compiled)) {
            return true;
        }

        return filemtime($source) > filemtime($compiled);
    }

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new TemplateError(sprintf('Cannot create the template cache directory "%s".', $directory));
        }

        // Written then renamed: a worker must never `require` a file another
        // process is still halfway through writing.
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temporary, $contents) === false || !@rename($temporary, $path)) {
            @unlink($temporary);

            throw new TemplateError(sprintf('Cannot write the compiled template "%s".', $path));
        }
    }

    /**
     * Resolves a template name to a path inside the template directory.
     *
     * Names are matched against a strict pattern rather than sanitised. A
     * template name that reached here from a request could otherwise be used
     * to compile and execute an arbitrary file.
     */
    private function sourcePath(string $template): string
    {
        if (preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#', $template) !== 1) {
            throw new TemplateError(sprintf(
                'Invalid template name "%s". Names may contain letters, digits, underscores, '
                . 'hyphens and forward slashes only.',
                $template,
            ));
        }

        return $this->templateDirectory . '/' . $template . '.orbit.php';
    }
}
