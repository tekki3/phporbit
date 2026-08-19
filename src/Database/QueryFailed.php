<?php

declare(strict_types=1);

namespace PhpOrbit\Database;

use PDOException;
use RuntimeException;

/**
 * A failed statement.
 *
 * The SQL is carried in the message because it is written by the developer and
 * is what makes the failure diagnosable. The bound parameters are deliberately
 * left out: they are user data and routinely contain passwords, tokens and
 * personal information that would then reach logs and error pages.
 */
final class QueryFailed extends RuntimeException
{
    public static function from(PDOException $previous, string $sql): self
    {
        return new self(
            sprintf('Query failed: %s -- SQL: %s', $previous->getMessage(), $sql),
            0,
            $previous,
        );
    }
}
