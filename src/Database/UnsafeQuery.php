<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use LogicException;

/**
 * Raised when a query would affect every row without saying so.
 *
 * A forgotten `where()` on an UPDATE or DELETE is one of the most expensive
 * mistakes available in a few keystrokes, and it is indistinguishable from a
 * deliberate whole-table change unless the caller states which they meant.
 */
final class UnsafeQuery extends LogicException
{
}
