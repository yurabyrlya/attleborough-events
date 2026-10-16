# Architecture Research

**Domain:** Deterministic event-ingestion and human-review pipeline with a public calendar (Laravel monolith)
**Researched:** 2026-10-16
**Confidence:** MEDIUM overall. Framework mechanics are HIGH in substance: I read them verbatim from the official Laravel 13.x and Filament 5.x doc sources and the framework source code. The research seam rates all of these `webfetch` sources as LOW because Context7 wasn't available in this session. The pipeline design choices are my judgement (MEDIUM), based on those mechanics and on the v1.1 spec.

> **Versions checked today (Packagist and GitHub releases):** laravel/framework **v13.37.0** (PHP ^8.3), filament/filament **v5.10.2**, livewire **v4.4.7**, sabre/vobject **5.0.0** (2026-07-07, PHP ^8.2, a new major with strict types), rlanvin/php-rrule **v3.0.0** (2026-07-29, a new major), spatie/laravel-model-states 2.14.2 (**PHP ^8.4**), symfony/dom-crawler v8.1.5 (PHP ≥8.4.1; v7.4 for PHP 8.3), laminas/laminas-feed 2.26.3, spatie/robots-txt 2.5.4, brick/structured-data 0.2.0 (pre-1.0). STACK.md owns the final version choices. This document only depends on the APIs noted below.

---

## Standard Architecture

### System Overview

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                         PRESENTATION (one Laravel app)                       │
│  ┌────────────────────┐  ┌──────────────────────┐  ┌───────────────────────┐ │
│  │ Public site        │  │ Filament admin panel │  │ Organiser submission  │ │
│  │ Livewire calendar, │  │ Review queue, Sources│  │ form (Livewire/Blade, │ │
│  │ Blade detail pages,│  │ Events, Categories,  │  │ honeypot, throttle,   │ │
│  │ sitemap, JSON-LD   │  │ Venues, Run logs,    │  │ Turnstile)            │ │
│  └─────────┬──────────┘  │ Dashboard widgets    │  └──────────┬────────────┘ │
│            │             └──────────┬───────────┘             │              │
├────────────┼────────────────────────┼─────────────────────────┼──────────────┤
│            │          APPLICATION / DOMAIN ACTIONS            │              │
│  ┌─────────▼──────────┐  ┌──────────▼───────────┐  ┌──────────▼────────────┐ │
│  │ Calendar           │  │ Review actions       │  │ DraftWriter           │ │
│  │ PublicOccurrence-  │  │ ApproveDraft, Reject,│  │ (single writer of     │ │
│  │ Query, DateWindow, │  │ MarkDuplicate,       │  │ event_drafts; all     │ │
│  │ OccurrenceGenerator│◄─┤ ApplyUpdate,         │  │ origins go through it)│ │
│  └────────────────────┘  │ ConfirmCancellation  │  └──────────▲────────────┘ │
│                          └──────────────────────┘             │              │
├───────────────────────────────────────────────────────────────┼──────────────┤
│                     INGESTION (queue: "ingest", 1 worker)     │              │
│  Scheduler ─► DispatchDueSources ─► FetchSourceJob(source)    │              │
│     ┌──────────────┐  ┌────────────────────┐  ┌───────────────┴──────────┐   │
│     │ HttpFetcher  │─►│ ExtractorRegistry  │─►│ SourceItemRecorder       │   │
│     │ robots, UA,  │  │ Ics | Feed | TEC | │  │ upsert source_items,     │   │
│     │ HostThrottle,│  │ Squarespace |      │  │ missing-item detection,  │   │
│     │ ETag/IMS,    │  │ JsonLd | Selector  │  │ run stats                │   │
│     │ size limits  │  │ → EventCandidate[] │  └───────────┬──────────────┘   │
│     └──────────────┘  └────────────────────┘              │ changed items    │
│                                     ProcessSourceItemJob(item) ◄─┘           │
│   Normalizer → LocationFilter → CategoryAssigner → ChangeDetector            │
│            → DuplicateDetector → QualityScorer → DraftWriter                 │
├──────────────────────────────────────────────────────────────────────────────┤
│                                  STORAGE                                     │
│  PostgreSQL: sources, source_runs, source_items(raw fragment), event_drafts, │
│  events, event_occurrences, event_sources, venues, categories, rules,        │
│  jobs/failed_jobs, cache + cache_locks (database cache store), sessions      │
│  Local disk (storage/app/raw): gzip'd full response bodies, 30-day TTL       │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Key idea:** there are two write models and one read model.
- **Ingest write model:** `sources → source_runs → source_items → event_drafts`. It's append-mostly, machine-written and never public.
- **Published write model:** `events → event_occurrences (+ event_sources)`. Only the Review actions (and manual entry, which is an auto-approved draft) write to it.
- **Read model:** `event_occurrences ⋈ events WHERE published`, queried by date window.

Nothing in the ingest model can reach the read model except through `ApproveDraft` / `ApplyUpdate` / `ConfirmCancellation`. That single choke point is how the architecture enforces the "nothing public without admin approval" promise.

### Component Responsibilities

| Component | Responsibility | Implementation | Interface (in / out) |
|-----------|----------------|----------------|----------------------|
| `DispatchDueSources` | Picks sources that are due and dispatches fetches | Scheduled invokable (`Schedule::call`/job), every 5 min, `withoutOverlapping()` | `sources` where active, not `unhealthy`, `next_check_at <= now` → `FetchSourceJob` |
| `HttpFetcher` | Polite HTTP: robots.txt, UA, per-host throttle, conditional GET, timeouts, max bytes, redirect cap | Wraps Laravel `Http` client (so `Http::fake()` works in tests) | `fetch(FetchRequest): FetchResult` (status, body, headers, finalUrl, notModified, bytes, ms) |
| `RobotsPolicy` | Parse and cache robots.txt per host (24h in the cache store) | `spatie/robots-txt` or a ~100-line in-house parser | `allows(url, ua): bool` |
| `HostThrottle` | At least 5 s between requests to the same host, across processes | Cache atomic lock plus a `last_request_at:{host}` key; sleeps the remainder | `waitTurn(host): void` |
| `ExtractorRegistry` | Resolves the extractor for a source's method/adapter | Container-bound map: `ExtractionMethod` enum + adapter name → class | `for(Source): Extractor` |
| `Extractor` (×6) | Turns one or more fetched documents into raw items plus candidates. **Rejects rather than guessing** | `IcsExtractor`, `FeedExtractor`, `TheEventsCalendarAdapter`, `SquarespaceEventsAdapter`, `JsonLdEventExtractor`, `SelectorExtractor` | `extract(FetchResult, Source, ExtractionContext): ExtractionResult` |
| `SourceItemRecorder` | Upserts `source_items` by (source_id, external_id), computes hashes, flags changed items, missing-item counters | Plain service called inside `FetchSourceJob` | `record(Source, SourceRun, ExtractionResult): RecordOutcome` |
| `EventNormalizer` | Cleans the candidate: trim, strip HTML, truncate description to 500, canonical URL, timezone resolution to UTC, venue alias lookup, title match key | Pure service, no I/O except venue lookup | `normalize(EventCandidate, Source): NormalizedEvent` |
| `LocationFilter` | `local` → in area; `filter` → postcode district / place names / known venue / lat-lng radius | Pure service with config from `geofence_settings` | `classify(NormalizedEvent, Source): LocationVerdict{InArea, OutOfArea, Unknown}` |
| `CategoryAssigner` | Source default → mapping → keyword rules → `Other` (+ flag) | Pure service | `assign(...): CategoryDecision{categoryId, rule, flagged}` |
| `ChangeDetector` | Decides new / update / cancellation / no-op against the **accepted baseline**, builds the field diff | Pure service | `detect(SourceItem, NormalizedEvent): ChangeVerdict{kind, diff}` |
| `DuplicateDetector` | External ID → canonical URL → date + venue + trigram title, against events **and** pending drafts | Query object (pg_trgm `similarity()`) | `find(NormalizedEvent): ?DuplicateMatch{eventId/draftId, score, reason}` |
| `QualityScorer` | 0–100 score with human-readable reasons | Pure service, weights in config | `score(...): QualityResult{score, band, reasons[]}` |
| `DraftWriter` | The only writer of `event_drafts`: supersedes the pending draft for the same item, writes the new one | Service plus a partial unique index as the backstop | `write(DraftData): EventDraft` |
| Review actions | State transitions and publish transaction | Invokable action classes, called by Filament actions | `ApproveDraft(draft, user, ?edits): Event`, etc. |
| `OccurrenceGenerator` | Materialises occurrences from the event's schedule over the rolling 90-day horizon. Preserves per-occurrence admin status | Uses `sabre/vobject` `EventIterator` as the **only** expansion engine | `sync(Event, ?CarbonImmutable $until): SyncStats` |
| `PublicOccurrenceQuery` | Date-window plus category query for the public site | Query object returning a paginator | `forWindow(DateWindow, ?category)` |

