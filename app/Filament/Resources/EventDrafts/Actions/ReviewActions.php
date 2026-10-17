<?php

namespace App\Filament\Resources\EventDrafts\Actions;

use App\Enums\EventOrigin;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\Actions\ApproveDraft;
use App\Publishing\Actions\RejectDraft;
use App\Publishing\Actions\ReopenDraft;
use App\Review\DraftPublishability;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
            self::reject(),
            self::reopen(),
            self::rawContent(),
        ];
    }

    /**
     * Record actions for the queue table.
     *
     * @return list<Action>
     */
    public static function forTable(): array
    {
        return [
            self::reject(),
            self::reopen(),
        ];
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
     * Reject with an optional preset reason and note (REV-04, D-11). From
     * the review page it returns to the queue; in the table it stays put.
     */
    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->color('danger')
            ->visible(fn (EventDraft $record, Component $livewire): bool => self::shownStatus($record, $livewire) === ReviewStatus::PendingReview)
            ->modalHeading('Reject this draft?')
            ->modalDescription('It leaves the queue and nothing is published. The organiser is not told. Rejected drafts are deleted after the retention period.')
            ->schema([
                Select::make('reject_reason')
                    ->label('Reason (optional)')
                    ->options(RejectReason::class)
                    ->placeholder('No reason'),
                Textarea::make('review_notes')
                    ->label('Note (optional)')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->modalSubmitActionLabel('Reject draft')
            ->modalCancelActionLabel(self::KEEP_LABEL)
            ->action(function (EventDraft $record, array $data, Component $livewire): void {
                $reason = self::rejectReason($data['reject_reason'] ?? null);
                $note = is_string($data['review_notes'] ?? null) ? $data['review_notes'] : null;

                $rejected = self::run(fn (User $actor) => app(RejectDraft::class)($record, $actor, $reason, $note), 'Draft rejected', $livewire);

                if ($rejected && $livewire instanceof ReviewEventDraft) {
                    $livewire->redirect(EventDraftResource::getUrl('index'));
                }
            });
    }

    /**
     * Return a rejected draft to the queue (D-12).
     */
    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label('Reopen')
            ->color('gray')
            ->visible(fn (EventDraft $record, Component $livewire): bool => self::shownStatus($record, $livewire)->canTransitionTo(ReviewStatus::PendingReview))
            ->requiresConfirmation()
            ->modalHeading('Reopen this draft?')
            ->modalDescription('It goes back into the review queue as pending. The rejection note is kept.')
            ->modalSubmitActionLabel('Reopen')
            ->modalCancelActionLabel(self::KEEP_LABEL)
            ->action(fn (EventDraft $record, Component $livewire) => self::run(fn (User $actor) => app(ReopenDraft::class)($record, $actor), 'Draft reopened', $livewire));
    }

    /**
     * What the organiser sent, as escaped text (T-02-33). Read-only.
     */
    public static function rawContent(): Action
    {
        return Action::make('rawContent')
            ->label('Raw content')
            ->color('gray')
            ->modalHeading('What the organiser sent')
            ->modalContent(fn (EventDraft $record) => view('filament.resources.event-drafts.raw-content', [
                'payload' => $record->raw_payload ?? [],
                'emptyMessage' => self::noRawContentMessage($record),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * Shown when a draft kept no raw content.
     */
    public static function noRawContentMessage(EventDraft $record): string
    {
        return $record->origin === EventOrigin::Manual
            ? 'No raw content: entered by admin'
            : 'No raw content was kept';
    }

    private static function rejectReason(mixed $value): ?RejectReason
    {
        if ($value instanceof RejectReason) {
            return $value;
        }

        return is_string($value) && $value !== '' ? RejectReason::tryFrom($value) : null;
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
