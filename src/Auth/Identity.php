<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

/**
 * Something that can log in.
 *
 * Kept to the two things authentication actually needs. An application's user
 * model will carry far more, but nothing in this namespace should depend on
 * any of it.
 */
interface Identity
{
    /**
     * A stable, opaque identifier stored in the session.
     */
    public function authIdentifier(): string;

    /**
     * The stored password hash to verify against.
     */
    public function passwordHash(): string;
}
