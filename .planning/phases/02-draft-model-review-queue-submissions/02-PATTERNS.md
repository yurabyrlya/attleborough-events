# Phase 2: Draft Model, Review Queue & Submissions - Pattern Map

**Mapped:** 2026-10-17
**Files analyzed:** 74 new or modified files, grouped into 48 rows below
**Analogs found:** 66 / 74. The 8 with no analog are listed in "No Analog Found".

All analog paths below are git-tracked source. I checked them with `git ls-files`. There are no gitignored mirrors in this repo. `.ai/rules/` does not exist. `.ai/guidelines/attleborough.md` is the tracked source that `AGENTS.md` is rendered from.

---

## Corrections to RESEARCH.md found while mapping (read first)

| # | RESEARCH.md says | Codebase reality | Planner action |
|---|------------------|------------------|----------------|
| C1 | Public "Somewhere else" reveal uses Alpine (Pattern 6, Pitfall 13) | **The public site loads no Alpine.** `package.json` has no alpinejs. The only public JS is the first-party vanilla module `resources/js/share.js`, loaded with `@vite` from the page's `@push('head')`. The `vite.config.js` input list is `['resources/css/app.css', 'resources/js/share.js', 'resources/css/filament/admin/theme.css']` | New `resources/js/submit.js` copies the `share.js` pattern (data-attributes, reveal with `hidden`, no inline handlers). Add it to the `vite.config.js` `input`. Alpine is fine **in the admin only** (Filament ships it) |
| C2 | "Register the review queue, Approve & next and the Rules resource in `AdminPanelProvider`" (CONTEXT Integration Points) | `AdminPanelProvider` uses `->discoverResources(in: app_path('Filament/Resources'), ...)` and `->discoverPages(in: app_path('Filament/Pages'), ...)` (lines 39-40) | **No provider edit is needed.** Files placed under `app/Filament/Resources/EventDrafts/` and `app/Filament/Pages/Rules.php` are discovered automatically |
| C3 | Edit AGENTS.md "Public site" rule for the Turnstile exception (D-17) | `AGENTS.md` is rendered from `.ai/guidelines/attleborough.md` (line 49: `- No third-party scripts, fonts, images or CDNs...`) | Edit **both**: the source `.ai/guidelines/attleborough.md:49`, and `AGENTS.md`, so they stay identical. Otherwise the next Boost render reverts the exception |
| C4 | `NoThirdPartyAssetsTest` "checks only `/`" | Correct. Also: test 1 asserts `/` has **0 scripts** and test 4 asserts the layout contains only `@vite('resources/css/app.css')` | Keep tests 1 and 4 unchanged. Add `/submit`, `/submit/thanks` and `/privacy` to the allow-list and deny-list tests. `/submit` gets its own test that allows exactly `challenges.cloudflare.com` |
| C5 | `RateLimiter::for('submissions', …)` (no file named) | No `RateLimiter` exists yet. `AppServiceProvider::boot()` calls one private method, `pinUrlsToAppUrl()` | Add a private `configureRateLimiting()` called from `boot()`, in the same style |
| C6 | Slug collision for `/submit` | `EventSlugger::RESERVED` already includes `'submit'` (line 37) | Nothing to do. `/submit` is already safe from event slugs |

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `app/Enums/ReviewStatus.php` | model (enum + state machine) | transform | `app/Enums/EventStatus.php` | exact |
| `app/Enums/DraftKind.php`, `RejectReason.php`, `ExtractionMethod.php` | model (enum) | — | `app/Enums/EventOrigin.php` | exact |
| `app/Enums/QualityBand.php` | model (enum + HasColor + `fromScore`) | transform | `app/Enums/EventStatus.php` (label + colour) | exact |
| `app/Review/Exceptions/InvalidReviewTransition.php`, `DraftNotPublishable.php` (or under `app/Publishing/Exceptions/`) | utility (exception) | — | `app/Publishing/Exceptions/InvalidStatusTransition.php` | exact |
| `database/migrations/2026_10_17_1xxxxx_create_event_drafts_table.php` | migration | CRUD | `database/migrations/2026_10_16_000130_create_events_table.php` | exact |
| `database/migrations/…_create_category_keyword_rules_table.php`, `…_create_category_mappings_table.php`, `…_create_geofence_terms_table.php` | migration | CRUD | `2026_10_17_000200_create_event_slugs_table.php` (small table + `DB::statement` CHECK) | exact |
| `database/migrations/…_add_contact_public_and_organiser_website_to_events.php` | migration (alter) | CRUD | `2026_10_16_000150_add_status_audit_columns.php` | exact |
| `app/Models/EventDraft.php` | model | CRUD | `app/Models/Event.php` | exact |
| `app/Models/CategoryKeywordRule.php`, `CategoryMapping.php`, `GeofenceTerm.php` | model | CRUD | `app/Models/Category.php` | exact |
| `app/Models/Event.php` (modify) | model | CRUD | itself | — |
| `database/factories/EventDraftFactory.php` | test factory | — | `database/factories/EventFactory.php` | exact |
| `database/seeders/RulesSeeder.php` + `DatabaseSeeder.php` (modify) | config/seed | batch | `database/seeders/CategorySeeder.php` (but use `firstOrCreate`) | role-match |
| `config/review.php` | config | — | `config/ops.php` / `config/calendar.php` | exact |
| `config/services.php` (modify: `turnstile` block), `.env.example`, `phpunit.xml` | config | — | existing blocks in the same files | exact |
| `app/Review/Data/DraftData.php` | DTO | transform | `app/Publishing/Data/PublishableEventData.php` | exact |
| `app/Review/Data/{CategoryDecision,QualityResult,VenueSuggestion}.php` | DTO (readonly value) | transform | `app/Ops/HealthCheck.php` (small `final readonly` value object) | exact |
| `app/Review/DraftWriter.php` | service (only writer of draft data) | CRUD | `app/Publishing/Actions/PublishEvent.php` | role-match |
| `app/Review/CategoryAssigner.php`, `QualityScorer.php`, `DraftPublishability.php` | service (pure, deterministic) | transform | `app/Calendar/ManualRecurrence.php` / `app/Publishing/EventSlugger.php` | role-match |
| `app/Review/VenueMatcher.php` | service | request-response (DB query) | `app/Calendar/PublicOccurrenceQuery.php` | role-match |
| `app/Review/ReviewQueue.php` | service (query object) | CRUD read | `app/Calendar/PublicOccurrenceQuery.php` | exact |
| `app/Review/ReferenceCode.php` | utility | transform | `app/Publishing/EventSlugger.php` (`isTaken` loop) | role-match |
| `app/Publishing/Actions/ApproveDraft.php` | action (write choke point) | CRUD (transaction) | `app/Publishing/Actions/PublishEvent.php` + `ChangeEventStatus.php` | exact |
| `app/Publishing/Actions/RejectDraft.php`, `ReopenDraft.php` | action | CRUD (lock + transition) | `app/Publishing/Actions/ChangeEventStatus.php` | exact |
| `app/Publishing/Actions/SaveRules.php` (or `app/Review/SaveRules.php`) | action | batch (sync tables in one tx) | `app/Publishing/Actions/ChangeEventStatus.php` (AdminGate + tx) | role-match |
| `app/Publishing/Data/PublishableEventData.php` (modify: `fromDraft`, `organiserWebsite`, `contactPublic`) | DTO | transform | itself (`fromForm`, `fromEvent`) | — |
| `app/Support/EventPageData.php`, `OccurrenceCardData.php` (modify) | view model | transform | itself | — |
| `resources/views/events/show.blade.php`, `calendar/partials/occurrence-card.blade.php` (modify) | component (view) | request-response | itself | — |
| `app/Filament/Resources/Events/Pages/CreateEvent.php` (modify) | controller (Filament page) | CRUD | itself | — |
| `app/Filament/Resources/Events/Schemas/EventForm.php` (modify: extract section builders) | component (schema) | — | itself | — |
| `app/Filament/Resources/EventDrafts/EventDraftResource.php` | controller (resource) | CRUD | `app/Filament/Resources/Events/EventResource.php` | exact |
| `app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php` | component (table) | CRUD read + bulk | `app/Filament/Resources/Events/Tables/EventsTable.php` | exact |
| `app/Filament/Resources/EventDrafts/Schemas/EventDraftForm.php` | component (schema) | — | `app/Filament/Resources/Events/Schemas/EventForm.php` | exact |
| `app/Filament/Resources/EventDrafts/Schemas/EventDraftInfolist.php` | component (schema) | — | `EventForm.php` (`TextEntry`, `Callout` usage) | role-match |
| `app/Filament/Resources/EventDrafts/Actions/ReviewActions.php` | component (actions) | request-response | `app/Filament/Resources/Events/Actions/StatusActions.php` | exact |
| `app/Filament/Resources/EventDrafts/Pages/ListEventDrafts.php` | controller (page) | CRUD read | `app/Filament/Resources/Events/Pages/ListEvents.php` | exact |
| `app/Filament/Resources/EventDrafts/Pages/EditEventDraft.php` | controller (page) | CRUD | `app/Filament/Resources/Events/Pages/EditEvent.php` | exact |
| `app/Filament/Resources/EventDrafts/Pages/ReviewEventDraft.php` + `resources/views/filament/resources/event-drafts/pages/review-event-draft.blade.php` | controller (custom record page) + view | request-response, event-driven (keys) | `app/Filament/Pages/SystemHealth.php` + `resources/views/filament/pages/system-health.blade.php` | role-match |
| `app/Filament/Pages/Rules.php` + `resources/views/filament/pages/rules.blade.php` | controller (custom page with form) | batch | `app/Filament/Pages/SystemHealth.php` | role-match |
| `app/Http/Controllers/SubmissionController.php` | controller | request-response (PRG) | `app/Http/Controllers/EventController.php` | role-match |
| `app/Http/Requests/StoreSubmissionRequest.php` | middleware/validation | request-response | none (first FormRequest) | no analog |
| `app/Submissions/TurnstileVerifier.php` | service (external HTTP) | request-response | none (first outbound HTTP client) | no analog |
| `app/Submissions/Honeypot.php` + `resources/views/components/submit/honeypot.blade.php` | utility + component | request-response | `resources/views/components/brand/logo.blade.php` (anonymous component) | partial |
| `routes/web.php` (modify) | route | request-response | itself | — |
| `routes/console.php` (modify: `model:prune`) | config (schedule) | batch | itself (`calendar:sync-occurrences` block) | exact |
| `app/Providers/AppServiceProvider.php` (modify: rate limiter) | provider | — | itself (`pinUrlsToAppUrl`) | — |
| `resources/views/submit/create.blade.php`, `submit/thanks.blade.php`, `privacy.blade.php` | component (public page) | request-response | `resources/views/events/show.blade.php` + `layouts/app.blade.php` | role-match |
| `resources/js/submit.js` + `vite.config.js` (modify) | component (progressive JS) | event-driven | `resources/js/share.js` | exact |
| `resources/views/errors/429.blade.php` | component (error page) | — | `resources/views/errors/404.blade.php` | exact |
| `resources/views/layouts/app.blade.php` (modify: footer links) | component (layout) | — | itself | — |
| `.ai/guidelines/attleborough.md` + `AGENTS.md` (modify) | config (rules) | — | itself | — |
| Tests: `tests/Unit/Enums/ReviewStatusTransitionsTest.php` | test | — | `tests/Unit/Enums/StatusTransitionsTest.php` | exact |
| Tests: `tests/Feature/Review/{ApproveDraftTest,RejectReopenDraftTest,DraftWriterTest,PruneRejectedDraftsTest}.php` | test | — | `tests/Feature/Publishing/PublishEventTest.php`, `ChangeEventStatusTest.php` | exact |
| Tests: `tests/Unit/Review/{CategoryAssignerTest,QualityScorerTest}.php` | test (pure) | — | `tests/Unit/Calendar/ManualRecurrenceTest.php` | role-match |
| Tests: `tests/Feature/Filament/{ReviewQueueTest,ReviewEventDraftTest,EditEventDraftTest,RulesPageTest}.php` | test (Livewire) | — | `tests/Feature/Filament/StatusActionsTest.php`, `CreateEventTest.php` | exact |
| Tests: `tests/Feature/Filament/CreateEventTest.php` (modify) | test | — | itself | — |
| Tests: `tests/Feature/Submissions/{SubmitEventTest,SpamDefenceTest,TurnstileVerifierTest}.php`, `tests/Feature/Public/{PrivacyPageTest,ContactVisibilityTest}.php` | test (HTTP) | — | `tests/Feature/Public/EventPageContentTest.php` / `ErrorPagesTest.php` | role-match |
| Tests: `tests/Feature/Public/NoThirdPartyAssetsTest.php` (modify) | test | — | itself | — |
| Tests: `tests/Browser/{SubmitEventTest,ReviewQueueTest,RulesTest}.php` + `tests/Browser/UatFixtures.php` (modify) | test (browser UAT) | — | `tests/Browser/AdminCreateEventTest.php`, `PublicCalendarTest.php` | exact |
| `docs/uat/browser-uat.md` (modify: F3 tweak, F11-F14) | docs | — | itself ("Adding flows in later phases", lines 191-229) | exact |
| `tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php` | test (file scan) | file-I/O | `tests/Unit/Ops/EnvExampleTest.php` (reads a repo file) | partial |
| `docs/research/event-sources.md`, `tests/Fixtures/sources/{slug}/…` + `manifest.json` | docs / fixtures | file-I/O | none | no analog |

