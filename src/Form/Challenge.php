<?php

declare(strict_types=1);

namespace PhpOrbit\Form;

/**
 * A captcha as rendered: the question a person answers, and the answer itself
 * sealed so the browser cannot read it.
 */
final class Challenge
{
    public function __construct(
        /** Shown to the visitor, e.g. "What is seven plus 3?" */
        public readonly string $question,
        /** The answer, encrypted. Travels in a hidden field. */
        public readonly string $sealedAnswer,
    ) {
    }
}
