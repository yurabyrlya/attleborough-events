<?php

namespace App\Filament\Resources\Events\Actions;

use App\Enums\OccurrenceStatus;
use App\Models\EventOccurrence;
use App\Models\User;
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
 * App\Publishing\Actions. Nothing here writes a model.
 */
final class StatusActions
{
    private const OCCURRENCE_CONFLICT = 'This date changed in the meantime. Refresh the page and try again.';

    /**
     * RED scaffold: header actions for the edit page.
     *
     * @return list<Action>
     */
    public static function forEvent(): array
    {
        return [];
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
            // RED scaffold: names only, never visible, no behaviour.
            Action::make('hide')->visible(false),
            Action::make('restore')->visible(false),
        ];
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