---

## Pattern Assignments

### `app/Enums/ReviewStatus.php` (enum + state machine)

**Analog:** `app/Enums/EventStatus.php` (lines 1-81). Copy the whole shape: `transitions()` / `canTransitionTo()` / `assertCanTransitionTo()` / `getLabel()` / `getColor()`.

```php
namespace App\Enums;

use App\Publishing\Exceptions\InvalidStatusTransition;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EventStatus: string implements HasColor, HasLabel
{
    case Published = 'published';
    case Hidden = 'hidden';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Published => [self::Hidden, self::Cancelled],
            self::Hidden => [self::Published],
            self::Cancelled => [self::Published],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    /** @throws InvalidStatusTransition */
    public function assertCanTransitionTo(self $to): void
    {
        if (! $this->canTransitionTo($to)) {
            throw new InvalidStatusTransition($this, $to);
        }
    }
    // getLabel(): match → 'Published' …; getColor(): match → 'success' | 'gray' | 'danger'
}
```
ReviewStatus table (RESEARCH Pattern 1): `PendingReview => [Approved, Rejected, Duplicate, Superseded]`, `Rejected => [PendingReview]`, the others `=> []`. Throw a new `InvalidReviewTransition`, not `InvalidStatusTransition`, because the latter's constructor type is `EventStatus|OccurrenceStatus`.

### `app/Enums/{DraftKind,RejectReason,ExtractionMethod,QualityBand}.php`

**Analog:** `app/Enums/EventOrigin.php` (lines 1-21). Reuse `EventOrigin` itself for `event_drafts.origin`.

```php
enum EventOrigin: string implements HasLabel
{
    case Manual = 'manual';
    case Submission = 'submission';
    case Discovered = 'discovered';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual entry',
            self::Submission => 'Public submission',
            self::Discovered => 'Discovered',
        };
    }
}
```
`QualityBand` also implements `HasColor` (high `success`, medium `warning`, low `danger`) and adds `public static function fromScore(int $score): self`. Labels are plain British English (D-11 presets: "Spam", "Out of area", "Not an event", "Past or expired", "Duplicate of an existing event", "Incomplete or can't be checked", "Other").

### `app/Review/Exceptions/InvalidReviewTransition.php`, `DraftNotPublishable.php`

**Analog:** `app/Publishing/Exceptions/InvalidStatusTransition.php` (lines 1-22):
```php
final class InvalidStatusTransition extends DomainException
{
    public function __construct(
        public readonly EventStatus|OccurrenceStatus $from,
        public readonly EventStatus|OccurrenceStatus $to,
    ) {
        parent::__construct("Can't change status from {$from->value} to {$to->value}");
    }
}
```
`DraftNotPublishable` carries `public readonly array $blockers` (`list<string>`, plain-English) and joins them into the message.

---

### `database/migrations/…_create_event_drafts_table.php` (migration)

**Analog:** `database/migrations/2026_10_16_000130_create_events_table.php` (lines 1-56).

