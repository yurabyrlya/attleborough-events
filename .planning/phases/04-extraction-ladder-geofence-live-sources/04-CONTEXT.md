# Phase 4: Extraction Ladder, Geofence & Live Sources - Context

**Gathered:** 2026-10-18
**Status:** Ready for planning

> **How these decisions were made:** the owner started `/gsd-discuss-phase 4` but was unavailable for questions (autopilot). Following the workflow's auto rules, the orchestrator chose the recommended option for every gray area, based on the Phase 1-3 decisions, the source evidence in `docs/research/event-sources.md` and the fixtures. Each decision is marked **[agent default]**. The owner may revise any of them before or during planning. None is one-way.

<domain>
## Phase Boundary

Every remaining rung of the deterministic extraction ladder works on top of the Phase 3 ingestion backbone:

- The Events Calendar REST API (platform adapter).
- Schema.org `Event` JSON-LD.
- Per-source CSS-selector listings.
- RSS/Atom with event dates.
- One small platform adapter for the FSE Design school calendar (D-13).

On top of the extractors:

- Regional (`filter`) sources are geofenced to the Attleborough area.
- The Phase 3 test-run preview works for every rung.
- The real P0 sources run on schedule and produce pending drafts.

**In scope:**

- **Requirements:** EXT-02, EXT-03, EXT-04, EXT-05, EXT-06, EXT-08, PIPE-02, SRC-02 (completing the Phase 3 ICS preview), RES-04 (local half, D-01), PIPE-07 second half (JSON-LD `eventStatus` cancellations).
- The year policies the date rules need.
- An architecture test that bans loose date parsing in ingestion code.
- SourceSeeder entries for the newly supported sources.

**Out of scope (other phases):**

- Production server, domain, Cloudflare Verified Bots, the Town Council datacenter-IP allowlist (Phase 5).
- Dashboard counts (Phase 5).
- Headless/JS rendering, Facebook/Instagram, LLM assistance (spec §6 "Future").
- Eventbrite: its ToS prohibits scraping, so it is never configured.

</domain>

<decisions>
## Implementation Decisions

### Live sources and the "in production" wording
- **D-01 [agent default]: P0 sources go live on the local app in Phase 4; the production half moves to Phase 5.**
  - Production only exists after Phase 5 (scope change 2026-10-16), so Phase 4 closes when the P0 sources are active on the local app and produce pending drafts from scheduled runs.
  - Scheduled runs mean the scheduler (`schedule:work` or cron) plus a queue worker; the owner already runs `queue:listen`.
  - ROADMAP success criterion 5 and RES-04 are reworded to "configured and producing drafts on schedule (local); production in Phase 5", and Phase 5 gains the production half, the same way PIPE-07 was split between Phases 3 and 4.
  - Evidence for verification: a scheduled (not manual) `source_runs` row per P0 source with drafts created.
- **D-02 [agent default]: the "initial P0 sources" are the Attleborough Baptist Church ICS and the Attleborough Town Council feed (TEC REST after D-04).**
  - P1 sources (Academy ICS, Heritage Group selectors, Primary adapter) are seeded inactive and may be activated by the owner, but they don't gate the phase.

### The Events Calendar REST (EXT-02)
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

### RSS/Atom (EXT-05)
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

### CSS-selector listings (EXT-04)
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

### JSON-LD (EXT-03)
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

### Attleborough Primary (embedded JS array)
- **D-13 [agent default]: a small platform adapter `fse_school_calendar`** (spec rung 2, "platform adapter"), selected through the existing `sources.adapter` column.
  - It reads the `json = [[id, ?, "YYYYMMDDTHHMMSS", "YYYYMMDDTHHMMSS", all_day, "title", ...]]` array from the page's `<script>` with strict validation. Anything unexpected is a `content_invalid` run, not a guess.
  - Times are floating, so they are read in Europe/London.
  - Identity is the array's `id`.
  - Built and tested against the real 18 Oct fixture.
  - Seeded inactive. Its notes say the calendar was stale (autumn 2025 only) and mostly pupil-only at capture, and that Crawl-delay 12000 means at most one fetch per 3 h 20 min (daily schedule).
  - It is the lowest-priority plan in the phase: the planner may put it in the last wave.
  - Rosecroft Primary (same provider) may embed the same array. The research step may check once, politely; if it does, Rosecroft is seeded inactive too.

### Geofence (PIPE-02)
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

### Test-run preview (SRC-02)
- **D-17 [agent default]: the Phase 3 test-run preview (D-01/D-24/D-26) is extended to every rung, unchanged in shape.**
  - It shows the drafts a run would create and the skips by reason: past, beyond the window, title rule, out of area, online-only, parse error, not changed.
  - Nothing is written.
  - For selector sources it also lists the raw matched rows (title/date/time text before parsing), so the admin can debug selectors.
  - The preview runs on the **saved** configuration (save, then test), as in Phase 3.
  - "Create drafts" reuses a preview body under 60 minutes old (Phase 3 D-24), extended to multi-page TEC runs (all pages reused or none).

