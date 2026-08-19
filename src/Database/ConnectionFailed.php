<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use RuntimeException;

/**
 * The database could not be reached.
 *
 * Distinct from {@see QueryFailed}: this one means the application cannot work
 * at all, whereas a failed query may be a bug in one statement. Both keep
 * credentials out of their messages.
 */
final class ConnectionFailed extends RuntimeException
{
}
