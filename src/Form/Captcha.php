<?php

declare(strict_types=1);

namespace PhpOrbit\Form;

use PhpOrbit\Http\ServerRequest;

/**
 * A challenge a person can answer and a script, ideally, cannot.
 *
 * The interface exists so the built-in {@see MathCaptcha} can be swapped for a
 * hosted service. Be aware that most of those need JavaScript, which this
 * framework's own pages deliberately do without — the choice is yours to make
 * in your application, not one made for you here.
 */
interface Captcha
{
    /**
     * Builds a fresh challenge.
     *
     * `$context` binds the sealed answer to the visitor, so one solved in
     * someone else's session cannot be pasted into yours.
     */
    public function challenge(string $context): Challenge;

    /**
     * The name of the input the visitor types their answer into.
     */
    public function answerField(): string;

    /**
     * The name of the hidden input carrying the sealed answer.
     */
    public function sealedField(): string;

    /**
     * Whether the submitted answer matches the challenge it was issued for.
     */
    public function isCorrect(ServerRequest $request, string $context): bool;
}
