<?php

namespace App\Enums;

use App\Publishing\Exceptions\InvalidStatusTransition;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EventStatus: string implements HasColor, HasLabel
{
    case Published = 'published';
    case Hidden = 'hidden';
    case Cancelled = 'cancelled';

    /**
     * Allowed moves (ARCHITECTURE Pattern 3). Deliberately no direct
     * hidden <-> cancelled: showing an event must never silently drop its
     * cancellation, and hiding a cancelled one would lose the badge state.
     * Same-to-same is never allowed.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Published => [self::Hidden, self::Cancelled],
            self::Hidden => [self::Published],
            self::Cancelled => [self::Published],
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
     * Statuses an event may have and still appear publicly (cancelled shows
     * a badge until its dates expire; hidden never shows, and its event page
     * 404s in plan 01-08).
     *
     * @return list<self>
     */
    public static function publiclyListed(): array
    {
        return [self::Published, self::Cancelled];
    }

    public function isPubliclyListed(): bool
    {
        return in_array($this, self::publiclyListed(), true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Published => 'Published',
            self::Hidden => 'Hidden',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Published => 'success',
            self::Hidden => 'gray',
            self::Cancelled => 'danger',
        };
    }
}