---

## Recommended Project Structure

This is a domain-grouped layout inside a standard Laravel app. It avoids a full modular-monolith package system, which is overkill for one developer, but keeps ingestion code isolated and testable.

```
app/
├── Enums/                          # Backed enums incl. state-machine rules
│   ├── ExtractionMethod.php        # ics, feed, adapter, json_ld, selector, manual, submission
│   ├── DraftKind.php               # new, update, cancellation
│   ├── DraftOrigin.php             # discovered, submission, manual
│   ├── ReviewStatus.php            # pending_review, approved, rejected, duplicate, superseded
│   ├── EventStatus.php             # published, hidden, cancelled
│   ├── OccurrenceStatus.php        # scheduled, cancelled, hidden
│   ├── SourceHealth.php            # healthy, degraded, unhealthy
│   ├── SourceRunStatus.php         # success, not_modified, failed, blocked, partial
│   ├── SourceItemStatus.php        # active, missing, out_of_area, error, ignored
│   └── LocationMode.php            # local, filter
├── Models/                         # Eloquent only: casts, relations, scopes. No business logic
│   ├── Source.php  SourceRun.php  SourceItem.php  EventDraft.php
│   ├── Event.php  EventOccurrence.php  EventSource.php
│   └── Venue.php  Category.php  CategoryMapping.php  CategoryKeywordRule.php
├── Ingestion/
│   ├── Fetching/                   # HttpFetcher, FetchRequest, FetchResult, RobotsPolicy, HostThrottle
│   ├── Extraction/
│   │   ├── Contracts/Extractor.php
│   │   ├── ExtractorRegistry.php
│   │   ├── Ics/IcsExtractor.php          # sabre/vobject; groups VEVENTs by UID
│   │   ├── Feed/FeedExtractor.php
│   │   ├── Adapters/TheEventsCalendarAdapter.php
│   │   ├── Adapters/SquarespaceEventsAdapter.php
│   │   ├── JsonLd/JsonLdEventExtractor.php
│   │   └── Selectors/SelectorExtractor.php  + SelectorConfig.php (validated schema)
│   ├── Data/                       # readonly DTOs: EventCandidate, RecurrenceSpec, LocalDateTimeValue,
│   │                               # ExtractionResult, RawItem, NormalizedEvent, ChangeVerdict, ...
│   ├── Pipeline/                   # EventNormalizer, LocationFilter, CategoryAssigner, ChangeDetector,
│   │                               # DuplicateDetector, QualityScorer, DraftWriter, ProcessSourceItem (action)
│   ├── SourceItemRecorder.php
│   ├── SourceHealthTracker.php
│   └── Jobs/                       # DispatchDueSources, FetchSourceJob, ProcessSourceItemJob
├── Review/
│   └── Actions/                    # ApproveDraft, RejectDraft, MarkDuplicate, ApplyUpdate, IgnoreUpdate,
│                                   # ConfirmCancellation, IgnoreCancellation, CreateManualEvent
├── Calendar/                       # Read side + occurrence engine (NOT app/Events: that dir means Laravel events)
│   ├── OccurrenceGenerator.php
│   ├── DateWindow.php              # today / weekend / week → [startUtc, endUtc)
│   ├── PublicOccurrenceQuery.php
│   └── CalendarCacheVersion.php    # versioned cache key (DB cache store has no tags)
├── Filament/
│   ├── Resources/EventDrafts/      # Review queue (List with tabs, View, Edit, custom Review page)
│   ├── Resources/Sources/          # + RelationManager for runs; Test run / Run now actions
│   ├── Resources/Events/           # + Occurrences relation manager (cancel/hide one occurrence)
│   ├── Resources/{Categories,CategoryMappings,KeywordRules,Venues}/
│   ├── Pages/GeofenceSettings.php
│   └── Widgets/                    # dashboard counts, unhealthy sources
├── Livewire/Calendar.php           # public listing with #[Url] filters
└── Http/Controllers/               # EventShowController (Blade, SEO), SitemapController
config/ingestion.php                # UA, throttle seconds, timeouts, max bytes, horizon days, score weights,
                                    # trigram thresholds, missing-run threshold, retention days
tests/
├── Fixtures/sources/{slug}/        # saved real responses, one dir per source/adapter
├── Unit/Ingestion/Extraction/      # extractor → expected candidates (golden JSON snapshots)
├── Unit/Calendar/                  # DST, all-day, floating, horizon tests (travelTo)
└── Feature/                        # jobs with Http::fake + preventStrayRequests, review actions, Filament
```

### Structure Rationale

- **`Ingestion/` is self-contained.** Its only outward dependency is `DraftWriter`, so all extractor work (the bulk of the phases) can be fixture-tested without touching Filament or public code.
- **`Review/Actions/` is the only way into the published model.** Filament actions are thin and call these classes, so the publish rules are unit-testable without Livewire.
- **`Calendar/` and not `Events/`.** `app/Events` is Laravel's convention for event classes, and `App\Models\Event` already collides with the `Event` facade name. Keeping the domain code under `Calendar` avoids a third meaning of "event". Keep the model named `Event` to match the spec and tables, and alias the facade where you need it.
- **Enums carry their transition rules**, so the Filament visibility logic (`->visible(fn ($r) => $r->review_status->canTransitionTo(...))`) and the domain actions use the same source of truth.

---

## Architectural Patterns

### Pattern 1: Extractor contract returning a normalised `EventCandidate` DTO

**What:** Each ladder rung implements one interface and emits typed, immutable candidates. It doesn't emit arrays or Eloquent models. Candidates carry **local** wall-clock values plus timezone provenance, so the Normalizer, not the parser, decides UTC conversion. Parse errors and warnings travel on the candidate rather than being thrown, because one bad item mustn't kill the page.
**When to use:** Every extraction method, including the organiser form and manual entry (they build an `EventCandidate` too). This gives one pipeline, one scorer and one draft shape.
**Trade-offs:** It's a little more code than passing arrays, but it gives deterministic snapshot tests (`json_encode($candidate)` vs a golden file) and lets a parser bug be fixed and reprocessed from stored raw fragments.

