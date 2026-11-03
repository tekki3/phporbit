<?php

declare(strict_types=1);

namespace PhpOrbit\Log;

final class ContextLogger implements Logger
{
    /** @param array<string, scalar|null|array<array-key, mixed>> $context */
    public function __construct(
        private readonly Logger $logger,
        private readonly array $context,
    ) {
    }

    public function log(Level $level, string $message, array $context = []): void
    {
        $this->logger->log($level, $message, [...$context, ...$this->context]);
    }
}
