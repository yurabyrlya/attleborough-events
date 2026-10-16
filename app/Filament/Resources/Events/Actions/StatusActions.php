<?php

namespace App\Filament\Resources\Events\Actions;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Publishing\Actions\ChangeEventStatus;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Exceptions\InvalidStatusTransition;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Filament status actions (ADM-03). Rule-free by design (ARCHITECTURE
 * Anti-Pattern 7): visibility asks the enum's canTransitionTo(), every action
 * asks for confirmation, and each closure only delegates to a domain action in
 * App\Publishing\Actions. Nothing here writes a model. Copy is plain British
 * English and never jokey about cancellations (D-05).
 */
final class StatusActions
{
    private const EVENT_CONFLICT = 'This event changed in the meantime. Refresh the page and try again.';

    private const OCCURRENCE_CONFLICT = 'This date changed in the meantime. Refresh the page and try again.';

    /**
     * Header actions for the edit page.
     *
     * @return list<Action>
     */
    public static function forEvent(): array
    {
        return [
            self::eventAction(
                name: 'hide',
                to: EventStatus::Hidden,
                from: null,
                label: 'Hide from calendar',
                color: 'gray',
                heading: 'Hide this event?',
                description: 'All of its dates disappear from the public calendar. You can show it again at any time.',
                successTitle: 'Event hidden',
            ),
            self::eventAction(
                name: 'unhide',
                to: EventStatus::Published,
                from: EventStatus::Hidden,
                label: 'Show on calendar',
                color: 'success',
                heading: 'Show this event on the calendar?',
                description: 'Its dates go back on the public calendar as they were before.',
                successTitle: 'Event shown on the calendar',
            ),
            self::eventAction(
                name: 'cancel',
                to: EventStatus::Cancelled,
                from: null,
                label: 'Mark as cancelled',
                color: 'danger',
                heading: 'Mark this event as cancelled?',
                description: 'The event stays on the public calendar with a "Cancelled" label until its dates have passed.',
                successTitle: 'Event marked as cancelled',
            ),
            self::eventAction(
                name: 'reinstate',
                to: EventStatus::Published,
                from: EventStatus::Cancelled,
                label: 'Reinstate',
                color: 'success',
                heading: 'Reinstate this event?',
                description: 'The "Cancelled" label is removed. Dates you cancelled one by one stay cancelled.',
                successTitle: 'Event reinstated',
            ),
        ];
    }

    /**
     * Record actions for the Occurrences table.
     *
     * @return list<Action>
     */
    public static function forOccurrence(): array
    {
        return [
            self::occurrenceAction(
                name: 'cancel',
                to: OccurrenceStatus::Cancelled,
                label: 'Cancel this date',
                color: 'danger',
                heading: 'Cancel this date?',
                description: 'This date stays on the public calendar with a "Cancelled" label until it has passed.',
                successTitle: 'Date cancelled',
            ),
            self::occurrenceAction(
                name: 'hide',
                to: OccurrenceStatus::Hidden,
                label: 'Hide this date',
                color: 'gray',
                heading: 'Hide this date?',
                description: 'This date disappears from the public calendar. You can restore it at any time.',
                successTitle: 'Date hidden',
            ),
            self::occurrenceAction(
                name: 'restore',
                to: OccurrenceStatus::Scheduled,
                label: 'Restore this date',
                color: 'success',
                heading: 'Restore this date?',
                description: 'This date goes back on the public calendar without a "Cancelled" label.',
                successTitle: 'Date restored',
            ),
        ];
    }

    /**
     * @param  EventStatus|null  $from  Only for actions sharing a target (unhide and
     *                                  reinstate both lead to Published): which
     *                                  current status the action is labelled for.
     */
    private static function eventAction(
        string $name,
        EventStatus $to,
        ?EventStatus $from,
        string $label,
        string $color,
        string $heading,
        string $description,
        string $successTitle,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->visible(fn (Event $record): bool => ($from === null || $record->status === $from) && $record->status->canTransitionTo($to))
            ->requiresConfirmation()
            ->modalHeading($heading)
            ->modalDescription($description)
            ->action(fn (Event $record) => self::run(fn (User $actor) => app(ChangeEventStatus::class)($record, $to, $actor), $successTitle, self::EVENT_CONFLICT))
            // Re-read (never write) the page's record so the header shows the
            // actions for the new status.
            ->after(fn (Event $record) => $record->refresh());
    }

    private static function occurrenceAction(
        string $name,
        OccurrenceStatus $to,
        string $label,
        string $color,
        string $heading,
        string $description,
        string $successTitle,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->visible(fn (EventOccurrence $record): bool => $record->status->canTransitionTo($to))
            ->requiresConfirmation()
            ->modalHeading($heading)
            ->modalDescription($description)
            ->action(fn (EventOccurrence $record) => self::run(fn (User $actor) => app(ChangeOccurrenceStatus::class)($record, $to, $actor), $successTitle, self::OCCURRENCE_CONFLICT));
    }

    /**
     * Run one domain status change as the signed-in user and report the result.
     *
     * @param  callable(User): mixed  $change
     *
     * @throws AuthorizationException
     */
    private static function run(callable $change, string $successTitle, string $conflictTitle): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException('Only the admin can change status.');
        }

        try {
            $change($actor);
        } catch (InvalidStatusTransition|ModelNotFoundException) {
            Notification::make()->title($conflictTitle)->danger()->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
