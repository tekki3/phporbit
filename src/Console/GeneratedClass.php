<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

/**
 * What `make:class` produced, and how to reach it from the application.
 *
 * A class is only "created" in the sense that matters once something can use
 * it, so the two lines that connect it — the registration, when the lifetime
 * needs one, and the constructor parameter that injects it — travel with the
 * path rather than being left for the developer to work out.
 */
final class GeneratedClass
{
    public function __construct(
        /** Fully qualified, e.g. App\Notes\NoteRepository */
        public readonly string $className,
        /** Project-relative, e.g. app/src/Notes/NoteRepository.php */
        public readonly string $path,
        public readonly Lifetime $lifetime,
        /** The import both snippets need */
        public readonly string $importSnippet,
        /** The bootstrap line, or null when the class is autowired */
        public readonly ?string $registrationSnippet,
        /** A promoted constructor parameter, ready to paste into a controller */
        public readonly string $injectionSnippet,
    ) {
    }
}
