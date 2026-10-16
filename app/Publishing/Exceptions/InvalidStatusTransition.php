<?php

namespace App\Publishing\Exceptions;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use DomainException;

/**
 * A status change the enum state machine does not allow (including
 * same-to-same). Raised inside the domain actions on the freshly locked
 * status, so a stale page or double submit can never apply it.
 */
final class InvalidStatusTransition extends DomainException
{
    public function __construct(
        public readonly EventStatus|OccurrenceStatus $from,
        public readonly EventStatus|OccurrenceStatus $to,
    ) {
        parent::__construct("Can't change status from {$from->value} to {$to->value}");
    }
}
