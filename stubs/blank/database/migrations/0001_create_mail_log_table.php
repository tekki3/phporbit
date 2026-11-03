<?php

declare(strict_types=1);

use PhpOrbit\Database\Connection;
use PhpOrbit\Database\Migration;

return new class implements Migration {
    public function up(Connection $database): void
    {
        $database->executeSchema(sprintf(
            'CREATE TABLE mail_log (
                id %s,
                to_addresses %s NOT NULL,
                cc_addresses %s NOT NULL,
                bcc_addresses %s NOT NULL,
                from_address %s NULL,
                reply_to %s NULL,
                subject %s NOT NULL,
                text_body %s NULL,
                html_body %s NULL,
                attachments %s NOT NULL,
                headers %s NOT NULL,
                status VARCHAR(20) NOT NULL,
                error %s NULL,
                attempts INTEGER NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL
            )',
            $database->driver()->autoIncrementPrimaryKey(),
            $database->driver()->textType(),
            $database->driver()->textType(),
            $database->driver()->textType(),
            $database->driver()->textType(),
            $database->driver()->textType(),
            $database->driver()->textType(),
            $database->driver()->textType(),
            $database->driver()->textType(),
            $database->driver()->textType(),
            $database->driver()->textType(),
            $database->driver()->textType(),
        ));

        // mail:list and a bulk `mail:resend --failed` both filter on this —
        // VARCHAR rather than TEXT because MySQL cannot index TEXT without a
        // prefix length.
        $database->executeSchema('CREATE INDEX mail_log_status ON mail_log (status)');
    }

    public function down(Connection $database): void
    {
        $database->executeSchema('DROP TABLE mail_log');
    }
};
