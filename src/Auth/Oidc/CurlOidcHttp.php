<?php

declare(strict_types=1);

namespace PhpOrbit\Auth\Oidc;

use PhpOrbit\Auth\AuthenticationFailed;

final class CurlOidcHttp implements OidcHttp
{
    public function request(string $url, array $form = []): string
    {
        if (!extension_loaded('curl')) {
            throw new AuthenticationFailed('OAuth login requires ext-curl.');
        }
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new AuthenticationFailed('OAuth endpoints must use HTTPS.');
        }

        $handle = curl_init($url);
        $body = '';
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1048576) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($form !== []) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        }
        $success = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($success === false || $status !== 200) {
            // Never include response bodies: token endpoint errors can contain credentials.
            throw new AuthenticationFailed('The identity provider could not complete the request.');
        }

        return $body;
    }
}
