<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Console;

use FilesystemIterator;
use InvalidArgumentException;
use PhpOrbit\Console\FormMaker;
use PhpOrbit\Console\Scaffold;
use PhpOrbit\Console\Variant;
use PhpOrbit\Crypto\Key;
use PhpOrbit\Database\Model;
use PhpOrbit\Crypto\Signer;
use PhpOrbit\Form\Form;
use PhpOrbit\Http\Status;
use PhpOrbit\Kernel\Application;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PhpOrbit\Tests\Support\Requests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A generated form is only worth generating if it renders, validates and
 * refuses — so the last tests here load what was written and submit to it,
 * once directly and once through a booted application.
 */
final class FormMakerTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/orbit-form-' . bin2hex(random_bytes(6));
        mkdir($this->project, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->project);
    }

    public function test_it_writes_a_form_definition(): void
    {
        $made = $this->maker()->create('Contact');

        self::assertSame('App\Forms\ContactForm', $made->className);
        self::assertSame('app/src/Forms/ContactForm.php', $made->formPath);
        self::assertSame('/contact', $made->action);
        self::assertSame(['name', 'email', 'message'], $made->fieldNames);

        // Without --controllers it writes exactly one file.
        self::assertSame([], $made->controllerPaths);
        self::assertNull($made->templatePath);
        self::assertSame([], $made->routeSnippets);
    }

    /**
     * Both spellings are what people type; neither should give `ContactFormForm`.
     */
    #[DataProvider('equivalentNames')]
    public function test_the_form_suffix_is_added_once(string $name): void
    {
        self::assertSame('App\Forms\ContactForm', $this->maker()->create($name)->className);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentNames(): iterable
    {
        yield 'bare' => ['Contact'];
        yield 'suffixed' => ['ContactForm'];
    }

    public function test_nested_names_nest_the_namespace_the_route_and_the_template(): void
    {
        $made = $this->maker()->create('Admin/Invite', withControllers: true);

        self::assertSame('App\Forms\Admin\InviteForm', $made->className);
        self::assertSame('app/src/Forms/Admin/InviteForm.php', $made->formPath);
        self::assertSame('/admin/invite', $made->action);
        self::assertSame('app/templates/admin/invite.orbit.php', $made->templatePath);
        self::assertSame([
            'app/src/Controllers/Admin/InviteController.php',
            'app/src/Controllers/Admin/SubmitInviteController.php',
        ], $made->controllerPaths);

        self::assertSame([
            "\$routes->get('/admin/invite', InviteController::class, 'admin.invite');",
            "\$routes->post('/admin/invite', SubmitInviteController::class, 'admin.invite.submit');",
        ], $made->routeSnippets);
    }

    /**
     * The action the form posts to and the route printed for it come from one
     * derivation, so they cannot disagree.
     */
    public function test_the_rendered_action_matches_the_printed_route(): void
    {
        $made = $this->maker()->create('Admin/Invite', withControllers: true);
        $source = (string) file_get_contents($this->project . '/' . $made->formPath);

        self::assertStringContainsString("Form::post('/admin/invite')", $source);
        self::assertStringContainsString("\$routes->post('/admin/invite'", $made->routeSnippets[1]);
    }

    // --- fields ---------------------------------------------------------------

    public function test_fields_are_declared_with_their_rules(): void
    {
        $made = $this->maker()->create(
            'Signup',
            fields: 'email:email,password:password,plan:select,terms:checkbox',
        );

        $source = (string) file_get_contents($this->project . '/' . $made->formPath);

        self::assertStringContainsString("Field::email('email')->required()->max(120),", $source);
        // 72 bytes is where bcrypt truncates, and PasswordHasher refuses more.
        self::assertStringContainsString("Field::password('password')->required()->min(12)->max(72),", $source);
        self::assertStringContainsString("Field::select('plan', ['First option', 'Second option'])->required(),", $source);
        // "Add a checkbox" does not mean "it must be ticked".
        self::assertStringContainsString("Field::checkbox('terms'),", $source);
    }

    public function test_protections_are_on_by_default(): void
    {
        $source = (string) file_get_contents(
            $this->project . '/' . $this->maker()->create('Contact')->formPath,
        );

        self::assertStringContainsString('->protectWith(new Honeypot($this->signer))', $source);
        self::assertStringContainsString('private readonly Signer $signer,', $source);
        self::assertStringNotContainsString('MathCaptcha', $source);
    }

    public function test_the_captcha_is_opt_in_and_brings_its_own_dependency(): void
    {
        $source = (string) file_get_contents(
            $this->project . '/' . $this->maker()->create('Contact', captcha: true)->formPath,
        );

        self::assertStringContainsString('->withCaptcha(new MathCaptcha($this->encrypter))', $source);
        self::assertStringContainsString('private readonly Encrypter $encrypter,', $source);
    }

    /**
     * With neither protection there is nothing to inject, so no constructor is
     * written at all rather than an empty one.
     */
    public function test_an_unprotected_form_needs_no_constructor(): void
    {
        $source = (string) file_get_contents(
            $this->project . '/' . $this->maker()->create('Internal', honeypot: false)->formPath,
        );

        self::assertStringNotContainsString('__construct', $source);
        self::assertStringNotContainsString('Honeypot', $source);
    }

    #[DataProvider('generatedShapes')]
    public function test_everything_it_writes_parses(bool $captcha, bool $honeypot, bool $controllers): void
    {
        $made = $this->maker()->create(
            'Contact',
            captcha: $captcha,
            honeypot: $honeypot,
            withControllers: $controllers,
        );

        foreach ([$made->formPath, ...$made->controllerPaths] as $relative) {
            $output = [];
            $status = 0;
            exec(
                sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($this->project . '/' . $relative)),
                $output,
                $status,
            );

            self::assertSame(0, $status, implode("\n", $output));
        }
    }

    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function generatedShapes(): iterable
    {
        yield 'default' => [false, true, false];
        yield 'captcha' => [true, true, false];
        yield 'unprotected' => [false, false, false];
        yield 'with controllers' => [true, true, true];
    }

    // --- refusals -------------------------------------------------------------

    /**
     * The clash is silent at runtime: a real visitor fills in the field, the
     * honeypot sees its decoy filled, and every genuine submission is rejected
     * with a message that explains nothing.
     */
    #[DataProvider('clashingFields')]
    public function test_it_refuses_a_field_the_form_itself_emits(string $field, bool $captcha): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->maker()->create('Contact', fields: $field . ':text', captcha: $captcha);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function clashingFields(): iterable
    {
        yield 'honeypot decoy' => ['website', false];
        yield 'honeypot clock' => ['_rendered', false];
        yield 'csrf token' => [Csrf::FIELD_NAME, false];
        yield 'captcha answer' => ['captcha', true];
        yield 'captcha seal' => ['_captcha', true];
    }

    /**
     * The same name twice means the second field silently wins the submitted
     * value and the first is never checked.
     */
    public function test_it_refuses_a_duplicate_field(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->maker()->create('Contact', fields: 'email:email,email:text');
    }

    #[DataProvider('badSpecs')]
    public function test_it_refuses_a_field_spec_it_cannot_honour(string $fields): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->maker()->create('Contact', fields: $fields);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badSpecs(): iterable
    {
        yield 'unknown type' => ['name:wat'];
        // A real FieldType, but Field has no factory for it: a value the
        // visitor never typed belongs in the handler, not the declaration.
        yield 'hidden' => ['secret:hidden'];
        yield 'empty' => [''];
        yield 'bad name' => ['not a name:text'];
        yield 'too many parts' => ['name:text:extra'];
    }

    #[DataProvider('badNames')]
    public function test_it_refuses_a_name_that_is_not_a_plain_identifier(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->maker()->create($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'traversal' => ['../../evil'];
        yield 'lowercase' => ['contact'];
        yield 'reserved' => ['List'];
        yield 'nothing but the suffix' => ['Form'];
        yield 'empty' => [''];
    }

    /**
     * The command writes a slice of four files. Stopping in the middle would
     * leave a project that neither compiles nor reruns cleanly.
     */
    public function test_an_occupied_target_stops_it_before_anything_is_written(): void
    {
        $this->maker()->create('Contact', withControllers: true);
        $this->removeTree($this->project . '/app/src/Forms');

        try {
            $this->maker()->create('Contact', withControllers: true);
            self::fail('an existing controller should not be overwritten');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('--force', $e->getMessage());
        }

        // The form is the first file it would have written; it was not.
        self::assertFileDoesNotExist($this->project . '/app/src/Forms/ContactForm.php');
    }

    // --- it actually runs -----------------------------------------------------

    /**
     * Loads the generated definition and submits to it: the fields validate,
     * and the honeypot the generator attached actually fires.
     */
    public function test_a_generated_form_validates_and_refuses(): void
    {
        $made = $this->maker()->create('GeneratedProbeContact', fields: 'email:email,message:textarea');

        require $this->project . '/' . $made->formPath;

        self::assertTrue(class_exists($made->className, false));

        $class = $made->className;
        $definition = new $class(new Signer(Key::generate()));

        self::assertInstanceOf($class, $definition);
        self::assertTrue(method_exists($definition, 'build'));

        $form = $definition->build();

        self::assertInstanceOf(Form::class, $form);

        $session = Session::started();
        $html = $form->render($session);

        self::assertStringContainsString('name="email"', $html);
        // Emitted because the form is a POST, not because the generator
        // remembered to ask for it.
        self::assertStringContainsString('name="' . Csrf::FIELD_NAME . '"', $html);
        // The decoy is hidden with the HTML attribute, never a CSS class.
        self::assertStringContainsString('<div hidden', $html);

        // Submitted the instant it was rendered: too fast to have been typed.
        $submission = $form->handle(
            Requests::form('/generated-probe-contact', [
                'email' => 'someone@example.test',
                'message' => 'Long enough to pass the rules.',
            ]),
            $session,
        );

        self::assertTrue($submission->failed());
        self::assertTrue($submission->looksAutomated());
        // The specific reason is for the log; the page gets one generic line.
        self::assertNotNull($submission->rejectedAs);
        self::assertSame(
            'That submission could not be accepted. Please try again.',
            $submission->error('_form'),
        );
    }

    /**
     * The whole point of `--controllers`: paste the two printed lines into a
     * real project and the page renders, accepts a valid submission and
     * re-renders an invalid one.
     */
    public function test_the_generated_pages_serve_a_round_trip(): void
    {
        (new Scaffold(dirname(__DIR__, 3)))->create($this->project, Variant::Blank, force: true);

        // A name unique to this test: the classes are loaded into this process
        // and cannot be redeclared by another.
        $made = (new FormMaker($this->project))->create(
            'GeneratedProbeSignup',
            fields: 'email:email,message:textarea',
            // Off for this test only: the honeypot refuses anything submitted
            // within two seconds of rendering, which is every request here.
            honeypot: false,
            withControllers: true,
        );

        $this->pasteRoutes($made->importSnippets, $made->routeSnippets);

        $autoloader = function (string $class): void {
            if (!str_starts_with($class, 'App\\')) {
                return;
            }

            $path = $this->project . '/app/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';

            if (is_file($path)) {
                require $path;
            }
        };

        spl_autoload_register($autoloader);

        $previousLevel = getenv('LOG_LEVEL');
        putenv('LOG_LEVEL=error');

        // Model::useConnection() is boot-time wiring; a real deployment calls
        // it once, but this test process boots several scaffolded apps in a
        // row, and each is entitled to point Model at its own connection.
        Model::resetConnectionForTesting();

        try {
            /** @var Application $application */
            $application = require $this->project . '/app/bootstrap.php';

            $page = $application->handle(Requests::get($made->action));

            self::assertSame(Status::Ok, $page->status);
            self::assertStringContainsString('name="email"', $page->body);

            $cookies = $this->cookiesFrom($page->headers->all('Set-Cookie'));
            $token = $this->tokenIn($page->body);

            $accepted = $application->handle(Requests::form($made->action, [
                Csrf::FIELD_NAME => $token,
                'email' => 'someone@example.test',
                'message' => 'Long enough to pass the rules.',
            ], $cookies));

            // Redirect after a successful write, so a refresh does not repost.
            self::assertSame(Status::Found, $accepted->status);
            self::assertSame($made->action, $accepted->headers->first('Location'));

            $rejected = $application->handle(Requests::form($made->action, [
                Csrf::FIELD_NAME => $token,
                'email' => 'not-an-email',
                'message' => '',
            ], $cookies));

            self::assertSame(Status::UnprocessableEntity, $rejected->status);
            self::assertStringContainsString('name="email"', $rejected->body);
            // Redisplayed with what was typed, so nothing has to be retyped.
            self::assertStringContainsString('not-an-email', $rejected->body);
        } finally {
            spl_autoload_unregister($autoloader);

            $previousLevel === false ? putenv('LOG_LEVEL') : putenv('LOG_LEVEL=' . $previousLevel);
        }
    }

    // --- helpers --------------------------------------------------------------

    private function maker(): FormMaker
    {
        return new FormMaker($this->project);
    }

    /**
     * Does by hand what the command deliberately leaves to the developer.
     *
     * @param list<string> $imports
     * @param list<string> $routes
     */
    private function pasteRoutes(array $imports, array $routes): void
    {
        $path = $this->project . '/app/routes.php';
        $source = (string) file_get_contents($path);

        $source = str_replace(
            'use PhpOrbit\Http\Response;',
            implode("\n", $imports) . "\nuse PhpOrbit\Http\Response;",
            $source,
        );

        $source = str_replace(
            'return static function (RouteCollection $routes, bool $debug): void {',
            "return static function (RouteCollection \$routes, bool \$debug): void {\n    "
            . implode("\n    ", $routes),
            $source,
        );

        file_put_contents($path, $source);
    }

    /**
     * @param list<string> $setCookies
     * @return array<string, string>
     */
    private function cookiesFrom(array $setCookies): array
    {
        $cookies = [];

        foreach ($setCookies as $header) {
            $pair = explode('=', explode(';', $header)[0], 2);

            if (count($pair) === 2) {
                $cookies[trim($pair[0])] = urldecode($pair[1]);
            }
        }

        return $cookies;
    }

    private function tokenIn(string $html): string
    {
        if (preg_match('/name="' . Csrf::FIELD_NAME . '" value="([a-f0-9]+)"/', $html, $match) !== 1) {
            self::fail('the rendered form carried no CSRF token');
        }

        return $match[1];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
