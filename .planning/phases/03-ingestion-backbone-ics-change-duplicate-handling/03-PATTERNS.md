# Phase 3: Ingestion Backbone, ICS & Change/Duplicate Handling - Pattern Map

**Mapped:** 2026-10-18
**Files analyzed:** 74 new or modified files (grouped by the 7-plan breakdown in 03-RESEARCH.md)
**Analogs found:** 63 / 74 (the other 11 have no analog in the codebase; see "No Analog Found")
**Inputs:** 03-CONTEXT.md (D-01..D-26), 03-RESEARCH.md, AGENTS.md, .ai/guidelines/attleborough.md. Every analog path below is git-tracked (checked with `git ls-files`).

> **Read first: "Conflicts: RESEARCH/CONTEXT vs actual code" (end of file).** The owner decisions D-19..D-25 came *after* research. They override parts of it: the window is 90 days, not 12 months, and `horizon_days` stays at 90.

---

## File Classification

### Plan 1: Schema and domain types
| New/Modified File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `database/migrations/*_create_sources_table.php` | migration | CRUD | `database/migrations/2026_10_17_100200_create_event_drafts_table.php` | exact |
| `database/migrations/*_create_source_runs_table.php` | migration | CRUD/batch | same (CHECKs + partial unique index lines 79-95) | exact |
| `database/migrations/*_create_source_items_table.php` | migration | CRUD | same | exact |
| `database/migrations/*_create_event_sources_table.php` | migration | CRUD | same | exact |
| `database/migrations/*_create_duplicate_decisions_table.php` | migration | CRUD | same | role-match |
| `database/migrations/*_create_crawl_hosts_table.php` | migration | shared state | `database/migrations/2026_10_16_000160_create_heartbeats_table.php` (string PK) | exact |
| `database/migrations/*_add_source_columns_to_event_drafts.php` (+ widen CHECKs, `event_drafts_one_pending_per_item`) | migration (alter) | — | `database/migrations/2026_10_17_000100_make_event_slugs_unique.php` | exact |
| `database/migrations/*_add_source_id_to_category_mappings.php` | migration (alter index) | — | same + `2026_10_17_100410_create_category_mappings_table.php:30` | exact |
| `database/migrations/*_add_excluded_dates_to_events_and_drafts.php` (D-19) | migration (alter) | — | `2026_10_17_100300_add_contact_public_and_organiser_website_to_events.php` | exact |
| `app/Models/{Source,SourceRun,SourceItem,EventSource,DuplicateDecision,CrawlHost}.php` | model | CRUD | `app/Models/EventDraft.php`, `app/Models/Heartbeat.php` (CrawlHost) | exact |
| `app/Models/EventDraft.php`, `app/Models/Event.php` (relations, casts) | model (mod) | — | self | — |
| `app/Enums/{SourceHealth,RunStatus,RunMode,RunTrigger,FetchFailureKind,IdentityStrategy,SourceItemStatus,TitleRuleMode}.php` | enum | — | `app/Enums/ReviewStatus.php` (status machine), `app/Enums/HealthState.php` (colours), `app/Enums/DraftKind.php` (label only) | exact |
| `app/Enums/ExtractionMethod.php` (+`Ics`), `app/Enums/RejectReason.php` (+`Ignored`) | enum (mod) | — | self | — |
| `database/factories/{Source,SourceRun,SourceItem}Factory.php` | factory | — | `database/factories/EventDraftFactory.php` | exact |
| `config/ingest.php` | config | — | `config/review.php`, `config/ops.php` | exact |

### Plan 2: HttpFetcher
| File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `app/Ingestion/Http/HttpFetcher.php` | service | request-response (outbound) | `app/Submissions/TurnstileVerifier.php` | role-match |
| `app/Ingestion/Http/{FetchRequest,FetchResult}.php` | DTO | — | `app/Ops/HealthCheck.php` (final readonly) | exact |
| `app/Ingestion/Http/RobotsPolicy.php`, `RobotsRules.php` | service + parser | request-response + transform | TurnstileVerifier (Http, fail closed) + `CategoryAssigner::titleContainsKeyword` (quoted pattern, never admin regex) | partial |
| `app/Ingestion/Http/HostThrottle.php` | service | DB row lock | `Heartbeat::beat` (UTC upsert) + `lockForUpdate` in `ApproveDraft` | partial |
| `app/Ingestion/Http/SsrfGuard.php`, `HostResolver.php`, `DnsHostResolver.php` | utility | transform | — | **none** |
| `app/Ingestion/Http/BodyValidator.php` | utility | transform | — | **none** |
| `app/Ingestion/Http/CanonicalUrl.php` | utility | transform | `app/Support/SafeLinks.php` | role-match |
| `app/Ingestion/RawBodyStore.php` | service | file-I/O | — (local disk root `storage/app/private`, config/filesystems.php:33-35) | **none** |

### Plan 3: ICS extractor
| File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `app/Ingestion/Ics/IcsExtractor.php` | service | transform | `app/Calendar/RecurrenceExpander.php` | exact (sabre usage) |
| `app/Ingestion/Ics/{IcsTimezoneResolver,IcsIdentity,IcsItemNormaliser}.php` | utility | transform | RecurrenceExpander, `CategoryAssigner::normalise` | role-match |
| `app/Ingestion/Data/{ExtractedItem,ExtractionResult,NormalisedFacts,ChangeVerdict,DuplicateMatch}.php` | DTO | — | `app/Review/Data/DraftData.php`, `app/Review/Data/VenueSuggestion.php` | exact |
| `app/Ingestion/Pipeline/{WindowClassifier,ContentHasher}.php` | utility | transform | `app/Calendar/OccurrenceGenerator.php:31-33` (London window) | role-match |
| `tests/Fixtures/ics/*.ics` (synthetic) | fixture | — | `tests/Fixtures/sources/*/2026-10-17-ical.ics` | exact |

### Plan 4: Pipeline and jobs
| File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `app/Ingestion/Pipeline/SourceItemRecorder.php` | service | batch/CRUD | `app/Review/DraftWriter.php` | role-match |
| `app/Ingestion/Pipeline/ChangeDetector.php`, `MissingItemDetector.php` | service | transform/batch | — (state machine shape: `ReviewStatus::transitions`) | partial |
| `app/Ingestion/Pipeline/DuplicateDetector.php` | query service | CRUD (read) | `app/Review/VenueMatcher.php` | exact |
| `app/Ingestion/Pipeline/TitleRules.php` | utility | transform | `CategoryAssigner::titleContainsKeyword` | exact |
| `app/Ingestion/Pipeline/ExcerptBuilder.php` | utility | transform | `app/Support/TextLimit.php` | exact |
| `app/Ingestion/Health/SourceHealthTracker.php` | service | event-driven | `app/Ops/HealthReport.php` (thresholds from config) | partial |
| `app/Ingestion/IngestRun.php`, `ExtractorRegistry.php`, `Extractor.php` | service | batch | `app/Console/Commands/SyncOccurrences.php` (per-item try/catch, ids-only log) | partial |
| `app/Review/DraftWriter.php` (+`createFromSource`, `replacePendingFromSource`) | service (mod) | CRUD | self `create()`/`update()` | exact |
| `app/Review/Data/DraftData.php` (+`fromSourceItem`, `excludedDates`) | DTO (mod) | — | self `fromSubmission()` | exact |
| `app/Review/CategoryAssigner.php` (source-specific mappings) | service (mod) | — | self `matchMapping()` | exact |
| `app/Jobs/FetchSourceJob.php` | job | event-driven | `app/Jobs/QueueHeartbeat.php` | role-match |
| `app/Console/Commands/{IngestDispatchDue,IngestPruneRaw}.php` | command | batch | `app/Console/Commands/SyncOccurrences.php` | exact |
| `routes/console.php` (schedule) | config | — | self lines 14-28 | exact |

### Plan 5: Publishing actions
| File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `app/Publishing/Actions/ApplySourceUpdate.php` | action | request-response (tx) | `ApproveDraft.php` + `UpdatePublishedEvent.php` | exact |
| `app/Publishing/Actions/IgnoreSourceChange.php` | action | tx | `RejectDraft.php` | exact |
| `app/Publishing/Actions/ConfirmSourceCancellation.php` | action | tx | `ApproveDraft.php` + `ChangeOccurrenceStatus.php` / `ChangeEventStatus.php` | exact |
| `app/Publishing/Actions/MarkDraftDuplicate.php` | action | tx | `RejectDraft.php` (status-only write) | exact |
| `app/Publishing/Actions/RecordNotDuplicate.php` | action | tx | `RejectDraft.php` | role-match |
| `app/Publishing/Actions/LinkEventSource.php` | action | tx | `ChangeEventStatus.php` (lock event, write one thing) | role-match |
| `app/Publishing/Actions/ApproveAllLikeThis.php` | action (loop) | batch | `EventDraftsTable::approveSelected()` lines 161-205 | exact |
| `app/Publishing/Actions/ApproveDraft.php` (call LinkEventSource + move baseline) | action (mod) | — | self | — |
| `app/Publishing/Actions/ReopenDraft.php` (refuse non-`new`, D-23) | action (mod) | — | self | — |
| `app/Publishing/Data/PublishableEventData.php` (excluded_dates) | DTO (mod) | — | self | — |
| `app/Calendar/RecurrenceExpander.php` (EXDATE) | service (mod) | — | self `describe()` | — |
| `app/Review/DraftPublishability.php` (kind-aware, duplicate_of_draft_id) | service (mod) | — | self | — |
| `app/Review/ReviewQueue.php` (D-09 urgent key) | query (mod) | — | self | — |

