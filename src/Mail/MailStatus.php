<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

/**
 * The outcome of the most recent delivery attempt for a logged message.
 *
 * Two cases, not three: sending is synchronous — see {@see PersistingMailer} —
 * so by the time a row exists at all, the attempt has already resolved one way
 * or the other. There is no "pending" to represent.
 */
enum MailStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
}
