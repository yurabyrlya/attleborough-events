# Phase 4: Extraction Ladder, Geofence & Live Sources - Pattern Map

**Mapped:** 2026-10-18
**Files analyzed:** 68 (33 new, 35 modified), plus fixtures and docs
**Analogs found:** 66 / 68 (two have only partial analogs: `Html/SelectorConfig.php` and `Adapters/FseSchoolCalendarExtractor.php`. See "No Analog Found")

> **Scope:** CONTEXT.md decisions, with R-01..R-12 taking precedence over D-items, plus RESEARCH.md "Recommended Project Structure", "Hard-wired ICS points" and "Recommended Plan Breakdown".
> **Tracked-source gate:** every analog path below was checked with `git ls-files`. None is a mirror or untracked file.
> **Project rules that apply to every file:**
> - Time is UTC in the database. Date arithmetic happens in Europe/London.
> - No writes to `events` or `event_occurrences` outside `App\Publishing\Actions`. Ingestion writes drafts only, via `DraftWriter`.
> - Filament 5 namespaces.
> - Pest 5 on PostgreSQL, run offline (`Http::preventStrayRequests()` is global).
> - No new composer dependencies (R-04).

---

## File Classification

### New files — `app/Ingestion/**`

| New file | Role | Data flow | Closest analog | Match |
|---|---|---|---|---|
| `app/Ingestion/Extraction/Extractor.php` (interface) | contract | transform | `app/Ingestion/Ics/IcsExtractor.php::extract()` (l.75) | exact (signature) |
| `app/Ingestion/Extraction/ExtractorRegistry.php` | service / dispatch | request-response | `app/Enums/IdentityStrategy.php::forSource()` + `app/Ingestion/Http/BodyValidator.php::validate()` `match` | role-match |
| `app/Ingestion/Extraction/SourceReader.php` (interface) + `ReaderRegistry.php` | contract / dispatch | request-response | `app/Ingestion/IngestRun.php::request()` + `execute()` (l.86-160) | role-match |
| `app/Ingestion/Extraction/SinglePageReader.php` | service | request-response (one fetch) | `app/Ingestion/IngestRun.php::request()` (l.134-160) | exact (code moved out of IngestRun) |
| `app/Ingestion/Extraction/FetchEnvelope.php` | value object | transform (JSON envelope encode/decode) | `app/Ingestion/Http/FetchResult.php`, `app/Ingestion/Data/ExtractionResult.php` | role-match |
| `app/Ingestion/Tec/TecRestReader.php` | service | multi-page fetch (batch) | `IngestRun::request()` + `HttpFetcher::fetch()` redirect loop (l.81-108) | role-match |
| `app/Ingestion/Tec/TecRestExtractor.php` | extractor (pure) | transform | `app/Ingestion/Ics/IcsExtractor.php` | exact |
| `app/Ingestion/JsonLd/JsonLdExtractor.php` | extractor (pure) | transform | `app/Ingestion/Ics/IcsExtractor.php` | exact |
| `app/Ingestion/JsonLd/JsonLdEventWalker.php` | utility (framework-free) | transform | `IcsExtractor::groups()` (l.136-153): group-then-merge by identity | role-match |
| `app/Ingestion/JsonLd/JsonLdDate.php` | utility | transform | `Dates/StrictDate` (new) + `IcsExtractor::timedSchedule()/allDaySchedule()` | role-match |
| `app/Ingestion/Html/HtmlSelectorExtractor.php` | extractor (pure) | transform | `app/Ingestion/Ics/IcsExtractor.php` | exact |
| `app/Ingestion/Html/SelectorConfig.php` | value object + validation | transform | `app/Enums/IdentityStrategy.php::forSource()` (reads `parser_config`) | partial |
| `app/Ingestion/Rss/RssExtractor.php` | extractor (pure) | transform | `app/Ingestion/Ics/IcsExtractor.php` | exact |
| `app/Ingestion/Rss/RssLinkFollowReader.php` | service | multi-page fetch | `Tec/TecRestReader.php` (new, same envelope) | role-match |
| `app/Ingestion/Adapters/FseSchoolCalendarExtractor.php` | extractor (pure) | transform | `app/Ingestion/Ics/IcsExtractor.php` (fatal-result style) | partial |
| `app/Ingestion/Dates/StrictDate.php` | utility (framework-free) | transform | `IcsExtractor::londonDate()` (l.655-665), RESEARCH "Strict date helper" | role-match |
| `app/Ingestion/Dates/YearInference.php` | utility | transform | RESEARCH "Year inference" + `StrictDate` | role-match |
| `app/Ingestion/Dates/TimeFinder.php` | utility | transform | `IcsItemNormaliser::POSTCODE_PATTERN` (fixed code regex) | role-match |
| `app/Ingestion/Pipeline/Geofence.php` (+ `OnlineDetector`) | pipeline rule (pure, plus reads `GeofenceTerm`) | transform / filter | `app/Ingestion/Pipeline/TitleRules.php` | exact |
| `app/Ingestion/Support/UkPostcode.php` | utility | transform | `IcsItemNormaliser::location()` postcode part (l.68-86) | exact |
| `app/Ingestion/Support/TextNormaliser.php` | utility | transform | `app/Ingestion/Ics/IcsItemNormaliser.php` (`line`, `description`, `plain`) | exact |

### New files — database, tests, fixtures

| New file | Role | Data flow | Closest analog | Match |
|---|---|---|---|---|
| `database/migrations/2026_10_19_xxxxxx_widen_ingestion_methods_and_counters.php` | migration | DDL | `database/migrations/2026_10_18_100400_add_ingestion_columns_to_event_drafts_and_events.php` (DROP/ADD CHECK) + `2026_10_18_100800_add_source_id_to_category_mappings_table.php` (alter existing table) | exact |
| `tests/Unit/Architecture/DateParsingBanTest.php` | test (arch) | — | `tests/Unit/Architecture/WriteChokePointTest.php` | exact |
| `tests/Unit/Ingestion/Dates/StrictDateTest.php`, `YearInferenceTest.php`, `TimeFinderTest.php` | test (unit) | — | `tests/Unit/Ingestion/TitleRulesTest.php` | exact |
| `tests/Unit/Ingestion/JsonLdEventWalkerTest.php` | test (unit) | — | `tests/Unit/Ingestion/IcsExtractorTest.php` | exact |
| `tests/Feature/Ingestion/TecRestRunTest.php` | test (feature) | — | `tests/Feature/Ingestion/RunNowTracerTest.php` | exact |
| `tests/Feature/Ingestion/TecIcsEquivalenceTest.php` | test | — | `tests/Unit/Ingestion/IcsExtractorTest.php` (`ingestExtract` helper) | role-match |
| `tests/Feature/Ingestion/JsonLdRunTest.php`, `JsonLdCancellationTest.php` | test (feature) | — | `RunNowTracerTest.php` + `tests/Feature/Ingestion/CancellationDraftTest.php` | exact |
| `tests/Feature/Ingestion/HtmlSelectorRunTest.php` | test (feature) | — | `RunNowTracerTest.php` | exact |
| `tests/Feature/Ingestion/RssRunTest.php` | test (feature) | — | `RunNowTracerTest.php` | exact |
| `tests/Feature/Ingestion/GeofenceTest.php` | test (feature) | — | `tests/Feature/Ingestion/PreviewRunTest.php` + `tests/Feature/Filament/RulesMappingsGeofenceTest.php` | role-match |
| `tests/Feature/Ingestion/FseSchoolCalendarTest.php` | test (feature) | — | `RunNowTracerTest.php` | exact |
| `tests/Fixtures/sources/*/derived-*.{json,html,xml}` + manifest entries | fixture | — | `tests/Fixtures/sources/attleborough-heritage-group/manifest.json` | exact |