### Plan 6: Filament
| File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `app/Filament/Resources/Sources/SourceResource.php` | resource | CRUD | `app/Filament/Resources/Events/EventResource.php` + `EventDraftResource.php` | exact |
| `app/Filament/Resources/Sources/Schemas/SourceForm.php` (title_rules repeater) | form schema | — | `app/Filament/Pages/Rules.php` (Repeater + TableColumn) | exact |
| `app/Filament/Resources/Sources/Tables/SourcesTable.php` | table | — | `app/Filament/Resources/Events/Tables/EventsTable.php` | exact |
| `app/Filament/Resources/Sources/Pages/{ListSources,CreateSource,EditSource}.php` | page | CRUD | `Events/Pages/EditEvent.php`, `EventDrafts/Pages/ListEventDrafts.php` | role-match |
| `app/Filament/Resources/Sources/RelationManagers/RunsRelationManager.php` | relation manager | read + poll | `Events/RelationManagers/OccurrencesRelationManager.php` | exact |
| `app/Filament/Resources/Sources/Actions/SourceActions.php` (Run now, Test run, Create drafts) | actions | event-driven | `EventDrafts/Actions/ReviewActions.php`, `Events/Actions/StatusActions.php` | exact |
| `app/Filament/Resources/EventDrafts/Actions/ReviewActions.php` (apply/ignore/confirm/duplicate/not-duplicate/approveAllLikeThis) | actions (mod) | — | self | — |
| `app/Filament/Resources/EventDrafts/Schemas/EventDraftInfolist.php` (diff + duplicate panel, urgent badge) | infolist (mod) | — | self `checks()` / `source()` | — |
| `resources/views/filament/resources/event-drafts/entries/{source-diff,duplicate-match}.blade.php` | view | — | `entries/safe-link.blade.php`, `raw-content.blade.php` | exact |
| `app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php` (source filter, urgent badge, duplicate filter) | table (mod) | — | self | — |
| `app/Filament/Resources/EventDrafts/Pages/ReviewEventDraft.php` (`approveFromKeyboard` kind guard) | page (mod) | — | self | — |
| `app/Filament/Pages/Rules.php` + `app/Review/SaveRules.php` (mapping source column) | page/action (mod) | — | self | — |
| `app/Ops/HealthReport.php` (+`sources()`), `app/Filament/Pages/SystemHealth.php` | service/page (mod) | — | self `calendarSync()` | exact |

### Plan 7: Public pages, seed and UAT
| File | Role | Data Flow | Closest Analog | Match |
|---|---|---|---|---|
| `app/Support/EventPageData.php`, `app/Support/OccurrenceCardData.php` (attribution) | view model (mod) | transform | self (`SafeLinks::http` source pattern) | exact |
| `resources/views/events/show.blade.php`, `resources/views/calendar/partials/occurrence-card.blade.php` | view (mod) | — | self lines 72-91 / 38-42 | exact |
| `app/Calendar/PublicOccurrenceQuery.php:59`, `app/Http/Controllers/EventController.php:66-67` (eager-load) | query (mod) | — | self | — |
| `database/seeders/SourceSeeder.php` + `DatabaseSeeder.php` | seeder | — | `database/seeders/RulesSeeder.php` | exact |
| `tests/Feature/Ingestion/**`, `tests/Unit/Ingestion/**`, `tests/Feature/Publishing/*Source*Test.php`, `tests/Feature/Filament/Source*Test.php` | test | — | see Shared Patterns: Testing | exact |
| `tests/Browser/SourcesTest.php` + `docs/uat/browser-uat.md` (F15+) | browser test/doc | — | `tests/Browser/ReviewQueueTest.php`, `tests/Browser/UatFixtures.php` | exact |

---

## Pattern Assignments

### Plan 1: Schema and domain types

#### New-table migrations (`sources`, `source_runs`, `source_items`, `event_sources`, `duplicate_decisions`)
**Analog:** `database/migrations/2026_10_17_100200_create_event_drafts_table.php`

What to copy: a class docblock saying who writes the table, `timestampTz` for every instant, string columns with a PG `CHECK` that mirrors the enum, and partial unique indexes written in raw SQL (lines 22-29, 72-95):
```php
$table->string('kind', 16)->default('new');
$table->foreignId('duplicate_of_event_id')->nullable()->constrained('events')->nullOnDelete();
$table->jsonb('parse_errors')->default(DB::raw("'[]'::jsonb"));
$table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestampTz('reviewed_at')->nullable();
$table->timestampsTz();
// ...
DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_kind_check CHECK (kind IN ('new', 'update', 'cancellation'))");
DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_reject_reason_only_when_rejected CHECK (reject_reason IS NULL OR review_status = 'rejected')");
// One "new" draft per published event: a concurrent second approval ... fails
DB::statement("CREATE UNIQUE INDEX event_drafts_one_new_per_event ON event_drafts (event_id) WHERE kind = 'new'");
```
Apply it like this:
- `source_runs_one_active ON source_runs (source_id) WHERE status IN ('queued','running')`
- `CHECK (failure_kind IS NULL OR status='failed')`
- `event_sources` partial unique `(event_id) WHERE is_primary`
- `duplicate_decisions` `CHECK (num_nonnulls(candidate_event_id, candidate_source_item_id, candidate_draft_id) = 1)`

Use FK `nullOnDelete` for `source_items.current_draft_id` and `cascadeOnDelete` for `duplicate_decisions.draft_id`, because `EventDraft` is `MassPrunable` (Pitfall 12).

#### `crawl_hosts` migration + `CrawlHost` model
**Analog:** `database/migrations/2026_10_16_000160_create_heartbeats_table.php` (lines 9-21) and `app/Models/Heartbeat.php`

Both use a string primary key, no timestamps, and a "why a table, not the cache" docblock:
```php
Schema::create('heartbeats', function (Blueprint $table) {
    $table->string('name', 64)->primary();
    $table->timestampTz('last_seen_at');
    $table->jsonb('meta')->nullable();
});
```
```php
// app/Models/Heartbeat.php:26-32, 41-52
protected $primaryKey = 'name';
public $incrementing = false;
protected $keyType = 'string';
public $timestamps = false;
// Upsert bypasses casts, so the instant is passed as an explicit UTC string.
static::query()->upsert([[ 'name' => $name,
    'last_seen_at' => CarbonImmutable::now()->utc()->format('Y-m-d H:i:s'),
    'meta' => $meta === [] ? null : json_encode($meta, JSON_THROW_ON_ERROR) ]], ['name'], ['last_seen_at', 'meta']);
```

#### Alter migrations (event_drafts columns, widen CHECKs, category_mappings key)
**Analog:** `database/migrations/2026_10_17_000100_make_event_slugs_unique.php` (lines 45-58)
```php
Schema::table('events', function ($table) { $table->unique('slug'); });
DB::statement("ALTER TABLE events ADD CONSTRAINT events_slug_format CHECK (slug ~ '".self::FORMAT."')");
// down():
DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_slug_format');
```
Widen a CHECK by dropping and re-adding it with the same name:
- `event_drafts_extraction_method_check` gains `'ics'` (current definition: create_event_drafts_table.php:82)
- `event_drafts_reject_reason_check` gains `'ignored'` (:86)
- Add the `event_drafts_one_pending_per_item` partial unique index

For `category_mappings`: `DROP INDEX category_mappings_source_value_unique`, then `CREATE UNIQUE INDEX ... ON category_mappings (coalesce(source_id, 0), lower(source_value))`. The table's own docblock promised this (create_category_mappings_table.php:17-19).

For simple added columns, the analog is `2026_10_17_100300_add_contact_public_and_organiser_website_to_events.php:17-30`. Its docblock names the writer ("Written only by App\Publishing\Actions"), and `down()` drops the columns.

#### Models (`Source`, `SourceRun`, `SourceItem`, `EventSource`, `DuplicateDecision`)
**Analog:** `app/Models/EventDraft.php`
- Docblock with `@property` for every column, plus a "who writes this" paragraph (lines 21-86).
- `#[Fillable([...])]` attribute holding **content columns only**. Review, derived and state columns are written with `forceFill` (lines 80-93, the "WR-07 pattern"). For `SourceItem`, keep `accepted_hash`, `accepted_payload`, `review_outcome`, `missing_runs` and `current_draft_id` out of Fillable.
- `$attributes` mirrors the migration defaults (lines 106-118).
- `casts()` method: enums, `'immutable_datetime'` for timestampTz, `'array'` for jsonb (lines 207-233).
- Typed relation docblocks: `@return BelongsTo<Event, $this>` (lines 120-136).

The new `EventDraft` relations follow `duplicateOf()` (lines 133-136): `source()`, `sourceItem()`, `duplicateOfDraft()`. On `Event`, add `eventSources()` and `primarySource()`, shaped like `Event::draft()` (Event.php:86-89):
```php
return $this->hasOne(EventDraft::class)->where('kind', DraftKind::New);
```

#### Enums
**Analog (status machine):** `app/Enums/ReviewStatus.php` lines 13-49. Use it for `RunStatus` and `SourceItemStatus`.
```php
enum ReviewStatus: string implements HasColor, HasLabel
{
    case PendingReview = 'pending_review';
    public function transitions(): array { return match ($this) {
        self::PendingReview => [self::Approved, self::Rejected, self::Duplicate, self::Superseded],
        self::Rejected => [self::PendingReview],
        self::Approved, self::Duplicate, self::Superseded => [], }; }
    public function canTransitionTo(self $to): bool { return in_array($to, $this->transitions(), true); }
    public function assertCanTransitionTo(self $to): void { if (! $this->canTransitionTo($to)) { throw new InvalidReviewTransition($this, $to); } }
```
**Analog (badge colours):** `app/Enums/HealthState.php` lines 8-44. Use it for `SourceHealth` (`healthy` success, `degraded` warning, `unhealthy` danger).
**Analog (label only):** `app/Enums/DraftKind.php` / `ExtractionMethod.php`. Add `case Ics = 'ics'` → label `'Calendar feed (ICS)'`, and update the docblock (ExtractionMethod.php:7-9 already says Phases 3 and 4 widen it). `RejectReason` gains `case Ignored = 'ignored'` (RejectReason.php:15-36). Its docblock says the values match the CHECK, so the migration above has to change with it.

