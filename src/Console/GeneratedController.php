<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

/**
 * What `make:controller` produced, and the line still to be written by hand.
 */
final class GeneratedController
{
    public function __construct(
        /** Fully qualified, e.g. App\Controllers\Admin\UsersController */
        public readonly string $className,
        /** Project-relative, e.g. app/src/Controllers/Admin/UsersController.php */
        public readonly string $controllerPath,
        /** Project-relative template, when one was requested */
        public readonly ?string $templatePath,
        /** The route declaration to paste into app/routes.php */
        public readonly string $routeSnippet,
        /** The import that declaration needs */
        public readonly string $importSnippet,
    ) {
    }
}