**Structure:** class docblock explaining the local wall-clock schedule, `Schema::create`, then one `DB::statement` CHECK per enum, after the create (lines 17-50):
```php
Schema::create('events', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    ...
    $table->foreignId('category_id')->constrained()->restrictOnDelete();
    $table->foreignId('venue_id')->constrained()->restrictOnDelete();
    $table->string('price_type', 16)->default('unknown');
    ...
    $table->string('timezone', 64)->default('Europe/London');
    $table->boolean('all_day')->default(false);
    $table->date('start_date');
    $table->time('start_time')->nullable();
    $table->date('end_date')->nullable();
    $table->time('end_time')->nullable();
    $table->text('recurrence_rule')->nullable();
    $table->string('status', 16);
    $table->string('origin', 16);
    $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
    $table->timestampTz('approved_at');
    $table->timestampsTz();

    $table->index('status');
});

DB::statement("ALTER TABLE events ADD CONSTRAINT events_price_type_check CHECK (price_type IN ('free', 'paid', 'unknown'))");
DB::statement("ALTER TABLE events ADD CONSTRAINT events_origin_check CHECK (origin IN ('manual', 'submission', 'discovered'))");
```
For drafts: same column types, but the schedule fields are **nullable** (`start_date` nullable for Phase 3). Use `foreignId('venue_id')->nullable()->constrained()->restrictOnDelete()`, the nullable user FK pattern `foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete()` (copied from `add_status_audit_columns.php:20`) and jsonb defaults from `create_venues_table.php:20`: `$table->jsonb('parse_errors')->default(DB::raw("'[]'::jsonb"));`. The partial unique index has no Blueprint API, so use `DB::statement("CREATE UNIQUE INDEX event_drafts_one_new_per_event ON event_drafts (event_id) WHERE kind = 'new'")`. Name constraints `event_drafts_{column}_check`.

### `database/migrations/…_create_category_keyword_rules_table.php` (and mappings, geofence_terms)

**Analog:** `database/migrations/2026_10_17_000200_create_event_slugs_table.php` (lines 16-28). It shows a small table, a docblock and a raw `DB::statement` after the create:
```php
Schema::create('event_slugs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('event_id')->constrained()->cascadeOnDelete();
    $table->string('slug', 100)->unique();
    $table->timestampTz('created_at')->useCurrent();
    $table->index('event_id');
});

DB::statement("ALTER TABLE event_slugs ADD CONSTRAINT event_slugs_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
```
Expression unique indexes (`unique(lower(keyword))`) → `DB::statement('CREATE UNIQUE INDEX category_keyword_rules_keyword_unique ON category_keyword_rules (lower(keyword))')`. `category_id` uses `->restrictOnDelete()` (Pitfall 10 backstop).

### `database/migrations/…_add_contact_public_and_organiser_website_to_events.php`

**Analog:** `database/migrations/2026_10_16_000150_add_status_audit_columns.php` (lines 8-33): `Schema::table` in `up()`, the exact reverse in `down()`, and a docblock naming who writes the columns.
```php
Schema::table($tableName, function (Blueprint $table) {
    $table->timestampTz('status_changed_at')->nullable();
    $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
});
```
Columns: `$table->string('organiser_website', 2048)->nullable();` and `$table->boolean('contact_public')->default(true);`. The default `true` keeps Phase 1 output unchanged (D-19).

---

### `app/Models/EventDraft.php` (model)

**Analog:** `app/Models/Event.php` (lines 1-158).

**Docblock + `#[Fillable]` with review state excluded** (lines 17-61):
```php
/**
 * An event's LOCAL wall-clock schedule (exactly what the admin typed).
 *
 * Writes go only through App\Publishing\Actions (single choke point).
 *
 * @property int $id
 * ...
 * Publishing-state and audit columns are never fillable: status, origin,
 * approved_by and approved_at are written only via forceFill by
 * App\Publishing\Actions\PublishEvent (WR-07); ...
 */
#[Fillable([
    'title', 'slug', 'description', 'category_id', 'venue_id', 'family_friendly',
    'price_type', 'price_text', 'organiser_name', 'organiser_contact', 'source_url',
    'source_note', 'timezone', 'all_day', 'start_date', 'start_time', 'end_date',
    'end_time', 'recurrence_rule',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'timezone' => 'Europe/London',
        'family_friendly' => false,
        'all_day' => false,
        'price_type' => 'unknown',
    ];
```
For EventDraft, these columns are **not fillable**: `reference, origin, kind, review_status, category_id, category_reason, category_flagged, quality_*, reject_reason, review_notes, reviewed_by, reviewed_at, event_id, duplicate_of_event_id`. Only `DraftWriter` and the review actions set them with `forceFill`.

**Relations + casts** (lines 105-157). Copy the `@return BelongsTo<Venue, $this>` docblocks and the immutable casts:
```php
protected function casts(): array
{
    return [
        'family_friendly' => 'boolean',
        'all_day' => 'boolean',
        'price_type' => PriceType::class,
        'status' => EventStatus::class,
        'origin' => EventOrigin::class,
        'start_date' => 'immutable_date',
        'end_date' => 'immutable_date',
        'approved_at' => 'immutable_datetime',
        ...
    ];
}
```
Add `'quality_reasons' => 'array'` and `'parse_errors' => 'array'` (cast like `Venue::casts()` `'aliases' => 'array'`), enum casts for `kind`, `review_status`, `reject_reason`, `quality_band`, `extraction_method`, and `'reviewed_at' => 'immutable_datetime'`. Add `use MassPrunable;` with `prunable()` per RESEARCH Code Examples (D-12).

### `app/Models/Event.php` (modify)

Add `'organiser_website', 'contact_public'` to the `#[Fillable]` list (line 56-61), a `@property` for each, `'contact_public' => 'boolean'` in `casts()`, and `'contact_public' => true` in `$attributes`. Add a `draft(): HasOne<EventDraft>` relation (`hasOne(EventDraft::class)->where('kind', 'new')`) if the review UI links back.

### `app/Models/{CategoryKeywordRule,CategoryMapping,GeofenceTerm}.php`

**Analog:** `app/Models/Category.php` (lines 1-36): `#[Fillable([...])]`, `HasFactory` with the generic docblock, a `BelongsTo` with the generic return docblock, and `casts()` returning `array<string, string>`. Add `Category::keywordRules(): HasMany` and `mappings(): HasMany` if useful.

### `database/factories/EventDraftFactory.php`

**Analog:** `database/factories/EventFactory.php` (lines 15-56). Copy the "Test-only factory" docblock, `Category::factory()` / `Venue::factory()` relations, wall-clock schedule defaults (`'start_date' => '2026-10-24'`, `'start_time' => '19:30:00'`) and enum values. Add states: `submission()`, `manual()`, `rejected(RejectReason $reason, CarbonImmutable $at)`, `freeTextVenue()` (`venue_id => null` + `venue_name/address/postcode`), `withRepeatsText()`.

### `database/seeders/RulesSeeder.php`

**Analog:** `database/seeders/CategorySeeder.php` (lines 9-39). Copy the `public const` list with an array-shape docblock and the loop. **Change** `updateOrCreate` to `firstOrCreate` (RESEARCH Pitfall 9: admin edits must survive a re-seed). Look up categories by `Str::slug($name)`, e.g. `Category::where('slug', 'community')`.
```php
/** @var list<array{0: string, 1: string}> */
public const CATEGORIES = [ ['Family', 'heroicon-o-user-group'], ... ['Other', 'heroicon-o-sparkles'] ];

public function run(): void
{
    foreach (self::CATEGORIES as $index => [$name, $icon]) {
        Category::updateOrCreate(['slug' => Str::slug($name)], [...]);   // RulesSeeder: firstOrCreate
    }
}
```
`DatabaseSeeder::run()` (line 20): add `$this->call(RulesSeeder::class);` after `CategorySeeder`. Keep its "reference data only" docblock true.

### `config/review.php`

**Analog:** `config/ops.php` (lines 1-30). A banner comment, then one line comment per key, plain integers, and "These are code, not secrets, so there are no env lookups." Keys: `quality.weights.*`, `quality.bands` (80/50), `retention_days.spam` 30 / `.default` 90, `submission.min_seconds` 3, `submission.max_age_minutes` 120, `submission.per_hour` 5, `submission.per_day` 10, `venue_similarity_threshold` 0.3. Turnstile secrets go in `config/services.php` (env-backed), never here.

