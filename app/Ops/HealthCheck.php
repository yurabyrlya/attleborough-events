<?php

namespace App\Ops;

use App\Enums\HealthState;
use Carbon\CarbonImmutable;

/**
 * One line of the System health report.
 */
final readonly class HealthCheck
{
    public function __construct(
        public string $key,
        public string $title,
        public HealthState $state,
        public ?CarbonImmutable $lastSeenAt,
        public ?int $ageSeconds,
        public string $expectation,
        public ?string $detail = null,
    ) {}

    /**
     * Last-seen instant in UK time, e.g. 'Sun 25 Oct, 1:30am'.
     */
    public function lastSeenLondon(): ?string
    {
        return $this->lastSeenAt
            ?->setTimezone((string) config('calendar.timezone'))
            ->format('D j M, g:ia');
    }

    /**
     * Relative age, e.g. '34 seconds ago'. Never negative.
     */
    public function relativeAge(): ?string
    {
        if ($this->ageSeconds === null) {
            return null;
        }

        return CarbonImmutable::now()->subSeconds($this->ageSeconds)->diffForHumans();
    }
}
