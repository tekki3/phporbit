<?php

declare(strict_types=1);

namespace PhpOrbit\Ai;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

final class Request
{
    public function __construct(
        public readonly Secret $key,
        public readonly string $model,
        #[SensitiveParameter] public readonly string $prompt,
        #[SensitiveParameter] public readonly string $system,
        public readonly string $endpoint,
        public readonly int $maxTokens = 1024,
        public readonly int $timeoutSeconds = 120,
        public readonly bool $streaming = false,
    ) {
        if (preg_match('/[\x00-\x20\x7f]/', $endpoint) === 1) {
            throw new InvalidArgumentException('AI endpoints must not contain whitespace or control characters.');
        }
        $url = parse_url($endpoint);
        if (trim($model) === '' || strlen($model) > 256
            || preg_match('/[\x00-\x1f\x7f]/', $model) === 1
            || preg_match('//u', $model) !== 1 || preg_match('//u', $prompt) !== 1 || preg_match('//u', $system) !== 1
            || trim($prompt) === '' || strlen($prompt) + strlen($system) > 1048576
            || $maxTokens < 1 || $maxTokens > 1000000 || $timeoutSeconds < 1 || $timeoutSeconds > 600) {
            throw new InvalidArgumentException('AI requests need UTF-8 text, a nonblank model up to 256 bytes, a nonblank prompt up to 1 MiB, token limit of 1-1000000 and timeout of 1-600 seconds.');
        }
        if ($url === false || !isset($url['host']) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['scheme'] ?? '', ['http', 'https'], true)) {
            throw new InvalidArgumentException('Use an absolute AI endpoint without credentials, query or fragment.');
        }
        if ($url['scheme'] !== 'https' && !in_array($url['host'], ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw new InvalidArgumentException('AI endpoints require HTTPS; plain HTTP is only allowed for literal loopback hosts.');
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['model' => $this->model, 'request' => '[redacted]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('AI requests cannot be serialized.');
    }
}
