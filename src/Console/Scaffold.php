<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use Closure;
use FilesystemIterator;
use PhpOrbit\Crypto\Key;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Creates a new phporbit project on disk.
 *
 * Two shapes, both complete and runnable:
 *
 * - **blank** — the smallest thing that boots and serves a page. One route,
 *   one controller, one template, no database tables.
 * - **demo** — the same application this repository ships as its own front
 *   page: sessions, authentication, uploads, notes and the live self-check.
 *
 * Neither includes the documentation. Docs belong to the framework, not to
 * every project built on it, and a copy in each application is a copy that goes
 * stale. `app/bootstrap.php` mounts `/docs` only when the directory exists, so
 * a scaffolded project simply has no such route.
 *
 * This class lives in `src/` and so must run on any SAPI: progress is reported
 * through a callback rather than written to a stream the CLI happens to have.
 */
final class Scaffold
{
    /** @var Closure(string): void */
    private readonly Closure $report;

    /**
     * @param string $frameworkRoot the phporbit checkout or package directory
     * @param (Closure(string): void)|null $report
     */
    public function __construct(
        private readonly string $frameworkRoot,
        ?Closure $report = null,
    ) {
        $this->report = $report ?? static function (string $line): void {
        };
    }

    /**
     * Writes a project into $target.
     *
     * @return list<string> the files created, relative to the target
     */
    public function create(string $target, Variant $variant, bool $force = false): array
    {
        $target = rtrim($target, '/\\');

        if ($target === '') {
            throw new RuntimeException('A project directory is required.');
        }

        $this->assertUsable($target, $force);

        ($this->report)('Writing entrypoints, configuration and tooling');

        // Shared skeleton first: entrypoints, configuration, tooling.
        $created = $this->copyTree($this->stub('skeleton'), $target);

        ($this->report)(sprintf('Writing %s', $variant->describe()));

        $created = match ($variant) {
            Variant::Blank => [...$created, ...$this->copyTree($this->stub('blank'), $target)],
            Variant::Demo => [...$created, ...$this->copyDemo($target)],
        };

        $this->writeComposerJson($target, $variant);
        $created[] = 'composer.json';

        // Directories git will not keep on its own, but the application needs.
        foreach (['storage/sessions', 'storage/cache/views', 'public/avatars'] as $directory) {
            $this->ensureDirectory($target . '/' . $directory);
        }

        // A project starts life without a .env; copying the example is the step
        // everyone forgets, and there is nothing secret in it yet.
        if (!is_file($target . '/.env') && is_file($target . '/.env.example')) {
            copy($target . '/.env.example', $target . '/.env');
            $created[] = '.env';

            ($this->report)('Copied .env.example to .env');

            // A key per project, generated now. The example file keeps a blank
            // APP_KEY because it is committed — a template that shipped a real
            // key would give every project built from it the same secret.
            $this->writeApplicationKey($target . '/.env');
        }

        if (is_file($target . '/orbit')) {
            @chmod($target . '/orbit', 0o755);
        }

        sort($created);

        return $created;
    }

    /**
     * The demo variant is copied from this repository's own application rather
     * than from a second set of stubs, so it cannot drift from the thing it is
     * a copy of.
     *
     * @return list<string>
     */
    private function copyDemo(string $target): array
    {
        $created = [];

        foreach (['app', 'database', 'public/assets', 'public/icons'] as $directory) {
            $source = $this->frameworkRoot . '/' . $directory;

            if (is_dir($source)) {
                $created = [...$created, ...$this->copyTree($source, $target . '/' . $directory)];
            }
        }

        foreach (['public/favicon.svg', 'public/favicon.ico', 'public/site.webmanifest'] as $file) {
            $source = $this->frameworkRoot . '/' . $file;

            if (is_file($source)) {
                $this->ensureDirectory(dirname($target . '/' . $file));
                copy($source, $target . '/' . $file);
                $created[] = $file;
            }
        }

        return $created;
    }

    /**
     * Recursively copies a directory, returning target-relative paths.
     *
     * @return list<string>
     */
    private function copyTree(string $source, string $target): array
    {
        if (!is_dir($source)) {
            throw new RuntimeException(sprintf('Missing scaffold source "%s".', $source));
        }

        $this->ensureDirectory($target);

        $created = [];

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target . '/' . $relative;

            if ($item->isDir()) {
                $this->ensureDirectory($destination);

                continue;
            }

            $this->ensureDirectory(dirname($destination));

            if (!copy($item->getPathname(), $destination)) {
                throw new RuntimeException(sprintf('Could not write "%s".', $destination));
            }

            $created[] = $relative;
        }

        return $created;
    }

    /**
     * Fills in APP_KEY so the project works before anyone reads the manual.
     */
    private function writeApplicationKey(string $envPath): void
    {
        $contents = file_get_contents($envPath);

        if ($contents === false) {
            return;
        }

        $updated = preg_replace(
            '/^APP_KEY=.*$/m',
            'APP_KEY=' . Key::generate()->exportForConfiguration(),
            $contents,
            1,
        );

        if ($updated !== null && $updated !== $contents) {
            file_put_contents($envPath, $updated);

            ($this->report)('Generated APP_KEY');
        }
    }

    /**
     * The one generated file: it carries the project's own name.
     */
    private function writeComposerJson(string $target, Variant $variant): void
    {
        $name = basename($target);
        $vendor = 'app';

        // Composer requires lowercase vendor/package with a narrow character set.
        $package = strtolower((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name));
        $package = trim($package, '-.');

        if ($package === '') {
            $package = 'project';
        }

        $manifest = [
            'name' => $vendor . '/' . $package,
            'description' => $variant === Variant::Demo
                ? 'A phporbit application, scaffolded from the demo.'
                : 'A phporbit application.',
            'type' => 'project',
            'require' => [
                'php' => '>=8.3',
                'phporbit/phporbit' => '^0.1',
            ],
            'require-dev' => [
                'phpstan/phpstan' => '^2.1',
                'phpunit/phpunit' => '^11.5',
            ],
            'autoload' => ['psr-4' => ['App\\' => 'app/src/']],
            'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'tests/']],
            'scripts' => ['test' => 'phpunit', 'stan' => 'phpstan analyse'],
            'config' => ['sort-packages' => true],
            'minimum-stability' => 'stable',
        ];

        file_put_contents(
            $target . '/composer.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    /**
     * Refuses to write into a directory that already has something in it.
     *
     * Scaffolding over an existing project would overwrite its bootstrap and
     * routes without warning, so an occupied target is an error rather than a
     * merge. `--force` is the way to say it was intended.
     */
    private function assertUsable(string $target, bool $force): void
    {
        if (!is_dir($target)) {
            $this->ensureDirectory($target);

            return;
        }

        $entries = scandir($target);

        if ($entries === false) {
            throw new RuntimeException(sprintf('Cannot read "%s".', $target));
        }

        $occupied = array_values(array_diff($entries, ['.', '..']));

        if ($occupied !== [] && !$force) {
            throw new RuntimeException(sprintf(
                'Directory "%s" is not empty (%d entries). Pass --force to write into it anyway.',
                $target,
                count($occupied),
            ));
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Cannot create directory "%s".', $path));
        }
    }

    private function stub(string $name): string
    {
        return $this->frameworkRoot . '/stubs/' . $name;
    }
}
