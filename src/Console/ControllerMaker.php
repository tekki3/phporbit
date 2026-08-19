<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Writes a controller class, and optionally the template it renders.
 *
 * What it deliberately does **not** do is edit `app/routes.php`. Rewriting a
 * file the developer owns means parsing and re-emitting their code, and getting
 * that subtly wrong is worse than not doing it at all — so the route line is
 * printed for them to paste. One line, in the place they can see it.
 *
 * Lives in `src/` rather than the CLI script so a scaffolded project gets the
 * same command; progress is reported through a callback rather than a stream,
 * because nothing in `src/` may assume a SAPI.
 */
final class ControllerMaker
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
     * @param string $name      `Home`, `HomeController` or `Admin/Users`
     * @param bool   $withView  also write a template, and render it
     */
    public function create(string $name, bool $withView = false, bool $force = false): GeneratedController
    {
        $segments = $this->parseName($name);
        $class = array_pop($segments);

        $namespace = implode('\\', ['App', 'Controllers', ...$segments]);
        $className = $namespace . '\\' . $class;

        $relativeDirectory = implode('/', ['app', 'src', 'Controllers', ...$segments]);
        $controllerPath = $relativeDirectory . '/' . $class . '.php';

        // "Admin/Users" -> "admin/users"; "UserProfile" -> "user-profile".
        $templateName = implode('/', array_map(
            $this->toKebabCase(...),
            [...$segments, substr($class, 0, -strlen('Controller'))],
        ));

        $templatePath = $withView ? 'app/templates/' . $templateName . '.orbit.php' : null;

        $this->write($controllerPath, $this->controllerSource($namespace, $class, $withView, $templateName), $force);

        if ($templatePath !== null) {
            $this->write($templatePath, $this->templateSource(), $force);
        }

        $routePath = '/' . $templateName;

        return new GeneratedController(
            $className,
            $controllerPath,
            $templatePath,
            sprintf(
                "\$routes->get('%s', %s::class, '%s');",
                $routePath,
                $class,
                str_replace('/', '.', $templateName),
            ),
            sprintf('use %s;', $className),
        );
    }

    /**
     * Splits `Admin/Users` into class-name segments, rejecting anything that
     * is not a plain StudlyCase identifier.
     *
     * A name reaching here from a script argument must never be able to place a
     * file outside `app/src/Controllers`, so this validates rather than
     * sanitises — there is no "clean up and continue" path.
     *
     * @return non-empty-list<string>
     */
    private function parseName(string $name): array
    {
        $segments = preg_split('#[/\\\\]+#', trim($name, "/\\ \t")) ?: [];
        $parsed = [];

        foreach ($segments as $segment) {
            if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $segment) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid controller name "%s". Use StudlyCase, optionally nested: '
                    . 'Home, UserProfile, Admin/Users.',
                    $name,
                ));
            }

            $parsed[] = $segment;
        }

        if ($parsed === []) {
            throw new InvalidArgumentException('A controller name is required, for example: Home');
        }

        $last = array_key_last($parsed);

        // "Home" and "HomeController" should both give HomeController.
        if (!str_ends_with($parsed[$last], 'Controller')) {
            $parsed[$last] .= 'Controller';
        }

        return $parsed;
    }

    private function controllerSource(string $namespace, string $class, bool $withView, string $template): string
    {
        $imports = [
            'PhpOrbit\Http\Response',
            'PhpOrbit\Http\ServerRequest',
            'PhpOrbit\Routing\Handler',
        ];

        if ($withView) {
            $imports[] = 'PhpOrbit\View\TemplateEngine';
        }

        sort($imports);
        $useLines = implode("\n", array_map(static fn (string $i): string => 'use ' . $i . ';', $imports));

        // Members are assembled with their own indentation and joined, rather
        // than interpolated into one another — nesting heredocs is how the
        // first version of this produced a method at column zero.
        $members = [];

        if ($withView) {
            $members[] = <<<'PHP'
                public function __construct(
                    private readonly TemplateEngine $view,
                ) {
                }
                PHP;
        }

        $action = $withView
            ? sprintf(
                "return \$this->view->respond('%s', [\n        'title' => '%s',\n    ]);",
                $template,
                $this->toTitle($class),
            )
            : sprintf("return Response::text('%s');", $this->toTitle($class));

        $members[] = <<<PHP
            public function handle(ServerRequest \$request): Response
            {
                {$action}
            }
            PHP;

        $body = $this->indent(implode("\n\n", $members));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            {$useLines}

            /**
             * One class per route.
             *
             * Constructor dependencies are resolved from the request scope, per
             * request, so anything held here dies with the request it was built for.
             */
            final class {$class} implements Handler
            {
            {$body}
            }

            PHP;
    }

    /**
     * Indents every non-blank line by one level.
     */
    private function indent(string $block): string
    {
        $lines = array_map(
            static fn (string $line): string => $line === '' ? '' : '    ' . $line,
            explode("\n", $block),
        );

        return implode("\n", $lines);
    }

    /**
     * `UserProfileController` -> `User Profile`, for a default page heading.
     */
    private function toTitle(string $class): string
    {
        $name = str_ends_with($class, 'Controller')
            ? substr($class, 0, -strlen('Controller'))
            : $class;

        return trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name));
    }

    private function templateSource(): string
    {
        return <<<'HTML'
            @extends('layout')

            @section('content')
                <h1>{{ $title }}</h1>

                <p>Edit this template, and the controller that renders it.</p>
            @endsection

            HTML;
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

    private function toKebabCase(string $value): string
    {
        $spaced = preg_replace('/(?<!^)[A-Z]/', '-$0', $value) ?? $value;

        return strtolower($spaced);
    }
}
