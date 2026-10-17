<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Publishing\Actions\ApproveDraft;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use App\Review\Exceptions\DraftNotPublishable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    /**
     * Manual entry is a `manual` draft approved at once through ApproveDraft
     * (D-07), so every event has an approved draft behind it and the review
     * path stays the only path to publication (REV-02). Writing the draft and
     * approving it share one transaction: nothing persists if either fails.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws Halt when the draft cannot be published (blockers shown as a warning)
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $admin */
        $admin = auth()->user();

        try {
            return DB::transaction(fn (): Event => app(ApproveDraft::class)(
                app(DraftWriter::class)->create(DraftData::fromManualForm($data)),
                $admin,
            ));
        } catch (DraftNotPublishable $e) {
            Notification::make()
                ->title("Can't publish yet")
                ->body(implode(' ', $e->blockers))
                ->warning()
                ->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return EventResource::getUrl('index');
    }
}
