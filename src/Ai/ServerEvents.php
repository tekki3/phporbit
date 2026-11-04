<?php

declare(strict_types=1);

namespace PhpOrbit\Ai;

use Generator;
use SensitiveParameter;

final class ServerEvents
{
    /**
     * @param iterable<string> $bytes
     * @return Generator<int, string>
     */
    public static function data(#[SensitiveParameter] iterable $bytes): Generator
    {
        $line = '';
        $data = [];
        $size = 0;
        $previousCr = false;
        foreach ($bytes as $chunk) {
            for ($i = 0, $length = strlen($chunk); $i < $length; $i++) {
                $char = $chunk[$i];
                if ($previousCr && $char === "\n") {
                    $previousCr = false;
                    continue;
                }
                $previousCr = $char === "\r";
                if ($char !== "\r" && $char !== "\n") {
                    $line .= $char;
                    if (++$size > 1048576) {
                        throw new AiFailed('AI stream event exceeded its size limit.');
                    }
                    continue;
                }
                if ($line === '') {
                    if ($data !== []) {
                        yield implode("\n", $data);
                    }
                    $data = [];
                    $size = 0;
                } elseif (str_starts_with($line, 'data:')) {
                    $value = substr($line, 5);
                    $data[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
                }
                $line = '';
            }
        }
        if ($line !== '' || $data !== []) {
            throw new AiFailed('AI stream ended inside an event.');
        }
    }
}
