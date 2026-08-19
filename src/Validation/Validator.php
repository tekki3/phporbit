<?php

declare(strict_types=1);

namespace PhpOrbit\Validation;

use PhpOrbit\Http\ServerRequest;

/**
 * Checks submitted form fields.
 *
 * Rules accumulate at most one error per field — the first failure — because
 * telling someone their password is too short *and* lacks a digit *and* lacks
 * a symbol, all at once, is how forms become unusable.
 *
 * Created per request and thrown away; never share one between requests.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, string> $input
     */
    public function __construct(
        private readonly array $input,
    ) {
    }

    public static function forRequest(ServerRequest $request): self
    {
        return new self($request->formData());
    }

    public function value(string $field): ?string
    {
        $value = $this->input[$field] ?? null;

        return $value === null ? null : trim($value);
    }

    public function required(string $field, ?string $message = null): self
    {
        $value = $this->value($field);

        if ($value === null || $value === '') {
            $this->fail($field, $message ?? sprintf('%s is required.', $this->label($field)));
        }

        return $this;
    }

    public function maxLength(string $field, int $max, ?string $message = null): self
    {
        $value = $this->value($field);

        if ($value !== null && mb_strlen($value) > $max) {
            $this->fail($field, $message ?? sprintf(
                '%s must be %d characters or fewer.',
                $this->label($field),
                $max,
            ));
        }

        return $this;
    }

    public function minLength(string $field, int $min, ?string $message = null): self
    {
        $value = $this->value($field);

        if ($value !== null && $value !== '' && mb_strlen($value) < $min) {
            $this->fail($field, $message ?? sprintf(
                '%s must be at least %d characters.',
                $this->label($field),
                $min,
            ));
        }

        return $this;
    }

    public function integer(string $field, ?string $message = null): self
    {
        $value = $this->value($field);

        if ($value !== null && $value !== '' && preg_match('/^-?\d+$/', $value) !== 1) {
            $this->fail($field, $message ?? sprintf('%s must be a whole number.', $this->label($field)));
        }

        return $this;
    }

    /**
     * A deliberately permissive address check.
     *
     * Strict RFC 5322 conformance rejects addresses that genuinely work; the
     * only real proof an address is valid is sending mail to it.
     */
    public function email(string $field, ?string $message = null): self
    {
        $value = $this->value($field);

        if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($field, $message ?? sprintf('%s must be a valid email address.', $this->label($field)));
        }

        return $this;
    }

    /**
     * @param list<string> $allowed
     */
    public function in(string $field, array $allowed, ?string $message = null): self
    {
        $value = $this->value($field);

        if ($value !== null && $value !== '' && !in_array($value, $allowed, true)) {
            $this->fail($field, $message ?? sprintf('%s is not a permitted value.', $this->label($field)));
        }

        return $this;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The validated value of a field known to have passed.
     */
    public function validated(string $field): string
    {
        return $this->value($field) ?? '';
    }

    private function fail(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
