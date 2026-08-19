<?php

declare(strict_types=1);

namespace PhpOrbit\Http\Exception;

use RuntimeException;

/**
 * Raised when incoming bytes cannot be turned into a valid request.
 *
 * This is always attributable to the client, never to application code,
 * and is rendered as a 400.
 */
final class MalformedRequest extends RuntimeException
{
}