#### Factories
**Analog:** `database/factories/EventDraftFactory.php`
- Docblock "Test-only factory. Production ... written exclusively by ..." (lines 19-33).
- Named states (lines 75-185): `submission()`, `freeTextVenue()`, `duplicateOf(Event)`, `approvedFor(Event)`.

New states to add:
- `Source::factory()->ics()->active()`
- `SourceItem::factory()->accepted()`
- `EventDraft::factory()->fromSource(SourceItem)`, `->update(array $diff)` and `->cancellation(?string $key)`

#### `config/ingest.php`
**Analog:** `config/review.php` (header comment lines 5-13, "Plain integers ... no env lookups") and `config/ops.php`.

Exception: `contact_url` must use `env('CRAWLER_CONTACT_URL', ...)` (STATE.md blocker), following the pattern in `config/site.php:15-17`:
```php
'domain' => env('SITE_DOMAIN', 'whatsonattleborough.co.uk'),
```
Keep the duplicate threshold beside the existing venue threshold: `config/review.php:52-53` has `'venue_similarity_threshold' => 0.3`. Per D-20, **do not** add `window_months`. Read the window from `config('calendar.horizon_days')`.

---

### Plan 2: HttpFetcher

#### `app/Ingestion/Http/HttpFetcher.php` (+ RobotsPolicy fetch)
**Analog:** `app/Submissions/TurnstileVerifier.php` (lines 9-84)

What to copy:
- A public URL const, so tests can `Http::fake([CONST => ...])`.
- Fails closed and never throws for remote problems: every `Throwable` from the transport becomes a typed result.
- Has a timeout.
- The class is not `final`, so browser tests can bind a passing subclass (`UatFixtures::fakeTurnstile()` lines 81-92).
```php
try {
    $body = Http::asForm()->acceptJson()->timeout(5)->post(self::SITEVERIFY_URL, [ ... ])->throw()->json();
} catch (Throwable) {
    return ['internal-error'];
}
if (! is_array($body)) { return ['internal-error']; }
```
Return `FetchResult` / a `FetchFailureKind` enum the same way, instead of error-code strings.

**Differences from the analog** (see RESEARCH Pattern 1):
- Use `withoutRedirecting()` and run the redirect loop by hand.
- Set `connectTimeout(10)->timeout(30)`.
- Send the honest User-Agent header.
- Use `withOptions(['stream' => true, 'curl' => [CURLOPT_RESOLVE => ...]])`.

Test analog: `tests/Feature/Submissions/TurnstileVerifierTest.php`, below.

#### `FetchRequest` / `FetchResult` / `Ingestion/Data/*` DTOs
**Analog:** `app/Ops/HealthCheck.php` lines 11-21: `final readonly class` with promoted public constructor properties and small formatting helpers. `app/Review/Data/DraftData.php` lines 26-77 shows larger DTOs: named-arg construction, `@param array<string, mixed>|null` shapes, and a "never throws on incomplete data" docblock.

#### `HostThrottle` (crawl_hosts row lock)
**Analogs:** `ApproveDraft` (lock-then-check inside one transaction) and `Heartbeat::beat` (UTC strings when bypassing casts):
```php
// app/Publishing/Actions/ApproveDraft.php:49-52
[$approved, $event] = DB::transaction(function () use ($draft, $reviewer): array {
    $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
    $locked->review_status->assertCanTransitionTo(ReviewStatus::Approved);
```
Use RESEARCH Pattern 1's `reserve()` as written. Sleep **outside** the transaction with `Illuminate\Support\Sleep`. There is no existing analog for Sleep.

#### `CanonicalUrl`, `SsrfGuard` (URL policy half)
**Analog:** `app/Support/SafeLinks.php:15-32`. Follow its blank-to-null handling, lower-casing of scheme and host, http/https allowlist and `www.` strip:
```php
$parts = parse_url($url);
$scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
$host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
if (! in_array($scheme, ['http', 'https'], true) || $host === '') { return null; }
return ['url' => $url, 'host' => (string) preg_replace('/^www\./', '', $host)];
```
CanonicalUrl uses league/uri (already installed) rather than `parse_url`. SsrfGuard IP checks: RESEARCH Pattern 1 snippet. There is no codebase analog for the IP checks.

#### `RobotsRules` path matching
Never compile admin or remote text into a regex without `preg_quote`. The project pattern is `CategoryAssigner::titleContainsKeyword` (CategoryAssigner.php:71-82):
```php
$pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';
```
For robots `*` / `$`: `preg_quote` the path, then substitute `\*` → `.*` and a trailing `\$` → `$`.

---

### Plan 3: ICS extractor

#### `app/Ingestion/Ics/IcsExtractor.php`
**Analog:** `app/Calendar/RecurrenceExpander.php` (lines 17-85). This is the only existing sabre user, and the project rule makes it *the* expansion pattern.
```php
$tz = new DateTimeZone($event->timezone ?: (string) config('calendar.timezone', 'Europe/London'));
try {
    // ALWAYS pass the timezone: sabre defaults to UTC.
    $iterator = new EventIterator($calendar, $uid, $tz);
    // fastForward() skips instances whose END <= the given instant. A timed VEVENT
    // without DTEND has zero duration, so step back one second ...
    $iterator->fastForward($windowStart->subSecond());
    $dates = [];
    while ($iterator->valid() && $iterator->getDtStart() < $windowEnd) {
        $start = $iterator->getDtStart();
        if ($start >= $windowStart) {
            if (count($dates) >= self::MAX_INSTANCES) { throw new RuntimeException(...); }
            $dates[] = $start->format('Y-m-d');   // recurrence_key = London local Y-m-d
        }
        $iterator->next();
    }
} catch (NoInstancesException) {
    return [];
}
```
Copy:
- the explicit zone
- the `subSecond()` fast-forward
- the class-level instance cap const (`MAX_INSTANCES = 500`, T-01-18); never touch `Settings::$maxRecurrences`
- the `Y-m-d` key
- one catch per item

The extractor differs from the analog in three ways:
- It catches more exception types *per item* and turns them into `parseErrors` (RESEARCH Pattern 3).
- It pre-validates every TZID with `TimeZoneUtil::getTimeZone($tzid, $vcal, true)`.
- It passes the identity group array to `EventIterator`.

The window comes from `OccurrenceGenerator::sync` lines 31-33 (London `startOfDay`, plus `horizon_days`). Per D-20 the import window **is** that horizon:
```php
$windowStart = ($now ?? CarbonImmutable::now())->setTimezone($tz)->startOfDay();
$windowEnd = $windowStart->addDays((int) config('calendar.horizon_days', 90));
```

#### Normaliser / title and venue text
**Analog:** `CategoryAssigner::normalise` (CategoryAssigner.php:150-159). Use it for `title_norm` and `venue_norm` in the content hash, so the hash and the D-04 rules normalise text the same way:
```php
$normalised = Normalizer::normalize($value, Normalizer::FORM_C);
if ($normalised === false) { $normalised = $value; }
return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($normalised)));
```

#### D-19 recurrence classification (representable vs parse error)
**Analog:** `PublishableEventData::fromDraft` (PublishableEventData.php:220-230) and `ManualRecurrence::tryFromStoredRule`. Run the same round-trip check at extraction, so a draft never carries a rule that Approve would refuse:
```php
$recurrence = ManualRecurrence::tryFromStoredRule($draft->recurrence_rule, $startLocal, $draft->all_day);
if ($recurrence === null) {
    throw new InvalidArgumentException("This repeat rule can't be published from the form.");
}
```

#### Synthetic fixtures `tests/Fixtures/ics/*.ics`
**Analog:** `tests/Fixtures/sources/*/2026-10-17-ical.ics` (real feeds, CRLF and folding kept by `tests/Fixtures/sources/.gitattributes`).

**Location matters:** `tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php:65` globs `tests/Fixtures/sources/*`, and every file there must appear in that source's `manifest.json`. Put synthetic files in `tests/Fixtures/ics/` (outside the scan) and copy the `.gitattributes` rule (`-text` / `eol=crlf`). Placeholders for any contact text: `redacted@example.invalid`, `01632 960000` (same test, lines 14-15).

---

### Plan 4: Pipeline and jobs

