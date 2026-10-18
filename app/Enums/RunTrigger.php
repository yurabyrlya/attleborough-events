<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What started a run: the scheduler, an admin's "Run now" / "Test run", or
 * the "Create drafts" activation (D-01). Values match source_runs_trigger_check.
 */
enum RunTrigger: string implements HasLabel
{
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Activation = 'activation';

    public function getLabel(): string
    {
        return match ($this) {
            self::Schedule => 'Scheduled',
            self::Manual => 'Manual',
            self::Activation => 'Activation',
        };
    }
}
