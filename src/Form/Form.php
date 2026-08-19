<?php

declare(strict_types=1);

namespace PhpOrbit\Form;

use PhpOrbit\Http\Method;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Security\Csrf;
use PhpOrbit\Security\Escaper;
use PhpOrbit\Session\Session;
use PhpOrbit\Validation\Validator;

/**
 * A form that renders itself and checks its own submissions.
 *
 * The two halves cannot drift, because they come from one declaration:
 * `Field::email('email')->required()` produces both the `required` attribute
 * and the check that rejects a blank value. A form whose markup and validation
 * disagree is the ordinary way a field ends up unvalidated.
 *
 * Immutable, so one may be built once at boot and rendered on every request —
 * nothing about a visitor is stored on it. The per-request pieces (the CSRF
 * token, a captcha challenge, previously typed values) are passed in.
 *
 * Everything rendered is escaped. There is no method that emits raw HTML.
 */
final class Form
{
    /** @var list<Field> */
    public readonly array $fields;

    /**
     * @param list<Field> $fields
     */
    private function __construct(
        public readonly Method $method,
        public readonly string $action,
        array $fields = [],
        public readonly string $submitLabel = 'Submit',
        public readonly ?Honeypot $honeypot = null,
        public readonly ?Captcha $captcha = null,
    ) {
        $this->fields = $fields;
    }

    public static function post(string $action): self
    {
        return new self(Method::Post, $action);
    }

    /**
     * A GET form: no CSRF token, because it changes nothing.
     */
    public static function get(string $action): self
    {
        return new self(Method::Get, $action);
    }

    public function add(Field ...$fields): self
    {
        return $this->with(fields: [...$this->fields, ...array_values($fields)]);
    }

    public function submitLabel(string $label): self
    {
        return $this->with(submitLabel: $label);
    }

    /**
     * Adds the decoy field and timing check.
     */
    public function protectWith(Honeypot $honeypot): self
    {
        return $this->with(honeypot: $honeypot);
    }

    /**
     * Adds a question a person answers.
     */
    public function withCaptcha(Captcha $captcha): self
    {
        return $this->with(captcha: $captcha);
    }

    /**
     * Renders the complete form.
     *
     * @param array<string, string> $old    previously typed values, on redisplay
     * @param array<string, string> $errors field => message
     */
    public function render(Session $session, array $old = [], array $errors = []): string
    {
        $html = sprintf(
            '<form method="%s" action="%s" novalidate>',
            $this->method === Method::Get ? 'get' : 'post',
            Escaper::attribute($this->action),
        );

        // Automatic, and not optional for a state-changing form. A form that
        // needs a line of boilerplate to be protected is a form someone will
        // eventually write without it.
        if ($this->method !== Method::Get) {
            $html .= Csrf::field($session);
        }

        foreach ($this->fields as $field) {
            $html .= $this->renderField($field, $old[$field->name] ?? '', $errors[$field->name] ?? null);
        }

        if ($this->captcha !== null) {
            $html .= $this->renderCaptcha($session, $errors[$this->captcha->answerField()] ?? null);
        }

        // Last, so a script reading the form top-down has already committed.
        if ($this->honeypot !== null) {
            $html .= $this->honeypot->render();
        }

        return $html . sprintf(
            '<button type="submit">%s</button></form>',
            Escaper::html($this->submitLabel),
        );
    }

    /**
     * Checks a submission: protections first, then the field rules.
     *
     * The protections run first deliberately — there is no reason to spend
     * validation on something that already looks automated, and no reason to
     * tell it which of its fields were wrong.
     */
    public function handle(ServerRequest $request, Session $session): Submission
    {
        $submitted = [];

        foreach ($this->fields as $field) {
            $submitted[$field->name] = $request->form($field->name) ?? '';
        }

        if ($this->honeypot !== null) {
            $reason = $this->honeypot->rejectionReason($request);

            if ($reason !== null) {
                // One generic message. Naming the check that fired would tell a
                // script author precisely what to change.
                return Submission::rejected(
                    $submitted,
                    ['_form' => 'That submission could not be accepted. Please try again.'],
                    $reason,
                );
            }
        }

        if ($this->captcha !== null && !$this->captcha->isCorrect($request, $this->captchaContext($session))) {
            return Submission::rejected(
                $submitted,
                [$this->captcha->answerField() => 'That answer was not correct. Please try again.'],
            );
        }

        $validator = Validator::forRequest($request);

        foreach ($this->fields as $field) {
            $this->applyRules($validator, $field);
        }

        if ($validator->fails()) {
            return Submission::rejected($submitted, $validator->errors());
        }

        return Submission::accepted($submitted);
    }

    private function applyRules(Validator $validator, Field $field): void
    {
        if ($field->isRequired) {
            $validator->required($field->name);
        }

        if ($field->minLength !== null) {
            $validator->minLength($field->name, $field->minLength);
        }

        if ($field->maxLength !== null) {
            $validator->maxLength($field->name, $field->maxLength);
        }

        if ($field->type === FieldType::Email) {
            $validator->email($field->name);
        }

        if ($field->type === FieldType::Number) {
            $validator->integer($field->name);
        }

        if ($field->type === FieldType::Select && $field->options !== []) {
            // Never trust the browser to have offered only what it was given.
            $validator->in($field->name, $field->options);
        }
    }

