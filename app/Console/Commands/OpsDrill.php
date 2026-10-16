<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * RED scaffold.
 */
class OpsDrill extends Command
{
    protected $signature = 'ops:drill {--fail-for=60 : Seconds the job keeps failing} {--status : Report drill state instead of queueing}';

    protected $description = 'Queue a deliberately failing job (or report drill state)';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
