<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Http\Upload;

use PhpOrbit\Http\Upload\UploadedFile;
use PhpOrbit\Http\Upload\UploadError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UploadedFileTest extends TestCase
{
    private string $temporary;

    private string $destination;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/orbit-file-' . bin2hex(random_bytes(6));

        $this->temporary = $base . '/tmp';
        $this->destination = $base . '/store';

        mkdir($this->temporary, 0o700, true);
        mkdir($this->destination, 0o750, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->temporary, $this->destination] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }

        @rmdir(dirname($this->temporary));
    }

    public function test_a_valid_upload_reports_its_contents(): void
    {
        $file = $this->upload('hello world');

        self::assertTrue($file->isValid());
        self::assertSame('hello world', $file->contents());
    }

    /**
     * The single most important behaviour here: what the file *is* wins over
     * what it claims to be.
     */
    public function test_the_detected_type_comes_from_the_bytes_not_the_name(): void
    {
        $file = $this->upload("<?php echo 'pwned';", 'avatar.png', 'image/png');

        self::assertSame('image/png', $file->clientMediaType, 'the browser claimed PNG');
        self::assertNotSame('image/png', $file->detectedType(), 'the bytes say otherwise');
        self::assertFalse($file->hasTypeIn(['image/png', 'image/jpeg']));
    }

    public function test_a_real_png_is_recognised(): void
    {
        // A complete 1x1 PNG: the signature alone is not enough for libmagic,
        // which wants a plausible IHDR chunk behind it.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        self::assertIsString($png);

        $file = $this->upload($png, 'x.png', 'image/png');

        self::assertSame('image/png', $file->detectedType());
        self::assertTrue($file->hasTypeIn(['image/png']));
        self::assertSame('png', $file->extensionFromContents(['image/png' => 'png']));
    }

    #[DataProvider('hostileNames')]
    public function test_safe_name_strips_paths_and_dangerous_characters(string $client, string $expected): void
    {
        self::assertSame($expected, $this->upload('x', $client)->safeName());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostileNames(): iterable
    {
        yield 'unix traversal' => ['../../etc/passwd', 'passwd'];
        yield 'windows separator' => ['..\\..\\windows\\system32\\cmd.exe', 'cmd.exe'];
        yield 'absolute path' => ['/etc/shadow', 'shadow'];
        yield 'leading dot' => ['.htaccess', 'htaccess'];
        yield 'nul byte' => ["shell.php\0.png", 'shell.php.png'];
        yield 'spaces and quotes' => ['my "photo" (1).png', 'my-photo-1-.png'];
        yield 'empty' => ['', 'upload'];
        yield 'only dots' => ['...', 'upload'];
    }

    public function test_move_to_stores_the_file(): void
    {
        $file = $this->upload('contents');

        $path = $file->moveTo($this->destination, 'stored.txt');

        self::assertFileExists($path);
        self::assertSame('contents', file_get_contents($path));
        self::assertTrue($file->wasMoved());
    }

    public function test_a_moved_file_cannot_be_moved_or_read_again(): void
    {
        $file = $this->upload('contents');
        $file->moveTo($this->destination, 'stored.txt');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been moved/');

        $file->contents();
    }

    /**
     * Even if an unsanitised name reached moveTo(), it must not place the file
     * outside the directory it was given.
     */
    /**
     * Refused outright rather than quietly reduced to "escaped.txt": silently
     * fixing the caller's mistake hides it.
     */
    #[DataProvider('escapingNames')]
    public function test_move_to_refuses_a_name_containing_a_separator(string $name): void
    {
        $file = $this->upload('contents');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/path separators/');

        $file->moveTo($this->destination, $name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapingNames(): iterable
    {
        yield 'parent' => ['../escaped.txt'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'nested' => ['sub/dir/file.txt'];
        yield 'backslash' => ['..\\escaped.txt'];
        yield 'nul' => ["file\0.txt"];
    }

    public function test_move_to_refuses_a_hidden_filename(): void
    {
        $file = $this->upload('contents');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-hidden/');

        $file->moveTo($this->destination, '.htaccess');
    }

    public function test_stored_files_are_not_world_readable(): void
    {
        $path = $this->upload('contents')->moveTo($this->destination, 'stored.txt');

        $permissions = fileperms($path);

        self::assertNotFalse($permissions);
        self::assertSame(0, $permissions & 0o007, 'other must have no access');
    }

    public function test_discard_removes_the_temporary_file(): void
    {
        $file = $this->upload('contents');

        self::assertTrue($file->isValid());

        $file->discard();

        self::assertFalse($file->isValid());
    }

    public function test_discard_is_idempotent_and_leaves_moved_files_alone(): void
    {
        $file = $this->upload('contents');
        $path = $file->moveTo($this->destination, 'stored.txt');

        $file->discard();
        $file->discard();

        self::assertFileExists($path, 'a moved file belongs to the application now');
    }

    public function test_a_failed_upload_exposes_its_reason(): void
    {
        $file = UploadedFile::failed('photo', UploadError::TooLarge, 'big.png');

        self::assertFalse($file->isValid());
        self::assertSame(UploadError::TooLarge, $file->error);
        self::assertNull($file->detectedType());

        $this->expectException(RuntimeException::class);

        $file->contents();
    }

    private function upload(string $contents, string $clientName = 'file.txt', ?string $type = null): UploadedFile
    {
        $path = $this->temporary . '/' . bin2hex(random_bytes(6));

        file_put_contents($path, $contents);

        return new UploadedFile(
            'field',
            $clientName,
            $type,
            strlen($contents),
            UploadError::None,
            $path,
        );
    }
}
