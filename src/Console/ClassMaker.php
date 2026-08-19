<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use Closure;
use RuntimeException;

/**
 * Writes a plain class under `App\`, and says how to reach it.
 *
 * Most of what an application holds is neither a controller nor a migration —
 * repositories, form definitions, services, small value objects. Hand-writing
 * one means retyping the same four lines of preamble and then remembering the
 * part that is actually easy to get wrong: how long the instance lives.
 *
 * So the lifetime is an argument rather than an afterthought. `Autowired` is the
 * default because it needs no registration and cannot leak — an unregistered
 * class is constructed by the {@see \PhpOrbit\Container\RequestScope}, per
 * request. `--singleton` and `--scoped` produce the bootstrap line instead, and
 * the class comment states the constraint the choice carries.
 *
 * Like {@see ControllerMaker}, it does not edit `app/bootstrap.php`: rewriting a
 * file the developer owns means parsing and re-emitting their code, and getting
 * that subtly wrong is worse than printing one line to paste.
 *
 * Lives in `src/` rather than the CLI script so a scaffolded project gets the
 * same command; progress is reported through a callback rather than a stream,
 * because nothing in `src/` may assume a SAPI.
 */
final class ClassMaker
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
     * @param string $name `Clock`, `Notes/NoteRepository` or `App\Notes\NoteRepository`
     */
    public function create(
        string $name,
        Lifetime $lifetime = Lifetime::Autowired,
        bool $force = false,
    ): GeneratedClass {
        $segments = $this->parseName($name);
        $class = array_pop($segments);

        $namespace = implode('\\', ['App', ...$segments]);
        $className = $namespace . '\\' . $class;
        $relative = implode('/', ['app', 'src', ...$segments]) . '/' . $class . '.php';

        $this->write($relative, $this->classSource($namespace, $class, $lifetime), $force);

        return new GeneratedClass(
            $className,
            $relative,
            $lifetime,
            sprintf('use %s;', $className),
            $lifetime->registration($class),
            sprintf('private readonly %s $%s,', $class, lcfirst($class)),
        );
    }

    /**
     * Splits `Notes/NoteRepository` into class-name segments, rejecting anything
     * that is not a plain StudlyCase identifier.
     *
     * A name reaching here from a script argument must never be able to place a
     * file outside `app/src`, so this validates rather than sanitises — there is
     * no "clean up and continue" path. The one normalisation is a leading `App`,
     * because copying a fully qualified name out of an editor is how people
     * refer to a class, and `App\App\Notes` would be nobody's intent.
     *
     * @return non-empty-list<string>
     */
    private function parseName(string $name): array
    {
        $segments = preg_split('#[/\\\\]+#', trim($name, "/\\ \t")) ?: [];

        if (($segments[0] ?? null) === 'App' && count($segments) > 1) {
            array_shift($segments);
        }

        return PhpName::segments(
            implode('/', $segments),
            'class',
            'Clock, NoteRepository, Notes/NoteRepository',
        );
    }

    private function classSource(string $namespace, string $class, Lifetime $lifetime): string
    {
        $note = $this->indent($this->lifetimeNote($lifetime), ' * ');

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            /**
            {$note}
             */
            final class {$class}
            {
            }

            PHP;
    }

    /**
     * The comment above the class: what its lifetime means for the code the
     * developer is about to write in it.
     *
     * Worded as a constraint rather than a description, because the constraint is
     * the part that bites. A singleton holding one request's data is the single
     * most common way an application that works under FPM breaks under a worker.
     */
    private function lifetimeNote(Lifetime $lifetime): string
    {
        return match ($lifetime) {
            Lifetime::Autowired => <<<'TEXT'
                Registered nowhere, on purpose.

                An unregistered class is built by the request scope when something asks
                for it, and discarded when that request ends — so state held here cannot
                reach another visitor. Constructor parameters must be object-typed: the
                container resolves collaborators, and refuses to invent a scalar nobody
                chose.

                Register it in app/bootstrap.php only when it needs something autowiring
                cannot supply, such as a directory path or a configuration value.
                TEXT,
            Lifetime::Scoped => <<<'TEXT'
                Scoped: rebuilt for every request.

                This is where per-request state belongs. Register it in app/bootstrap.php
                with $app->container->scoped(); the factory receives the RequestScope, so
                it can reach the session, the matched route or the authenticated user —
                never the boot container, which would miss what middleware published.
                TEXT,
            Lifetime::Singleton => <<<'TEXT'
                A singleton: one instance for the whole process.

                Under a long-lived worker — this framework's own server and FrankenPHP —
                that one instance is shared by every request the process serves, so it
                must be stateless. Anything mutable stored on it leaks from one visitor
                to the next, and the leak is invisible under Apache or nginx+FPM, where
                the process dies after each response.

                Hold connections, compiled tables and configuration here. Hold request
                data in a scoped class instead.
                TEXT,
        };
    }

    /**
     * Prefixes every line, leaving blank lines unpadded so the comment carries no
     * trailing whitespace.
     */
    private function indent(string $block, string $prefix): string
    {
        $lines = array_map(
            static fn (string $line): string => $line === '' ? rtrim($prefix) : $prefix . $line,
            explode("\n", $block),
        );

        return implode("\n", $lines);
    }

    /**
     * @param string $relative project-relative path
     */
    private function write(string $relative, string $contents, bool $force): void
    {
        $path = $this->root . '/' . $relative;

        if (is_file($path) && !$force) {
            throw new RuntimeException(sprintf(
                '%s already exists. Pass --force to overwrite it.',
                $relative,
            ));
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
