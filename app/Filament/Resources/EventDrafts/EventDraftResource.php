<?php

namespace App\Filament\Resources\EventDrafts;

use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Pages\ListEventDrafts;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Filament\Resources\EventDrafts\Schemas\EventDraftInfolist;
use App\Filament\Resources\EventDrafts\Tables\EventDraftsTable;
use App\Models\EventDraft;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
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
