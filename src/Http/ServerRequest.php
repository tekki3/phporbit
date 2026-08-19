<?php

declare(strict_types=1);

namespace PhpOrbit\Http;

use PhpOrbit\Http\Upload\UploadedFile;

/**
 * An incoming request, fully detached from the SAPI that produced it.
 *
 * Every field is materialised eagerly by the adapter. Nothing here reads a
 * superglobal, so the same object is produced identically under FPM, Apache,
 * FrankenPHP and the built-in server.
 */
final class ServerRequest
{
    /** @var array<string, string> */
    private readonly array $cookies;

    /** @var array<string, string> */
    private readonly array $attributes;

    /** @var array<string, string> */
    private readonly array $form;

    /** @var array<string, UploadedFile> */
    private readonly array $files;

    /**
     * @param array<string, string>       $cookies
     * @param array<string, string>       $attributes route parameters and middleware annotations
     * @param array<string, string>       $form       decoded form fields, see {@see FormBody}
     * @param array<string, UploadedFile> $files      decoded uploads, keyed by field name
     */
    public function __construct(
        public readonly Method $method,
        public readonly Uri $uri,
        public readonly Headers $headers,
        public readonly string $body = '',
        array $cookies = [],
        array $attributes = [],
        array $form = [],
        array $files = [],
    ) {
        $this->cookies = $cookies;
        $this->attributes = $attributes;
        $this->form = $form;
        $this->files = $files;
    }

    /**
     * An uploaded file by field name, or null when the field carried none.
     */
    public function file(string $field): ?UploadedFile
    {
        return $this->files[$field] ?? null;
    }

    /**
     * @return array<string, UploadedFile>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * A submitted form field, or null when absent.
     */
    public function form(string $name): ?string
    {
        return $this->form[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function formData(): array
    {
        return $this->form;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * A value attached upstream — typically a route parameter.
     */
    public function attribute(string $name): ?string
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function withAttribute(string $name, string $value): self
    {
        $attributes = $this->attributes;
        $attributes[$name] = $value;

        return new self(
            $this->method,
            $this->uri,
            $this->headers,
            $this->body,
            $this->cookies,
            $attributes,
            $this->form,
            $this->files,
        );
    }

    /**
     * @param array<string, string> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self(
            $this->method,
            $this->uri,
            $this->headers,
            $this->body,
            $this->cookies,
            [...$this->attributes, ...$attributes],
            $this->form,
            $this->files,
        );
    }
}
