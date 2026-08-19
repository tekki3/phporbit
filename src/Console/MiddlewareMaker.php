<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use Closure;
use RuntimeException;

/**
 * Writes a middleware class implementing {@see \PhpOrbit\Middleware\Middleware}.
 *
 * Unlike a controller or an autowired class, a middleware instance is never
 * resolved by the container: `$app->middleware(...)` takes constructed objects
 * directly, in the exact order they run — and that order is meaning, not
 * plumbing (session before CSRF, logging outermost). Editing `app/bootstrap.php`
 * to insert one automatically would mean guessing where in that order it
 * belongs, which is a decision only the developer can make. So this writes the
 * class and prints the `new X(),` entry to place by hand, the same way
 * {@see ControllerMaker} leaves the route line to paste rather than rewriting
 * `app/routes.php`.
 *
 * Lives in `src/` rather than the CLI script so a scaffolded project gets the
 * same command; progress is reported through a callback rather than a stream,
 * because nothing in `src/` may assume a SAPI.
 */
final class MiddlewareMaker
{
    /** @var Closure(string): void */
    private readonly Closure $report;

    /**
     * @param string $root the project root, containing app/
     * @param (Closure(string): void)|null $report
     */
    public function __construct(
        private readonly string $root,
        ?Closure $report = null,
    ) {
        $this->report = $report ?? static function (string $line): void {
        };
    }

    /**
     * @param string $name `RequestId`, `RequestIdMiddleware` or `Admin/RequireApiKey`
     */
    public function create(string $name, bool $force = false): GeneratedMiddleware
    {
        $segments = PhpName::segments($name, 'middleware', 'RequestId, RateLimit, Admin/RequireApiKey');
        $class = array_pop($segments);

        // "RequestId" and "RequestIdMiddleware" should both give
        // RequestIdMiddleware, the same convention ControllerMaker applies.
        if (!str_ends_with($class, 'Middleware')) {
            $class .= 'Middleware';
        }

        $namespace = implode('\\', ['App', 'Middleware', ...$segments]);
        $className = $namespace . '\\' . $class;
        $relative = implode('/', ['app', 'src', 'Middleware', ...$segments]) . '/' . $class . '.php';

        $this->write($relative, $this->source($namespace, $class), $force);

        return new GeneratedMiddleware(
            $className,
            $relative,
            sprintf('use %s;', $className),
            sprintf('new %s(),', $class),
        );
    }

    private function source(string $namespace, string $class): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Closure;
            use PhpOrbit\\Container\\RequestScope;
            use PhpOrbit\\Http\\Response;
            use PhpOrbit\\Http\\ServerRequest;
            use PhpOrbit\\Middleware\\Middleware;

            /**
             * Registered in \$app->middleware(...), in app/bootstrap.php — where in
             * the list decides what this sees and what it can change. Every instance
             * is built once at boot and shared by every request the process serves,
             * so it must hold no mutable state of its own; anything specific to one
             * request comes from the RequestScope, never a property here.
             *
             * Return early, without calling \$next, to reject a request before the
             * handler ever runs — that is how CsrfMiddleware refuses a bad token.
             */
            final class {$class} implements Middleware
            {
                public function process(ServerRequest \$request, RequestScope \$scope, Closure \$next): Response
                {
                    return \$next(\$request);
                }
            }

            PHP;
    }

    /**
     * @param string $relative project-relative path
     */
    private function write(string $relative, string $contents, bool $force): void
    {
        $path = $this->root . '/' . $relative;

        if (is_file($path) && !$force) {
            throw new RuntimeException(sprintf(
                '%s already exists. Pass --force to overwrite it.',
                $relative,
            ));
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create directory "%s".', $directory));
        }

        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Cannot write "%s".', $relative));
        }

        ($this->report)('Created ' . $relative);
    }
}
