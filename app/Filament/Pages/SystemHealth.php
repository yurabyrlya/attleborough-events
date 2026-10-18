<?php

namespace App\Filament\Pages;

use App\Models\FailedJob;
use App\Models\User;
use App\Ops\HealthCheck;
use App\Ops\HealthReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * Admin-only System health page (OPS-04): heartbeat freshness for the
 * scheduler, the queue worker and the daily sync, the crawled sources'
 * health (SRC-08, a warning only), plus failed jobs with
 * Retry / Delete. Never renders, logs or notifies a failed job's payload.
 * Writes no heartbeat: only the scheduler, the worker and artisan do.
 */
class SystemHealth extends Page implements HasTable
{
    use InteractsWithTable {
        resolveTableRecord as protected resolveExistingTableRecord;
    }

    private const ALREADY_HANDLED = 'This job was already retried or deleted. Refresh the page.';

    /**
     * Filament's Sidebar listens for this and re-renders the navigation, which
     * recomputes getNavigationBadge() from the database. Chosen over a page
     * redirect so the toast and the table state survive.
     */
    private const REFRESH_NAVIGATION_EVENT = 'refresh-sidebar';

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

    public static function getNavigationBadge(): ?string
    {
        $count = app(HealthReport::class)->failedJobCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    /**
     * @return list<HealthCheck>
     */
    public function checks(): array
    {
        $report = app(HealthReport::class);

        return [...$report->all(), $report->sources()];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Failed jobs')
            ->query(FailedJob::query()->orderByDesc('failed_at')->orderByDesc('id'))
            ->columns([
                TextColumn::make('failed_at')
                    ->label('Failed')
                    ->dateTime('D j M, g:ia')
                    ->timezone((string) config('calendar.timezone')),
                TextColumn::make('job')
                    ->label('Job')
                    ->state(fn (FailedJob $record): string => $record->jobName()),
                TextColumn::make('queue')
                    ->label('Queue'),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->state(fn (FailedJob $record): string => $record->exceptionSummary())
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription('Send this job back to the queue. The worker runs it again within a few seconds.')
                    ->action(fn (FailedJob $record) => $this->retry($record)),
                Action::make('delete')
                    ->label('Delete')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash)
                    ->requiresConfirmation()
                    ->modalDescription('This removes the record of the failure. It cannot be undone.')
                    ->action(fn (FailedJob $record) => $this->forget($record)),
            ])
            ->emptyStateHeading('No failed jobs')
            ->emptyStateDescription('Jobs that fail after all their tries appear here.')
            ->paginated([10])
            ->defaultPaginationPageOption(10);
    }

    /**
     * A row that was already retried or deleted (another tab, the CLI)
     * resolves to an unsaved placeholder instead of silently dropping the
     * click, so the action can tell the admin what happened.
     *
     * @return Model|array<string, mixed>|null
     */
    protected function resolveTableRecord(?string $key): Model|array|null
    {
        $record = $this->resolveExistingTableRecord($key);

        if ($record === null && $key !== null && ctype_digit($key)) {
            return (new FailedJob)->forceFill(['id' => (int) $key]);
        }

        return $record;
    }

    private function retry(FailedJob $record): void
    {
        $uuid = $this->currentUuid($record);

        if ($uuid === null) {
            $this->notifyAlreadyHandled();

            return;
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Log::warning('ops: failed job retried', ['uuid' => $uuid, 'admin_id' => auth()->id()]);

        Notification::make()->success()->title('Sent back to the queue')->send();
        $this->refreshNavigationBadge();
    }

    private function forget(FailedJob $record): void
    {
        $uuid = $this->currentUuid($record);

        if ($uuid === null || Artisan::call('queue:forget', ['id' => $uuid]) !== 0) {
            $this->notifyAlreadyHandled();

            return;
        }

        Log::warning('ops: failed job deleted', ['uuid' => $uuid, 'admin_id' => auth()->id()]);

        Notification::make()->success()->title('Failed job deleted')->send();
        $this->refreshNavigationBadge();
    }

    /**
     * Re-read the uuid from the table, so a stale or forged record id cannot
     * act on another row (T-01-48).
     */
    private function currentUuid(FailedJob $record): ?string
    {
        $query = FailedJob::query()->whereKey($record->getKey());

        if ($record->exists) {
            $query->where('uuid', $record->uuid);
        }

        $uuid = $query->value('uuid');

        return is_string($uuid) ? $uuid : null;
    }

    /**
     * The count may have changed even when this click found the row gone
     * (another tab or the CLI handled it), so every path refreshes the badge.
     */
    private function notifyAlreadyHandled(): void
    {
        Notification::make()->danger()->title(self::ALREADY_HANDLED)->send();
        $this->refreshNavigationBadge();
    }

    private function refreshNavigationBadge(): void
    {
        $this->dispatch(self::REFRESH_NAVIGATION_EVENT);
    }
}
