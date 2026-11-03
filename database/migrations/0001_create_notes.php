<?php

declare(strict_types=1);

use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;

return new class implements Migration {
    public function up(Connection $database): void
    {
        // The primary key and unbounded text types are engine-specific.
        $database->executeSchema(sprintf(
            'CREATE TABLE notes (
                id %s,
                title %s NOT NULL,
                body %s NOT NULL,
                created_at VARCHAR(40) NOT NULL
            )',
            $database->driver()->autoIncrementPrimaryKey(),
            $database->driver()->textType(),
            $database->driver()->textType(),
        ));
    }

    public function down(Connection $database): void
    {
        $database->executeSchema('DROP TABLE notes');
    }
};
