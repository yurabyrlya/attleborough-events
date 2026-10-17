<?php

namespace App\Filament\Resources\EventDrafts\Tables;

use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Actions\ReviewActions;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\Actions\ApproveDraft;
use App\Publishing\Actions\RejectDraft;
use App\Review\DraftPublishability;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
use App\Review\ReviewQueue;
use App\Support\LondonFormatter;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EventDraftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // D-09: the same sort key as ReviewQueue::next()/previous().
            ->defaultSort(
                /** @param Builder<EventDraft> $query */
                fn (Builder $query): Builder => ReviewQueue::applyOrder($query),
            )
            ->columns([
                // Band first, number second (D-22); the band is text, not
                // colour alone.
                TextColumn::make('quality_band')
                    ->label('Quality')
                    ->badge()
                    ->placeholder('Not scored'),
                TextColumn::make('title')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('when')
                    ->label('When')
                    ->state(fn (EventDraft $record): string => LondonFormatter::wallClock($record->start_date, $record->start_time, $record->all_day)),
                TextColumn::make('origin')
                    ->label('Source'),
                TextColumn::make('review_status')
                    ->label('Status')
                    ->badge(),
            ])
            ->recordUrl(fn (EventDraft $record): string => EventDraftResource::getUrl('review', ['record' => $record]))
            // No delete, bulk delete or force delete: drafts are the audit
            // trail behind published events, and rejected drafts purge on
            // schedule (D-12). Every decision goes through the review
            // actions in App\Publishing\Actions.
            ->recordActions(ReviewActions::forTable())
            // Every draft stays selectable (no record-selectability rule):
            // Low-quality and parse-error drafts must still be bulk
            // rejectable. Bulk approve skips them per record instead and
            // names each skip (RESEARCH Pitfall 6).
            ->toolbarActions([
                BulkActionGroup::make([
                    self::approveSelected(),
                    self::rejectSelected(),
                ]),
            ]);
    }

    /**
     * Bulk approve (REV-08). Each selected draft is checked against
     * DraftPublishability::bulkBlockerKeys() and either approved through
     * ApproveDraft, in its own transaction, or reported under its first
     * skip key. The failure notification lists every key with its count, so
     * the skips add up to the drafts that were not approved and none is
     * skipped silently (D-06, D-21).
     */
    public static function approveSelected(): BulkAction
    {
        return BulkAction::make('approveSelected')
            ->label('Approve selected')
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve the selected drafts?')
            ->modalDescription('Drafts with problems are skipped and listed afterwards.')
            ->modalSubmitActionLabel('Approve')
            ->modalCancelActionLabel(ReviewActions::KEEP_LABEL)
            ->action(function (BulkAction $action, Collection $records): void {
                $actor = self::actor();
                $publishability = app(DraftPublishability::class);
                $approve = app(ApproveDraft::class);

                /** @var EventDraft $record */
                foreach ($records as $record) {
                    if ($record->review_status !== ReviewStatus::PendingReview) {
                        self::skip($action, 'already_reviewed');

                        continue;
                    }

                    $keys = $publishability->bulkBlockerKeys($record);

                    if ($keys !== []) {
                        self::skip($action, $keys[0]);

                        continue;
                    }

                    try {
                        $approve($record, $actor);
                    } catch (InvalidReviewTransition|ModelNotFoundException) {
                        self::skip($action, 'already_reviewed');
                    } catch (DraftNotPublishable) {
                        self::skip($action, 'blocked');
                    }
                }
            })
            ->successNotificationTitle('Approved')
            ->failureNotificationTitle(fn (int $successCount, int $totalCount): string => "Approved {$successCount} of {$totalCount}. The rest were skipped:")
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Bulk reject with one optional reason and note (REV-04, REV-08). Any
     * draft may be selected; drafts that are no longer pending are reported
     * as already reviewed.
     */
    public static function rejectSelected(): BulkAction
    {
        return BulkAction::make('rejectSelected')
            ->label('Reject selected')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->modalHeading('Reject the selected drafts?')
            ->modalDescription('They leave the queue and nothing is published. Organisers are not told. Rejected drafts are deleted after the retention period.')
            ->schema(ReviewActions::rejectFields())
            ->modalSubmitActionLabel('Reject drafts')
            ->modalCancelActionLabel(ReviewActions::KEEP_LABEL)
            ->action(function (BulkAction $action, Collection $records, array $data): void {
                $actor = self::actor();
                $reject = app(RejectDraft::class);
                [$reason, $note] = ReviewActions::rejectInput($data);

                /** @var EventDraft $record */
                foreach ($records as $record) {
                    if ($record->review_status !== ReviewStatus::PendingReview) {
                        self::skip($action, 'not_pending');

                        continue;
                    }

                    try {
                        $reject($record, $actor, $reason, $note);
                    } catch (InvalidReviewTransition|ModelNotFoundException) {
                        self::skip($action, 'not_pending');
                    }
                }
            })
            ->successNotificationTitle('Drafts rejected')
            ->failureNotificationTitle(fn (int $successCount, int $totalCount): string => "Rejected {$successCount} of {$totalCount}. The rest were skipped:")
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Count one skipped draft under its reason; the notification shows
     * "{n} skipped: {reason}" once per reason.
     */
    private static function skip(BulkAction $action, string $key): void
    {
        $action->reportBulkProcessingFailure(
            $key,
            message: fn (int $failureCount): string => DraftPublishability::skipMessage($key, $failureCount),
        );
    }

    /**
     * @throws AuthorizationException
     */
    private static function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException('Only the admin can review drafts.');
        }

        return $actor;
    }
}
