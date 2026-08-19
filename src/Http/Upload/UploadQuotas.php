<?php

declare(strict_types=1);

namespace PhpOrbit\Http\Upload;

use InvalidArgumentException;

/**
 * Limits applied while decoding a multipart body.
 *
 * An upload endpoint without quotas is a denial-of-service primitive: anyone
 * can post a body large enough to exhaust disk or memory, and repeat it. These
 * are therefore required to parse at all rather than optional hardening, and
 * the defaults are small enough to be safe on a machine nobody has tuned.
 */
final class UploadQuotas
{
    public function __construct(
        public readonly int $maxFileBytes = 2 * 1024 * 1024,
        public readonly int $maxTotalBytes = 8 * 1024 * 1024,
        public readonly int $maxFiles = 5,
        public readonly int $maxFieldBytes = 64 * 1024,
        public readonly int $maxParts = 50,
    ) {
        foreach (
            [
                'maxFileBytes' => $maxFileBytes,
                'maxTotalBytes' => $maxTotalBytes,
                'maxFiles' => $maxFiles,
                'maxFieldBytes' => $maxFieldBytes,
                'maxParts' => $maxParts,
            ] as $name => $value
        ) {
            if ($value < 1) {
                throw new InvalidArgumentException(sprintf('%s must be at least 1.', $name));
            }
        }

        if ($maxFileBytes > $maxTotalBytes) {
            throw new InvalidArgumentException(
                'maxFileBytes cannot exceed maxTotalBytes; a single file would never be accepted.',
            );
        }
    }

    /**
     * Roomier limits for endpoints that genuinely take large files.
     */
    public static function permissive(): self
    {
        return new self(
            maxFileBytes: 32 * 1024 * 1024,
            maxTotalBytes: 64 * 1024 * 1024,
            maxFiles: 20,
        );
    }
}
