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
                to_addresses TEXT NOT NULL,
                cc_addresses TEXT NOT NULL,
                bcc_addresses TEXT NOT NULL,
                from_address TEXT NULL,
                reply_to TEXT NULL,
                subject TEXT NOT NULL,
                text_body TEXT NULL,
                html_body TEXT NULL,
                attachments TEXT NOT NULL,
                headers TEXT NOT NULL,
                status VARCHAR(20) NOT NULL,
                error TEXT NULL,
                attempts INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            $database->driver()->autoIncrementPrimaryKey(),
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
