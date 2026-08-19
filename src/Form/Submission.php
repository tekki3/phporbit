<?php

declare(strict_types=1);

namespace PhpOrbit\Form;

use LogicException;

/**
 * What came back from a submitted form.
 *
 * `values()` is only reachable once the submission has passed, so there is no
 * path that reads a field the checks rejected.
 */
final class Submission
{
    /** @var array<string, string> */
    private readonly array $values;

    /** @var array<string, string> */
    private readonly array $errors;

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors field => message
     */
    private function __construct(
        public readonly bool $accepted,
        array $values,
        array $errors,
        /** Set when the submission looked automated. For your logs, not the page. */
        public readonly ?string $rejectedAs = null,
    ) {
        $this->values = $values;
        $this->errors = $errors;
    }

    /**
     * @param array<string, string> $values
     */
    public static function accepted(array $values): self
    {
        return new self(true, $values, []);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    public static function rejected(array $values, array $errors, ?string $rejectedAs = null): self
    {
        return new self(false, $values, $errors, $rejectedAs);
    }

    public function failed(): bool
    {
        return !$this->accepted;
    }

    /**
     * The validated values.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        if (!$this->accepted) {
            throw new LogicException(
                'This submission was rejected; its values have not been validated. '
                . 'Check failed() first, and use old() to repopulate the form.',
            );
        }

        return $this->values;
    }

    public function value(string $field): string
    {
        return $this->values()[$field] ?? '';
    }

    /**
     * What the visitor typed, valid or not, for redisplaying the form.
     *
     * @return array<string, string>
     */
    public function old(): array
    {
        return $this->values;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * Whether this looked like a script rather than a person.
     *
     * Worth logging, and worth treating differently from a validation failure —
     * but the page should say the same thing either way.
     */
    public function looksAutomated(): bool
    {
        return $this->rejectedAs !== null;
    }
}