    private function renderField(Field $field, string $value, ?string $error): string
    {
        $id = 'f-' . $field->name;
        $describedBy = [];

        if ($field->hint !== null) {
            $describedBy[] = $id . '-hint';
        }

        if ($error !== null) {
            $describedBy[] = $id . '-error';
        }

        $attributes = [
            'id' => $id,
            'name' => $field->name,
            // The value is escaped for an attribute, so a quote in it cannot
            // end the attribute and start another.
            'value' => $field->type->repopulates() ? $value : '',
            'autocomplete' => $field->autocomplete,
            'placeholder' => $field->placeholder,
            'maxlength' => $field->maxLength === null ? null : (string) $field->maxLength,
            'aria-describedby' => $describedBy === [] ? null : implode(' ', $describedBy),
            'aria-invalid' => $error === null ? null : 'true',
        ];

        $html = sprintf(
            '<p class="field"><label for="%s">%s%s</label>',
            Escaper::attribute($id),
            Escaper::html($field->label),
            $field->isRequired ? ' <span aria-hidden="true">*</span>' : '',
        );

        $html .= match ($field->type) {
            FieldType::Textarea => sprintf(
                '<textarea %s>%s</textarea>',
                $this->attributes([...$attributes, 'value' => null], $field),
                Escaper::html($value),
            ),
            FieldType::Select => sprintf(
                '<select %s>%s</select>',
                $this->attributes([...$attributes, 'value' => null, 'maxlength' => null], $field),
                $this->options($field, $value),
            ),
            default => sprintf(
                '<input type="%s" %s>',
                $field->type->value,
                $this->attributes($attributes, $field),
            ),
        };

        if ($field->hint !== null) {
            $html .= sprintf(
                '<span class="hint" id="%s">%s</span>',
                Escaper::attribute($id . '-hint'),
                Escaper::html($field->hint),
            );
        }

        if ($error !== null) {
            $html .= sprintf(
                '<span class="error" id="%s">%s</span>',
                Escaper::attribute($id . '-error'),
                Escaper::html($error),
            );
        }

        return $html . '</p>';
    }

    private function renderCaptcha(Session $session, ?string $error): string
    {
        // A challenge per render: reloading the page must not reissue the same
        // question with the same sealed answer.
        $captcha = $this->captcha;

        if ($captcha === null) {
            return '';
        }

        $challenge = $captcha->challenge($this->captchaContext($session));
        $id = 'f-' . $captcha->answerField();

        $html = sprintf(
            '<p class="field"><label for="%s">%s <span aria-hidden="true">*</span></label>'
            . '<input type="text" id="%s" name="%s" value="" required '
            . 'autocomplete="off" inputmode="numeric"%s>',
            Escaper::attribute($id),
            Escaper::html($challenge->question),
            Escaper::attribute($id),
            Escaper::attribute($captcha->answerField()),
            $error === null ? '' : sprintf(' aria-invalid="true" aria-describedby="%s"', Escaper::attribute($id . '-error')),
        );

        if ($error !== null) {
            $html .= sprintf(
                '<span class="error" id="%s">%s</span>',
                Escaper::attribute($id . '-error'),
                Escaper::html($error),
            );
        }

        return $html . sprintf(
            '<input type="hidden" name="%s" value="%s"></p>',
            Escaper::attribute($captcha->sealedField()),
            Escaper::attribute($challenge->sealedAnswer),
        );
    }

    /**
     * Ties a challenge to the visitor, so one solved elsewhere cannot be pasted
     * in. The session id is already a secret the visitor holds.
     */
    private function captchaContext(Session $session): string
    {
        return 'phporbit:captcha:' . $session->id();
    }

    /**
     * @param array<string, string|null> $attributes
     */
    private function attributes(array $attributes, Field $field): string
    {
        $rendered = [];

        foreach ($attributes as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $rendered[] = sprintf('%s="%s"', $name, Escaper::attribute($value));
        }

        if ($field->isRequired) {
            $rendered[] = 'required';
        }

        return implode(' ', $rendered);
    }

    private function options(Field $field, string $selected): string
    {
        $html = '';

        foreach ($field->options as $option) {
            $html .= sprintf(
                '<option value="%s"%s>%s</option>',
                Escaper::attribute($option),
                $option === $selected ? ' selected' : '',
                Escaper::html($option),
            );
        }

        return $html;
    }

    /**
     * @param list<Field>|null $fields
     */
    private function with(
        ?array $fields = null,
        ?string $submitLabel = null,
        ?Honeypot $honeypot = null,
        ?Captcha $captcha = null,
    ): self {
        return new self(
            $this->method,
            $this->action,
            $fields ?? $this->fields,
            $submitLabel ?? $this->submitLabel,
            $honeypot ?? $this->honeypot,
            $captcha ?? $this->captcha,
        );
    }
}
