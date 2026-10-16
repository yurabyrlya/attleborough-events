<?php

namespace App\Filament\Resources\Events\Pages;

use App\Enums\EventOrigin;
use App\Filament\Resources\Events\EventResource;
use App\Models\User;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    /**
     * Manual entry goes through the single publishing choke point (ADM-02).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $admin */
        $admin = auth()->user();

        return app(PublishEvent::class)(PublishableEventData::fromForm($data), $admin, EventOrigin::Manual);
    }

    protected function getRedirectUrl(): string
    {
        return EventResource::getUrl('index');
    }
}