```php
interface Extractor
{
    /** Extra pages to fetch (e.g. detail pages), capped by source config. */
    public function followUps(FetchResult $page, Source $source): array; // list<string> URLs

    public function extract(FetchResult $page, Source $source, ExtractionContext $ctx): ExtractionResult;
}

final readonly class ExtractionResult
{
    /** @param list<RawItem> $items  @param list<string> $warnings */
    public function __construct(public array $items, public array $warnings = []) {}
}

final readonly class RawItem
{
    public function __construct(
        public string $externalId,        // ICS UID, feed guid, TEC id, JSON-LD @id/url, selector key
        public ?string $url,
        public string $rawFragment,       // VEVENT group text, <item> XML, JSON-LD block, HTML node
        public EventCandidate $candidate,
    ) {}
}

final readonly class EventCandidate
{
    public function __construct(
        public ?string $title,
        public ?string $description,
        public ?LocalDateTimeValue $start,   // wall-clock + tz kind: Utc|Tzid(name)|Offset|Floating|DateOnly
        public ?LocalDateTimeValue $end,
        public bool $allDay,
        public ?RecurrenceSpec $recurrence,  // raw RRULE/RDATE/EXDATE/overrides + original VEVENT text
        public CandidateStatus $status,      // scheduled|cancelled|postponed|rescheduled
        public ?string $venueName, public ?string $address, public ?string $postcode,
        public ?float $lat, public ?float $lng,
        public ?string $organiser, public ?string $contact,
        public ?string $priceText, public ?bool $isFree,
        public array $sourceCategories = [],
        public array $parseErrors = [],      // e.g. "date has no year" → never guessed
        public array $parseWarnings = [],
    ) {}
}
```

**Ladder-specific notes:**
- **ICS:** group all VEVENTs with the same `UID` (master plus `RECURRENCE-ID` overrides) into **one** `RawItem`, with external_id = UID. Treating overrides as separate items breaks updates and dedup.
- **Feed (RSS/Atom):** feeds rarely carry structured event dates. The extractor should only produce a candidate when the date can be read deterministically, either from a namespaced field or from a per-source `date_pattern` in `parser_config`. Otherwise it produces a candidate with a parse error (Low quality). Feeds are often "latest N", so set `supports_missing_detection = false` by default.
- **TEC / Squarespace:** adapters choose the endpoint (REST JSON or ICS). They can delegate to `IcsExtractor` internally when the ICS route is used. The exact endpoints are still to be verified on live installs (spec §14).
- **JSON-LD:** walk `@graph`, accept `Event` and its subtypes, read `eventStatus`. The listing → detail pattern uses `followUps()`.
- **Selectors:** `SelectorConfig` is a validated schema (item selector, field selectors + attribute, **explicit** date formats tried in order with `CarbonImmutable::createFromFormat`; failure → parse error). Validate it in the Filament form as well as at runtime.

### Pattern 2: Thin jobs, fat (pure) actions

**What:** Jobs hold IDs and own the queue semantics (uniqueness, middleware, retries). The work lives in action/service classes that are plain PHP. `ProcessSourceItemJob::handle()` just calls `app(ProcessSourceItem::class)($item)`. The Test run and `ingest:reprocess` commands call the **same** action in dry-run mode.
**When to use:** Always for ingestion.
**Trade-offs:** None worth mentioning. Dry-run needs `DraftWriter` swapped for a collecting implementation, so pass an output port into the action rather than hard-wiring the writer.

### Pattern 3: Enum-backed state machines, enforced in actions and backstopped by DB constraints

**What:** Backed enums with `transitions()` / `canTransitionTo()`, an `assertCanTransitionTo()` call in every action, plus a Postgres `CHECK` constraint on allowed values and partial unique indexes for invariants.
**When to use:** All three machines (draft, event, occurrence) are small and flat. **Don't** adopt `spatie/laravel-model-states`: it adds a class per state, its current release requires PHP ^8.4, and the transitions here have no side-effect-heavy state classes that would justify it.

```php
enum ReviewStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';
    case Superseded = 'superseded';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::PendingReview => [self::Approved, self::Rejected, self::Duplicate, self::Superseded],
            self::Rejected      => [self::PendingReview],     // optional "reopen"
            default             => [],                         // terminal
        };
    }

    public function canTransitionTo(self $to): bool { return in_array($to, $this->transitions(), true); }

    public function assertCanTransitionTo(self $to): void
    {
        $this->canTransitionTo($to) || throw new InvalidTransition($this, $to);
    }
}
```

| Machine | States | Allowed transitions | Notes |
|---|---|---|---|
| Draft `review_status` | pending_review, approved, rejected, duplicate, superseded | pending → approved / rejected / duplicate / superseded; rejected → pending (reopen) | **One pending draft per source item** (partial unique index). `superseded` is only set by `DraftWriter` |
| Draft `kind` | new, update, cancellation | Immutable after creation | Kind decides which actions are offered |
| Event `status` | published, hidden, cancelled | published ↔ hidden; published → cancelled; cancelled → published (un-cancel via update) | **Expired is derived** from occurrences, never stored |
| Occurrence `status` | scheduled, cancelled, hidden | scheduled ↔ cancelled; scheduled ↔ hidden | Survives regeneration (see Pattern 6) |
| Source `health` | healthy, degraded, unhealthy | by consecutive failed runs: 0 → healthy, ≥3 → degraded, ≥5 → unhealthy (paused); admin "resume" resets | Driven by `SourceHealthTracker` after each run |

### Pattern 4: Job design: FetchSourceJob → ProcessSourceItemJob

The relevant Laravel 13 mechanics, read verbatim from the `queues.md` 13.x docs:
- `ShouldBeUnique` and `ShouldBeUniqueUntilProcessing` use cache atomic locks. The **database** cache driver supports locks, which matters because there's no Redis here. Use the `#[UniqueFor(seconds)]` attribute with `uniqueId()`. Unique constraints **don't apply inside batches**.
- `WithoutOverlapping($key)` supports `releaseAfter()`, `expireAfter()`, `dontRelease()` and `shared()`.
- `RateLimited` releases the job with a delay, and each release **increments attempts**.
- `#[Tries]`, `#[Backoff([..])]`, `#[Timeout]`, `#[MaxExceptions]` and `#[FailOnTimeout]` are attributes in 13.x.
- `retry_after` must be **longer** than the worker `--timeout`, or jobs run twice.
- `->afterCommit()` / `after_commit` delays dispatch until the transaction commits.

```php
#[UniqueFor(900)]
#[Tries(2)]                      // queue retries are for *unexpected* exceptions only
#[Timeout(600)]
final class FetchSourceJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function __construct(public int $sourceId, public bool $manual = false)
    {
        $this->onConnection('ingest')->onQueue('ingest');
    }

    public function uniqueId(): string { return (string) $this->sourceId; }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("source:{$this->sourceId}"))->expireAfter(660)->dontRelease()];
    }

    public function handle(HttpFetcher $fetcher, ExtractorRegistry $registry,
                           SourceItemRecorder $recorder, SourceHealthTracker $health): void
    {
        $source = Source::findOrFail($this->sourceId);
        $run = $source->runs()->create(['started_at' => now(), 'status' => SourceRunStatus::Running]);

        try {
            $page = $fetcher->fetch(FetchRequest::forSource($source));      // robots + throttle + ETag
            if ($page->notModified) { $run->finish(SourceRunStatus::NotModified); $health->success($source); return; }

            $result  = $registry->for($source)->extract($page, $source, ExtractionContext::live($run));
            $outcome = $recorder->record($source, $run, $result);            // upserts, hashes, missing-items

            foreach ($outcome->changedItemIds as $id) {
                ProcessSourceItemJob::dispatch($id)->afterCommit();
            }
            $run->finish(SourceRunStatus::Success, $outcome->stats());
            $health->success($source, zeroAfterResults: $outcome->suspiciousZero);
        } catch (FetchFailed|ExtractionFailed|RobotsDisallowed $e) {         // EXPECTED failures
            $run->fail($e);                                                   // record, don't rethrow
            $health->failure($source, $e);                                    // failure_count++ once per run
        }
    }
}
```

