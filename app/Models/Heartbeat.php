<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A liveness record (OPS-04). Never touched by HTTP routes or Filament
 * actions: only the scheduler, the queue worker and artisan commands beat.
 *
 * @property string $name
 * @property CarbonImmutable $last_seen_at
 * @property array<string, mixed>|null $meta
 */
class Heartbeat extends Model
{
    public const SCHEDULER = 'scheduler';

    public const QUEUE = 'queue';

    public const CALENDAR_SYNC = 'calendar-sync';

    public const DRILL = 'ops-drill';

    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * Record that $name is alive now. One INSERT ... ON CONFLICT (name) DO
     * UPDATE, so overlapping runs never hit a duplicate key or lose a beat.
     * Upsert bypasses casts, so the instant is passed as an explicit UTC string.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function beat(string $name, array $meta = []): void
    {
        static::query()->upsert(
            [[
                'name' => $name,
                'last_seen_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s'),
                'meta' => $meta === [] ? null : json_encode($meta, JSON_THROW_ON_ERROR),
            ]],
            ['name'],
            ['last_seen_at', 'meta'],
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'meta' => 'array',
        ];
    }
}