#### `DraftWriter::createFromSource()` / `replacePendingFromSource()` (modify `app/Review/DraftWriter.php`)
**Analog:** the same file, `create()` lines 34-54 and `update()` lines 78-93:
```php
public function create(DraftData $data): EventDraft
{
    return DB::transaction(function () use ($data): EventDraft {
        $draft = new EventDraft($data->toAttributes());
        $draft->forceFill([
            'reference' => ReferenceCode::unique(),
            'origin' => $data->origin,
            'kind' => DraftKind::New,                 // <- hard-coded today (F9)
            'review_status' => ReviewStatus::PendingReview,
            'extraction_method' => $data->extractionMethod,
            'submitted_category_id' => $this->submittedCategoryId($data),
            'raw_payload' => $data->rawPayload,
            'consented_at' => $data->consentedAt?->utc(),
        ]);
        $this->derive($draft, $data);
        $draft->save();
        return $draft;
    });
}
```
```php
// update(): lock, refuse non-pending, fill, derive, save
$locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
if ($locked->review_status !== ReviewStatus::PendingReview) {
    throw new InvalidReviewTransition($locked->review_status, ReviewStatus::PendingReview);
}
```
Changes:
- New methods take `DraftKind $kind`, a `list<string> $sourceValues` (ICS CATEGORIES), and the source and item ids. These go through `forceFill`, never Fillable.
- `derive()` line 110 currently passes `[]`. Thread `$sourceValues` through:
```php
$decision = $this->categories->assign($data->preferredCategoryId, $chooser, [], $draft->title);
```
- Pitfall 3: `replacePendingFromSource()` must keep the admin's `venue_id`, a `category_id` whose reason is `DraftData::ADMIN_CATEGORY_REASON` ('Chosen by admin', DraftData.php:37), and an edited description.
- The DraftWriter docblock (lines 14-25) says `create()` has no AdminGate because submissions are anonymous. Ingestion is a system writer too, so say that in the docblock.

#### `DraftData::fromSourceItem()` (modify `app/Review/Data/DraftData.php`)
**Analog:** `fromSubmission()` (DraftData.php:129-~190): a named-argument `new self(...)` with an origin-specific privacy default. Set:
- `origin: EventOrigin::Discovered`, `extractionMethod: ExtractionMethod::Ics`
- `organiserName/organiserContact/organiserWebsite: null`, `contactPublic: false` (D-17)
- `sourceUrl: item URL` (satisfies `no_contact`, DraftPublishability.php:164-166)
- `venueId: source default venue` per D-22
- `description: ExcerptBuilder` output or the D-21 fallback
- `rawPayload: [full description, organiser, categories ...]` (internal only)

Add `excludedDates` to the constructor (lines 42-77) and to `toAttributes()` (lines 362-392). `toAttributes()` is the Fillable allowlist, so update `EventDraft` `#[Fillable]` too.

#### `app/Ingestion/Pipeline/DuplicateDetector.php`
**Analog:** `app/Review/VenueMatcher.php` (lines 8-57): a read-only query object, bound parameters only, threshold from config, ordering `score desc, id asc`, and a small result DTO (`VenueSuggestion`):
```php
$threshold = (float) config('review.venue_similarity_threshold');
$similar = Venue::query()
    ->selectRaw('id, name, similarity(name, ?) as score', [$name])
    ->whereRaw('similarity(name, ?) >= ?', [$name, $threshold])
    ->orderByDesc('score')
    ->orderBy('id')
    ->first();
return new VenueSuggestion($similar->id, $similar->name, 'similar', (float) $similar->getAttribute('score'));
```
Copy the docblock wording "Read-only ... Organiser text reaches SQL only as bound parameters" (lines 16-18). Use `VenueMatcher::exact()` for the venue leg of the fuzzy rule. Never use the `%` operator. The full query shape is RESEARCH Pattern 6. Return a `DuplicateMatch` DTO shaped like `app/Review/Data/VenueSuggestion.php`.

#### `app/Ingestion/Pipeline/TitleRules.php` (D-04)
**Analog:** `CategoryAssigner::titleContainsKeyword` (lines 71-82). Call it; do not reimplement it. CONTEXT says "whole-word, case-insensitive, like Phase 2 keyword rules", and this is that exact function.

#### `app/Ingestion/Pipeline/ExcerptBuilder.php` (PIPE-09)
**Analog:** `app/Support/TextLimit.php:20-36`. The ellipsis counts inside `$max`:
```php
$keep = max(0, $max - (int) grapheme_strlen($end));
$cut = grapheme_substr($text, 0, $keep);
return rtrim(is_string($cut) ? $cut : '').$end;
```
Strip emails, phones and leftover labels first (RESEARCH Pattern 12 regexes), then `TextLimit::graphemes($text, 200)`. The phone regex in `SourceFixturesHaveNoPersonalDataTest.php` (around line 48) is a second, already-tested UK pattern you can cross-check against.

#### `app/Jobs/FetchSourceJob.php`
**Analog:** `app/Jobs/QueueHeartbeat.php` (lines 11-39)
```php
class QueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $uniqueFor = 3600;
    public function __construct(?CarbonImmutable $queuedAt = null)
    {
        $this->queuedAt = ($queuedAt ?? CarbonImmutable::now())->utc();
        $this->onConnection('database');
        $this->onQueue('default');
    }
```
Copy:
- `ShouldBeUnique, ShouldQueue` + `Queueable`
- explicit `onConnection('database')->onQueue('default')`; the Phase 5 worker listens only on `default` (F8)
- constructor takes only scalars (`int $runId`)

Existing jobs use **properties** (`public int $tries`, `$uniqueFor`), not the `#[Tries]`/`#[UniqueFor]` attributes RESEARCH suggests. Pick one style for the phase; properties match the codebase. Add `uniqueId()`, `retryUntil()` and `maxExceptions` (F8). Keep `handle()` thin: guarded `UPDATE ... WHERE status='queued'`, then delegate to `IngestRun`.

#### `app/Console/Commands/IngestDispatchDue.php`, `IngestPruneRaw.php`
**Analog:** `app/Console/Commands/SyncOccurrences.php` (lines 21-75)
```php
protected $signature = 'calendar:sync-occurrences {--event=* : Only these event ids}';
public function handle(OccurrenceGenerator $occurrences): int
{
    $events = Event::query()->whereNotNull('recurrence_rule')->when($only !== [], ...)->lazyById(100);
    foreach ($events as $event) {
        try {
            DB::transaction(function () use ($event, $occurrences): void {
                $locked = Event::query()->whereKey($event->id)->lockForUpdate()->first();
                ...
            });
            $synced++;
        } catch (Throwable $e) {
            report($e);
            // Id and message only: never event content or organiser details (T-01-22).
            Log::error('calendar:sync-occurrences failed', ['event_id' => $event->id, 'error' => $e->getMessage()]);
            $failed[] = $event->id;
        }
    }
    Heartbeat::beat(Heartbeat::CALENDAR_SYNC, [...]);   // optional: an 'ingest-dispatch' beat
    return $failed === [] ? self::SUCCESS : self::FAILURE;
}
```
Copy:
- the signature with an id filter option (`--source=*`)
- `lazyById`, per-row transaction and lock
- ids-only logs
- exit code
- an optional heartbeat, so HealthReport can show a stale dispatcher

#### `routes/console.php` schedule
**Analog:** lines 14-28. Use London times outside the 01:00-02:00 DST hour, and `withoutOverlapping()`:
```php
Schedule::command('calendar:sync-occurrences')->dailyAt('03:10')->timezone('Europe/London')->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [EventDraft::class]])->dailyAt('03:40')->timezone('Europe/London')->withoutOverlapping();
```
Add `ingest:dispatch-due` every 10 min `withoutOverlapping()` and `ingest:prune-raw` daily. 03:40 is taken; RESEARCH suggests 03:50. Each entry gets a one-line "why" comment, as in the existing ones.

#### `SourceHealthTracker` + thresholds
**Analog:** `HealthReport::calendarSync()` (HealthReport.php:51-79): thresholds come from config, and a `match (true)` picks the state:
```php
$state = match (true) {
    $age > (int) config('ops.calendar_sync_stale_after') => HealthState::Stale,
    $failed > 0 => HealthState::Failed,
    default => HealthState::Ok,
};
```

#### `IngestRun` per-item failure isolation
Same shape as SyncOccurrences: one transaction per item (`SourceItemRecorder`). A failing item becomes a counter (`skipped_parse_error`) plus a parse error. It never fails the run. Source problems are recorded and never thrown (RESEARCH Pattern 1, Pitfall 10).

---

### Plan 5: Publishing actions

All new actions copy the **ApproveDraft skeleton**, in this order:
1. `AdminGate::authorize` *before* the transaction.
2. Re-read the draft with `lockForUpdate()`.
3. `assertCanTransitionTo` on the fresh status.
4. Check the domain rules.
5. Call an inner Publishing action (its transaction becomes a savepoint).
6. Stamp the draft with `forceFill`.
7. Log ids only, inside `DB::afterCommit`.

**Analog:** `app/Publishing/Actions/ApproveDraft.php` (lines 32-87)
```php
final class ApproveDraft
{
    public function __construct(private PublishEvent $publish, private DraftPublishability $publishability) {}

    /**
     * @throws AuthorizationException when the reviewer is not the configured admin
     * @throws InvalidReviewTransition when the draft is no longer pending review
     * @throws DraftNotPublishable when the draft lacks what publishing needs
     */
    public function __invoke(EventDraft $draft, User $reviewer): Event
    {
        // Defence in depth beside the panel gate (T-02-01).
        AdminGate::authorize($reviewer, 'Only the admin can approve drafts.');

        [$approved, $event] = DB::transaction(function () use ($draft, $reviewer): array {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $locked->review_status->assertCanTransitionTo(ReviewStatus::Approved);
            $blockers = $this->publishability->blockers($locked);
            if ($blockers !== []) { throw new DraftNotPublishable($blockers); }
            try { $data = PublishableEventData::fromDraft($locked); }
            catch (InvalidArgumentException $e) { throw new DraftNotPublishable([$e->getMessage()]); }
            $event = ($this->publish)($data, $reviewer, $locked->origin);
            $locked->forceFill([
                'review_status' => ReviewStatus::Approved,
                'event_id' => $event->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now()->utc(),
            ])->save();
            return [$locked, $event];
        });

        // Ids only: never titles or contact details. After the outermost
        // commit, so a rolled-back approval is never logged.
        DB::afterCommit(fn () => Log::info('draft approved', [
            'draft_id' => $approved->id, 'event_id' => $event->id, 'user_id' => $reviewer->id,
        ]));
        return $event;
    }
}
```