### `config/services.php` (modify)

Copy the shape of the existing `'postmark' => ['key' => env('POSTMARK_API_KEY')]` block (lines 17-19):
```php
'turnstile' => [
    'site_key' => env('TURNSTILE_SITE_KEY'),
    'secret' => env('TURNSTILE_SECRET_KEY'),
    'action' => 'submit',
    'hostnames' => array_filter(explode(',', (string) env('TURNSTILE_HOSTNAMES', ''))),
],
```
`.env.example`: add the Cloudflare test keys next to `ADMIN_EMAIL=` (line 64). `phpunit.xml`: leave `TURNSTILE_SITE_KEY` empty so no widget renders in tests (line 28 already sets `CACHE_STORE=array`, which the rate limiter needs).

---

### `app/Review/Data/DraftData.php` (DTO)

**Analog:** `app/Publishing/Data/PublishableEventData.php` (lines 1-288). It is `final readonly`, uses constructor promotion with defaults, and has static named constructors from form arrays plus private normalisers.

**Named constructor from form state** (lines 135-163):
```php
/** @param  array<string, mixed>  $data  Validated Filament form state */
public static function fromForm(array $data): self
{
    $allDay = (bool) ($data['all_day'] ?? false);
    $priceType = $data['price_type'] ?? PriceType::Unknown;

    return new self(
        title: trim((string) $data['title']),
        ...
        startTime: $allDay ? null : self::nullableString($data['start_time'] ?? null),
        priceType: $priceType instanceof PriceType ? $priceType : PriceType::from((string) $priceType),
        ...
        timezone: (string) config('calendar.timezone', 'Europe/London'),
        repeats: RepeatPattern::from((string) ($data['repeats'] ?? RepeatPattern::None->value)),
        ...
    );
}
```
**Normalisers to copy verbatim** (lines 247-287): `nullableString()`, `nullableInt()`, `date()`, `nullableDate()`.

**`toAttributes()`** (lines 216-245): note how `recurrence_rule` is built, `$this->recurrence()->toRrule($this->startLocal(), $this->allDay)`. DraftData stores the RRULE the same way (Anti-pattern: never store `RepeatPattern`). Named constructors: `fromSubmission(array $validated)` (public form keys), `fromManualForm(array $data)` (EventForm keys, origin Manual), `fromReviewForm(array $data)`.

Unlike `PublishableEventData`, DraftData must **not** throw on incomplete data. Drafts may lack fields, and those gaps become quality reasons and blockers.

### `app/Publishing/Data/PublishableEventData.php` (modify)

- Add constructor params `public ?string $organiserWebsite = null, public bool $contactPublic = true` after `organiserContact` (line 38). These are named-arg safe: every existing caller (tests, `UatFixtures::publish`) uses named args.
- Widen the invariant at lines 111-113:
  ```php
  if (self::nullableString($this->sourceUrl) === null && self::nullableString($this->organiserContact) === null) {
      throw new InvalidArgumentException('Add a source URL or an organiser contact.');
  }
  ```
  so it also accepts `organiserWebsite`.
- Add `'organiser_website'` and `'contact_public'` to `toAttributes()` (lines 222-244).
- Add `public static function fromDraft(EventDraft $draft): self`, modelled on `fromEvent()` + `editableRecurrence()` (lines 165-206). Build `startLocal` with `CarbonImmutable::createFromFormat('!Y-m-d', …, $draft->timezone)` and recover the form enums with `ManualRecurrence::tryFromStoredRule($draft->recurrence_rule, $start, $draft->all_day)`. A `null` result is a blocker (Pitfall 11). Map `organiser_email → organiserContact`.

### `app/Review/Data/{CategoryDecision,QualityResult,VenueSuggestion}.php`

**Analog:** `app/Ops/HealthCheck.php` (lines 11-21):
```php
final readonly class HealthCheck
{
    public function __construct(
        public string $key,
        public string $title,
        public HealthState $state,
        ...
        public ?string $detail = null,
    ) {}
```
Example: `CategoryDecision(public ?int $categoryId, public string $reason, public bool $flagged)`. Give `QualityResult` a `/** @param list<string> $reasons */` docblock.

---

### `app/Review/DraftWriter.php` (service, only writer of draft field data)

**Analog:** `app/Publishing/Actions/PublishEvent.php` (lines 33-64). It is `final`, injects collaborators through the constructor, wraps the write in `DB::transaction`, uses `new Model($dto->toAttributes())` then `forceFill([...state...])` then `save()`, and documents in the docblock that it is the only writer.
```php
final class PublishEvent
{
    public function __construct(
        private OccurrenceGenerator $occurrences,
        private EventSlugger $slugger,
    ) {}

    public function __invoke(PublishableEventData $data, User $approver, EventOrigin $origin): Event
    {
        AdminGate::authorize($approver, 'Only the admin can publish events.');

        return DB::transaction(function () use ($data, $approver, $origin): Event {
            $event = new Event($data->toAttributes());
            $event->forceFill([
                'status' => EventStatus::Published,
                'origin' => $origin,
                'approved_by' => $approver->id,
                'approved_at' => now()->utc(),
            ]);
            $this->slugger->assign($event);
            $event->save();
            $this->occurrences->sync($event);

            return $event;
        });
    }
}
```
DraftWriter **does not** call `AdminGate` in `create()`, because public submissions are anonymous. `update()` is only reached from admin pages and actions that already passed the gate. Say this in the docblock.

### `app/Review/CategoryAssigner.php`, `QualityScorer.php`, `DraftPublishability.php` (pure services)

**Analog:** `app/Calendar/ManualRecurrence.php`. It is deterministic, keeps user-facing strings in code, returns plain-English labels (`label()`, `optionsFor()` lines 180-210) and has a "single check behind X, Y and Z so they cannot disagree" docblock. Compare `PublishableEventData::editableRecurrence()` (lines 189-206), which is documented as "The single check behind UpdatePublishedEvent::canEdit(), fromEvent() and the events table's Repeats label". `DraftPublishability::blockers()` must carry the same single-source-of-truth docblock: it is used by the A key, the Approve button, bulk approve and `ApproveDraft`.

Look up `Other` by slug: `Category::query()->where('slug', 'other')->firstOrFail()`, the same idiom as `UatFixtures::category()` (line 44).

### `app/Review/ReviewQueue.php` and `VenueMatcher.php` (query objects)

**Analog:** `app/Calendar/PublicOccurrenceQuery.php`. It is an injectable query class with intention-revealing public methods (`paginate()`, `upcomingForEvent()`, `findListedOccurrence()`, lines 39-103) and a private static status helper. Use the same shape for `ReviewQueue::query(): Builder`, `next(EventDraft): ?EventDraft` and `previous(EventDraft): ?EventDraft`, with one ordering helper shared with `EventDraftsTable::defaultSort` (D-09). Write `VenueMatcher::suggest(string $name, ?string $postcode): ?VenueSuggestion` with `whereRaw`/`selectRaw` bindings. Never interpolate strings.

### `app/Review/ReferenceCode.php`

**Analog:** `app/Publishing/EventSlugger.php` (lines 50-54). It uses a "first free candidate" loop against a UNIQUE column:
```php
$slug = $base;
for ($n = 2; $this->isTaken($slug, $event); $n++) {
    $slug = $base.'-'.$n;
}
```
For example `WOA-` plus 6 chars from an unambiguous alphabet, regenerated while `EventDraft::where('reference', $code)->exists()`. The UNIQUE index is the backstop, as the slugger docblock (lines 27-28) notes.

---

### `app/Publishing/Actions/ApproveDraft.php` (action, CRUD transaction)

