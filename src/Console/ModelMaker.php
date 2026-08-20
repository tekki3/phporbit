<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Writes a `PhpOrbit\Database\Model` subclass under `App\Models`.
 *
 * Unlike {@see ClassMaker}, there is no lifetime to choose: a model is never
 * resolved from the container, so there is nothing to register and no
 * bootstrap line to print. The only thing worth printing is a reminder that
 * `Model::useConnection()` has to run once, in `app/bootstrap.php`, before
 * any generated model can be used — that call is not written for the
 * developer, the same reasoning `make:controller` leaves the route line to
 * paste rather than editing a file it does not own.
 *
 * Lives in `src/` rather than the CLI script so a scaffolded project gets the
 * same command; progress is reported through a callback rather than a
 * stream, because nothing in `src/` may assume a SAPI.
 */
final class ModelMaker
{
    /** @var Closure(string): void */
    private readonly Closure $report;

    /**
     * @param string $root the project root, containing app/
     * @param (Closure(string): void)|null $report
     */
    public function __construct(
        private readonly string $root,
        ?Closure $report = null,
    ) {
        $this->report = $report ?? static function (string $line): void {
        };
    }

    /**
     * @param string      $name   `Note`, `Blog/Post` or `App\Models\Note`
     * @param string|null $table  overrides the pluralised guess from $name
     * @param string|null $fields `title:string,views:int,archived_at:?string`
     */
    public function create(
        string $name,
        ?string $table = null,
        ?string $fields = null,
        bool $force = false,
    ): GeneratedModel {
        $segments = $this->parseName($name);
        $class = array_pop($segments);

        $namespace = implode('\\', ['App', 'Models', ...$segments]);
        $className = $namespace . '\\' . $class;
        $relative = implode('/', ['app', 'src', 'Models', ...$segments]) . '/' . $class . '.php';

        $table ??= $this->guessTable($class);

        if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid table name "%s". Identifiers may contain letters, digits and underscores, '
                . 'starting with a letter.',
                $table,
            ));
        }

        $specs = $this->parseFields($fields ?? '');

        $this->write($relative, $this->classSource($namespace, $class, $table, $specs), $force);

        return new GeneratedModel(
            $className,
            $relative,
            $table,
            array_map(static fn (ModelFieldSpec $spec): string => $spec->name, $specs),
            sprintf('use %s;', $className),
        );
    }

    /**
     * Splits `Blog/Post` into class-name segments, and drops a leading `App`
     * or `Models` segment — a fully qualified name is how people refer to a
     * class, and `App\Models\Models\Post` would be nobody's intent.
     *
     * @return non-empty-list<string>
     */
    private function parseName(string $name): array
    {
        $segments = preg_split('#[/\\\\]+#', trim($name, "/\\ \t")) ?: [];

        if (($segments[0] ?? null) === 'App' && count($segments) > 1) {
            array_shift($segments);
        }

        if (($segments[0] ?? null) === 'Models' && count($segments) > 1) {
            array_shift($segments);
        }

        return PhpName::segments(implode('/', $segments), 'model', 'Note, Blog/Post');
    }

    /**
     * `Note` -> `notes`, `Category` -> `categories`, `Box` -> `boxes`. A
     * starting point only — every generated model names its table in
     * `table()`, which is the one line to edit if the guess is wrong.
     */
    private function guessTable(string $class): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)([A-Z])/', '_$1', $class));

        if (preg_match('/[^aeiou]y$/', $snake) === 1) {
            return substr($snake, 0, -1) . 'ies';
        }

        if (preg_match('/(s|x|z|ch|sh)$/', $snake) === 1) {
            return $snake . 'es';
        }

        return $snake . 's';
    }

    /**
     * @return list<ModelFieldSpec>
     */
    private function parseFields(string $spec): array
    {
        $parsed = [];
        $seen = [];

        foreach (explode(',', $spec) as $entry) {
            if (trim($entry) === '') {
                continue;
            }

            $field = ModelFieldSpec::parse($entry);

            // Two fields of one name is not a preference to resolve: the
            // second would silently win both the property and the column.
            if (isset($seen[$field->name])) {
                throw new InvalidArgumentException(sprintf('Field "%s" is declared twice.', $field->name));
            }

            $seen[$field->name] = true;
            $parsed[] = $field;
        }

        return $parsed;
    }

    /**
     * @param list<ModelFieldSpec> $specs
     */
    private function classSource(string $namespace, string $class, string $table, array $specs): string
    {
        $properties = $specs === []
            ? '    // No fields yet — add one, or generate with --fields=title:string,views:int.'
            : implode("\n", array_map(
                static fn (ModelFieldSpec $spec): string => '    ' . $spec->declaration(),
                $specs,
            ));

        $fromRowLines = $specs === []
            ? ''
            : "\n" . implode("\n", array_map(
                static fn (ModelFieldSpec $spec): string => '        ' . $spec->fromRowLine(),
                $specs,
            ));

        $toRowLines = $specs === []
            ? ''
            : "\n" . implode("\n", array_map(
                static fn (ModelFieldSpec $spec): string => '            ' . $spec->toRowLine(),
                $specs,
            )) . "\n        ";

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use PhpOrbit\\Database\\Model;

            /**
             * A "{$table}" row.
             *
             * Registered nowhere: reached through its own static finders —
             * {$class}::find(), {$class}::where(), {$class}::all() — once
             * app/bootstrap.php has called Model::useConnection() after the
             * Connection is built. Joins, aggregates beyond count(), and anything
             * past this one table are still Connection::select()'s job.
             */
            final class {$class} extends Model
            {
            {$properties}

                protected static function table(): string
                {
                    return '{$table}';
                }

                protected static function fromRow(array \$row): static
                {
                    \$model = new static();{$fromRowLines}

                    return \$model;
                }

                public function toRow(): array
                {
                    return [{$toRowLines}];
                }
            }

            PHP;
    }

    /**
     * @param string $relative project-relative path
     */
    private function write(string $relative, string $contents, bool $force): void
    {
        $path = $this->root . '/' . $relative;

        if (is_file($path) && !$force) {
            throw new RuntimeException(sprintf('%s already exists. Pass --force to overwrite it.', $relative));
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create directory "%s".', $directory));
        }

        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Cannot write "%s".', $relative));
        }

        ($this->report)('Created ' . $relative);
    }
}
