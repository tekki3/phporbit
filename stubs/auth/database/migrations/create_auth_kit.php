<?php

declare(strict_types=1);

use PhpOrbit\Auth\SecurityMigration;
use PhpOrbit\Api\ApiMigration;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;

return new class implements Migration {
    public function up(Connection $database): void
    {
        $id = $database->driver()->autoIncrementPrimaryKey();
        $database->executeSchema("CREATE TABLE auth_users (
            id $id, email VARCHAR(191) NOT NULL, email_key VARCHAR(64) NOT NULL UNIQUE,
            display_name VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL,
            is_admin INT NOT NULL, blocked INT NOT NULL
        )");
        $database->executeSchema("CREATE TABLE auth_registrations (
            id $id, email VARCHAR(191) NOT NULL, email_key VARCHAR(64) NOT NULL,
            display_name VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL,
            token_hash VARCHAR(64) NOT NULL UNIQUE, expires_at BIGINT NOT NULL
        )");
        $database->executeSchema('CREATE INDEX auth_registration_expiry ON auth_registrations (expires_at)');
        $database->executeSchema("CREATE TABLE auth_management (id $id, lock_version VARCHAR(64) NOT NULL)");
        $database->query('auth_management')->insert(['lock_version' => 'initial']);
        $database->executeSchema("CREATE TABLE auth_attempts (
            id $id, attempt_key VARCHAR(191) NOT NULL, attempted_at BIGINT NOT NULL
        )");
        $database->executeSchema('CREATE INDEX auth_attempts_lookup ON auth_attempts (attempt_key, attempted_at)');
        (new SecurityMigration())->up($database);
        (new ApiMigration())->up($database);
    }

    public function down(Connection $database): void
    {
        (new ApiMigration())->down($database);
        (new SecurityMigration())->down($database);
        foreach (['auth_attempts', 'auth_management', 'auth_registrations', 'auth_users'] as $table) {
            $database->executeSchema('DROP TABLE ' . $table);
        }
    }
};
