<?php

declare(strict_types=1);

use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;

return new class implements Migration {
    public function up(Connection $database): void
    {
        // The primary key is the one part of this schema the three engines
        // spell differently; TEXT and the rest are accepted by all of them.
        $database->executeSchema(sprintf(
            'CREATE TABLE notes (
                id %s,
                title TEXT NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
            $database->driver()->autoIncrementPrimaryKey(),
        ));
    }

    public function down(Connection $database): void
    {
        $database->executeSchema('DROP TABLE notes');
    }
};
