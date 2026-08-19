<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

use InvalidArgumentException;

/**
 * One mailbox, validated on construction.
 *
 * A CR or LF reaching a header is the classic email injection: everything after
 * it becomes headers of the attacker's choosing — extra recipients, a forged
 * From, a second body. Both parts are therefore rejected outright rather than
 * stripped, in the same spirit as {@see \PhpOrbit\Http\Headers}.
 */
final class Address
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $name = null,
    ) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid email address.', $email));
        }

        // FILTER_VALIDATE_EMAIL already refuses these, but the check is stated
        // rather than assumed: it is the property the whole class exists for.
        if (preg_match('/[\r\n\0]/', $email) === 1) {
            throw new InvalidArgumentException('An email address may not contain CR, LF or NUL.');
        }

        if ($name !== null && preg_match('/[\r\n\0]/', $name) === 1) {
            throw new InvalidArgumentException(
                'A display name may not contain CR, LF or NUL — that is how header injection works.',
            );
        }
    }

    /**
     * Parses `Ada Lovelace <ada@example.test>` or a bare address.
     */
    public static function parse(string $value): self
    {
        $value = trim($value);

        if (preg_match('/^(.*)<([^<>]+)>$/', $value, $match) === 1) {
            $name = trim($match[1], " \t\"");

            return new self(trim($match[2]), $name === '' ? null : $name);
        }

        return new self($value);
    }

    /**
     * The header form, with the display name MIME-encoded when it needs it.
     */
    public function toHeaderValue(): string
    {
        if ($this->name === null || $this->name === '') {
            return $this->email;
        }

        return sprintf('%s <%s>', Mime::encodeHeaderWord($this->name), $this->email);
    }

    /**
     * The bare address, as it appears in the SMTP envelope.
     *
     * The envelope is what actually routes the message; the headers are only
     * what the recipient's client displays.
     */
    public function envelope(): string
    {
        return $this->email;
    }

    public function __toString(): string
    {
        return $this->toHeaderValue();
    }
}