**Analogs:** `PublishEvent.php` (the outer shape, above) + `ChangeEventStatus.php` (lock + transition + log), lines 27-62:
```php
final class ChangeEventStatus
{
    /**
     * @throws AuthorizationException when the actor is not the configured admin
     * @throws InvalidStatusTransition when the enum machine refuses the move
     */
    public function __invoke(Event $event, EventStatus $to, User $actor): Event
    {
        // Defence in depth beside the panel gate (T-01-30).
        AdminGate::authorize($actor, 'Only the admin can change event status.');

        [$changed, $from] = DB::transaction(function () use ($event, $to, $actor): array {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            $from->assertCanTransitionTo($to);

            $locked->forceFill([
                'status' => $to,
                'status_changed_at' => CarbonImmutable::now(),
                'status_changed_by' => $actor->id,
            ])->save();

            return [$locked, $from];
        });

        // Ids only: never titles or contact details.
        Log::info('event status changed', [
            'event_id' => $changed->id,
            'from' => $from->value,
            'to' => $to->value,
            'user_id' => $actor->id,
        ]);

        return $changed->refresh();
    }
}
```
ApproveDraft runs the same lock, assert and forceFill steps, then checks `DraftPublishability::blockers()` and calls `($this->publish)(PublishableEventData::fromDraft($locked), $reviewer, $locked->origin)` inside the closure (a nested transaction becomes a savepoint). It stamps `review_status`, `event_id`, `reviewed_by` and `reviewed_at`. Catch `InvalidArgumentException` from the DTO constructor and rethrow it as `DraftNotPublishable` (Pitfall 11). Log ids only: `draft_id`, `event_id`, `user_id`.

### `app/Publishing/Actions/RejectDraft.php`, `ReopenDraft.php`

**Analog:** `ChangeEventStatus.php` (above). Use it exactly: AdminGate, then `lockForUpdate`, then `assertCanTransitionTo`, then `forceFill`, then a `Log::info` with ids only. Signatures: `__invoke(EventDraft $draft, User $reviewer, ?RejectReason $reason, ?string $note)` / `__invoke(EventDraft $draft, User $reviewer)`. Reopen clears `reject_reason` and keeps `review_notes`.

### `app/Publishing/Actions/SaveRules.php`

**Analog:** `ChangeEventStatus.php` for the AdminGate + `DB::transaction` frame. Inside, sync each table (delete rows missing from state, upsert the rest). Refuse to delete the `other` category or any category referenced by events/drafts with a domain exception that the page turns into a Notification. FK `restrictOnDelete` is the backstop.

---

### `app/Filament/Resources/Events/Pages/CreateEvent.php` (modify)

**Current** (lines 17-28):
```php
protected function handleRecordCreation(array $data): Model
{
    /** @var User $admin */
    $admin = auth()->user();

    return app(PublishEvent::class)(PublishableEventData::fromForm($data), $admin, EventOrigin::Manual);
}
```
**Target (D-07):** `return DB::transaction(fn (): Event => app(ApproveDraft::class)(app(DraftWriter::class)->create(DraftData::fromManualForm($data)), $admin));`. It still returns the `Event`, so `getRedirectUrl()` and every existing test keep working. Update the docblock to "Manual entry is a `manual` draft approved at once through ApproveDraft (D-07)". Map a `DraftNotPublishable` to a `Notification` + `Halt`, using the `EditEvent::refuseUnsupportedRule()` pattern (lines 128-137).

### `app/Filament/Resources/Events/Schemas/EventForm.php` (modify: extract sections)

Today `configure()` builds six `Section::make(...)` blocks inline (lines 44-277). Extract `public static function whatSection()`, `whenSection()`, `repeatsSection()`, `whereSection()`, `priceSection()` and `whoSection()`, and have `configure()` call them. Change nothing else, so the Phase 1 tests stay green. The private helpers (`repeats()`, `ends()`, `startLocal()`, `nextDates()`, `repeatOptions()`) stay private and shared. `editedEventRepeats()` / `hasUneditableRule()` check `$record instanceof Event`, so on the draft form they return null/false and the create-page behaviour applies, which is what the draft form needs.

Where section (lines 229-243). The draft form reuses this, adding a suggested default and a pre-filled create form (D-05):
```php
Select::make('venue_id')
    ->label('Venue')
    ->relationship('venue', 'name')
    ->searchable()
    ->preload()
    ->required()
    ->createOptionForm([
        TextInput::make('name')->required()->maxLength(255),
        TextInput::make('address')->required()->maxLength(255),
        TextInput::make('postcode')->required()->maxLength(10),
        TextInput::make('town')->required()->maxLength(100)->default('Attleborough'),
    ]),
```
Who & source (lines 259-276): the draft version adds `organiser_website`, a `contact_public` Toggle and the `requiredWithout` widening.

---

### `app/Filament/Resources/EventDrafts/EventDraftResource.php`

**Analog:** `app/Filament/Resources/Events/EventResource.php` (lines 1-51):
```php
class EventResource extends Resource
{
    protected static ?string $model = Event::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;
    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema { return EventForm::configure($schema); }
    public static function table(Table $table): Table { return EventsTable::configure($table); }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }
}
```
Pages: `'index'`, `'edit' => EditEventDraft::route('/{record}/edit')` and `'review' => ReviewEventDraft::route('/{record}/review')`. There is **no create page**: manual entry stays on `EventResource`. Add `infolist()` → `EventDraftInfolist::configure()`, `getGloballySearchableAttributes(): ['title', 'reference', 'organiser_name', 'venue_name']` (ADM-06), `$navigationGroup = 'Review'` (property style from `SystemHealth.php:53`, `string|UnitEnum|null`), and a pending-count `getNavigationBadge()` using the `SystemHealth::getNavigationBadge()` pattern (lines 69-74).

### `app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php`

**Analog:** `app/Filament/Resources/Events/Tables/EventsTable.php` (lines 16-60):
```php
public static function configure(Table $table): Table
{
    return $table
        ->modifyQueryUsing(fn ($query) => $query->with(['category', 'nextOccurrence']))
        ->defaultSort('start_date', 'desc')
        ->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('category.name')->label('Category'),
            TextColumn::make('nextOccurrence.starts_at')
                ->label('Next occurrence')
                ->dateTime('D j M Y, H:i')
                ->timezone('Europe/London')
                ->placeholder('None upcoming'),
            TextColumn::make('status')->badge(),
        ])
        ->filters([
            SelectFilter::make('status')->options(EventStatus::class),
        ])
        ->recordActions([
            EditAction::make(),
            ...StatusActions::forEvent(),
        ])
        ->toolbarActions([]);
}
```
For drafts: the default sort comes from `ReviewQueue` (D-09: `->defaultSort(fn (Builder $q) => ReviewQueue::applyOrder($q))`). Show `quality_band` as a badge **before** `quality_score` (band first). Add `SelectFilter`s on `kind`, `origin` (labelled "Source", D-20) and `quality_band`, a `TernaryFilter` on `duplicate_of_event_id`, a date-range `Filter` on `start_date`, and tabs (Pending / Rejected / Approved) on `ListEventDrafts` via `getTabs()`. Bulk actions go in `->toolbarActions([BulkActionGroup::make([...ReviewActions::bulk()])])`. Keep the docblock comment style that explains why there is no delete action (lines 45-53).

### `app/Filament/Resources/EventDrafts/Actions/ReviewActions.php`

**Analog:** `app/Filament/Resources/Events/Actions/StatusActions.php` (lines 27-208). It is a `final` class of static factories with labels and copy in one place. Every closure only delegates to a domain action, and `run()` maps domain exceptions to danger notifications.

**Factory + confirmation copy** (lines 136-161):
```php
return Action::make($name)
    ->label($label)
    ->color($color)
    ->visible(fn (Event $record): bool => ($from === null || $record->status === $from) && $record->status->canTransitionTo($to))
    ->requiresConfirmation()
    ->modalHeading($heading)
    ->modalDescription($description)
    ->modalSubmitActionLabel($submitLabel)
    ->modalCancelActionLabel(self::KEEP_LABEL)
    ->action(fn (Event $record) => self::run(fn (User $actor) => app(ChangeEventStatus::class)($record, $to, $actor), $successTitle, self::EVENT_CONFLICT))
    ->after(fn (Event $record) => $record->refresh());
```
**Runner** (lines 189-207):
```php
private static function run(callable $change, string $successTitle, string $conflictTitle): void
{
    $actor = auth()->user();

    if (! $actor instanceof User) {
        throw new AuthorizationException('Only the admin can change status.');
    }

    try {
        $change($actor);
    } catch (InvalidStatusTransition|ModelNotFoundException) {
        Notification::make()->title($conflictTitle)->danger()->send();

        return;
    }

    Notification::make()->title($successTitle)->success()->send();
}
```
For drafts: catch `InvalidReviewTransition|DraftNotPublishable|ModelNotFoundException`. The `reject` action gets `->schema([Select::make('reject_reason')->options(RejectReason::class)->placeholder('No reason'), Textarea::make('review_notes')])` and the dismiss label **"Keep as is"** (the house rule in the class docblock: "Cancel" never means "keep"). `reopen` is visible when `review_status->canTransitionTo(PendingReview)`. Bulk approve follows the RESEARCH Code Examples `BulkAction` + `reportBulkProcessingFailure()` block.

