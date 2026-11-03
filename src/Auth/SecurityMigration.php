<?php

declare(strict_types=1);

namespace PhpOrbit\Auth;

use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;

final class SecurityMigration implements Migration
{
    public function up(Connection $database): void
    {
        $id = $database->driver()->autoIncrementPrimaryKey();
        $text = $database->driver()->textType();
        $database->executeSchema("CREATE TABLE auth_security (
            id $id, user_key VARCHAR(64) NOT NULL UNIQUE,
            version VARCHAR(64) NOT NULL, totp_secret $text NULL, last_step BIGINT NOT NULL
        )");
        $database->executeSchema("CREATE TABLE auth_recovery_codes (
            id $id, user_key VARCHAR(64) NOT NULL, code_hash VARCHAR(64) NOT NULL UNIQUE
        )");
        $database->executeSchema('CREATE INDEX auth_recovery_user ON auth_recovery_codes (user_key)');
        $database->executeSchema("CREATE TABLE auth_email_tokens (
            id $id, token_hash VARCHAR(64) NOT NULL UNIQUE, user_identifier VARCHAR(191) NOT NULL,
            purpose VARCHAR(32) NOT NULL, credential_stamp VARCHAR(64) NOT NULL, expires_at BIGINT NOT NULL
        )");
        $database->executeSchema('CREATE INDEX auth_email_user ON auth_email_tokens (user_identifier)');
        $database->executeSchema('CREATE INDEX auth_email_expiry ON auth_email_tokens (expires_at)');
        $database->executeSchema("CREATE TABLE auth_external_accounts (
            id $id, external_key VARCHAR(64) NOT NULL UNIQUE, user_identifier VARCHAR(191) NOT NULL,
            provider VARCHAR(32) NOT NULL, issuer VARCHAR(191) NOT NULL, subject VARCHAR(191) NOT NULL
        )");
        $database->executeSchema('CREATE INDEX auth_external_user ON auth_external_accounts (user_identifier)');
    }

    public function down(Connection $database): void
    {
        foreach (['auth_external_accounts', 'auth_email_tokens', 'auth_recovery_codes', 'auth_security'] as $table) {
            $database->executeSchema('DROP TABLE ' . $table);
        }
    }
}
