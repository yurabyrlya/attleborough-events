<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * RED scaffold.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 0;

    public function handle(): void {}
}