### `app/Filament/Resources/EventDrafts/Pages/ListEventDrafts.php`

**Analog:** `app/Filament/Resources/Events/Pages/ListEvents.php` (19 lines, `ListRecords` + `$resource`). Add `getTabs()` (Pending / Rejected / Approved) and no create header action.

### `app/Filament/Resources/EventDrafts/Pages/EditEventDraft.php`

**Analog:** `app/Filament/Resources/Events/Pages/EditEvent.php` (lines 38-156):
- `mutateFormDataBeforeFill()` (lines 52-64) hydrates Repeats/Ends from the stored RRULE. The draft version uses `ManualRecurrence::tryFromStoredRule(...)->toFormState()` and pre-selects the `VenueMatcher` suggestion when `venue_id` is null (D-05).
- `handleRecordUpdate()` (lines 98-106) delegates and never writes the model:
  ```php
  protected function handleRecordUpdate(Model $record, array $data): Model
  {
      $this->refuseUnsupportedRule($this->event($record));
      /** @var User $editor */
      $editor = auth()->user();
      return app(UpdatePublishedEvent::class)($this->event($record), PublishableEventData::fromForm($data), $editor);
  }
  ```
  Draft: "Save" → `DraftWriter::update()`. "Save & approve" is an extra form action that runs `DB::transaction(fn () => $approve($writer->update(...), $admin))` and then redirects to `ReviewQueue::next()`.
- Refusal pattern (lines 128-145): `Notification::make()->title(...)->warning()->send(); throw new Halt;`, used for blockers.
- The type-guard helper `event(Model $record)` (lines 147-154) becomes `draft(Model $record): EventDraft`.
- Show `repeats_text` prominently above the Repeats section as a `Callout`, using `EventForm` lines 128-133. Its "Callout escapes the description" comment applies.

### `app/Filament/Resources/EventDrafts/Pages/ReviewEventDraft.php` + view

**Analog:** `app/Filament/Pages/SystemHealth.php` (non-static `$view`, static nav props, public methods used by the Blade view, `Notification` feedback) + `resources/views/filament/pages/system-health.blade.php`.

Page properties (lines 46-58). Copy the **non-static** `$view` (Filament 5):
```php
protected string $view = 'filament.pages.system-health';
protected static ?string $title = 'System health';
```
For the record page, extend `Filament\Resources\Pages\Page`, `use InteractsWithRecord`, set `protected static string $resource = EventDraftResource::class;` and `public function mount(int|string $record): void { $this->record = $this->resolveRecord($record); }`. Header actions come from `ReviewActions` (`approve`, `reject`, `skip`, `previous`, `help`), each ending in `$this->redirect(ReviewQueue::next(...) ? EventDraftResource::getUrl('review', [...]) : EventDraftResource::getUrl('index'))`.

View shell (system-health.blade.php lines 1-30):
```blade
<x-filament-panels::page>
    <div class="grid gap-6">
        ...
        <x-filament::section :heading="$check->title">
            <x-slot name="afterHeader">
                <x-filament::badge :color="$check->state->color()">{{ $check->state->label() }}</x-filament::badge>
            </x-slot>
            ...
        </x-filament::section>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
```
Use `<x-filament::badge>` for the quality band and `<x-filament::section>` for each block. The keyboard handler is the RESEARCH Pattern 5 Alpine block (admin-only Alpine is fine). Tailwind classes work here because of the custom theme (`resources/css/filament/admin/theme.css`, quick task 261017-e46). Its `@source` globs, `app/Filament/**/*` and `resources/views/filament/**/*`, already cover the new page classes and views, so the theme needs no edit.

### `app/Filament/Pages/Rules.php` + `resources/views/filament/pages/rules.blade.php`

**Analog:** `app/Filament/Pages/SystemHealth.php` for the page skeleton, `canAccess()` (lines 63-67) and notifications:
```php
public static function canAccess(): bool
{
    $user = auth()->user();

    return $user instanceof User && $user->canAccessPanel(Filament::getPanel('admin'));
}
```
Swap `HasTable`/`InteractsWithTable` for `HasSchemas`/`InteractsWithSchemas` with a `form(Schema $schema)` using `->statePath('data')`. The single "Save rules" action calls `SaveRules`. Set `$navigationGroup = 'Review'` and `$slug = 'rules'`.

---

### `app/Http/Controllers/SubmissionController.php`

**Analog:** `app/Http/Controllers/EventController.php` (lines 31-73). It is `final`, uses constructor injection and a class docblock that spells out the URL contract and security notes (T-ids), and returns `view('…', ['page' => …])`.
```php
final class EventController
{
    public function __construct(private PublicOccurrenceQuery $query) {}

    public function __invoke(Request $request, string $slug): View|RedirectResponse
    {
        ...
        return view('events.show', ['page' => EventPageData::make($event, $dates, $now)]);
    }
}
```
Use three methods (`create`, `store`, `thanks`), not `__invoke`. `store(StoreSubmissionRequest $request, DraftWriter $writer): RedirectResponse` → `redirect()->route('submit.thanks')->with('reference', $draft->reference)` (PRG). `thanks()` redirects to `submit.create` when the flash is missing.

### `routes/web.php` (modify)

Current style (lines 8-18): one `Route::get(...)` per line with `->name()` and a comment for public URL contracts:
```php
Route::get('/', CalendarController::class)->name('calendar');

// Public URL contract (costly to change): shared links and calendar files.
Route::get('/events/{slug}', EventController::class)
    ->where('slug', '[A-Za-z0-9-]{1,120}')
    ->name('events.show');
```
Add `/submit` (GET `submit.create`, POST `submit.store` `->middleware('throttle:submissions')`), `/submit/thanks` (`submit.thanks`) and `/privacy` (`Route::view('/privacy', 'privacy')->name('privacy')`). Mark `/privacy` with the same "Public URL contract" comment (D-04: Phase 5 expands the same URL).

### `routes/console.php` (modify)

**Analog:** the block at lines 13-18. A comment says why the time avoids the DST hour:
```php
// Roll the recurring-series horizon forward daily. 03:10 London avoids the
// 01:00-02:00 DST transition hour (REC-01).
Schedule::command('calendar:sync-occurrences')
    ->dailyAt('03:10')
    ->timezone('Europe/London')
    ->withoutOverlapping();
```
Add `Schedule::command('model:prune', ['--model' => [EventDraft::class]])->dailyAt('03:40')->timezone('Europe/London');` with a comment citing D-12 (30 days spam / 90 days other, one-way).

### `app/Providers/AppServiceProvider.php` (modify)

`boot()` calls documented private methods (lines 22-25, 27-56). Add:
```php
public function boot(): void
{
    $this->pinUrlsToAppUrl();
    $this->configureRateLimiting();
}

/** SUB-04: per-IP limits on POST /submit only (config/review.php). Behind a proxy see STATE Phase 5 trusted-proxies concern. */
private function configureRateLimiting(): void
{
    RateLimiter::for('submissions', fn (Request $request): array => [
        Limit::perHour((int) config('review.submission.per_hour'))->by('ip:'.$request->ip()),
        Limit::perDay((int) config('review.submission.per_day'))->by('ip:'.$request->ip()),
    ]);
}
```

---

### `resources/views/submit/create.blade.php`, `submit/thanks.blade.php`, `privacy.blade.php` (public pages)

**Analog:** `resources/views/events/show.blade.php` (lines 1-92) + `resources/views/layouts/app.blade.php`.

