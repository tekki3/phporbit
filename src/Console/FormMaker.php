<?php

declare(strict_types=1);

namespace PhpOrbit\Console;

use Closure;
use InvalidArgumentException;
use PhpOrbit\Security\Csrf;
use RuntimeException;

/**
 * Writes a form definition, and optionally the pages that use it.
 *
 * A form in this framework is one declaration: `Field::email('email')->required()`
 * yields both the input and the rule that checks it. That is the property worth
 * generating — a hand-written form is where a field ends up rendered but never
 * validated, because the markup and the rules live in different files and only
 * one of them got updated.
 *
 * So the generated class exposes `build(): Form` and nothing else, and with
 * `--controllers` the two controllers that render and handle it are written
 * against *that* method. Neither can drift from the other, which is the same
 * reason the demo's contact page is defined in one place.
 *
 * Protections are on by default because the safe path has to be the default
 * path: a generated public form arrives with the honeypot already attached, and
 * `--captcha` adds the arithmetic question on top. Turning one off is a
 * deliberate flag, not an omission.
 */
final class FormMaker
{
    /** A contact-shaped starting point, which is what most first forms are. */
    private const DEFAULT_FIELDS = 'name:text,email:email,message:textarea';

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
     * @param string      $name            `Contact`, `ContactForm` or `Admin/Invite`
     * @param string|null $fields          `name:text,email:email,message:textarea`
     * @param bool        $captcha         add the arithmetic question
     * @param bool        $honeypot        add the decoy field and signed clock
     * @param bool        $withControllers also write the two controllers and the template
     */
    public function create(
        string $name,
        ?string $fields = null,
        bool $captcha = false,
        bool $honeypot = true,
        bool $withControllers = false,
        bool $force = false,
    ): GeneratedForm {
        $segments = PhpName::segments($name, 'form', 'Contact, Signup, Admin/Invite');
        $base = $this->baseName(array_pop($segments), $name);

        $specs = $this->parseFields($fields ?? self::DEFAULT_FIELDS, $captcha, $honeypot);

        $formClass = $base . 'Form';
        $formNamespace = implode('\\', ['App', 'Forms', ...$segments]);
        $formPath = implode('/', ['app', 'src', 'Forms', ...$segments]) . '/' . $formClass . '.php';

        // "Admin/Invite" -> "admin/invite", which is both the template name and
        // the path the form posts to. One derivation, so the action the form
        // renders and the route printed below cannot disagree.
        $slug = implode('/', array_map(PhpName::toKebabCase(...), [...$segments, $base]));
        $action = '/' . $slug;

        $controllerPaths = [];
        $routeSnippets = [];
        $importSnippets = [];
        $templatePath = null;

        $controllerNamespace = implode('\\', ['App', 'Controllers', ...$segments]);
        $directory = implode('/', ['app', 'src', 'Controllers', ...$segments]);
        $formClassName = $formNamespace . '\\' . $formClass;
        $render = $base . 'Controller';
        $submit = 'Submit' . $base . 'Controller';

        if ($withControllers) {
            $templatePath = 'app/templates/' . $slug . '.orbit.php';
            $controllerPaths = [
                $directory . '/' . $render . '.php',
                $directory . '/' . $submit . '.php',
            ];
        }

        // Every target is checked before the first is written. This command
        // produces a slice — a form, two controllers, a template — and stopping
        // half way through would leave a project that neither compiles nor
        // reruns cleanly, with the failure named for the file it stopped at
        // rather than the one already occupied.
        $this->assertAllWritable(
            [$formPath, ...$controllerPaths, ...($templatePath === null ? [] : [$templatePath])],
            $force,
        );

        $this->write(
            $formPath,
            $this->formSource($formNamespace, $formClass, $action, $specs, $captcha, $honeypot),
            $force,
        );

        if ($withControllers) {
            $this->write(
                $directory . '/' . $render . '.php',
                $this->renderControllerSource($controllerNamespace, $render, $formClassName, $formClass, $slug, $base),
                $force,
            );
            $this->write(
                $directory . '/' . $submit . '.php',
                $this->submitControllerSource($controllerNamespace, $submit, $formClassName, $formClass, $slug, $base, $action),
                $force,
            );
            $this->write((string) $templatePath, $this->templateSource($base), $force);

            $routeName = str_replace('/', '.', $slug);

            $importSnippets = [
                sprintf('use %s\\%s;', $controllerNamespace, $render),
                sprintf('use %s\\%s;', $controllerNamespace, $submit),
            ];
            $routeSnippets = [
                sprintf("\$routes->get('%s', %s::class, '%s');", $action, $render, $routeName),
                sprintf("\$routes->post('%s', %s::class, '%s.submit');", $action, $submit, $routeName),
            ];
        }

        return new GeneratedForm(
            $formNamespace . '\\' . $formClass,
            $formPath,
            $action,
            array_map(static fn (FormFieldSpec $spec): string => $spec->name, $specs),
            $controllerPaths,
            $templatePath,
            $routeSnippets,
            $importSnippets,
            sprintf('private readonly %s $form,', $formClass),
        );
    }

