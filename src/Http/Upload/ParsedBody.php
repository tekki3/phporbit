<?php

declare(strict_types=1);

namespace PhpOrbit\Http\Upload;

/**
 * The result of decoding a multipart body: text fields and file parts.
 */
final class ParsedBody
{
    /** @var array<string, string> */
    public readonly array $fields;

    /** @var array<string, UploadedFile> */
    public readonly array $files;

    /**
     * @param array<string, string>       $fields
     * @param array<string, UploadedFile> $files
     */
    public function __construct(array $fields = [], array $files = [])
    {
        $this->fields = $fields;
        $this->files = $files;
    }

    public static function empty(): self
    {
        return new self();
    }
}
