<?php

declare(strict_types=1);

namespace PhpOrbit\Http\Upload;

use RuntimeException;

/**
 * One uploaded file, living in a temporary location until moved or discarded.
 *
 * Three things about an upload are attacker-controlled and none of them may be
 * trusted: the filename, the declared media type, and the bytes. So:
 *
 * - {@see clientFilename} is never used to build a path. Use
 *   {@see safeName()}, or better, a name the application generates.
 * - {@see clientMediaType} is what the browser claimed. {@see detectedType()}
 *   inspects the actual bytes; only the latter should gate anything.
 * - {@see moveTo()} refuses to write outside the directory it is given.
 *
 * Instances are per-request. The kernel discards any that were not moved when
 * the request scope closes, so a handler that ignores an upload does not leave
 * a file behind.
 */
final class UploadedFile
{
    private bool $moved = false;

    private bool $discarded = false;

    /**
     * @param bool $managedByPhp true when PHP's SAPI created the temp file, in
     *                           which case moving it must go through
     *                           move_uploaded_file()
     */
    public function __construct(
        public readonly string $field,
        public readonly string $clientFilename,
        public readonly ?string $clientMediaType,
        public readonly int $size,
        public readonly UploadError $error,
        private readonly string $temporaryPath,
        private readonly bool $managedByPhp = false,
    ) {
    }

    /**
     * A placeholder for a field that produced no usable file.
     */
    public static function failed(string $field, UploadError $error, string $clientFilename = ''): self
    {
        return new self($field, $clientFilename, null, 0, $error, '', false);
    }

    public function isValid(): bool
    {
        return $this->error === UploadError::None
            && !$this->moved
            && !$this->discarded
            && $this->temporaryPath !== ''
            && is_file($this->temporaryPath);
    }

    public function wasMoved(): bool
    {
        return $this->moved;
    }

    /**
     * The media type sniffed from the file's actual contents.
     *
     * This is what to check against an allowlist. A file named `avatar.png`
     * and declared `image/png` can still be a PHP script; only the bytes say
     * what it really is.
     */
    public function detectedType(): ?string
    {
        if (!$this->isValid()) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        // No finfo_close(): it is deprecated as of PHP 8.5, since the handle
        // is an object that is released when it goes out of scope.
        $type = finfo_file($finfo, $this->temporaryPath);

        return $type === false ? null : $type;
    }

    /**
     * Whether the real contents match one of the permitted types.
     *
     * @param list<string> $allowed
     */
    public function hasTypeIn(array $allowed): bool
    {
        $detected = $this->detectedType();

        return $detected !== null && in_array($detected, $allowed, true);
    }

    /**
     * The client's filename reduced to something safe to put on disk.
     *
     * Strips any directory component, collapses everything outside a small
     * character set, and refuses a leading dot so an upload cannot become
     * `.htaccess`. Still not a substitute for generating your own name — two
     * users can upload the same filename.
     */
    public function safeName(string $fallback = 'upload'): string
    {
        // Removed rather than substituted: a NUL truncates the name in any
        // code still using a C-style API, so `shell.php\0.png` must become
        // `shell.php.png` and not gain a separator that hides the real
        // extension.
        $name = str_replace("\0", '', $this->clientFilename);

        // basename() alone is not enough: it keeps backslashes, which some
        // filesystems treat as separators.
        $name = basename(str_replace('\\', '/', $name));

        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $name = trim($name, '.-');

        if ($name === '' || $name === '.' || $name === '..') {
            return $fallback;
        }

        // Keep names short enough for every filesystem in common use.
        return substr($name, 0, 120);
    }

    /**
     * The extension implied by the file's real contents.
     *
     * Deriving it from the sniffed type rather than the client's filename is
     * what stops `photo.php` from keeping its extension.
     *
     * @param array<string, string> $typeToExtension media type => extension
     */
    public function extensionFromContents(array $typeToExtension): ?string
    {
        $detected = $this->detectedType();

        return $detected === null ? null : ($typeToExtension[$detected] ?? null);
    }

    public function contents(): string
    {
        if (!$this->isValid()) {
            throw new RuntimeException(sprintf(
                'Cannot read upload "%s": %s',
                $this->field,
                $this->moved ? 'it has already been moved.' : $this->error->message(),
            ));
        }

        $contents = file_get_contents($this->temporaryPath);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Cannot read the temporary file for "%s".', $this->field));
        }

        return $contents;
    }

    /**
     * Moves the upload into a directory under a name the caller chooses.
     *
     * The name is resolved against the directory and the result checked to be
     * inside it, so a name containing traversal cannot place the file
     * elsewhere even if it reached here unsanitised.
     */
    public function moveTo(string $directory, string $name): string
    {
        if (!$this->isValid()) {
            throw new RuntimeException(sprintf(
                'Cannot move upload "%s": %s',
                $this->field,
                $this->moved ? 'it has already been moved.' : $this->error->message(),
            ));
        }

        $root = realpath($directory);

        if ($root === false || !is_dir($root)) {
            throw new RuntimeException(sprintf('Upload destination "%s" is not a directory.', $directory));
        }

        if (!is_writable($root)) {
            throw new RuntimeException(sprintf('Upload destination "%s" is not writable.', $root));
        }

        // Refused rather than quietly reduced with basename(): silently
        // storing "../evil" as "evil" would hide that the caller passed
        // something it should not have, and the next such bug might not be
        // caught by a leading "..".
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new RuntimeException(sprintf(
                'The upload name "%s" must be a plain filename with no path separators.',
                $name,
            ));
        }

        if (str_starts_with($name, '.')) {
            throw new RuntimeException('An upload must be stored under a non-hidden filename.');
        }

        $destination = $root . DIRECTORY_SEPARATOR . $name;

        // Belt and braces: after resolving, the target must still be a direct
        // child of the directory we were given.
        if (dirname($destination) !== $root) {
            throw new RuntimeException('The upload name resolves outside the destination directory.');
        }

        // move_uploaded_file() additionally verifies the source really was an
        // upload for this request, which rename() cannot do. It only applies to
        // files PHP itself created.
        $moved = $this->managedByPhp
            ? move_uploaded_file($this->temporaryPath, $destination)
            : rename($this->temporaryPath, $destination);

        if (!$moved) {
            throw new RuntimeException(sprintf('Could not store the upload for "%s".', $this->field));
        }

        // Uploads are data, never programs.
        @chmod($destination, 0o640);

        $this->moved = true;

        return $destination;
    }

    /**
     * Deletes the temporary file if it is still there.
     *
     * Called by the kernel when the request scope closes. Safe to call more
     * than once, and a no-op once the file has been moved.
     */
    public function discard(): void
    {
        if ($this->moved || $this->discarded) {
            return;
        }

        $this->discarded = true;

        // PHP removes its own temp files at request end; deleting them early is
        // harmless and keeps behaviour identical across the four targets.
        if ($this->temporaryPath !== '' && is_file($this->temporaryPath)) {
            @unlink($this->temporaryPath);
        }
    }
}
