<?php

declare(strict_types=1);

namespace PhpOrbit\Http;

use PhpOrbit\Security\Escaper;

final class Response
{
    private function __construct(
        public readonly Status $status,
        public readonly Headers $headers,
        public readonly string $body,
    ) {
    }

    public static function make(Status $status, string $body = '', ?Headers $headers = null): self
    {
        return new self($status, self::secureDefaults($headers ?? Headers::empty()), $body);
    }

    /**
     * Plain text. Charset is always stated — omitting it invites the browser
     * to sniff, which is an XSS vector.
     */
    public static function text(string $body, Status $status = Status::Ok): self
    {
        return self::make($status, $body)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * HTML from an already-escaped string.
     *
     * Nothing in phporbit escapes on output at this layer; use {@see Escaper}
     * or the template engine to produce the string handed in here.
     */
    public static function html(string $body, Status $status = Status::Ok): self
    {
        return self::make($status, $body)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * JSON encoded with escaping suitable for embedding in HTML.
     *
     * JSON_HEX_* prevents a payload containing `</script>` from breaking out
     * when the response is inlined into a page.
     */
    public static function json(mixed $data, Status $status = Status::Ok): self
    {
        $encoded = json_encode(
            $data,
            JSON_THROW_ON_ERROR
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_UNICODE,
        );

        return self::make($status, $encoded)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public static function redirect(string $location, Status $status = Status::Found): self
    {
        return self::make($status)->withHeader('Location', $location);
    }

    public static function noContent(): self
    {
        return self::make(Status::NoContent);
    }

    /**
     * Adds a Set-Cookie header, keeping any already present.
     */
    public function withCookie(Cookie $cookie): self
    {
        return $this->withAddedHeader('Set-Cookie', $cookie->toHeaderValue());
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->headers->with($name, $value), $this->body);
    }

    public function withAddedHeader(string $name, string $value): self
    {
        return new self($this->status, $this->headers->add($name, $value), $this->body);
    }

    public function withStatus(Status $status): self
    {
        return new self($status, $this->headers, $this->body);
    }

    public function withBody(string $body): self
    {
        return new self($this->status, $this->headers, $body);
    }

    /**
     * The body as it should appear on the wire.
     *
     * 204 and 304 must not carry one regardless of what the handler built.
     */
    public function wireBody(): string
    {
        return $this->status->allowsBody() ? $this->body : '';
    }

    /**
     * Headers applied to every response unless a handler overrides them.
     *
     * These are set here rather than in middleware so that a response built on
     * an error path — where middleware may have been skipped — still carries
     * them.
     */
    private static function secureDefaults(Headers $headers): Headers
    {
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'; base-uri 'self'",
        ];

        foreach ($defaults as $name => $value) {
            if (!$headers->has($name)) {
                $headers = $headers->with($name, $value);
            }
        }

        return $headers;
    }
}
