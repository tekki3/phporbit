<?php

declare(strict_types=1);

namespace PhpOrbit\Form;

/**
 * The input types a form can render.
 *
 * A closed set rather than a free string: the type reaches the `type=`
 * attribute, and an arbitrary one is both a rendering surprise and a way to
 * smuggle attributes into the tag.
 */
enum FieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Password = 'password';
    case Number = 'number';
    case Url = 'url';
    case Tel = 'tel';
    case Date = 'date';
    case Hidden = 'hidden';
    case Checkbox = 'checkbox';
    case Textarea = 'textarea';
    case Select = 'select';

    /**
     * Whether the browser should be told not to autofill it.
     *
     * A password manager filling a one-time code or a honeypot is a support
     * ticket waiting to happen.
     */
    public function rendersAsInput(): bool
    {
        return $this !== self::Textarea && $this !== self::Select;
    }

    /**
     * A value the user never typed should not be echoed back on redisplay.
     */
    public function repopulates(): bool
    {
        return $this !== self::Password;
    }
}
