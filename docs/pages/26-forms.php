<?php

declare(strict_types=1);

return [
    'slug' => 'forms',
    'title' => 'Forms',
    'summary' => 'One declaration that renders a form, validates it, and carries its own spam protection.',
    'body' => <<<'HTML'
[[php]]
<?php
use PhpOrbit\Form\Field;
use PhpOrbit\Form\Form;

$form = Form::post('/contact')
    ->add(
        Field::text('name')->required()->max(80),
        Field::email('email')->required()->hint('Only used to reply to you.'),
        Field::select('topic', ['General', 'Bug report', 'Security'])->required(),
        Field::textarea('message')->required()->min(10)->max(2000),
    )
    ->submitLabel('Send message');
[[/php]]

[[php]]
<?php
// Rendering
echo $form->render($session);

// Handling
$submission = $form->handle($request, $session);

if ($submission->failed()) {
    return $this->view->respond('contact', [
        'form' => $form->render($session, $submission->old(), $submission->errors()),
    ], Status::UnprocessableEntity);
}

$name = $submission->value('name');
[[/php]]

<div class="good">
<b>The markup and the validation cannot disagree</b>
<p><code>Field::email('email')->required()</code> produces the <code>required</code> attribute <em>and</em> the check that rejects a blank value. A form whose rendering and validation are declared separately is the ordinary way a field ends up unvalidated — the two drift, and the one that matters is the one nobody updated.</p>
</div>

<h2>What you get without asking</h2>

<ul>
<li><strong>A CSRF token</strong>, on every <code>post()</code> form. Not a line of boilerplate you could forget.</li>
<li><strong>Escaped output.</strong> There is no method on a form that emits raw HTML, so a rejected submission's values go back into the page as values rather than markup.</li>
<li><strong>Labels tied to inputs</strong>, with <code>aria-describedby</code> pointing at hints and errors, and <code>aria-invalid</code> on the fields that failed.</li>
<li><strong>Passwords are never echoed back</strong> on redisplay.</li>
<li><strong>Selects are re-checked server-side</strong> against the options they were rendered with — the browser is not trusted to have offered only what it was given.</li>
</ul>

<h2>Fields</h2>

[[php]]
<?php
Field::text('name');       Field::email('email');     Field::password('password');
Field::textarea('body');   Field::number('quantity'); Field::url('website');
Field::tel('phone');       Field::date('starts_on');  Field::checkbox('terms');
Field::select('topic', ['General', 'Support']);

Field::text('name')
    ->label('Your full name')      // otherwise derived from the field name
    ->required()
    ->min(2)->max(80)
    ->hint('As it appears on your account')
    ->placeholder('Ada Lovelace')
    ->autocomplete('name');
[[/php]]

<p>Fields and forms are immutable, so a form may be defined once — at boot, or in a small class of its own — and rendered on every request. Nothing about a visitor sticks to it.</p>

<h2>Honeypot</h2>

<p>Two cheap checks that ask a person for nothing.</p>

[[php]]
<?php
use PhpOrbit\Form\Honeypot;

$form = $form->protectWith(new Honeypot($signer));
[[/php]]

<p><strong>A decoy field</strong> a person never sees and a script fills because it fills everything. <strong>A signed timestamp</strong> saying when the form was rendered: submissions arriving faster than a person could type are refused, and ones arriving hours later have gone stale. Signed rather than stored, so it costs no session state and survives a visitor with several tabs open.</p>

<div class="note">
<b>Hidden by HTML, not by a stylesheet</b>
<p>The decoy sits in a <code>&lt;div hidden aria-hidden="true"&gt;</code>. This framework ships no inline CSS, and a class whose rule someone forgets to copy into their own stylesheet would leave the trap visible — and then reject the real people who dutifully fill it in. The <code>hidden</code> attribute is honoured by the browser's own stylesheet, so it works wherever the markup does.</p>
</div>

<h2>Captcha</h2>

[[php]]
<?php
use PhpOrbit\Form\MathCaptcha;

$form = $form->withCaptcha(new MathCaptcha($encrypter));
[[/php]]

<p>A small arithmetic question — <em>What is seven plus 3?</em> — with some numbers spelled as words to defeat the obvious regex. No JavaScript, no third-party script, no images, no request leaving your server, and nothing for a screen reader to struggle with. A distorted-image captcha fails all five.</p>

<p>The answer is <strong>encrypted, not signed</strong>. A signed value is still readable, and the visitor could simply read the answer out of the page source. It is also bound to the session, so a challenge solved elsewhere — by a human solving service, say — cannot be pasted into another visitor's submission, and it expires.</p>

<div class="warn">
<b>Be clear-eyed about what this stops</b>
<p>It stops undirected scripts: the ones that post to every form they find. It will <strong>not</strong> stop someone who has decided to attack you specifically, because a language model solves arithmetic without effort. Treat it as one layer alongside the honeypot and rate limiting, not as a wall.</p>
<p>If you need to resist a determined attacker, implement the <code>Captcha</code> interface against a service built for that job — and note that most of them need JavaScript, which is a choice for your application to make.</p>
</div>

<h2>What a rejected submission is told</h2>

[[php]]
<?php
if ($submission->looksAutomated()) {
    $logger->log(Level::Warning, 'form rejected', ['reason' => $submission->rejectedAs]);
}
[[/php]]

<p>The page gets one generic message. The reason — <em>the decoy field was filled in</em>, <em>submitted after 0 seconds</em> — goes to your log only. Telling the submitter which check fired tells a script author exactly what to change next.</p>

<p><code>values()</code> throws if the submission was rejected, so there is no path that reads a field the checks refused. Use <code>old()</code> to repopulate the form and <code>errors()</code> to show what went wrong.</p>

<h2>Defining a form once</h2>

<p>Rendering and handling usually live in different controllers. Give the form its own small class so the two cannot disagree:</p>

[[php]]
<?php
final class ContactForm
{
    public function __construct(
        private readonly Signer $signer,
        private readonly Encrypter $encrypter,
    ) {
    }

    public function build(): Form
    {
        return Form::post('/contact')
            ->add(/* … */)
            ->protectWith(new Honeypot($this->signer))
            ->withCaptcha(new MathCaptcha($this->encrypter));
    }
}
[[/php]]

<p>The demo application does exactly this — see <code>/contact</code> on a running server, and read the page source: the decoy is visible in the markup, and the captcha's sealed answer is not.</p>

<p><code>orbit make:form Contact --controllers</code> writes that class, the two controllers built against it and the template, with the honeypot already attached — see <a href="cli.html">The orbit CLI</a>.</p>

<h2>Not built</h2>

<ul>
<li><strong>File inputs.</strong> Uploads have their own quotas and cleanup contract; see <a href="uploads.html">File uploads</a>.</li>
<li><strong>Radio groups and multi-selects.</strong> A select covers the common case; anything richer is markup you write yourself around the same <code>Validator</code>.</li>
<li><strong>Client-side validation.</strong> The rendered attributes (<code>required</code>, <code>maxlength</code>, <code>type</code>) are what the browser needs; the form carries <code>novalidate</code> so your messages are shown rather than the browser's, and every rule is enforced on the server regardless.</li>
<li><strong>Rate limiting.</strong> Worth adding in front of any public form — <code>LoginThrottle</code> shows the shape.</li>
</ul>
HTML,
];
