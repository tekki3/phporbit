<?php

declare(strict_types=1);

namespace PhpOrbit\Tests\Unit\Http\Upload;

use PhpOrbit\Http\Exception\MalformedRequest;
use PhpOrbit\Http\Headers;
use PhpOrbit\Http\Upload\MultipartParser;
use PhpOrbit\Http\Upload\UploadError;
use PhpOrbit\Http\Upload\UploadQuotas;
use PHPUnit\Framework\TestCase;

final class MultipartParserTest extends TestCase
{
    private const BOUNDARY = '----orbittest';

    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/orbit-uploads-' . bin2hex(random_bytes(6));

        mkdir($this->temporary, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->temporary);
    }

    public function test_it_decodes_fields_and_files_together(): void
    {
        $parsed = $this->parse([
            $this->field('title', 'My photo'),
            $this->file('photo', 'holiday.png', 'image/png', 'PNGDATA'),
        ]);

        self::assertSame(['title' => 'My photo'], $parsed->fields);

        $file = $parsed->files['photo'] ?? null;

        self::assertNotNull($file);
        self::assertTrue($file->isValid());
        self::assertSame('holiday.png', $file->clientFilename);
        self::assertSame('image/png', $file->clientMediaType);
        self::assertSame(7, $file->size);
        self::assertSame('PNGDATA', $file->contents());
    }

    public function test_binary_content_survives_intact(): void
    {
        $binary = "\x00\x01\x02\xff\r\n\x89PNG";

        $parsed = $this->parse([$this->file('blob', 'b.bin', 'application/octet-stream', $binary)]);

        self::assertSame($binary, $parsed->files['blob']->contents());
    }

    public function test_an_empty_file_input_reports_no_file(): void
    {
        $parsed = $this->parse([$this->file('photo', '', null, '')]);

        self::assertSame(UploadError::NoFile, $parsed->files['photo']->error);
        self::assertFalse($parsed->files['photo']->isValid());
    }

    /**
     * A file over the limit is ordinary user error, not an exception — the
     * handler should be able to show a message.
     */
    public function test_a_file_over_the_size_quota_is_flagged_not_thrown(): void
    {
        $parser = new MultipartParser(
            new UploadQuotas(maxFileBytes: 4, maxTotalBytes: 100),
            $this->temporary,
        );

        $parsed = $parser->parse(
            $this->headers(),
            $this->body([$this->file('photo', 'big.png', 'image/png', 'far too long')]),
        );

        self::assertSame(UploadError::TooLarge, $parsed->files['photo']->error);
    }

    public function test_too_many_files_are_flagged(): void
    {
        $parser = new MultipartParser(new UploadQuotas(maxFiles: 1), $this->temporary);

        $parsed = $parser->parse($this->headers(), $this->body([
            $this->file('a', 'a.txt', 'text/plain', 'one'),
            $this->file('b', 'b.txt', 'text/plain', 'two'),
        ]));

        self::assertSame(UploadError::None, $parsed->files['a']->error);
        self::assertSame(UploadError::TooMany, $parsed->files['b']->error);
    }

    /**
     * Exceeding the aggregate budget is abuse rather than a mistake, so it
     * ends the request.
     */
    public function test_exceeding_the_total_budget_is_rejected(): void
    {
        $parser = new MultipartParser(
            new UploadQuotas(maxFileBytes: 10, maxTotalBytes: 12, maxFiles: 10),
            $this->temporary,
        );

        $this->expectException(MalformedRequest::class);
        $this->expectExceptionMessageMatches('/total/');

        $parser->parse($this->headers(), $this->body([
            $this->file('a', 'a.txt', 'text/plain', '0123456789'),
            $this->file('b', 'b.txt', 'text/plain', '0123456789'),
        ]));
    }

    public function test_an_oversized_text_field_is_rejected(): void
    {
        $parser = new MultipartParser(new UploadQuotas(maxFieldBytes: 4), $this->temporary);

        $this->expectException(MalformedRequest::class);

        $parser->parse($this->headers(), $this->body([$this->field('title', 'far too long')]));
    }

    public function test_too_many_parts_are_rejected(): void
    {
        $parser = new MultipartParser(new UploadQuotas(maxParts: 2), $this->temporary);

        $this->expectException(MalformedRequest::class);
        $this->expectExceptionMessageMatches('/parts/');

        $parser->parse($this->headers(), $this->body([
            $this->field('a', '1'),
            $this->field('b', '2'),
            $this->field('c', '3'),
        ]));
    }

    public function test_a_missing_boundary_is_rejected(): void
    {
        $this->expectException(MalformedRequest::class);
        $this->expectExceptionMessageMatches('/boundary/');

        (new MultipartParser(temporaryDirectory: $this->temporary))->parse(
            Headers::fromArray(['Content-Type' => 'multipart/form-data']),
            'whatever',
        );
    }

    /**
     * The client's filename is never a path. A traversal attempt must not
     * survive into anything used on disk.
     */
    public function test_a_traversal_filename_is_reduced_to_a_safe_name(): void
    {
        $parsed = $this->parse([
            $this->file('photo', '../../../etc/passwd', 'text/plain', 'x'),
        ]);

        $file = $parsed->files['photo'] ?? null;

        self::assertNotNull($file);
        self::assertSame('../../../etc/passwd', $file->clientFilename, 'the raw value is preserved as-is');
        self::assertSame('passwd', $file->safeName(), 'but the safe form has no path');
    }

    public function test_handles_recognises_multipart_content_types(): void
    {
        self::assertTrue(MultipartParser::handles($this->headers()));
        self::assertFalse(MultipartParser::handles(
            Headers::fromArray(['Content-Type' => 'application/x-www-form-urlencoded']),
        ));
        self::assertFalse(MultipartParser::handles(Headers::empty()));
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @param list<string> $parts
     */
    private function parse(array $parts): \PhpOrbit\Http\Upload\ParsedBody
    {
        return (new MultipartParser(temporaryDirectory: $this->temporary))
            ->parse($this->headers(), $this->body($parts));
    }

    private function headers(): Headers
    {
        return Headers::fromArray([
            'Content-Type' => 'multipart/form-data; boundary=' . self::BOUNDARY,
        ]);
    }

    /**
     * @param list<string> $parts
     */
    private function body(array $parts): string
    {
        $body = '';

        foreach ($parts as $part) {
            $body .= '--' . self::BOUNDARY . "\r\n" . $part . "\r\n";
        }

        return $body . '--' . self::BOUNDARY . "--\r\n";
    }

    private function field(string $name, string $value): string
    {
        return sprintf("Content-Disposition: form-data; name=\"%s\"\r\n\r\n%s", $name, $value);
    }

    private function file(string $name, string $filename, ?string $type, string $contents): string
    {
        $headers = sprintf('Content-Disposition: form-data; name="%s"; filename="%s"', $name, $filename);

        if ($type !== null) {
            $headers .= "\r\nContent-Type: " . $type;
        }

        return $headers . "\r\n\r\n" . $contents;
    }
}
