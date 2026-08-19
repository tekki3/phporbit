<?php

declare(strict_types=1);

namespace PhpOrbit\Crypto;

use InvalidArgumentException;
use PhpOrbit\Config\Environment;
use PhpOrbit\Config\MissingConfiguration;

/**
 * Reads `APP_KEY` and the retired keys beside it.
 *
 * `required()` rather than `string()`: `APP_KEY=` is exactly as unusable as
 * omitting it, and a blank key that silently became a valid-looking all-zero
 * secret is the failure this guards against.
 */
final class CryptoFactory
{
    public static function keyFromEnvironment(Environment $config): Key
    {
        try {
            return Key::fromString($config->required('APP_KEY'));
        } catch (InvalidArgumentException $e) {
            throw MissingConfiguration::notOfType(
                'APP_KEY',
                'application key',
                'base64:… — run `orbit key:generate` (' . $e->getMessage() . ')',
            );
        }
    }

    /**
     * Keys retired by a rotation: still able to read old data, never used to
     * write new.
     *
     * @return list<Key>
     */
    public static function retiredKeysFromEnvironment(Environment $config): array
    {
        $keys = [];

        foreach ($config->strings('APP_PREVIOUS_KEYS') as $value) {
            try {
                $keys[] = Key::fromString($value);
            } catch (InvalidArgumentException $e) {
                throw MissingConfiguration::notOfType(
                    'APP_PREVIOUS_KEYS',
                    'list of application keys',
                    'comma-separated base64:… values (' . $e->getMessage() . ')',
                );
            }
        }

        return $keys;
    }

    public static function encrypterFromEnvironment(Environment $config): Encrypter
    {
        return new Encrypter(
            self::keyFromEnvironment($config),
            self::retiredKeysFromEnvironment($config),
        );
    }

    public static function signerFromEnvironment(Environment $config): Signer
    {
        return new Signer(
            self::keyFromEnvironment($config),
            self::retiredKeysFromEnvironment($config),
        );
    }
}
