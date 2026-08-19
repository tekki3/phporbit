<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use InvalidArgumentException;
use PhpOrbit\Form\FieldType;

/**
 * One `name:type` pair from `--fields`, checked before any file is written.
 *
 * The type is resolved to a {@see FieldType} rather than pasted into the
 * generated code as a string, so an unknown type is answered at the command
 * line instead of appearing as "call to undefined method Field::hidden()" in a
 * file the developer did not write.
 */
final class FormFieldSpec
{
    /**
     * Types with no `Field` factory behind them.
     *
     * `hidden` is a real {@see FieldType}, but a hidden input carries a value
     * the visitor never typed and cannot correct — that belongs in the handler,
     * not in a form declaration.
     *
     * @var list<string>
     */
    private const UNSUPPORTED = ['hidden'];

    private function __construct(
        public readonly string $name,
        public readonly FieldType $type,
    ) {
    }

    /**
     * Parses `name`, `email:email`, `message:textarea`.
     */
    public static function parse(string $spec): self
    {
        $parts = explode(':', trim($spec));

        if (count($parts) > 2) {
            throw new InvalidArgumentException(sprintf(
                'Invalid field "%s". Write it as name:type, for example message:textarea.',
                $spec,
            ));
        }

        $name = trim($parts[0]);
        $type = strtolower(trim($parts[1] ?? 'text'));

        // The same rule Field itself applies: the name reaches a `name=`
        // attribute, and anything beyond this set is a rendering surprise.
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid field name "%s". Use letters, digits, underscores and hyphens, '
                . 'starting with a letter.',
                $name,
            ));
        }

        $resolved = FieldType::tryFrom($type);

        if ($resolved === null || in_array($type, self::UNSUPPORTED, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown field type "%s" for "%s". Available: %s.',
                $type,
                $name,
                implode(', ', self::available()),
            ));
        }

        return new self($name, $resolved);
    }

    /**
     * @return list<string>
     */
    public static function available(): array
    {
        $types = [];

        foreach (FieldType::cases() as $case) {
            if (!in_array($case->value, self::UNSUPPORTED, true)) {
                $types[] = $case->value;
            }
        }

        return $types;
    }

    /**
     * The declaration to write into the form.
     *
     * The rules attached here are a starting point and never load-bearing —
     * except that they *exist*: a generated field with no rules is one a
     * developer has to remember to constrain, and the point of declaring the
     * markup and the validation together is that neither can be forgotten.
     */
    public function declaration(): string
    {
        $factory = match ($this->type) {
            FieldType::Select => sprintf(
                "Field::select('%s', ['First option', 'Second option'])",
                $this->name,
            ),
            default => sprintf("Field::%s('%s')", $this->type->value, $this->name),
        };

        $rules = match ($this->type) {
            // A checkbox that must be ticked is a specific demand (terms,
            // consent), not the default reading of "add a checkbox".
            FieldType::Checkbox => [],
            // 72 bytes is where bcrypt truncates, and PasswordHasher refuses
            // anything longer rather than silently ignoring the tail.
            FieldType::Password => ['required()', 'min(12)', 'max(72)'],
            FieldType::Textarea => ['required()', 'max(2000)'],
            FieldType::Number, FieldType::Date, FieldType::Select => ['required()'],
            default => ['required()', 'max(120)'],
        };

        return $factory . implode('', array_map(
            static fn (string $rule): string => '->' . $rule,
            $rules,
        )) . ',';
    }
}
