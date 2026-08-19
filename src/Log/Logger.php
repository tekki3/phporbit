<?php

declare(strict_types=1);

namespace PhpOrbit\Log;

interface Logger
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function log(Level $level, string $message, array $context = []): void;
}
