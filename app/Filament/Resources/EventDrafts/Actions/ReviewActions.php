<?php

namespace App\Filament\Resources\EventDrafts\Actions;

use App\Enums\EventOrigin;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\EventDrafts\Pages\EditEventDraft;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\Actions\ApproveDraft;
use App\Publishing\Actions\RejectDraft;
use App\Publishing\Actions\ReopenDraft;
use App\Review\DraftPublishability;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
use App\Review\ReviewQueue;
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
 *
 * "Approve & next" (REV-10, D-09/D-10): on the review page Approve, Reject
 * and Skip open the next pending draft in ReviewQueue order (the list with
 * "You've reached the end of the queue" after the last one). Every button
 * shows its key and carries aria-keyshortcuts; the keys themselves are a
 * guarded Alpine handler in the page view, never Filament's key bindings
 * (Mousetrap's global bindings fire inside text inputs).
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
            self::edit(),
            self::reject(),
            self::skip(),
            self::previous(),
            self::reopen(),
            self::rawContent(),
            self::help(),
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
            ->label('Approve & next (A)')
            ->extraAttributes(['aria-keyshortcuts' => 'A'])
            ->color('success')
            ->visible(fn (EventDraft $record, Component $livewire): bool => self::shownStatus($record, $livewire) === ReviewStatus::PendingReview)
            ->disabled(fn (EventDraft $record): bool => app(DraftPublishability::class)->blockers($record) !== [])
            ->requiresConfirmation()
            ->modalHeading('Publish this event?')
            ->modalDescription('It goes on the public calendar straight away.')
            ->modalSubmitActionLabel('Publish')
            // Focus "Publish" when the dialog opens, so the keyboard flow is
            // A then Enter (D-10); Escape or "Keep as is" backs out.
            ->modalSubmitAction(fn (Action $action): Action => $action->extraAttributes(['autofocus' => true]))
            ->modalCancelActionLabel(self::KEEP_LABEL)
            ->action(function (EventDraft $record, Component $livewire): void {
                $published = self::run(fn (User $actor) => app(ApproveDraft::class)($record, $actor), 'Published', $livewire);

                if ($published) {
                    self::openNext($record, $livewire);
                }
            });
    }

    /**
     * Open the edit-and-approve page (REV-03).
     */
    public static function edit(): Action
    {
        return Action::make('edit')
            ->label('Edit (E)')
            ->extraAttributes(['aria-keyshortcuts' => 'E'])
            ->color('gray')
            ->visible(fn (EventDraft $record, Component $livewire): bool => self::shownStatus($record, $livewire) === ReviewStatus::PendingReview)
            ->url(fn (EventDraft $record): string => EventDraftResource::getUrl('edit', ['record' => $record]));
    }

    /**
     * Leave this draft pending and open the next one (D-09).
     */
    public static function skip(): Action
    {
        return Action::make('skip')
            ->label('Skip (S)')
            ->extraAttributes(['aria-keyshortcuts' => 'S J'])
            ->color('gray')
            ->visible(fn (EventDraft $record, Component $livewire): bool => self::shownStatus($record, $livewire) === ReviewStatus::PendingReview)
            ->action(fn (EventDraft $record, Component $livewire) => self::openNext($record, $livewire));
    }

    /**
     * Open the pending draft before this one; hidden on the first.
     */
    public static function previous(): Action
    {
        return Action::make('previous')
            ->label('Previous (K)')
            ->extraAttributes(['aria-keyshortcuts' => 'K'])
            ->color('gray')
            ->visible(fn (EventDraft $record): bool => app(ReviewQueue::class)->previous($record) !== null)
            ->action(function (EventDraft $record, Component $livewire): void {
                $previous = app(ReviewQueue::class)->previous($record);

                $livewire->redirect($previous === null
                    ? EventDraftResource::getUrl('index')
                    : EventDraftResource::getUrl('review', ['record' => $previous]));
            });
    }

    /**
     * The keyboard shortcuts (D-10), also opened with "?".
     */
    public static function help(): Action
    {
        return Action::make('help')
            ->label('Keyboard shortcuts (?)')
            ->extraAttributes(['aria-keyshortcuts' => '?'])
            ->color('gray')
            ->modalHeading('Keyboard shortcuts')
            ->modalContent(view('filament.resources.event-drafts.keyboard-help'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * Reject with an optional preset reason and note (REV-04, D-11). From
     * the review page it opens the next draft; in the table it stays put.
     */
    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(fn (Component $livewire): string => $livewire instanceof ReviewEventDraft ? 'Reject (R)' : 'Reject')
            ->extraAttributes(fn (Component $livewire): array => $livewire instanceof ReviewEventDraft ? ['aria-keyshortcuts' => 'R'] : [])
            ->color('danger')
            ->visible(fn (EventDraft $record, Component $livewire): bool => self::shownStatus($record, $livewire) === ReviewStatus::PendingReview)
            ->modalHeading('Reject this draft?')
            ->modalDescription('It leaves the queue and nothing is published. The organiser is not told. Rejected drafts are deleted after the retention period.')
            ->schema(self::rejectFields())
            ->modalSubmitActionLabel('Reject draft')
            ->modalCancelActionLabel(self::KEEP_LABEL)
            ->action(function (EventDraft $record, array $data, Component $livewire): void {
                [$reason, $note] = self::rejectInput($data);

                $rejected = self::run(fn (User $actor) => app(RejectDraft::class)($record, $actor, $reason, $note), 'Draft rejected', $livewire);

                if ($rejected) {
                    self::openNext($record, $livewire);
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

    /**
     * The optional reason and note asked for by every reject dialog: the
     * row action, the review page and bulk reject.
     *
     * @return list<Select|Textarea>
     */
    public static function rejectFields(): array
    {
        return [
            Select::make('reject_reason')
                ->label('Reason (optional)')
                ->options(RejectReason::class)
                ->placeholder('No reason'),
            Textarea::make('review_notes')
                ->label('Note (optional)')
                ->rows(3)
                ->maxLength(2000),
        ];
    }

    /**
     * The reason and note from a reject dialog's data.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?RejectReason, 1: ?string}
     */
    public static function rejectInput(array $data): array
    {
        $note = $data['review_notes'] ?? null;

        return [
            self::rejectReason($data['reject_reason'] ?? null),
            is_string($note) ? $note : null,
        ];
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

    /**
     * On the review page, open the pending draft after $current (D-09), or
     * the queue with a notice at the end. Elsewhere (the table) stay put.
     */
    private static function openNext(EventDraft $current, Component $livewire): void
    {
        if (! $livewire instanceof ReviewEventDraft) {
            return;
        }

        $next = app(ReviewQueue::class)->next($current);

        if ($next === null) {
            Notification::make()->title(EditEventDraft::END_OF_QUEUE)->info()->send();
            $livewire->redirect(EventDraftResource::getUrl('index'));

            return;
        }

        $livewire->redirect(EventDraftResource::getUrl('review', ['record' => $next]));
    }

    private static function resync(Component $livewire): void
    {
        if ($livewire instanceof ReviewEventDraft) {
            $livewire->syncShownStatus();
        }
    }
}
