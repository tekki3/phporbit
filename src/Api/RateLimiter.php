<?php

declare(strict_types=1);

namespace PhpOrbit\Api;

use InvalidArgumentException;
use PhpOrbit\Database\Connection;
use RuntimeException;

final class RateLimiter
{
    public function __construct(private readonly Connection $database)
    {
    }

    public function consume(string $key, int $limit = 60, int $windowSeconds = 60): RateLimitResult
    {
        if ($key === '' || $limit < 1 || $windowSeconds < 1 || $windowSeconds > 86400) {
            throw new InvalidArgumentException('Rate limits require a key, a positive limit and a window of 1–86400 seconds.');
        }

        return $this->database->transaction(function () use ($key, $limit, $windowSeconds): RateLimitResult {
            // Lock before reading, including under engines with snapshot isolation.
            if ($this->database->query('api_rate_lock')->where('id', '=', 1)->update(['lock_version' => bin2hex(random_bytes(16))]) !== 1) {
                throw new RuntimeException('API rate-limit schema is incomplete. Run migrations.');
            }
            $now = time();
            $this->database->query('api_rate_windows')->where('resets_at', '<=', $now)->delete();
            $bucket = hash('sha256', $key);
            $query = $this->database->query('api_rate_windows')->where('bucket_key', '=', $bucket);
            $row = $query->first();
            if ($row === null) {
                $this->database->query('api_rate_windows')->insert([
                    'bucket_key' => $bucket, 'hits' => 1, 'resets_at' => $now + $windowSeconds,
                ]);
                return new RateLimitResult(true, $limit - 1, $now + $windowSeconds);
            }
            $hits = (int) $row['hits'];
            if ($hits >= $limit) {
                return new RateLimitResult(false, 0, (int) $row['resets_at']);
            }
            $query->update(['hits' => $hits + 1]);

            return new RateLimitResult(true, $limit - $hits - 1, (int) $row['resets_at']);
        });
    }
}