**Decisions and rationale:**

1. **Expected failures don't use queue retries.** HTTP and robots problems are recorded as a failed run, and the job completes. Transport retries happen inside the fetcher (`Http::retry(2, 2000)` on connection errors and 5xx, still throttled). That keeps the spec's "3 consecutive failures → degraded" meaning 3 *runs*, not 3 × tries. `#[Tries(2)]` is only for bugs or DB blips, which then land in `failed_jobs`. Schedule `queue:prune-failed`.
2. **Uniqueness in two layers.** `ShouldBeUnique` stops the scheduler and "Run now" from stacking duplicate fetches of the same source. `WithoutOverlapping` guards execution if a unique lock expired. `uniqueFor` (900) must be at least the timeout (600), which is less than `retry_after` (900+).
3. **Separate `ingest` connection.** Configure a second `database` connection named `ingest` with `retry_after => 960`, and keep `default` at around 90 s. Run **two worker processes**: `queue:work ingest --queue=ingest` (exactly one process, which makes per-host throttling trivially correct) and `queue:work database --queue=default`. Admin-triggered side effects never wait behind a 2-minute throttled crawl.
4. **Per-host rate limiting lives in the fetcher, not in job middleware.** One source run can hit the same host several times (robots.txt, listing, N detail pages), so a job-level `RateLimited` middleware can't enforce "5 s between requests". `HostThrottle::waitTurn($host)` takes `Cache::lock("throttle:{$host}", 30)->block(30)`, reads `last_request_at:{host}`, sleeps the remainder, then writes now. That's correct across processes because the database cache store supports locks. As defence in depth for the job level, `RateLimiter::for('host', fn ($job) => Limit::perSecond(1, 5)->by($job->host))` exists (`Limit::perSecond($maxAttempts, $decaySeconds)` is in the 13.x source). Releases increment attempts, though, so it isn't needed with a single ingest worker.
5. **Detail-page fan-out stays inside FetchSourceJob**, sequentially, capped by `parser_config.max_follow_ups` (default 25 → about 125 s at 5 s/req, which fits the 600 s timeout). That's simpler than per-URL jobs and keeps run stats atomic. If a source needs more than 100 detail pages, split it into a paged source.
6. **ProcessSourceItemJob is one per changed item**, on the `ingest` queue, with `ShouldBeUniqueUntilProcessing` keyed by `source_item_id`. That allows a newer change to queue while an older one is running, and `WithoutOverlapping("item:{id}")` serialises execution. The job is idempotent: it reloads the item and returns early if `processed_hash === content_hash`. A failure marks the item `status=error` with the message and doesn't affect the run.
7. **No `Bus::batch`.** Run completion doesn't depend on item processing. Counters such as `drafts_created` are updated with atomic `increment()` on `source_runs` from item jobs. This also avoids the "unique doesn't apply in batches" caveat.
8. **Scheduler:** cron `* * * * * php artisan schedule:run`. Tasks: `DispatchDueSources` every 5 min (`withoutOverlapping()`), `calendar:extend-occurrences` daily at 03:10 Europe/London, `model:prune` daily (`MassPrunable` on `source_runs` older than 180 days and on raw files), `queue:prune-failed --hours=720`, and the backup. `onOneServer()` isn't needed on a single VPS.

### Pattern 5: Change detection against an *accepted baseline*, with field-level diff

**What:** Three hashes per source item:
- `raw_hash`: sha256 of the raw fragment. A cheap "skip" check. **Not** used for change decisions, because ICS `DTSTAMP` and feed `lastBuildDate` change on every export.
- `content_hash`: sha256 of the **canonical JSON of the normalised event**. It covers only the fields the product cares about (title, description, start/end UTC, all_day, recurrence spec, venue, address, postcode, price, organiser, contact, url, status), with sorted keys and volatile data excluded. **This is the change signal.**
- `accepted_hash` plus `accepted_payload` (jsonb): the normalised snapshot the admin last approved, applied, or **ignored**.

**Decision table in `ChangeDetector`:**

| Situation | Result |
|---|---|
| Item has no linked event, and no pending draft | `new` draft |
| No linked event, pending `new` draft exists, content changed | supersede pending, new `new` draft |
| Linked event, `content_hash == accepted_hash` | no-op |
| Linked event, candidate status cancelled (`STATUS:CANCELLED`, `EventCancelled`) | `cancellation` draft |
| Linked event, `content_hash != accepted_hash` | `update` draft, diff = `accepted_payload` vs candidate |
| Missed 2 consecutive successful runs and the linked event has future occurrences | `cancellation` draft with reason "missing from source" |

**Why diff against the baseline and not the live event:** if the admin corrected a typo in the published title, diffing source against event would raise a "title changed" update on every run. Diffing source-now against source-then shows only what the **source** changed. The Filament diff then shows three columns, *Published now | Source before | Source now*, and `ApplyUpdate` lets the admin tick which fields to apply. `IgnoreUpdate` sets `accepted_hash` to the candidate hash, so the same change isn't raised again.

**Missing-item detection** (in `SourceItemRecorder`, only after a **successful, non-empty** extraction):
- Seen items: `missed_runs = 0`, `missing_since = null`, `last_seen_at = now`.
- Unseen active items: `missed_runs++`, set `missing_since` on first miss.
- Only items whose last-known start is **in the future** count. Feeds drop past events naturally, and that isn't a cancellation.
- Skipped entirely when the source has `supports_missing_detection = false` (paged or "latest N" feeds, listing pages with pagination), or when the run returned 0 items. Zero items after previous results is flagged as a possible structure change, never as mass cancellation.
- Missing items produce a draft, never an auto-cancel. Source failures never touch events.

**Pending-draft replacement invariant (DB backstop):**
```sql
CREATE UNIQUE INDEX event_drafts_one_pending_per_item
  ON event_drafts (source_item_id) WHERE review_status = 'pending_review' AND source_item_id IS NOT NULL;
```
`DraftWriter` runs `UPDATE … SET review_status='superseded' WHERE source_item_id=? AND review_status='pending_review'` and then the `INSERT`, in one transaction. A race fails loudly on the index instead of creating two pending drafts.

### Pattern 6: Recurrence storage: rule on the event, materialised occurrences, rolling horizon

**Storage:**
- `events.recurrence_rule` (text, the RRULE for display and filtering).
- `events.recurrence_source` (text): the **full VEVENT group** (master + overrides, plus RDATE/EXDATE) for ICS-origin series, or a synthetic VEVENT built for manual series.
- `events.timezone` (default `Europe/London`).
- `events.first_starts_at_local` / `duration_minutes` for non-ICS series.

