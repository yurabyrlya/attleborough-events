<?php

namespace App\Filament\Resources\EventDrafts\Actions;

use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\Actions\ApproveDraft;
use App\Review\DraftPublishability;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Component;

/**
 * Review queue actions (REV-01, REV-04). Rule-free like StatusActions: every
 * closure only delegates to a domain action in App\Publishing\Actions and
 * reports the result; nothing here writes a model. Approve's disabled state
 * asks DraftPublishability, the same check ApproveDraft re-runs on the
 * server (D-10). Dialog buttons name the action and the dismiss button reads
 * "Keep as is", so "Cancel" never means "keep" (D-22).
 *
 * On the review page, visibility follows the status the page was showing
 * (ReviewEventDraft::$shownStatus), not the freshly re-read record. A draft
 * decided in another tab therefore still offers the buttons the admin sees,
 * and clicking one reports "This draft was already reviewed" instead of
 * doing nothing.
 */
final class ReviewActions
{
    public const KEEP_LABEL = 'Keep as is';

    public const ALREADY_REVIEWED = 'This draft was already reviewed';

    /**
     * Header actions for the review page.
     *
     * @return list<Action>
     */
    public static function forReviewPage(): array
    {
        return [
            self::approve(),
        ];
    }

    /**
     * Record actions for the queue table.
     *
     * @return list<Action>
     */
    public static function forTable(): array
    {
        return [];
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Approve and publish')
            ->color('success')
            ->visible(fn (EventDraft $record, Component $livewire): bool => self::shownStatus($record, $livewire) === ReviewStatus::PendingReview)
            ->disabled(fn (EventDraft $record): bool => app(DraftPublishability::class)->blockers($record) !== [])
            ->requiresConfirmation()
            ->modalHeading('Publish this event?')
            ->modalDescription('It goes on the public calendar straight away.')
            ->modalSubmitActionLabel('Publish')
            ->modalCancelActionLabel(self::KEEP_LABEL)
            ->action(function (EventDraft $record, Component $livewire): void {
                $published = self::run(fn (User $actor) => app(ApproveDraft::class)($record, $actor), 'Published', $livewire);

                if ($published) {
                    $livewire->redirect(EventDraftResource::getUrl('index'));
                }
            });
    }

    /**
     * The status the admin is looking at: the page's snapshot on the review
     * page, the row's own status in the table.
     */
    private static function shownStatus(EventDraft $record, Component $livewire): ReviewStatus
    {
        return $livewire instanceof ReviewEventDraft ? $livewire->shownStatus() : $record->review_status;
    }

    /**
     * Run one review decision as the signed-in user and report the result.
     * Returns true when the decision was applied.
     *
     * @param  callable(User): mixed  $decide
     *
     * @throws AuthorizationException
     */
    private static function run(callable $decide, string $successTitle, Component $livewire): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException('Only the admin can review drafts.');
        }

        try {
            $decide($actor);
        } catch (InvalidReviewTransition|ModelNotFoundException) {
            Notification::make()->title(self::ALREADY_REVIEWED)->body('Refresh the page to see its current state.')->danger()->send();
            self::resync($livewire);

            return false;
        } catch (DraftNotPublishable $e) {
            // Plain-text body: Filament escapes notification strings.
            Notification::make()->title("Can't publish yet")->body(implode(' ', $e->blockers))->danger()->send();
            self::resync($livewire);

            return false;
        }

        Notification::make()->title($successTitle)->success()->send();
        self::resync($livewire);

        return true;
    }

    private static function resync(Component $livewire): void
    {
        if ($livewire instanceof ReviewEventDraft) {
            $livewire->syncShownStatus();
        }
    }
}
