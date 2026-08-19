<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

use InvalidArgumentException;
use RuntimeException;

/**
 * A file travelling with a message.
 *
 * The filename is attacker-influenced whenever it came from a user, so it is
 * reduced to something safe here rather than trusted: a recipient's mail client
 * writes it to disk, and a name containing a path separator or CR/LF is how
 * that becomes someone else's problem.
 */
final class Attachment
{
    private function __construct(
        public readonly string $filename,
        public readonly string $contents,
        public readonly string $mediaType,
        public readonly bool $inline = false,
    ) {
    }

    public static function fromString(
        string $filename,
        string $contents,
        string $mediaType = 'application/octet-stream',
    ): self {
        return new self(self::safeName($filename), $contents, self::checkType($mediaType));
    }

    public static function fromPath(string $path, ?string $filename = null, ?string $mediaType = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Cannot attach "%s": no such readable file.', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Cannot read "%s".', $path));
        }

        return new self(
            self::safeName($filename ?? basename($path)),
            $contents,
            self::checkType($mediaType ?? self::detectType($path)),
        );
    }

    /**
     * Strips directories and anything that could break out of the header.
     */
    private static function safeName(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $name = trim($name, '.-');

        if ($name === '') {
            throw new InvalidArgumentException('An attachment needs a usable filename.');
        }

        return substr($name, 0, 120);
    }

    private static function checkType(string $mediaType): string
    {
        if (preg_match('#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+-]+$#i', $mediaType) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid media type.', $mediaType));
        }

        return $mediaType;
    }

    /**
     * Sniffs the real bytes; the extension is not consulted.
     */
    private static function detectType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        try {
            $type = finfo_file($finfo, $path);

            return $type === false ? 'application/octet-stream' : $type;
        } finally {
            finfo_close($finfo);
        }
    }
}
