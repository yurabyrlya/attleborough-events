<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a draft's fields were obtained. Phases 3 and 4 add the crawler methods
 * (and widen the event_drafts_extraction_method_check constraint with them).
 */
enum ExtractionMethod: string implements HasLabel
{
    case Submission = 'submission';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Submission => 'Organiser form',
            self::Manual => 'Entered by admin',
        };
    }
}
