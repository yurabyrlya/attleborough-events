<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How complete and trustworthy a draft looks. Values match the
 * event_drafts.quality_band CHECK. Bulk approve only takes High and Medium
 * drafts; an unscored draft counts as Low (fail closed).
 */
enum QualityBand: string implements HasColor, HasLabel
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /**
     * The band for a 0-100 score, using the thresholds in config/review.php.
     */
    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= (int) config('review.quality.bands.high') => self::High,
            $score >= (int) config('review.quality.bands.medium') => self::Medium,
            default => self::Low,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::High => 'success',
            self::Medium => 'warning',
            self::Low => 'danger',
        };
    }
}