### Seeding (Phase 3 todo, D-05 rules)
- **D-18 [agent default]: SourceSeeder adds the newly supported sources.** All are inactive and create-only (firstOrCreate on slug), with values that pass the Sources form, legal basis and notes from `docs/research/event-sources.md`:
  - Town Council as TEC REST (fresh databases only, D-04).
  - Heritage Group (selectors, with a working config from the fixture).
  - Attleborough Primary (`fse_school_calendar`).
  - Baptist Church RSS fallback (D-07).
  - Visit Wymondham (JSON-LD, `filter`).
  - Diocese of Norwich (TEC REST, `filter`, Crawl-delay 10). Its notes say: "check the terms before activating" (inventory: terms not yet confirmed).
  - Visit Norfolk is not seeded (D-12).

### Roadmap housekeeping
- **D-19 [agent default, informational]:** the `**Mode:** mvp` tag is removed from Phase 4 in ROADMAP.md. The owner removed it from Phases 1-3 (Phase 3 D-18), and Phase 4 is planned the same way as Phase 3.

### Carried forward (do not re-ask)
- **Phase 3 backbone:** sources / runs / items; the polite `HttpFetcher` (robots on every URL, honest User-Agent, ≥5 s per host plus Crawl-delay, size cap, SSRF guard and address pin, per-rung body validation, bot wall = `content_invalid`, robots disallow = `blocked`); raw and content hashes; accepted baseline; change / cancellation / missing drafts; duplicates; health; attribution; excerpts (≤200 chars, no contacts); title skip/keep rules; per-source category mappings; frozen identity and time zone.
- **Time and parsing rules:** never publish automatically; parsers reject rather than guess; floating times are Europe/London; unknown TZIDs are parse errors; store UTC; sabre/vobject is the only recurrence engine; the import window is 90 days.
- **Writes:** only `App\Publishing\Actions` write events and occurrences (arch test).
- **Sources:** never bypass bot protection. The Cloudflare-walled sources (Breckland, Norfolk CC, Norfolk Museums, TicketSource) stay blocked until a Phase 2 D-25 route works. Facebook and Instagram are never scraped.

### Agent's Discretion
- TEC page cap and RSS `link_follow` cap (about 20 each); how a capped run is reported.
- Exact field names and layout of the selector form section and the preview's raw-rows view.
- The internal shape of extractor classes and the extractor registry/dispatch (IngestRun currently hard-wires `IcsExtractor`).
- Parse-error and skip-reason wording, within the Phase 1 brand voice.
- The exact Event-subtype list and the online-location keywords.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Product scope and requirements
- `.planning/ROADMAP.md`: Phase 4 goal and success criteria 1-5; planning notes (EXT-05 DoD rule, the "in production" scope note).
- `.planning/REQUIREMENTS.md`: EXT-02..06, EXT-08, PIPE-02, PIPE-07, SRC-02, RES-04.
- `docs/product-specification.md`:
  - §6: extraction ladder and "reject, not guess".
  - §8.6: location (radius superseded by PIPE-02, D-15).
  - §8.7: polite crawling.
  - §8.8: content and privacy.

### Research
- `.planning/research/SUMMARY.md`, `.planning/research/ARCHITECTURE.md`, `.planning/research/PITFALLS.md`, `.planning/research/STACK.md`: ladder, date-parsing pitfalls, crawler rules. Research flag for Phase 4: live TEC REST, Eventbrite ToS, real UK date formats.

### Sources and fixtures
- `docs/research/event-sources.md`: per-source evidence.
  - TEC contract check (RES-02): `next_rest_url`, `x-tec-total`, `rest_no_route`, no-store headers.
  - Heritage Group GridView markup.
  - Primary JS array.
  - Visit Wymondham / Visit Norfolk JSON-LD notes.
  - Fixture index.
- `docs/research/source-inventory.md`: owner-facing inventory (feed/API, crawlable, manual, blocked) and owner actions.
- `tests/Fixtures/sources/*/manifest.json` and captures:
  - Town Council `2026-10-17-tec-p1.json` / `-p2.json` / `-ical.ics`.
  - Baptist `2026-10-17-feed.xml`.
  - Heritage `2026-10-17-events-page.html`.
  - Primary `2026-10-18-events-page.html`.
- `ops/probe-sources.sh`: the polite probe used for new captures (D-11).

