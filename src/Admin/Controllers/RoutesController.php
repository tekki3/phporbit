<?php

declare(strict_types=1);

namespace PhpOrbit\Admin\Controllers;

use PhpOrbit\Admin\AdminApplication;
use PhpOrbit\Admin\ProjectPaths;
use PhpOrbit\Http\Response;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Routing\Handler;
use PhpOrbit\View\TemplateEngine;

/**
 * The project's own compiled route table — read from `app/routes.php`
 * directly, the same source `orbit routes` prints, never by re-serving those
 * routes on the admin app's own router.
 */
final class RoutesController implements Handler
{
    public function __construct(
        private readonly TemplateEngine $view,
        private readonly ProjectPaths $paths,
    ) {
    }

    public function handle(ServerRequest $request): Response
    {
        $routes = AdminApplication::projectRoutes($this->paths, false);

        $rows = array_map(static fn ($route): array => [
            'method' => $route->method->value,
            'pattern' => $route->pattern,
            'name' => $route->name ?? '',
        ], $routes);

        usort($rows, static fn (array $a, array $b): int => [$a['pattern'], $a['method']] <=> [$b['pattern'], $b['method']]);

        return $this->view->respond('routes', [
            'title' => 'Routes',
            'subtitle' => 'The compiled route table, read straight from app/routes.php.',
            'currentPath' => '/routes',
            'rows' => $rows,
        ]);
    }
}
