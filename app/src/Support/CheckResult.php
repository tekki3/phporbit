<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One line on the self-check page.
 */
final class CheckResult
{
    private function __construct(
        public readonly string $name,
        public readonly bool $passed,
        public readonly string $detail,
    ) {
    }

    public static function pass(string $name, string $detail): self
    {
        return new self($name, true, $detail);
    }

    public static function fail(string $name, string $detail): self
    {
        return new self($name, false, $detail);
    }

    /**
     * Records a check as passed only if the condition holds.
     */
    public static function of(string $name, bool $condition, string $whenTrue, string $whenFalse): self
    {
        return $condition ? self::pass($name, $whenTrue) : self::fail($name, $whenFalse);
    }
}
