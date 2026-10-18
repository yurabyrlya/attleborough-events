<?php

namespace App\Filament\Resources\EventDrafts\Tables;

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\QualityBand;
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
use App\Support\ConcurrencyFailure;
use App\Support\LondonFormatter;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Livewire\Component;
use PDOException;

class EventDraftsTable
{
    public const URGENT = 'Urgent';

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
                TextColumn::make('quality_score')
                    ->label('Score')
                    ->formatStateUsing(fn (?int $state): string => "{$state}/100")
                    ->placeholder('Not scored'),
                // One search box over the title, reference, organiser and
                // typed venue (case-insensitive on PostgreSQL).
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable(['title', 'reference', 'organiser_name', 'venue_name'])
                    ->wrap(),
                TextColumn::make('when')
                    ->label('When')
                    ->state(fn (EventDraft $record): string => LondonFormatter::wallClock($record->start_date, $record->start_time, $record->all_day)),
                TextColumn::make('venue')
                    ->label('Venue')
                    ->state(fn (EventDraft $record): ?string => self::venueLabel($record))
                    ->placeholder('No venue')
                    ->wrap(),
                TextColumn::make('category')
                    ->label('Category')
                    ->state(fn (EventDraft $record): ?string => self::categoryLabel($record))
                    ->placeholder('No category'),
                // The source's name for discovered drafts, the origin label
                // (Manual entry, Public submission) otherwise (REV-09).
                TextColumn::make('source')
                    ->label('Source')
                    ->state(fn (EventDraft $record): string => self::sourceLabel($record))
                    ->wrap(),
                // The kind, plus an "Urgent" badge for an update or
                // cancellation due within 48 hours (D-09), judged by the same
                // rule that puts it first in the queue.
                TextColumn::make('kind')
                    ->label('Kind')
                    ->state(fn (EventDraft $record): array => self::kindBadges($record))
                    ->badge()
                    ->color(fn (string $state): string => $state === self::URGENT ? 'danger' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime('D j M Y, H:i')
                    ->timezone('Europe/London')
                    ->sortable(),
                TextColumn::make('review_status')
                    ->label('Status')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(
                /** @param Builder<EventDraft> $query */
                fn (Builder $query): Builder => $query->with(['venue', 'category', 'source']),
            )
            ->filters([
                SelectFilter::make('kind')
                    ->label('Kind')
                    ->options(DraftKind::class),
                // REV-09: the real sources; manual entries and submissions
                // have none, so the Origin filter finds those.
                SelectFilter::make('source_id')
                    ->label('Source')
                    ->relationship('source', 'name'),
                SelectFilter::make('origin')
                    ->label('Origin')
                    ->options(EventOrigin::class),
                SelectFilter::make('quality_band')
                    ->label('Quality')
                    ->options(QualityBand::class),
                // PIPE-05: flagged against a published event or another
                // pending draft (03-11 deferred item 3).
                TernaryFilter::make('duplicate')
                    ->label('Possible duplicate')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q
                            ->whereNotNull('duplicate_of_event_id')
                            ->orWhereNotNull('duplicate_of_draft_id')),
                        false: fn (Builder $query): Builder => $query
                            ->whereNull('duplicate_of_event_id')
                            ->whereNull('duplicate_of_draft_id'),
                    ),
                // start_date is the London wall-clock date, so the range is
                // a plain inclusive date comparison. Drafts without a start
                // date drop out only once a bound is set.
                Filter::make('event_date')
                    ->label('Event date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(
                        /**
                         * @param  Builder<EventDraft>  $query
                         * @param  array<string, mixed>  $data
                         */
                        fn (Builder $query, array $data): Builder => $query
                            ->when(filled($data['from'] ?? null), fn (Builder $q): Builder => $q->whereDate('start_date', '>=', $data['from']))
                            ->when(filled($data['until'] ?? null), fn (Builder $q): Builder => $q->whereDate('start_date', '<=', $data['until'])),
                    )
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        filled($data['from'] ?? null) ? 'From '.CarbonImmutable::parse($data['from'])->format('j M Y') : null,
                        filled($data['until'] ?? null) ? 'Until '.CarbonImmutable::parse($data['until'])->format('j M Y') : null,
                    ]))),
            ])
            ->emptyStateHeading(fn (Component $livewire): string => self::isPendingTab($livewire) ? 'Nothing waiting for review' : 'No drafts here')
            ->emptyStateDescription(fn (Component $livewire): ?string => self::isPendingTab($livewire)
                ? 'New submissions appear here. Manual entries are published straight away.'
                : null)
            ->recordUrl(fn (EventDraft $record): string => EventDraftResource::getUrl('review', ['record' => $record]))
            // No delete, bulk delete or force delete: drafts are the audit
            // trail behind published events, and rejected drafts purge on
            // schedule (D-12). Every decision goes through the review
            // actions in App\Publishing\Actions.
            ->recordActions([
                ...ReviewActions::forTable(),
                self::approveAllLikeThis(),
            ])
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
                    } catch (DraftNotPublishable|InvalidArgumentException) {
                        self::skip($action, 'blocked');
                    } catch (PDOException $e) {
                        // Review WR-08: skip a deadlock victim, carry on.
                        if (! ConcurrencyFailure::causedBy($e)) {
                            throw $e;
                        }

                        self::skip($action, 'busy');
                    }
                }
            })
            ->successNotificationTitle('Approved')
            ->failureNotificationTitle(fn (int $successCount, int $totalCount): string => "Approved {$successCount} of {$totalCount}. The rest were skipped:")
            ->deselectRecordsAfterCompletion();
    }

    /**
     * "Approve all like this" (D-03): the same action as on the review page.
     */
    public static function approveAllLikeThis(): Action
    {
        return ReviewActions::approveAllLikeThis();
    }

    /**
     * Bulk reject with one optional reason and note (REV-04, REV-08). Any
     * draft may be selected; drafts that are no longer pending are reported
     * as already reviewed, and source updates and cancellations as source
     * changes (they are applied, confirmed or ignored one by one).
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

                    // Rejecting would leave the accepted baseline where it
                    // was, so the next run would raise the change again.
                    if ($record->kind !== DraftKind::New) {
                        self::skip($action, 'source_change');

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
     * The confirmed venue, or the organiser's typed venue marked as not yet
     * confirmed.
     */
    private static function venueLabel(EventDraft $record): ?string
    {
        if ($record->venue !== null) {
            return $record->venue->name;
        }

        $typed = trim((string) $record->venue_name);

        return $typed === '' ? null : "{$typed} (not confirmed)";
    }

    private static function sourceLabel(EventDraft $record): string
    {
        return $record->source->name ?? $record->origin->getLabel();
    }

    /**
     * @return list<string>
     */
    private static function kindBadges(EventDraft $record): array
    {
        $badges = [$record->kind->getLabel()];

        if (ReviewQueue::isUrgent($record, CarbonImmutable::now())) {
            $badges[] = self::URGENT;
        }

        return $badges;
    }

    /**
     * The category name; a flagged Other fallback reads "Other (check)".
     */
    private static function categoryLabel(EventDraft $record): ?string
    {
        $name = $record->category?->name;

        if ($record->category_flagged) {
            return ($name ?? 'Other').' (check)';
        }

        return $name;
    }

    private static function isPendingTab(Component $livewire): bool
    {
        return ! property_exists($livewire, 'activeTab') || in_array($livewire->activeTab, [null, 'pending'], true);
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
