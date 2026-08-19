<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

/**
 * What `make:form` produced, and the lines still to be written by hand.
 */
final class GeneratedForm
{
    /**
     * @param list<string> $fieldNames       in declaration order
     * @param list<string> $controllerPaths  empty unless controllers were asked for
     * @param list<string> $routeSnippets    the declarations to paste into app/routes.php
     * @param list<string> $importSnippets   the imports those declarations need
     */
    public function __construct(
        /** Fully qualified, e.g. App\Forms\ContactForm */
        public readonly string $className,
        /** Project-relative, e.g. app/src/Forms/ContactForm.php */
        public readonly string $formPath,
        /** The path the form posts to, e.g. /contact */
        public readonly string $action,
        public readonly array $fieldNames,
        public readonly array $controllerPaths,
        /** Project-relative template, when controllers were requested */
        public readonly ?string $templatePath,
        public readonly array $routeSnippets,
        public readonly array $importSnippets,
        /** A promoted constructor parameter, for a controller written by hand */
        public readonly string $injectionSnippet,
    ) {
    }
}
