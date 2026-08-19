<?php

declare(strict_types=1);

namespace PhpOrbit\Admin;

/**
 * The one piece of plain configuration the admin controllers need: where the
 * project they are administering lives on disk.
 *
 * Autowired classes may only take object-typed constructor parameters — the
 * container refuses to invent a scalar nobody chose — so a bare `string $root`
 * cannot be a dependency directly. This is the object that carries it,
 * registered once as a singleton in {@see AdminApplication::boot()}.
 */
final class ProjectPaths
{
    public function __construct(
        public readonly string $root,
    ) {
    }

    public function routesFile(): string
    {
        return $this->root . '/app/routes.php';
    }
}
