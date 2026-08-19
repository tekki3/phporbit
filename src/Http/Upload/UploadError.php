<?php

declare(strict_types=1);

namespace PhpOrbit\Http\Upload;

/**
 * Why an upload is unusable.
 *
 * A rejected upload is not an exception: a user picking a file that is too
 * large is ordinary form input, and the handler should be able to show them a
 * message rather than catch a throwable.
 */
enum UploadError: string
{
    case None = 'none';
    case TooLarge = 'too_large';
    case Partial = 'partial';
    case NoFile = 'no_file';
    case CannotWrite = 'cannot_write';
    case TooMany = 'too_many';

    public function message(): string
    {
        return match ($this) {
            self::None => 'The file uploaded successfully.',
            self::TooLarge => 'The file is larger than this endpoint accepts.',
            self::Partial => 'The upload was interrupted before it finished.',
            self::NoFile => 'No file was selected.',
            self::CannotWrite => 'The server could not store the upload.',
            self::TooMany => 'Too many files were sent at once.',
        };
    }

    /**
     * Maps PHP's own `$_FILES` error codes, which arrive under FPM and Apache.
     */
    public static function fromPhpCode(int $code): self
    {
        return match ($code) {
            UPLOAD_ERR_OK => self::None,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => self::TooLarge,
            UPLOAD_ERR_PARTIAL => self::Partial,
            UPLOAD_ERR_NO_FILE => self::NoFile,
            default => self::CannotWrite,
        };
    }
}
