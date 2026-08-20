<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use InvalidArgumentException;

/**
 * One `name:type` pair from `make:model --fields`.
 *
 * The type is a native PHP scalar type — `string`, `int`, `float`, `bool` —
 * optionally `?`-prefixed for nullable, because that is what a `Model`
 * property actually is: a typed column, not a form control. This is a
 * different vocabulary from {@see FormFieldSpec}, which names an *input*
 * (`email`, `textarea`); a model field names *storage*.
 */
final class ModelFieldSpec
{
    private const TYPES = ['string', 'int', 'float', 'bool'];

    /**
     * @param 'string'|'int'|'float'|'bool' $type typed as the literal union rather
     *        than `string`, so the `match()` expressions below stay exhaustive
     *        without a `default` branch that could otherwise hide a fifth type
     *        added to {@see TYPES} without being handled everywhere else
     */
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable,
    ) {
    }

    /**
     * Parses `title`, `body:string`, `views:int`, `archived_at:?string`.
     */
    public static function parse(string $spec): self
    {
        $parts = explode(':', trim($spec));

        if (count($parts) > 2) {
            throw new InvalidArgumentException(sprintf(
                'Invalid field "%s". Write it as name:type, for example views:int.',
                $spec,
            ));
        }

        $name = trim($parts[0]);
        $rawType = trim($parts[1] ?? 'string');

        // The name reaches both a PHP property and a column identifier, so it
        // is held to the stricter of the two: a valid PHP identifier.
        if (preg_match('/^[a-z][a-z0-9_]*$/i', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid field name "%s". Use letters, digits and underscores, starting with a letter.',
                $name,
            ));
        }

        $nullable = str_starts_with($rawType, '?');
        $type = strtolower($nullable ? substr($rawType, 1) : $rawType);

        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown field type "%s" for "%s". Available: %s (optionally "?"-prefixed for nullable).',
                $rawType,
                $name,
                implode(', ', self::TYPES),
            ));
        }

        return new self($name, $type, $nullable);
    }

    /**
     * The property declaration, typed and defaulted so a freshly-`new`d
     * instance never has an uninitialized-property error before `save()`.
     */
    public function declaration(): string
    {
        $type = $this->nullable ? '?' . $this->type : $this->type;
        $default = $this->nullable ? 'null' : match ($this->type) {
            'string' => "''",
            'int' => '0',
            'float' => '0.0',
            'bool' => 'false',
        };

        return sprintf('public %s $%s = %s;', $type, $this->propertyName(), $default);
    }

    /**
     * The line inside `fromRow()`: narrows the untyped row value for this one
     * column, the same "narrow once, at the boundary" shape as
     * {@see \PhpOrbit\Database\Connection::narrowRow()}.
     */
    public function fromRowLine(): string
    {
        $column = $this->name;
        $property = $this->propertyName();

        if ($this->nullable) {
            $cast = match ($this->type) {
                'string' => 'is_string($value) ? $value : null',
                'int' => 'is_int($value) ? $value : (is_numeric($value) ? (int) $value : null)',
                'float' => 'is_float($value) || is_int($value) ? (float) $value : null',
                'bool' => 'is_bool($value) ? $value : null',
            };

            return sprintf(
                "\$value = \$row['%s'] ?? null;\n        \$model->%s = %s;",
                $column,
                $property,
                $cast,
            );
        }

        $cast = match ($this->type) {
            'string' => "(string) (\$row['{$column}'] ?? '')",
            'int' => "(int) (\$row['{$column}'] ?? 0)",
            'float' => "(float) (\$row['{$column}'] ?? 0.0)",
            'bool' => "(bool) (\$row['{$column}'] ?? false)",
        };

        return sprintf('$model->%s = %s;', $property, $cast);
    }

    /**
     * The line inside `toRow()`.
     */
    public function toRowLine(): string
    {
        return sprintf("'%s' => \$this->%s,", $this->name, $this->propertyName());
    }

    /**
     * `created_at` -> `createdAt`; a column name is snake_case, a property is
     * camelCase, and the boundary between the two belongs here rather than
     * being re-derived at every call site.
     */
    public function propertyName(): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $this->name))));
    }
}
