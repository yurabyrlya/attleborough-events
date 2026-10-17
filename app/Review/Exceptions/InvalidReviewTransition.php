<?php

namespace App\Review\Exceptions;

use App\Enums\ReviewStatus;
use DomainException;

/**
 * A review move the ReviewStatus state machine does not allow (including
 * same-to-same). Raised inside the review actions on the freshly locked
 * draft, so a stale page or double submit can never apply it.
 */
final class InvalidReviewTransition extends DomainException
{
    public function __construct(
        public readonly ReviewStatus $from,
        public readonly ReviewStatus $to,
    ) {
        parent::__construct("This draft was already reviewed ({$from->value} → {$to->value} isn't allowed).");
    }
}
