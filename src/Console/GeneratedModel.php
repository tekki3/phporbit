<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

/**
 * What `make:model` produced.
 *
 * Unlike {@see GeneratedClass}, there is no lifetime and no registration
 * snippet: a `Model` is reached through its own static finders
 * (`Note::find(1)`), never resolved from the container.
 */
final class GeneratedModel
{
    /**
     * @param list<string> $fieldNames
     */
    public function __construct(
        /** Fully qualified, e.g. App\Models\Note */
        public readonly string $className,
        /** Project-relative, e.g. app/src/Models/Note.php */
        public readonly string $path,
        public readonly string $table,
        public readonly array $fieldNames,
        /** The import a caller needs */
        public readonly string $importSnippet,
    ) {
    }
}
