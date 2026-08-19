<?php

declare(strict_types=1);

namespace PhpOrbit\Http\Upload;

use PhpOrbit\Http\Exception\MalformedRequest;
use PhpOrbit\Http\Headers;
use RuntimeException;

/**
 * Decodes `multipart/form-data` bodies.
 *
 * Used by the built-in server, which reads the request itself. Under FPM,
 * Apache and FrankenPHP, PHP has already decoded the body into `$_FILES`
 * before any of this code runs, and {@see \PhpOrbit\Sapi\FpmSapi} adapts that
 * instead — the two paths converge on the same {@see UploadedFile} objects.
 *
 * The body is decoded from a string already held in memory, which bounds how
 * large an upload the built-in server can accept. That is a deliberate limit
 * for a development server; the production targets stream to disk before PHP
 * sees the request.
 */
final class MultipartParser
{
    public function __construct(
        private readonly UploadQuotas $quotas = new UploadQuotas(),
        private readonly ?string $temporaryDirectory = null,
    ) {
    }

    /**
     * Whether this content type is a multipart body this parser handles.
     */
    public static function handles(Headers $headers): bool
    {
        $contentType = $headers->first('Content-Type');

        if ($contentType === null) {
            return false;
        }

        return str_starts_with(strtolower(trim($contentType)), 'multipart/form-data');
    }

    public function parse(Headers $headers, string $body): ParsedBody
    {
        $boundary = $this->boundary($headers);
        $delimiter = '--' . $boundary;

        $segments = explode($delimiter, $body);

        // The text before the first delimiter is a preamble and is discarded.
        array_shift($segments);

        if ($segments === []) {
            throw new MalformedRequest('The multipart body contains no parts.');
        }

        $fields = [];
        $files = [];
        $totalBytes = 0;
        $fileCount = 0;
        $partCount = 0;

        foreach ($segments as $segment) {
            // "--" immediately after a delimiter marks the end of the body.
            if (str_starts_with($segment, '--')) {
                break;
            }

            if (++$partCount > $this->quotas->maxParts) {
                throw new MalformedRequest(sprintf(
                    'The multipart body has more than %d parts.',
                    $this->quotas->maxParts,
                ));
            }

            [$rawHeaders, $content] = $this->split($segment);

            $disposition = $this->contentDisposition($rawHeaders);

            if ($disposition === null) {
                continue;
            }

            [$name, $filename] = $disposition;

            if ($filename === null) {
                if (strlen($content) > $this->quotas->maxFieldBytes) {
                    throw new MalformedRequest(sprintf(
                        'Form field "%s" exceeds the %d byte limit.',
                        $name,
                        $this->quotas->maxFieldBytes,
                    ));
                }

                $fields[$name] = $content;

                continue;
            }

            // A file input left empty still sends a part, with a blank filename.
            if ($filename === '' && $content === '') {
                $files[$name] = UploadedFile::failed($name, UploadError::NoFile);

                continue;
            }

            if (++$fileCount > $this->quotas->maxFiles) {
                $files[$name] = UploadedFile::failed($name, UploadError::TooMany, $filename);

                continue;
            }

            $size = strlen($content);

            if ($size > $this->quotas->maxFileBytes) {
                $files[$name] = UploadedFile::failed($name, UploadError::TooLarge, $filename);

                continue;
            }

            $totalBytes += $size;

            if ($totalBytes > $this->quotas->maxTotalBytes) {
                throw new MalformedRequest(sprintf(
                    'The uploads total more than the %d byte limit.',
                    $this->quotas->maxTotalBytes,
                ));
            }

            $files[$name] = $this->store($name, $filename, $this->mediaType($rawHeaders), $content);
        }

        return new ParsedBody($fields, $files);
    }

    /**
     * Writes a part's bytes to a temporary file.
     */
    private function store(string $field, string $filename, ?string $mediaType, string $content): UploadedFile
    {
        $directory = $this->temporaryDirectory ?? sys_get_temp_dir();

        $path = @tempnam($directory, 'orbit-upload-');

        if ($path === false) {
            return UploadedFile::failed($field, UploadError::CannotWrite, $filename);
        }

        // Uploads can contain anything; keep them unreadable to other accounts
        // for the short time they exist.
        @chmod($path, 0o600);

        if (@file_put_contents($path, $content) === false) {
            @unlink($path);

            return UploadedFile::failed($field, UploadError::CannotWrite, $filename);
        }

        return new UploadedFile(
            $field,
            $filename,
            $mediaType,
            strlen($content),
            UploadError::None,
            $path,
            managedByPhp: false,
        );
    }

    /**
     * @return array{0: string, 1: string} raw headers, content
     */
    private function split(string $segment): array
    {
        // Each part begins with the CRLF that ended the delimiter line and
        // ends with the CRLF that starts the next one.
        $segment = preg_replace('/^\r\n/', '', $segment) ?? $segment;
        $segment = preg_replace('/\r\n$/', '', $segment) ?? $segment;

        $separator = strpos($segment, "\r\n\r\n");

        if ($separator === false) {
            throw new MalformedRequest('A multipart part has no header block.');
        }

        return [
            substr($segment, 0, $separator),
            substr($segment, $separator + 4),
        ];
    }

    /**
     * Extracts the field name and, for files, the client filename.
     *
     * @return array{0: string, 1: string|null}|null null when the part is not form-data
     */
    private function contentDisposition(string $rawHeaders): ?array
    {
        if (preg_match('/^Content-Disposition:\s*(.+)$/mi', $rawHeaders, $match) !== 1) {
            return null;
        }

        $disposition = $match[1];

        if (stripos($disposition, 'form-data') === false) {
            return null;
        }

        if (preg_match('/\bname="((?:[^"\\\\]|\\\\.)*)"/i', $disposition, $nameMatch) !== 1) {
            return null;
        }

        $name = stripslashes($nameMatch[1]);

        // A NUL in a field name has no legitimate use and can truncate strings
        // in code further down that still uses C-style APIs.
        if (str_contains($name, "\0")) {
            throw new MalformedRequest('A multipart field name contains NUL.');
        }

        $filename = null;
        if (preg_match('/\bfilename="((?:[^"\\\\]|\\\\.)*)"/i', $disposition, $fileMatch) === 1) {
            $filename = str_replace("\0", '', stripslashes($fileMatch[1]));
        }

        return [$name, $filename];
    }

    private function mediaType(string $rawHeaders): ?string
    {
        if (preg_match('/^Content-Type:\s*([^\r\n;]+)/mi', $rawHeaders, $match) !== 1) {
            return null;
        }

        return trim($match[1]);
    }

    /**
     * Pulls the boundary out of the Content-Type header.
     */
    private function boundary(Headers $headers): string
    {
        $contentType = $headers->first('Content-Type') ?? '';

        // One capture covering both the quoted and bare forms; the optional
        // quotes sit outside it.
        if (preg_match('/boundary="?([^";,\s]+)"?/i', $contentType, $match) !== 1) {
            throw new MalformedRequest('The multipart Content-Type declares no boundary.');
        }

        return $match[1];
    }

    /**
     * Ensures a usable temporary directory, for callers that configure one.
     */
    public static function assertWritableDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create the upload directory "%s".', $directory));
        }

        if (!is_writable($directory)) {
            throw new RuntimeException(sprintf('The upload directory "%s" is not writable.', $directory));
        }
    }
}
