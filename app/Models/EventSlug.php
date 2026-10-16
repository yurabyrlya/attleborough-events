<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A slug the event used to have (EVT-02). Written only by
 * App\Publishing\EventSlugger.
 *
 * @property int $id
 * @property int $event_id
 * @property string $slug
 * @property CarbonImmutable $created_at
 * @property-read Event $event
 */
#[Fillable(['event_id', 'slug'])]
class EventSlug extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