**One engine:** `sabre/vobject`'s `Recur\EventIterator` (v5 constructor: `__construct($input, ?string $uid = null, ?DateTimeZone $timeZone = null)`, plus `fastForward()`, read from source). It already handles RRULE, RDATE, EXDATE and RECURRENCE-ID overrides, and it guards against runaway rules with `Settings::$maxRecurrences`. Manual series ("every Friday 9–12") are expressed as a synthetic VEVENT with `TZID=Europe/London`, so the same engine expands everything. Use `rlanvin/php-rrule` **only** for the human-readable recurrence summary on drafts and detail pages. **[UNVERIFIED]** whether `humanReadable()` survives unchanged in php-rrule v3.0.0; check during the ICS phase.

**`OccurrenceGenerator::sync(Event $event)`:**
1. Window = `[today 00:00 Europe/London, today + 90 days]`, with the horizon days in config.
2. Expand in the **event's timezone**, so a weekly 10:00 market stays 10:00 across the 25 Oct 2026 / 28 Mar 2027 clock changes, then convert each instance to UTC.
3. Upsert on `(event_id, recurrence_key)`, where `recurrence_key` = the original local start (`RECURRENCE-ID` semantics, e.g. `20261106T100000`). Update `starts_at`, `ends_at` and `expires_at`, but **don't overwrite `status`** if the admin set it to cancelled or hidden.
4. Delete future `scheduled` occurrences in the window that the rule no longer produces. Never delete past occurrences.
5. A source-cancelled single instance (override with `STATUS:CANCELLED`) → occurrence `cancelled`.

It runs **inside** the approve/apply transaction (fast: a weekly series is about 13 rows), and daily from the scheduler to roll the horizon forward. Non-recurring and multi-day events get exactly one occurrence.

### Pattern 7: Timezone rules (UTC storage, Europe/London semantics)

| Input | Rule |
|---|---|
| App config | `config/app.php` timezone = `UTC`. All columns `timestampTz`. Display via `->timezone('Europe/London')` in one Blade/Filament formatter |
| ICS `DTSTART;TZID=…` / `…Z` | Honour as given |
| ICS **floating** time (no TZID, no Z) | Interpret in `source.default_timezone` (default Europe/London). **Pass that timezone explicitly** to `expand()` / `EventIterator` / `getDateTime()`: sabre defaults floating times to **UTC** if none is given (verified in v5 source: `$timeZone ??= new DateTimeZone('UTC')`). This is a one-hour summer-time bug waiting to happen |
| ICS `VALUE=DATE` (all-day) | `all_day = true`. `starts_at` = local midnight → UTC. `ends_at` = DTEND (exclusive) local midnight → UTC. If no DTEND, start + 1 day |
| JSON-LD / feed datetime **with** offset | Honour |
| JSON-LD / selector datetime **without** offset | Interpret as `source.default_timezone`, add a quality warning "no offset; assumed Europe/London" |
| Date with no year / unparseable | Parse error, Low quality. **Never guessed** |
| Date-only value in JSON-LD `startDate` | All-day, with a warning |
| Nonexistent local time (spring-forward gap) | Parse warning. Carbon shifts forward, so show the result to the reviewer |
| `expires_at` (stored, derived on write) | `ends_at` ?? `starts_at + 3h`. All-day: end of the local day (= exclusive `ends_at`) |

`DateWindow` computes the windows in Europe/London and converts them to UTC half-open ranges `[start, end)`. **Today** = local midnight to next midnight. **This weekend** = upcoming Sat 00:00 to Mon 00:00, or the current weekend if today is Sat/Sun. **This week** = now (or today 00:00) to the next Mon 00:00. Test with `travelTo()` on both DST transition dates.

### Pattern 8: Duplicate detection query (pg_trgm)

The cascade follows spec §8.3. Stage 1 isn't dedup: the same (source, external_id) is the same item and goes through ChangeDetector.

1. **Canonical URL.** `EventNormalizer` produces `canonical_url`: lowercase scheme and host, drop the fragment, drop `utm_*`/`fbclid`/`gclid`, strip the trailing slash, sort the query string. Store it on `events`, `event_drafts` and `source_items` with a btree index. An exact match means a strong duplicate.
2. **Fuzzy.** Same London date (or overlapping for multi-day), plus similar venue, plus similar title. **Lead with the date range** (btree on occurrences), which shrinks the candidate set to a handful of rows. Then score with `similarity()` on a pre-computed `match_title`: lowercased, punctuation stripped, stop-words like "the/annual/2026" removed, computed in PHP so it's deterministic and doesn't need the non-immutable `unaccent` in an index expression.

```sql
SELECT e.id, o.starts_at,
       similarity(e.match_title, :title)            AS title_sim,
       (e.venue_id = :venue_id OR e.postcode = :postcode
        OR similarity(e.match_venue, :venue) > 0.5)  AS venue_match
FROM event_occurrences o
JOIN events e ON e.id = o.event_id
WHERE o.starts_at <  :day_end_utc
  AND o.expires_at > :day_start_utc
  AND o.status <> 'hidden'
  AND similarity(e.match_title, :title) >= :title_threshold      -- config, e.g. 0.45
ORDER BY title_sim DESC
LIMIT 5;
```

Run the same query against `event_drafts WHERE review_status='pending_review'`. That catches two sources announcing the same new event before either has been approved.

- Use the **`similarity()` function with an app-config threshold** rather than the `%` operator. `%` depends on the session GUC `pg_trgm.similarity_threshold` (default 0.3), which isn't reproducible from code. Determinism matters more than index use at this scale.
- Still add `CREATE INDEX events_match_title_trgm ON events USING GIN (match_title gin_trgm_ops);`. It's cheap, helps the admin "link to existing event" search, and lets you switch to `%` later if needed. pg_trgm is a *trusted* extension, so a non-superuser with CREATE can install it in a migration: `CREATE EXTENSION IF NOT EXISTS pg_trgm`.
- The result is a **warning on the draft** (`duplicate_of_event_id`, score, reason). It's never an automatic merge.

### Pattern 9: Draft → event approval transaction

```php
final class ApproveDraft
{
    public function __construct(private OccurrenceGenerator $occurrences, private CalendarCacheVersion $cache) {}

    public function __invoke(EventDraft $draft, User $reviewer, array $edits = []): Event
    {
        return DB::transaction(function () use ($draft, $reviewer, $edits) {
            $draft = EventDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail(); // double-click safe
            $draft->review_status->assertCanTransitionTo(ReviewStatus::Approved);
            $data = PublishableEventData::fromDraft($draft, $edits)->validateOrFail(); // §8.1 required fields

            $event = Event::create($data->toEventAttributes() + [
                'status' => EventStatus::Published, 'approved_by' => $reviewer->id, 'approved_at' => now(),
            ]);
            $this->occurrences->sync($event);

            if ($draft->source_item_id) {
                $draft->sourceItem->update([
                    'event_id' => $event->id,
                    'accepted_hash' => $draft->content_hash,
                    'accepted_payload' => $draft->normalized_payload,
                ]);
            }
            EventSource::create(['event_id' => $event->id, 'source_item_id' => $draft->source_item_id,
                                 'draft_id' => $draft->id, 'relationship' => 'primary']);

            $draft->update(['event_id' => $event->id, 'review_status' => ReviewStatus::Approved,
                            'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);

            DB::afterCommit(fn () => $this->cache->bump());   // public cache invalidation
            return $event;
        });
    }
}
```

