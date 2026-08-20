<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use RuntimeException;

/**
 * Raised by {@see Model::findOrFail()} when no row matches the id.
 */
final class ModelNotFound extends RuntimeException
{
}
