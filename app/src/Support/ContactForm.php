<?php

declare(strict_types=1);

namespace App\Support;

use PhpOrbit\Crypto\Encrypter;
use PhpOrbit\Crypto\Signer;
use PhpOrbit\Form\Field;
use PhpOrbit\Form\Form;
use PhpOrbit\Form\Honeypot;
use PhpOrbit\Form\MathCaptcha;

/**
 * One definition of the contact form, used to render it and to check it.
 *
 * The form is immutable, so this could equally be built once at boot; it is
 * built per call here only because that reads more plainly.
 */
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
            ->add(
                Field::text('name')->label('Your name')->required()->max(80)->autocomplete('name'),
                Field::email('email')->label('Email')->required()
                    ->hint('Only used to reply to you.'),
                Field::select('topic', ['General', 'Bug report', 'Security'])->required(),
                Field::textarea('message')->required()->min(10)->max(2000),
            )
            ->submitLabel('Send message')
            // Two layers: the decoy and clock stop undirected scripts, the
            // question raises the cost for anything that got past them.
            ->protectWith(new Honeypot($this->signer))
            ->withCaptcha(new MathCaptcha($this->encrypter));
    }
}