### Modified files

| Modified file | Role | Change | Pattern source |
|---|---|---|---|
| `app/Ingestion/IngestRun.php` | orchestrator | Inject the registries in place of `IcsExtractor` (l.78). Incomplete-run handling. Per-page `startedAt`. Preview `skipped` keys. Null-start rows | self (existing `timedOut()` / `finish(keepValidators:)`) |
| `app/Ingestion/Http/FetchRequest.php` | value object | `METHOD_*` constants per rung. Optional `allowErrorBody` flag (R-03) | self (l.15) |
| `app/Ingestion/Http/HttpFetcher.php` | service | `ACCEPT_*` per method. `icsHeaders()` → `headers()`. `rest_no_route` 404 branch (R-03) | self (l.59, l.327-344, l.415-417) |
| `app/Ingestion/Http/BodyValidator.php` | validator | `validateJson/Html/Feed`. LiteSpeed and specific wall markers | self (`validateIcs()` l.43-67) |
| `app/Ingestion/Data/ExtractionResult.php` | value object | `bool $complete = true`, `?string $note`. Generalise the `eventCount` doc | self |
| `app/Ingestion/Data/ExtractedItem.php` | value object | Optional `array $raw` for selector raw rows (D-17). Doc generalised beyond ICS | self |
| `app/Ingestion/Data/NormalisedFacts.php` | value object | `?string $startDate` (R-07). `?string $statusNote`. Remove `CarbonImmutable::parse` (l.204) | self |
| `app/Ingestion/Pipeline/ContentHasher.php` | service | Canonical-JSON `structuredRawHash()`. `statusNote` stays out of `contentHash` | self (`contentHash()` l.65-88) |
| `app/Ingestion/Pipeline/SourceItemRecorder.php` | pipeline | `SKIPPED_OUT_OF_AREA`, `SKIPPED_ONLINE` + `COUNTER`. Geofence inside `classify()`. Tracked items bypass it | self (l.73-92, l.438-466) |
| `app/Ingestion/Pipeline/WindowClassifier.php` | pipeline | Null-start → `IN_WINDOW` (draft, flagged) | self (l.47-55) |
| `app/Ingestion/Pipeline/DuplicateDetector.php` | pipeline | Replace `CarbonImmutable::parse` (l.244, 252, 268) | `IcsExtractor::londonDate()` (`createFromFormat('!…')`) / `StrictDate` |
| `app/Ingestion/Http/RobotsPolicy.php` (l.106), `app/Ingestion/Http/HostThrottle.php` (l.62, 65) | infra | Replace `CarbonImmutable::parse($row->…, 'UTC')` | same as above (DB timestamp format via `createFromFormat`) |
| `app/Ingestion/Pipeline/MissingItemDetector.php` (l.66), `app/Ingestion/Ics/IcsExtractor.php` (l.657) | pipeline | Already use `createFromFormat('!Y-m-d')`. Route through `StrictDate` if the token scan covers `createFromFormat` (R-08) | `Dates/StrictDate` |
| `app/Ingestion/Health/SourceHealthTracker.php` | service | Force `is_complete = false` for an incomplete envelope | self (`record()` l.54-71) |
| `app/Enums/ExtractionMethod.php` | enum | Cases `TecRest`, `JsonLd`, `HtmlSelectors`, `Rss`, `Adapter` | self |
| `app/Models/SourceRun.php` | model | `COUNTERS` + `skipped_online` | self (l.63-67) |
| `app/Review/Data/DraftData.php` | DTO | l.276 `ExtractionMethod::Ics` → `$source->extraction_method` | self |
| `app/Review/QualityScorer.php` | service | Reason "Location unknown: check it's in the Attleborough area" | self (`$reasons[]` blocks l.84-104) |
| `app/Filament/Resources/Sources/Schemas/SourceForm.php` | Filament form | Method options, adapter select, RSS mode, selector section, `YEAR_POLICIES` + `infer_with_weekday`, method lock, URL label/help per method | self |
| `app/Filament/Resources/Sources/Schemas/SourceFieldHelp.php` | Filament help | New `TOPICS` for every new field. Rewrite `url`, `extraction_method`, `adapter`, `year_policy` | self |
| `app/Filament/Resources/Sources/Pages/EditSource.php` | Filament page | Preview skip labels, `out_of_area`/`online` reasons, raw rows, "No date found". Method change clears validators | self (l.69-82, l.205-281) |
| `resources/views/filament/resources/sources/preview.blade.php` | Blade view | Raw selector rows table, location-unknown flag | self |
| `app/Filament/Resources/Sources/RelationManagers/RunsRelationManager.php` | Filament table | `skipped_online` column | self (l.83-85) |
| `database/seeders/SourceSeeder.php` | seeder | Per-entry method / location / year / parser_config. New slugs (R-01, D-07, D-18) | self |
| `database/factories/SourceFactory.php` | factory | `tecRest()`, `jsonLd()`, `htmlSelectors()`, `rss()`, `fseSchoolCalendar()`, `filter()` | self (`ics()` l.41-47) |
| `config/ingest.php` | config | `tec_page_cap` (20), `link_follow_cap` (20), `max_envelope_bytes` (10 MiB) | self (`max_events` l.56) |
| `tests/Feature/Ingestion/PreviewRunTest.php` | test | Skipped keys now 6 (l.~103 `toHaveCount(4)`). Multi-page reuse | self |
| `tests/Feature/Ingestion/IngestionSchemaTest.php` | test | l.100-108 "refuses a non-ics extraction method" must switch to an unknown value | self |
| `tests/Feature/Filament/SourceEditSafetyTest.php` | test | Method change refused once items exist; clears validators | self (l.123-160) |
| `tests/Feature/Filament/SourceFieldHelpTest.php` | test | Dataset over `TOPICS` keys already covers new topics. New section-level help needs the `schemaComponent` key branch | self (l.21-28) |
| `tests/Feature/Review/SourceSeederTest.php` | test | l.41 slug list, l.126 "exactly three" → new count; form-validation loop (l.99-113) covers new entries | self |
| `tests/Browser/SourcesTest.php`, `tests/Browser/UatFixtures.php` | browser test | F15 update, new F21/F22. `fakeIngestion()` hard-codes `text/calendar` (l.200) | self |
| `docs/uat/browser-uat.md`, `.planning/ROADMAP.md`, `.planning/REQUIREMENTS.md` | docs | Flows, D-19 / RES-04 wording | — |

---

## Pattern Assignments

