<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\View;

use PhpOrbit\View\TemplateEngine;
use PhpOrbit\View\TemplateError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateEngineTest extends TestCase
{
    private string $templates;

    private string $cache;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/orbit-engine-' . bin2hex(random_bytes(6));

        $this->templates = $base . '/templates';
        $this->cache = $base . '/cache';

        mkdir($this->templates, 0o750, true);
        mkdir($this->cache, 0o750, true);

        file_put_contents($this->templates . '/page.orbit.php', 'hello {{ $name }}');
    }

    protected function tearDown(): void
    {
        foreach ([$this->templates, $this->cache] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }

        @rmdir(dirname($this->templates));
    }

    public function test_it_renders_and_caches(): void
    {
        $engine = new TemplateEngine($this->templates, $this->cache);

        self::assertSame('hello ada', $engine->render('page', ['name' => 'ada']));
        self::assertCount(1, glob($this->cache . '/*.php') ?: []);
    }

    public function test_a_cached_template_is_reused_until_the_source_changes(): void
    {
        $engine = new TemplateEngine($this->templates, $this->cache);
        $engine->render('page', ['name' => 'ada']);

        $compiled = (glob($this->cache . '/*.php') ?: [])[0];
        $firstMtime = filemtime($compiled);

        $engine->render('page', ['name' => 'grace']);

        self::assertSame($firstMtime, filemtime($compiled), 'unchanged source should not recompile');

        // Backdate the compiled file so the source looks newer.
        touch($compiled, time() - 60);
        touch($this->templates . '/page.orbit.php', time());

        $engine->render('page', ['name' => 'grace']);

        self::assertGreaterThan(time() - 60, (int) filemtime($compiled));
    }

    public function test_respond_produces_an_html_response(): void
    {
        $response = (new TemplateEngine($this->templates, $this->cache))->respond('page', ['name' => 'ada']);

        self::assertSame('text/html; charset=utf-8', $response->headers->first('Content-Type'));
        self::assertSame('hello ada', $response->body);
    }

    public function test_a_missing_template_is_reported_clearly(): void
    {
        $this->expectException(TemplateError::class);
        $this->expectExceptionMessageMatches('/not found/');

        (new TemplateEngine($this->templates, $this->cache))->render('nope');
    }

    /**
     * A template name that reached the engine from a request must not be able
     * to select a file outside the template directory.
     */
    #[DataProvider('hostileNames')]
    public function test_it_rejects_a_hostile_template_name(string $name): void
    {
        $this->expectException(TemplateError::class);
        $this->expectExceptionMessageMatches('/Invalid template name/');

        (new TemplateEngine($this->templates, $this->cache))->render($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileNames(): iterable
    {
        yield 'traversal' => ['../../../etc/passwd'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'nul byte' => ["page\0.php"];
        yield 'dot segment' => ['a/../b'];
    }

    /**
     * Rendering an array has no single correct answer, so it must not quietly
     * produce "Array".
     */
    public function test_rendering_a_non_stringable_value_fails_loudly(): void
    {
        file_put_contents($this->templates . '/arr.orbit.php', '{{ $items }}');

        $this->expectException(TemplateError::class);
        $this->expectExceptionMessageMatches('/Cannot render a value of type array/');

        (new TemplateEngine($this->templates, $this->cache))->render('arr', ['items' => [1, 2]]);
    }

    /**
     * Template data must never be able to overwrite the renderer handle the
     * compiled code depends on.
     */
    public function test_template_data_cannot_clobber_the_renderer(): void
    {
        $engine = new TemplateEngine($this->templates, $this->cache);

        self::assertSame('hello ada', $engine->render('page', ['name' => 'ada', '__r' => 'hijacked']));
    }

    /**
     * Site-wide values every page needs — the base URL, the running SAPI —
     * without threading them through every controller.
     */
    public function test_shared_values_reach_every_template(): void
    {
        file_put_contents($this->templates . '/shared.orbit.php', 'at {{ $appUrl }}');

        $engine = new TemplateEngine($this->templates, $this->cache, shared: ['appUrl' => 'https://example.test']);

        self::assertSame('at https://example.test', $engine->render('shared'));
    }

    /**
     * A page must be able to override a shared value for itself without
     * changing what any other page sees.
     */
    public function test_per_render_data_wins_over_shared_data(): void
    {
        $engine = new TemplateEngine($this->templates, $this->cache, shared: ['name' => 'shared']);

        self::assertSame('hello local', $engine->render('page', ['name' => 'local']));
        self::assertSame('hello shared', $engine->render('page'), 'the override did not persist');
    }

    public function test_exists_reports_availability(): void
    {
        $engine = new TemplateEngine($this->templates, $this->cache);

        self::assertTrue($engine->exists('page'));
        self::assertFalse($engine->exists('absent'));
    }
}
