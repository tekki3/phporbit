<?php

declare(strict_types=1);

namespace PhpOrbit\Container\Exception;

use LogicException;

/**
 * Raised when a class cannot be constructed without explicit registration.
 *
 * The message always names the parameter that blocked resolution, because the
 * fix is to register that dependency rather than to change the class.
 */
final class CannotAutowire extends LogicException
{
}
