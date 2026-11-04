<?php

declare(strict_types=1);

namespace PhpOrbit\Ai;

interface Provider
{
    public function endpoint(): string;

    /** @return array<string, scalar|array<array-key, mixed>> */
    public function body(Request $request): array;

    /** @return array<string, string> */
    public function headers(Request $request): array;

    public function text(string $json): string;

    /**
     * @param iterable<string> $bytes
     * @return iterable<string>
     */
    public function chunks(iterable $bytes): iterable;
}
