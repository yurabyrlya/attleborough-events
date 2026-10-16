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
     * RED scaffold: no transitions yet.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return [];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    public function assertCanTransitionTo(self $to): void
    {
        if (! $this->canTransitionTo($to)) {
            throw new InvalidStatusTransition($this, $to);
        }
    }

    /**
     * RED scaffold.
     *
     * @return list<self>
     */
    public static function publiclyListed(): array
    {
        return [];
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