#### `ApplySourceUpdate.php` (REV-06, D-07)
**Analogs:**
- ApproveDraft (skeleton above)
- `UpdatePublishedEvent::__invoke` (UpdatePublishedEvent.php:59-86): lock event → refuse → fill allowlist → slugger → save → `occurrences->sync`
- `PublishableEventData::fromEvent()` (PublishableEventData.php:274-285) + `fromForm()` (161-199) to build "published now + diff"
- `EditEvent::handleRecordUpdate` (EditEvent.php:100-108), which already calls UpdatePublishedEvent the same way:
```php
return app(UpdatePublishedEvent::class)($this->event($record), PublishableEventData::fromForm($data), $editor);
```
Surface the existing refusals as the blocker text: `UpdatePublishedEvent::UNSUPPORTED_RULE_REFUSED` (public const, line 45). `TYPE_SWITCH_REFUSED` is `private` (line 47), so catch the `InvalidArgumentException` message instead, or make it public. `UpdatePublishedEvent::canEdit()` (lines 93-97) is the pre-check for disabling the Apply button.

`event_drafts_approved_has_event` (create_event_drafts_table.php:88) requires `event_id` on approved rows. Update and cancellation drafts already carry the target `event_id`. That is safe because `event_drafts_one_new_per_event` only covers `kind='new'` (:95).

#### `IgnoreSourceChange.php`
**Analog:** `app/Publishing/Actions/RejectDraft.php` (lines 27-68). It writes review state only, with `reject_reason` = new `RejectReason::Ignored`, then moves the item baseline (`SourceItem` lock **after** the draft):
```php
$locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
$from = $locked->review_status;
$from->assertCanTransitionTo(ReviewStatus::Rejected);
$locked->forceFill([
    'review_status' => ReviewStatus::Rejected, 'reject_reason' => $reason, 'review_notes' => $note,
    'reviewed_by' => $reviewer->id, 'reviewed_at' => now()->utc(),
])->save();
```
CHECK `event_drafts_reject_reason_only_when_rejected` (:87) means `Ignored` is valid only with `rejected`. That fits.

#### `ConfirmSourceCancellation.php` (REV-07, D-08)
**Analogs:** ApproveDraft skeleton, plus the inner calls:
- `ChangeOccurrenceStatus` (ChangeOccurrenceStatus.php:34-66) for a series date. It locks the parent event first (line 40), so take the draft lock, then let it lock event → occurrence, then lock the item last.
- `ChangeEventStatus` (ChangeEventStatus.php:33-62) for a one-off. A8: the event page "Cancelled:" prefix reads `EventStatus` (EventPageData.php:65-67).

Transition rule (`OccurrenceStatus::transitions`, OccurrenceStatus.php:21-28): `Scheduled => [Cancelled, Hidden]`, `Cancelled => [Scheduled]`. Cancelling an already-cancelled date throws `InvalidStatusTransition`. Treat that as "already cancelled": approve the draft and still move the baseline.

#### `MarkDraftDuplicate.php` (REV-05, D-10)
**Analog:** RejectDraft (status-only write). Target status `ReviewStatus::Duplicate` (allowed from PendingReview, ReviewStatus.php:30). Set `duplicate_of_event_id`, **never** `event_id`. Then call `LinkEventSource` (non-primary unless D-25 applies) and move the baseline.

#### `RecordNotDuplicate.php` (D-13 memory)
**Analog:** RejectDraft: AdminGate, then one transaction that inserts a `duplicate_decisions` row and clears `duplicate_of_*`/`duplicate_reason`/`duplicate_score` with `forceFill`. Re-score through `DraftWriter`, because quality depends on the draft fields. Ids-only `afterCommit` log.

#### `LinkEventSource.php`
**Analog:** `ChangeEventStatus` (lock the events row, write exactly one thing, ids-only log). It writes `event_sources`. D-25: when a source with the "organiser's own site" flag links, it takes primary; demote the old primary in the same transaction, which the partial unique index on `is_primary` requires.

#### `ApproveAllLikeThis.php` (D-03)
**Analog:** `EventDraftsTable::approveSelected()` (EventDraftsTable.php:161-205). It checks status, then `bulkBlockerKeys()[0]`, then `ApproveDraft`, and maps exceptions to skip keys:
```php
foreach ($records as $record) {
    if ($record->review_status !== ReviewStatus::PendingReview) { self::skip($action, 'already_reviewed'); continue; }
    $keys = $publishability->bulkBlockerKeys($record);
    if ($keys !== []) { self::skip($action, $keys[0]); continue; }
    try { $approve($record, $actor); }
    catch (InvalidReviewTransition|ModelNotFoundException) { self::skip($action, 'already_reviewed'); }
    catch (DraftNotPublishable) { self::skip($action, 'blocked'); }
}
```
Do **not** reuse `self::skip()` (lines 286-292), which calls `$action->reportBulkProcessingFailure()` on a `BulkAction` (F7). Return `array{approved: int, total: int, skipped: array<string,int>}` and build the notification from `DraftPublishability::skipMessage($key, $count)` (DraftPublishability.php:110-117). Order the scope with `ReviewQueue::applyOrder()`. This action has no AdminGate of its own beyond ApproveDraft's, so add one at the top anyway (defence in depth, matching every other action).

#### Modify `ReopenDraft.php`
Add a kind guard before `assertCanTransitionTo` (ReopenDraft.php:33-37). `ReviewStatus::Rejected => [PendingReview]` would otherwise revive an ignored update or cancellation draft (F9). Also check D-23 (`source_items.review_outcome`).

#### Modify `DraftPublishability.php`
- `SKIP_REASONS` (lines 26-36): keys must exist before `skipMessage()` sees them, or it throws `InvalidArgumentException` (line 113). Add any new key.
- `bulkBlockerKeys()` line 77: `if ($draft->duplicate_of_event_id !== null)`. Extend it to `|| $draft->duplicate_of_draft_id !== null`.
- Line 132-134 (`kind` blocker) stays as it is. Approve is for `new` drafts only, and Apply/Confirm have their own checks.
- D-21 needs **no** change to the `no_description` blocker (148-150). The fallback text fills `description`.

#### Modify `ReviewQueue.php` (D-09)
`SORT_KEY` (ReviewQueue.php:30-37). Keys that start with `(` are ordered via `orderByRaw` (applyOrder lines 50-52), so the urgent key must be parenthesised. `qualify()` (line 123) has to learn the new columns:
```php
return (string) preg_replace('/\b(start_date|start_time|created_at|id)\b/', "{$alias}.$1", $expression);
```
→ add `kind|relevant_starts_at`. Do not add bare words that also appear inside string literals: `'new'` is fine, but a column named `new` would not be.

#### Modify `RecurrenceExpander::describe()` + `PublishableEventData` (D-19 excluded_dates)
RecurrenceExpander.php:111 is `$vevent->add('RRULE', $event->recurrence_rule);`. Add one `EXDATE` per excluded date, in the same TZID/VALUE=DATE form as DTSTART (lines 99-109). `PublishableEventData::toAttributes()` (lines 329-357) is the **write allowlist** for both PublishEvent and UpdatePublishedEvent. See conflict C2: carrying `excluded_dates` naively would wipe them on every admin edit.

---

### Plan 6: Filament

#### `app/Filament/Resources/Sources/SourceResource.php`
**Analogs:** `app/Filament/Resources/Events/EventResource.php` (lines 21-80) and `EventDraftResource.php` (lines 30-124). Copy:
- `canAccess()` via `AdminGate::allows`
- the delegations `form()` → `SourceForm::configure`, `table()` → `SourcesTable::configure`
- `getRelations()` → `[RunsRelationManager::class]`
- `getPages()`
- `$navigationGroup = 'Review'` (or a new "Sources" group)
- a `Heroicon::Outlined*` navigation icon

```php
public static function canAccess(): bool
{
    $user = auth()->user();
    return $user instanceof User && AdminGate::allows($user);
}
public static function getRelations(): array { return [OccurrencesRelationManager::class]; }
public static function getPages(): array { return [
    'index' => ListEvents::route('/'), 'create' => CreateEvent::route('/create'), 'edit' => EditEvent::route('/{record}/edit'),
]; }
```
`getNavigationBadge()` (EventDraftResource.php:109-114) works as a "sources needing attention" badge (degraded, unhealthy or blocked).

#### `Schemas/SourceForm.php` (SRC-01 fields + D-04 title rules)
**Analog:** `app/Filament/Pages/Rules.php` repeaters (lines 123-176). Use a Repeater with a `->table([TableColumn::make(...)])` layout, `Hidden::make('id')`, `maxLength` from class consts, `->reorderable(false)`, `->defaultItems(0)`, and plain-English `helperText`:
```php
Repeater::make('keyword_rules')
    ->hiddenLabel()
    ->helperText("Lower numbers are checked first. Words match whole words only, so 'quiz' doesn't match 'quizzical'.")
    ->table([TableColumn::make('Keyword'), TableColumn::make('Category'), TableColumn::make('Priority')])
    ->schema([
        Hidden::make('id'),
        TextInput::make('keyword')->label('Keyword')->required()->maxLength(SaveRules::KEYWORD_MAX_LENGTH),
        Select::make('category_id')->label('Category')
            ->options(fn (): array => Category::query()->orderBy('sort')->orderBy('id')->pluck('name', 'id')->all())->required(),
    ])
    ->reorderable(false)->addActionLabel('Add keyword rule')->defaultItems(0),
```
For `title_rules` (jsonb on `sources`), use columns Mode (`Select` → `TitleRuleMode`) and Keyword. Show `IdentityStrategy` as a `Select` over the enum, never a free regex (F4). Section and Callout layout: `Filament\Schemas\Components\{Section,Callout}`. Callout example from `Events/Schemas/EventForm.php:152-156`:
```php
Callout::make("This repeat rule can't be edited here")
    ->description(fn (mixed $record): string => self::uneditableRuleDescription($record))
    ->warning()->visible(fn (string $operation, mixed $record): bool => self::hasUneditableRule($operation, $record))
    ->columnSpanFull(),
```
Use a Callout for "Blocked by robots.txt", "Crawl-delay exceeds the schedule" and the structure alert.

