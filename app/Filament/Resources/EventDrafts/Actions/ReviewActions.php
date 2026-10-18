<?php

namespace App\Filament\Resources\EventDrafts\Actions;

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\EventDrafts\Pages\EditEventDraft;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Filament\Resources\EventDrafts\Schemas\DiscoveredDraftDetails;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\Actions\ApplySourceUpdate;
use App\Publishing\Actions\ApproveDraft;
use App\Publishing\Actions\ConfirmSourceCancellation;
use App\Publishing\Actions\IgnoreSourceChange;
use App\Publishing\Actions\RejectDraft;
use App\Publishing\Actions\ReopenDraft;
use App\Review\DraftPublishability;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\DraftNotReopenable;
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
 *
 * Kind-aware (03-13): Approve and Reject are for new-event drafts only. A
 * source update offers "Apply changes" and "Ignore", a source cancellation
 * "Confirm cancellation" and "Ignore" (REV-06, REV-07, D-07, D-08), so a
 * source change is never rejected without moving the item's accepted
 * baseline (which would raise the same change again on the next run).
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
            self::applyChanges(),
            self::confirmCancellation(),
            self::edit(),
            self::reject(),
            self::ignore(),
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
            self::applyChanges(),
            self::confirmCancellation(),
            self::reject(),
            self::ignore(),
            self::reopen(),
        ];
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Approve & next (A)')
            ->extraAttributes(['aria-keyshortcuts' => 'A'])
            ->color('success')
            ->visible(fn (EventDraft $record, Component $livewire): bool => self::pendingOfKind($record, $livewire, DraftKind::New))
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
            // Source changes are ignored, not rejected (03-10 handoff).
            ->visible(fn (EventDraft $record, Component $livewire): bool => self::pendingOfKind($record, $livewire, DraftKind::New))
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
     * Apply a source update's changes to the published event (REV-06,
     * D-07) through ApplySourceUpdate, then open the next pending draft.
     */
    public static function applyChanges(): Action
    {
        return Action::make('applyChanges')
            ->label('Apply changes')
            ->color('success')
            ->visible(fn (EventDraft $record, Component $livewire): bool => self::pendingOfKind($record, $livewire, DraftKind::Update))
            ->requiresConfirmation()
            ->modalHeading('Apply these changes to the published event?')
            ->modalDescription('The public calendar shows the new details straight away. Fields the source did not change keep their published values.')
            ->modalSubmitActionLabel('Apply changes')
            ->modalCancelActionLabel(self::KEEP_LABEL)
            ->action(function (EventDraft $record, Component $livewire): void {
                $applied = self::run(
                    fn (User $actor) => app(ApplySourceUpdate::class)($record, $actor),
                    'Changes applied',
                    $livewire,
                    refusalTitle: "Can't apply this change",
                );

                if ($applied) {
                    self::openNext($record, $livewire);
                }
            });
    }

    /**
     * Confirm a source cancellation (REV-07, D-08): the listed dates, or
     * the whole event, show "Cancelled" on the public calendar.
     */
    public static function confirmCancellation(): Action
    {
        return Action::make('confirmCancellation')
            ->label('Confirm cancellation')
            ->color('danger')
            ->visible(fn (EventDraft $record, Component $livewire): bool => self::pendingOfKind($record, $livewire, DraftKind::Cancellation))
            ->requiresConfirmation()
            ->modalHeading('Confirm this cancellation?')
            ->modalDescription(fn (EventDraft $record): string => self::cancellationDescription($record))
            ->modalSubmitActionLabel('Confirm cancellation')
            ->modalCancelActionLabel(self::KEEP_LABEL)
            ->action(function (EventDraft $record, Component $livewire): void {
                $confirmed = self::run(
                    fn (User $actor) => app(ConfirmSourceCancellation::class)($record, $actor),
                    'Cancellation confirmed',
                    $livewire,
                    refusalTitle: "Can't confirm this cancellation",
                );

                if ($confirmed) {
                    self::openNext($record, $livewire);
                }
            });
    }

    /**
     * Ignore a source update or cancellation (D-07, D-08) through
     * IgnoreSourceChange: the published event stays as it is and the
     * accepted baseline moves, so the same change is not raised again.
     * On the review page it takes the R key that Reject has on new drafts.
     */
    public static function ignore(): Action
    {
        return Action::make('ignore')
            ->label(fn (Component $livewire): string => $livewire instanceof ReviewEventDraft ? 'Ignore (R)' : 'Ignore')
            ->extraAttributes(fn (Component $livewire): array => $livewire instanceof ReviewEventDraft ? ['aria-keyshortcuts' => 'R'] : [])
            ->color('gray')
            ->visible(fn (EventDraft $record, Component $livewire): bool => self::pendingOfKind($record, $livewire, DraftKind::Update, DraftKind::Cancellation))
            ->requiresConfirmation()
            ->modalHeading(fn (EventDraft $record): string => 'Ignore this change from '.($record->source->name ?? 'the source').'?')
            ->modalDescription('The published event stays as it is. This change is not raised again; a later change from the source is.')
            ->modalSubmitActionLabel('Ignore change')
            ->modalCancelActionLabel(self::KEEP_LABEL)
            ->action(function (EventDraft $record, Component $livewire): void {
                $ignored = self::run(
                    fn (User $actor) => app(IgnoreSourceChange::class)($record, $actor),
                    'Change ignored',
                    $livewire,
                    refusalTitle: "Can't ignore this change",
                );

                if ($ignored) {
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
            // C14: an ignored update or cancellation draft stays ignored.
            ->visible(fn (EventDraft $record, Component $livewire): bool => $record->kind === DraftKind::New
                && self::shownStatus($record, $livewire)->canTransitionTo(ReviewStatus::PendingReview))
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
                ->options(RejectReason::rejectOptions())
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
            $reason = $value;
        } else {
            $reason = is_string($value) && $value !== '' ? RejectReason::tryFrom($value) : null;
        }

        // Ignored belongs to IgnoreSourceChange, never to a Reject dialog.
        return $reason === RejectReason::Ignored ? null : $reason;
    }

    /**
     * Shown (not re-read) status is pending, and the draft is one of $kinds.
     * The kind never changes after a draft is written.
     */
    private static function pendingOfKind(EventDraft $record, Component $livewire, DraftKind ...$kinds): bool
    {
        return in_array($record->kind, $kinds, true)
            && self::shownStatus($record, $livewire) === ReviewStatus::PendingReview;
    }

    private static function cancellationDescription(EventDraft $record): string
    {
        $dates = DiscoveredDraftDetails::cancelledDates($record);

        if (in_array($dates, [[DiscoveredDraftDetails::WHOLE_EVENT], [DiscoveredDraftDetails::NOT_LISTED]], true)) {
            return 'The whole event is shown as Cancelled on the public calendar.';
        }

        return 'Shown as Cancelled on the public calendar: '.implode(', ', $dates).'. The other dates stay as they are.';
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
    private static function run(callable $decide, string $successTitle, Component $livewire, string $refusalTitle = "Can't publish yet"): bool
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
            Notification::make()->title($refusalTitle)->body(implode(' ', $e->blockers))->danger()->send();
            self::resync($livewire);

            return false;
        } catch (DraftNotReopenable $e) {
            Notification::make()->title("Can't reopen this draft")->body($e->getMessage())->danger()->send();
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
