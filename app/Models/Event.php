<?php

namespace App\Models;

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\PriceType;
use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An event's LOCAL wall-clock schedule (exactly what the admin typed).
 *
 * Writes go only through App\Publishing\Actions (single choke point).
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string $description
 * @property int $category_id
 * @property int $venue_id
 * @property bool $family_friendly
 * @property PriceType $price_type
 * @property string|null $price_text
 * @property string|null $organiser_name
 * @property string|null $organiser_contact
 * @property string|null $organiser_website
 * @property bool $contact_public
 * @property string|null $source_url
 * @property string|null $source_note
 * @property string $timezone
 * @property bool $all_day
 * @property CarbonImmutable $start_date
 * @property string|null $start_time
 * @property CarbonImmutable|null $end_date
 * @property string|null $end_time
 * @property string|null $recurrence_rule
 * @property list<string> $excluded_dates
 * @property EventStatus $status
 * @property EventOrigin $origin
 * @property int $approved_by
 * @property CarbonImmutable $approved_at
 * @property CarbonImmutable|null $status_changed_at
 * @property int|null $status_changed_by
 *
 * Publishing-state and audit columns are never fillable: status, origin,
 * approved_by and approved_at are written only via forceFill by
 * App\Publishing\Actions\PublishEvent (WR-07); status after publish,
 * status_changed_at and status_changed_by only via forceFill by
 * App\Publishing\Actions\ChangeEventStatus.
 */
#[Fillable([
    'title', 'slug', 'description', 'category_id', 'venue_id', 'family_friendly',
    'price_type', 'price_text', 'organiser_name', 'organiser_contact', 'organiser_website',
    'contact_public', 'source_url', 'source_note', 'timezone', 'all_day', 'start_date', 'start_time', 'end_date',
    'end_time', 'recurrence_rule',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'Europe/London',
        'family_friendly' => false,
        'all_day' => false,
        'price_type' => 'unknown',
        // D-19: Phase 1 events and admin entry show the contact publicly.
        'contact_public' => true,
        // D-19: London dates (Y-m-d) an ICS series skips (EXDATE).
        'excluded_dates' => '[]',
    ];

    /**
     * The approved "new event" draft this event was published from (D-07).
     *
     * @return HasOne<EventDraft, $this>
     */
    public function draft(): HasOne
    {
        return $this->hasOne(EventDraft::class)->where('kind', DraftKind::New);
    }

    /**
     * Every source listing this event (REV-05, LEG-04), oldest link first.
     *
     * @return HasMany<EventSource, $this>
     */
    public function eventSources(): HasMany
    {
        return $this->hasMany(EventSource::class)->orderBy('linked_at')->orderBy('id');
    }

    /**
     * The main attribution (D-12, D-25): at most one per event, enforced by
     * the event_sources_one_primary index.
     *
     * @return HasOne<EventSource, $this>
     */
    public function primarySource(): HasOne
    {
        return $this->hasOne(EventSource::class)->where('is_primary', true);
    }

    /**
     * @return HasMany<EventOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class);
    }

    /**
     * Slugs this event used to have; they 301 to the current slug (EVT-02).
     *
     * @return HasMany<EventSlug, $this>
     */
    public function slugHistory(): HasMany
    {
        return $this->hasMany(EventSlug::class);
    }

    /**
     * The next occurrence that has not yet expired.
     *
     * @return HasOne<EventOccurrence, $this>
     */
    public function nextOccurrence(): HasOne
    {
        return $this->hasOne(EventOccurrence::class)->ofMany(
            ['starts_at' => 'min'],
            fn ($query) => $query->where('expires_at', '>', CarbonImmutable::now()->utc()),
        );
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'family_friendly' => 'boolean',
            'all_day' => 'boolean',
            'contact_public' => 'boolean',
            'excluded_dates' => 'array',
            'price_type' => PriceType::class,
            'status' => EventStatus::class,
            'origin' => EventOrigin::class,
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
