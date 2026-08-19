<?php

declare(strict_types=1);

namespace PhpOrbit\Routing;

use LogicException;

/**
 * Raised when a link cannot be generated from a route name.
 *
 * Always a programming error — a typo'd name or a missing parameter — so it
 * throws rather than returning a placeholder URL that would 404 later.
 */
final class UnknownRoute extends LogicException
{
}
