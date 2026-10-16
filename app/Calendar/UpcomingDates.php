<?php

namespace App\Calendar;

use App\Models\EventOccurrence;
use Illuminate\Support\Collection;

/**
 * An event's next listed occurrences (at most the page limit) and how many
 * more listed upcoming occurrences follow them.
 */
final readonly class UpcomingDates
{
    /**
     * @param  Collection<int, EventOccurrence>  $occurrences
     */
    public function __construct(
        public Collection $occurrences,
        public int $remaining,
    ) {}

    public function first(): ?EventOccurrence
    {
        return $this->occurrences->first();
    }
}
