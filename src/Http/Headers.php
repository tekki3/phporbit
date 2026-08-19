<?php

declare(strict_types=1);

namespace PhpOrbit\Http;

use PhpOrbit\Http\Exception\MalformedRequest;

/**
 * An immutable, case-insensitive header collection.
 *
 * Field names are compared lowercased but retain the casing they were
 * created with, so responses look conventional on the wire.
 */
final class Headers
{
    /** @var array<string, list<string>> keyed by lowercased name */
    private readonly array $values;

    /** @var array<string, string> lowercased name => original casing */
    private readonly array $names;

    /**
     * @param array<string, list<string>> $values
     * @param array<string, string>       $names
     */
    private function __construct(array $values, array $names)
    {
        $this->values = $values;
        $this->names = $names;
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function fromArray(array $headers): self
    {
        $result = self::empty();

        foreach ($headers as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $single) {
                $result = $result->add($name, $single);
            }
        }

        return $result;
    }

    public function has(string $name): bool
    {
        return isset($this->values[strtolower($name)]);
    }

    /**
     * The first value for a header, or null when absent.
     *
     * Most headers are single-valued; use {@see all()} when repetition matters.
     */
    public function first(string $name): ?string
    {
        return $this->values[strtolower($name)][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function all(string $name): array
    {
        return $this->values[strtolower($name)] ?? [];
    }

    /**
     * Replaces any existing values for this header.
     */
    public function with(string $name, string $value): self
    {
        $key = strtolower($this->assertValidName($name));

        $values = $this->values;
        $names = $this->names;
        $values[$key] = [$this->assertValidValue($value)];
        $names[$key] = $name;

        return new self($values, $names);
    }

    /**
     * Appends a value, keeping any already present.
     */
    public function add(string $name, string $value): self
    {
        $key = strtolower($this->assertValidName($name));

        $values = $this->values;
        $names = $this->names;
        $values[$key][] = $this->assertValidValue($value);
        $names[$key] ??= $name;

        return new self($values, $names);
    }

    public function without(string $name): self
    {
        $key = strtolower($name);

        $values = $this->values;
        $names = $this->names;
        unset($values[$key], $names[$key]);

        return new self($values, $names);
    }

    /**
     * Flattened to wire form: one entry per value, original casing preserved.
     *
     * @return list<array{string, string}>
     */
    public function toWire(): array
    {
        $lines = [];

        foreach ($this->values as $key => $values) {
            foreach ($values as $value) {
                $lines[] = [$this->names[$key] ?? $key, $value];
            }
        }

        return $lines;
    }

    /**
     * Header injection defence. A newline in a name or value would let an
     * attacker forge additional headers or split the response, so malformed
     * input is rejected outright rather than sanitised.
     */
    private function assertValidName(string $name): string
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new MalformedRequest(sprintf('Invalid header name "%s".', $name));
        }

        return $name;
    }

    private function assertValidValue(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new MalformedRequest('Header values may not contain CR, LF or NUL.');
        }

        return $value;
    }
}