#### `Tables/SourcesTable.php`
**Analog:** `Events/Tables/EventsTable.php` (lines 16-59). It eager-loads in `modifyQueryUsing`, uses `->badge()` on enum status columns, `->timezone('Europe/London')` on instants, a `SelectFilter` over the enum, and has no delete:
```php
->modifyQueryUsing(fn ($query) => $query->with(['category', 'nextOccurrence']))
TextColumn::make('nextOccurrence.starts_at')->label('Next occurrence')->dateTime('D j M Y, H:i')->timezone('Europe/London')->placeholder('None upcoming'),
TextColumn::make('status')->badge(),
SelectFilter::make('status')->options(EventStatus::class),
```

#### `RelationManagers/RunsRelationManager.php`
**Analog:** `Events/RelationManagers/OccurrencesRelationManager.php` (lines 19-67): `isReadOnly(): true`, `defaultSort`, `->timezone('Europe/London')`, no header or toolbar actions. Add `->poll(fn () => ... '3s' : null)`. No `->poll()` exists anywhere in `app/` yet, so this is a new pattern (RESEARCH Pitfall 13).
```php
public function isReadOnly(): bool { return true; }
public function table(Table $table): Table
{
    return $table->defaultSort('starts_at', 'asc')
        ->columns([ TextColumn::make('starts_at')->label('Starts')->dateTime('D j M Y, H:i')->timezone('Europe/London')->sortable(), ... ])
        ->headerActions([])->recordActions(StatusActions::forOccurrence())->toolbarActions([]);
}
```

#### `Actions/SourceActions.php` (Run now, Test run, Create drafts)
**Analogs:** `Events/Actions/StatusActions.php` (eventAction lines 135-158, run lines 187-208) and `ReviewActions::run` (lines 305-332). The closures stay rule-free: they delegate to a domain service, map known exceptions to a danger notification, and send a success notification otherwise. `KEEP_LABEL = 'Keep as is'` (D-22) for every dismiss button:
```php
return Action::make($name)->label($label)->color($color)
    ->visible(fn (Event $record): bool => ($from === null || $record->status === $from) && $record->status->canTransitionTo($to))
    ->requiresConfirmation()->modalHeading($heading)->modalDescription($description)
    ->modalSubmitActionLabel($submitLabel)->modalCancelActionLabel(self::KEEP_LABEL)
    ->action(fn (Event $record) => self::run(fn (User $actor) => app(ChangeEventStatus::class)($record, $to, $actor), $successTitle, self::EVENT_CONFLICT))
    ->after(fn (Event $record) => $record->refresh());
```
"Run now" catches the unique violation from `source_runs_one_active` and shows "A run is already queued or running." Never fetch inside the request.

#### Modify `EventDrafts/Actions/ReviewActions.php`
Copy `approve()` (lines 87-110) and `reject()` (176-197) for the new actions:
- apply (U), ignore, confirm cancellation, mark duplicate, not a duplicate, approve all like this
- each keeps the `shownStatus` visibility guard (line 93) and adds a `kind` check
- `approve()` gets `&& $record->kind === DraftKind::New`
```php
->visible(fn (EventDraft $record, Component $livewire): bool => self::shownStatus($record, $livewire) === ReviewStatus::PendingReview)
->disabled(fn (EventDraft $record): bool => app(DraftPublishability::class)->blockers($record) !== [])
->modalSubmitAction(fn (Action $action): Action => $action->extraAttributes(['autofocus' => true]))
->modalCancelActionLabel(self::KEEP_LABEL)
->action(function (EventDraft $record, Component $livewire): void {
    $published = self::run(fn (User $actor) => app(ApproveDraft::class)($record, $actor), 'Published', $livewire);
    if ($published) { self::openNext($record, $livewire); }
});
```
Extend `run()`'s catch list (lines 313-326) with `InvalidStatusTransition` and the update refusal `InvalidArgumentException`. Add the new actions to `forReviewPage()` (lines 60-72) and `forTable()` (79-85). If they get keys, also update the keyboard handler and help (`ReviewEventDraft::approveFromKeyboard` lines 94-110 must not open Approve for update or cancellation drafts).

#### Modify `EventDraftInfolist.php` (three-column diff, duplicate panel, urgent badge)
**Analog:** the same file, `checks()` lines 212-235 (duplicate text and link) and `source()` lines 190-210 (`ViewEntry` with a custom Blade view):
```php
ViewEntry::make('source_url')->label('Source link')->view('filament.resources.event-drafts.entries.safe-link'),
...
TextEntry::make('duplicate')->label('Duplicate check')
    ->state(fn (EventDraft $record): string => $record->duplicateOf !== null ? 'Possible duplicate of '.$record->duplicateOf->title : 'No duplicate found')
    ->url(fn (EventDraft $record): ?string => $record->duplicate_of_event_id !== null ? EventResource::getUrl('edit', ['record' => $record->duplicate_of_event_id]) : null),
```
Add a `diff()` Section, `->visible(fn ($record) => $record->kind === DraftKind::Update)`. The badge pattern is "text marker, not colour alone" (lines 105-110):
```php
TextEntry::make('category_check')->hiddenLabel()->state('Check category')->badge()->color('warning')->visible(fn (EventDraft $record): bool => $record->category_flagged),
```
Do the "Urgent" badge the same way.

#### New Blade entries `entries/source-diff.blade.php`, `entries/duplicate-match.blade.php`
**Analogs:** `entries/safe-link.blade.php` (lines 1-12, entry wrapper + SafeLinks) and `raw-content.blade.php` (lines 1-23, `<dl>` with escaped values and a scalar/array/empty fallback):
```blade
{{-- An organiser-supplied link (T-02-34): http/https only via SafeLinks ... --}}
@php($link = \App\Support\SafeLinks::http($getState()))
<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($link !== null)
        <a href="{{ $link['url'] }}" target="_blank" rel="nofollow noopener noreferrer" class="text-sm text-primary-600 underline break-all dark:text-primary-400">{{ $link['host'] }}</a>
    ...
</x-dynamic-component>
```
Only `{{ }}`: feed text is untrusted (T-02-33). Use a `<table>` with `<th scope="col">` for Published now / Source before / Source now.

#### Modify `EventDraftsTable.php`
- Lines 96-100: replace the origin "Source" filter (its comment says "until Phase 3 adds per-source records") with `SelectFilter::make('source_id')->relationship('source', 'name')`. Keep the origin filter under the label "Origin".
- Lines 104-107: the `TernaryFilter duplicate` uses `->attribute('duplicate_of_event_id')`. Switch to a custom `->queries(...)` that covers `duplicate_of_draft_id` too.
- `kind` column (line 76): add an "Urgent" badge state, using the same PHP mirror as the SQL key.
- Line 90 eager load: add `'source'`.

#### Modify `Rules.php` + `SaveRules.php` (category mappings per source)
- Rules.php:155-176: the mappings Repeater has no source column, and its helper text says "Sources arrive in a later phase." Add `Select::make('source_id')->placeholder('All sources')`.
- `SaveRules::mappingRows/mappingProblems/syncMappings` (SaveRules.php:437-550): the `$seen[$normalised]` duplicate check is global. Key it by `source_id`. The "park changed values" trick (`"\u{2060}parked-{$id}"`) must also respect the new composite index.
- `CategoryAssigner::matchMapping` (lines 91-122) loads every mapping `orderBy('id')`. Prefer `source_id = ?` matches, then `source_id IS NULL`.

#### Modify `app/Ops/HealthReport.php` (+ `sources()`), `SystemHealth` page
**Analog:** `HealthReport::calendarSync()` (lines 51-79) returns a `HealthCheck` with key, title, state, lastSeenAt, age, expectation and detail. Add it to `all()` (lines 124-127):
```php
return [$this->scheduler(), $this->queue(), $this->calendarSync(), $this->spamCheck()];
```
`SystemHealth::checks()` (SystemHealth.php:84-87) renders whatever `all()` returns, so the page needs no change beyond the view if one line per source is wanted. Use the existing `HealthState` (`Ok`/`Failed`/`Stale`) for the report line; `SourceHealth` is the per-source enum.

---

### Plan 7: Public pages, seed and UAT

#### Modify `app/Support/EventPageData.php` + `resources/views/events/show.blade.php` (LEG-04, D-14, D-16)
**Analog:** the same files. The existing source-link pattern (EventPageData.php:71, 99-100) is: build the link with `SafeLinks::http`, null the blanks, render `{{ }}` only:
```php
$source = SafeLinks::http($event->source_url);
...
sourceUrl: $source['url'] ?? null,
sourceHost: $source['host'] ?? null,
```
```blade
{{-- show.blade.php:86-91 --}}
@if ($page->sourceUrl !== null)
    <p class="source-link mt-1 text-muted wrap-anywhere">More information: <a href="{{ $page->sourceUrl }}" rel="nofollow noopener">{{ $page->sourceHost }}</a></p>
@endif
@if ($page->sourceNote === null && $page->sourceUrl === null)
    <p class="listed-by mt-1 text-muted">Listed by What's On Attleborough.</p>
@endif
```
Add `attributionName`, `attributionUrl` and `alsoListedBy` (a `list<array{name,url}>`) as constructor properties with PHPDoc shapes (lines 29-61). The "Who's running it" section (show.blade.php:72-91) already holds the source lines. Put "Listed by {source}" there and make sure it cannot appear next to "Listed by What's On Attleborough." (C10).

