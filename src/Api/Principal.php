<?php

declare(strict_types=1);

namespace PhpOrbit\Api;

use PhpOrbit\Auth\Identity;

final class Principal
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly Identity $user,
        public readonly string $tokenId,
        public readonly array $scopes,
    ) {
    }

    public function permits(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
