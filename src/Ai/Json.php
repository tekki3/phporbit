<?php

declare(strict_types=1);

namespace PhpOrbit\Ai;

use JsonException;
use SensitiveParameter;

final class Json
{
    /** @return array<string, mixed> */
    public static function object(#[SensitiveParameter] string $json): array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiFailed('AI provider returned malformed JSON.');
        }
        if (!is_array($data) || array_is_list($data) || isset($data['error'])) {
            throw new AiFailed('AI provider returned an invalid response or an error.');
        }

        $object = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new AiFailed('AI provider returned invalid object keys.');
            }
            $object[$key] = $value;
        }
        return $object;
    }
}