#### Modify `app/Support/OccurrenceCardData.php` + `occurrence-card.blade.php`
**Analog:** the same files, `fromOccurrence()` (lines 35-64) and card lines 38-42:
```blade
@if ($card->sourceUrl !== null)
    <p class="source text-sm text-muted">Source: <a href="{{ $card->sourceUrl }}" rel="nofollow noopener">{{ $card->sourceHost }}</a></p>
@elseif ($card->contactText !== null)
```
Add `public ?string $via`. The card excerpt is already cut to `calendar.card_description_chars` (140) by `OccurrenceCardData::excerpt` (lines 72-95). The 200-grapheme ingestion excerpt sits on top of that and does not replace it.

#### Eager loading (avoid N+1)
`PublicOccurrenceQuery.php:59`:
```php
->with(['event.venue', 'event.category'])
```
→ add `'event.primarySource.source'`. `EventController.php:66-67` `with(['venue', 'category'])` → add `'eventSources.source'`.

#### `database/seeders/SourceSeeder.php` (D-05)
**Analog:** `database/seeders/RulesSeeder.php` (lines 11-90). Its docblock says "Reference data only ... created only when missing (firstOrCreate ...) never updated". Match on a case-insensitive key with `whereRaw(...)->firstOrCreate([], [...])`:
```php
CategoryKeywordRule::query()
    ->whereRaw('lower(keyword) = ?', [mb_strtolower($keyword)])
    ->firstOrCreate([], [ 'keyword' => $keyword, 'category_id' => $categoryId, 'priority' => SaveRules::DEFAULT_PRIORITY ]);
```
Key on `slug`. Seed `is_active => false` and `supports_missing_detection` per A6 (Baptist true, TC true, Academy false). Take URLs, legal basis and notes from `docs/research/event-sources.md`, and URLs from each manifest's `url`. Register it in `DatabaseSeeder::run()` after `RulesSeeder` (DatabaseSeeder.php:20-24); its docblock "reference data only" needs the D-05 clarification added. Test analog: `tests/Feature/Review/RulesSeederTest.php` (seeds once, seeds twice with the same counts, keeps admin edits after re-seeding, lines 16-49).

#### Browser UAT `tests/Browser/SourcesTest.php` + `docs/uat/browser-uat.md`
**Analogs:**
- `tests/Browser/ReviewQueueTest.php` (lines 18-24): `beforeEach` with `freezeLondon`, `Uat::seedCategories()`, `actingAs(Uat::admin())`
- `UatFixtures::submissionDraft()` (lines 101-120): data written through the real write path
- role selectors `Uat::role('button', '...')`
- `Uat::settleFields()` after Livewire fills

Add a `UatFixtures::sourceDraft()` that goes through `DraftWriter::createFromSource`. In the docs, add F15+ and extend the "Automating these flows" table (browser-uat.md:256-299). Line 260 already lists the expected Phase 3 flows.

---

## Shared Patterns

### Authorisation (every new Publishing action and every Filament surface)
**Source:** `app/Publishing/AdminGate.php:19-36`
```php
public static function authorize(User $user, string $message): void
{
    if (! self::allows($user)) { throw new AuthorizationException($message); }
}
```
- **Actions:** call it as the first line, before `DB::transaction`, with a plain-English message ("Only the admin can apply updates.").
- **Resources and pages:** `canAccess()` returns `$user instanceof User && AdminGate::allows($user)` (EventResource.php:33-38).
- **Filament closures:** resolve the actor with `auth()->user()` and an `instanceof User` check, as in `ReviewActions::run` (lines 307-311).

### Locking and transaction order
**Sources:** `ChangeOccurrenceStatus.php:20-25` docblock and lines 39-42, and `UpdatePublishedEvent.php:64-65`. Always lock **draft → event (via the inner action) → occurrence → source_item**. Check transitions on the freshly locked row, never on the caller's model:
```php
Event::query()->whereKey($occurrence->event_id)->lockForUpdate()->firstOrFail();
$locked = EventOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()->firstOrFail();
$from = $locked->status;
$from->assertCanTransitionTo($to);
```

### Logging: ids only, after commit
**Source:** `ApproveDraft.php:78-84` (`DB::afterCommit(fn () => Log::info('draft approved', [ids]))`) and `SyncOccurrences.php:51-52` ("Id and message only: never event content or organiser details"). Never log titles, feed text, URLs with query strings or response bodies. `source_runs.error_message` comes from enum-based messages only.

### Notifications and UI copy (Filament)
**Sources:** `ReviewActions` consts (lines 51-53: `KEEP_LABEL = 'Keep as is'`, `ALREADY_REVIEWED`) and the `run()` catch mapping (313-331). Notification bodies are plain text, and Filament escapes them ("Plain-text body: Filament escapes notification strings", line 321). Write in British English and the brand voice. Dialogs name the action on the submit button ("Publish", "Reject draft").

### Time
**Sources:**
- `OccurrenceGenerator.php:38-41`: upsert with explicit UTC strings, `->utc()->format('Y-m-d H:i:s')`
- `Heartbeat::beat` (UTC upsert)
- `ApproveDraft.php:72` (`now()->utc()`)
- Filament: `->timezone('Europe/London')` (EventDraftsTable.php:78-82), `LondonFormatter::TZ` (EventDraftInfolist.php:73)

Do window and "48 hours" maths on `CarbonImmutable::now('Europe/London')` (`DraftData`/StoreSubmissionRequest convention, STATE 02-05).

### Escaped output and links
**Sources:** `SafeLinks::http` and `entries/safe-link.blade.php`. External links get `rel="nofollow noopener"` (noreferrer in admin). Feed text never goes through `->html()`, `HtmlString` or `{!! !!}`.

### Testing
| Concern | Copy from | Lines |
|---|---|---|
| Suite bootstrap (RefreshDatabase on Feature, `Http::preventStrayRequests()` everywhere) | `tests/Pest.php` | 28-58 |
| Freeze London time | `freezeLondon()` in `tests/Pest.php` | 69-72 |
| Offline HTTP fake helper | `fakeTurnstilePass()` in `tests/Pest.php` | 146-158 |
| Http::fake + assertSent + fail-closed datasets | `tests/Feature/Submissions/TurnstileVerifierTest.php` | 12-70 |
| Action test: AdminGate refusal, locked transition, raw-SQL UTC asserts | `tests/Feature/Review/ApproveDraftTest.php` | 28-112 |
| Test-only event+occurrence insert | `makeOccurrence()` in `tests/Pest.php` | 166-189 |
| Filament bulk/notification assertions | `tests/Feature/Filament/BulkReviewTest.php` (`bulkNotificationLines()`) | 22-48 |
| Table action tests | `tests/Feature/Status/CancelOccurrenceTracerTest.php` (`TestAction::make('cancel')->table($record)`) | 60-62 |
| Keyset/order test (extend for urgent key) | `tests/Feature/Review/ReviewQueueOrderTest.php` | 18-44 |
| Seeder idempotency | `tests/Feature/Review/RulesSeederTest.php` | 16-49 |
| Arch choke point (stays green; new actions live in `App\Publishing\Actions`) | `tests/Unit/Architecture/WriteChokePointTest.php` | 17-19 |
| Health check lines | `tests/Feature/Ops/HealthReportTest.php`, `tests/Feature/Ops/OpsFixtures.php` | — |

Raw-SQL UTC assertion shape (ApproveDraftTest.php:71-77):
```php
$rows = DB::select("select starts_at at time zone 'UTC' as s from event_occurrences where event_id = ? order by starts_at", [$event->id]);
```
**Global-name hazard:** Pest test files declare top-level functions and consts (`draftDataFrom`, `approvalBlockers`, `occurrenceStartsUtc`, `bulkNotificationLines`, `fakeSiteverify`, `const OPEN_MODAL`, `SOURCE_FIXTURE_*`), and the suite loads them all into one namespace. New helpers need unique names (prefix `ingest…`). Helpers shared across files go in `tests/Pest.php`: an `icsFixture()`/`sourceFixture()` loader and a fake `HostResolver` binding.

---

## No Analog Found

The planner should use the RESEARCH.md pattern for these:

| File | Role | Data Flow | Reason / RESEARCH reference |
|---|---|---|---|
| `app/Ingestion/Http/SsrfGuard.php` + `HostResolver`/`DnsHostResolver` | utility | transform | No outbound-to-arbitrary-URL code exists. RESEARCH Pattern 1 snippet (`FILTER_FLAG_GLOBAL_RANGE` + multicast/NAT64) |
| `app/Ingestion/Http/BodyValidator.php` | utility | transform | RESEARCH Pitfall 8 (BOM strip, `BEGIN:VCALENDAR`, `<html` → content_invalid) |
| `app/Ingestion/Http/RobotsRules.php` (RFC 9309 group selection) | parser | transform | RESEARCH Pattern 2. Only the preg_quote discipline has an analog |
| Manual redirect loop + stream size cap in `HttpFetcher` | service | streaming | RESEARCH Pattern 1 steps 5-7, Pitfall 9 |
| `app/Ingestion/RawBodyStore.php` + `ingest:prune-raw` | service | file-I/O | No disk writes in app code yet. `local` disk root is `storage/app/private` (config/filesystems.php:33-35). Paths from integer ids only |
| `app/Ingestion/Pipeline/ChangeDetector.php` / `ContentHasher.php` | service | transform | RESEARCH Pattern 4 (raw vs content hash, fixed key order) |
| `app/Ingestion/Pipeline/MissingItemDetector.php` | service | batch | RESEARCH Pattern 5 SQL |
| `Illuminate\Support\Sleep` usage in `HostThrottle` | utility | — | Not used in the codebase yet. `Sleep::fake()` in tests |
| `->poll()` on RunsRelationManager / preview section | UI | polling | Not used in `app/` yet (Pitfall 13) |
| `FetchSourceJob::release()` / `retryUntil()` | job | event-driven | Existing jobs are single-try (`$tries = 1`). RESEARCH F8 |
| Pre-validating TZIDs (`TimeZoneUtil::getTimeZone(..., true)`) | utility | transform | RESEARCH Pattern 3 probe results |

