<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncOccurrences extends Command
{
    protected $signature = 'calendar:sync-occurrences {--event=* : Only these event ids}';

    protected $description = 'Materialise occurrences of recurring events over the rolling horizon';

    public function handle(): int
    {
        return self::SUCCESS; // RED scaffold: implemented in GREEN
    }
}
