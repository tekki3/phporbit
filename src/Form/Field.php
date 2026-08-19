<?php

declare(strict_types=1);

namespace PhpOrbit\Form;

use InvalidArgumentException;

/**
 * One input, with the rules that decide whether its value is acceptable.
 *
 * Immutable, so a field — or a whole form — can be defined once at boot and
 * rendered on every request without one visitor's value reaching another.
 *
 * The rules are declared here rather than repeated in the controller, so the
 * markup and the validation cannot disagree: `required()` sets the attribute
 * *and* the check.
 */
final class Field
{
    /** @var list<string> */
    public readonly array $options;

    /**
     * @param list<string> $options for select fields
     */
    private function __construct(
        public readonly string $name,
        public readonly FieldType $type,
        public readonly string $label,
        public readonly bool $isRequired = false,
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
        public readonly ?string $hint = null,
        public readonly ?string $placeholder = null,
        public readonly ?string $autocomplete = null,
        array $options = [],
    ) {
        // The name reaches an HTML attribute and a form-data key; anything
        // beyond this set is either a rendering surprise or an injection.
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid field name "%s". Use letters, digits, underscores and hyphens, '
                . 'starting with a letter.',
                $name,
            ));
        }

        if ($minLength !== null && $maxLength !== null && $minLength > $maxLength) {
            throw new InvalidArgumentException(sprintf(
                'Field "%s" has a minimum longer than its maximum.',
                $name,
            ));
        }

        $this->options = $options;
    }

    public static function text(string $name, ?string $label = null): self
    {
        return new self($name, FieldType::Text, $label ?? self::humanise($name));
    }

    public static function email(string $name, ?string $label = null): self
    {
        return (new self($name, FieldType::Email, $label ?? self::humanise($name)))
            ->autocomplete('email');
    }

    public static function password(string $name, ?string $label = null): self
    {
        return (new self($name, FieldType::Password, $label ?? self::humanise($name)))
            ->autocomplete('current-password');
    }

    public static function textarea(string $name, ?string $label = null): self
    {
        return new self($name, FieldType::Textarea, $label ?? self::humanise($name));
    }

    public static function number(string $name, ?string $label = null): self
    {
        return new self($name, FieldType::Number, $label ?? self::humanise($name));
    }

    public static function url(string $name, ?string $label = null): self
    {
        return new self($name, FieldType::Url, $label ?? self::humanise($name));
    }

    public static function tel(string $name, ?string $label = null): self
    {
        return new self($name, FieldType::Tel, $label ?? self::humanise($name));
    }

    public static function date(string $name, ?string $label = null): self
    {
        return new self($name, FieldType::Date, $label ?? self::humanise($name));
    }

    public static function checkbox(string $name, ?string $label = null): self
    {
        return new self($name, FieldType::Checkbox, $label ?? self::humanise($name));
    }

    /**
     * @param list<string> $options
     */
    public static function select(string $name, array $options, ?string $label = null): self
    {
        return new self(
            $name,
            FieldType::Select,
            $label ?? self::humanise($name),
            options: $options,
        );
    }

    /**
     * Overrides the label derived from the field name.
     */
    public function label(string $label): self
    {
        return $this->with(label: $label);
    }

    public function required(): self
    {
        return $this->with(isRequired: true);
    }

    public function min(int $characters): self
    {
        return $this->with(minLength: $characters);
    }

    public function max(int $characters): self
    {
        return $this->with(maxLength: $characters);
    }

    /**
     * Help text, rendered under the field and referenced by `aria-describedby`.
     */
    public function hint(string $hint): self
    {
        return $this->with(hint: $hint);
    }

    public function placeholder(string $placeholder): self
    {
        return $this->with(placeholder: $placeholder);
    }

    /**
     * Tells the browser what this field is for, e.g. `name`, `street-address`.
     */
    public function autocomplete(string $token): self
    {
        return $this->with(autocomplete: $token);
    }

    /**
     * `email_address` -> `Email address`.
     */
    private static function humanise(string $name): string
    {
        return ucfirst(strtolower(trim((string) preg_replace('/[_-]+|(?<!^)(?=[A-Z])/', ' ', $name))));
    }

    /**
     * @param list<string>|null $options
     */
    private function with(
        ?string $label = null,
        ?bool $isRequired = null,
        ?int $minLength = null,
        ?int $maxLength = null,
        ?string $hint = null,
        ?string $placeholder = null,
        ?string $autocomplete = null,
        ?array $options = null,
    ): self {
        return new self(
            $this->name,
            $this->type,
            $label ?? $this->label,
            $isRequired ?? $this->isRequired,
            $minLength ?? $this->minLength,
            $maxLength ?? $this->maxLength,
            $hint ?? $this->hint,
            $placeholder ?? $this->placeholder,
            $autocomplete ?? $this->autocomplete,
            $options ?? $this->options,
        );
    }
}
