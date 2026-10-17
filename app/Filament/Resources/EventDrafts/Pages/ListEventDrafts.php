<?php

namespace App\Filament\Resources\EventDrafts\Pages;

use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Models\EventDraft;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEventDrafts extends ListRecords
{
    protected static string $resource = EventDraftResource::class;

    /**
     * Set while Filament builds the query for explicitly selected rows, so
     * the active tab does not filter them out (see
     * getSelectedTableRecordsQuery()).
     */
    private bool $ignoreActiveTab = false;

    /**
     * No create action: drafts come from submissions and manual entry.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Pending (the default), Rejected (D-12), Approved and All, each with
     * its count. A row action or a selected row still resolves after its
     * draft leaves the tab (decided elsewhere), so the domain action can
     * report "already reviewed" instead of the row vanishing.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = EventDraft::query()
            ->selectRaw('review_status, count(*) as aggregate')
            ->groupBy('review_status')
            ->pluck('aggregate', 'review_status');

        $count = fn (ReviewStatus $status): int => (int) ($counts[$status->value] ?? 0);

        return [
            'pending' => self::statusTab('Pending', ReviewStatus::PendingReview, $count(ReviewStatus::PendingReview)),
            'rejected' => self::statusTab('Rejected', ReviewStatus::Rejected, $count(ReviewStatus::Rejected)),
            'approved' => self::statusTab('Approved', ReviewStatus::Approved, $count(ReviewStatus::Approved)),
            'all' => Tab::make('All')->badge((int) $counts->sum()),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'pending';
    }

    /**
     * Rows the admin ticked are acted on by key, whatever the tab now
     * shows. Without this, a selected draft decided in another tab would be
     * dropped from a bulk run silently instead of being reported as
     * "already reviewed" (REV-01, REV-08: no silent skips). "Select all"
     * (tracking deselected rows) still means "all on this tab".
     *
     * @return Builder<EventDraft>
     */
    public function getSelectedTableRecordsQuery(bool $shouldFetchSelectedRecords = true, ?int $chunkSize = null): Builder
    {
        if ($this->isTrackingDeselectedTableRecords) {
            return parent::getSelectedTableRecordsQuery($shouldFetchSelectedRecords, $chunkSize);
        }

        $this->ignoreActiveTab = true;

        try {
            return parent::getSelectedTableRecordsQuery($shouldFetchSelectedRecords, $chunkSize);
        } finally {
            $this->ignoreActiveTab = false;
        }
    }

    /**
     * @param  Builder<EventDraft>  $query
     * @return Builder<EventDraft>
     */
    protected function modifyQueryWithActiveTab(Builder $query, bool $isResolvingRecord = false): Builder
    {
        if ($this->ignoreActiveTab) {
            return $query;
        }

        return parent::modifyQueryWithActiveTab($query, $isResolvingRecord);
    }

    private static function statusTab(string $label, ReviewStatus $status, int $count): Tab
    {
        return Tab::make($label)
            ->badge($count)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('review_status', $status))
            ->excludeQueryWhenResolvingRecord();
    }
}
