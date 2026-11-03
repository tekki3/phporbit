<?php

declare(strict_types=1);

namespace PhpOrbit\Log;

interface Logger
{
    /**
     * Nested arrays may contain only scalars, null and other arrays.
     *
     * @param array<string, scalar|null|array<array-key, mixed>> $context
     */
    public function log(Level $level, string $message, array $context = []): void;
}
