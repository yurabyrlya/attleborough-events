<?php

namespace App\Enums;

use App\Review\Exceptions\InvalidReviewTransition;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a draft is in review. Only pending drafts can be decided; a rejected
 * draft can be reopened; approved, duplicate and superseded are final.
 */
enum ReviewStatus: string implements HasColor, HasLabel
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
    case Superseded = 'superseded';

    /**
     * Allowed moves. Same-to-same is never allowed, so a stale page or a
     * double submit cannot approve or reject twice.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::PendingReview => [self::Approved, self::Rejected, self::Duplicate, self::Superseded],
            self::Rejected => [self::PendingReview],
            self::Approved, self::Duplicate, self::Superseded => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    /**
     * @throws InvalidReviewTransition
     */
    public function assertCanTransitionTo(self $to): void
    {
        if (! $this->canTransitionTo($to)) {
            throw new InvalidReviewTransition($this, $to);
        }
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Duplicate => 'Duplicate',
            self::Superseded => 'Superseded',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PendingReview => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Duplicate, self::Superseded => 'gray',
        };
    }
}