### `app/Ingestion/Extraction/Extractor.php` + every extractor (`Tec/TecRestExtractor`, `JsonLd/JsonLdExtractor`, `Html/HtmlSelectorExtractor`, `Rss/RssExtractor`, `Adapters/FseSchoolCalendarExtractor`)

**Analog:** `app/Ingestion/Ics/IcsExtractor.php`

**Class docblock + constructor shape** (l.33-73): a "Pure: no database, no network, no writes." contract, `final class`, constructor-injected helpers, and a `LONDON` constant:
```php
 * Pure: no database, no network, no writes.
 */
final class IcsExtractor
{
    /** external_id column length. */
    private const int MAX_EXTERNAL_ID = 255;

    private const string LONDON = 'Europe/London';

    public function __construct(
        private readonly IcsTimezoneResolver $timezones,
        private readonly IcsItemNormaliser $normaliser,
        private readonly ContentHasher $hasher,
    ) {}

    public function extract(string $body, Source $source, ?CarbonImmutable $now = null): ExtractionResult
```
The interface keeps exactly this signature. `IngestRun::process()` already calls `extract((string) $result->body, $source, $nowLondon)`.

**Max-events guard + fatal results** (l.77-92, 124-127). Every extractor counts records before per-item work and returns `ExtractionResult::fatal(...)` for unreadable bodies, in plain British English:
```php
$declared = (int) preg_match_all('/^BEGIN:VEVENT[ \t]*\r?$/mi', $body);
$maxEvents = (int) config('ingest.max_events', 5000);
if ($declared > $maxEvents) {
    return $this->tooManyEvents($declared, $maxEvents);
}
try {
    $calendar = Reader::read($body, Reader::OPTION_FORGIVING);
} catch (ParseException) {
    return ExtractionResult::fatal('The feed could not be read as a calendar (ICS) file.', $declared);
}
```
How this maps to each rung:
- TEC and JSON-LD: `json_decode(..., flags: JSON_THROW_ON_ERROR)` with `catch (JsonException)` → fatal.
- FSE: an arity or type deviation → fatal (D-13). The run then fails as `parse_error`, because `IngestRun::process()` maps `failed()` to `FetchFailureKind::ParseError`. If the plan wants `content_invalid` (D-13 wording), put that check in `BodyValidator` instead.

**Identity → externalId with length cap** (l.158-191). Copy this for every extractor. `summary_start` = `{raw start text}|{normalised title}`:
```php
$id = match (true) {
    $identity === IdentityStrategy::Uid && $uid !== '' => $uid,
    $identity === IdentityStrategy::TecPostId && preg_match(IdentityStrategy::TEC_POST_ID_PATTERN, $uid, $m) === 1 => $m[1],
    ...
    default => $this->summaryStartKey($master),
};
if (strlen($id) <= self::MAX_EXTERNAL_ID) {
    return $id;
}
return mb_strcut($id, 0, 190, 'UTF-8').'#'.hash('sha256', $id);
...
private function summaryStartKey(VEvent $master): string
{
    $start = $this->dateProperty($master, 'DTSTART');
    $title = CategoryAssigner::normalise($this->normaliser->line($this->text($master, 'SUMMARY')) ?? '');
    return ($start?->getValue() ?? '').'|'.$title;
}
```

**Unreadable item vs facts with parse errors** (l.211, 226-245):
```php
$unreadable = fn (string $error): ExtractedItem => new ExtractedItem($externalId, $fragment, $rawHash, null, null, [$error]);
...
return new ExtractedItem($externalId, $fragment, $rawHash, $facts, $this->hasher->contentHash($facts), $errors);
```
R-07 / SC3: a bad or missing date or time on a selector or JSON-LD row keeps the facts (title, venue), sets `startDate` to null, and adds a parse error. That way it becomes a Low-quality draft, not `facts === null`. RSS `date_required` with no pattern match at all is the exception: skip it and count it.

**Schedule helpers to reuse** (l.325-363 `timedSchedule()` / `allDaySchedule()`): London wall clock, `endDate` only when it is a later local day, zero-duration → no end. TEC timed (from `utc_*`) and JSON-LD offset values convert to London, then follow the same shape. All-day TEC (R-02) uses the local `start_date`/`end_date` date parts.

**NormalisedFacts construction** (l.292-317): named arguments, every field explicit. New extractors pass `rrule: null`, empty series arrays, and `nextInstanceStartUtc: $startAt->utc()` (null when undated).

**Sorting** (l.114-119): sort items by `nextInstanceStartUtc` then `externalId`. Undated items go last (`PHP_INT_MAX`).

**Text normalisation:** reuse `IcsItemNormaliser::line()/description()/url()` through the new `Support/TextNormaliser` (see below). Decode entities once with `html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8')` + NFC.

---

### `app/Ingestion/Support/TextNormaliser.php` + `app/Ingestion/Support/UkPostcode.php`

**Analog:** `app/Ingestion/Ics/IcsItemNormaliser.php`. Extract its rung-independent parts. `IcsItemNormaliser` can then delegate to them, so ICS stays byte-identical, which the TEC↔ICS hash-equivalence test needs.

**Core** (l.27-56, 146-152):
```php
public function line(?string $value): ?string
{
    if ($value === null) { return null; }
    $text = $this->plain($value);
    $text = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));
    return $text === '' ? null : $text;
}
...
private function plain(string $value): string
{
    $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $normalised = Normalizer::normalize($text, Normalizer::FORM_C);
    return $normalised === false ? $text : $normalised;
}
```

**Postcode** (l.21-22, 80-83). Replace this with RESEARCH's stricter pattern in `UkPostcode::find(string): list<array{outward, formatted}>`, and make `IcsItemNormaliser::location()` call it:
```php
private const string POSTCODE_PATTERN = '/\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b/i';
...
if (preg_match(self::POSTCODE_PATTERN, $location, $m) === 1) {
    $postcode = strtoupper($m[1]).' '.strtoupper($m[2]);
}
```
Check whether the new pattern changes any ICS `postcode` payload values. That could cause update drafts on existing items, so compare against the Baptist / Town Council fixtures.

**URL safety** (l.91-96): `SafeLinks::http($url) !== null ? $url : null` (`app/Support/SafeLinks.php`).

---

### `app/Ingestion/Dates/StrictDate.php`, `YearInference.php`, `TimeFinder.php`

**Analog:** `IcsExtractor::londonDate()` (l.655-665) is the only strict-format parse in the codebase today:
```php
private function londonDate(string $date): CarbonImmutable
{
    $value = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::LONDON);
    if (! $value instanceof CarbonImmutable) {
        throw new InvalidArgumentException("Invalid date [{$date}].");
    }
    return $value;
}
```
Build on RESEARCH "Strict date helper" (prototype verified):
- `createFromFormat('!'.$format, …)`
- `DateTimeImmutable::getLastErrors() === false` (warnings count too)
- lower-cased round trip

**Style analog for framework-free statics:** `app/Ingestion/Pipeline/TitleRules.php`: a `final class`, a docblock stating the rule, `public static` methods, fixed constants, and no container. Code-defined patterns only. Never admin-written regex (`IdentityStrategy` doc: "no admin-written regex: ReDoS, invalid patterns").

