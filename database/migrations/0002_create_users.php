<?php

declare(strict_types=1);

use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;

return new class implements Migration {
    public function up(Connection $database): void
    {
        $database->executeSchema(sprintf(
            'CREATE TABLE users (
                id %s,
                email VARCHAR(255) NOT NULL,
                password_hash %s NOT NULL,
                display_name %s NOT NULL,
                avatar_path %s NULL,
                created_at VARCHAR(40) NOT NULL
            )',
            $database->driver()->autoIncrementPrimaryKey(),
            $database->driver()->textType(),
            $database->driver()->textType(),
            $database->driver()->textType(),
        ));

        // Enforced by the database rather than only by application code: two
        // concurrent registrations would otherwise both pass a "does this
        // email exist" check and both insert.
        $database->executeSchema('CREATE UNIQUE INDEX users_email_unique ON users (email)');
    }

    public function down(Connection $database): void
    {
        $database->executeSchema('DROP TABLE users');
    }
};
