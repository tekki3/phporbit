<?php

declare(strict_types=1);

namespace PhpOrbit\Routing\Exception;

use LogicException;

/**
 * Raised at boot when a route pattern cannot be compiled.
 *
 * Failing here means a misconfigured application never starts serving, rather
 * than returning 500s from one particular URL.
 */
final class InvalidRoutePattern extends LogicException
{
}
