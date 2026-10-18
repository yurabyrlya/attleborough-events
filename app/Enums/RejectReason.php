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
 *
 * Ignored is set only by IgnoreSourceChange on a source update or
 * cancellation draft (03-10, A7); it is not offered in the Reject form.
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
    case Ignored = 'ignored';

    /**
     * The reasons the admin can pick in a Reject dialog, value => label.
     * Ignored is left out: only IgnoreSourceChange sets it.
     *
     * @return array<string, string>
     */
    public static function rejectOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case !== self::Ignored) {
                $options[$case->value] = $case->getLabel();
            }
        }

        return $options;
    }

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
            self::Ignored => 'Ignored (source change)',
        };
    }
}
