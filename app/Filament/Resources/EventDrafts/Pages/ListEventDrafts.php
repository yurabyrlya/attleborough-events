<?php

namespace App\Filament\Resources\EventDrafts\Pages;

use App\Filament\Resources\EventDrafts\EventDraftResource;
use Filament\Resources\Pages\ListRecords;

class ListEventDrafts extends ListRecords
{
    protected static string $resource = EventDraftResource::class;

    /**
     * No create action: drafts come from submissions and manual entry.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
