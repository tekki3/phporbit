<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

use InvalidArgumentException;
use SensitiveParameter;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(): string
    {
        $result = '';
        $bits = '';

        foreach (str_split(random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        foreach (str_split($bits, 5) as $chunk) {
            $index = 0;
            foreach (str_split($chunk) as $bit) {
                $index = ($index << 1) | ($bit === '1' ? 1 : 0);
            }
            $result .= substr(self::ALPHABET, $index, 1);
        }

        return $result;
    }

    public static function code(#[SensitiveParameter] string $secret, int $timestamp): string
    {
        if ($timestamp < 0) {
            throw new InvalidArgumentException('TOTP timestamps must not be negative.');
        }

        $step = intdiv($timestamp, 30);
        $hash = hash_hmac('sha1', pack('N2', intdiv($step, 4294967296), $step % 4294967296), self::decode($secret), true);
        $offset = ord($hash[19]) & 15;
        $number = ((ord($hash[$offset]) & 127) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Returns the accepted time step, for atomic replay protection. */
    public static function match(#[SensitiveParameter] string $secret, string $code, int $timestamp): ?int
    {
        if (preg_match('/^\d{6}$/D', $code) !== 1) {
            return null;
        }

        foreach ([0, -1, 1] as $offset) {
            $time = $timestamp + $offset * 30;

            if ($time >= 0 && hash_equals(self::code($secret, $time), $code)) {
                return intdiv($time, 30);
            }
        }

        return null;
    }

    public static function uri(#[SensitiveParameter] string $secret, string $account, string $issuer = 'phporbit'): string
    {
        self::decode($secret);

        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?' . http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private static function decode(#[SensitiveParameter] string $secret): string
    {
        if (preg_match('/^[A-Z2-7]{32}$/D', $secret) !== 1) {
            throw new InvalidArgumentException('A TOTP secret must contain 32 base32 characters.');
        }

        $bits = '';
        foreach (str_split($secret) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new InvalidArgumentException('Invalid base32 secret.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $raw = '';
        foreach (str_split($bits, 8) as $chunk) {
            $byte = 0;
            foreach (str_split($chunk) as $bit) {
                $byte = ($byte << 1) | ($bit === '1' ? 1 : 0);
            }
            $raw .= chr($byte & 255);
        }

        return $raw;
    }
}
