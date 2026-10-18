<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One source listing one published event (REV-05, LEG-04): the primary
 * attribution or an "also listed by" link (D-12, D-25).
 *
 * Written only by App\Publishing\Actions\LinkEventSource, always with
 * forceFill, so nothing is fillable.
 *
 * @property int $id
 * @property int $event_id
 * @property int $source_id
 * @property int|null $source_item_id
 * @property bool $is_primary
 * @property string $linked_via
 * @property string|null $item_url
 * @property int $linked_by
 * @property CarbonImmutable $linked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EventSource extends Model
{
    /** The admin approved the item's draft. */
    public const VIA_APPROVED = 'approved';

    /** The admin marked the item's draft as a duplicate of the event. */
    public const VIA_DUPLICATE = 'duplicate';

    /** @var list<string> */
    public const LINKED_VIA = [self::VIA_APPROVED, self::VIA_DUPLICATE];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_primary' => false,
    ];

    /**
     * Whether a feed item is linked to an event (approved or marked as a
     * duplicate, D-11). One link per item is a UNIQUE constraint.
     */
    public static function linksItem(?int $sourceItemId): bool
    {
        return $sourceItemId !== null && self::query()->where('source_item_id', $sourceItemId)->exists();
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<SourceItem, $this>
     */
    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(SourceItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'linked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