The sibling actions follow the same lock → assert → mutate → occurrences → baseline → audit → bump shape:
- `ApplyUpdate(draft, fields[])` applies the selected fields.
- `ConfirmCancellation` sets the event to cancelled, or a single occurrence when the draft targets one instance.
- `MarkDuplicate(draft, event)` adds an `event_sources` row with `relationship=duplicate` and sets `source_item.event_id`, so later cancellations from that source are still noticed.
- `RejectDraft` changes status and adds a note.
- `CreateManualEvent` builds an `EventCandidate` → a draft with origin `manual` → `ApproveDraft` in the same request. Manual events get the same audit trail with no special case.

**Policy for secondary (duplicate-linked) sources:** only *cancellations* from secondary sources create drafts. Field changes from secondaries are recorded on the item but not drafted, to avoid two sources fighting over one event. Admin can "promote to primary". **[Design decision; confirm in the review-queue phase discussion.]**

### Pattern 10: Raw content storage and retention

| What | Where | Retention | Why |
|---|---|---|---|
| Item raw fragment (VEVENT group, `<item>`, JSON-LD block, HTML node; usually <20 KB) | `source_items.raw_content` (text) | Lifetime of the item | Backs the draft's "raw content" link and allows `ingest:reprocess {source}` after a parser fix **without refetching**. Small, compresses via TOAST |
| Full response body | `storage/app/raw/{source_id}/{sha256}.gz` (content-addressed; `source_runs.raw_path`) | 30 days (pruned by a scheduled command; `MassPrunable` on runs deletes the paths) | Debugging, building new fixtures, proving what was fetched. Content addressing means unchanged pages cost nothing. Keeps DB backups small. Disposable |
| Normalised payload + hashes | `source_items` / `event_drafts` (jsonb) | Lifetime | Change detection baseline and diffs |
| `source_runs` | DB | 180 days (`MassPrunable`) | Health history, dashboard |

Don't store full pages in Postgres. Twenty sources × 4 runs/day × 200 KB is about 0.5 GB/month of churn that only bloats backups. On a small VPS the DB backup is the one thing that has to be cheap and reliable.

### Pattern 11: Public query performance over occurrences

```sql
-- the only hot query
SELECT o.*, e.slug, e.title, ...
FROM event_occurrences o JOIN events e ON e.id = o.event_id
WHERE e.status = 'published'
  AND o.status IN ('scheduled','cancelled')
  AND o.expires_at > :now_utc          -- not expired (spec §8.1)
  AND o.expires_at > :window_start     -- overlaps window start
  AND o.starts_at  < :window_end       -- overlaps window end
  [AND e.category_id = :cat]
ORDER BY o.starts_at
LIMIT 50;
```

- **Index:** `CREATE INDEX occ_public ON event_occurrences (starts_at, expires_at) WHERE status <> 'hidden';` plus a btree on `event_occurrences(event_id)`. With fewer than 10k future rows a range scan on `starts_at` is sub-millisecond. Don't use `tstzrange` + GiST or `btree_gist` yet; that's premature.
- **Denormalise `expires_at`** on write. Don't compute `COALESCE(ends_at, starts_at + interval '3 hours')` in the WHERE clause, because that defeats the index.
- **Multi-day events** naturally appear in every overlapping window because of the overlap predicate. Ordering by `starts_at` means an ongoing festival sorts first, and the card shows "until Sun".
- **Caching:** cache window results for 5 minutes under `calendar:v{version}:{window}:{cat}:{page}`. The database cache store **doesn't support tags** (Laravel 13 cache docs), so invalidate by bumping an integer version key in `DB::afterCommit` from every review action and from occurrence sync. The Livewire component uses `#[Url]` properties for window and category, so filtered URLs are shareable and crawlable. Event detail pages are plain Blade controllers, not Livewire, for SEO and caching simplicity.

### Pattern 12: Filament review queue structure

Use a **Resource** (`EventDraftResource`) for the queue, plus **one custom record page** for fast sequential review. Not a standalone custom page: you'd lose the free table, filters, bulk actions and policies. The Filament 5.x mechanics below were verified in the docs: `getTabs()` with `Tab::make()->modifyQueryUsing()` and badges; `BulkAction` in `toolbarActions()` with `authorizeIndividualRecords()`, `reportBulkProcessingFailure()`, `deselectRecordsAfterCompletion()`, `checkIfRecordIsSelectableUsing()` and `chunkSelectedRecords()`; custom resource record pages via `InteractsWithRecord`; infolists with custom Blade entries.

| Page | Contents |
|---|---|
| `ListEventDrafts` | Default query `pending_review`. **Tabs** (badge counts): New · Updates · Cancellations · Possible duplicates · Low quality · All/History. **Filters:** source, origin, quality band, duplicate flag, date range. **Columns:** title, start (London), source, method badge, quality badge (tooltip = reasons), duplicate icon, parse-error icon. **Record actions:** Approve, Reject (reason select + note), Review (opens custom page). **Bulk:** Approve and Reject, with `checkIfRecordIsSelectableUsing(pending && kind=new && no parse errors)`, `reportBulkProcessingFailure()` per failed draft, and each record calling the **same** `ApproveDraft` action in its own transaction |
| `ReviewEventDraft` (custom, `/{record}/review`) | Infolist sections: extracted fields · recurrence summary + next 5 occurrences preview · **Source (always visible):** name, URL, method, raw content link · quality score + reasons · parse errors · duplicate panel (side-by-side with the matched event, "Mark duplicate" / "Not a duplicate") · **diff panel** for updates (custom Blade entry: field / published now / source before / source now, with checkboxes). Header actions vary by kind: Approve / Edit & approve / Reject / Mark duplicate / Apply selected / Ignore update / Confirm / Ignore cancellation. Each finishes with **"→ next pending"** navigation. This page is what gets the admin under 2 h/week |
| `EditEventDraft` | Standard form for corrections, with a "Save & approve" header action → `ApproveDraft($draft, $user, $edits)` |
| `SourceResource` | Form whose `parser_config` fields switch by `extraction_method` (selector schema builder for rung 4). Header actions: **Test run** (synchronous, dry-run pipeline, max 1 page + 3 follow-ups, results in a modal table with candidates, errors and location verdicts; creates nothing) and **Run now** (dispatches `FetchSourceJob(manual: true)`). Runs relation manager. Health badge |
| `EventResource` | Edit / hide / cancel published events. Occurrences relation manager with per-occurrence Cancel / Hide. Sources relation manager (traceability) |

Filament action closures stay one line (`->action(fn (EventDraft $r) => app(ApproveDraft::class)($r, auth()->user()))`), and `visible()` delegates to `ReviewStatus::canTransitionTo()` plus the draft kind.

---

## Data Flow

### Ingestion flow

```
schedule:run (every min) ─► DispatchDueSources (every 5 min)
    └─► FetchSourceJob(source)  [ingest queue, unique per source]
          HttpFetcher: robots ✓ → HostThrottle ✓ → GET (If-None-Match / If-Modified-Since)
            ├─ 304 / same raw body hash ─► run=not_modified ─► stop
            └─ 200 ─► Extractor.extract() (+ followUps, throttled) ─► RawItem[]
                  SourceItemRecorder: upsert (source_id, external_id), raw_hash, normalise→content_hash
                    ├─ unchanged items: touch last_seen_at
                    ├─ missing items: missed_runs++ (rules above) ─► at 2 → mark for cancellation check
                    └─ changed/new/missing-threshold items ─► ProcessSourceItemJob(id)->afterCommit()
ProcessSourceItemJob(item)
    Normalizer ─► LocationFilter ──out_of_area──► item.status=out_of_area, run.items_out_of_area++ (no draft)
               ─► CategoryAssigner ─► ChangeDetector ──no-op──► processed_hash=content_hash
               ─► DuplicateDetector (new only) ─► QualityScorer ─► DraftWriter (supersede + insert)
```