    /**
     * `Contact` and `ContactForm` both name the same form.
     */
    private function baseName(string $class, string $name): string
    {
        $base = str_ends_with($class, 'Form') ? substr($class, 0, -strlen('Form')) : $class;

        if ($base === '') {
            throw new InvalidArgumentException(sprintf(
                'Invalid form name "%s". Name it after what it collects: Contact, Signup, Admin/Invite.',
                $name,
            ));
        }

        return $base;
    }

    /**
     * @return non-empty-list<FormFieldSpec>
     */
    private function parseFields(string $spec, bool $captcha, bool $honeypot): array
    {
        $parsed = [];
        $seen = [];

        foreach (explode(',', $spec) as $entry) {
            if (trim($entry) === '') {
                continue;
            }

            $field = FormFieldSpec::parse($entry);

            // Two fields of one name is not a preference to resolve: the second
            // silently wins the submitted value and the first is never checked.
            if (isset($seen[$field->name])) {
                throw new InvalidArgumentException(sprintf(
                    'Field "%s" is declared twice.',
                    $field->name,
                ));
            }

            $this->assertNotReserved($field->name, $captcha, $honeypot);

            $seen[$field->name] = true;
            $parsed[] = $field;
        }

        if ($parsed === []) {
            throw new InvalidArgumentException(
                'A form needs at least one field, for example: --fields=email:email,message:textarea',
            );
        }

        return $parsed;
    }

    /**
     * Refuses a field name the form itself will emit.
     *
     * A field called `website` alongside the honeypot means the decoy carries a
     * value every real visitor types, so every real submission is rejected as
     * automated — and the message they get says nothing about why. The clash is
     * silent at runtime, which is exactly why it is answered here.
     */
    private function assertNotReserved(string $field, bool $captcha, bool $honeypot): void
    {
        $reserved = [Csrf::FIELD_NAME => 'the CSRF token the form emits'];

        if ($honeypot) {
            $reserved['website'] = "the honeypot's decoy field — rename it, or pass --no-honeypot";
            $reserved['_rendered'] = "the honeypot's signed timestamp — rename it, or pass --no-honeypot";
        }

        if ($captcha) {
            $reserved['captcha'] = "the captcha's answer field — rename it, or drop --captcha";
            $reserved['_captcha'] = "the captcha's sealed answer — rename it, or drop --captcha";
        }

        if (isset($reserved[$field])) {
            throw new InvalidArgumentException(sprintf(
                'Field "%s" clashes with %s.',
                $field,
                $reserved[$field],
            ));
        }
    }

    /**
     * @param non-empty-list<FormFieldSpec> $specs
     */
    private function formSource(
        string $namespace,
        string $class,
        string $action,
        array $specs,
        bool $captcha,
        bool $honeypot,
    ): string {
        $imports = ['PhpOrbit\Form\Field', 'PhpOrbit\Form\Form'];
        $constructorParameters = [];
        $protections = [];

        if ($honeypot) {
            $imports[] = 'PhpOrbit\Crypto\Signer';
            $imports[] = 'PhpOrbit\Form\Honeypot';
            $constructorParameters[] = 'private readonly Signer $signer,';
            $protections[] = "    // The decoy and the signed clock ask a person for nothing and\n"
                . "    // stop the scripts that post to every form they find.\n"
                . '    ->protectWith(new Honeypot($this->signer))';
        }

        if ($captcha) {
            $imports[] = 'PhpOrbit\Crypto\Encrypter';
            $imports[] = 'PhpOrbit\Form\MathCaptcha';
            $constructorParameters[] = 'private readonly Encrypter $encrypter,';
            $protections[] = "    // Arithmetic stops undirected scripts. It will not stop someone\n"
                . "    // who has decided to attack you — a language model solves it — so\n"
                . "    // treat it as a layer, not a wall.\n"
                . '    ->withCaptcha(new MathCaptcha($this->encrypter))';
        }

        sort($imports);
        $useLines = implode("\n", array_map(static fn (string $i): string => 'use ' . $i . ';', $imports));

        $fieldLines = implode("\n", array_map(
            static fn (FormFieldSpec $spec): string => '            ' . $spec->declaration(),
            $specs,
        ));

        $members = [];

        if ($constructorParameters !== []) {
            $parameters = implode("\n", array_map(
                static fn (string $parameter): string => '    ' . $parameter,
                $constructorParameters,
            ));

            $members[] = "public function __construct(\n" . $parameters . "\n) {\n}";
        }

        $build = "public function build(): Form\n{\n"
            . "    return Form::post('" . $action . "')\n"
            . "        ->add(\n" . $fieldLines . "\n        )";

        foreach ($protections as $protection) {
            $build .= "\n    " . str_replace("\n", "\n    ", $protection);
        }

        $members[] = $build . ";\n}";

        $body = $this->indent(implode("\n\n", $members));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            {$useLines}

            /**
             * One declaration of this form: the markup, the validation rules and the
             * protections all come from the same fields.
             *
             * Declaring them separately is the ordinary way a field ends up rendered but
             * never checked — so whatever renders the form and whatever handles the
             * submission both build it from here, and cannot disagree about it.
             *
             * A Form is immutable, which means this could equally be built once at boot.
             * It is built per call because that reads more plainly and costs nothing.
             */
            final class {$class}
            {
            {$body}
            }

            PHP;
    }

