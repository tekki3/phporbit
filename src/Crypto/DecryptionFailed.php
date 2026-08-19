<?php

declare(strict_types=1);

namespace PhpOrbit\Crypto;

use RuntimeException;

/**
 * A value could not be decrypted or its signature did not verify.
 *
 * There is deliberately one exception and one message for every cause — wrong
 * key, tampered ciphertext, truncated token, wrong context. Distinguishing them
 * would tell an attacker which part of a forgery attempt was closest, which is
 * exactly the feedback that turns guessing into an attack.
 */
final class DecryptionFailed extends RuntimeException
{
    public static function create(): self
    {
        return new self(
            'The value could not be decrypted. It was tampered with, encrypted with a '
            . 'different key, or is not a phporbit token.',
        );
    }
}
