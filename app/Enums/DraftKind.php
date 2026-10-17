<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a draft proposes: a new event, a change to a published one, or its
 * cancellation. Phase 2 creates only New drafts; Phase 3 adds the others.
 */
enum DraftKind: string implements HasLabel
{
    case New = 'new';
    case Update = 'update';
    case Cancellation = 'cancellation';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'New event',
            self::Update => 'Update',
            self::Cancellation => 'Cancellation',
        };
    }
}
