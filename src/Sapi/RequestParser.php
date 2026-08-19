<?php

declare(strict_types=1);

namespace PhpOrbit\Sapi;

use PhpOrbit\Http\Exception\MalformedRequest;
use PhpOrbit\Http\FormBody;
use PhpOrbit\Http\Headers;
use PhpOrbit\Http\Method;
use PhpOrbit\Http\ServerRequest;
use PhpOrbit\Http\Upload\MultipartParser;
use PhpOrbit\Http\Upload\ParsedBody;
use PhpOrbit\Http\Uri;

/**
 * Reads an HTTP/1.1 message off a socket.
 *
 * Every read is bounded. An HTTP parser with no limits is a memory-exhaustion
 * primitive: a client that opens a connection and streams header bytes forever
 * would otherwise consume the whole process. Exceeding a limit is treated as a
 * malformed request and closes the connection.
 */
final class RequestParser
{
    private const MAX_REQUEST_LINE = 8192;
    private const MAX_HEADER_COUNT = 100;
    private const MAX_HEADER_BYTES = 32768;

    public function __construct(
        private readonly int $maxBodyBytes = 8 * 1024 * 1024,
        private readonly MultipartParser $multipart = new MultipartParser(),
    ) {
    }

    /**
     * @param resource $connection
     * @return ServerRequest|null null when the peer closed the connection cleanly
     */
    public function parse($connection, string $scheme, string $host, int $port): ?ServerRequest
    {
        $requestLine = $this->readLine($connection, self::MAX_REQUEST_LINE);

        if ($requestLine === null) {
            return null;
        }

        // Tolerate leading blank lines, which some clients emit between
        // keep-alive requests.
        while ($requestLine === '') {
            $requestLine = $this->readLine($connection, self::MAX_REQUEST_LINE);

            if ($requestLine === null) {
                return null;
            }
        }

        $parts = explode(' ', $requestLine);
        if (count($parts) !== 3) {
            throw new MalformedRequest('Malformed request line.');
        }

        [$rawMethod, $target, $version] = $parts;

        if ($version !== 'HTTP/1.1' && $version !== 'HTTP/1.0') {
            throw new MalformedRequest(sprintf('Unsupported HTTP version "%s".', $version));
        }

        $headers = $this->readHeaders($connection);

        $requestHost = $headers->first('Host') ?? $host;
        $requestPort = $port;
        if (str_contains($requestHost, ':')) {
            [$requestHost, $rawPort] = explode(':', $requestHost, 2);
            $requestPort = (int) $rawPort;
        }

        $body = $this->readBody($connection, $headers);

        // A multipart body carries both fields and files, so it replaces the
        // urlencoded decoding rather than supplementing it.
        $parsed = MultipartParser::handles($headers)
            ? $this->multipart->parse($headers, $body)
            : new ParsedBody(FormBody::parse($headers, $body));

        return new ServerRequest(
            Method::parse($rawMethod),
            Uri::fromRequestTarget($target, $scheme, $requestHost, $requestPort),
            $headers,
            $body,
            $this->parseCookies($headers),
            form: $parsed->fields,
            files: $parsed->files,
        );
    }

    /**
     * @param resource $connection
     */
    private function readHeaders($connection): Headers
    {
        $headers = Headers::empty();
        $count = 0;
        $bytes = 0;

        while (true) {
            $line = $this->readLine($connection, self::MAX_REQUEST_LINE);

            if ($line === null) {
                throw new MalformedRequest('Connection closed inside the header block.');
            }

            if ($line === '') {
                return $headers;
            }

            $bytes += strlen($line);
            if (++$count > self::MAX_HEADER_COUNT || $bytes > self::MAX_HEADER_BYTES) {
                throw new MalformedRequest('Request header block is too large.');
            }

            $separator = strpos($line, ':');
            if ($separator === false || $separator === 0) {
                throw new MalformedRequest('Malformed header line.');
            }

            $headers = $headers->add(
                substr($line, 0, $separator),
                trim(substr($line, $separator + 1)),
            );
        }
    }

    /**
     * @param resource $connection
     */
    private function readBody($connection, Headers $headers): string
    {
        if ($headers->has('Transfer-Encoding')) {
            throw new MalformedRequest(
                'Chunked transfer encoding is not supported by the built-in server. '
                . 'Send a Content-Length instead, or deploy behind FrankenPHP, nginx or Apache.',
            );
        }

        $raw = $headers->first('Content-Length');
        if ($raw === null) {
            return '';
        }

        if (preg_match('/^\d+$/', $raw) !== 1) {
            throw new MalformedRequest('Content-Length must be a non-negative integer.');
        }

        $length = (int) $raw;

        if ($length > $this->maxBodyBytes) {
            throw new MalformedRequest(sprintf(
                'Request body of %d bytes exceeds the %d byte limit.',
                $length,
                $this->maxBodyBytes,
            ));
        }

        $body = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread($connection, min(8192, $remaining));

            if ($chunk === false || $chunk === '') {
                throw new MalformedRequest('Connection closed before the body was complete.');
            }

            $body .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $body;
    }

    /**
     * @return array<string, string>
     */
    private function parseCookies(Headers $headers): array
    {
        $cookies = [];

        foreach ($headers->all('Cookie') as $header) {
            foreach (explode(';', $header) as $pair) {
                $separator = strpos($pair, '=');

                if ($separator === false) {
                    continue;
                }

                $name = trim(substr($pair, 0, $separator));
                if ($name !== '') {
                    $cookies[$name] = urldecode(trim(substr($pair, $separator + 1)));
                }
            }
        }

        return $cookies;
    }

    /**
     * Reads one CRLF-terminated line, without the terminator.
     *
     * @param resource $connection
     * @return string|null null at end of stream
     */
    private function readLine($connection, int $limit): ?string
    {
        $line = stream_get_line($connection, $limit, "\r\n");

        if ($line === false) {
            return null;
        }

        if (strlen($line) >= $limit) {
            throw new MalformedRequest('Request line or header exceeds the permitted length.');
        }

        if ($line === '' && feof($connection)) {
            return null;
        }

        return rtrim($line, "\r");
    }
}
