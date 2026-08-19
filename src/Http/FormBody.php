<?php

declare(strict_types=1);

namespace PhpOrbit\Http;

/**
 * Decodes an `application/x-www-form-urlencoded` request body.
 *
 * Called by the SAPI adapters while they build the request, so the result is
 * computed once and the request object stays immutable — no lazy parsing, no
 * memoised state hiding inside a value object.
 *
 * `multipart/form-data` is not decoded. Supporting uploads means writing
 * attacker-controlled bytes to disk, which needs quotas, type checks and a
 * cleanup contract; until that exists it is better to return nothing than to
 * half-support it.
 */
final class FormBody
{
    /**
     * @return array<string, string>
     */
    public static function parse(Headers $headers, string $body): array
    {
        if ($body === '') {
            return [];
        }

        $contentType = $headers->first('Content-Type');

        if ($contentType === null) {
            return [];
        }

        // The header may carry parameters, e.g. "...; charset=utf-8".
        $mediaType = strtolower(trim(explode(';', $contentType)[0]));

        if ($mediaType !== 'application/x-www-form-urlencoded') {
            return [];
        }

        parse_str($body, $parsed);

        $fields = [];
        foreach ($parsed as $name => $value) {
            // Nested inputs such as `a[b]=1` decode to arrays. Sessions and
            // form handling here are string-typed, so nested structures are
            // dropped rather than flattened into something ambiguous.
            if (is_string($name) && is_string($value)) {
                $fields[$name] = $value;
            }
        }

        return $fields;
    }
}
