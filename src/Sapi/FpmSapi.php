<?php

declare(strict_types=1);

namespace PhpOrbit\Sapi;

use PhpOrbit\Http\FormBody;
use PhpOrbit\Http\Headers;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Upload\MultipartParser;
use PhpOrbit\Http\Upload\UploadedFile;
use PhpOrbit\Http\Upload\UploadError;
use PhpOrbit\Http\Uri;
use PhpOrbit\Kernel\Application;

/**
 * nginx + PHP-FPM, and Apache under either mod_php or FPM.
 *
 * The process serves one request and dies, so nothing here needs to worry
 * about cleanup. This is also the only file that reads superglobals: they are
 * consumed once, converted into a request object, and never touched again.
 */
final class FpmSapi implements Sapi
{
    /**
     * @param list<string> $trustedProxies CIDR-less IPs whose forwarding headers are believed
     */
    public function __construct(
        private readonly Emitter $emitter = new Emitter(),
        private readonly array $trustedProxies = [],
    ) {
    }

    public function run(Application $app): void
    {
        $this->emitter->emit($app->handle($this->captureRequest()));
    }

    public function captureRequest(): ServerRequest
    {
        /** @var array<string, string> $server */
        $server = array_filter($_SERVER, is_string(...));

        $headers = $this->headersFromServer($server);
        $scheme = $this->scheme($server, $headers);
        [$host, $port] = $this->hostAndPort($server, $headers, $scheme);

        $body = file_get_contents('php://input');

        /** @var array<string, string> $cookies */
        $cookies = array_filter($_COOKIE, is_string(...));

        $rawBody = $body === false ? '' : $body;

        // PHP consumes a multipart body itself, so php://input is empty and
        // the decoded fields arrive in $_POST rather than needing FormBody.
        $isMultipart = MultipartParser::handles($headers);

        /** @var array<string, string> $posted */
        $posted = $isMultipart ? array_filter($_POST, is_string(...)) : [];

        return new ServerRequest(
            Method::parse($server['REQUEST_METHOD'] ?? 'GET'),
            Uri::fromRequestTarget($server['REQUEST_URI'] ?? '/', $scheme, $host, $port),
            $headers,
            $rawBody,
            $cookies,
            form: $isMultipart ? $posted : FormBody::parse($headers, $rawBody),
            files: $this->uploadsFromSapi(),
        );
    }

    /**
     * Adapts `$_FILES` into the same objects the built-in server produces.
     *
     * PHP has already written each upload to a temporary file and validated
     * that it belongs to this request, so these are flagged as SAPI-managed:
     * moving one must go through `move_uploaded_file()`, which re-checks that
     * provenance and is the difference between storing an upload and storing
     * whatever path an attacker managed to get into the variable.
     *
     * Array-style inputs (`name="photos[]"`) are not adapted. Handling them
     * properly means reshaping PHP's transposed arrays, and silently taking
     * only the first file would lose the rest without saying so.
     *
     * @return array<string, UploadedFile>
     */
    private function uploadsFromSapi(): array
    {
        $uploads = [];

        foreach ($_FILES as $field => $file) {
            if (!is_string($field) || !is_array($file)) {
                continue;
            }

            $temporaryPath = $file['tmp_name'] ?? null;
            $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

            if (is_array($temporaryPath) || is_array($error)) {
                continue;
            }

            $code = UploadError::fromPhpCode(is_int($error) ? $error : UPLOAD_ERR_NO_FILE);
            $clientFilename = is_string($file['name'] ?? null) ? $file['name'] : '';

            if ($code !== UploadError::None || !is_string($temporaryPath) || $temporaryPath === '') {
                $uploads[$field] = UploadedFile::failed($field, $code, $clientFilename);

                continue;
            }

            $uploads[$field] = new UploadedFile(
                $field,
                $clientFilename,
                is_string($file['type'] ?? null) ? $file['type'] : null,
                is_int($file['size'] ?? null) ? $file['size'] : 0,
                UploadError::None,
                $temporaryPath,
                managedByPhp: true,
            );
        }

        return $uploads;
    }

    /**
     * @param array<string, string> $server
     */
    private function headersFromServer(array $server): Headers
    {
        $headers = Headers::empty();

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                // These arrive without the HTTP_ prefix by CGI convention.
                $name = str_replace('_', '-', $key);
            } else {
                continue;
            }

            $headers = $headers->with($name, $value);
        }

        return $headers;
    }

    /**
     * @param array<string, string> $server
     */
    private function scheme(array $server, Headers $headers): string
    {
        $https = $server['HTTPS'] ?? '';
        if ($https !== '' && strtolower($https) !== 'off') {
            return 'https';
        }

        // X-Forwarded-Proto is client-controlled unless a trusted proxy set it.
        // Believing it unconditionally would let anyone claim their plaintext
        // request was HTTPS and unlock Secure-only cookies.
        if ($this->behindTrustedProxy($server)) {
            $forwarded = $headers->first('X-Forwarded-Proto');
            if ($forwarded !== null && strtolower($forwarded) === 'https') {
                return 'https';
            }
        }

        return 'http';
    }

    /**
     * @param array<string, string> $server
     * @return array{0: string, 1: int}
     */
    private function hostAndPort(array $server, Headers $headers, string $scheme): array
    {
        $default = $scheme === 'https' ? 443 : 80;

        // SERVER_NAME comes from the web server's own configuration; the Host
        // header comes from the client. Prefer the former so a forged Host
        // cannot poison generated URLs or password-reset links.
        $host = $server['SERVER_NAME'] ?? $headers->first('Host') ?? 'localhost';
        $port = isset($server['SERVER_PORT']) ? (int) $server['SERVER_PORT'] : $default;

        if (str_contains($host, ':')) {
            [$host, $rawPort] = explode(':', $host, 2);
            $port = (int) $rawPort;
        }

        return [$host, $port > 0 ? $port : $default];
    }

    /**
     * @param array<string, string> $server
     */
    private function behindTrustedProxy(array $server): bool
    {
        $remote = $server['REMOTE_ADDR'] ?? '';

        return $remote !== '' && in_array($remote, $this->trustedProxies, true);
    }
}
