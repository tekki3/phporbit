<?php

declare(strict_types=1);

namespace PhpOrbit\Mail;

/**
 * The outcome of the most recent delivery attempt, or Pending when delivery
 * has been deferred for an explicit CLI run.
 */
enum MailStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
    case Pending = 'pending';
}