### Review → publish flow

```
Admin (Filament) ─► Review action ─► DB::transaction {
      lock draft ► assert transition ► create/update Event ► OccurrenceGenerator.sync
      ► source_item baseline (accepted_hash) ► event_sources ► draft reviewed_by/at
} ─► afterCommit: bump calendar cache version
```

### Public read flow

```
GET /?when=weekend&category=music ─► Livewire Calendar ─► DateWindow(Europe/London→UTC)
   ─► Cache::remember(calendar:v{n}:…, 300) ─► PublicOccurrenceQuery ─► cards
GET /events/{slug} ─► EventShowController ─► Blade + spatie/schema-org JSON-LD
```

### Other producers

- **Organiser submission:** validated Form Request → `EventCandidate` (origin `submission`) → `QualityScorer` + `DuplicateDetector` → `DraftWriter`. No `source_item`.
- **Manual entry:** Filament form → `CreateManualEvent` → draft (origin `manual`) → `ApproveDraft` immediately.

---

## Scaling Considerations

Realistic load: 20–50 sources, about 200–1,000 occurrences per 90 days, and a few hundred visitors a day with spikes from local Facebook shares.

| Scale | Architecture Adjustments |
|-------|--------------------------|
| Launch (≤1k visits/day) | Single VPS: Nginx + PHP-FPM + Postgres + 2 queue workers (systemd/supervisor) + cron. Database queue and cache. Nothing else |
| Spike (10k visits/day from a viral share) | 5-min calendar cache + `Cache-Control: public, max-age=300` on detail pages. Optionally Cloudflare free in front (Turnstile already uses Cloudflare) |
| Growth (more towns, 500+ sources) | Redis for queue and cache (swap drivers, no code change). Several ingest workers, which is the point where the cache-lock `HostThrottle` matters. Move raw bodies to object storage |

### Scaling Priorities

1. **First bottleneck: VPS RAM, not queries.** PHP-FPM children, two workers and Postgres on 2–4 GB. Use `queue:work --max-time=3600 --memory=192` and tune `pm.max_children`. Restart workers on deploy (`queue:restart`).
2. **Second: long ingest runs delaying each other** on one ingest worker. Mitigate with `check_frequency`, the follow-up cap and conditional GETs. Add a second ingest worker only once the throttle is proven correct across processes.

---

## Anti-Patterns

### Anti-Pattern 1: Hashing raw responses to detect *changes*
**What people do:** create an update draft whenever the fetched bytes differ.
**Why it's wrong:** `DTSTAMP`, build timestamps, CSRF tokens and ad markup change on every fetch, so the admin drowns in empty updates.
**Do this instead:** use the raw hash only for skipping. Decide changes on the hash of the normalised payload, compared with the accepted baseline.

### Anti-Pattern 2: Using queue retries as the source-failure policy
**What people do:** `$tries = 5` on the fetch job and count failed jobs as source failures.
**Why it's wrong:** health counters inflate per attempt, retries hammer a struggling small site, and `failed_jobs` fills with expected outages.
**Do this instead:** record expected failures on `source_runs` and complete the job. Reserve queue retries for bugs.

### Anti-Pattern 3: Job-level rate limiting for per-host politeness
**What people do:** `RateLimited` middleware keyed by host.
**Why it's wrong:** a single job makes several requests to the host, and each rate-limit release burns an attempt.
**Do this instead:** throttle per request inside `HttpFetcher` with `HostThrottle`.

### Anti-Pattern 4: Expanding RRULEs in UTC, or letting floating times default to UTC
**What people do:** convert DTSTART to UTC, then apply the RRULE, or call `expand()` with no timezone.
**Why it's wrong:** every instance after a DST change shifts by an hour, and floating ICS times land an hour early all summer.
**Do this instead:** expand in the event or source timezone and convert each instance afterwards. Always pass `Europe/London` to sabre.

### Anti-Pattern 5: Regenerating occurrences by delete-all + insert
**Why it's wrong:** it loses admin per-occurrence cancellations or hides, and changes occurrence IDs that links might rely on.
**Do this instead:** upsert on `(event_id, recurrence_key)` and preserve `status`.

### Anti-Pattern 6: Storing "expired" or "pending" on events
**Why it's wrong:** it duplicates state that time or drafts already imply, and the two drift apart (the v1.0 spec problem).
**Do this instead:** derive expiry from `expires_at`. Pending lives only on drafts.

### Anti-Pattern 7: Business logic in Filament action closures
**Why it's wrong:** it can't be tested without Livewire, and bulk approve diverges from single approve.
**Do this instead:** put the logic in invokable action classes. Filament only calls them.

### Anti-Pattern 8: Treating missing feed items as cancellations
**Why it's wrong:** past events and paginated "latest 10" feeds drop items legitimately.
**Do this instead:** only count future-dated items, only on non-empty successful runs, and only on sources flagged `supports_missing_detection`. The result is always a draft.

### Anti-Pattern 9: Using the `%` trigram operator with the default GUC as the duplicate rule
**Why it's wrong:** the threshold lives in the DB session rather than in code, so behaviour isn't reproducible.
**Do this instead:** use `similarity() >= config('ingestion.dedup.title_threshold')` after a date-range prefilter.

