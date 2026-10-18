<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * A live run writes source items and drafts; a preview (test) run only
 * reports what a live run would do (D-01). Values match source_runs_mode_check.
 */
enum RunMode: string implements HasLabel
{
    case Live = 'live';
    case Preview = 'preview';

    public function getLabel(): string
    {
        return match ($this) {
            self::Live => 'Live',
            self::Preview => 'Test run',
        };
    }
}
