<?php

namespace App\Filament\Resources\EventDrafts;

use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Pages\ListEventDrafts;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Filament\Resources\EventDrafts\Schemas\EventDraftInfolist;
use App\Filament\Resources\EventDrafts\Tables\EventDraftsTable;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Support\LondonFormatter;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The Review queue (REV-01): every draft, soonest event first (D-09), with a
 * review page per draft. There is no create page (manual entry stays on
 * EventResource) and no delete: drafts are the audit trail behind published
 * events, and rejected drafts purge on schedule (D-12).
 */
class EventDraftResource extends Resource
{
    protected static ?string $model = EventDraft::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Review';

    protected static ?string $navigationLabel = 'Review queue';

    protected static ?string $modelLabel = 'draft';

    protected static ?string $recordTitleAttribute = 'title';

    public static function table(Table $table): Table
    {
        return EventDraftsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EventDraftInfolist::configure($schema);
    }

    /**
     * Same rule as the panel gate (User::canAccessPanel), so global search
     * and the pages refuse a non-admin even outside the panel middleware.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && AdminGate::allows($user);
    }

    /**
     * ADM-06. PostgreSQL search is case-insensitive substring matching, so
     * a reference typed in lower case still finds the draft.
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'reference', 'organiser_name', 'venue_name'];
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('review', ['record' => $record]);
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof EventDraft) {
            return [];
        }

        return [
            'Status' => $record->review_status->getLabel(),
            'Starts' => LondonFormatter::wallClock($record->start_date, $record->start_time, $record->all_day),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = EventDraft::query()->where('review_status', ReviewStatus::PendingReview)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEventDrafts::route('/'),
            'review' => ReviewEventDraft::route('/{record}/review'),
        ];
    }
}
