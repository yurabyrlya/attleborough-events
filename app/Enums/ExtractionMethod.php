<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a draft's fields were obtained (and, for sources, how a source is
 * read). Values match event_drafts_extraction_method_check; Ics also matches
 * sources_extraction_method_check. Phase 4 adds the HTML crawler methods and
 * widens both constraints with them.
 */
enum ExtractionMethod: string implements HasLabel
{
    case Submission = 'submission';
    case Manual = 'manual';
    case Ics = 'ics';

    public function getLabel(): string
    {
        return match ($this) {
            self::Submission => 'Organiser form',
            self::Manual => 'Entered by admin',
            self::Ics => 'ICS feed',
        };
    }
}
