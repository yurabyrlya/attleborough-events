<?php

namespace App\Filament\Pages;

use App\Models\FailedJob;
use App\Models\User;
use App\Ops\HealthCheck;
use App\Ops\HealthReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin-only System health page (OPS-04): how fresh each heartbeat is.
 */
class SystemHealth extends Page implements HasTable
{
    use InteractsWithTable {
        resolveTableRecord as protected resolveExistingTableRecord;
    }

    protected string $view = 'filament.pages.system-health';

    protected static ?string $title = 'System health';

    protected static ?string $navigationLabel = 'System health';

    protected static ?string $slug = 'system-health';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 100;

    /**
     * Same rule as the panel gate (User::canAccessPanel), kept in one place.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canAccessPanel(Filament::getPanel('admin'));
    }

    /**
     * @return list<HealthCheck>
     */
    public function checks(): array
    {
        return app(HealthReport::class)->all();
    }

    /** RED scaffold. */
    public static function getNavigationBadge(): ?string
    {
        return null;
    }

    /** RED scaffold. */
    public function table(Table $table): Table
    {
        return $table
            ->query(FailedJob::query())
            ->recordActions([
                Action::make('retry')->visible(false),
                Action::make('delete')->visible(false),
            ]);
    }

    /**
     * RED scaffold: a row that is already gone resolves to an unsaved
     * placeholder so the action can report it.
     *
     * @return Model|array<string, mixed>|null
     */
    protected function resolveTableRecord(?string $key): Model|array|null
    {
        return $this->resolveExistingTableRecord($key) ?? (new FailedJob)->forceFill(['id' => (int) $key]);
    }
}
