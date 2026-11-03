<?php

declare(strict_types=1);

namespace PhpOrbit\Auth\Oidc;

interface OidcHttp
{
    /** @param array<string, string> $form Empty for GET, otherwise POST. */
    public function request(string $url, array $form = []): string;
}
