<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

/**
 * What `make:middleware` produced, and the line still to be written by hand.
 */
final class GeneratedMiddleware
{
    public function __construct(
        /** Fully qualified, e.g. App\Middleware\RequestIdMiddleware */
        public readonly string $className,
        /** Project-relative, e.g. app/src/Middleware/RequestIdMiddleware.php */
        public readonly string $path,
        /** The import the registration line needs */
        public readonly string $importSnippet,
        /** The `new X(),` entry for the $app->middleware(...) list */
        public readonly string $registrationSnippet,
    ) {
    }
}
