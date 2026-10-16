<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * RED scaffold.
 */
class OpsDrillJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public function __construct(public string $failUntil) {}

    public function handle(): void {}
}
