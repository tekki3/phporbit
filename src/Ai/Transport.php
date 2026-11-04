<?php

declare(strict_types=1);

namespace PhpOrbit\Ai;

interface Transport
{
    /**
     * @param array<string, string> $headers
     * @param array<string, scalar|array<array-key, mixed>> $body
     * @return iterable<string> Raw response bytes, bounded and checked for HTTP errors.
     */
    public function request(Request $request, array $headers, array $body): iterable;
}