**Unit-test analog:** `tests/Unit/Ingestion/TitleRulesTest.php`: a top comment naming the requirement, a tiny helper function, and `expect(...)->toBeTrue()->and(...)` chains. Add Pest datasets for the weekday-mismatch, overflow, `Nov`-for-`F` and DST gap tables.

---

### `app/Ingestion/Extraction/ExtractorRegistry.php`, `ReaderRegistry.php`

**Analogs:** `app/Enums/IdentityStrategy.php::forSource()` (reads source config into a strategy) and `BodyValidator::validate()` (a `match` on method):
```php
public static function forSource(Source $source): self
{
    $configured = $source->parser_config['identity'] ?? null;
    return is_string($configured) ? (self::tryFrom($configured) ?? self::Uid) : self::Uid;
}
```
```php
return match ($method) {
    FetchRequest::METHOD_ICS => $this->validateIcs($body),
    default => 'Unsupported extraction method',
};
```
Resolve extractors from the container (`app(IcsExtractor::class)`) so their constructor dependencies keep working. For `Adapter`, nest a `match ($source->adapter) { 'fse_school_calendar' => … }`. An unknown adapter returns a fatal `ExtractionResult`; it does not throw.

---

### `app/Ingestion/Extraction/SinglePageReader.php`, `Tec/TecRestReader.php`, `Rss/RssLinkFollowReader.php`, `Extraction/FetchEnvelope.php`

