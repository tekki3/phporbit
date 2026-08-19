<?php

declare(strict_types=1);

namespace PhpOrbit\Http;

use InvalidArgumentException;

/**
 * A cookie to be sent with a response.
 *
 * The defaults are the restrictive ones: HttpOnly so script cannot read it,
 * SameSite=Lax so it is not attached to cross-site subrequests, and Path=/.
 * Relaxing any of them requires saying so at the call site.
 *
 * `secure` is the one default that cannot simply be true: a Secure cookie is
 * never sent over plain HTTP, which would silently break the built-in dev
 * server on http://localhost. Callers pass the request's scheme instead, so
 * the flag is set correctly in both places — see {@see forRequest()}.
 */
final class Cookie
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly ?int $expires = null,
        public readonly string $path = '/',
        public readonly ?string $domain = null,
        public readonly bool $secure = true,
        public readonly bool $httpOnly = true,
        public readonly SameSite $sameSite = SameSite::Lax,
    ) {
        // A separator or control character here would end the attribute early
        // and let the rest of the value forge cookie attributes of its own.
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid cookie name "%s".', $name));
        }

        if (preg_match('/[\x00-\x20",;\\\\\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(
                'Cookie values may not contain control characters, spaces, quotes, commas, '
                . 'semicolons or backslashes. Encode the value first.',
            );
        }

        if ($sameSite === SameSite::None && !$secure) {
            throw new InvalidArgumentException(
                'SameSite=None requires the Secure attribute; browsers reject the combination otherwise.',
            );
        }
    }

    /**
     * Builds a cookie whose Secure flag matches how the request arrived.
     */
    public static function forRequest(
        ServerRequest $request,
        string $name,
        string $value,
        ?int $expires = null,
        SameSite $sameSite = SameSite::Lax,
    ): self {
        return new self(
            $name,
            $value,
            $expires,
            secure: $request->uri->isSecure(),
            sameSite: $sameSite,
        );
    }

    /**
     * A cookie that instructs the browser to drop an existing one.
     *
     * The attributes must match those it was set with or the browser keeps it.
     */
    public static function expired(string $name, string $path = '/', bool $secure = true): self
    {
        return new self($name, '', expires: 0, path: $path, secure: $secure);
    }

    public function toHeaderValue(): string
    {
        $parts = [$this->name . '=' . $this->value];

        if ($this->expires !== null) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $this->expires);
            $parts[] = 'Max-Age=' . max(0, $this->expires - time());
        }

        $parts[] = 'Path=' . $this->path;

        if ($this->domain !== null) {
            $parts[] = 'Domain=' . $this->domain;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite->value;

        return implode('; ', $parts);
    }
}
