<?php

namespace App\Filament\Resources\Events\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class OccurrencesRelationManager extends RelationManager
{
    protected static string $relationship = 'occurrences';

    public function table(Table $table): Table
    {
        return $table->columns([]); // RED scaffold: implemented in GREEN
    }
}
