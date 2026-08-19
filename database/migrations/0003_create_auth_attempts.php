<?php

declare(strict_types=1);

use PhpOrbit\Auth\LoginThrottle;
use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;

return new class implements Migration {
    public function up(Connection $database): void
    {
        $database->executeSchema(sprintf(
            'CREATE TABLE %s (
                id %s,
                attempt_key VARCHAR(191) NOT NULL,
                attempted_at BIGINT NOT NULL
            )',
            LoginThrottle::TABLE,
            $database->driver()->autoIncrementPrimaryKey(),
        ));

        // Every throttle check filters on both columns; without this the table
        // is scanned on each login attempt, which is the opposite of what a
        // brute-force defence should do under load.
        $database->executeSchema(sprintf(
            'CREATE INDEX auth_attempts_lookup ON %s (attempt_key, attempted_at)',
            LoginThrottle::TABLE,
        ));
    }

    public function down(Connection $database): void
    {
        $database->executeSchema('DROP TABLE ' . LoginThrottle::TABLE);
    }
};