### Prior phase decisions
- `.planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-CONTEXT.md`: D-01 (test run), D-04 (title rules), D-05 (seeding rules), D-15..D-17 (excerpts, Read more, no organiser contacts), D-20 (90-day window), D-24 (body reuse), D-26 (SRC-02 partly delivered).
- `.planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-REVIEW-DISPOSITION.md`: WR-01 (no validators after aborts), WR-03 (frozen identity/time zone), deferred WR-04 (Retry-After).
- `.planning/phases/02-draft-model-review-queue-submissions/02-CONTEXT.md`: D-16 (crawlable classification), D-23..D-26 (source decisions), geofence storage on the Rules screen.
- `.planning/phases/01-foundation-published-calendar/01-CONTEXT.md`: time rules, brand voice.
- `AGENTS.md` and `.ai/guidelines/attleborough.md`: project rules (time, recurrence, writes, Filament 5, tests, no third-party services).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Ingestion/IngestRun.php`: the run pipeline. It currently constructs with `IcsExtractor` directly; Phase 4 adds dispatch by `extraction_method` / `adapter`.
- `app/Ingestion/Ics/IcsExtractor.php`, `IcsItemNormaliser.php`, `IcsTimezoneResolver.php`: the extractor contract (`extract(body, source, nowLondon)` → `ExtractionResult` of `ExtractedItem`s) for the new extractors to follow.
- `app/Ingestion/Data/*` (`ExtractedItem`, `ExtractionResult`, `NormalisedFacts`): the shared item shape.
- `app/Ingestion/Http/HttpFetcher.php`, `BodyValidator.php`, `RobotsPolicy.php`, `HostThrottle.php`, `SsrfGuard.php`: reuse for multi-page TEC and link-follow fetches. `BodyValidator` needs per-rung rules for JSON / HTML / RSS.
- `app/Ingestion/Pipeline/TitleRules.php`, `SourceItemRecorder.php`, `ChangeDetector.php`, `MissingItemDetector.php`, `DuplicateDetector.php`, `ExcerptBuilder.php`: rung-independent pipeline steps. The geofence slots in next to the title rules (skip and count).
- `app/Enums/IdentityStrategy.php`: `uid` / `tec_post_id` / `summary_start`.
- `app/Enums/ExtractionMethod.php`: today Submission / Manual / Ics. The `sources_extraction_method_check` and `event_drafts_extraction_method_check` constraints need the new values.
- `app/Models/GeofenceTerm.php`, `database/seeders/RulesSeeder.php`: geofence terms (NR17, NR16, NR18, IP24 plus 9 place names), edited on the Rules screen (`app/Filament/Pages/Rules.php`, `app/Review/SaveRules.php`).
- `app/Review/VenueMatcher.php`: exact and trigram venue matching (geofence step 2, TEC venues).
- `app/Filament/Resources/Sources/Schemas/SourceForm.php` (`YEAR_POLICIES`, `TIMEZONE_LOCKED`), `SourceFieldHelp.php` (the "?" help pattern), `Pages/EditSource.php` and `Actions/SourceActions.php` (test run / Create drafts preview).
- `database/seeders/SourceSeeder.php`: create-only, inactive seeding pattern.

### Established Patterns
- Offline tests: `Http::preventStrayRequests`, `Http::fake` with fixture bodies, frozen London time, DST fixtures (25 Oct 2026, 28 Mar 2027), raw-SQL UTC assertions. Pest 5 on PostgreSQL.
- Architecture tests in `tests/Unit/Architecture/` (e.g. `WriteChokePointTest.php`): the place for the date-parsing ban.
- Filament 5 conventions; browser UAT flows in `tests/Browser` with `Uat::settleFields`, `Uat::clickUntil`, `Uat::dismissNotifications`.

### Integration Points
- `sources.extraction_method` / `adapter` / `parser_config` / `year_policy` / `location_mode` columns already exist (Phase 3). Phase 4 widens their check constraints and form options.
- The run log and preview skip counters gain `out_of_area`, `online` and the location-unknown flag.
- Review queue drafts gain the "Location unknown" flag (`DraftPublishability` blockers/flags).

</code_context>

<specifics>
## Specific Ideas

- The Heritage Group page has two GridViews (desktop `#ctl00_maincontent_GridViewList` and mobile `#ctl00_maincontent_GridViewListMobile`). The item selector must target only one, or every event is read twice.
- ROADMAP SC1 asks for "a selector listing of 12 events giving 12 source items", but the real Heritage capture lists 1 event. Build a derived 12-row fixture from the real markup (labelled derived in the manifest) and keep the real 1-event capture.
- Town Council REST titles carry HTML entities (`&#038;`). TEC Pro expands recurring series into per-occurrence posts with per-occurrence URLs (`/event/{slug}/{YYYY-MM-DD}`).
- Visit Wymondham `url` values can be `/events/__trashed-3/`; fall back to the listing URL.
- Wymondham is both an allowlisted place name and in NR18, so most Visit Wymondham events pass the geofence by design.
- The Primary calendar was stale at capture; don't treat zero upcoming items there as a structure change worth alarming about. The Phase 3 zero-items flag still applies.

</specifics>

<deferred>
## Deferred Ideas

- Radius geofence from JSON-LD `geo` coordinates (offline distance from Attleborough centre). PIPE-02 doesn't need it; revisit if postcode/place-name matching proves too coarse.
- Visit Norfolk as a live source through listing → detail link-follow (D-12). Prefer a Visit East of England partner feed.
- Test-running unsaved selector configuration straight from the form (D-17 keeps save-then-test).
- Honouring `Retry-After` (Phase 3 WR-04) and feed personal-data retention (Phase 3 WR-06): already scheduled before launch (STATE).

</deferred>

---

*Phase: 04-extraction-ladder-geofence-live-sources*
*Context gathered: 2026-10-18*