**Analog for the single-page reader (move, don't rewrite):** `IngestRun::request()` (l.134-160). It holds the conditional-validator rule (validators only after a succeeded or not-modified live run, never on preview):
```php
if ($run->mode !== RunMode::Live) {
    return new FetchRequest(url: $source->url, etag: null, lastModified: null, startedAt: $run->started_at);
}
$previous = SourceRun::query()
    ->where('source_id', $source->id)
    ->where('mode', RunMode::Live)
    ->where('id', '<', $run->id)
    ->whereNotIn('status', [RunStatus::Queued, RunStatus::Running])
    ->orderByDesc('id')
    ->first();
$conditional = $previous !== null && in_array($previous->status, [RunStatus::Succeeded, RunStatus::NotModified], true);
```
Rules for the multi-page readers:
- TEC and link-follow never send validators (D-03 `no-store`, RESEARCH "never send conditional validators for link_follow").
- Each page's `startedAt` is `CarbonImmutable::now()` at the moment that page starts (RESEARCH Pitfall 14). The run deadline comes from `config('ingest.run_deadline')`, counted from `$run->started_at`, the same way `IngestRun::process()` counts it (l.181).

**Loop analog:** `HttpFetcher::fetch()` hop loop (l.81-108): bounded `for`, a cap check first, and a `FetchResult` returned rather than thrown. Then add RESEARCH "TEC reader loop (shape)":
- `HostBusy` on page 1 rethrows; after page 1 it stops incomplete.
- Follow only a same-origin `next_rest_url`.

**Envelope value object:** follow `FetchResult`'s readonly-plus-named-constructors style (`ok()`, `failed()`, `withDuration()`, l.47-112). The reader returns `FetchResult::ok($envelopeJson, $finalUrl, 200)`, so `RawBodyStore::put`, `raw_sha256` and the D-24 `reusablePreviewBody()` (IngestRun l.343-374) work unchanged. That gives "all pages or none" reuse.

---

### `app/Ingestion/IngestRun.php` (modify)

**Constructor seam** (l.75-82). Replace `private readonly IcsExtractor $extractor,` with the two registries.

**Incomplete-run pattern.** Copy from `timedOut()` (l.229-243) and `finish(..., keepValidators:)` (l.224, l.409-417). A capped TEC run is `Succeeded`, with no validators, an `error_message` note, and `is_complete` false:
```php
$this->finish($run, $source, RunStatus::Succeeded, null, null, $result, keepValidators: $itemFailures === 0);
```
Extend this to `keepValidators: $itemFailures === 0 && $extraction->complete`, and pass the note through `self::errorMessage()`. Missing detection runs inside `finish()` (l.466-469) after `$this->health->record($run)`. It must see `is_complete = false`: set it in `SourceHealthTracker::record()` or right after it.

**Preview skipped map** (l.311-318). Add `'out_of_area'` and `'online'`:
```php
'skipped' => [
    'past' => $counters['skipped_past'],
    'far_future' => $counters['skipped_far_future'],
    'rule' => $counters['skipped_rule'],
    'parse_error' => $counters['skipped_parse_error'],
],
```

**Preview row** (l.328-353). `starts` is already nullable. Add `flags` (`['location_unknown']`) and an optional `raw` (selector rows, each ≤ 200 characters). `laterDate()` (l.380-391) and `previewRow()` read `$facts->startDate`, so guard them for null (R-07).

---

### `app/Ingestion/Http/FetchRequest.php`, `HttpFetcher.php`, `BodyValidator.php` (modify)

**FetchRequest** (l.15-23). Add constants next to `METHOD_ICS = 'ics'`. Use the same strings as `ExtractionMethod` values, or keep a separate validator-kind vocabulary (`json`, `html`, `feed`). The planner decides. For R-03, add a `bool $acceptErrorBody = false` flag (rung-specific; default behaviour unchanged).

**HttpFetcher headers** (l.59, l.327-344). Turn `icsHeaders()` into `headers()` and pick `Accept` by method. Keep the conditional headers block as-is:
```php
public const string ACCEPT_ICS = 'text/calendar, text/plain;q=0.5, */*;q=0.1';
...
$headers = [
    'User-Agent' => self::userAgent(),
    'Accept' => self::ACCEPT_ICS,
];
```

**R-03 branch location** (l.415-417). This is the only place to add the 404 allowance. Read the capped body and check `rest_no_route` before returning `HttpStatus`:
```php
if ($status >= 400 || $status < 200) {
    return FetchResult::failed(FetchFailureKind::HttpStatus, 'HTTP '.$status, $url, httpStatus: $status, headers: $headers);
}
```
Messages are built from labels and our own wording only, never response text (FetchResult doc, T-03-06).

**BodyValidator** (l.43-67). Copy `validateIcs()` per rung: strip the BOM, treat empty as invalid, accept a positive signature, then diagnose markers:
```php
$text = ltrim($this->stripBom($body));
if ($text === '') { return 'Empty response'; }
if (stripos($text, 'BEGIN:VCALENDAR') === 0) { return null; }
$lower = strtolower($text);
foreach (self::CHALLENGE_MARKERS as $marker) {
    if (str_contains($lower, $marker)) {
        return 'Bot challenge page instead of a calendar';
    }
}
```
- JSON: the body starts with `{` or `[` after trimming.
- Feed: `<rss` or `<feed` before the first element.
- HTML: has `<html` or `<body` and no wall markers.

Use specific markers. The generic `captcha` marker is out (RESEARCH anti-pattern: Visit Wymondham loads reCAPTCHA). Add the LiteSpeed wall marker.

---

### `app/Ingestion/Data/NormalisedFacts.php`, `ExtractionResult.php`, `ExtractedItem.php` (modify)

**NormalisedFacts.** Readonly properties are assigned in the constructor (l.84-131), and `toPayload()` / `fromPayload()` must stay inverses (ContentHasher contract l.24-25). Rules:
- Add `?string $statusNote` as the **last** constructor parameter, so existing named-argument calls are untouched.
- `startDate` becomes `?string`. `fromPayload()` currently defaults it to `''` (l.180). Use `null` instead.
- Replace l.204 `CarbonImmutable::parse($next, 'UTC')` with `CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $next, 'UTC')`, which matches the `toPayload()` format at l.161. Or use `StrictDate`.

**ExtractionResult** (l.15-24). Add `public bool $complete = true, public ?string $note = null` after `eventCount`, keeping `fatal()` as is.

---

### `app/Ingestion/Pipeline/ContentHasher.php` (modify)

**Analog for the structured raw hash:** `contentHash()` (l.65-88). It encodes a fixed-key-order array with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`:
```php
return hash('sha256', json_encode($factSet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
```
`structuredRawHash(array $item, list<string> $volatileKeys)`:
- Recursively `ksort`.
- Drop volatile keys (TEC `modified`, `modified_utc`, …), the same idea as `VOLATILE_PROPERTIES` (l.30).
- Encode with the same flags.

The key order of `contentHash()` is a contract: "never reorder or rename". `statusNote` must not enter it.

---

### `app/Ingestion/Pipeline/Geofence.php` (+ `OnlineDetector`)

**Analog:** `app/Ingestion/Pipeline/TitleRules.php` (the whole file, 50 lines): a pure static rule, constants for modes, malformed entries ignored, matching delegated to `CategoryAssigner::titleContainsKeyword`.
```php
public static function allows(array $rules, string $title): bool
{
    ...
    $matches = CategoryAssigner::titleContainsKeyword($title, $rule['keyword']);
```
Place names reuse `CategoryAssigner::titleContainsKeyword()` (`app/Review/CategoryAssigner.php` l.74-85: whole-word, NFC, lower-case):
```php
$pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';
return preg_match($pattern, self::normalise($title)) === 1;
```
The venue step uses `VenueMatcher::exact($name, $postcode)` (`app/Review/VenueMatcher.php` l.25-28). It applies only when no postcode is present (R-11).

Terms come from `GeofenceTerm` (`app/Models/GeofenceTerm.php`, `type` ∈ `POSTCODE_DISTRICT` / `PLACE_NAME`). Load them once per run, not per item. Pass them into the geofence check, or memoise them in the recorder per run. Districts are stored upper-case without spaces (`RulesSeeder::POSTCODE_DISTRICTS`), so compare the outward code by equality.

Return a verdict constant: `KEEP`, `KEEP_UNKNOWN`, `OUT_OF_AREA` or `ONLINE`.

---

### `app/Ingestion/Pipeline/SourceItemRecorder.php` (modify)

**Bucket constants + COUNTER map** (l.67-92). Add both constants and map them:
```php
public const string SKIPPED_PARSE_ERROR = 'skipped_parse_error';
public const array COUNTER = [
    ...
    self::SKIPPED_PARSE_ERROR => 'skipped_parse_error',
];
```
`SKIPPED_OUT_OF_AREA => 'skipped_out_of_area'` uses the existing column. `SKIPPED_ONLINE => 'skipped_online'` needs the new column.

**classify()** (l.438-466). Insert the geofence after the window checks and before `$existing === null`, only when `$source->location_mode === 'filter'`. Update the `@return` unions on `verdict()` and `classify()` (l.176, 436).
```php
$windowClass = $this->window->classify($facts, $nowLondon);
if ($windowClass === WindowClassifier::PAST) { return self::SKIPPED_PAST; }
if ($windowClass === WindowClassifier::FAR_FUTURE) { return self::SKIPPED_FAR_FUTURE; }
// ← online / geofence here (filter sources only)
if ($existing === null) { return self::NEW; }
```
Tracked items already bypass `classify()` in `record()` (l.150-163), so they bypass the geofence automatically.

---

### `app/Ingestion/Pipeline/WindowClassifier.php` (modify)

`classify()` (l.47-55) builds an unsaved `Event` from `$facts->startDate`. Add an early return before `$this->event(...)`: `if ($facts->startDate === null) { return self::IN_WINDOW; }`. The undated item then becomes a flagged draft (R-07). Follow the existing comment style, e.g. l.69-71 "keep it in front of the admin rather than skip it".

---

### `app/Review/QualityScorer.php` (modify)

**Reason pattern** (l.84-104): a condition, then subtract a weight from `config('review.quality.weights.*')`, then append a plain-English reason:
```php
if ($categoryFlagged) {
    $score -= self::weight('category_other');
    $reasons[] = 'No category matched, set to Other';
}
```
Add `location_unknown`:
- Condition: `$draft->source?->location_mode === 'filter'`, with venue and address both blank.
- Weight: a new `config/review.php` `weights.location_unknown` (0 or small; RESEARCH A7).
- Reason text: "Location unknown: check it's in the Attleborough area".

`'Missing start date' => $draft->start_date === null` (l.48) already handles undated drafts. Together with the parse-error weight they land in the Low band.

---

### `app/Review/Data/DraftData.php` (modify, l.276)

```php
extractionMethod: ExtractionMethod::Ics,
```
→ `extractionMethod: $source->extraction_method,`. `app/Models/Source.php` l.158 already casts `extraction_method` to `ExtractionMethod::class`.

---

### `database/migrations/2026_10_19_xxxxxx_*.php` (new)

**Analog:** `database/migrations/2026_10_18_100400_add_ingestion_columns_to_event_drafts_and_events.php`.

**Docblock style** (l.10-30): a bullet list of what changes and why, naming each constraint.

**DROP/ADD CHECK** (l.48-49):
```php
DB::statement('ALTER TABLE event_drafts DROP CONSTRAINT event_drafts_extraction_method_check');
DB::statement("ALTER TABLE event_drafts ADD CONSTRAINT event_drafts_extraction_method_check CHECK (extraction_method IN ('submission', 'manual', 'ics'))");
```

**Symmetric down()** (l.72-75): restore the narrower constraint. Document that down() fails while wider rows exist, as `2026_10_18_100800` does at l.35-37 ("Fails on the narrower index while per-source rows repeat a value; remove those first.").

Contents:
- `sources_extraction_method_check` and `event_drafts_extraction_method_check` widened.
- A new `sources_adapter_check` (`adapter IS NULL OR adapter IN ('fse_school_calendar')`).
- `year_policy` widened from 16 to 32 (`$table->string('year_policy', 32)->default('none')->change();`), plus `sources_year_policy_check`.
- `source_runs.skipped_online integer default 0`, placed after `skipped_out_of_area`.

The originals to mirror are `2026_10_18_100100_create_sources_table.php` l.41 and l.65 and `2026_10_18_100200_create_source_runs_table.php` l.41-47.

---

### `app/Enums/ExtractionMethod.php` (modify)

The pattern is the enum itself (l.13-27): a backed string enum, `HasLabel`, a `match` label, and a docblock naming the CHECK constraints it mirrors. Labels go into draft and form text, so use plain English ("The Events Calendar (WordPress)", "Structured data (JSON-LD)", "Web page selectors", "RSS/Atom feed", "Site adapter").

---

### `app/Filament/Resources/Sources/Schemas/SourceForm.php` (modify)

**Options-constant pattern** (l.53-70):
```php
public const array YEAR_POLICIES = [
    'none' => 'Dates include the year',
];
```
Add `'infer_with_weekday' => 'Dates leave out the year but give the weekday'` (or similar).

**Select from enum cases** (l.153-155). Copy this for the method select. Plain string options keep json state as strings:
```php
->options(collect(IdentityStrategy::cases())->mapWithKeys(fn (IdentityStrategy $strategy): array => [$strategy->value => $strategy->getLabel()])->all())
```
Filter out `Submission` and `Manual` for sources.

**Locked-once-recorded rule** (l.157, l.274-290). Extend `unchangedOnceRecorded()` with a `'extraction_method'` field and a `METHOD_LOCKED` message constant in the style of `IDENTITY_LOCKED` (l.50-52). R-01: no in-place method switch.
```php
public static function unchangedOnceRecorded(?Source $record, string $field, string $message): Closure
{
    return static function (string $attribute, mixed $value, Closure $fail) use ($record, $field, $message): void {
        if ($record === null || ! $record->exists) { return; }
        $stored = $field === 'identity' ? ... : (string) $record->default_timezone;
        if ((string) $value !== $stored && $record->items()->exists()) {
            $fail($message);
        }
    };
}
```

**Closure validation rule** (l.296-309 `urlRule()`). Copy this for:
- the selector compile check (`\Dom\HTMLDocument::createEmpty()->querySelector($s)` in try/catch `\DOMException`)
- the date-format character whitelist
- the TEC path check (`/wp-json/tribe/events/v1/events`)
```php
public static function urlRule(): Closure
{
    return static function (string $attribute, mixed $value, Closure $fail): void {
        if (! is_string($value) || trim($value) === '') { return; }
        if (app(SsrfGuard::class)->checkUrl(trim($value)) !== null) {
            $fail(self::URL_REFUSED);
        }
    };
}
```

**Section + hintAction per field** (l.92-131, l.215-240). Every field has `->hintAction(SourceFieldHelp::action('<key>'))`. A section with help uses `->key('…-section')->headerActions([SourceFieldHelp::action('…')])`, like `title-rules-section`. The selector section is `Section::make('Web page selectors')->key('selectors-section')`, with conditional visibility `->visible(fn (Get $get): bool => $get('extraction_method') === 'html_selectors')`. `Get` comes from `Filament\Schemas\Components\Utilities\Get`. Fields are `TextInput::make('parser_config.selectors.item')` and so on, dot-notation into json like `parser_config.identity`.

**Adapter select** (l.140-147). It is currently `->disabled()->dehydrated(false)`. Enable and dehydrate it when the method is `adapter`. Options: `['fse_school_calendar' => '…']`.

**prepareForSave whitelist** (l.252-271): `Arr::only($data, (new Source)->getFillable())` plus trimming. Add normalisation for selector strings (trim; blank → removed key).

**URL field help text** (l.112-117) says "The ICS calendar address…". Make it method-aware or neutral.

---

### `app/Filament/Resources/Sources/Schemas/SourceFieldHelp.php` (modify)

**Topic shape** (l.17-29). Every new form field or section needs an entry with exactly these keys. `SourceFieldHelpTest` iterates `array_keys(TOPICS)` and asserts each one renders:
```php
'name' => [
    'label' => 'Name',
    'summary' => 'The organisation or website the events come from.',
    'why' => '…',
    'how' => ['…', '…'],
    'example' => '"Attleborough Baptist Church" or "Attleborough Town Council".',
],
```
Rewrite the existing `url` (l.39-49), `extraction_method` (l.73-81), `adapter` (l.82-90) and `year_policy` topics: they say "Only ICS feed is available for now". Use real examples from the research: the Heritage selectors, the TEC endpoint, the Baptist RSS `Y-m-d H:i` title prefix.

`action()` (l.208-224) derives the action name from `'help_'.str_replace('.', '_', $topic)`, so nested keys like `parser_config.selectors.item` work unchanged.

---

### `app/Filament/Resources/Sources/Pages/EditSource.php` + `preview.blade.php` (modify)

**Validator clearing** (EditSource l.69-82). Add `extraction_method`, `adapter` and `parser_config` changes to the dirty check (D-04's "changing method clears ETag", still relevant for method changes on item-less sources):
```php
$identityChanged = ($record->getOriginal('parser_config')['identity'] ?? null) !== ($record->getAttribute('parser_config')['identity'] ?? null);
if ($record->isDirty(['url', 'default_timezone']) || $identityChanged) {
    $record->forceFill(['etag' => null, 'last_modified' => null]);
}
```

**Label maps** (l.222-228 and l.256-262). Add matching entries in **both** `previewViewData()['skipped']` and `previewRow()` `$reason`: `'out_of_area' => 'Out of area'`, `'online' => 'Online only'`. Extend the row array shape docblocks with `flags` and `raw`. `previewStart()` (l.283-304) returns `''` for null, so show "No date found" instead (RESEARCH).

**Blade** (`preview.blade.php` l.40-66). Escaped `{{ }}` only. Add a column or block per row for `raw` (title/date/time text) when present, plus the flag text "Location unknown". Follow the existing `<span class="block text-warning-600 dark:text-warning-400">` note pattern (l.54-56). If new Tailwind classes are added, run `npm run build` (AGENTS.md).

---

### `app/Filament/Resources/Sources/RelationManagers/RunsRelationManager.php` (modify)

Copy l.85:
```php
TextColumn::make('skipped_out_of_area')->label('Skipped: out of area')->numeric(),
```
→ `TextColumn::make('skipped_online')->label('Skipped: online only')->numeric(),`

---

### `database/seeders/SourceSeeder.php` (modify)

**Create-only pattern** (l.44-63). Today `extraction_method`, `location_mode` and `year_policy` are **hard-coded** in `run()` (l.54-57). Move them into each `sources()` entry and keep defaults with `??` or spread order:
```php
Source::query()->firstOrCreate(['slug' => $slug], [
    ...$source,
    'slug' => $slug,
    'extraction_method' => ExtractionMethod::Ics,
    'default_timezone' => 'Europe/London',
    'location_mode' => 'local',
    'year_policy' => 'none',
    'check_frequency' => 'daily',
    ...
]);
```
**Entry shape** (l.74-95): slug constant, then `name`, `short_name`, `url`, `events_page_url`, `parser_config`, `supports_missing_detection`, `is_organiser_source`, `title_rules`, `category`, `venue`, `legal_basis`, `notes`. Long text is joined with `.` concatenation.

Entries to add:
- `TOWN_COUNCIL_API = 'attleborough-town-council-api'` (R-01). It mirrors the Town Council's title rules.
- Update the existing Town Council `notes` to "fallback: activate only if the API source fails" (R-01). This only reaches fresh databases, because seeding is create-only.
- Baptist RSS fallback (D-07).
- Heritage selectors, with the working config from RESEARCH.
- Primary `fse_school_calendar` (R-06 notes).
- Visit Wymondham: `filter`, `supports_missing_detection => false`.
- Diocese of Norwich: TEC, `filter`, note "check the terms before activating".

Update the class docblock l.13-33 ("three ready ICS sources").

**Test analog:** `tests/Feature/Review/SourceSeederTest.php` l.99-113. It loops every seeded source through `EditSource::save` with `assertHasNoFormErrors()`, so every new entry must pass the new form rules. Update the slug list (l.41) and the "exactly three" test (l.126).

---

### `database/factories/SourceFactory.php` (modify)

Copy `ics()` (l.41-47) for each rung:
```php
public function ics(): static
{
    return $this->state([
        'extraction_method' => ExtractionMethod::Ics,
        'parser_config' => ['identity' => 'uid'],
    ]);
}
```
For example: `tecRest()` → `TecRest` + `['identity' => 'tec_post_id']`, and `filter()` → `['location_mode' => 'filter']`.

---

### `tests/Unit/Architecture/DateParsingBanTest.php` (new)

**Analog:** `tests/Unit/Architecture/WriteChokePointTest.php` (the whole file). A top comment explains **why a weaker form can never fail**. That matters here because R-08 bans `->not->toBeUsedIn()`:
```php
/*
 * ... Not written as expect('App')->not->toUse(PublishEvent)->ignoring('App\Publishing\Actions'):
 * Pest's ignoring() skips matching targets AND dependencies ... so that form can never fail (a
 * planted App\Jobs bypass passed it). toOnlyBeUsedIn() states the rule directly and fails on that bypass.
 */
arch('publishes events only through the Publishing actions')
    ->expect('App\Publishing\Actions\PublishEvent')
    ->toOnlyBeUsedIn('App\Publishing\Actions');
```
Body: RESEARCH "Arch test (function ban + token scan)". Plant-test it once, then remove the plant. Existing offenders to fix first: `NormalisedFacts.php:204`, `DuplicateDetector.php:244,252,268`, `RobotsPolicy.php:106`, `HostThrottle.php:62,65`. `StrictDate` is the only allowed `createFromFormat` caller, if the scan also covers `createFromFormat`; otherwise `MissingItemDetector.php:66` and `IcsExtractor.php:657` may stay.

---

### Feature run tests (`TecRestRunTest`, `JsonLdRunTest`, `HtmlSelectorRunTest`, `RssRunTest`, `FseSchoolCalendarTest`, `GeofenceTest`)

**Analog:** `tests/Feature/Ingestion/RunNowTracerTest.php`.

**Header comment + URL constants + fake helper** (l.23-45):
```php
const TRACER_BAPTIST_ROBOTS_URL = 'https://www.attleborough.church/robots.txt';
const TRACER_BAPTIST_ICS_URL = 'https://www.attleborough.church/Calendar/Calendar.ics';

function tracerFakeBaptist(array $headers = []): void
{
    Http::fake([
        TRACER_BAPTIST_ROBOTS_URL => Http::response("User-agent: *\nCrawl-delay: 10\n...", 200, ['Content-Type' => 'text/plain']),
        TRACER_BAPTIST_ICS_URL => Http::response(sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics'), 200, [...]),
    ]);
}
```
Pest functions and constants are global, so prefix them per file (`tec…`, `jsonLd…`) to avoid collisions.

**beforeEach** (l.55-65):
```php
Sleep::fake(syncWithCarbon: true);
Storage::fake('local');
freezeLondon('2026-10-17 06:00:00');
$this->seed(CategorySeeder::class);
$this->source = Source::factory()->ics()->active()->create([...]);
```

**Run + assertions** (l.67-104). `FetchSourceJob::dispatchSync($run->id)`, or the shared `ingestLiveRun($source, RunMode::Preview)` from `tests/Pest.php` l.177-185. Then:
- the counter identity: `items_found` equals the sum of the buckets. Add `skipped_out_of_area` and `skipped_online`.
- the draft `extraction_method` assertion (l.95): now the source's method.
- raw-SQL UTC checks (l.124-127):
```php
$stored = DB::selectOne("select to_char(last_success_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as s from sources where id = ?", [$source->id]);
```

**Multi-URL fakes (TEC p1/p2, link-follow).** Use the closure form from `tests/Pest.php::ingestFeed()` (l.150-175) or `PreviewRunTest::previewServe()` (l.35-50) with a request counter. Then `Http::assertSentCount` proves D-24 reuse makes no second fetch.

**Fixture loading:** `sourceFixture('attleborough-town-council/2026-10-17-tec-p1.json')` (`tests/Pest.php` l.121-128).

**Cancellation assertions (JsonLdCancellationTest):** follow `tests/Feature/Ingestion/CancellationDraftTest.php`. A new identity that is already cancelled gives a cancelled item and no draft. A linked one gives a cancellation draft, via `ingestApprove()` (`tests/Pest.php` l.200-207).

**Geofence + Rules edits:** `tests/Feature/Filament/RulesMappingsGeofenceTest.php` (l.58+) shows how terms are saved through the Rules screen. Reuse it to prove admin edits take effect.

---

### `tests/Unit/Ingestion/JsonLdEventWalkerTest.php`, `TecIcsEquivalenceTest.php`

**Analog:** `tests/Unit/Ingestion/IcsExtractorTest.php` l.1-60. Pure: `Source::factory()->...->make()` with no database insert, a `freezeLondon` beforeEach, and helpers:
```php
function ingestExtract(string $body, string $identity = 'uid', array $attributes = []): ExtractionResult
{
    $source = Source::factory()->withIdentity($identity)->make($attributes);
    return app(IcsExtractor::class)->extract($body, $source);
}
function ingestItemsById(ExtractionResult $result): array { ... keyed by externalId ... }
```
Reuse `ingestItemsById()` for the equivalence test. Compare `contentHash` of the TEC and ICS facts for the 24 overlapping ids.

---

### `tests/Feature/Ingestion/PreviewRunTest.php`, `IngestionSchemaTest.php`, `SourceEditSafetyTest.php` (modify)

- **PreviewRunTest:** `->toHaveCount(4)->toHaveKeys(['past', 'far_future', 'rule', 'parse_error'])` (in the first `it`) becomes 6 keys. Add a multi-page "Create drafts reuses the envelope" case. Copy the existing D-24 reuse test in the same file, with `previewServe()` counting.
- **IngestionSchemaTest l.100-108:** "refuses a non-ics extraction method at the database" (`->throws(QueryException::class, 'sources_extraction_method_check')`). Change the value to something still invalid (`'bogus'`), and add the adapter and year_policy CHECK tests in the same style.
- **SourceEditSafetyTest l.123-136:** copy "refuses an item identity change once the source has recorded feed items" for `extraction_method`:
```php
->fillForm(['parser_config.identity' => 'summary_start'])
->call('save')
->assertHasFormErrors(['parser_config.identity'])
->assertSee(SourceForm::IDENTITY_LOCKED);
```

---

### `tests/Browser/SourcesTest.php`, `tests/Browser/UatFixtures.php` (modify)

- **Flow pattern** (SourcesTest l.83-120): `visit('/admin')`, then click by role, `Uat::settleFields($page, [...])`, `sourcesConfirm($page, 'Test run', …)`, `Uat::workQueue()`, assertions. The help dialog check is at l.92-96.
- **UatFixtures::fakeIngestion()** (l.182-203) answers every fixture with `Content-Type: text/calendar` (l.200). Add per-file content types (JSON, HTML, XML) to `FEED_FIXTURES` handling. BodyValidator ignores Content-Type, but realistic headers keep the UAT honest.
- Add flows F21 (selector source: create, Test run, raw rows, Create drafts) and F22 (filter source: geofence skips in the preview) to `docs/uat/browser-uat.md`.

---

### Fixtures (`tests/Fixtures/sources/*/derived-*` + manifests)

**Analog manifest:** `tests/Fixtures/sources/attleborough-heritage-group/manifest.json`:
- keys `file`, `type`, `url`, `fetched_at`, `http_status`, `headers`, `robots_*`, `legal_basis`, `sha256_raw`, `redactions`, `modified`, `events`, `window`, `notes`
- derived files set `"modified": true` and name their base file in `notes` (or a `derived_from` key, if the guard test allows it)

`tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php` scans every manifest and file, so derived fixtures must pass it.

---

## Shared Patterns

### Pure extractor contract
**Source:** `app/Ingestion/Ics/IcsExtractor.php` l.33-55 docblock + l.75.
**Apply to:** all five new extractors, `JsonLdEventWalker`, and `Dates/*`.
No database access, no network, no writes. `extract(string $body, Source $source, ?CarbonImmutable $now)` → `ExtractionResult`. Parse errors are plain British English sentences that tell the admin what to fix (e.g. `'The feed gives this event no title.'`).

### Never throw source problems; record them on the run
**Source:** `app/Ingestion/IngestRun.php` class doc (l.26-61), `execute()` `match` (l.104-109), `FetchResult` doc.
**Apply to:** readers, `HttpFetcher` changes, extractors.
```php
match (true) {
    $result->isBlocked() => $this->finish($run, $source, RunStatus::Blocked, null, $result->message),
    $result->isNotModified() => $this->finish($run, $source, RunStatus::NotModified, null, null, $result),
    $result->isFailed() => $this->finish($run, $source, RunStatus::Failed, $result->failureKind ?? FetchFailureKind::Network, $result->message),
    default => $this->process($run, $source, $result),
};
```
`HostBusy` is the only exception allowed to escape, and only on page 1.

### Error messages: our wording only, 500-character cap
**Source:** `IngestRun::errorMessage()` (l.116-123), `FetchResult` doc ("never from the response text").
**Apply to:** incomplete-run notes ("Read 20 of 31 pages…"), the `rest_no_route` message, and validator reasons.

### Logging by ids only
**Source:** `IngestRun` l.193-201:
```php
// Ids only: never feed text, URLs or SQL bindings (T-01-22).
Log::error('ingest: item failed', ['run_id' => $run->id, 'source_id' => $source->id, 'item_index' => $index, 'exception' => $e::class]);
```
**Apply to:** readers (page failures) and link-follow.

### One polite fetch path (SRC-05)
**Source:** `app/Ingestion/Http/HttpFetcher.php::fetch()`. It runs the SSRF pin, robots, the throttle, the size cap and the validator on every hop.
**Apply to:** every TEC page and every RSS link-follow target. No `Http::get()` anywhere else in `app/Ingestion`.

### Admin-facing "locked once recorded" + validation closures
**Source:** `SourceForm::unchangedOnceRecorded()` (l.274-290), `SourceForm::urlRule()` (l.296-309), message constants (l.48-52).
**Apply to:** the method lock, the selector compile check, the date-format whitelist, the TEC URL path rule.

### "?" help on every field
**Source:** `SourceFieldHelp::TOPICS` + `action()`. Test: `tests/Feature/Filament/SourceFieldHelpTest.php` (a dataset over all topics).
**Apply to:** every new form field and section in `SourceForm`.

### Create-only, inactive seeding that passes the form
**Source:** `database/seeders/SourceSeeder.php` l.13-63; test `SourceSeederTest.php` l.99-113.
**Apply to:** all new seed entries (D-18, R-01, D-07).

### Offline feature tests
**Source:** `tests/Pest.php`: `preventStrayRequests` (l.42-75), `sourceFixture()` (l.121), `ingestFeed()` (l.150), `ingestLiveRun()` (l.177), `freezeLondon()` (l.241). Also `RunNowTracerTest` beforeEach (l.55-65).
**Apply to:** every new feature test. Use the DST fixtures (Sun 25 Oct 2026, Sun 28 Mar 2027) and raw-SQL UTC assertions where instants are stored.

### Write choke point (unchanged)
**Source:** `tests/Unit/Architecture/WriteChokePointTest.php`.
**Apply to:** all new code. Ingestion reaches drafts only through `SourceItemRecorder` → `DraftWriter`. Nothing in this phase may touch `Event` or `EventOccurrence` directly.

---

## No Analog Found

| File | Role | Data flow | Reason / what to use instead |
|---|---|---|---|
| `app/Ingestion/Html/SelectorConfig.php` | value object + validation | transform | No CSS or DOM code exists in the repo. Follow RESEARCH "Native selectors on the real Heritage markup" (`\Dom\HTMLDocument::createFromString($body, LIBXML_NOERROR \| \Dom\HTML_NO_DEFAULT_NS)`, `querySelectorAll`) for runtime and `createEmpty()->querySelector()` in try/catch for save-time validation. Read config the `IdentityStrategy::forSource()` way (typed defaults, never throw on a malformed `parser_config`). |
| `app/Ingestion/Adapters/FseSchoolCalendarExtractor.php` | extractor | transform (embedded JS array) | No embedded-script parsing exists. Use RESEARCH "FSE school calendar adapter": substring between `json = ` and `;limitedto`, `json_decode` depth 4 with `JSON_THROW_ON_ERROR`, exact 12-element arity and type check per row, `StrictDate('Ymd\THis', …, Europe/London)`. Use the IcsExtractor shape for everything else. |
| SimpleXML usage in `Rss/RssExtractor.php` | — | — | No XML parsing in the repo. Use RESEARCH: `simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET \| LIBXML_NOCDATA)`, never `LIBXML_NOENT`/`DTDLOAD`/`PARSEHUGE`. Add an entity-bomb test (A1). |

## Metadata

**Analog search scope:**
- `app/Ingestion/**`, `app/Enums`, `app/Models/{SourceRun,GeofenceTerm}.php`
- `app/Review/{QualityScorer,VenueMatcher,CategoryAssigner,Data/DraftData,SaveRules}.php`
- `app/Filament/Resources/Sources/**`, `resources/views/filament/resources/sources/*`
- `database/{migrations/2026_10_18_*,seeders,factories}`, `config/{ingest,review}.php`
- `tests/{Pest.php,Unit/Architecture,Unit/Ingestion,Feature/Ingestion,Feature/Filament,Feature/Review,Browser}`, `tests/Fixtures/sources`

**Files scanned:** about 150 tracked files listed; 34 read in full or in targeted ranges.
**Pattern extraction date:** 2026-10-18

## PATTERN MAPPING COMPLETE
