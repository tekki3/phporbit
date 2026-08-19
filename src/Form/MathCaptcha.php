<?php

declare(strict_types=1);

namespace PhpOrbit\Form;

use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Http\ServerRequest;

/**
 * A small arithmetic question, answered in a text box.
 *
 * Chosen for what it does not require: no JavaScript, no third-party script, no
 * images, no requests leaving your server, and nothing for a screen reader to
 * struggle with — a distorted-image captcha fails all five.
 *
 * **What it is worth being clear about:** this stops undirected scripts, the
 * ones that post to every form they find. It will not stop someone who has
 * decided to attack *you*, because a language model solves arithmetic. Treat it
 * as one layer with the honeypot and rate limiting, not as a wall. If you need
 * to resist a determined attacker, implement {@see Captcha} against a service
 * built for that job.
 *
 * The answer is encrypted rather than signed, because a signed value is still
 * readable — the visitor could simply read the answer out of the page source.
 */
final class MathCaptcha implements Captcha
{
    /** Spelling some numbers as words defeats the obvious regex. */
    private const WORDS = [
        1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
        6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
    ];

    public function __construct(
        private readonly Encrypter $encrypter,
        /** How long an issued challenge stays answerable. */
        private readonly int $ttlSeconds = 900,
        private readonly string $answerField = 'captcha',
        private readonly string $sealedField = '_captcha',
    ) {
    }

    public function challenge(string $context): Challenge
    {
        $left = random_int(1, 10);
        $right = random_int(1, 9);

        // Only addition and multiplication: subtraction invites a negative
        // answer, and division a fractional one, both of which produce a
        // question a person answers correctly and the check then rejects.
        $isSum = random_int(0, 1) === 1;

        $answer = $isSum ? $left + $right : $left * $right;

        $question = sprintf(
            'What is %s %s %s?',
            $this->spell($left),
            $isSum ? 'plus' : 'times',
            $this->spell($right),
        );

        $sealed = $this->encrypter->encrypt(
            $this->expiryPrefix() . ':' . $answer,
            $context,
        );

        return new Challenge($question, $sealed);
    }

    public function answerField(): string
    {
        return $this->answerField;
    }

    public function sealedField(): string
    {
        return $this->sealedField;
    }

    public function isCorrect(ServerRequest $request, string $context): bool
    {
        $sealed = $request->form($this->sealedField);
        $given = $request->form($this->answerField);

        if ($sealed === null || $given === null) {
            return false;
        }

        $payload = $this->encrypter->tryDecrypt($sealed, $context);

        // Wrong key, tampering, or a challenge issued for a different visitor.
        if ($payload === null) {
            return false;
        }

        $separator = strpos($payload, ':');

        if ($separator === false) {
            return false;
        }

        $expiresAt = (int) substr($payload, 0, $separator);
        $answer = substr($payload, $separator + 1);

        if ($expiresAt < time()) {
            return false;
        }

        // People type "12 ", " 12" and occasionally "twelve"; the first two are
        // worth accepting, and the third is not worth guessing at.
        return trim($given) === $answer;
    }

    private function expiryPrefix(): string
    {
        return (string) (time() + $this->ttlSeconds);
    }

    private function spell(int $number): string
    {
        // Mixed spelling, decided per number, so the same question renders
        // differently for different visitors.
        return random_int(0, 1) === 1
            ? (self::WORDS[$number] ?? (string) $number)
            : (string) $number;
    }
}