Page skeleton (show.blade.php lines 1-23, 38-39):
```blade
@extends('layouts.app')

@php
    /**
     * Event page (EVT-01, EVT-02). Renders only EventPageData fields, through
     * escaped echo. ...
     */
@endphp

@section('title', $page->pageTitle)
@section('description', $page->metaDescription)

@push('head')
    <link rel="canonical" href="{{ $page->canonicalUrl }}">
    {{-- The only script on the page: the first-party share module (EVT-07). Never loaded on /. --}}
    @vite('resources/js/share.js')
@endpush

@section('content')
    <p class="mt-6"><a href="{{ route('calendar', absolute: false) }}" class="tap-target text-accent underline underline-offset-2">← All events</a></p>
```
- `/submit` pushes `@vite('resources/js/submit.js')` and, **only when `config('services.turnstile.site_key')` is filled**, `<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>` into `@push('head')` (layout line 30 `@stack('head')`). Never put it in the layout (D-17).
- Section/heading markup (show.blade.php lines 67-70): `<section class="mt-6" aria-labelledby="about-heading"><h2 id="about-heading" class="text-lg font-semibold text-ink">…</h2>`.
- Form controls: copy the label + input classes from `resources/views/events/partials/share.blade.php` (lines 22-25): `<label for="share-url" class="text-sm text-muted">…</label>` and `<input id="share-url" type="text" … class="tap-target w-full rounded-md border border-line bg-bg px-2 text-ink">`. `tap-target` (`resources/css/app.css:62-67`) gives 44px minimum targets. Primary button: `class="tap-target self-start rounded-md border border-accent px-3 font-semibold text-accent"` (share.blade.php line 16). A filled variant is planner's choice within the brand tokens `bg-accent`/`text-*`.
- Status/announcement region (share.blade.php line 28): `<div role="status" aria-live="polite" class="text-sm text-ink"></div>`.
- Disclosure without JS: `calendar/partials/filter-chips.blade.php:40` uses a native `<details class="group">`. This is an alternative for the "Somewhere else" fields if the planner prefers no JS at all (D-01).
- `<noscript>` notice (D-18) linking `mailto:{{ config('site.contact_email') }}`, styled like the footer link (layout line 46: `class="text-accent underline underline-offset-2"`).
- Voice: British English, friendly noticeboard (e.g. errors/404 copy: "It may have been taken down, or the link may be wrong.").

### `resources/js/submit.js` + `vite.config.js`

**Analog:** `resources/js/share.js` (lines 1-40). It has a docblock that says "Progressive enhancement only… work without this file and are never hidden or changed here", selects roots with data-attributes, reveals with `el.hidden = false` and adds no inline handlers:
```js
for (const root of document.querySelectorAll('[data-share]')) {
    ...
    const native = root.querySelector('[data-share-native]');
    if (native && typeof navigator.share === 'function' && ...) {
        native.hidden = false;
        native.addEventListener('click', async () => { ... });
    }
}
```
For submit.js: `[data-venue-picker]` hides `[data-venue-other]` only when JS is on and a known venue is selected. The fields are rendered visible by default (D-01). Add `'resources/js/submit.js'` to `vite.config.js` line 13 `input`.

### `resources/views/layouts/app.blade.php` (modify)

Footer (lines 44-47). Add "Add your event" (`route('submit.create')`) and "Privacy" (`route('privacy')`) links in the same `text-accent underline underline-offset-2` style, wrapped in `tap-target`. **Do not** add any `@vite` script here: `NoThirdPartyAssetsTest` test 4 asserts the layout loads only `resources/css/app.css`.

### `resources/views/errors/429.blade.php`

**Analog:** `resources/views/errors/404.blade.php` (8 lines):
```blade
@extends('errors.layout')

@section('title', 'Page not found')
@section('heading', "We can't find that page")

@section('content')
    <p class="mt-4 text-ink">It may have been taken down, or the link may be wrong. ...</p>
@endsection
```
429 copy, for example: "Too many submissions from here today. Please try again later, or email {{ config('site.contact_email') }}." `errors/layout.blade.php` is static-safe (no `route()`), so keep the 429 page free of `route()` too.

### `app/Support/EventPageData.php`, `OccurrenceCardData.php` (modify) + views

`EventPageData::make()` line 67 `$contact = self::blankToNull($event->organiser_contact);` → `$contact = $event->contact_public ? self::blankToNull($event->organiser_contact) : null;`, and add `organiserWebsiteUrl/Host` via `SafeLinks::http($event->organiser_website)`, the same as `$source` on line 68. `OccurrenceCardData::fromOccurrence()` line 40: same gate. In `events/show.blade.php`, the "Who's running it" section (lines 72-89) gets a website line in the style of the `source-link` line (line 84), with `rel="nofollow ugc noopener"`.

### `app/Submissions/Honeypot.php` + `resources/views/components/submit/honeypot.blade.php`

**Partial analog:** anonymous Blade components under `resources/views/components/` (e.g. `brand/logo.blade.php`, used as `<x-brand.logo />` in layout line 37). The hidden-field CSS goes in `resources/css/app.css` (no inline style). Use the `@utility` / `@layer base` blocks there (lines 28, 62) as the place to add `.hp-field { display: none }`.

---

### Tests

**`tests/Unit/Enums/ReviewStatusTransitionsTest.php`.** Analog: `tests/Unit/Enums/StatusTransitionsTest.php` (lines 12-44). It pins every pair, including same-to-same, with a named dataset:
```php
dataset('event status pairs', [
    'published → hidden' => [EventStatus::Published, EventStatus::Hidden, true],
    ...
]);

it('allows exactly the event status transitions in the machine table', function (EventStatus $from, EventStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
    if ($allowed) {
        $from->assertCanTransitionTo($to);
    } else {
        expect(fn () => $from->assertCanTransitionTo($to))->toThrow(InvalidStatusTransition::class);
    }
})->with('event status pairs');
```

**`tests/Feature/Review/ApproveDraftTest.php` (and Reject/Reopen/DraftWriter/Prune).** Analog: `tests/Feature/Publishing/PublishEventTest.php` (lines 17-57). Copy the file-local helper builder with `array_merge` overrides, `beforeEach` with `travelTo(...'Europe/London')` plus an admin whose email matches `phpunit.xml` `ADMIN_EMAIL=admin@example.test`, and raw-SQL UTC assertions:
```php
function utcColumn(int $occurrenceId, string $column): ?string
{
    $row = DB::selectOne("select {$column} at time zone 'UTC' as v from event_occurrences where id = ?", [$occurrenceId]);
    return $row->v === null ? null : substr((string) $row->v, 0, 19);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'Europe/London'));
    $this->admin = User::factory()->create(['email' => 'admin@example.test']);
});
```
Must-have cases: approve twice gives one event (Pitfall 2); non-admin gets `AuthorizationException` and writes nothing; recurring draft approved once gives N occurrences (REV-11), including the DST fixture Sun 25 Oct 2026; blockers throw `DraftNotPublishable`; prune at 29/31 days (spam) and 89/91 days (other).

**`tests/Feature/Filament/{ReviewQueueTest,ReviewEventDraftTest,EditEventDraftTest,RulesPageTest}.php`.** Analog: `tests/Feature/Filament/StatusActionsTest.php` (lines 20-64, 144-177) for `livewire(Page::class, ['record' => …])`, `assertActionVisible/Hidden` and table actions:
```php
use Filament\Actions\Testing\TestAction;
...
->callAction(TestAction::make('cancel')->table($row))
->assertNotified('Date cancelled');
```
and `tests/Feature/Filament/CreateEventTest.php` (lines 16-41) for `fillForm()->call('create')->assertHasNoFormErrors()` + `DB::selectOne` checks.

**`tests/Feature/Filament/CreateEventTest.php` (modify).** Add to the existing cases: `expect(EventDraft::sole())->origin->toBe(EventOrigin::Manual)->review_status->toBe(ReviewStatus::Approved)->event_id->toBe(Event::sole()->id)` (Pitfall 12).

**`tests/Feature/Submissions/*`.** HTTP tests with `$this->post(route('submit.store'), …)`, `Http::fake([TurnstileVerifier::SITEVERIFY_URL => …])` (RESEARCH Code Examples skeleton) and `freezeLondon()` from `tests/Pest.php:64-67`. `Http::preventStrayRequests()` is already global (Pest.php line 26). Include the London-midnight case `freezeLondon('2026-07-01 00:30')` (Pitfall 4).

