<?php

declare(strict_types=1);

namespace PhpOrbit\Form;

use PhpOrbit\Crypto\Signer;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Security\Escaper;

/**
 * Two cheap checks that stop most automated submissions without asking a human
 * for anything.
 *
 * **The decoy field.** A text input a person never sees and a script fills in
 * because it fills everything. It is hidden with the HTML `hidden` attribute
 * rather than a stylesheet: this framework ships no inline CSS, and a class
 * whose rule someone forgets to copy would leave the trap visible and reject
 * real people. `hidden` is honoured by the browser's own stylesheet, so it
 * works wherever the markup does.
 *
 * **The clock.** A signed timestamp saying when the form was rendered.
 * Submissions arriving faster than a person could type are refused, and ones
 * arriving days later have gone stale. It is signed, not stored, so it costs no
 * session state and survives a visitor with several tabs open.
 *
 * Neither is a wall. Together they remove the volume — the scripts that post to
 * every form they find — and leave anything that targeted you specifically to
 * the captcha.
 */
final class Honeypot
{
    public function __construct(
        private readonly Signer $signer,
        /** The decoy's name. Plausible enough that a script wants to fill it. */
        public readonly string $decoyField = 'website',
        public readonly string $timestampField = '_rendered',
        /** Faster than this and it was not typed. */
        public readonly int $minimumSeconds = 2,
        /** Older than this and the page has been sitting open too long. */
        public readonly int $maximumSeconds = 7200,
    ) {
    }

    /**
     * The markup to place inside the form.
     */
    public function render(): string
    {
        // aria-hidden and tabindex keep it out of a screen reader's reading
        // order and out of the tab sequence, so it is invisible to people using
        // the form without a mouse too.
        return sprintf(
            '<div hidden aria-hidden="true">'
            . '<label for="%1$s">Leave this field empty</label>'
            . '<input type="text" id="%1$s" name="%1$s" value="" tabindex="-1" autocomplete="off">'
            . '</div>'
            . '<input type="hidden" name="%2$s" value="%3$s">',
            Escaper::attribute($this->decoyField),
            Escaper::attribute($this->timestampField),
            Escaper::attribute($this->signer->sign((string) time())),
        );
    }

    /**
     * Why this submission looks automated, or null if it does not.
     *
     * The reason is for your logs. Showing it to the submitter would tell a
     * script author exactly which check to defeat next.
     */
    public function rejectionReason(ServerRequest $request): ?string
    {
        if (($request->form($this->decoyField) ?? '') !== '') {
            return 'the decoy field was filled in';
        }

        $signed = $request->form($this->timestampField);

        if ($signed === null) {
            return 'the timing field was missing';
        }

        $renderedAt = $this->signer->verify($signed);

        if ($renderedAt === null || preg_match('/^\d+$/', $renderedAt) !== 1) {
            return 'the timing field was forged or malformed';
        }

        $elapsed = time() - (int) $renderedAt;

        if ($elapsed < $this->minimumSeconds) {
            return sprintf('submitted after %d seconds, faster than a person types', $elapsed);
        }

        if ($elapsed > $this->maximumSeconds) {
            return 'the form was rendered too long ago';
        }

        return null;
    }
}
