<?php

declare(strict_types=1);

namespace PhpOrbit\Ai;

use CurlHandle;
use Generator;
use JsonException;
use SensitiveParameter;

final class CurlTransport implements Transport
{
    /** @return Generator<int, string> */
    public function request(#[SensitiveParameter] Request $request, #[SensitiveParameter] array $headers, #[SensitiveParameter] array $body): iterable
    {
        if (!extension_loaded('curl')) {
            throw new AiFailed('AI HTTP requests require ext-curl.');
        }
        $curl = curl_init($request->endpoint);
        $multi = curl_multi_init();
        try {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiFailed('AI request could not be encoded as JSON.');
        }
        $buffer = '';
        $bytes = 0;
        $tooLarge = false;
        $lines = ['Content-Type: application/json', 'Accept: ' . ($request->streaming ? 'text/event-stream' : 'application/json')];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $request->timeoutSeconds),
            CURLOPT_TIMEOUT => $request->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$buffer, &$bytes, &$tooLarge): int {
                $bytes += strlen($chunk);
                if ($bytes > 16777216 || strlen($buffer) + strlen($chunk) > 1048576) {
                    $tooLarge = true;
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_multi_add_handle($multi, $curl);
        try {
            $running = 0;
            do {
                $code = curl_multi_exec($multi, $running);
                if ($code !== CURLM_OK || $tooLarge) {
                    throw new AiFailed($tooLarge ? 'AI response exceeded its size limit.' : 'AI transport failed.');
                }
                $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                if ($status >= 200 && $status !== 200) {
                    throw new AiFailed('AI provider returned HTTP ' . $status . '.');
                }
                if ($buffer !== '') {
                    $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
                    $expected = $request->streaming ? 'text/event-stream' : 'application/json';
                    if (!is_string($type) || strtolower(trim(explode(';', $type, 2)[0])) !== $expected) {
                        throw new AiFailed('AI provider returned an unexpected content type.');
                    }
                    $chunk = $buffer;
                    $buffer = '';
                    yield $chunk;
                }
                if ($running > 0 && curl_multi_select($multi, .1) === -1) {
                    usleep(1000);
                }
            } while ($running > 0);
            $info = curl_multi_info_read($multi);
            if ($info === false || $info['result'] !== CURLE_OK || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new AiFailed('AI request failed or timed out.');
            }
        } finally {
            curl_multi_remove_handle($multi, $curl);
            // Closing the multi handle releases sockets even after early cancellation.
            curl_multi_close($multi);
        }
    }
}
