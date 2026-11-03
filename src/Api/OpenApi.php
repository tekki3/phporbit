<?php

declare(strict_types=1);

namespace PhpOrbit\Api;

final class OpenApi
{
    /** @param array<string, mixed> $document OpenAPI JSON data. */
    public function __construct(private readonly array $document)
    {
        json_encode($document, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return $this->document;
    }
}
