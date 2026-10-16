<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\User;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Publishing\Data\PublishableEventData;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Edits go through the single publishing choke point (ADM-03): this page never
 * writes the model itself, and has no delete action. Status actions arrive in
 * plan 01-05.
 */
class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    /**
     * Hydrate Repeats/Ends from the stored recurrence_rule.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return PublishableEventData::fromEvent($this->event($this->getRecord()));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $editor */
        $editor = auth()->user();

        return app(UpdatePublishedEvent::class)($this->event($record), PublishableEventData::fromForm($data), $editor);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    private function event(Model $record): Event
    {
        if (! $record instanceof Event) {
            throw new InvalidArgumentException('EditEvent expects an Event record.');
        }

        return $record;
    }
}
