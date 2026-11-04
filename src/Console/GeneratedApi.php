<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

final class GeneratedApi
{
    /** @param list<string> $files */
    public function __construct(
        public readonly string $controllerPath,
        public readonly string $declarationPath,
        public readonly string $url,
        public readonly array $files,
    ) {
    }
}
