<?php

namespace App\Filament\Resources\Sources;

use App\Enums\RunStatus;
use App\Enums\SourceHealth;
use App\Filament\Resources\Sources\Pages\CreateSource;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\RelationManagers\RunsRelationManager;
use App\Filament\Resources\Sources\Schemas\SourceForm;
use App\Filament\Resources\Sources\Tables\SourcesTable;
use App\Models\Source;
use App\Models\User;
use App\Publishing\AdminGate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The source registry (SRC-01): ICS feeds the crawler checks. Configuration
 * is saved through the default Filament create/edit record saves (conflict
 * C18: the writes rule covers events and occurrences only); validation
 * lives in SourceForm. is_active changes only through the "Create drafts"
 * and "Pause" actions (D-01), never through the form. Runs, health and
 * scheduling are written by the ingestion pipeline and only shown here.
 */
class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Review';

    protected static ?string $navigationLabel = 'Sources';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Same rule as the panel gate (User::canAccessPanel), so the pages
     * refuse a non-admin even outside the panel middleware.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && AdminGate::allows($user);
    }

    /**
     * Live sources needing attention: degraded or unhealthy, a possible
     * structure change, or a last live run blocked by robots.txt.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Source::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->where('health', '<>', SourceHealth::Healthy->value)
                ->orWhereNotNull('structure_alert_at')
                ->orWhereHas('latestLiveRun', fn (Builder $run) => $run->where('status', RunStatus::Blocked->value)))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Live sources that need attention';
    }

    public static function form(Schema $schema): Schema
    {
        return SourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SourcesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSources::route('/'),
            'create' => CreateSource::route('/create'),
            'edit' => EditSource::route('/{record}/edit'),
        ];
    }
}
