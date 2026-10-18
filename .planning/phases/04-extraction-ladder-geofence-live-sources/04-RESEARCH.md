# Phase 4: Extraction Ladder, Geofence & Live Sources - Research

**Researched:** 2026-10-18
**Domain:** Deterministic event extraction (TEC REST, JSON-LD, CSS selectors, RSS, one platform adapter), strict date parsing, postcode/place-name geofence, Laravel 13 / PHP 8.4 / PostgreSQL ingestion pipeline
**Confidence:** HIGH for codebase facts, fixture analysis and PHP behaviour (all probed this session); MEDIUM for TEC REST semantics not covered by its own schema; LOW items are listed in the Assumptions Log.

## Summary

Phase 3 left a solid, single-fetch ICS pipeline: `IngestRun` hard-wires `IcsExtractor`, `HttpFetcher` and `BodyValidator` only know `'ics'`, `DraftData::fromSourceItem` stamps every discovered draft as ICS, and the DB constraints allow only `'ics'`. Phase 4 has to add an extractor/reader seam (registry keyed on `extraction_method` + `adapter`), a multi-page reader for TEC REST and RSS link-follow, per-rung body validation, a strict date parser, a geofence step and new skip counters. **No new Composer package is needed.** PHP 8.4's native `\Dom\HTMLDocument::querySelectorAll()` runs real CSS selectors on the real Heritage markup, validates selectors (it throws `DOMException` on invalid ones), and PHPStan level 6 understands its types. SimpleXML covers RSS/Atom, and `DateTimeImmutable::createFromFormat` covers dates, provided it is wrapped in a strict helper (see Pitfalls 3–5).

Research found **six places where the locked [agent default] decisions are wrong or need changing**. The planner must not plan them as written:

