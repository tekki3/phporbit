<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Middleware;

use PhpOrbit\Http\Exception\MalformedRequest;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\Status;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Kernel\Blueprint;
use PhpOrbit\Middleware\ServeStaticFiles;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class ServeStaticFilesTest extends TestCase
{
    private string $root;

    private string $outside;

    private string $docs;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/orbit-static-' . bin2hex(random_bytes(6));

        $this->root = $base . '/public';
        $this->outside = $base . '/private';
        $this->docs = $base . '/docs';

        mkdir($this->root . '/assets', 0o750, true);
        mkdir($this->outside, 0o750, true);
        mkdir($this->docs, 0o750, true);

        file_put_contents($this->root . '/assets/app.css', 'body{color:red}');
        file_put_contents($this->root . '/.env', 'SECRET=hunter2');
        file_put_contents($this->outside . '/secrets.txt', 'do not serve me');
        file_put_contents($this->docs . '/index.html', '<h1>Docs</h1>');
        file_put_contents($this->docs . '/guide.html', '<h1>Guide</h1>');
    }

    protected function tearDown(): void
    {
        foreach (['/assets/app.css', '/.env'] as $file) {
            @unlink($this->root . $file);
        }

        @unlink($this->outside . '/secrets.txt');
        @unlink($this->docs . '/index.html');
        @unlink($this->docs . '/guide.html');
        @rmdir($this->root . '/assets');
        @rmdir($this->root);
        @rmdir($this->outside);
        @rmdir($this->docs);
        @rmdir(dirname($this->root));
    }

    public function test_it_serves_a_file_with_the_right_type(): void
    {
        $response = $this->application()->handle(Requests::get('/assets/app.css'));

        self::assertSame(Status::Ok, $response->status);
        self::assertSame('body{color:red}', $response->body);
        self::assertSame('text/css; charset=utf-8', $response->headers->first('Content-Type'));
        self::assertNotNull($response->headers->first('ETag'));
    }

    public function test_it_falls_through_to_the_application(): void
    {
        $response = $this->application()->handle(Requests::get('/'));

        self::assertSame('app', $response->body);
    }

    public function test_a_matching_etag_yields_304(): void
    {
        $app = $this->application();
        $etag = (string) $app->handle(Requests::get('/assets/app.css'))->headers->first('ETag');

        $response = $app->handle(Requests::of(
            Method::Get,
            '/assets/app.css',
            ['If-None-Match' => $etag],
        ));

        self::assertSame(Status::NotModified, $response->status);
        self::assertSame('', $response->wireBody());
    }

    /**
     * Dotfiles sit next to application code and are the first thing probed.
     */
    public function test_dotfiles_are_never_served(): void
    {
        self::assertSame(Status::NotFound, $this->application()->handle(Requests::get('/.env'))->status);
    }

    /**
     * Traversal is stopped a layer earlier than this middleware.
     *
     * By the time a request exists its path has been decoded and resolved, and
     * one that climbs above the root was rejected outright — so the file
     * server is never asked the question at all.
     */
    public function test_traversal_is_rejected_before_the_request_is_built(): void
    {
        $this->expectException(MalformedRequest::class);

        Requests::get('/assets/%2E%2E/%2E%2E/private/secrets.txt');
    }

    /**
     * Traversal that stays within the root resolves harmlessly.
     */
    public function test_in_bounds_dot_segments_resolve_normally(): void
    {
        $response = $this->application()->handle(Requests::get('/assets/%2E%2E/assets/app.css'));

        self::assertSame(Status::Ok, $response->status);
        self::assertSame('body{color:red}', $response->body);
    }

    public function test_a_symlink_pointing_outside_the_root_is_refused(): void
    {
        $link = $this->root . '/leak.txt';

        if (!@symlink($this->outside . '/secrets.txt', $link)) {
            self::markTestSkipped('symlinks are unavailable here');
        }

        try {
            $response = $this->application()->handle(Requests::get('/leak.txt'));

            self::assertSame('app', $response->body, 'a symlink out of the root must not be served');
        } finally {
            @unlink($link);
        }
    }

    /**
     * A POST to a static path is not a file request; it falls through to the
     * router, which has no such route.
     */
    public function test_non_get_methods_fall_through(): void
    {
        $response = $this->application()->handle(Requests::post('/assets/app.css'));

        self::assertSame(Status::NotFound, $response->status);
        self::assertStringNotContainsString('body{color:red}', $response->body);
    }

    // --- mounting a second root under a prefix --------------------------------

    public function test_a_prefixed_root_serves_its_files(): void
    {
        $response = $this->mounted()->handle(Requests::get('/docs/guide.html'));

        self::assertSame(Status::Ok, $response->status);
        self::assertSame('<h1>Guide</h1>', $response->body);
        self::assertSame('text/html; charset=utf-8', $response->headers->first('Content-Type'));
    }

    public function test_a_prefixed_root_serves_its_directory_index(): void
    {
        foreach (['/docs', '/docs/'] as $path) {
            $response = $this->mounted()->handle(Requests::get($path));

            self::assertSame(Status::Ok, $response->status, $path);
            self::assertSame('<h1>Docs</h1>', $response->body, $path);
        }
    }

    /**
     * The boundary matters: "/docsomething" is a different path and must not be
     * answered by the "/docs" mount.
     */
    public function test_a_prefix_only_matches_on_a_segment_boundary(): void
    {
        $response = $this->mounted()->handle(Requests::get('/docsomething'));

        self::assertSame(Status::NotFound, $response->status);
    }

    public function test_a_prefixed_root_cannot_reach_outside_itself(): void
    {
        // The traversal is resolved by Uri before matching, so this asks the
        // docs mount for a file that simply is not under it.
        $response = $this->mounted()->handle(Requests::get('/docs/assets/app.css'));

        self::assertSame(Status::NotFound, $response->status);
    }

    public function test_the_unprefixed_root_does_not_answer_the_prefixed_path(): void
    {
        // /docs/guide.html must come from the docs mount, never from public/.
        $application = Application::boot(function (Blueprint $app): void {
            $app->middleware(new ServeStaticFiles($this->root));

            $app->routes->get('/', static fn (): Response => Response::text('app'));
        });

        self::assertSame(Status::NotFound, $application->handle(Requests::get('/docs/guide.html'))->status);
    }

    /**
     * The regression this allowlist exists for.
     *
     * With an octet-stream fallback, every file under the root was downloadable
     * verbatim — including public/index.php, the front controller. A static
     * file server must never hand out source.
     */
    public function test_php_source_is_never_served(): void
    {
        file_put_contents($this->root . '/index.php', '<?php $secret = "hunter2";');
        file_put_contents($this->root . '/legacy.phtml', '<?php echo $secret;');

        try {
            foreach (['/index.php', '/legacy.phtml'] as $path) {
                $response = $this->application()->handle(Requests::get($path));

                self::assertSame(Status::NotFound, $response->status, $path);
                self::assertStringNotContainsString('hunter2', $response->body, $path);
                self::assertStringNotContainsString('<?php', $response->body, $path);
            }
        } finally {
            @unlink($this->root . '/index.php');
            @unlink($this->root . '/legacy.phtml');
        }
    }

    /**
     * An extension we have no type for is not guessed at, and not downloaded
     * either — it simply is not ours to serve.
     */
    public function test_an_unlisted_extension_falls_through(): void
    {
        file_put_contents($this->root . '/backup.sqlite', 'rows');

        try {
            $response = $this->application()->handle(Requests::get('/backup.sqlite'));

            self::assertSame(Status::NotFound, $response->status);
            self::assertStringNotContainsString('rows', $response->body);
        } finally {
            @unlink($this->root . '/backup.sqlite');
        }
    }

    private function application(): Application
    {
        $root = $this->root;

        return Application::boot(static function (Blueprint $app) use ($root): void {
            $app->middleware(new ServeStaticFiles($root));

            $app->routes->get('/', static fn (): Response => Response::text('app'));
            $app->routes->get('/leak.txt', static fn (): Response => Response::text('app'));
        });
    }

    /**
     * Two roots: public at "/", documentation at "/docs".
     */
    private function mounted(): Application
    {
        $public = $this->root;
        $docs = $this->docs;

        return Application::boot(static function (Blueprint $app) use ($public, $docs): void {
            $app->middleware(
                new ServeStaticFiles($public),
                new ServeStaticFiles($docs, prefix: '/docs'),
            );

            $app->routes->get('/', static fn (): Response => Response::text('app'));
        });
    }
}