**`tests/Feature/Public/NoThirdPartyAssetsTest.php` (modify).** Reuse `resourceUrlsIn()` (lines 20-34) and the allow-list loop (lines 51-69) as a dataset over `['/', '/privacy', '/submit/thanks']` (thanks needs a flashed reference: `withSession(['reference' => 'WOA-TEST01'])`). Add one `/submit` test: with `services.turnstile.site_key` set, the only non-own-origin URL is `https://challenges.cloudflare.com/turnstile/v0/api.js`; with it empty, there are none.

**`tests/Browser/{SubmitEventTest,ReviewQueueTest,RulesTest}.php`.** Analogs: `tests/Browser/AdminCreateEventTest.php` (lines 14-58) and `PublicCalendarTest.php` (lines 12-60):
```php
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    Uat::seedCategories();
    Uat::venue();
    $this->admin = Uat::admin();
});

it('F0: logs the admin in to the panel', function () {
    $page = visit('/admin/login')
        ->fill(Uat::label('Email address'), $this->admin->email)
        ->fill('[id="form.password"]', Uat::PASSWORD);

    Uat::settleFields($page, ['form.email' => $this->admin->email, 'form.password' => Uat::PASSWORD])
        ->click(Uat::role('button', 'Sign in'))
        ->waitForText('Dashboard')
        ...
        ->assertNoJavaScriptErrors();
});
```
- Public form (no Livewire): plain `->fill(Uat::label(...))`. `settleFields` is only needed on Filament forms.
- Bind a passing fake verifier: `app()->instance(TurnstileVerifier::class, …)` (the in-process server shares the container, Pest.php lines 37-46). Keep the site key empty so no Cloudflare request is made.
- Keyboard flows: `->keys('body', 'a')`. Assert that typing `a` in the reject textarea does **not** approve (Pitfall 1).
- Use `Uat::waitUntil()` for state that is not visible text (UatFixtures lines 132-145).

**`tests/Browser/UatFixtures.php` (modify).** Add `submissionDraft(array $overrides = []): EventDraft` built through `DraftWriter::create(DraftData::fromSubmission(...))`, i.e. through the real write path, following `publish()` (lines 57-68), and prefix titles with "UAT".

**`docs/uat/browser-uat.md` (modify).** Follow "Adding flows in later phases" (lines 191-229): same shape (steps, selectors, expected result, public effect). Add F11 submit, F12 queue/Approve & next, F13 bulk, F14 rules, and rows in the "Automating these flows" table. Update F3 to mention the manual draft row.

**`tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php`.** Partial analog: `tests/Unit/Ops/EnvExampleTest.php`. It reads a repo file in a Unit test, with a docblock citing the verification gap:
```php
it('turns the Laravel Boost browser logger off in .env.example', function () {
    $lines = preg_split('/\R/', (string) file_get_contents(base_path('.env.example')));
    expect($lines)->toContain('BOOST_BROWSER_LOGS_WATCHER=false');
});
```
Iterate `glob(base_path('tests/Fixtures/sources/*/*'))` and assert that only the placeholder email and phone values appear, and that every file is listed in its `manifest.json`. Note that `tests/Fixtures/public/` and `tests/Fixtures/views/` already exist and must not be scanned.

---

## Shared Patterns

### Admin authorisation (defence in depth)
**Source:** `app/Publishing/AdminGate.php` (lines 17-37) + the first line of every action (`ChangeEventStatus.php:36`)
**Apply to:** `ApproveDraft`, `RejectDraft`, `ReopenDraft`, `SaveRules`, and any `DraftWriter::update()` caller path
```php
// Defence in depth beside the panel gate (T-01-30).
AdminGate::authorize($actor, 'Only the admin can change event status.');
```
Filament custom pages use `canAccess()` from `SystemHealth.php:63-67`. **Do not** call AdminGate on the public submission path.

### Lock → assert transition → forceFill → log ids only
**Source:** `app/Publishing/Actions/ChangeEventStatus.php` lines 38-59
**Apply to:** every review action. A row lock inside `DB::transaction` and the enum `assertCanTransitionTo()` on the **locked** value make double-clicks and stale pages harmless. Log `*_id`, `from`, `to`, `user_id` only, never titles or contacts.

### Non-fillable state, `forceFill` only in the owning writer
**Source:** `app/Models/Event.php` docblock lines 49-55 + `PublishEvent.php:48-54`
**Apply to:** `EventDraft` (review/category/quality state), `Event` (status/origin unchanged; `contact_public` and `organiser_website` are content, so fillable).

### Filament actions delegate, notify, never write
**Source:** `app/Filament/Resources/Events/Actions/StatusActions.php` docblock lines 18-26 + `run()` lines 189-207
**Apply to:** `ReviewActions`, `ReviewEventDraft`, `EditEventDraft`, `Rules`. Confirmations name the action ("Reject draft", "Approve and publish"). The dismiss button is "Keep as is". Plain British English.

### Escaped output, safe links
**Source:** `app/Support/SafeLinks.php` (http/mailto/tel, lines 15-60); every public view uses `{{ }}`
**Apply to:** the public form (`old()` repopulation), thanks page, privacy page, event page and card contact lines, and the Filament infolist. `Callout::description()` escapes (EventForm comment line 130).

### Wall-clock dates in Europe/London
**Source:** `PublishableEventData::startLocal()` (lines 202-211), `EventForm::startLocal()` (lines 381-399)
**Apply to:** `StoreSubmissionRequest` "today" bounds (`CarbonImmutable::now('Europe/London')->toDateString()`), `DraftData`, `ReviewQueue` ordering, the prune cutoffs (`now()->utc()` instants compared with `timestampTz`). Filament columns showing `reviewed_at`/`created_at` get `->timezone('Europe/London')` (EventsTable line 30).

### Tests: Postgres, offline, frozen London time
**Source:** `tests/Pest.php` lines 23-67
**Apply to:** all new tests. `freezeLondon()`, the global `Http::preventStrayRequests()`, `RefreshDatabase` in Feature/Browser, the admin email `admin@example.test` (phpunit.xml line 36), and `CACHE_STORE=array` (line 28) so the rate limiter resets per test.

---

## No Analog Found

| File | Role | Data Flow | Reason / What to use instead |
|---|---|---|---|
| `app/Http/Requests/StoreSubmissionRequest.php` | validation | request-response | First FormRequest in the app. Use the RESEARCH Pattern 6 rule list. Mirror the `EventForm` validation messages' voice (e.g. `'after_or_equal' => 'The end date cannot be before the start date.'`, EventForm lines 92-94) |
| `app/Submissions/TurnstileVerifier.php` | service | request-response (outbound HTTP) | No outbound `Http` client yet. Use the RESEARCH Pattern 8 sketch: fail closed, test with `Http::fake` |
| `app/Submissions/Honeypot.php` (logic) | utility | request-response | No spam handling yet. Use RESEARCH Pattern 7 (`Crypt::encryptString` timing token, required presence) |
| `resources/views/filament/resources/event-drafts/pages/review-event-draft.blade.php` (keyboard handler part) | component | event-driven | No keyboard shortcuts exist. Use the RESEARCH Pattern 5 Alpine block inside the `system-health.blade.php` shell |
| `app/Filament/Pages/Rules.php` (form-with-repeaters part) | controller | batch | No custom page with a form exists (SystemHealth is table-only). Use RESEARCH Pattern 9 (Tabs + table Repeaters + TagsInput) |
| `app/Review/VenueMatcher.php` (`pg_trgm` part) | service | DB query | `pg_trgm` is enabled (`2026_10_16_000100_enable_pg_trgm.php`) but never queried. Use the RESEARCH Pattern 4 SQL with bound parameters |
| `docs/research/event-sources.md` | docs | — | New. Use the RESEARCH template (two lists per D-16) |
| `tests/Fixtures/sources/{slug}/*` + `manifest.json` | fixtures | file-I/O | New layout per D-14. Manifest schema in RESEARCH "Fixtures" |

---

## Metadata

**Analog search scope:** `app/**`, `config/**`, `database/{migrations,factories,seeders}/**`, `routes/**`, `resources/{views,js,css}/**`, `tests/{Feature,Unit,Browser}/**`, `docs/uat/**`, `.ai/guidelines/**`, `vite.config.js`, `phpunit.xml`, `.env.example`
**Files scanned:** about 190 tracked files listed; 38 read in full or in part
**Pattern extraction date:** 2026-10-17