---

## Conflicts: RESEARCH/CONTEXT vs actual code (planner must resolve)

| # | Assumption (source) | Actual code / later decision | Action for the planner |
|---|---|---|---|
| C1 | RESEARCH F2, Pattern 3/5 and the config sketch use a **12-month** window (`window_months=12`) and recommend `horizon_days = 400` | **D-20 (owner, after research): keep `calendar.horizon_days` at 90.** The import window is 90 days. Items further out → `skipped_far_future`. `config/calendar.php:18` stays `90` | `WindowClassifier`, `observed_until` and missing detection read `config('calendar.horizon_days')`. Do not add `window_months`. Pitfall 2 does not apply |
| C2 | RESEARCH F1 option B: "`excluded_dates` ... honoured by RecurrenceExpander", a small change | `PublishableEventData::toAttributes()` (329-357) is the write allowlist for **both** PublishEvent and UpdatePublishedEvent. `fromForm()` (161-199) builds from the edit form, which has no excluded-dates field. Adding `excluded_dates` to `toAttributes()` with a `[]` default would **wipe EXDATEs on every admin edit**. `fromEvent()` (274-285) uses `attributesToArray()`, so the value reaches form state but `fromForm` drops it. `UpdatePublishedEvent::canEdit` / `ManualRecurrence` round-trip ignore EXDATE | Choose one: (a) carry `excluded_dates` through form state (Hidden field + `fromForm`), or (b) leave it out of `toAttributes()` and write it only in PublishEvent/ApplySourceUpdate. Add a regression test: admin edits a series with EXDATE, EXDATE survives. Also update RecurrenceExpander.php:111, DraftData, EventDraft Fillable and OccurrenceGenerator (delete-not-produced rows, line 52-54, then drops the excluded date's row: intended) |
| C3 | CONTEXT code_context: drafts with "origin `source`" | `EventOrigin::Discovered = 'discovered'` (EventOrigin.php:11). `event_drafts_origin_check` already allows `'discovered'` (migration :79). `CategoryAssigner` gives the reason "Source default" for Discovered (line 42) | Use `EventOrigin::Discovered`. No origin migration needed |
| C4 | CONTEXT: "DraftWriter: write discovered items as drafts" | `DraftWriter::create` hard-codes `kind => DraftKind::New` (line 41). `derive()` passes `[]` source values (line 110). `ExtractionMethod` lacks `Ics`, and the CHECK (:82) lacks `'ics'` | Add source-aware writer methods. Widen the enum and CHECK together (C-style alter migration) |
| C5 | D-22: the source default venue counts as confirmed | `VenueMatcher` docblock: "never sets a draft's venue_id" (lines 16-18). `DraftWriter::derive` docblock: "venue_id is set only by the organiser's or admin's pick (D-05, D-21)" (lines 101-103) | Set `venueId` in `DraftData::fromSourceItem` from `sources.default_venue_id` (admin configuration). Never from VenueMatcher. Update both docblocks to name D-22 |
| C6 | RESEARCH F6/Pattern 11 | Confirmed: `qualify()` regex (ReviewQueue.php:123) only knows 4 columns. `applyOrder` sends `(`-prefixed keys through `orderByRaw` (50-52) | Parenthesise the urgent key, extend the regex, extend `ReviewQueueOrderTest` |
| C7 | RESEARCH F7 | Confirmed: `EventDraftsTable::skip(BulkAction $action, ...)` (286-292) is typed to `BulkAction` | ApproveAllLikeThis returns counts. The Filament action builds its own `Notification` |
| C8 | RESEARCH Pattern 7: `#[Tries]`, `#[UniqueFor]`, `#[Timeout]`, `#[MaxExceptions]` attributes | Existing jobs use **properties** (`public int $tries = 1; public int $uniqueFor = 3600;`, QueueHeartbeat.php:20-22) | Either works on Laravel 13. Pick one and say so in the plan (properties match the codebase) |
| C9 | RESEARCH Pattern 9: nest `UpdatePublishedEvent` / `ChangeOccurrenceStatus` inside new transactional actions | `ChangeOccurrenceStatus` (line 57) and `ChangeEventStatus` (line 54) call `Log::info` **directly**, not in `DB::afterCommit`. Nested in ConfirmSourceCancellation, a rolled-back confirm still logs "occurrence status changed" | Accept that, or wrap their logs in `DB::afterCommit` (as ApproveDraft does, line 80) in a small change to those two actions. Their existing tests still pass, since afterCommit runs immediately outside a transaction |
| C10 | D-14 "Listed by {source}" / "via {short}" | The public pages **already** show `events.source_url` as "Source: {host}" on cards (occurrence-card.blade.php:38-39) and "More information: {host}" plus a fallback "Listed by What's On Attleborough." on the event page (show.blade.php:86-91). Discovered drafts set `sourceUrl` = item URL, so `events.source_url` = the item URL | Decide the order and dedupe so a discovered event doesn't show both "Source: attleboroughtc.org.uk" and "via Town Council". Keep the fallback line only when there is no attribution. Update `tests/Feature/Public/OccurrenceCardTest.php` / `EventPageContentTest.php` expectations |
| C11 | RESEARCH Schema: `category_mappings.source_id`, assigner prefers source-specific | `SaveRules::mappingProblems` enforces **global** normalised uniqueness (`$seen`, ~lines 465-500). `syncMappings` parks values against the old index. `Rules.php:155-176` has no source column, and its helper text says "Sources arrive in a later phase." | Plan the Rules-screen + SaveRules + CategoryAssigner change with the migration. Otherwise the new index and SaveRules disagree |
| C12 | RESEARCH: synthetic fixtures in `tests/Fixtures/ics/` | `SourceFixturesHaveNoPersonalDataTest.php:65` requires every file under `tests/Fixtures/sources/*` to be in a manifest. `tests/Fixtures/ics/` is outside that scan | Keep synthetic ICS in `tests/Fixtures/ics/`. Don't add files to `sources/` without manifest entries. Also: the Attleborough Primary capture (STATE pending todo) must update its manifest + `docs/research/event-sources.md` index |
| C13 | — | `Event::draft()` is `hasOne(EventDraft)->where('kind', DraftKind::New)` (Event.php:86-89). Approved update/cancellation drafts set `event_id` too | Safe: the relation filters on kind. Any new `Event::drafts()` must not assume one row |
| C14 | RESEARCH F9: ReopenDraft could revive ignored update drafts | Confirmed: `ReopenDraft` has no kind check (lines 33-37), and Reopen is visible for any `canTransitionTo(PendingReview)` (ReviewActions.php:207) | Guard in both the action and the visibility closure. D-23 adds the review_outcome rule |
| C15 | RESEARCH Pattern 12: fallback "Details on {source short name}'s website." | **D-21 wording: "Details from {source name}." + "Read more at {source}"** | Use the D-21 wording. QualityScorer's "Missing description" (QualityScorer.php:50) is then not triggered, so bulk approve works |
| C16 | D-25 flag | RESEARCH names it `sources.is_organiser_source`. CONTEXT label "Organiser's own site" | Column name is up to the planner. The form label follows CONTEXT |
| C17 | — | `HealthState` has no degraded/unhealthy (Ok, Stale, NeverSeen, Failed, NotRunYet) | Add a new `SourceHealth` enum for sources. HealthReport lines keep using `HealthState` |
| C18 | — | No Filament resource does plain Eloquent create/edit yet. Every existing write goes through an action (EditEvent → UpdatePublishedEvent, Rules → SaveRules) | The writes rule covers only events/occurrences, so default `CreateRecord`/`EditRecord` saves for `sources` are allowed. For consistency (and title_rules validation) consider a `SaveSource` action like SaveRules (AdminGate + `RulesNotSaved`-style refusal). Planner's choice; record it |
| C19 | RESEARCH notes `config/site.php` has no UA keys | Confirmed (config/site.php:11-17) | `config/ingest.php` `contact_url => env('CRAWLER_CONTACT_URL', ...)`. Add it to `.env.example` (`tests/Unit/Ops/EnvExampleTest.php` only checks `BOOST_BROWSER_LOGS_WATCHER=false`, so it does not break) |

---

## Metadata

**Analog search scope:** `app/**` (Calendar, Console, Enums, Filament, Http, Jobs, Models, Ops, Publishing, Review, Submissions, Support), `config/*`, `database/{migrations,seeders,factories}`, `routes/console.php`, `resources/views/{calendar,events,filament}`, `tests/{Pest.php,Unit,Feature,Browser}`, `tests/Fixtures/sources`, `docs/uat/browser-uat.md`
**Files read in full or in targeted sections:** ~55
**Tracked-source gate:** every analog path comes from `git ls-files`. No gitignored mirrors are referenced.
**Pattern extraction date:** 2026-10-18