    private function renderControllerSource(
        string $namespace,
        string $class,
        string $formClassName,
        string $formClass,
        string $template,
        string $base,
    ): string {
        $title = PhpName::toTitle($base);
        $flashKey = str_replace('/', '.', $template) . '.sent';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use {$formClassName};
            use PhpOrbit\\Http\\Response;
            use PhpOrbit\\Http\\ServerRequest;
            use PhpOrbit\\Routing\\Handler;
            use PhpOrbit\\Session\\Session;
            use PhpOrbit\\View\\TemplateEngine;

            /**
             * Renders the form.
             *
             * The session is needed because the form carries a CSRF token, and the
             * captcha — if one is attached — is bound to the session so it cannot be
             * solved elsewhere and pasted in.
             */
            final class {$class} implements Handler
            {
                public function __construct(
                    private readonly TemplateEngine \$view,
                    private readonly {$formClass} \$form,
                    private readonly Session \$session,
                ) {
                }

                public function handle(ServerRequest \$request): Response
                {
                    return \$this->view->respond('{$template}', [
                        'title' => '{$title}',
                        'form' => \$this->form->build()->render(\$this->session),
                        'sent' => \$this->session->takeFlash('{$flashKey}'),
                    ]);
                }
            }

            PHP;
    }

    private function submitControllerSource(
        string $namespace,
        string $class,
        string $formClassName,
        string $formClass,
        string $template,
        string $base,
        string $action,
    ): string {
        $title = PhpName::toTitle($base);
        $flashKey = str_replace('/', '.', $template) . '.sent';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use {$formClassName};
            use PhpOrbit\\Http\\Response;
            use PhpOrbit\\Http\\ServerRequest;
            use PhpOrbit\\Http\\Status;
            use PhpOrbit\\Log\\Level;
            use PhpOrbit\\Log\\Logger;
            use PhpOrbit\\Routing\\Handler;
            use PhpOrbit\\Session\\Session;
            use PhpOrbit\\View\\TemplateEngine;

            /**
             * Handles the submission.
             *
             * Note what is *not* here: no CSRF check — middleware did it — no escaping,
             * because the form renders escaped, and no repetition of the validation
             * rules, because they are declared on the fields.
             */
            final class {$class} implements Handler
            {
                public function __construct(
                    private readonly TemplateEngine \$view,
                    private readonly {$formClass} \$form,
                    private readonly Session \$session,
                    private readonly Logger \$logger,
                ) {
                }

                public function handle(ServerRequest \$request): Response
                {
                    \$form = \$this->form->build();
                    \$submission = \$form->handle(\$request, \$this->session);

                    if (\$submission->failed()) {
                        // Why a submission looked automated goes to the log, never to the
                        // page: naming the check that fired tells a script author exactly
                        // what to change.
                        if (\$submission->looksAutomated()) {
                            \$this->logger->log(Level::Warning, '{$template} rejected', [
                                'reason' => \$submission->rejectedAs,
                            ]);
                        }

                        return \$this->view->respond('{$template}', [
                            'title' => '{$title}',
                            'form' => \$form->render(\$this->session, \$submission->old(), \$submission->errors()),
                            'formError' => \$submission->error('_form'),
                        ], Status::UnprocessableEntity);
                    }

                    // Store it, email it, queue it — whatever this form is for.
                    // \$submission->value('...') returns a checked field.

                    \$this->session->flash('{$flashKey}', 'Thanks — that was received.');

                    // Redirect after a successful write, so a refresh does not repost.
                    return Response::redirect('{$action}');
                }
            }

            PHP;
    }

    private function templateSource(string $base): string
    {
        $title = PhpName::toTitle($base);

        return <<<HTML
            @extends('layout')

            @section('content')
                <h1>{$title}</h1>

                @if(\$sent ?? null)
                    <p class="card">{{ \$sent }}</p>
                @endif

                @if(\$formError ?? null)
                    <p class="error">{{ \$formError }}</p>
                @endif

                {# The form escapes everything it renders and has no method that emits
                   raw HTML, so this is markup the application itself built. #}
                <div class="card">
                    {!! \$form !!}
                </div>
            @endsection

            HTML;
    }

    /**
     * Indents every non-blank line by one level.
     */
    private function indent(string $block): string
    {
        $lines = array_map(
            static fn (string $line): string => $line === '' ? '' : '    ' . $line,
            explode("\n", $block),
        );

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $relatives project-relative paths
     */
    private function assertAllWritable(array $relatives, bool $force): void
    {
        if ($force) {
            return;
        }

        foreach ($relatives as $relative) {
            if (is_file($this->root . '/' . $relative)) {
                throw new RuntimeException(sprintf(
                    '%s already exists. Pass --force to overwrite it.',
                    $relative,
                ));
            }
        }
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
