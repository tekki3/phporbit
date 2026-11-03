<?php

declare(strict_types=1);

namespace PhpOrbit\Api;

use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;

final class ApiMigration implements Migration
{
    public function up(Connection $database): void
    {
        $text = $database->driver()->textType();
        $database->executeSchema("CREATE TABLE api_tokens (
            token_id VARCHAR(64) PRIMARY KEY, token_hash VARCHAR(64) NOT NULL UNIQUE,
            user_key VARCHAR(64) NOT NULL, token_kind VARCHAR(16) NOT NULL, token_name VARCHAR(100) NOT NULL,
            scopes $text NOT NULL, security_stamp VARCHAR(64) NOT NULL,
            created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL
        )");
        $database->executeSchema('CREATE INDEX api_tokens_user ON api_tokens (user_key)');
        $database->executeSchema('CREATE INDEX api_tokens_expiry ON api_tokens (expires_at)');
        $database->executeSchema('CREATE TABLE api_rate_lock (id INT PRIMARY KEY, lock_version VARCHAR(64) NOT NULL)');
        $database->query('api_rate_lock')->insert(['id' => 1, 'lock_version' => 'initial']);
        $database->executeSchema('CREATE TABLE api_rate_windows (
            bucket_key VARCHAR(64) PRIMARY KEY, hits INT NOT NULL, resets_at BIGINT NOT NULL
        )');
        $database->executeSchema('CREATE INDEX api_rate_expiry ON api_rate_windows (resets_at)');
    }

    public function down(Connection $database): void
    {
        foreach (['api_rate_windows', 'api_rate_lock', 'api_tokens'] as $table) {
            $database->executeSchema('DROP TABLE ' . $table);
        }
    }
}
