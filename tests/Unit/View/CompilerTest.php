<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\View;

use PhpOrbit\View\TemplateEngine;
use PHPUnit\Framework\TestCase;

/**
 * Compiles and renders real templates rather than asserting on generated PHP.
 *
 * What matters is the output a browser receives; the intermediate code is an
 * implementation detail free to change.
 */
final class CompilerTest extends TestCase
{
    private string $templates;

    private string $cache;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/orbit-views-' . bin2hex(random_bytes(6));

        $this->templates = $base . '/templates';
        $this->cache = $base . '/cache';

        mkdir($this->templates, 0o750, true);
        mkdir($this->cache, 0o750, true);
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

    public function test_double_braces_escape_their_value(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $this->render('{{ $x }}', ['x' => '<script>alert(1)</script>']),
        );
    }

    public function test_the_raw_directive_emits_markup_unchanged(): void
    {
        self::assertSame('<em>hi</em>', $this->render('{!! $x !!}', ['x' => '<em>hi</em>']));
    }

    public function test_at_braces_render_literal_delimiters(): void
    {
        self::assertSame('{{ $name }}', $this->render('@{{ $name }}', []));
    }

    /**
     * The raw delimiters need the same escape as the escaped ones.
     *
     * Without it, a page documenting `{!! !!}` compiles to broken PHP: the
     * non-greedy pattern matches the inner opening and treats the fragment
     * before it as an expression. The starter template hit exactly this.
     */
    public function test_at_raw_delimiters_render_literally(): void
    {
        self::assertSame('{!! $name !!}', $this->render('@{!! $name !!}', []));
    }

    /**
     * Both escapes in one template, mixed with real output.
     */
    public function test_literal_and_real_delimiters_coexist(): void
    {
        self::assertSame(
            'escaped {{ x }} raw {!! x !!} value Ada',
            $this->render('escaped @{{ x }} raw @{!! x !!} value {{ $name }}', ['name' => 'Ada']),
        );
    }

    public function test_comments_are_removed(): void
    {
        self::assertSame('ab', $this->render('a{# not output #}b', []));
    }

    /**
     * Templates are developer-written, but a stray `<?` should render as text
     * rather than silently opening a PHP block.
     */
    public function test_literal_php_tags_are_neutralised(): void
    {
        self::assertSame('<?php echo 1; ?>', $this->render('<?php echo 1; ?>', []));
    }

    public function test_conditionals(): void
    {
        $template = '@if($n > 2)big@elseif($n === 2)two@else small@endif';

        self::assertSame('big', $this->render($template, ['n' => 5]));
        self::assertSame('two', $this->render($template, ['n' => 2]));
        self::assertSame(' small', $this->render($template, ['n' => 1]));
    }

    public function test_loops(): void
    {
        self::assertSame(
            'a,b,c,',
            $this->render('@foreach($items as $i){{ $i }},@endforeach', ['items' => ['a', 'b', 'c']]),
        );
    }

    /**
     * Nested parentheses in a condition must not truncate the directive.
     */
    public function test_a_condition_may_contain_nested_parentheses(): void
    {
        self::assertSame(
            'yes',
            $this->render('@if(count($items) > 0)yes@endif', ['items' => [1]]),
        );
    }

    public function test_layouts_and_sections(): void
    {
        $this->write('layout', '<html>@yield("title")|@yield("content")</html>');

        $output = $this->render(
            '@extends("layout")@section("title")T@endsection@section("content")C@endsection',
            [],
        );

        self::assertSame('<html>T|C</html>', $output);
    }

    /**
     * A child with no explicit content section still fills the layout's slot,
     * so the simplest possible page needs no boilerplate.
     */
    public function test_child_output_becomes_the_implicit_content_section(): void
    {
        $this->write('layout', '[@yield("content")]');

        self::assertSame('[hello]', $this->render('@extends("layout")hello', []));
    }

    public function test_includes_share_the_parent_data(): void
    {
        $this->write('partial', 'partial:{{ $name }}');

        self::assertSame('partial:ada', $this->render('@include("partial")', ['name' => 'ada']));
    }

    public function test_an_unknown_at_word_passes_through(): void
    {
        self::assertSame(
            'write to hello@example.test',
            $this->render('write to hello@example.test', []),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $source, array $data): string
    {
        $this->write('page', $source);

        return $this->engine()->render('page', $data);
    }

    private function write(string $name, string $source): void
    {
        file_put_contents($this->templates . '/' . $name . '.orbit.php', $source);
    }

    private function engine(): TemplateEngine
    {
        return new TemplateEngine($this->templates, $this->cache, alwaysRecompile: true);
    }
}
