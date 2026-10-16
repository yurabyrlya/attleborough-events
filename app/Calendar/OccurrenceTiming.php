<?php

namespace App\Calendar;

use Carbon\CarbonImmutable;

/**
 * The concrete UTC instants for one instance of an event.
 */
final readonly class OccurrenceTiming
{
    public function __construct(
        /** London local date of the instance, 'Y-m-d' (the recurrence key). */
        public string $key,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public CarbonImmutable $expiresAt,
    ) {}
}
