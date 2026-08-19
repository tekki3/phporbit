<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Form;

use InvalidArgumentException;
use LogicException;
use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Crypto\Key;
use PhpOrbit\Crypto\Signer;
use PhpOrbit\Form\Field;
use PhpOrbit\Form\Form;
use PhpOrbit\Form\Honeypot;
use PhpOrbit\Form\MathCaptcha;
use PhpOrbit\Http\Headers;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Uri;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Session\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormTest extends TestCase
{
    private Key $key;

    protected function setUp(): void
    {
        $this->key = Key::generate();
    }

    // --- rendering ------------------------------------------------------------

    public function test_it_renders_an_accessible_field(): void
    {
        $html = $this->contactForm()->render(Session::started());

        self::assertStringContainsString('<label for="f-email">Email', $html);
        self::assertStringContainsString('id="f-email"', $html);
        self::assertStringContainsString('name="email"', $html);
        self::assertStringContainsString('type="email"', $html);
        self::assertStringContainsString('required', $html);
    }

    /**
     * The token is added because the form is a POST, not because the developer
     * remembered. A form needing a line of boilerplate to be protected is one
     * that eventually gets written without it.
     */
    public function test_a_post_form_carries_a_csrf_token_without_being_asked(): void
    {
        $session = Session::started();

        $html = Form::post('/contact')->add(Field::text('name'))->render($session);

        self::assertStringContainsString('name="' . Csrf::FIELD_NAME . '"', $html);
        self::assertStringContainsString(Csrf::token($session), $html);
    }

    public function test_a_get_form_has_no_token(): void
    {
        $html = Form::get('/search')->add(Field::text('q'))->render(Session::started());

        self::assertStringNotContainsString(Csrf::FIELD_NAME, $html);
    }

    /**
     * Values come back from a rejected submission and go straight into an
     * attribute, which is exactly where a quote would break out.
     */
    #[DataProvider('hostileValues')]
    public function test_redisplayed_values_cannot_break_out_of_the_markup(string $payload): void
    {
        $html = $this->contactForm()->render(
            Session::started(),
            old: ['name' => $payload],
            errors: ['name' => $payload],
        );

        // What matters is that the payload creates no markup: no new tag, and
        // no way out of the attribute it sits in. It may well appear as inert
        // *text* inside the error span — that is escaped output working, not a
        // leak, so asserting the raw string is simply absent would be wrong.
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);

        preg_match('/value="([^"]*)"[^>]*id="f-name"|id="f-name"[^>]*value="([^"]*)"/', $html, $match);

        $attribute = ($match[1] ?? '') . ($match[2] ?? '');

        self::assertStringNotContainsString('"', $attribute);
        self::assertStringNotContainsString('<', $attribute);
        self::assertStringNotContainsString(' ', $attribute, 'a space would start a new attribute if the quotes were dropped');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileValues(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'attribute break' => ['" onfocus="alert(1)'];
        yield 'unquoted break' => ['x onmouseover=alert(1)'];
        yield 'img onerror' => ['<img src=x onerror=alert(1)>'];
    }

    public function test_a_hint_and_an_error_are_announced_to_screen_readers(): void
    {
        $form = Form::post('/contact')->add(
            Field::text('name')->hint('As it appears on your account')->required(),
        );

        $html = $form->render(Session::started(), errors: ['name' => 'Required.']);

        // The separator is written as a character reference by the attribute
        // escaper. The HTML parser decodes it, so the DOM sees two ids — the
        // safe escaper is applied uniformly rather than skipped for values
        // that happen to be ours.
        self::assertStringContainsString('aria-describedby="f-name-hint&#x20;f-name-error"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('id="f-name-hint"', $html);
        self::assertStringContainsString('id="f-name-error"', $html);
    }

    public function test_a_password_is_never_echoed_back(): void
    {
        $html = Form::post('/login')
            ->add(Field::password('password'))
            ->render(Session::started(), old: ['password' => 'hunter2']);

        self::assertStringNotContainsString('hunter2', $html);
    }

    /**
     * Built once, rendered many times — nothing about a visitor sticks to it.
     */
    public function test_a_form_can_be_reused_across_requests(): void
    {
        $form = $this->contactForm();

        $first = $form->render(Session::started(), old: ['name' => 'Ada']);
        $second = $form->render(Session::started());

        self::assertStringContainsString('Ada', $first);
        self::assertStringNotContainsString('Ada', $second);
    }

    public function test_an_invalid_field_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Field::text('not a name');
    }

    // --- validation -----------------------------------------------------------

    public function test_the_declared_rules_are_the_rules_applied(): void
    {
        $form = Form::post('/contact')->add(
            Field::text('name')->required()->max(5),
            Field::email('email')->required(),
        );

        $submission = $form->handle(
            $this->submit(['name' => 'far too long', 'email' => 'not-an-email']),
            Session::started(),
        );

        self::assertTrue($submission->failed());
        self::assertArrayHasKey('name', $submission->errors());
        self::assertArrayHasKey('email', $submission->errors());
    }

    /**
     * A select must never trust the browser to have offered only what it was
     * given.
     */
    public function test_a_select_rejects_a_value_it_never_offered(): void
    {
        $form = Form::post('/contact')->add(
            Field::select('topic', ['sales', 'support'])->required(),
        );

        self::assertTrue(
            $form->handle($this->submit(['topic' => 'admin']), Session::started())->failed(),
        );
        self::assertFalse(
            $form->handle($this->submit(['topic' => 'sales']), Session::started())->failed(),
        );
    }

    public function test_values_are_unreachable_until_the_submission_passes(): void
    {
        $submission = $this->contactForm()->handle($this->submit([]), Session::started());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/failed\(\)/');

        $submission->values();
    }

    public function test_a_passing_submission_returns_its_values(): void
    {
        $submission = $this->contactForm()->handle(
            $this->submit(['name' => 'Ada', 'email' => 'ada@example.test']),
            Session::started(),
        );

        self::assertFalse($submission->failed());
        self::assertSame('Ada', $submission->value('name'));
        self::assertSame('ada@example.test', $submission->value('email'));
    }

    // --- honeypot -------------------------------------------------------------

    public function test_the_decoy_is_hidden_without_needing_a_stylesheet(): void
    {
        $html = $this->guardedForm()->render(Session::started());

        // The HTML attribute, honoured by the browser's own stylesheet — this
        // framework ships no inline CSS, and a class whose rule someone forgot
        // to copy would leave the trap visible and reject real people.
        self::assertStringContainsString('<div hidden aria-hidden="true">', $html);
        self::assertStringContainsString('name="website"', $html);
        self::assertStringContainsString('tabindex="-1"', $html);
        self::assertStringNotContainsString('style=', $html);
    }

    public function test_filling_the_decoy_is_rejected(): void
    {
        $submission = $this->guardedForm()->handle(
            $this->submit([
                'name' => 'Ada',
                'email' => 'ada@example.test',
                'website' => 'http://spam.example',
                '_rendered' => $this->renderedAt(-30),
            ]),
            Session::started(),
        );

        self::assertTrue($submission->failed());
        self::assertTrue($submission->looksAutomated());
        self::assertStringContainsString('decoy', (string) $submission->rejectedAs);
    }

    public function test_submitting_faster_than_a_person_types_is_rejected(): void
    {
        $submission = $this->guardedForm()->handle(
            $this->submit([
                'name' => 'Ada',
                'email' => 'ada@example.test',
                '_rendered' => $this->renderedAt(0),
            ]),
            Session::started(),
        );

        self::assertTrue($submission->looksAutomated());
        self::assertStringContainsString('faster than a person', (string) $submission->rejectedAs);
    }

    public function test_a_forged_or_missing_timestamp_is_rejected(): void
    {
        foreach ([[], ['_rendered' => (string) (time() - 30)]] as $fields) {
            $submission = $this->guardedForm()->handle(
                $this->submit(['name' => 'Ada', 'email' => 'ada@example.test', ...$fields]),
                Session::started(),
            );

            self::assertTrue($submission->looksAutomated());
        }
    }

    public function test_an_ordinary_submission_passes_the_honeypot(): void
    {
        $submission = $this->guardedForm()->handle(
            $this->submit([
                'name' => 'Ada',
                'email' => 'ada@example.test',
                'website' => '',
                '_rendered' => $this->renderedAt(-30),
            ]),
            Session::started(),
        );

        self::assertFalse($submission->failed(), (string) $submission->rejectedAs);
    }

    /**
     * The reason belongs in a log. Telling the submitter which check fired
     * tells a script author what to change.
     */
    public function test_the_page_is_told_nothing_about_which_check_fired(): void
    {
        $submission = $this->guardedForm()->handle(
            $this->submit(['website' => 'spam', '_rendered' => $this->renderedAt(-30)]),
            Session::started(),
        );

        $shown = implode(' ', $submission->errors());

        self::assertStringNotContainsString('decoy', $shown);
        self::assertStringNotContainsString('honeypot', $shown);
        self::assertStringNotContainsString('website', $shown);
    }

    // --- captcha --------------------------------------------------------------

    public function test_the_answer_is_not_readable_in_the_page(): void
    {
        $session = Session::started();
        $html = $this->captchaForm()->render($session);

        self::assertMatchesRegularExpression('/What is .+\?/', $html);

        // Encrypted, not signed: a signed answer is still readable, and the
        // visitor could simply read it out of the page source.
        $sealed = html_entity_decode($this->sealedAnswerIn($html), ENT_QUOTES, 'UTF-8');

        for ($answer = 0; $answer <= 100; $answer++) {
            self::assertStringNotContainsString((string) $answer, base64_decode(substr($sealed, 3), true) ?: '');
        }
    }

    public function test_the_right_answer_is_accepted_and_a_wrong_one_is_not(): void
    {
        $session = Session::started();
        $captcha = new MathCaptcha(new Encrypter($this->key), ttlSeconds: 900);
        $challenge = $captcha->challenge('phporbit:captcha:' . $session->id());

        $answer = $this->solve($challenge->question);

        $form = $this->captchaForm();

        $base = ['name' => 'Ada', 'email' => 'ada@example.test', '_captcha' => $challenge->sealedAnswer];

        self::assertFalse(
            $form->handle($this->submit([...$base, 'captcha' => (string) $answer]), $session)->failed(),
        );
        self::assertTrue(
            $form->handle($this->submit([...$base, 'captcha' => (string) ($answer + 1)]), $session)->failed(),
        );
    }

    /**
     * The sealed answer is bound to the session, so one solved elsewhere — by a
     * human solving service, say — cannot be pasted into another visitor's
     * submission.
     */
    public function test_a_challenge_solved_in_another_session_is_rejected(): void
    {
        $theirs = Session::started();
        $mine = Session::started();

        $captcha = new MathCaptcha(new Encrypter($this->key));
        $challenge = $captcha->challenge('phporbit:captcha:' . $theirs->id());

        $request = $this->submit([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'captcha' => (string) $this->solve($challenge->question),
            '_captcha' => $challenge->sealedAnswer,
        ]);

        // Correct in the session it was issued for — the control that makes the
        // next assertion mean something.
        self::assertTrue($captcha->isCorrect($request, 'phporbit:captcha:' . $theirs->id()));

        // The same solved answer, replayed into a different visitor's request.
        self::assertFalse($captcha->isCorrect($request, 'phporbit:captcha:' . $mine->id()));
    }

    public function test_an_expired_challenge_is_rejected(): void
    {
        $session = Session::started();
        $captcha = new MathCaptcha(new Encrypter($this->key), ttlSeconds: -1);
        $challenge = $captcha->challenge('phporbit:captcha:' . $session->id());

        self::assertFalse($captcha->isCorrect(
            $this->submit([
                'captcha' => (string) $this->solve($challenge->question),
                '_captcha' => $challenge->sealedAnswer,
            ]),
            'phporbit:captcha:' . $session->id(),
        ));
    }

    public function test_a_tampered_seal_is_rejected(): void
    {
        $session = Session::started();
        $captcha = new MathCaptcha(new Encrypter($this->key));
        $challenge = $captcha->challenge('phporbit:captcha:' . $session->id());

        self::assertFalse($captcha->isCorrect(
            $this->submit(['captcha' => '4', '_captcha' => $challenge->sealedAnswer . 'x']),
            'phporbit:captcha:' . $session->id(),
        ));
    }

    public function test_each_render_issues_a_fresh_challenge(): void
    {
        $session = Session::started();
        $form = $this->captchaForm();

        self::assertNotSame(
            $this->sealedAnswerIn($form->render($session)),
            $this->sealedAnswerIn($form->render($session)),
        );
    }

    // --- helpers --------------------------------------------------------------

    private function contactForm(): Form
    {
        return Form::post('/contact')->add(
            Field::text('name')->required()->max(80),
            Field::email('email')->required(),
        );
    }

    private function guardedForm(): Form
    {
        return $this->contactForm()->protectWith(
            new Honeypot(new Signer($this->key), minimumSeconds: 2),
        );
    }

    private function captchaForm(): Form
    {
        return $this->contactForm()->withCaptcha(new MathCaptcha(new Encrypter($this->key)));
    }

    /**
     * The sealed answer from a rendered form.
     */
    private function sealedAnswerIn(string $html): string
    {
        if (preg_match('/name="_captcha" value="([^"]+)"/', $html, $match) !== 1) {
            self::fail('no sealed answer in the rendered form');
        }

        return $match[1];
    }

    /**
     * A signed timestamp as the form would have rendered it $offset seconds ago.
     */
    private function renderedAt(int $offset): string
    {
        return (new Signer($this->key))->sign((string) (time() + $offset));
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(array $fields): ServerRequest
    {
        return new ServerRequest(
            Method::Post,
            Uri::fromRequestTarget('/contact', 'http', 'localhost', 8080),
            Headers::fromArray(['Content-Type' => 'application/x-www-form-urlencoded']),
            body: http_build_query($fields),
            form: $fields,
        );
    }

    /**
     * Works out the answer the way a person would.
     */
    private function solve(string $question): int
    {
        $words = [
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
            'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        ];

        if (preg_match('/What is (\S+) (plus|times) (\S+)\?/', $question, $match) !== 1) {
            self::fail('unparsable question: ' . $question);
        }

        $left = $words[$match[1]] ?? (int) $match[1];
        $right = $words[$match[3]] ?? (int) $match[3];

        return $match[2] === 'plus' ? $left + $right : $left * $right;
    }
}
