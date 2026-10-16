<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Ops\HealthCheck;
use App\Ops\HealthReport;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin-only System health page (OPS-04): how fresh each heartbeat is.
 */
class SystemHealth extends Page
{
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
}