---

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| Source websites (ICS/RSS/HTML/JSON) | `HttpFetcher` (Laravel Http/Guzzle). UA with contact URL, connect timeout 5 s, total 20 s, max body 5 MB (abort via Guzzle `on_headers`/`progress` options), max 5 redirects with a robots check on the final host | Admin-entered URLs only. Still reject private/loopback IPs (cheap SSRF hygiene) |
| robots.txt | `RobotsPolicy`, cached 24 h per host | Disallowed → run `blocked`, not `failed` (doesn't degrade health) |
| Cloudflare Turnstile | Server-side verify on the submission form | Only external call on the public request path |
| Cookieless analytics | Script tag only | No backend integration |
| Postcode → lat/lng | **Not in MVP.** Geofence uses postcode districts, place names, known venues and JSON-LD `geo` | postcodes.io is an option later **[UNVERIFIED]**. It would add a network dependency to a deterministic pipeline |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| Extractors ↔ Pipeline | `EventCandidate` DTOs only | Extractors never touch Eloquent writes. Pure input → output, fixture-tested |
| Ingestion ↔ Review | `event_drafts` table, written only via `DraftWriter` | Partial unique index enforces one pending draft per item |
| Review ↔ Calendar | Direct calls (`OccurrenceGenerator::sync`) inside the review transaction | Synchronous: no queue dependency for publish correctness |
| Calendar ↔ Public UI | `PublicOccurrenceQuery` + versioned cache | Read-only |
| Filament ↔ Domain | Action classes + enum `canTransitionTo()` | Filament holds no rules |

---

## Build Order (dependency graph for the roadmap)

```
P0 Foundation ──► P1 Published core + manual entry + public calendar ──► P2 Drafts + review queue (+ submissions)
                                                                              │
Source research (parallel, non-code; produces fixtures) ──────────────────┐   ▼
                                                                          └► P3 Ingestion backbone + ICS
                                                                                  │
                                                                                  ▼
                                                                    P4 Change detection, duplicates,
                                                                       updates/cancellations, missing items
                                                                                  │
                                                                                  ▼
                                                                    P5 Remaining ladder rungs + geofence + Test run
                                                                                  │
                                                                                  ▼
                                                                    P6 SEO, legal, dashboard, ops hardening, launch
```

| # | Phase | Builds | Depends on | Why here |
|---|-------|--------|-----------|----------|
| P0 | **Foundation** | Laravel 13 + Filament 5 + Livewire 4 skeleton, Postgres with `pg_trgm` migration, UTC app tz + London display formatter, database queue/cache (two connections), Pest + `Http::preventStrayRequests`, all enums, core migrations (categories, venues, events, event_occurrences, event_sources), CI, **deploy to the VPS early** (cron + 2 workers) | none | Ops risk on a £5 VPS is cheapest to retire first. Enums and schema are shared by everything |
| P1 | **Published core** | `OccurrenceGenerator` (single + multi-day + manual weekly/monthly series via synthetic VEVENT), `DateWindow`, `PublicOccurrenceQuery`, Livewire calendar with filters, detail page, `EventResource`, **manual entry** (temporarily direct; rewired through drafts in P2), cache versioning | P0 | Delivers a usable site immediately, and builds the target that approvals write into. DST/all-day/window tests happen before any parser exists |
| P2 | **Drafts + review queue** | `event_drafts`, `DraftWriter`, `QualityScorer`, `CategoryAssigner`, `ApproveDraft`/`Reject`/`EditAndApprove`, `EventDraftResource` (tabs, filters, bulk, custom Review page), **organiser submission form** as the first draft producer, manual entry rewired as auto-approved draft | P1 | The review UX can be built and tested with no crawling at all. The submission form is the simplest producer |
| — | **Source research** (parallel with P1–P2) | `docs/research/event-sources.md`, saved fixtures per candidate source, live verification of TEC/Squarespace endpoints | none | P3/P5 extractors need real fixtures. Needs owner input for URLs |
| P3 | **Ingestion backbone + ICS** | `sources`, `source_runs`, `source_items`; `HttpFetcher` (robots, throttle, conditional GET, limits); `ExtractorRegistry`; `IcsExtractor` (UID grouping, recurrence spec, floating/all-day); `SourceItemRecorder`; `FetchSourceJob` / `ProcessSourceItemJob`; `DispatchDueSources`; health tracking; `SourceResource` with Run now; raw storage + pruning | P2 (drafts exist), research fixtures | ICS is rung 1 and exercises recurrence end to end. It proves the whole fetch → draft → approve → occurrences chain |
| P4 | **Change & duplicate handling** | Normalised `content_hash`, accepted baseline, `ChangeDetector` + diff, update/cancellation drafts, `ApplyUpdate`/`Ignore`/`ConfirmCancellation`, missing-item detection, `DuplicateDetector` (URL + trigram vs events and pending drafts), `MarkDuplicate`, per-occurrence cancel from ICS overrides | P3 | Needs real items flowing and published events to compare against. This is the subtlest logic, so isolate it in its own phase |
| P5 | **Remaining ladder + geofence** | `FeedExtractor`, `JsonLdEventExtractor` (+ follow-ups), ≥1 verified platform adapter (TEC or Squarespace), `SelectorExtractor` + Filament config builder, `LocationFilter` (`filter` mode), **Test run** (dry-run pipeline), `ingest:reprocess` | P3 (contract), P4 (so new rungs get updates/dedup for free), research | Each extractor is an independent plan within the phase, since they share the contract and fixture harness. Geofence only matters once regional (JSON-LD/Eventbrite) sources exist |
| P6 | **Launch readiness** | Schema.org JSON-LD output, meta/OG, sitemap, privacy notice + takedown page, cookieless analytics, dashboard widgets (counts, approval rate per source, unhealthy sources), structure-change alerts, backups, accessibility pass, `calendar:extend-occurrences` verification across a horizon roll | P1–P5 | Polish and compliance. SEO could move earlier if a soft launch is wanted after P2 |

**Research flags for phases:**
- **P3:** needs focused research on sabre/vobject **5.0** typed API changes, RECURRENCE-ID override handling and `maxRecurrences`, and the PHP 8.3 vs 8.4 choice (Symfony 8 DomCrawler and spatie/model-states require 8.4).
- **P5:** live verification of TEC REST/ICS and Squarespace `?format=json`/`?format=ical`, brick/structured-data 0.2 suitability vs an in-house JSON-LD walker, and a feed library choice (laminas-feed vs SimplePie).
- **P4:** tune trigram thresholds against real fixture pairs. Confirm the secondary-source update policy with the owner.
- **P0, P1, P2, P6:** standard Laravel/Filament patterns. Research is unlikely to be needed.

---

## Sources

The research seam tiers all of these as LOW (`webfetch`) because Context7 wasn't available. Each was read verbatim from the official source on 2026-10-16.
- Laravel 13.x Queues docs, `laravel/docs` branch 13.x `queues.md`: Unique Jobs (`ShouldBeUnique`, `#[UniqueFor]`, `ShouldBeUniqueUntilProcessing`, database cache lock support, batch caveat), Debounced Jobs, Job Middleware (`RateLimited`, `WithoutOverlapping` incl. `expireAfter`/`shared`, `ThrottlesExceptions`), `#[Tries]`/`#[Backoff]`/`#[Timeout]` attributes, `after_commit`/`afterCommit()`, `retry_after` vs `--timeout`.
- Laravel 13.x Scheduling docs (`scheduling.md`): `withoutOverlapping`, `onOneServer`, `runInBackground`.
- Laravel 13.x Cache docs (`cache.md`): database driver, atomic locks, "cache tags are not supported when using the … database … drivers".
- Laravel 13.x Eloquent docs (`eloquent.md`): `Prunable` / `MassPrunable`, `model:prune`. `eloquent-mutators.md`: enum casting.
- Laravel 13.x HTTP Client docs (`http-client.md`): `retry`, `timeout`, `connectTimeout`, `withOptions`, `Http::fake`.
- `laravel/framework` 13.x source, `src/Illuminate/Cache/RateLimiting/Limit.php`: `Limit::perSecond($maxAttempts, $decaySeconds = 1)`.
- Filament 5.x docs, `filamentphp/filament` branch 5.x: `docs/03-resources/02-listing-records.md` (tabs), `12-custom-pages.md` (`InteractsWithRecord`), `05-viewing-records.md` (infolist view pages), `packages/tables/docs/04-actions.md` (bulk actions, `authorizeIndividualRecords`, `reportBulkProcessingFailure`, `checkIfRecordIsSelectableUsing`, `chunkSelectedRecords`).
- sabre/vobject master source (v5.0.0): `VCalendar::expand(DateTimeInterface, DateTimeInterface, ?DateTimeZone)` defaulting to UTC, `Recur\EventIterator::__construct($input, ?string $uid, ?DateTimeZone)` with floating-time reference timezone, `Settings::$maxRecurrences`, `DateTime::isFloating()/hasTime()`. CHANGELOG 5.0.0 (2026-07-07).
- PostgreSQL 18 docs, F.35 pg_trgm: `similarity`, `word_similarity`, `pg_trgm.similarity_threshold` default 0.3, `gin_trgm_ops`/`gist_trgm_ops`, trusted extension.
- Packagist / GitHub releases API (2026-10-16) for the version and PHP-constraint facts in the header.
- Design judgement (MEDIUM, not externally sourced): the accepted-baseline diff, secondary-source policy, raw storage split, two-connection worker layout and build order. They're derived from the mechanics above and the v1.1 spec §6–§12.

---
*Architecture research for: deterministic local-events ingestion + review pipeline (Laravel 13 / Filament 5 / Livewire 4 / PostgreSQL)*
*Researched: 2026-10-16*