1. **D-04 is factually wrong.** Town Council ICS post ids equal REST `id`s only for occurrences of recurring series. Single (non-recurring) events use the real post id in REST (`23254`) but a provisional occurrence id in ICS (`10006470`), with the same URL. In the 17 Oct fixtures, 10 of 72 REST events are of this kind, and 2 fall inside the overlap window and mismatch. Switching in place would make those items "missing" (cancellation drafts) and "new" at once, and same-source duplicate detection would not flag them. **Recommendation:** drop the "allowed method change" exception and keep method changes refused once items exist. Seed TEC REST for fresh databases. For the owner's dev DB, either reset it or add TEC REST as a new source and pause the ICS one; cross-source URL duplicate detection then flags the pairs. Production does not exist yet, so no real data is at risk.
2. **D-05 all-day mapping is wrong for UTC fields.** TEC all-day items carry local `00:00:00`–`23:59:59` and UTC fields shifted by the zone offset (verified on TEC's demo: `start_date 2026-11-21 00:00:00`, `utc_start_date 2026-11-21 05:00:00`, `utc_end_date 2026-11-22 04:59:59`). In BST a London all-day item's UTC start falls on the previous day. Use the local `start_date`/`end_date` date parts for `all_day: true`, and UTC (cross-checked against local + `timezone`) only for timed items. `timezone_abbr` is unreliable: the Town Council labels BST dates as "GMT".
3. **D-03's `404 rest_no_route → content_invalid` needs a fetcher change.** `HttpFetcher::classify()` returns `http_status` for any status ≥ 400 before `BodyValidator` runs, and it never reads the body.
4. **D-08's "selectors must compile with symfony/css-selector" should become "compile with `\Dom\HTMLDocument`".** symfony/css-selector is only a transitive dependency (via `tijsverkoyen/css-to-inline-styles`). It translates to XPath for DOMXPath, a different engine from the one we would run. Validating with the engine that executes the selector avoids both a dependency and a mismatch.
5. **D-10 "every Event node is an item" breaks on real data.** Visit Norfolk's `@graph` holds **two** `Event` nodes for one event (a dateless SEO node and a dated node, same `url`). Visit Wymondham reuses one `url` for three dates of "Glow Run Club". Event nodes must be merged by `@id`/`url` before validation, and the JSON-LD identity must be `summary_start` (raw `startDate` + name), not `url`.
6. **D-13's adapter can be built and tested but cannot run live as the fetcher stands.** robots.txt is fetched through the host throttle, so with `Crawl-delay: 12000` the page request always waits 3 h 20 m. Queued runs are abandoned after 120 minutes. The adapter can ship (seeded inactive, as D-13 says), but a live run needs an owner decision on the throttle (Owner item O-3).

Two decisions need extra code that CONTEXT does not mention. D-09 / SC3 ("ambiguous date → *draft* with parse error and Low quality") needs `NormalisedFacts::$startDate` to be nullable and `WindowClassifier` to handle a missing date or time. Today such items are skipped (no draft), and a timed item with no start time throws in `EventTiming::forDate`. D-01's evidence ("a scheduled run with drafts created") may take several days for the Baptist Church: "Create drafts" already drafts everything in the window, so scheduled runs draft only items that are new since then.

**Primary recommendation:** Lead with a TEC REST tracer through a new `Extractor` + `SourceReader` seam that stores multi-page bodies as one JSON envelope, so preview, D-24 body reuse, raw storage and hashing keep working unchanged. Next, land a framework-free strict date toolkit (`createFromFormat` + `getLastErrors() === false` + round-trip check) together with the token-scan architecture test. After that, each remaining rung is an extractor behind the same seam.

<user_constraints>
## User Constraints (from CONTEXT.md)

> Copied verbatim from `04-CONTEXT.md`. All decisions are **[agent default]** (owner away). The Summary above lists the ones research shows are wrong or need changing (D-04, D-05 all-day, D-03 rest_no_route, D-08 selector compiler, D-10 node merge/identity, D-13 live fetch).

### Locked Decisions

#### Live sources and the "in production" wording
- **D-01 [agent default]: P0 sources go live on the local app in Phase 4; the production half moves to Phase 5.**
  - Production only exists after Phase 5 (scope change 2026-10-16), so Phase 4 closes when the P0 sources are active on the local app and produce pending drafts from scheduled runs.
  - Scheduled runs mean the scheduler (`schedule:work` or cron) plus a queue worker; the owner already runs `queue:listen`.
  - ROADMAP success criterion 5 and RES-04 are reworded to "configured and producing drafts on schedule (local); production in Phase 5", and Phase 5 gains the production half, the same way PIPE-07 was split between Phases 3 and 4.
  - Evidence for verification: a scheduled (not manual) `source_runs` row per P0 source with drafts created.
- **D-02 [agent default]: the "initial P0 sources" are the Attleborough Baptist Church ICS and the Attleborough Town Council feed (TEC REST after D-04).**
  - P1 sources (Academy ICS, Heritage Group selectors, Primary adapter) are seeded inactive and may be activated by the owner, but they don't gate the phase.

#### The Events Calendar REST (EXT-02)
- **D-03 [agent default]: request contract.**
  - `GET {site}/wp-json/tribe/events/v1/events?start_date={today London}&end_date={today + calendar.horizon_days}&per_page=50`, then follow `next_rest_url` until it is absent.
  - The window is the Phase 3 import window, 90 days (Phase 3 D-20).
  - Every page goes through the polite `HttpFetcher` (robots, per-host throttle, Crawl-delay, size cap, SSRF guard).
  - A page cap (agent's discretion, about 20) or the run deadline ends the run as **incomplete**: missing-item detection doesn't run, and no validators are stored (Phase 3 WR-01).
  - A `404 rest_no_route` means "this site doesn't run The Events Calendar": the run fails as `content_invalid` with that message.
  - TEC REST sends `no-store`, so no conditional GET; unchanged content is skipped through the raw hash.
- **D-04 [agent default]: switch the Town Council from its ICS export to TEC REST, in place.**
  - Why switch: the ICS export lists only about 30 items, which makes missing-item detection unreliable. REST gives the full window with structured venue, all-day, cost and categories.
  - Why in place: both methods use the `tec_post_id` identity. In the 17 Oct fixtures, all 24 overlapping ICS UIDs carry the same post id as the REST `id`; the 6 ICS-only ids fall before the REST capture window.
  - Changing the extraction method on a source that has items is therefore allowed **only between methods with the same identity keys** (ICS with `tec_post_id` → TEC REST). Every other method change stays refused once items exist, like identity and time zone (Phase 3 WR-03).
  - Changing method clears the stored ETag/Last-Modified.
  - The research step confirms the id equality on a fresh capture.
  - SourceSeeder seeds the Town Council as TEC REST for fresh databases. An existing (dev) row is switched by the admin in the form.
- **D-05 [agent default]: TEC field mapping.**
  - Title: HTML entities decoded.
  - Start/end from `utc_start_date` / `utc_end_date` (with `timezone` as a check) and `all_day`.
  - Per-occurrence `url` for "Read more".
  - `venue.venue` + address into the existing VenueMatcher.
  - `cost` → price/free.
  - `categories` → the per-source category mappings (Phase 3).
  - `description` → the Phase 3 excerpt (≤200 chars, contacts stripped; the Town Council legal basis allows a short excerpt).
  - `organizer[]` is never stored on public fields (Phase 3 D-17).
  - Cancellations: TEC REST has no cancellation status in the observed payloads. Events that vanish are handled by missing-item detection.

#### RSS/Atom (EXT-05)
- **D-06 [agent default]: EXT-05 is part of the Definition of Done.**
  - Research found a qualifying feed: the Baptist Church dated RSS (`<title>` = `YYYY-MM-DD HH:MM - Name`, `<description>` = `Time: Sunday, 18 October 2026 at 10:30`).
  - Both modes ship:
    - **`date_required`:** a per-source date pattern applied to the title and/or description; an item without a parseable date is a parse error.
    - **`link_follow`:** follow each item link and read its JSON-LD with the D-10 extractor, capped per run (agent's discretion, about 20 links) and inside the run deadline.
  - `pubDate` is never the event date.
  - Fixtures: the real Baptist RSS for `date_required`. For `link_follow`, a derived RSS pointing at the captured JSON-LD page (D-11), labelled as derived in the manifest.
- **D-07 [agent default]: the Baptist RSS is seeded as a separate inactive fallback source**, "Attleborough Baptist Church (RSS fallback)".
  - Its notes say to activate it only if the ICS source fails.
  - If both are ever active, the shared `show_event=` URL makes cross-source duplicate detection flag the pairs (canonical URL match).

#### CSS-selector listings (EXT-04)
- **D-08 [agent default]: selector configuration is admin-editable as structured fields in the Sources form** (shown when the method is HTML selectors). It is not a raw JSON box and not per-site PHP classes.
  - Fields:
    - item selector
    - title selector
    - date selector + date pattern(s)
    - optional time selector + time pattern
    - optional end date/time
    - optional venue, link and description selectors
  - Stored in `parser_config`, validated on save (selectors must compile with symfony/css-selector).
  - Each field gets a "?" help entry (why / how / example) following the owner's SourceFieldHelp pattern.
  - Identity for selector items is `summary_start` (title + start) unless a link selector gives per-event URLs.
- **D-09 [agent default]: dates and times are parsed with explicit per-source patterns only (EXT-06).**
  - A date that parses with no parseable start time becomes a draft with a parse error and Low quality ("no start time found"). There is no all-day guess.
  - A per-source "these events are all-day" switch is the only way to get all-day items from a selector source.
  - Prose time ranges such as `10.30-11.30` (Heritage Group) are read by the configured time pattern; anything else is a parse error.
  - Year policies (`sources.year_policy`):
    - `none` (today's only value): dates must carry a year, and a missing year is a parse error.
    - `infer_with_weekday` (new): the EXT-06 rule. A weekday must be present and exactly one year in [fetch − 7 days, fetch + 365 days] must match.
  - An architecture test bans `strtotime`, `Carbon::parse`, `date_parse` and string-parsing `new DateTime*` in `app/Ingestion`. The ICS code is checked against it too.

#### JSON-LD (EXT-03)
- **D-10 [agent default]: the JSON-LD extractor reads every `Event` node on a configured page.**
  - Where it looks: top-level objects, arrays and `@graph`, plus nested nodes.
  - What counts as an event: Schema.org Event subtypes (MusicEvent, TheaterEvent, …) via an explicit list.
  - The page can be a listing (Visit Wymondham) or a single event page; SC1's "e.g. an Eventbrite event" means "a single event page", not Eventbrite itself.
  - Dates:
    - ISO 8601 with an offset is used as given.
    - A date-only value is all-day.
    - A datetime without an offset is read in the source's default timezone (EXT-07).
    - Anything else is a parse error.
  - `url` falls back to the page URL when it is missing or looks like WordPress trash (`__trashed`).
  - `eventStatus`:
    - `EventCancelled` → a cancellation draft (PIPE-07 second half).
    - `EventPostponed` → a cancellation draft whose reason says "Postponed at the source".
    - `EventRescheduled` → normal change detection on the new date.
    - `EventMovedOnline` → the online-only rule (D-16).
- **D-11 [agent default]: JSON-LD fixtures are captured from real pages before planning closes** (politely, with `ops/probe-sources.sh`):
  - The Visit Wymondham listing (robots allows everything; one page; offsets spanning the 25 Oct 2026 clock change).
  - One Visit Norfolk event page (`@graph` wrapper).
  - Personal data is redacted, as with the other fixtures.
- **D-12 [agent default]: Visit Norfolk is not configured as a live source in Phase 4.** It is regional and high-volume, with few Attleborough events, and its listing filter goes through Sprig/htmx. Reading it would need many detail-page fetches per run. Its event page serves only as the `@graph` fixture. A partner data feed from Visit East of England is the better route (owner outreach).

#### Attleborough Primary (embedded JS array)
- **D-13 [agent default]: a small platform adapter `fse_school_calendar`** (spec rung 2, "platform adapter"), selected through the existing `sources.adapter` column.
  - It reads the `json = [[id, ?, "YYYYMMDDTHHMMSS", "YYYYMMDDTHHMMSS", all_day, "title", ...]]` array from the page's `<script>` with strict validation. Anything unexpected is a `content_invalid` run, not a guess.
  - Times are floating, so they are read in Europe/London.
  - Identity is the array's `id`.
  - Built and tested against the real 18 Oct fixture.
  - Seeded inactive. Its notes say the calendar was stale (autumn 2025 only) and mostly pupil-only at capture, and that Crawl-delay 12000 means at most one fetch per 3 h 20 min (daily schedule).
  - It is the lowest-priority plan in the phase: the planner may put it in the last wave.
  - Rosecroft Primary (same provider) may embed the same array. The research step may check once, politely; if it does, Rosecroft is seeded inactive too.

#### Geofence (PIPE-02)
- **D-14 [agent default]: a `filter` source keeps an item only on a positive match.** Checks run in this order on the item's own location data:
  1. A full UK postcode in the location/address. The outward code decides: in the admin's district list (NR17, NR16, NR18, IP24 by default) → keep; any other valid postcode → skip as `out_of_area`.
  2. The venue matches a known venue exactly (the existing `VenueMatcher::exact`; every venue in our table is local) → keep.
  3. An allowlisted place name (whole word, case-insensitive) in the venue name, address or **title** → keep. The description is not checked, because it often names far-off places.
  4. Location text present but nothing matched → skip as `out_of_area`.
  5. No location data at all → keep as a draft flagged "Location unknown, check it's in the Attleborough area" (an "unknown location" in PIPE-02).
  - Skips are counted per reason in the run log and the test-run preview.
  - Postcode districts and place names are the existing admin-editable `geofence_terms` (Rules screen, RulesSeeder defaults).
  - `local` sources skip the geofence entirely.
- **D-15 [agent default]: there is no radius or geocoding.** PIPE-02's postcode districts and place names supersede spec §8.6's "8 miles". The project rules forbid third-party services, and districts plus place names are what the admin already edits.
- **D-16 [agent default]: online-only events are skipped (counted as `skipped_online`) on `filter` sources.**
  - Online-only means:
    - JSON-LD `eventAttendanceMode` = Online, or `EventMovedOnline`.
    - A location that is only a `VirtualLocation`.
    - A location field whose whole text is "Online", "Zoom", "Teams" or "Virtual".
  - Mixed attendance counts as physical, so the geofence applies.
  - `local` sources skip this rule with the rest of the filter (PIPE-02).

#### Test-run preview (SRC-02)
- **D-17 [agent default]: the Phase 3 test-run preview (D-01/D-24/D-26) is extended to every rung, unchanged in shape.**
  - It shows the drafts a run would create and the skips by reason: past, beyond the window, title rule, out of area, online-only, parse error, not changed.
  - Nothing is written.
  - For selector sources it also lists the raw matched rows (title/date/time text before parsing), so the admin can debug selectors.
  - The preview runs on the **saved** configuration (save, then test), as in Phase 3.
  - "Create drafts" reuses a preview body under 60 minutes old (Phase 3 D-24), extended to multi-page TEC runs (all pages reused or none).

#### Seeding (Phase 3 todo, D-05 rules)
- **D-18 [agent default]: SourceSeeder adds the newly supported sources.** All are inactive and create-only (firstOrCreate on slug), with values that pass the Sources form, legal basis and notes from `docs/research/event-sources.md`:
  - Town Council as TEC REST (fresh databases only, D-04).
  - Heritage Group (selectors, with a working config from the fixture).
  - Attleborough Primary (`fse_school_calendar`).
  - Baptist Church RSS fallback (D-07).
  - Visit Wymondham (JSON-LD, `filter`).
  - Diocese of Norwich (TEC REST, `filter`, Crawl-delay 10). Its notes say: "check the terms before activating" (inventory: terms not yet confirmed).
  - Visit Norfolk is not seeded (D-12).

#### Roadmap housekeeping
- **D-19 [agent default, informational]:** the `**Mode:** mvp` tag is removed from Phase 4 in ROADMAP.md. The owner removed it from Phases 1-3 (Phase 3 D-18), and Phase 4 is planned the same way as Phase 3.

#### Carried forward (do not re-ask)
- **Phase 3 backbone:** sources / runs / items; the polite `HttpFetcher` (robots on every URL, honest User-Agent, ≥5 s per host plus Crawl-delay, size cap, SSRF guard and address pin, per-rung body validation, bot wall = `content_invalid`, robots disallow = `blocked`); raw and content hashes; accepted baseline; change / cancellation / missing drafts; duplicates; health; attribution; excerpts (≤200 chars, no contacts); title skip/keep rules; per-source category mappings; frozen identity and time zone.
- **Time and parsing rules:** never publish automatically; parsers reject rather than guess; floating times are Europe/London; unknown TZIDs are parse errors; store UTC; sabre/vobject is the only recurrence engine; the import window is 90 days.
- **Writes:** only `App\Publishing\Actions` write events and occurrences (arch test).
- **Sources:** never bypass bot protection. The Cloudflare-walled sources (Breckland, Norfolk CC, Norfolk Museums, TicketSource) stay blocked until a Phase 2 D-25 route works. Facebook and Instagram are never scraped.

### the agent's Discretion
- TEC page cap and RSS `link_follow` cap (about 20 each); how a capped run is reported.
- Exact field names and layout of the selector form section and the preview's raw-rows view.
- The internal shape of extractor classes and the extractor registry/dispatch (IngestRun currently hard-wires `IcsExtractor`).
- Parse-error and skip-reason wording, within the Phase 1 brand voice.
- The exact Event-subtype list and the online-location keywords.

### Deferred Ideas (OUT OF SCOPE)
- Radius geofence from JSON-LD `geo` coordinates (offline distance from Attleborough centre). PIPE-02 doesn't need it; revisit if postcode/place-name matching proves too coarse.
- Visit Norfolk as a live source through listing → detail link-follow (D-12). Prefer a Visit East of England partner feed.
- Test-running unsaved selector configuration straight from the form (D-17 keeps save-then-test).
- Honouring `Retry-After` (Phase 3 WR-04) and feed personal-data retention (Phase 3 WR-06): already scheduled before launch (STATE).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| EXT-02 | TEC REST: explicit window, full pagination, verified against live installs | §TEC REST rules; `TecRestReader` (multi-page envelope, page cap, same-host `next_rest_url`); `rest_no_route` hook (Pitfall 9); all-day/UTC rule (correction 2); fixtures p1/p2 + derived all-day/DST copies |
| EXT-03 | JSON-LD incl. `@graph`, arrays, subtypes, `eventStatus` | §JSON-LD rules: walker, node merge by `@id`/`url` (Pitfall 6), verified subtype + enum lists, date-format allowlist; real Wymondham/Norfolk fixtures captured this session |
| EXT-04 | Multi-event selector listing, per-source date patterns | Native `\Dom\HTMLDocument` (probed on the real Heritage page); `parser_config.selectors` shape; derived 12-row Heritage fixture for SC1 |
| EXT-05 | RSS/Atom `date_required` / `link_follow`, never `pubDate` | Baptist RSS rules (title prefix `Y-m-d H:i`); SimpleXML safe flags; link-follow reusing the JSON-LD extractor + reader caps |
| EXT-06 | Explicit patterns, ambiguous → parse error, weekday year rule, arch ban | `StrictDate` helper (round-trip + `getLastErrors`), `infer_with_weekday` algorithm (prototyped), arch test = Pest `not->toUse([functions])` + token scan; 6 existing violations to refactor |
| EXT-08 | Fixture tests from real samples incl. DST, offline | Fixture inventory incl. new captures; derived fixtures list; `Http::fake` + `sourceFixture()` + `freezeLondon()` pattern |
| PIPE-02 | Geofence districts/place names, online skip, unknown → flagged draft | Postcode finder (prototyped), placement in `SourceItemRecorder::classify`, `skipped_online` counter, quality-reason flag |
| PIPE-07 (2nd half) | JSON-LD `eventStatus` cancellations | `NormalisedFacts::$cancelled` + new status note; existing ICS cancellation path reused |
| SRC-02 | Test-run preview for every rung | Preview rows/skips extended; raw selector rows; envelope makes D-24 reuse "all pages or none" for free |
| RES-04 (local) | P0 sources producing drafts on schedule (local) | `trigger = 'schedule'` evidence query; owner runbook (schedule:work + queue:listen, check_time, home network) |
</phase_requirements>

## Project Constraints (from AGENTS.md / .ai/guidelines/attleborough.md)

There is no `./copilot-instructions.md` at the repo root. `.github/copilot-instructions.md` holds only GSD routing. These directives come from `AGENTS.md` and `.ai/guidelines/attleborough.md` and are binding:

- **Time:** app and PostgreSQL session TZ are UTC; store instants as `timestampTz` UTC; do date arithmetic on Europe/London `CarbonImmutable`; `recurrence_key` is the London `Y-m-d`; floating feed times are Europe/London.
- **Recurrence:** sabre/vobject `EventIterator` is the only expansion engine.
- **Writes:** only `App\Publishing\Actions\*` write events/occurrences (arch test `tests/Unit/Architecture/WriteChokePointTest.php`). Ingestion writes drafts only, through `DraftWriter`.
- **Tests:** Pest 5 on PostgreSQL (`attleborough_testing`); `Http::preventStrayRequests()` is global; freeze London time; DST fixtures Sun 25 Oct 2026 and Sun 28 Mar 2027; raw-SQL UTC assertions; CI `TZ=America/New_York`; browser UAT flows in `docs/uat/browser-uat.md`, updated for new UI.
- **Filament 5:** `Filament\Schemas\Schema`, `Filament\Schemas\Components\*`, `Get`/`Set` in `Filament\Schemas\Components\Utilities`, actions `Filament\Actions\*`, enums implement `HasLabel`. Never call `FilamentTimezone::set()`.
- **Dependencies:** "Do not change the application's dependencies without approval." No Redis, no LLM SDKs, no third-party services or scripts.
- **Sources:** never bypass bot protection; robots honoured on every URL; Facebook/Instagram/Eventbrite never scraped.
- **Skills:** `laravel-best-practices` (prefer existing abstractions, no speculative abstractions); `testing-best-practices` (feature test first; unit tests only for framework-free logic; arch tests judged by the convention they protect; no new test dependencies).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Polite fetching (robots, throttle, SSRF, caps), multi-page reading | API / Backend (queue job: `FetchSourceJob` → `IngestRun` → `SourceReader` → `HttpFetcher`) | Database (`crawl_hosts`, `source_runs`) | All network I/O stays in the single fetch path (SRC-05) |
| Per-rung body validation (JSON / HTML / XML / bot wall) | API / Backend (`BodyValidator`, `HttpFetcher::classify`) | — | Must run before extraction (SRC-06) |
| Extraction (TEC, JSON-LD, selectors, RSS, FSE adapter) | API / Backend (pure extractor classes, no DB) | — | Pure functions of body + source config + clock, so they can be tested from fixtures |
| Strict date/time parsing, year inference | API / Backend (framework-free helper) | — | EXT-06; unit-testable without the framework |
| Geofence / online skip | API / Backend (`SourceItemRecorder::classify`) | Database (`geofence_terms`, `venues`) | Shares verdict() with preview so preview and live cannot drift |
| Location-unknown flag | API / Backend (`QualityScorer` reason) | Admin UI (review page shows reasons) | No schema change; the score is already shown everywhere |
| Selector config editing + validation | Admin UI (Filament `SourceForm`) | API (validation closure using `\Dom\HTMLDocument`) | D-08; save-then-test |
| Preview rendering (rows, skips, raw selector rows) | Admin UI (`EditSource` + Blade view) | Database (`source_runs.preview` jsonb) | D-17 |
| Scheduling evidence (RES-04) | Database (`source_runs.trigger='schedule'`) | Ops (local `schedule:work` + `queue:listen`) | Already recorded by Phase 3 |

## Standard Stack

### Core (all already installed; no dependency changes)
| Library / built-in | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| PHP `\Dom\HTMLDocument` (ext-dom, lexbor HTML5 parser) | PHP 8.4.12 (prod 8.5) | HTML5 parsing + native `querySelector(All)` | [VERIFIED: probe on `tests/Fixtures/sources/attleborough-heritage-group/2026-10-17-events-page.html`] selectors `#ctl00_maincontent_GridViewListMobile tr.closebtn`, `table.gridleft tr:nth-child(2) > td` returned the real title/date; `:has()`, `:not()`, `:nth-of-type()` compile; `:contains()` and `:scope` throw `DOMException`. 963 KB page parsed in 9 ms, peak 12.9 MB; 4.8 MB in 43 ms, peak 60 MB (CLI memory_limit 128M) |
| PHP SimpleXML / libxml | bundled (libxml 2.12.5 = `LIBXML_VERSION 21205`) | RSS 2.0 / Atom parsing | Built in; safe by default when `LIBXML_NOENT` / `LIBXML_DTDLOAD` / `LIBXML_PARSEHUGE` are **not** passed [ASSUMED for libxml defaults; standard PHP ≥ 8 behaviour] |
| `DateTimeImmutable::createFromFormat` + `getLastErrors()` | PHP 8.4 | Explicit-pattern date parsing | [VERIFIED: probes this session] `getLastErrors()` returns `false` when there are no warnings or errors; overflow (`2026-02-30`) and `25:10` only produce **warnings**, so the check must be `=== false` |
| `json_decode(..., flags: JSON_THROW_ON_ERROR, depth: N)` | PHP 8.4 | TEC JSON, JSON-LD blocks, FSE array | Built in |
| sabre/vobject | 5.0.0 | Unchanged (ICS only) | [VERIFIED: `composer show --direct`] |
| Pest arch (`pestphp/pest-plugin-arch`) | Pest 5.3.2 | Function ban | [VERIFIED: probe] `expect('App\Ingestion')->not->toUse(['strtotime', 'date_parse'])` fails on a planted `strtotime`; `expect([...functions])->not->toBeUsedIn('App\Ingestion')` **passed vacuously** with the same plant |
| PHPStan / Larastan | phpstan 2.3.1, larastan 3.13.0, level 6 | Static analysis | [VERIFIED: probe] analyses `Dom\HTMLDocument` code with correct types |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `\Dom\HTMLDocument::querySelectorAll` | symfony/dom-crawler + symfony/css-selector | dom-crawler is **not installed** (`composer show symfony/dom-crawler` → not found); css-selector is only transitive (`tijsverkoyen/css-to-inline-styles v2.4.0 requires symfony/css-selector`). Depending on a transitive package directly needs owner approval, and it targets libxml's HTML4 parser via XPath. Not recommended |
| SimpleXML | XMLReader | XMLReader streams, but bodies are capped at 5 MiB, so SimpleXML is simpler |
| strict `createFromFormat` | `Carbon::parse` / `strtotime` | Banned by EXT-06 |
| spatie/schema-org (installed) | — | It *builds* JSON-LD for our pages (EVT-03); it does not parse |

**Installation:** none. **No owner approval needed for dependencies.**

## Package Legitimacy Audit

No external packages are installed by this phase. Every capability uses PHP built-ins or already-locked packages (`composer show --direct` this session: deployer/deployer 8.0.5, filament/filament 5.10.2, laravel/framework 13.37.0, pestphp/pest 5.3.2, sabre/vobject 5.0.0, spatie/schema-org 5.0.2, …).

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| (none) | — | — | — | — | — | — |

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
 scheduler (ingest:dispatch-due, every 10 min) ──┐        admin: Test run / Create drafts / Run now
                                                 ▼                         │
                                  RunLauncher → source_runs row (mode, trigger) → FetchSourceJob (queue)
                                                                                      │
                                                                                      ▼
                                                                IngestRun::execute(run)
                                         ┌────────── D-24: activation run & fresh preview? ──yes──► reuse stored body/envelope
                                         │no                                                         │
                                         ▼                                                           │
                         ReaderRegistry::for(source) ─► SinglePageReader (ics, json_ld, html_selectors, rss/date_required, adapter)
                                         │            └► TecRestReader   (page 1 → next_rest_url … ≤ page cap, same host)
                                         │            └► RssLinkFollowReader (feed → ≤ N item links, same pipeline per page)
                                         ▼  every request: HttpFetcher (SSRF pin → robots → HostThrottle → GET → classify → BodyValidator[method])
                              FetchResult (body = raw body, or JSON envelope {pages[], complete, note})
                                         │ blocked / not_modified / failed ─► finish(run) (no extraction)
                                         ▼ ok
                              RawBodyStore::put + raw_sha256
                                         ▼
                         ExtractorRegistry::for(source) ─► Ics | TecRest | JsonLd | HtmlSelector | Rss | FseSchoolCalendar
                                         │   (pure; StrictDate for every date; returns ExtractionResult{items, fatalError, complete})
                                         ▼
                   per item: SourceItemRecorder::verdict/record
                       parse error? ─► title rules ─► window (past / far future) ─► [filter only] online? ─► geofence (postcode → venue → place name → text-without-match / unknown)
                                         ▼ new / changed / unchanged
                       DraftWriter (New / update / cancellation drafts, duplicates)  — preview: write nothing, build run.preview rows
                                         ▼
                       finish(): counters (+ skipped_online), validators (single-page only), health, is_complete (forced false if !complete), missing detection
```

### Hard-wired ICS points the plans must change (verified this session)

| Where | Verbatim today | Change |
|---|---|---|
| `app/Ingestion/IngestRun.php:78` | `private readonly IcsExtractor $extractor,` | inject `ExtractorRegistry` (+ `ReaderRegistry`) |
| `app/Ingestion/Http/FetchRequest.php:15,21` | `public const string METHOD_ICS = 'ics';` / `public string $method = self::METHOD_ICS,` | method per rung; Accept header per method |
| `app/Ingestion/Http/HttpFetcher.php:59` | `public const string ACCEPT_ICS = 'text/calendar, text/plain;q=0.5, */*;q=0.1';` | add JSON / HTML / XML Accept values; `icsHeaders()` → per-method headers |
| `app/Ingestion/Http/BodyValidator.php` `validate()` | `FetchRequest::METHOD_ICS => $this->validateIcs($body),` / `default => 'Unsupported extraction method',` | add `validateJson`, `validateHtml`, `validateFeed` |
| `app/Review/Data/DraftData.php:276` | `extractionMethod: ExtractionMethod::Ics,` | `$source->extraction_method` (otherwise every draft says "ICS feed") |
| `app/Enums/ExtractionMethod.php:15-17` | `case Submission = 'submission';` `case Manual = 'manual';` `case Ics = 'ics';` | add cases |
| `database/migrations/2026_10_18_100100_create_sources_table.php:65` | `CHECK (extraction_method IN ('ics'))` | widen |
| `database/migrations/2026_10_18_100400_…:49` | `event_drafts_extraction_method_check CHECK (extraction_method IN ('submission', 'manual', 'ics'))` | widen |
| `database/migrations/2026_10_18_100100_create_sources_table.php:41` | `$table->string('year_policy', 16)->default('none');` | **`'infer_with_weekday'` is 18 characters**: widen to 32 + add a CHECK |
| `app/Filament/Resources/Sources/Schemas/SourceForm.php:59-61` | `'none' => 'Dates include the year',` | add `infer_with_weekday` |
| `app/Filament/Resources/Sources/Schemas/SourceForm.php:136-137` | `->options([ExtractionMethod::Ics->value => ExtractionMethod::Ics->getLabel()])` | all methods; adapter select enabled when method = adapter |
| `database/seeders/SourceSeeder.php:54` | `'extraction_method' => ExtractionMethod::Ics,` | per-entry method / location_mode / year_policy / parser_config |
| `app/Ingestion/Pipeline/ContentHasher.php:32` `rawHash()` | ICS line-based (drops `DTSTAMP`, `LAST-MODIFIED`, `CREATED`, `SEQUENCE`) | add a canonical-JSON raw hash for structured items (drop TEC `modified`, `modified_utc`, …) |
| `app/Models/SourceRun.php:63-67` | `'skipped_past', 'skipped_far_future', 'skipped_rule', 'skipped_parse_error', 'skipped_out_of_area',` | add `skipped_online` (column + COUNTERS + recorder bucket + preview key + runs table column) |

`skipped_out_of_area` already exists [VERIFIED: `database/migrations/2026_10_18_100200_create_source_runs_table.php:43` `'skipped_past', 'skipped_far_future', 'skipped_rule', 'skipped_parse_error', 'skipped_out_of_area',`]. Run triggers are [VERIFIED: `app/Enums/RunTrigger.php:13-15`] `case Schedule = 'schedule';` `case Manual = 'manual';` `case Activation = 'activation';`.

### Recommended Project Structure (new files)
```
app/Ingestion/
├── Extraction/            # Extractor interface, ExtractorRegistry, ReaderRegistry, SinglePageReader, FetchEnvelope
├── Tec/                   # TecRestReader, TecRestExtractor
├── JsonLd/                # JsonLdExtractor, JsonLdEventWalker (framework-free), JsonLdDate
├── Html/                  # HtmlSelectorExtractor, SelectorConfig (value object + validation)
├── Rss/                   # RssExtractor (date_required), RssLinkFollowReader
├── Adapters/              # FseSchoolCalendarExtractor
├── Dates/                 # StrictDate, YearInference, TimeFinder (framework-free; the only date-parsing code)
└── Pipeline/Geofence.php  # + OnlineDetector (or inside Geofence)
app/Ingestion/Support/     # UkPostcode, TextNormaliser (shared line/description/entity decoding)
```

### Pattern 1: Reader + envelope (multi-page without touching the rest of the pipeline)
**What:** a `SourceReader` returns **one** `FetchResult`. For TEC and link-follow its body is a JSON envelope:
```json
{"envelope":"woa-pages/1","complete":true,"note":null,"pages":[{"url":"…page=1","status":200,"body":"{…}"},{"url":"…page=2","status":200,"body":"{…}"}]}
```
**Why:** `RawBodyStore::put`, `raw_sha256`, D-24 reuse (`reusablePreviewBody` re-hashes the stored body) and preview all work on one string, so "all pages reused or none" comes for free. Extractors for these methods decode the envelope.
**Rules:**
- the reader stops (`complete:false`, `note`) on: page cap; run-deadline margin; a `next_rest_url` on another scheme/host; a page that fails; `HostBusy` **after page 1** (catch it and stop: releasing the job would throw away the pages already fetched); a total-bytes cap (recommend `ingest.max_envelope_bytes` ≈ 10 MiB).
- `HostBusy` on page 1 still escapes (unchanged Phase 3 behaviour).
- Each page's `FetchRequest::startedAt` must be **the moment that page starts**, not the run start. `fetch_deadline` (55 s) is counted from `startedAt` [VERIFIED: `HttpFetcher::fetch` `$startedAt = $request->startedAt ?? CarbonImmutable::now();`], and IngestRun passes `startedAt: $run->started_at` today. The reader enforces `run_deadline` (240 s) itself.
- A page-1 failure is the run's failure (same statuses as today). A later page failure → stop incomplete with what we have.
- **Incomplete** → run `succeeded`, but `is_complete` forced false (pass a flag to `SourceHealthTracker::record` or set it after), no validators, `error_message` note ("Read 20 of 31 pages…"). Not `failed`: a large site must not degrade health.

### Pattern 2: Extractor registry
```php
// Shape only (names are recommendations, not existing code)
interface Extractor {
    public function extract(string $body, Source $source, CarbonImmutable $nowLondon): ExtractionResult;
}
// ExtractorRegistry::for(Source $s): match ($s->extraction_method) { Ics => IcsExtractor, TecRest => …,
//   JsonLd => …, HtmlSelectors => …, Rss => …, Adapter => match ($s->adapter) { 'fse_school_calendar' => … } }
```
`IcsExtractor::extract(string $body, Source $source, ?CarbonImmutable $now = null)` already fits. `ExtractionResult` gains `bool $complete = true`. Its `eventCount` doc ("VEVENT components") is generalised to "event records seen".

### Pattern 3: Strict date toolkit (framework-free, the only parsing code in `app/Ingestion`)
- `StrictDate::parse(string $format, string $text, DateTimeZone $tz): ?DateTimeImmutable`. Collapse whitespace (incl. U+00A0); `createFromFormat('!'.$format, …)`; require `DateTimeImmutable::getLastErrors() === false`; require `mb_strtolower($d->format($format)) === mb_strtolower($normalisedText)` (round trip). The round trip rejects weekday/date mismatches (PHP silently moves the date to the named weekday), overflow, `Nov` for `F`, and spring-forward gap times.
- `YearInference::resolve(format-without-Y, text, fetchLondon)`: the format must contain `l` or `D` and no `Y`/`y`. For each candidate year in {fetch−1, fetch, fetch+1}, `StrictDate::parse($fmt.' Y', $text.' '.$year)`; keep those whose date is in [fetch − 7 days, fetch + 365 days]. Exactly one → that date; otherwise a parse error. Prototype results (fetch 2026-10-18): `Saturday 21 November` → 2026-11-21; `Sunday 21 November` → none; `Friday 15 October` → 2027-10-15; `Thursday 15 October` → 2026-10-15; `Saturday 29 February` → none.
- `TimeFinder`: code-defined time formats only (no admin regex; the project already forbids admin-written patterns, `IdentityStrategy` doc "no admin-written regex: ReDoS, invalid patterns"). Recommend the set `H:i`, `G:i`, `G.i`, `g:ia`, `g.ia`, `ga`, each as a single time or a range `{t}{-|–|to}{t}`, found in free text by a fixed regex per format. Then validate each hit with `StrictDate`. **Exactly one** match (one time or one range) → start/end; zero or several different ones → "no start time found" / "more than one time found".
- **Allowed admin date formats:** a whitelist of PHP format characters (`d j D l S F M m n Y`, plus literals and `\`-escapes). Refuse `y` (2-digit year), `U`, `u`, `v`, `e`, `T`, `O`, `P`, `p`, `*`, `+`, `?`, `#`, `!`, `|` on save. The form offers presets ("Saturday, 21 November 2026" → `l, j F Y`; "21 November 2026" → `j F Y`; "Sat 21 Nov" → `D j M`) plus a validated custom field.

### Pattern 4: Geofence inside the shared verdict
Put it in `SourceItemRecorder::classify()` (used by both `record()` and `verdict()`) so preview and live cannot drift. Order: `facts === null` → parse error; title rules; window; then **only when `location_mode === 'filter'`**: online → `skipped_online`; geofence → `skipped_out_of_area`. Put window before geofence so counts reflect in-window items. Tracked items (linked or with a pending New draft) already bypass rules and window in `record()`; they must bypass the geofence too, otherwise a linked event whose venue text changes would never reach change detection.

### Anti-Patterns to Avoid
- **One `ExtractedItem` per JSON-LD node:** produces dateless duplicates (Visit Norfolk). Merge first.
- **`url` as JSON-LD identity:** collides (Glow Run Club ×3). Use `summary_start`.
- **Validating selectors with one engine and running another:** use `\Dom\HTMLDocument` for both.
- **Releasing the job on `HostBusy` mid-pagination:** loses pages. Stop incomplete instead.
- **A generic `captcha` challenge marker:** the real Visit Wymondham page loads Google reCAPTCHA for a form (`<script id="google-recaptcha-js" src="https://www.google.com/recaptcha/api.js…`). Use specific markers (Pitfall 8).
- **Treating TEC `utc_*` as truth for all-day items:** wrong day in BST.

## Per-Source Parsing Rules (from the real fixtures, analysed this session)

### TEC REST (Town Council, Diocese)
- **Contract** [VERIFIED: TEC's own schema `GET https://demo.theeventscalendar.com/wp-json/tribe/events/v1/doc` (title "The Events Calendar REST API")]: `/events` params include `page`, `per_page`, `start_date` ("Events that start on the specified date"), `end_date`, `strict_dates` ("The default behavior is to include the entire days"), `status`. **No `per_page` maximum is documented.** `per_page=50` returns 50 [VERIFIED: fixture p1]. The schema has no event-status field (`event_status`, `tribe_events_status`, `canceled`, `postponed` all absent), which confirms D-05's "no cancellation status".
- **Pagination** [VERIFIED: fixtures]: p1 keys `events, rest_url, next_rest_url, total, total_pages` (`total` 72, `total_pages` 2); p2 has `previous_rest_url` and no `next_rest_url`. TEC rewrites the window in `next_rest_url` (`start_date=2026-10-19+00%3A00%3A00&end_date=2026-11-22+23%3A59%3A59&status=publish&page=2`): follow it verbatim, but only on the source's scheme + host. Headers `x-tec-total` / `x-tec-totalpages` are present [VERIFIED: demo response `x-tec-total: 24`, `x-tec-totalpages: 1`]. The body's `total`/`total_pages` are the stronger cross-check (no header plumbing needed).
- **Request:** `start_date={London today Y-m-d}&end_date={London today + 90 days Y-m-d}&per_page=50`. The source `url` should store the collection endpoint (`https://attleboroughtc.org.uk/wp-json/tribe/events/v1/events`). Validate on save that the path ends with `/wp-json/tribe/events/v1/events`.
- **Ids** [VERIFIED: fixtures]: 72 unique; 62 provisional (≥ 10 000 000, recurring occurrences, URL `/event/{slug}/{date}`), 10 real post ids (singles, URL `/event/{slug}`). See correction 1 (D-04).
- **Times** [VERIFIED: fixtures]: all 72 have `timezone` `Europe/London`, and local `start_date` converted from London equals `utc_start_date` for every item. `timezone_abbr` is wrong on some rows (e.g. `2026-10-23 09:30:00` / `08:30:00` UTC labelled `GMT`), so never read it. Rule: timed → instant from `utc_start_date` (`!Y-m-d H:i:s`, UTC), London wall clock from it; parse error if it disagrees with local `start_date` read in `timezone`. All-day → date parts of local `start_date`/`end_date`; end inclusive (`23:59:59`); `endDate = null` when it is the same day [VERIFIED: demo all-day `2026-11-21 00:00:00`→`2026-11-21 23:59:59`, `utc_start_date 2026-11-21 05:00:00` (America/Detroit)]. The fixtures have **no all-day items**: build a derived all-day + 28 Mar 2027 copy.
- **Fields** [VERIFIED: fixtures + demo]: `title` has entities (`Planning &#038; Highway Committee Meeting`); `venue` is an object (`venue`, `address`, `city`, `zip` optional) **or an empty list `[]`** on the demo; venue names have entities (`Attleborough Town Football &#038; Social Club`); `categories[].name` has entities (`&amp;`); `cost` is `''` (all 72), demo `'Free'` and `'&#036;100.00'`; `cost_details.values` is a list; `description` is HTML (`<p>2 x Services</p>`), present on 3 of 72; `organizer` is a 1-element list (personal data: payload only); `is_virtual`, `virtual_url` exist (`false`/`null` on all 72).
- **Venue string, to avoid update-draft floods:** build `venueName = decode(venue.venue)` and `venueAddress = implode(', ', non-empty [address, city, (stateprovince|province), zip])`. For `Recreation Ground` this gives the same normalised venue as ICS `LOCATION:Recreation Ground\, Station Road\, Attleborough\, NR17 2AS` [VERIFIED: compared all 24 overlapping items; start, end, title (entity-decoded) and location string were identical]. Add a regression test: same content hash for ICS vs REST facts of the overlapping items.
- **Price:** `cost` decoded; `free` (case-insensitive) or every `cost_details.values` = 0 → Free; any value > 0 or a currency amount → Paid; `''` → Unknown.
- **Online (filter sources):** `is_virtual === true` and no venue → online-only.

### JSON-LD (Visit Wymondham listing, Visit Norfolk event page)
- **Subtypes** [VERIFIED: schema.org vocabulary `schemaorg-current-https.jsonld`, transitive `rdfs:subClassOf schema:Event`]: BroadcastEvent, BusinessEvent, ChildrensEvent, ComedyEvent, ConferenceEvent, CourseInstance, DanceEvent, DeliveryEvent, EducationEvent, EventSeries, ExhibitionEvent, Festival, FoodEvent, Hackathon, LiteraryEvent, MusicEvent, OnDemandEvent, PerformingArtsEvent, PublicationEvent, SaleEvent, ScreeningEvent, SocialEvent, SportsEvent, TheaterEvent, VisualArtsEvent (+ the superseded `UserInteraction` family). **Recommended allowlist:** `Event` + all of the above **except** `UserInteraction` and its `User*` subtypes (superseded by `InteractionCounter`) and `EventSeries` (a container that usually has no single start). `@type` may be a string or a list; accept `schema:`/full-IRI prefixes.
- **Enums** [VERIFIED: same vocabulary]: `EventStatusType` = `EventCancelled, EventMovedOnline, EventPostponed, EventRescheduled, EventScheduled`; `EventAttendanceModeEnumeration` = `MixedEventAttendanceMode, OfflineEventAttendanceMode, OnlineEventAttendanceMode`. Values in the wild are full IRIs (`https://schema.org/EventScheduled` in the Wymondham capture); also accept `http://`, `schema:` and bare forms. Unknown status → keep the facts plus a parse error.
- **Walk:** decode every `<script type="application/ld+json">` (match type case-insensitively, trimmed, ignoring parameters); depth limit (e.g. 32). Recurse into lists, `@graph` and nested objects. Collect nodes whose type is allowed, then **merge nodes sharing `@id`, else `url`** (first non-empty value wins). Only then require `startDate`. A block with invalid JSON is skipped with a run note; a page with **no** ld+json block is `content_invalid` ("No structured data found on the page").
- **Wymondham capture** [VERIFIED: 13 blocks, 12 top-level `Event` objects, all `@type: "Event"`]: offsets `+01:00` until 23 Oct and `+00:00` from 25 Oct; names with entities (`Kett&#8217;s`, `&amp;`); `location` always `Place`, address free text with NR18/NR16 postcodes or empty (`"name": "", "address": ""` on 3 items); `url` repeats (`/events/glow-run-club/` ×3) and points off-site for 2 items (`https://kettsbooks.co.uk/…`, a `chloesfitness.co.uk` URL with `&#038;` entities); no `__trashed` URLs in this capture (D-10 fallback still needed per research notes); `organizer` is a `Person` (names redacted).
- **Norfolk capture** [VERIFIED]: one block `{"@context","@graph":[Event, LocalBusiness #identity, Organization #creator, BreadcrumbList, Event]}`; Event #1 has no `startDate`; Event #2 has `startDate 2026-11-28T09:00:00+00:00`, `location {Place, name "Holkham", address "Holkham, Norfolk, England, United Kingdom"}`, same `url`.
- **Dates (D-10):** allowlist formats `Y-m-d` (all-day), `Y-m-d\TH:i`, `Y-m-d\TH:i:s`, `Y-m-d\TH:i:s.v` / `.u` (floating → source TZ), and each of the timed ones + `P` (covers `Z` and `+01:00`) or `O` (`+0100`), all through `StrictDate`. Offset values → convert to the London wall clock. A date-only `endDate` is inclusive (last day) [ASSUMED]. A space instead of `T` → parse error (D-10 "anything else").
- **Identity:** `summary_start` = `{raw startDate}|{normalised name}` (same shape as ICS `summaryStartKey`); `uid` = `@id` only when the site emits per-event `@id`s. Default `summary_start`, and seed Visit Wymondham with `supports_missing_detection = false` (a moved time looks like removed + added).
- **Read-more URL:** decode entities; use the event `url` if it is on the page's host, otherwise the page URL (keep the external URL in the payload). The "Read more at Visit Wymondham" attribution must not point at a third-party shop. `__trashed` → page URL.
- **Cancellations:** `EventCancelled` → `cancelled = true` (existing PIPE-07 path: new identity → cancelled item, no draft; linked → cancellation draft). `EventPostponed` → `cancelled = true` + a status note "Postponed at the source", carried in the payload and shown on the draft (add `?string $statusNote` to `NormalisedFacts` and the payload; keep it **out** of the content hash).
- **Expected geofence result for the Wymondham fixture (12 items, frozen 2026-10-18):** keep 9 (NR18 ×7, NR16 1AN Wreningham ×1, title "Youth Cafe Wymondham" ×1), keep-flagged-unknown 2 (empty Place: "Whatever Wednesdays…", "Dungeons & Dragons…"), skip out_of_area 1 ("Ketts Books", name only, no postcode or place name). The Norfolk fixture item → out_of_area (Holkham, no postcode). Derived copies are needed for `EventCancelled`, `EventPostponed`, `EventMovedOnline`, `OnlineEventAttendanceMode`, `VirtualLocation`, `MixedEventAttendanceMode`, floating datetimes and a 28 Mar 2027 date.

### HTML selectors (Heritage Group)
- [VERIFIED: probe] Two GridViews. Mobile `#ctl00_maincontent_GridViewListMobile` holds everything: each event is `tr.closebtn` → nested `table.gridleft`. Row 1 `td` = `Saturday, 21 November 2026`, row 2 = title, rows 3–5 empty (an `<img>` inside a `<tr>` gets foster-parented), last row `p` = prose with `10.30-11.30` and the place. Desktop `#ctl00_maincontent_GridViewList tr.gridselectedrow` has only `21 November 2026` + title (no weekday, time or description). **Use the mobile grid.**
- Working config: item `#ctl00_maincontent_GridViewListMobile tr.closebtn`; title `table.gridleft tr:nth-child(2) > td`; date `table.gridleft tr:nth-child(1) > td`, format `l, j F Y`; time source `table.gridleft p`, time format `G.i` range; description `table.gridleft p`; no link (postback rows) → identity `summary_start` = `{raw date text}|{raw time text}|{normalised title}`. Two rows with the same key in one body → keep the first, add a parse error ("listed twice"), like ICS.
- No venue selector: the venue is in the prose ("St Mary's Church Hall"), so drafts get "Missing venue" and need the admin's pick (local source, no geofence).
- SC1 needs a derived 12-row copy built from the real row markup (labelled `derived` in the manifest), including at least one ambiguous-date row and one row with no time (SC3).

### RSS date_required (Baptist fallback)
- [VERIFIED: fixture] 64 items, every `<title>` matches `YYYY-MM-DD HH:MM - Name`; `<description>` is entity-escaped HTML (`&lt;b&gt;Time:&lt;/b&gt; Sunday, 18 October 2026 at 10:30&lt;br /&gt;…&lt;b&gt;Location:&lt;/b&gt; Church Building&lt;br /&gt;&lt;b&gt;Postcode:&lt;/b&gt; NR17 2HX`); `<link>` = `…Month.aspx?show_event={id}`; no `<guid>`; `<pubDate>` = start in UTC (ignored).
- Config: `rss_mode=date_required`, `date_source=title_prefix`, separator ` - `, format `Y-m-d H:i` (London floating); title = the remainder. Alternative `date_source=description_label` with label `Time:` and format `l, j F Y \a\t H:i` [VERIFIED: `StrictDate` probe gave `2026-10-18T10:30:00+01:00`]. Labelled fields `Location:` / `Postcode:` → venue / postcode.
- Identity `uid` = `<guid>` else `<link>`. An item with no parseable date → `skipped_parse_error`, **no draft** (feeds mix posts and events; drafting every undated post would spam the queue). This differs on purpose from selector rows (SC3), which are known event rows.
- SimpleXML: `simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA)`; never `LIBXML_NOENT`/`LIBXML_DTDLOAD`/`LIBXML_PARSEHUGE`. Atom: `<entry>`, `<link rel="alternate" href>`, `<id>`, `<summary>`/`<content>`.

### RSS link_follow
- Reader: feed (single request), then up to N item links (recommend 20), each through `HttpFetcher` with the JSON-LD/HTML validator, stopping incomplete on the cap or deadline. Same-host links only, unless the admin opts in (recommend same registrable host). Each page → `JsonLdExtractor`, and the item URL is the page URL. Never send conditional validators for link_follow (the feed being unchanged says nothing about the linked pages). Fixture: derived RSS whose `<link>`s point at the captured Visit Norfolk / Wymondham pages, served by `Http::fake`.

### FSE school calendar adapter (Attleborough Primary)
- [VERIFIED: fixture] exactly one `json = [[` assignment (plus one `json = new Array();`), terminated by `]];limitedto`. 22 rows, each exactly 12 elements with types `str,int,str,str,bool,str,int,list,bool,list,str,str`; `[0]` unique id string; `[2]`/`[3]` `Ymd\THis`; `[4]` all-day; `[5]` title (`\u2019` escapes). All-day rows (10) are `T000000`→`T235900` (inclusive end day); timed rows: 8 have start == end (point time → no end), 4 have a real end.
- Extract the substring between `json = ` and `;limitedto` (or the matching `]]`), then `json_decode` (depth 4, throw). Validate the exact arity and types per row; any deviation → fatal `content_invalid`. Dates `StrictDate('Ymd\THis', …, Europe/London)`.
- Rosecroft Primary: robots fetched 2026-10-18T17:59:56Z (`Crawl-delay: 12000`, `/parents/school-calendar` allowed); page request deferred until 22:19 BST (owner item O-4).

## Geofence, Online Skip and Preview Details

- **Postcode finder** (prototyped this session; shared `UkPostcode` helper):
  `/(?<![A-Z0-9])((?:[A-PR-UWYZ][0-9][0-9]?|[A-PR-UWYZ][A-HK-Y][0-9][0-9]?|[A-PR-UWYZ][0-9][A-HJKPSTUW]|[A-PR-UWYZ][A-HK-Y][0-9][ABEHMNPRV-Y]))\s*([0-9][ABD-HJLNP-UW-Z]{2})(?![A-Z0-9])/iu`
  Group 1 upper-cased is the **outward code**. Compare it by **equality** with the districts (`NR1` must not match `NR17`). The probe matched `NR18 0WP`, `NR17 2AS`, `nr172hx`, `NR1 3JU`, `IP24 2DS`, `SW1A 1AA`, `EC1A 1BB`, `W1A 0AX`, `M1 1AE`, `B33 8TH`, `CR2 6XH`, `DN55 1PT`, and rejected `Room NR17 Hall`, `01953 604721`, `Holkham, Norfolk, …`. (The first draft used ` ?` and missed `NR17  2AS`; `\s*` fixes it.) Districts are stored upper-case without spaces [VERIFIED: `database/seeders/RulesSeeder.php:43` `public const POSTCODE_DISTRICTS = ['NR17', 'NR16', 'NR18', 'IP24'];`]. Place names [VERIFIED: `RulesSeeder.php:46-56`] are `'Attleborough'`, `'Besthorpe'`, `'Great Ellingham'`, `'Rockland St Peter'`, `'Old Buckenham'`, `'Morley'`, `'Deopham'`, `'Snetterton'`, `'Wymondham'`.
- Scan the postcode in `facts->postcode` + `venueName` + `venueAddress` only (never title/description). Several postcodes: keep if **any** is in the list, else out_of_area.
- **Step 2:** `VenueMatcher::exact($venueName, $postcode)` (name → alias → postcode) [VERIFIED: `app/Review/VenueMatcher.php`]. Risk: generic names ("St Mary's Church", "Village Hall", "Market Place") on a regional source would exact-match a local venue of that name. Mitigation (recommended): step 2 counts only when the item has no postcode (step 1 already decided otherwise). Admin review remains the backstop.
- **Step 3:** reuse `CategoryAssigner::titleContainsKeyword($haystack, $placeName)`, the whole-word, NFC, case-insensitive rule used for title rules, over venue name, address and title.
- **Online keywords** (D-16, discretion): the whole trimmed location text, case-insensitive, equals one of `online`, `zoom`, `teams`, `microsoft teams`, `virtual`, `online event`, `livestream`, `live stream`.
- **Location-unknown flag:** no schema change needed. A discovered draft from a `filter` source with blank venue name and address already scores `Missing venue` (−50) and has the `no_venue` blocker. Add a `QualityScorer` reason "Location unknown: check it's in the Attleborough area" (weight 0 or small), computed from `draft->source->location_mode`. Mark the preview row the same way.
- **Preview JSON** gains `skipped.online`, `skipped.out_of_area`, row `reason` values `online` / `out_of_area`, a row `flags: ['location_unknown']`, and for selector sources `rows[].raw = {title, date, time}` (each ≤ 200 chars, trimmed). Update `EditSource::previewViewData()` labels and the `RunsRelationManager` columns.
- Preview row `starts` for an undated item: `null` (the view must show "No date found").

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| CSS selector engine | XPath translation or regex over HTML | `\Dom\HTMLDocument::querySelectorAll` | Spec-compliant HTML5 tree building (foster-parenting seen on the Heritage page); selector validation from the same engine |
| HTML → text, entity decoding | ad-hoc `str_replace` | `textContent` + `html_entity_decode($s, ENT_QUOTES \| ENT_HTML5, 'UTF-8')` once | Entities seen: `&#038;`, `&#8217;`, `&amp;`, `&#036;` |
| Date parsing | regex date grammars, `strtotime` | `StrictDate` over `createFromFormat` | Leap years, overflow and weekday handled by PHP; strictness from `getLastErrors` + round trip |
| Multi-page fetching | a second HTTP client path | `HttpFetcher::fetch` per page inside a reader | Keeps robots / throttle / SSRF pin / caps (SRC-05) |
| Recurrence | anything | none needed: all new rungs yield one-off items (TEC expands occurrences) | sabre is the only engine (project rule) |
| Venue matching | new fuzzy matcher | `VenueMatcher::exact` / `suggest` | Existing D-05/D-22 semantics |
| Duplicate detection / change detection / missing items | per-rung variants | existing `DuplicateDetector`, `ChangeDetector`, `MissingItemDetector` | Rung-independent once facts are normalised |
| XML safety | custom entity stripping | libxml defaults (no `LIBXML_NOENT`/`DTDLOAD`/`PARSEHUGE`) + 5 MiB cap | XXE / billion laughs handled by libxml limits |

**Key insight:** every hard part (fetch politeness, change/duplicate/missing logic, baselines) is already rung-independent. Phase 4 is almost entirely **pure extractors + a reader seam**. Anything that bypasses `NormalisedFacts` → `SourceItemRecorder` re-opens bugs Phase 3 already fixed.

## Runtime State Inventory

> Included because D-04 changes how an existing source is read and identified.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | The owner's dev DB may hold `source_items` for the Town Council ICS source with `external_id` = ICS provisional ids (e.g. `10006470`), possibly linked to published events via `event_sources`. REST would key 10/72 single events on real post ids (`23254`). | Do **not** re-key in place (correction 1). Either `migrate:fresh --seed` (dev only) or pause the ICS source and add TEC REST as a new source (new slug, e.g. `attleborough-town-council-rest`); URL duplicates get flagged across sources. No data migration task. |
| Live service config | None. No external service holds our configuration. | None (verified: ingestion config lives only in `sources` rows and `config/ingest.php`). |
| OS-registered state | Local scheduler/worker processes only (`schedule:work`, `queue:listen`), started by hand. | Owner restarts `queue:listen` after deploying new code (long-running workers keep old classes). |
| Secrets / env vars | None renamed. `CRAWLER_CONTACT_URL` unchanged. | None. |
| Build artifacts | None (no new packages; no compiled assets change unless a Filament view changes → `npm run build` per AGENTS.md). | Run `npm run build` if the preview Blade view gains Tailwind classes. |

## Common Pitfalls

### Pitfall 1: The weekday silently moves the date
**What goes wrong:** `createFromFormat('!l, j F Y', 'Monday, 21 November 2026')` returns **2026-11-23** with `getLastErrors() === false` [VERIFIED: probe].
**How to avoid:** round-trip check (`format($fmt)` must equal the input, case-insensitively). Test with a wrong-weekday fixture row.

### Pitfall 2: Overflow is only a warning
**What goes wrong:** `2026-02-30` → `2026-03-02` and `25:10` → `01:10`, with `warning_count 1`, `error_count 0` [VERIFIED].
**How to avoid:** require `DateTimeImmutable::getLastErrors() === false` (PHP 8.2+ returns `false` when clean), not `error_count === 0`.

### Pitfall 3: `F` accepts `Nov` and lowercase
**What goes wrong:** `j F Y` parses `21 Nov 2026` and `21 november 2026` [VERIFIED].
**How to avoid:** the round trip rejects `Nov` (formats back to `November`) while case-insensitive comparison still accepts lowercase. Document this in the help text.

### Pitfall 4: DST edges for floating times
**What goes wrong:** `2026-10-25 01:30` London → `+00:00` (the **second** 01:30, GMT) with no warning; `2027-03-28 01:30` → `02:30 BST`, silently [VERIFIED].
**How to avoid:** the round trip catches the spring gap (wall clock changed) → parse error. For the repeated autumn hour, keep the facts and add a "check the time: the clocks go back during this hour" parse error, mirroring Phase 3's "check-the-times" note. Test both DST dates.

### Pitfall 5: `!` matters
Without `!`, missing fields default to **now** (e.g. a date-only format gets today's time). Always prefix `!`. The year-less probe `!l j F` produced 1970: never use year-less results directly; go through `YearInference`.

### Pitfall 6: Duplicate / dateless JSON-LD nodes
Visit Norfolk: two Event nodes, one without `startDate` [VERIFIED]. Merge by `@id`/`url` before validating.

### Pitfall 7: Undated drafts (SC3) and missing start times crash or vanish today
`facts === null` → `skipped_parse_error`, no draft [VERIFIED: `SourceItemRecorder::classify`]. A timed item with `startTime === null` makes `EventTiming::forDate` throw [VERIFIED: `app/Calendar/EventTiming.php:53-55` `if ($event->start_time === null) {` / `throw new InvalidArgumentException("Event [{$event->id}] has no start time.");`]. `WindowClassifier` catches this only on the RRULE path, so the item is caught by IngestRun's per-item `Throwable` handler, logged as an error and counted as a parse error. **Fix:** make `NormalisedFacts::$startDate` `?string`; `WindowClassifier` returns IN_WINDOW for a null date and classifies a timed-but-timeless item by date (as if all-day); `DuplicateDetector` already guards non-`Y-m-d` dates; `IngestRun::previewRow`/`laterDate` handle null. Quality then lands in Low naturally [VERIFIED: `config/review.php:19-20` `'missing_field' => 50,` `'parse_error' => 40,`; bands `'high' => 80,` `'medium' => 50,`]: 100 − 50 − 40 = 10. A parse error alone would be Medium (60), so never fill a guessed date.

### Pitfall 8: Bot-wall markers vs real pages
Current markers [VERIFIED: `app/Ingestion/Http/BodyValidator.php:19-24`] are `'just a moment...'`, `'challenge-platform'`, `'cf-chl-'`, `'attention required!'`. For HTML rungs add the LiteSpeed wall the Town Council serves (`lsrecaptcha`, `<title>Bot Verification`, as already used by `ops/probe-sources.sh`). Do **not** add a bare `captcha`/`recaptcha` (false positive on Visit Wymondham). Consider matching `challenge-platform` only together with a challenge `<title>`, because Cloudflare injects `/cdn-cgi/challenge-platform/…` scripts into some normal pages [ASSUMED]. For TEC, any non-JSON body is invalid ("HTML page instead of JSON").

### Pitfall 9: `rest_no_route` never reaches the validator
`classify()` returns `FetchResult::failed(FetchFailureKind::HttpStatus, 'HTTP '.$status, …)` for `>= 400` without reading the body [VERIFIED: `HttpFetcher::classify`]. Add a method-aware branch: for TEC and `404`, read the capped body; if it decodes to `{"code":"rest_no_route"}` → `ContentInvalid` "This site does not run The Events Calendar's REST API". Messages stay our own wording (Phase 3 rule: no response text in `error_message`).

### Pitfall 10: Crawl-delay vs queue abandonment
robots.txt goes through `waitForSlot()` [VERIFIED: `HttpFetcher::fetchRobotsTxt` calls `$this->waitForSlot($url, null, $startedAt);`]; queued runs are abandoned after [VERIFIED: `app/Console/Commands/IngestDispatchDue.php:36`] `public const int QUEUED_TIMEOUT_MINUTES = 120;`; robots cache [VERIFIED: `RobotsPolicy.php:28`] `public const int CACHE_SECONDS = 86400;`. With `Crawl-delay: 12000` a run never completes (robots → 3 h 20 m wait → `HostBusy` release → abandoned). This affects Attleborough Primary (and Rosecroft). Out of scope to fix silently: owner item O-3.

### Pitfall 11: The arch test that cannot fail
`arch()->expect(['strtotime', …])->not->toBeUsedIn('App\Ingestion')` passed with a planted `strtotime` [VERIFIED: probe]. Use `expect('App\Ingestion')->not->toUse(['strtotime', 'date_parse', 'date_create', 'date_create_immutable', 'date_parse_from_format'])` (fails correctly), and plant-test the rule once while writing it. Pest cannot single out `Carbon::parse` (it would have to ban the whole class), so add a token-scan test.

### Pitfall 12: Existing code already violates the ban
[VERIFIED: grep + read] `app/Ingestion/Data/NormalisedFacts.php:204` (`CarbonImmutable::parse($next, 'UTC')`), `app/Ingestion/Pipeline/DuplicateDetector.php:244,252,268`, `app/Ingestion/Http/RobotsPolicy.php:106`, `app/Ingestion/Http/HostThrottle.php:62,65`. These parse our own DB/payload strings. Refactor them to explicit formats: payload `Y-m-d\TH:i:s\Z` (`NormalisedFacts::toPayload` writes `format('Y-m-d\TH:i:s\Z')`); PostgreSQL `timestamptz` text is `Y-m-d H:i:sO` **or** `Y-m-d H:i:s.uO` when microseconds are stored [VERIFIED: probe; `+00` parses with `O`], so try both strictly. `MissingItemDetector`/`IcsExtractor` already use `createFromFormat('!Y-m-d', …)`. `IcsExtractor:455` uses sabre `DateTimeParser::parse` (strict ICS grammar: allow it).

### Pitfall 13: `year_policy` column too short
`varchar(16)` vs `'infer_with_weekday'` (18 chars) → SQL error on save. Widen it in the same migration that adds the CHECK.

### Pitfall 14: The fetch deadline is per request, counted from `startedAt`
Passing the run's `started_at` to every page makes page 2+ time out after 55 s of run time. Use a fresh `startedAt` per page and let the reader enforce `run_deadline`.

### Pitfall 15: RES-04 evidence timing and network
"Create drafts" drafts the whole window, so the next scheduled run (tomorrow 05:30 + jitter London, `RunLauncher::nextCheckAt`) drafts only items new since then. The Baptist feed gains roughly 0–2 a day, so some scheduled runs create 0 drafts. The Town Council is reachable only from a residential network (LiteSpeed reCAPTCHA on the corporate network, `docs/research/event-sources.md`), and the laptop must be awake with `schedule:work` running at the check time. Evidence query: `select source_id, status, drafts_created from source_runs where trigger='schedule' and mode='live' and status='succeeded'`. Accept "a scheduled succeeded run per P0 source, and at least one scheduled run with `drafts_created > 0` per P0 source". The owner may set `check_time` to a daytime hour.

### Pitfall 16: Same-source duplicate detection is off
`DuplicateDetector` excludes same-source candidates (Phase 3 03-11). A re-keyed item inside one source is **not** flagged, which is another reason not to switch the Town Council in place.

## Code Examples

### Strict date helper (prototyped this session; behaviour verified)
```php
// Source: PHP manual createFromFormat / getLastErrors; probed in this session
final class StrictDate
{
    public static function parse(string $format, string $text, DateTimeZone $tz): ?DateTimeImmutable
    {
        $norm = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
        $date = DateTimeImmutable::createFromFormat('!'.$format, $norm, $tz);

        if ($date === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;                       // errors OR warnings (overflow, 25:10)
        }

        // Round trip: rejects weekday/date mismatch, "Nov" for F, spring-forward gaps.
        return mb_strtolower($date->format($format)) === mb_strtolower($norm) ? $date : null;
    }
}
```

### Year inference (EXT-06)
```php
// $format has l or D and no Y/y; $fetch is the London fetch time (CarbonImmutable).
$hits = [];
foreach ([$fetch->year - 1, $fetch->year, $fetch->year + 1] as $year) {
    $d = StrictDate::parse($format.' Y', $text.' '.$year, $fetch->getTimezone());
    if ($d !== null) {
        $day = CarbonImmutable::instance($d)->startOfDay();
        if ($day->betweenIncluded($fetch->startOfDay()->subDays(7), $fetch->startOfDay()->addDays(365))) {
            $hits[] = $day;
        }
    }
}
// count($hits) === 1 → resolved; else parse error "The date has no year and the weekday does not fix one."
```

### Arch test (function ban + token scan)
```php
// tests/Unit/Architecture/DateParsingBanTest.php — Pest 5
arch('ingestion never parses dates loosely (functions)')
    ->expect('App\Ingestion')
    ->not->toUse(['strtotime', 'date_parse', 'date_parse_from_format', 'date_create', 'date_create_immutable']);

it('ingestion never calls Carbon::parse / ::make or new DateTime*(string)', function () {
    $offences = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Ingestion'))) as $file) {
        if ($file->getExtension() !== 'php') continue;
        $tokens = array_values(array_filter(PhpToken::tokenize(file_get_contents($file->getPathname())),
            fn (PhpToken $t) => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
        foreach ($tokens as $i => $t) {
            // X::parse( / X::make( where X is Carbon, CarbonImmutable, Date, DateTime*
            if ($t->is(T_DOUBLE_COLON) && in_array(strtolower($tokens[$i + 1]->text ?? ''), ['parse', 'make', 'createfromtimestring'], true)
                && preg_match('/(^|\\\\)(Carbon|CarbonImmutable|Date|DateTime|DateTimeImmutable)$/', $tokens[$i - 1]->text ?? '')) {
                $offences[] = $file->getFilename().':'.$t->line;
            }
            // new DateTime(...)/new CarbonImmutable(...) with any argument
            if ($t->is(T_NEW) && preg_match('/(^|\\\\)(Carbon|CarbonImmutable|DateTime|DateTimeImmutable)$/', $tokens[$i + 1]->text ?? '')
                && ($tokens[$i + 2]->text ?? '') === '(' && ($tokens[$i + 3]->text ?? '') !== ')') {
                $offences[] = $file->getFilename().':'.$t->line;
            }
        }
    }
    expect($offences)->toBe([]);
});
```
(`T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED` tokens carry `\Carbon\CarbonImmutable`, hence the regex on the text. Plant-test once while writing it, then remove the plant.)

### Native selectors on the real Heritage markup (probed)
```php
$doc = \Dom\HTMLDocument::createFromString($body, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
foreach ($doc->querySelectorAll($cfg->item) as $row) {          // '#ctl00_maincontent_GridViewListMobile tr.closebtn'
    $title = trim($row->querySelector($cfg->title)?->textContent ?? '');   // 'Coffee and Chat'
    $date  = trim($row->querySelector($cfg->date)?->textContent ?? '');    // 'Saturday, 21 November 2026'
}
// Save-time validation with the same engine:
try { \Dom\HTMLDocument::createEmpty()->querySelector($selector); } catch (\DOMException) { $fail('…'); }
```

### TEC reader loop (shape)
```php
$url = $this->firstPageUrl($source, $nowLondon);     // …/events?start_date=Y-m-d&end_date=Y-m-d&per_page=50
for ($page = 1; $url !== null; $page++) {
    if ($page > $cap || $this->deadlineNear($run)) { return $this->envelope($pages, complete: false, note: …); }
    try { $result = $this->fetcher->fetch(new FetchRequest(url: $url, method: …TEC, startedAt: CarbonImmutable::now())); }
    catch (HostBusy $e) { if ($page === 1) throw $e; return $this->envelope($pages, false, …); }
    if (! $result->isOk()) { return $page === 1 ? $result : $this->envelope($pages, false, …); }
    $pages[] = ['url' => $result->finalUrl, 'status' => $result->httpStatus, 'body' => $result->body];
    $next = json_decode($result->body, true, 16, JSON_THROW_ON_ERROR)['next_rest_url'] ?? null;
    $url = is_string($next) && $this->sameOrigin($next, $source->url) ? $next : null;   // off-host → stop incomplete
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `DOMDocument::loadHTML` (libxml HTML4) + XPath / symfony/css-selector | `\Dom\HTMLDocument` (HTML5, lexbor) with native `querySelector(All)` | PHP 8.4 (Nov 2024) | No dependency; correct HTML5 tree building |
| `DateTime::getLastErrors()` returning an array with zero counts | returns `false` when there are no warnings or errors | PHP 8.2 | Check `=== false` |
| TEC "Event Status" (cancelled/postponed) | Stored as post meta and shown on event pages and JSON-LD; **not** in REST v1 | — | REST consumers rely on missing-item detection (D-05) [VERIFIED absence in TEC's own REST schema] |

**Deprecated/outdated:** schema.org `UserInteraction` and its `User*` subtypes (superseded by `InteractionCounter`) [VERIFIED: vocabulary `schema:supersededBy`].

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | libxml (2.12.5) defaults block XXE / billion laughs unless `LIBXML_NOENT`/`DTDLOAD`/`PARSEHUGE` are passed | Standard Stack, RSS | A malicious feed could expand entities; mitigated by the 5 MiB cap. Add a test with an entity-bomb body |
| A2 | A date-only JSON-LD `endDate` is the inclusive last day | JSON-LD | Multi-day all-day items end a day early or late; fixture-test both readings and pick one consciously |
| A3 | TEC provisional (≥ 10 000 000) occurrence ids can be regenerated when a series is edited (TEC custom tables) | TEC / Pitfalls | Recurring items look removed + added after a series edit (affects ICS today too). Missing detection needs 2 complete runs, so the admin sees the cancellation drafts |
| A4 | Cloudflare injects `challenge-platform` scripts into some normal pages | Pitfall 8 | Keeping the bare marker could mark a real page `content_invalid`; low risk for current sources |
| A5 | TEC REST `start_date` includes events that started before the window but end inside it | TEC | Ongoing multi-day events may be absent; missing detection only considers future starts, so low impact |
| A6 | `per_page` above 50 may be capped by TEC | TEC | None if we use 50 |
| A7 | Online keyword list and the location-unknown weight | Geofence | Wording / UX only |
| A8 | The geofence "venue exact match only without a postcode" refinement is acceptable within D-14 | Geofence | Slight deviation from D-14 step 2; the owner may prefer the literal D-14 |

## Open Questions (RESOLVED)

1. **RESOLVED: R-01.** **How should the owner's dev DB move the Town Council to REST?** We know in-place re-keying breaks 10/72 items. Unclear: whether the dev DB holds Town Council items worth keeping. Recommendation: plan "seed REST for fresh DBs + document the dev options"; owner chooses (O-1).
2. **RESOLVED: R-06.** **Make Attleborough Primary fetchable?** Options: (a) accept, inactive; (b) don't count the robots.txt request towards Crawl-delay spacing; (c) a longer robots cache for long-delay hosts. Recommendation: (a) for Phase 4 (O-3).
3. **RESOLVED: R-07 exception.** **RSS `date_required` items without dates: skip or draft?** Recommendation: skip and count (feeds mix posts and events). Confirm with the owner (O-5).
4. **RESOLVED: R-05.** **Should Visit Wymondham's off-site event URLs be used for "Read more"?** Recommendation: same-host only; external URL kept in the payload.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP CLI with ext-dom (`\Dom\HTMLDocument`), SimpleXML, intl, mbstring | all extractors | ✓ | 8.4.12 (static-php-cli), libxml 2.12.5 | — |
| PostgreSQL 18 (`attleborough_testing`) | Pest suite | ✓ (suite ran this session) | — | — |
| Composer / Pest / PHPStan | tests, analysis | ✓ | Pest 5.3.2, PHPStan 2.3.1 | — |
| curl, bash (probe script) | fixture capture | ✓ | — | — |
| Network: residential vs corporate | Town Council live runs (RES-04) | unknown at plan time | — | Run from home network; otherwise the run is `content_invalid` (bot wall) |
| `timeout` (GNU coreutils) | — | ✗ on macOS | — | not needed |

**Missing dependencies with no fallback:** none for building and testing. RES-04 live evidence needs the owner's machine on a residential network at check time.

## Validation Architecture

> `workflow.nyquist_validation` is `false` in `.planning/config.json`, but the orchestrator asked for this section, so it is included.

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3.2 on PostgreSQL (`attleborough_testing`), `Http::preventStrayRequests()` global, CI `TZ=America/New_York` |
| Config file | `phpunit.xml` (memory_limit 512M), `tests/Pest.php` (helpers `sourceFixture()`, `freezeLondon()`) |
| Quick run command | `./vendor/bin/pest tests/Feature/Ingestion tests/Unit --parallel` (or a single file) |
| Full suite command | `./vendor/bin/pest --testsuite=Unit,Feature` then `vendor/bin/phpstan analyse --memory-limit=1G`; browser `./vendor/bin/pest --testsuite=Browser` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| EXT-02 | Town Council p1+p2 → 72 items / drafts in window; `next_rest_url` followed; page cap → incomplete (no missing detection); off-host next → stop; `rest_no_route` → content_invalid; LiteSpeed wall → content_invalid; all-day + 28 Mar 2027 derived copy | feature | `pest tests/Feature/Ingestion/TecRestRunTest.php` | ❌ Wave 0 |
| EXT-02 | ICS vs REST facts → equal content hash for the 24 overlapping items; single-event id mismatch documented | unit/feature | `pest tests/Feature/Ingestion/TecIcsEquivalenceTest.php` | ❌ |
| EXT-03 | Wymondham 12 items, offsets across 25 Oct; Norfolk `@graph` merge → 1 item; subtypes; status/attendance derived fixtures; no-JSON-LD page → content_invalid | unit + feature | `pest tests/Unit/Ingestion/JsonLdEventWalkerTest.php tests/Feature/Ingestion/JsonLdRunTest.php` | ❌ |
| PIPE-07b | `EventCancelled` linked → cancellation draft; new → cancelled item, no draft; `EventPostponed` → cancellation draft with "Postponed at the source" | feature | `pest tests/Feature/Ingestion/JsonLdCancellationTest.php` | ❌ |
| EXT-04 | Heritage real (1 item) + derived 12 rows → 12 source items; desktop grid not double-read; selector validation on save | feature | `pest tests/Feature/Ingestion/HtmlSelectorRunTest.php` | ❌ |
| EXT-05 | Baptist RSS 64 items, title-prefix dates, DST 25 Oct; `pubDate` ignored; undated item skipped; link_follow derived feed → JSON-LD items, cap → incomplete | feature | `pest tests/Feature/Ingestion/RssRunTest.php` | ❌ |
| EXT-06 | `StrictDate` (weekday mismatch, overflow, `Nov`, gap/overlap hours), `YearInference` table, `TimeFinder` (`10.30-11.30`, two times → error) | unit | `pest tests/Unit/Ingestion/Dates` | ❌ |
| EXT-06 | Arch ban (functions + token scan) | arch/unit | `pest tests/Unit/Architecture/DateParsingBanTest.php` | ❌ |
| EXT-06 / SC3 | Ambiguous / year-less unresolvable selector date → draft with parse error, Low band, `start_date` null | feature | in `HtmlSelectorRunTest.php` | ❌ |
| EXT-08 | Every extractor fixture-tested offline incl. 25 Oct 2026 and 28 Mar 2027 | feature | covered above | ❌ |
| PIPE-02 | Wymondham geofence expectations (9 keep / 2 flagged / 1 out_of_area); Norfolk → out_of_area; online variants → `skipped_online`; local source skips; tracked items bypass; districts/place names edited on Rules screen take effect | feature | `pest tests/Feature/Ingestion/GeofenceTest.php` | ❌ |
| SRC-02 | Preview for every rung writes nothing; skips by reason incl. online/out_of_area; raw selector rows; Create drafts reuses a multi-page envelope (no second fetch, `Http::assertSentCount`) | feature + browser | `pest tests/Feature/Ingestion/PreviewRunTest.php` (extend) + `tests/Browser/SourcesTest.php` | ✅ extend |
| RES-04 | Scheduled live run row per P0 source with drafts | manual (owner) + feature for `dispatch-due` trigger | `select … from source_runs where trigger='schedule'` | manual |
| FSE | Primary fixture 22 rows; arity/type deviation → content_invalid | feature | `pest tests/Feature/Ingestion/FseSchoolCalendarTest.php` | ❌ |

### Sampling Rate
- **Per task commit:** the touched test files + `tests/Unit/Architecture`.
- **Per wave merge:** `./vendor/bin/pest --testsuite=Unit,Feature` + PHPStan.
- **Phase gate:** full suite green + browser flows (F15 updated, new F21/F22) before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] Derived fixtures (each labelled `derived` in its manifest with its base file): `attleborough-town-council/derived-tec-allday-dst.json`, `attleborough-heritage-group/derived-12-rows.html`, `visit-wymondham/derived-status-variants.html`, `attleborough-baptist-church/derived-link-follow-feed.xml`, `attleborough-primary-school/derived-malformed-array.html`.
- [ ] `SourceFactory` states per method (`tecRest()`, `jsonLd()`, `htmlSelectors()`, `rss()`, `fseSchoolCalendar()`, `filter()`).
- [ ] Fixture guard test keeps passing (`tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php`: passed 18/18 after this session's captures).

## Security Domain

`security_enforcement: true`, ASVS level 1, block on high.

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (no new auth surface) | existing single-admin Filament gate |
| V3 Session Management | no | — |
| V4 Access Control | yes (new form fields / actions on Sources) | existing `SourceResource` admin gate; `SourceForm::prepareForSave` whitelist (`is_active` never fillable) |
| V5 Input Validation | yes | Filament rules: selector compile check (`\Dom\HTMLDocument`), date-format character whitelist, TEC URL path check, `SsrfGuard::checkUrl`; extractors validate remote data strictly (arity/types/formats) |
| V6 Cryptography | no | sha256 hashing only (existing) |
| V10/V12 (files, SSRF) | yes | every request (pages, `next_rest_url`, link_follow targets, redirects) through `HttpFetcher` (SSRF pin, robots, caps); same-origin rule for `next_rest_url`/links |
| V14 Configuration | yes | no new env or secrets |

### Known Threat Patterns for this stack
| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| SSRF via `next_rest_url` / RSS links / redirects | Tampering / Info disclosure | `SsrfGuard` per hop (existing) + same-origin rule + page/link caps |
| XXE / entity expansion in RSS | DoS / Info disclosure | SimpleXML without `LIBXML_NOENT`/`DTDLOAD`/`PARSEHUGE`; 5 MiB cap; `LIBXML_NONET` |
| JSON depth or size bombs | DoS | `json_decode` depth limits, 5 MiB per page, envelope byte cap, `ingest.max_events` |
| ReDoS | DoS | no admin regex; fixed code-defined patterns only (`TimeFinder`, postcode) |
| Stored XSS from feed text | Tampering | escaped Blade only (project rule); entities decoded once, then stored as plain text |
| Personal data (organiser Person names, emails in descriptions) | Info disclosure | organiser kept in payload only (D-17); excerpts strip contacts (PIPE-09); fixtures redacted (guard test) |
| Bot-wall pages read as "0 events" | Repudiation / integrity | per-rung validators + specific wall markers → `content_invalid` (SRC-06) |
| Crawling disallowed or over-fast | Legal / abuse | robots for every URL incl. link-follow, host throttle, Crawl-delay; never bypass walls |

## Recommended Plan Breakdown (tracer-first; coarse granularity)

Each plan **starts with one end-to-end slice** (fixture → run → drafts/preview visible).

**Wave 1**
- **04-01 Extraction seam + TEC REST tracer (EXT-02, SRC-02 part).** Tracer: queued Town Council run on fixtures p1+p2 → drafts. Migration (methods on `sources` + `event_drafts`, `adapter` CHECK, `year_policy` → varchar(32) + CHECK, `skipped_online`), `ExtractionMethod` cases, `Extractor`/`ExtractorRegistry`/`SourceReader`/envelope, `TecRestReader`/`TecRestExtractor`, BodyValidator JSON + LiteSpeed markers, `rest_no_route` branch, `DraftData` method from source, structured raw hash, incomplete-run handling, ICS↔REST hash-equivalence test.
- **04-02 Strict dates + arch ban + undated drafts (EXT-06, SC3 groundwork).** Tracer: unit table for `StrictDate`/`YearInference`/`TimeFinder`; arch test (plant-tested); refactor the 6 existing violations; `NormalisedFacts::$startDate` nullable + `WindowClassifier`/`IngestRun` null handling; framework-free tests. Independent of 04-01 apart from file overlap in `NormalisedFacts` (sequence the two if they run in parallel).

**Wave 2** (depends on 04-01 + 04-02)
- **04-03 HTML selectors (EXT-04, SC1 selector, SC3).** Tracer: Heritage real page → 1 draft; derived 12 rows → 12 items incl. parse-error Low drafts. `SelectorConfig`, raw rows in preview.
- **04-04 JSON-LD (EXT-03, PIPE-07b).** Tracer: Wymondham fixture → 12 items, DST offsets; Norfolk merge; status/attendance derived fixtures; cancellation/postponed drafts.
- **04-05 Geofence + online (PIPE-02).** Tracer: Wymondham as a `filter` source → 9 kept / 2 flagged / 1 out_of_area in preview and live; `UkPostcode`, verdict placement, counters, quality reason, Rules-screen edits honoured. (Can start on synthetic facts in parallel with 04-04; the final assertions need 04-04.)
- **04-06 RSS (EXT-05).** Tracer: Baptist RSS → 64 items, drafts in window; then link_follow reader on the derived feed (depends on 04-04).

**Wave 3**
- **04-07 Sources admin UI (SRC-01 extension, SRC-02).** Tracer: browser flow creating a selector source, Test run, raw rows, Create drafts. Method/adapter/RSS mode/selector section/year policy options, validation, method-change rule (refused once items exist), SourceFieldHelp topics (`SourceFieldHelpTest` checks every field), preview skip labels, runs table column, F15 update + new UAT flows.
- **04-08 FSE school calendar adapter (D-13, lowest priority).** Tracer: Primary fixture → 22 items (all past at 2026-10-18 → `skipped_past`; test with frozen 2025-09-01 for drafts). Strict arity validation.

**Wave 4**
- **04-09 Seeding, docs, roadmap housekeeping (D-18, D-19, RES-04 local).** SourceSeeder entries (per-entry method; Town Council REST for fresh DBs; Heritage working config; Baptist RSS fallback; Visit Wymondham `filter`, missing detection off; Diocese TEC `filter` with "check terms"; Primary adapter); ROADMAP/REQUIREMENTS wording; owner runbook + `checkpoint:human-verify` for scheduled-run evidence (home network, `schedule:work` + `queue:listen`, check_time).

## Owner-Blocking / Owner-Decision Items

- **O-1 (decision, blocks 04-09 wording):** D-04 cannot be done in place (single events re-key). Approve: "method change stays refused once items exist; dev DB → reset or new REST source."
- **O-2 (environment, blocks RES-04 sign-off only):** scheduled-run evidence needs the laptop awake on a **residential** network at the check time with `php artisan schedule:work` and `php artisan queue:listen` running (restart the worker after code changes). Optionally set the P0 sources' `check_time` to a daytime hour.
- **O-3 (decision, non-blocking):** Attleborough Primary (Crawl-delay 12000) can't complete a run (robots request + 3 h 20 m > 120-minute abandon limit). Accept "built, tested, inactive", or approve a throttle change.
- **O-4 (optional action):** after 22:19 BST today, run `bash ops/probe-sources.sh rosecroft-primary-school` (one page request), redact, and decide whether to seed Rosecroft.
- **O-5 (confirm):** RSS items without a date are skipped (not drafted); "Read more" uses only same-host JSON-LD URLs; D-14 step 2 applies only when there is no postcode.
- **O-6 (informational):** no new dependencies are needed. D-08's symfony/css-selector check is replaced by PHP's native engine.
- Diocese of Norwich terms still unconfirmed (seed inactive with that note; not blocking).

## Files Created / Changed by This Research

- **Created:** `.planning/phases/04-extraction-ladder-geofence-live-sources/04-RESEARCH.md`
- **Created (fixtures, redacted, not committed):** `tests/Fixtures/sources/visit-wymondham/2026-10-18-events-page.html`, `tests/Fixtures/sources/visit-wymondham/manifest.json`, `tests/Fixtures/sources/visit-norfolk/2026-10-18-event-page.html`, `tests/Fixtures/sources/visit-norfolk/manifest.json`
- **Changed:** `ops/probe-sources.sh` (3 target lines: visit-wymondham, visit-norfolk, rosecroft-primary-school; an `event-page` type for `/events/{slug}` paths); `docs/research/event-sources.md` (Fixture index rows + Rosecroft pending note)
- **Not mine (pre-existing in the working tree):** `.planning/config.json` (modified), `.planning/state.json`, `.planning/research/.cache/*.json` (untracked)
- Temporary probe files (`app/Ingestion/ZzProbe/*`, `tests/Unit/Architecture/ZzProbeTest.php`, `/tmp/*`) were created and removed; `git status` confirms none remain.

## Sources

### Primary (HIGH confidence)
- Codebase files read this session: `app/Ingestion/**` (IngestRun, HttpFetcher, BodyValidator, FetchRequest, RobotsPolicy, HostThrottle, Data/*, Pipeline/SourceItemRecorder, WindowClassifier, ContentHasher, Ics/*), `app/Enums/*`, `app/Models/Source.php`, `SourceRun.php`, `GeofenceTerm.php`, `app/Review/VenueMatcher.php`, `QualityScorer.php`, `DraftData.php`, `app/Calendar/EventTiming.php`, `app/Filament/Resources/Sources/**`, `app/Console/Commands/IngestDispatchDue.php`, `app/Ingestion/RunLauncher.php`, `app/Jobs/FetchSourceJob.php`, migrations, seeders, `config/ingest.php`, `config/review.php`, `config/queue.php`, `routes/console.php`, `ops/probe-sources.sh`, `tests/Fixtures/sources/**`
- TEC REST schema from TEC itself: `https://demo.theeventscalendar.com/wp-json/tribe/events/v1/doc` (fetched 2026-10-18 17:57Z) and one demo events request (all-day, `venue: []`, cost entities, `x-tec-total`)
- schema.org vocabulary: `https://schema.org/version/latest/schemaorg-current-https.jsonld` (Event subtypes, EventStatusType, EventAttendanceModeEnumeration, VirtualLocation, startDate range Date/DateTime)
- Local PHP 8.4.12 probes: `\Dom\HTMLDocument`, `createFromFormat`/`getLastErrors`, DST edges, year inference, postcode regex, DOM memory; Pest arch probes; PHPStan probe

### Secondary (MEDIUM confidence)
- `docs/research/event-sources.md` (TEC contract check, per-source evidence) cross-checked against the fixtures

### Tertiary (LOW confidence)
- Training knowledge for A1–A6 (Assumptions Log)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH (everything installed and probed; no new packages)
- Architecture: HIGH for seams and hard-wired points (code read); MEDIUM for the envelope design (recommendation, not yet built)
- Parsing rules: HIGH (derived from real fixtures + TEC/schema.org primary sources)
- Pitfalls: HIGH (13 of 16 reproduced by probes or code reading)

**Research date:** 2026-10-18
**Valid until:** 2026-11-17 (sources and fixtures can change; re-probe the TEC and JSON-LD sites before Phase 5)

