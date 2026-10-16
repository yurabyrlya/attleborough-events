<?php

namespace App\Enums;

use App\Publishing\Exceptions\InvalidStatusTransition;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OccurrenceStatus: string implements HasColor, HasLabel
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case Hidden = 'hidden';

    /**
     * Allowed moves (ARCHITECTURE Pattern 3). No direct cancelled <-> hidden:
     * restore first. Same-to-same is never allowed.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Cancelled, self::Hidden],
            self::Cancelled => [self::Scheduled],
            self::Hidden => [self::Scheduled],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    /**
     * @throws InvalidStatusTransition
     */
    public function assertCanTransitionTo(self $to): void
    {
        if (! $this->canTransitionTo($to)) {
            throw new InvalidStatusTransition($this, $to);
        }
    }

    /**
     * Statuses an occurrence may have and still appear publicly (cancelled
     * shows a badge until it expires; hidden never shows).
     *
     * @return list<self>
     */
    public static function publiclyListed(): array
    {
        return [self::Scheduled, self::Cancelled];
    }

    public function isPubliclyListed(): bool
    {
        return in_array($this, self::publiclyListed(), true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Cancelled => 'Cancelled',
            self::Hidden => 'Hidden',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduled => 'success',
            self::Cancelled => 'danger',
            self::Hidden => 'gray',
        };
    }
}
