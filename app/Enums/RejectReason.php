<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Optional preset reason the admin picks when rejecting a draft (D-11).
 * Stored as the value so Phase 5's dashboard can count rejections by reason;
 * the free-text note lives in review_notes. Values match the
 * event_drafts_reject_reason_check constraint.
 *
 * Spam also shortens retention: see EventDraft::prunable() (D-12).
 */
enum RejectReason: string implements HasLabel
{
    case Spam = 'spam';
    case OutOfArea = 'out_of_area';
    case NotAnEvent = 'not_an_event';
    case Past = 'past';
    case Duplicate = 'duplicate';
    case Incomplete = 'incomplete';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Spam => 'Spam',
            self::OutOfArea => 'Out of area',
            self::NotAnEvent => 'Not an event',
            self::Past => 'Past or expired',
            self::Duplicate => 'Duplicate of an existing event',
            self::Incomplete => "Incomplete or can't be checked",
            self::Other => 'Other',
        };
    }
}
