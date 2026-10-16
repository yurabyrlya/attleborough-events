# Pitfalls Research

**Domain:** Deterministic local-events aggregator (Laravel 13 / Filament 5 / PostgreSQL), single admin, tiny VPS, UK (Attleborough, Norfolk)
**Researched:** 2026-10-16
**Confidence:** MEDIUM overall. The research seam rates `webfetch` as LOW, but most claims marked **[VERIFIED]** were checked directly against primary sources on 2026-10-16: RFC 5545/9309 text, `sabre/vobject` and The Events Calendar source on GitHub, Laravel 13 docs, Google Search Central, ICO, legislation.gov.uk, live Eventbrite/Squarespace pages, and local PHP 8.4.12 runs. Claims marked **[UNVERIFIED]** come from training knowledge and need checking during the relevant phase.

> **Phase names used below.** No roadmap exists yet, so pitfalls map to these *areas*. The roadmapper should map them to numbered phases:
> `SRC-RESEARCH` (source research + fixtures) · `FOUNDATION` (skeleton, schema, VPS, deploy, ops) · `FETCH` (HttpFetcher, robots, Source Registry, run logs, health) · `ICS` (ICS parser, recurrence, occurrences) · `FEEDS-ADAPTERS` (RSS/Atom, TEC, Squarespace) · `JSONLD-SELECTORS` (JSON-LD parser, CSS selector parser, date parsing) · `PIPELINE` (normaliser, geofence, quality, change detection, dedup, drafts) · `REVIEW` (Filament queue, manual entry) · `PUBLIC` (calendar, detail, SEO, a11y) · `SUBMIT-LEGAL` (form, spam, privacy, analytics, takedown) · `LAUNCH` (hardening, monitoring, backups drill)

---

## Highest-impact findings (read first)

1. **The planned Squarespace adapter breaks the project's own robots.txt rule.** Squarespace's default robots.txt (checked live on attleboroughtownfc.co.uk) has `User-agent: *` → `Disallow:/*?format=json` and `Disallow:/*?format=ical`. Both endpoints named in spec §14 are disallowed. [VERIFIED] Also, the one known Squarespace source (Attleborough Town FC) has no events collection in its navigation at all. [VERIFIED, homepage nav only]
2. **The Events Calendar REST defaults will trigger mass false "missing item" cancellations.** The default `start_date` is `yesterday 23:59`, so every event that has just finished drops out of the feed. Spec §8.4 says "missing for 2 runs → cancellation draft", so this would create a cancellation draft for *every past event*. [VERIFIED in TEC source]
3. **Silent timezone fallbacks exist in three layers.** `sabre/vobject` falls back to the PHP default timezone when it can't resolve a TZID, unless `failIfUncertain` is set. `VCalendar::expand()` reads floating times as UTC by default. Laravel's `app.timezone` defaults to UTC. Any of these shifts an event by 1 hour during BST. [VERIFIED in source]
4. **PHP's `strtotime`/`Carbon::parse` silently produce wrong dates for UK listing text.** `"Sat 15th Nov 2026"` returns **21 Nov** because the weekday overrides the date. `"11/12/2026"` returns **12 Nov** (US order). `"Sat 14th Nov"` silently gets the current year. `"14/11/2026"` and `"Sat 14th Nov 7.30pm"` return `false`. [VERIFIED, PHP 8.4.12 local run]
5. **Eventbrite listing pages only expose date-only `startDate`.** Full times come from the detail pages. A search for "Attleborough" returns Norwich events and online events. [VERIFIED live]

---

## Critical Pitfalls

### Pitfall 1: Silent timezone fallback (ICS TZIDs, floating times, server default)

**What goes wrong:**
Events show 1 hour early or late during BST (late March to late October), and nobody notices until a resident turns up at the wrong time. The specific failure paths:
- `Sabre\VObject\TimeZoneUtil::getTimeZone($tzid, $vcal, $failIfUncertain = false)`: if no finder or guesser matches, it **returns `date_default_timezone_get()`**, which is UTC in a default Laravel app. [VERIFIED: `lib/TimeZoneUtil.php`]
- `VCalendar::expand($start, $end, $tz = null)`: **`$tz` defaults to UTC**, so floating DATE-TIMEs (no TZID, no `Z`) are read as UTC. [VERIFIED: `lib/Component/VCalendar.php`]
- Microsoft/Outlook-exported feeds use Windows names such as `TZID:GMT Standard Time`. sabre maps this to `Europe/London`. But `Greenwich Standard Time` maps to `Atlantic/Reykjavik`, which has no DST, so a mislabelled feed silently loses BST. [VERIFIED: `lib/timezonedata/windowszones.php`]
- Custom TZIDs (`TZID:"(UTC+00:00) Dublin, Edinburgh, Lisbon, London"`, `/mozilla.org/.../Europe/London`) depend on guessers. [UNVERIFIED per exact string; test with fixtures]
- Google Calendar feeds carry `X-WR-TIMEZONE` at calendar level, which is a non-standard hint. [UNVERIFIED for current behaviour]
- JSON-LD offsets vary even within one site: Eventbrite emitted `2026-10-17T09:00:00+01:00` on one page and `2026-11-28T18:00:00Z` on another. [VERIFIED live]

**Why it happens:**
Fixtures are usually captured in one season, so tests pass in October and fail in April. Library defaults favour "never throw" over "be right".

**How to avoid:**
- Keep `config('app.timezone') = 'UTC'` for storage (spec §9). **Never rely on the PHP default timezone to interpret source data.** Always pass explicit zones.
- Wrap sabre in an `IcsTimezoneResolver` that calls `TimeZoneUtil::getTimeZone($tzid, $vcal, true)` and turns the exception into a **parse error on the item**. Do not guess.
- Floating times: interpret them in `Europe/London` **only if** source `parser_config.floating_tz` is set (default `Europe/London` for `local` sources), and add a quality reason "floating time assumed Europe/London".
- Never call `expand()` without the third argument. Better still, don't use `expand()` for storage at all (see Pitfall 3).
- JSON-LD/TEC: if a value has an offset, honour it. If it has no offset, apply the source's configured zone and record a quality reason. For TEC, prefer `utc_start_date` + `timezone` over `start_date`, which is site-local. [VERIFIED field names in TEC `Post_Repository.php`]
- Keep a **DST fixture pack**: events on and across **29 Mar 2026, 25 Oct 2026, 28 Mar 2027, 31 Oct 2027**, a weekly RRULE spanning a transition (it must stay at 19:00 local, not 19:00 UTC), a floating-time event, a `GMT Standard Time` event, and an unknown TZID that must produce an error.

**Warning signs:** Events at :00 in the source appear at :00 ± 1h. Recurring events shift by an hour after the clocks change. Draft times differ from the source page during summer only.

**Phase to address:** `ICS` (resolver + fixtures), `FEEDS-ADAPTERS` (TEC UTC fields), `JSONLD-SELECTORS` (offset handling). Add a CI test that runs the whole suite with `TZ=America/New_York` and `date.timezone=Pacific/Auckland` to prove nothing depends on the process timezone.

---

### Pitfall 2: All-day and end-time off-by-one errors

**What goes wrong:**
- ICS all-day `DTSTART;VALUE=DATE:20261114` + `DTEND;VALUE=DATE:20261115` is **one day**, because DTEND is non-inclusive. A naive mapping shows a 2-day event, which then appears in the "Sunday" window and stays unexpired a day too long. [VERIFIED RFC 5545 §3.6.1]
- A DATE DTSTART with no DTEND/DURATION lasts one day. A DATE-TIME DTSTART with no DTEND has **zero duration** (it ends at its start). [VERIFIED RFC 5545 §3.6.1] Spec §8.1's "start + 3h" fallback must apply to that case, not to all-day events.
- TEC all-day events store end as `23:59:59` local. [UNVERIFIED exact value; check on a live install]
- JSON-LD `startDate: "2026-10-17"` (date-only) does **not** mean all-day. On Eventbrite listing pages it means "time not given here". [VERIFIED live]
- Placeholder times: some sources emit `T00:00:00` for "unknown time". Google explicitly says not to use midnight or `23:59:59` placeholders. [VERIFIED Google Event docs]

**How to avoid:**
- Normalise into a single internal `EventTiming` value object with `start` (UTC), `end` (UTC, nullable), `allDay` (bool), `timeKnown` (bool). Every parser converts into it, and expiry, filters and JSON-LD output read from it.
- ICS DATE-valued DTEND: store `end = DTEND − 1 day` as an *inclusive* local date, or store exclusive and document it. Pick one convention, write it in the model docblock, and test it.
- Date-only without an explicit all-day signal: set `timeKnown=false`, add a quality reason "start time unknown", and score Medium at most.
- Treat `00:00` start as suspicious when the source is not ICS and not explicitly all-day (quality reason, not rejection).

**Warning signs:** Single-day fêtes appear under "This weekend" on both days. Events disappear from "Today" at midnight instead of after they finish. JSON-LD shows `T00:00`.

**Phase to address:** `PIPELINE` (EventTiming + expiry). Fixtures in `ICS`, `FEEDS-ADAPTERS` and `JSONLD-SELECTORS`. The `PUBLIC` filters must use the same value object.

---

### Pitfall 3: Recurrence model mismatch (expansion vs. series identity)

**What goes wrong:**
- `VCalendar::expand()` flattens a series into separate instance VEVENTs, all sharing the UID. If you ingest the *expanded* output, every daily run shifts the 90-day window. That shows up as "new" occurrences and "missing" old ones, flooding the queue with update and cancellation drafts.
- Overrides are separate VEVENTs with the same `UID` + `RECURRENCE-ID`. Keying source items on `(source_id, UID)` alone causes overwrites or unique-constraint violations (spec §9 has `UNIQUE(source_id, external_id)`).
- Google Calendar typically represents a cancelled single instance as an override VEVENT with `STATUS:CANCELLED` or as an `EXDATE`. [UNVERIFIED; capture fixtures]
- `EXDATE` values must match instance start times exactly, including TZID. A mismatch (EXDATE in UTC, DTSTART in local) means the excluded date still shows.
- `RANGE=THISANDFUTURE` exists in the RFC but is poorly supported. [VERIFIED it exists]
- `UNTIL` is **inclusive** and must be UTC when DTSTART has a TZID. `COUNT` **includes** DTSTART. Instances on invalid dates (Feb 30) or in the DST gap **must be skipped and not counted**. [VERIFIED RFC 5545 §3.3.10] `BYSETPOS` ("last Friday of the month": `FREQ=MONTHLY;BYDAY=FR;BYSETPOS=-1`) is exactly the pattern community markets use.
- Unbounded RRULEs plus sabre's `Settings::$maxRecurrences = 3500` throw `MaxInstancesExceededException` on old daily series. `expand()` also throws `InvalidDataException` if a recurring VEVENT has no UID. [VERIFIED]

**How to avoid:**
- **One source item per series** (`external_id = UID`). Its `raw_content` is the master VEVENT plus all overrides for that UID. Overrides live inside the item (`overrides` JSON keyed by RECURRENCE-ID), not as separate items.
- **Content hash = canonical serialisation of master + overrides + EXDATE/RDATE.** Never hash expanded instances, so the hash doesn't change when the window moves.
- Our own `OccurrenceGenerator` builds `event_occurrences` from the *approved* event's rule over the rolling 90-day horizon. A scheduled daily job extends the horizon. It never creates drafts.
- A change to an override or EXDATE produces **one update draft on the series**, with a diff like "occurrence 21 Nov cancelled" or "occurrence 5 Dec moved to 18:00".
- Use sabre's `EventIterator` (or `rlanvin/php-rrule` v3.0.0, a new major released 2026-07-29, so check its changelog) with an explicit window `[now − 1 day, now + 90 days]`, and catch MaxInstances as a parse error.
- Required fixture set: weekly over DST; monthly `BYSETPOS=-1`; `COUNT=10` vs equivalent `UNTIL`; `UNTIL` given as local time (invalid but common); EXDATE with a different TZID form; moved override; cancelled override; `RDATE` extra date; all-day weekly; missing UID; a Feb-30-style monthly `BYMONTHDAY=31`.

**Warning signs:** Every run reports "items changed" for calendars nobody edited. The review queue fills with near-identical recurring drafts. Unique-key exceptions on ICS ingest.

**Phase to address:** `ICS` (item-per-series, hashing, iterator). `PIPELINE` (series-level diff). `PUBLIC` (occurrence horizon job). This has the highest rewrite risk, so **decide the data shape before writing the parser.**

---

### Pitfall 4: Silent wrong dates from free-text parsing (CSS selector rung)

**What goes wrong (PHP 8.4.12 behaviour, `Europe/London`):** [VERIFIED local run]

| Input | `strtotime` result | Problem |
|---|---|---|
| `Sat 15th Nov 2026` | **Sat 21 Nov 2026** | Weekday wins: a typo moves the event by 6 days with no error |
| `11/12/2026` | **12 Nov 2026** | US m/d order; a UK author meant 11 Dec |
| `14/11/2026` | `false` | UK d/m not understood |
| `Sat 14th Nov` | 14 Nov **2026** | Year silently assumed; in December, "Sat 9th Jan" becomes a past date |
| `Sat 14th Nov 7.30pm` | `false` | UK "7.30pm" dot separator not understood |
| `this Saturday` | relative to *parse* time | Re-parsing a stored page later gives a different date |
| `DateTime::createFromFormat('D jS M', ...)` | fills the current year, no error | Same silent year assumption |

Other UK formats to expect: `14th–16th Nov`, `Sat 14 & Sun 15 Nov`, `7.30pm–late`, `noon`, `doors 7pm`, `Every Saturday`, `TBC`, `Nov 14` (US-style CMS themes), and ordinal suffixes inside `<sup>`.

**Why it happens:** `Carbon::parse` is the obvious tool, and it never says "I don't know".

**How to avoid:**
- **Ban `strtotime`/`Carbon::parse`/`new DateTime($freeText)` in parser code.** Enforce with a PHPStan rule or an architecture test (Pest `arch()->expect(...)->not->toUse('strtotime')` on `App\Extraction`).
- Build a `UkDateParser` that accepts an **ordered list of explicit regex patterns per source** (`parser_config.date_patterns`). It uses `createFromFormat('!...')` with the `!` reset and checks `DateTime::getLastErrors()`. Any warning becomes a parse error.
- **Weekday cross-check:** if the text includes a weekday, it must match the computed date, or it's a parse error.
- **Deterministic year resolution, not guessing:** if the year is missing *and* a weekday is present, choose the single year in `[fetch_date − 7 days, fetch_date + 365 days]` where the weekday matches. If zero or two candidates match, it's a parse error. If no weekday is present, use per-source policy `year_policy: reject | next_occurrence`, defaulting to `reject` per spec §6. This keeps the "reject, not guess" principle while not discarding most UK listing pages. Flag this to the owner as a spec clarification.
- **Relative dates** ("this Saturday", "tomorrow") resolve against `source_items.fetched_at`, never `now()`. Store `fetched_at` with the raw content so re-parsing is reproducible.
- Tests use `Carbon::setTestNow()` and cover the December→January rollover.

**Warning signs:** Draft dates falling on a different weekday than the source text says. Events dated in the past in a fresh run. Clusters of events on the 1st or 12th of a month.

**Phase to address:** `JSONLD-SELECTORS` (UkDateParser + arch test). `SRC-RESEARCH` (collect real date strings from every P1 source as fixtures).

---

### Pitfall 5: Robots.txt misinterpretation and endpoints you're not allowed to use

**What goes wrong:**
- **Squarespace:** default robots.txt disallows `?format=json`, `?format=ical`, `?format=page-context`, `?format=main-content`, `?view=`, `?month=`, `?tag=`, `?author=`, `/api/`. It also lists a long group of AI/crawler user-agents. [VERIFIED live] The spec §14 plan ("Squarespace `?format=ical` / `?format=json`") is therefore off-limits.
- **Eventbrite** disallows `/rss/`, `/atom/`, `/events/rss/`, `/events/atom/`. [VERIFIED live] Event pages (`/e/...`) and `/d/` listing pages were not disallowed for `*` in the lines checked.
- Naive robots parsers get RFC 9309 semantics wrong: [VERIFIED RFC 9309]
  - the **longest (most specific) match wins** between Allow and Disallow;
  - `*` and `$` wildcards apply;
  - consecutive `User-agent` lines form **one group**;
  - **4xx robots.txt → you MAY crawl everything; 5xx/unreachable → assume complete disallow**;
  - cache for at most **24 h**;
  - follow up to 5 redirects;
  - parse at least 500 KiB.

**How to avoid:**
- Squarespace adapter = **server-rendered HTML of the events collection page + per-event detail pages** (check whether they emit `Event` JSON-LD, [UNVERIFIED]), discovered via `sitemap.xml`. Or drop the Squarespace adapter and use the JSON-LD/selector rungs. Spec DoD #3 needs only "at least one verified platform adapter", so make TEC that adapter.
- Choose a robots library and **fixture-test it against the saved Squarespace and Eventbrite robots.txt files** (cases: `/events?format=ical` → disallowed; `/events/my-event` → allowed; 5xx → disallowed). `spatie/robots-txt` is at 2.5.4 (2026-02-25), but its wildcard/longest-match behaviour is [UNVERIFIED], so test before trusting it.
- The robots check runs in `HttpFetcher` for **every** URL (including pagination and detail pages), not once per source. A disallowed URL is a run error ("blocked by robots"), visible in Source Registry.
- **Test run** must apply the same robots check. It is not exempt.

**Warning signs:** Adapter code that appends `?format=` to URLs. Robots checked only on the source's root URL.

**Phase to address:** `SRC-RESEARCH` (record robots status per source; re-decide the Squarespace adapter). `FETCH` (RFC 9309-correct checker + fixtures). `FEEDS-ADAPTERS` (adapter design).

---

### Pitfall 6: False "missing item" cancellations

**What goes wrong:** Spec §8.4 creates a cancellation draft when an item is missing for 2 consecutive successful runs. Items go missing for many reasons other than cancellation:
- **TEC REST defaults to `start_date = yesterday 23:59`**, so yesterday's events vanish from every response. [VERIFIED]
- Listing pages show "next 10 events"; RSS shows the last N posts; Google ICS may trim; JSON-LD listings paginate.
- Pagination stopped early (timeout, 429 on page 3). The run is "successful" but partial.
- TEC returns **HTTP 404 (`event-archive-page-not-found`) for a page past the end** instead of an empty list. [VERIFIED] A naive loop marks the run as failed, or another naive loop treats the 404 as the end but stops early.
- Organisers delete past events.

**How to avoid:**
- An item is **eligible for missing-detection only if** its last known start is in the future, **and** it falls inside the source's *observed window* (min/max start dates returned in this run), **and** the run is **complete** (all pages fetched, `items_found ≥ 50%` of the previous run). Store `run.complete` as a boolean.
- TEC: request an explicit `start_date` (e.g. today − 1) and `end_date`, page until `page > X-TEC-TotalPages`, and use `per_page=50` (the server max, filterable). [VERIFIED headers + max]
- Past events are never cancellation candidates. They just expire.
- A sudden drop (more than 50% fewer items than the last run) means "possible structure change". Raise a source health alert, not N cancellation drafts.

**Warning signs:** Cancellation drafts for events whose date has passed. A burst of cancellations from one source in one run.

**Phase to address:** `PIPELINE` (missing-detection rules), `FEEDS-ADAPTERS` (TEC pagination), `FETCH` (completeness flag).

---

### Pitfall 7: Change-detection noise → review fatigue

**What goes wrong:** Hashing raw HTML or JSON means every run looks "changed". Common causes: CSRF tokens, nonces, cache-buster query strings, "3 days to go", "Only 5 tickets left", Eventbrite `availability`/price changes, TEC `modified` timestamps, rotating ads/related-events blocks, `utm_*`/`fbclid` on links, and HTML entity differences (`&amp;` vs `&`). Each change becomes an update draft. A solo admin with a 2 h/week budget gives up within a month.

**How to avoid:**
- Use **two hashes**:
  - `raw_hash` (cheap skip if bytes are identical, alongside ETag/304);
  - `semantic_hash` = SHA-256 of canonical JSON of *normalised extracted fields only* (title, start, end, allDay, venue, address, postcode, price_type, price_text, status, recurrence, source_url canonical), with sorted keys, trimmed and whitespace-collapsed, NFC-normalised, entities decoded. **Only a `semantic_hash` change creates an update draft.**
- **URL canonicalisation**: lowercase host, force https where possible, drop the fragment, strip `utm_*`, `fbclid`, `gclid`, `mc_cid`, `mc_eid`, `ref`, `aff`, Eventbrite `aff=` params, and the trailing slash. Unit-test this.
- **Diff thresholds**: description edits under N characters, or whitespace-only edits, don't trigger a draft. Store them silently on the source item and show them on the next real update.
- Exclude volatile fields (ticket availability, `lowPrice` when only the sale price moves, `modified`).
- Pending update drafts for the same item are **superseded** (spec §8.4). Verify the supersede path with a test: three changes produce one pending draft.

**Warning signs:** `items_changed / items_found` above 10% per run on stable sources. The same event gets an update draft every day.

**Phase to address:** `PIPELINE` (ChangeDetector). `FETCH` (raw hash + conditional requests).

---

### Pitfall 8: Duplicate detection false positives and negatives

**What goes wrong:**
- **False positives:** weekly "Quiz Night @ The Griffin" is the *same title, same venue, different date*. Matching title + venue without strict date equality flags every week. Also morning and evening shows on the same day, "U11s vs X" vs "U12s vs X", and short titles ("Bingo", "Market") where `pg_trgm` similarity is inflated.
- **False negatives:** "Attleborough Christmas Lights Switch-On" vs "Christmas Lights Switch On 2026 – Attleborough Town Council"; venue "Connaught Hall" vs "The Connaught Hall, Station Rd"; one source gives 18:00, another 18:30 (doors vs start); one source lists a recurring market as a series while another lists it as single dates.
- Re-firing: the admin marks "not duplicate", and the warning comes back on every update.

**How to avoid:**
- Match at **occurrence level**: same local date is required. Times must be within ±60 min, or one side has `timeKnown=false`.
- Title similarity: normalise first (lowercase; strip year, venue name, town name, punctuation, "the", emoji). Use `similarity()` threshold ~0.45 with a **minimum normalised length of 6**. Below that, require venue equality too. Calibrate thresholds on a fixture corpus collected in `SRC-RESEARCH`. [Threshold UNVERIFIED; tune it]
- Venue matching via the `venues` table with **aliases** (spec §9), plus postcode equality as a strong signal.
- Persist admin decisions in a `duplicate_decisions(left_id, right_id, decision)` table so "not duplicate" pairs are suppressed.
- Series vs single: match a single-date draft against generated occurrences of published series.
- Never auto-merge (spec). Bulk approve must **exclude drafts with a duplicate flag** unless explicitly included.

**Warning signs:** Duplicate rate above 30% on a recurring-heavy source. Admin "not duplicate" decisions above 50% of warnings.

**Phase to address:** `PIPELINE` (DuplicateDetector + decisions table). `REVIEW` (bulk-action guard). `SRC-RESEARCH` (calibration corpus).

---

### Pitfall 9: Failed fetches that look like success

**What goes wrong:** The fetcher gets HTTP 200, but the body is a block page, a Cloudflare challenge, a cookie wall, a maintenance page, or a caching-plugin copy from last month. The parser finds 0 items, the run is "success", and missing-detection starts counting toward cancellations. *During this research the network's DNS filter returned a 200 `text/html` redirect page instead of a Google Calendar ICS*, which shows this happens in practice. [VERIFIED observation] Cloudflare challenges typically come as 403/503 with `cf-mitigated: challenge` or a `__cf_chl` marker. [UNVERIFIED exact header; capture it]

**How to avoid:**
- **Validate the body by rung before parsing:**
  - ICS must contain `BEGIN:VCALENDAR` after BOM strip;
  - RSS/Atom must parse as XML with `<rss` or `<feed`;
  - JSON must `json_decode` with `JSON_THROW_ON_ERROR` and have the expected shape;
  - HTML selector sources need a per-source **sentinel selector** that must match (e.g. the listing container).
  
  Failing validation counts as a **failed run** (`content_invalid`), not 0 items.
- Detect challenge/bot pages. Mark the source `blocked` and pause it. **Do not try to bypass bot protection.** Record in notes and switch to manual entry or contact the owner.
- Honour `429` + `Retry-After`, plus exponential backoff per host (1h, 6h, 24h). Count it toward `failure_count` but surface it distinctly.
- Size limit (e.g. 5 MB, enforced while streaming), connect/read timeouts, a max redirect count, and reject redirects to non-http(s) or private IPs (SSRF via admin-entered URLs or organiser links if any are fetched).

**Warning signs:** `items_found = 0` with `status = success`. Identical `raw_hash` across sources. HTML `content-type` on an ICS source.

**Phase to address:** `FETCH` (validation, blocked state, backoff, SSRF guard).

---

### Pitfall 10: Queue/scheduler failure on a tiny VPS

**What goes wrong:**
- `retry_after` (database connection, default 90) is shorter than the actual job duration, so jobs **run twice**. Laravel: "`--timeout` should always be at least several seconds shorter than `retry_after`… If your `--timeout` is longer… jobs may be processed twice." [VERIFIED Laravel 13 docs]
- Polite crawling implemented with `sleep(5)` inside the job: a 12-page TEC fetch blocks the single worker for over 60 s, hits `--timeout` (default 60 s), and gets killed mid-run.
- Nobody installed the `* * * * * php artisan schedule:run` cron, or the worker isn't supervised, and **nothing alerts** because the thing that would alert is the scheduler.
- Long-lived workers leak memory (DomCrawler on big pages). Default `--memory` is 128 MB. [VERIFIED]
- Workers keep running old code after deploy without `queue:restart`. Laravel uses the **cache** to signal restarts, so `CACHE_STORE` must be shared and persistent (the `database` store works; `array` doesn't). [VERIFIED docs]
- `failed_jobs` and `jobs` tables bloat. Old `source_runs` and `raw_content` fill the disk on a 20–40 GB VPS.
- Two overlapping `FetchSourceJob`s for the same source race on `source_items` upserts.

**How to avoid:**
- **Per-host politeness without sleep:** use `RateLimited` job middleware or a `release($delay)` pattern keyed by host. Fetch pages as **one job per page** chained, or loop with `release()`.
- `FetchSourceJob implements ShouldBeUnique` (`uniqueId = source_id`) plus `WithoutOverlapping("source:{$id}")`. The database cache store supports atomic locks. [VERIFIED docs list requirement; confirm the database store in L13]
- Supervisor (or systemd) program: `queue:work database --sleep=3 --tries=3 --timeout=120 --max-time=3600 --memory=192`, `stopwaitsecs` above the timeout. Set `retry_after=180` in `config/queue.php`.
- A **heartbeat**: scheduler writes `last_scheduler_tick` every minute, and the worker writes `last_job_processed_at`. An external free uptime monitor (e.g. healthchecks.io-style ping from the scheduler [UNVERIFIED provider choice]) alerts if pings stop. Show the same values on the Filament dashboard.
- Schedule `queue:prune-failed --hours=168`, `model:prune` (source_runs older than 90 d), and `raw_content` retention (keep the latest 2 versions per item plus anything linked to a pending draft).
- 2 GB RAM VPS: add a 1–2 GB swapfile, set Postgres `shared_buffers≈256MB`, 1 worker process, PHP-FPM `pm.max_children` 5–8. [UNVERIFIED sizing; benchmark]

**Warning signs:** Duplicate source runs with overlapping timestamps. `jobs` row count growing. Dashboard "last checked" older than the check frequency. `MaxAttemptsExceededException` in failed jobs.

**Phase to address:** `FOUNDATION` (supervisor, cron, heartbeat, prune schedule, deploy script). `FETCH` (rate-limit middleware, uniqueness). `LAUNCH` (failure drill: kill the worker and confirm the alert fires).

---

### Pitfall 11: Ops basics skipped on a single VPS (backups, disk, logs, deploys)

**What goes wrong:** The only copy of reviewed events lives on one disk. Backups exist but were never restored. `storage/logs/laravel.log` grows unbounded (the default `single` channel). Deploys run `composer install && migrate` in place, so the site returns 500s mid-deploy and workers crash on half-updated code. Postgres major upgrades go wrong.

**How to avoid:**
- Logging channel `daily` with `days=14` (or logrotate). Log level `warning` in production for parser noise. Store per-item parse errors in the DB, not in logs.
- Nightly `pg_dump -Fc`, plus `storage/app` and `.env` encrypted copy, sent **off-box** (Hetzner Storage Box / S3-compatible / Backblaze B2). Retain 7 daily + 4 weekly. `spatie/laravel-backup` is the usual Laravel choice [UNVERIFIED current version]. **Do a restore drill before launch** and document it in a runbook.
- Atomic-symlink deploys (Deployer, Envoyer-style, or panel equivalents such as Ploi/Forge [UNVERIFIED pricing]): build in a new release dir, `migrate --force`, swap the symlink, `php artisan queue:restart`, reload PHP-FPM. Write migrations to be backwards-compatible (expand → migrate → contract).
- Disk alert at 80%. Keep `raw_content` in a separate table (`source_item_payloads`) so Filament and list queries never load it.

**Phase to address:** `FOUNDATION` (logging, deploy script, backup job). `LAUNCH` (restore drill, disk alert).

---

### Pitfall 12: Review queue that a solo admin abandons

**What goes wrong:**
- The **first run** of a Google Calendar ICS can contain every event since the calendar was created (years of history), which becomes hundreds of drafts. [UNVERIFIED for current Google behaviour; common report]
- Regional directories create mostly out-of-area drafts. Low-quality drafts with no venue clog the top of the queue.
- Filament table pages slow down because `select *` loads `raw_content`, `diff` JSON and description, plus N+1 queries for source and category per row. Livewire polling every 2 s on the dashboard. [UNVERIFIED specific Filament 5 defaults]
- Bulk approve on a filtered list silently includes duplicates or parse-error drafts.

**How to avoid:**
- **Never draft past events** (end < now) and never draft beyond the horizon (e.g. > 12 months ahead). Count them in the run log as `skipped_past` / `skipped_far_future`.
- **New-source onboarding mode:** the first run produces a *preview batch* the admin approves as a set. Test run already exists (spec §7.3); make it show counts by outcome.
- Default queue sort: soonest start date first (time-sensitive). Badges for kind and quality band. Keyboard actions. "Approve & next".
- Bulk approve **disabled** for drafts with a parse error, duplicate flag or Low quality. They need individual review.
- Filament resource queries: `->select([...])` explicit columns, `->with(['source','category'])`, raw payload on a separate model and an "open raw" action. No polling on the review table. Paginate at 25.
- Track **median review time** and drafts/week (spec §15) from day one. If it's over 2 h/week, the next phase is noise reduction, not new sources.

**Warning signs:** Pending count growing week over week. Queue page TTFB above 1 s. Admin skipping the queue for manual entry.

**Phase to address:** `REVIEW` (UX + query hygiene). `PIPELINE` (skip rules). `FETCH` (test-run preview counts).

---

### Pitfall 13: Legal exposure from copying listings (database right, copyright, personal data)

**What goes wrong:**
- **Database right (UK, Copyright and Rights in Databases Regulations 1997 reg. 16):** "the repeated and systematic extraction or re-utilisation of insubstantial parts of the contents of a database may amount to the extraction… of a substantial part." [VERIFIED legislation.gov.uk] Pulling every listing from a regional directory daily is exactly that pattern.
- **Copyright in descriptions:** facts (date, venue, price) aren't protected. Prose descriptions are literary works. "Truncate to 500 chars" (spec §8.8) is not a safe harbour, because substantiality is *qualitative* and a 500-character blurb may be most of the original. [UNVERIFIED as legal advice]
- Third-party images are already excluded (good). Eventbrite's terms of use may restrict automated access or reuse beyond robots.txt. [UNVERIFIED; read the current ToS during `SRC-RESEARCH`]
- **Personal data:** organiser names, phone numbers and personal emails in scraped descriptions or submissions. Under the **Data (Use and Access) Act 2025** (all data-protection provisions in force as of the ICO's 19 June 2026 update), controllers must **help people make data-protection complaints (e.g. an electronic complaints form), acknowledge them within 30 days and respond without undue delay.** [VERIFIED ICO]

**How to avoid:**
- Per-source **legal assessment field** (spec has `notes`; make it a structured `legal_basis` enum: `own_feed_published_for_syndication | factual_extract_only | permission_granted | manual_only`). Regional directories default to `factual_extract_only` with links back. Prefer **asking organisers for permission** for P0 sources (an email template), and record the permission.
- Publish discovered descriptions as a **short excerpt (≤ 200 chars) plus "Read more at [source]"**. Keep the full text internal for review only. Organiser submissions and manual entries can be full length.
- Strip phone numbers and emails from *discovered* descriptions by default (regex), and only show organiser contact fields the organiser published for that purpose. Short Legitimate Interests Assessment (LIA) document in the repo.
- Privacy notice + **takedown/correction form + data-protection complaints route** with a 30-day acknowledgement SLA tracked in Filament (a simple `contact_requests` resource).

**Phase to address:** `SRC-RESEARCH` (per-source legal basis, ToS checks). `PIPELINE` (excerpt + PII strip). `SUBMIT-LEGAL` (privacy notice, LIA, complaints/takedown form).

---

### Pitfall 14: SEO mishandling of expired, hidden and duplicate events

**What goes wrong:**
- Expired events return 404, which throws away the inbound links that local shares build up. The opposite failure: thousands of stale event pages stay indexed and outrank current ones.
- Event JSON-LD on listing/calendar pages. Google: "the event experience on Google only supports pages that focus on a single event." [VERIFIED]
- Cancelled events have their `startDate`/`location` removed. Google says keep all properties and set `eventStatus: EventCancelled`. Rescheduled events need `EventRescheduled` + `previousStartDate`. [VERIFIED]
- Missing required properties: **`name`, `startDate`, `location` (Place with `name` + `PostalAddress`)** are required. `image` is only *recommended*, but the no-third-party-images policy means category placeholder images (≥ 720 px wide, crawlable) should be emitted. [VERIFIED] Venues without a full postal address produce invalid markup.
- Marking non-events (opening hours, offers) as `Event` goes against Google's content guidelines. [VERIFIED]
- Setting `rel=canonical` to the *source* page deindexes your own page.

**How to avoid (opinionated):**
- **Past events:** keep the URL **200** with a prominent "This event has ended" banner and links to upcoming events in that category. Remove from `sitemap.xml` at expiry. Add `noindex` 30 days after expiry. Return **410** after 12 months, or immediately for events the admin **hides/rejects for being wrong**. Merged duplicates **301** to the canonical event.
- **Recurring series:** one page per series, emitting separate `Event` objects for upcoming occurrences (cap ~10), following Google's "separate Event element for each performance" guidance. [VERIFIED guidance; effect on recurring community events UNVERIFIED]
- JSON-LD only on detail pages. Validate generated markup in CI with fixtures (assert required keys, an offset on every datetime, `eventStatus` present when cancelled).
- Self-referencing canonical. Attribution link to the source with plain `rel="nofollow"` is optional. Write unique summary text where possible (thin/duplicate content risk). [UNVERIFIED impact]
- Sitemap lists only published, non-expired events plus static pages, with `lastmod` = event updated_at.

**Phase to address:** `PUBLIC` (status codes, JSON-LD builder, sitemap). `REVIEW` (hide vs reject semantics drive 410/301).

---

## Moderate Pitfalls

### M1: RSS/Atom items have no event date (`pubDate` ≠ event date)
**What goes wrong:** News/CMS feeds expose `pubDate` (publication time). Treating it as the event start publishes events on the day they were *announced*. Some feeds add `ev:startdate` (RSS events module) or TEC's own feed puts the date in the title/description. [UNVERIFIED for specific feeds]
**Prevention:** The RSS rung **never** uses `pubDate`/`updated` as event time. It extracts the date from (in order) a namespaced event element, per-source configured regex on title/description through `UkDateParser`, or **follows the item link** to the detail page and applies the JSON-LD rung there (respecting robots + rate limit). No date found means a parse error draft or a skip, per source config `rss_mode: date_required | link_follow`. Use `guid` (fall back to canonical link) as `external_id`. GUIDs sometimes change on edit, so use URL as a secondary key.
**Phase:** `FEEDS-ADAPTERS`.

### M2: JSON-LD parsing quirks
**What goes wrong:**
- Multiple `<script type="application/ld+json">` blocks per page. Eventbrite detail pages have `WebPage`, `Event`-subtype, `BreadcrumbList`, `FAQPage`. [VERIFIED]
- `@graph` wrappers (Yoast/WordPress) and top-level arrays.
- `@type` as an array (`["Event","SocialEvent"]`) or a subtype: Eventbrite used **`EducationEvent`**. [VERIFIED] Others include `MusicEvent`, `SportsEvent`, `Festival`, `ChildrensEvent`, `ComedyEvent`, `FoodEvent`, `TheaterEvent`, `SocialEvent`, `ExhibitionEvent`.
- `ItemList`/`ListItem.item` wrappers on listing pages. [VERIFIED Eventbrite]
- Invalid JSON: trailing commas, raw newlines in strings, HTML entities, `//` comments, CDATA wrappers.
- `eventStatus`/`eventAttendanceMode` as full URL, `http://` URL, or bare `EventCancelled`.
- `location` as a string, a `VirtualLocation` (online), or an array of Place + VirtualLocation (mixed).
- Address quality: Eventbrite gave `streetAddress: "St Andrew's Plain, #The Halls, Norwich, NR3 1AU"` with **no `postalCode`**. [VERIFIED]
- Price: `AggregateOffer` with `lowPrice: "0.0"` string.

**Prevention:**
- Small in-house parser (`json_decode` + a lenient-repair pass that is *logged*) rather than relying on `brick/structured-data` 0.2.0 (pre-1.0, last release 2025-06-10). [VERIFIED Packagist] If you do use it, pin it.
- Flatten `@graph`/arrays/ItemList. Accept any type in a **whitelist of Event subtypes**. Normalise status/mode by suffix. Treat `OnlineEventAttendanceMode` or a `VirtualLocation`-only location as **skip (out of scope)** with a run counter.
- Extract a UK postcode by regex from any address text (`postalCode`, `streetAddress`, `name`) for the geofence. Price `0`/`0.0` maps to Free.
- **Eventbrite:** the listing page's `startDate` is date-only, so always fetch detail pages for the time. The Attleborough location search returned Norwich and online events [VERIFIED], so the Eventbrite source must be `location_mode=filter`. Use the numeric suffix in `/e/...-tickets-{id}` as `external_id`.
**Phase:** `JSONLD-SELECTORS`, `PIPELINE` (geofence postcode extraction).

### M3: The Events Calendar REST/ICS variations
**What goes wrong:**
- REST disabled by security plugins (Wordfence/iThemes "disable REST for anonymous"), so you get 401/403 JSON or HTML. [UNVERIFIED frequency]
- Page caching (WP Rocket, LiteSpeed, Cloudflare APO) serves stale REST/ICS for hours.
- Older TEC versions differ in fields. Recurring events (Events Calendar **Pro**) may expose each occurrence as its own ID or as provisional occurrence IDs depending on version, so the "same series" identity is unstable. [UNVERIFIED; check on 2–3 installs]
- `description` is HTML after `the_content` filters (shortcode output, embeds). `cost` is a free-text string. [VERIFIED]
- `per_page` is capped at 50. Paging past the end returns 404. [VERIFIED]

**Prevention:** Adapter probes `/wp-json/tribe/events/v1/` and falls back to the TEC ICS export (`?ical=1` on the events archive [UNVERIFIED exact URL; verify live]). Strip HTML with an allow-list sanitiser, then excerpt. Keep `cost` as `price_text`, mapping `Free`/`£0` to Free. Use `id` + `start_date` as external key for Pro occurrences. Add a `X-TEC-TotalPages` loop with a hard page cap (e.g. 20). Record the TEC version from `/wp-json/` (namespace list) in source notes.
**Phase:** `FEEDS-ADAPTERS`, `SRC-RESEARCH` (live verification on 2–3 Norfolk installs).

### M4: CSS selector brittleness
**What goes wrong:** Theme updates rename classes. Selectors break silently and produce 0 items, or worse, match the wrong elements (e.g. the "past events" block). Dates split across elements (`<span>14</span><span>Nov</span>`). Lazy-loaded "more events" content is missing from the server HTML.
**Prevention:**
- Per-source config: `item` selector, field selectors, `date_patterns`, `sentinel` selector, `expected_min_items`.
- Prefer stable hooks (`[itemprop]`, `data-*`, semantic tags) over generated classes. Save fixtures and a golden-output JSON per source, and re-run them on every config edit via Test run.
- Zero results after previous results flags the source (spec has this). Also flag **"items_found changed by more than 70%"**.
**Phase:** `JSONLD-SELECTORS`, `FETCH` (health rules).

### M5: Malformed and huge ICS feeds
**What goes wrong:** Line folding split mid-UTF-8 sequence (the RFC warns about this) [VERIFIED §3.1]. CRLF vs LF. BOM. Missing `END:VCALENDAR`. Duplicate UIDs across unrelated events. `DTSTART` without `VALUE=DATE` but in date format. Huge feeds (5–20 MB, years of history), where sabre's full DOM uses a lot of memory.
**Prevention:** Use the sabre reader in lenient mode (`Reader::OPTION_FORGIVING`) [UNVERIFIED flag name; check v5 API]. Run `->validate()` and log repairs as quality reasons. Stream size limit. Parse in a dedicated job with `--memory` headroom. Filter VEVENTs to the window *before* building drafts. Treat duplicate UIDs with different DTSTARTs and no RECURRENCE-ID as distinct items (`UID#DTSTART`). Note: `sabre/vobject` 5.0.0 was released 2026-07-07, a new major, so check the changelog for API breaks against older tutorials. [VERIFIED release date]
**Phase:** `ICS`.

### M6: Geofence errors
**What goes wrong:** "Attleborough" is also a district of Nuneaton (Warwickshire), so place-name matching gives false positives [UNVERIFIED as source-specific risk]. Postcode district matching on `NR17` misses nearby villages in NR16/IP24/IP25. Geocoding requires an API (cost, rate limits, ToS).
**Prevention:** Postcode districts list (NR17 + configurable NR16, IP24, NR18 [owner to decide, spec §18 Q3]) plus village place-name list matched with word boundaries **and** a county/postcode check. Offline postcode-to-lat/lng from ONS Postcode Directory / OS Code-Point Open (open data) [UNVERIFIED licence details] instead of a geocoding API. Unknown location → draft with a flag (spec).
**Phase:** `PIPELINE`.

### M7: Encoding and text-normalisation bugs
**What goes wrong:** Windows-1252 pages declared as UTF-8 (`£` becomes `Â£`). Double-escaped entities in titles (`&amp;amp;`). Smart quotes break dedup. ALL CAPS titles. "SOLD OUT –" prefixes. Emoji.
**Prevention:** Detect the charset from header → meta → `mb_detect_encoding` and convert to UTF-8. Decode entities once. NFC normalise. Store `display_title` (admin-editable) separately from `match_title` (normalised for dedup).
**Phase:** `PIPELINE`.

### M8: Organiser submission spam and abuse
**What goes wrong:** The Turnstile token isn't verified server-side, or is verified without checking `hostname`/`action`. Rate limits key on the IP behind Cloudflare (all traffic looks like one IP). Submissions include links to scams, and the site publishes them under its trusted brand.
**Prevention:** Server-side `siteverify` call. Configure trusted proxies so `request()->ip()` is the real client IP. `RateLimiter` per IP + per email. Honeypot + minimum fill time. Links shown with `rel="nofollow ugc"`. Admin approval is already mandatory.
**Phase:** `SUBMIT-LEGAL`.

### M9: Mobile accessibility regressions
**What goes wrong:** Date filter chips smaller than 24×24 px (WCAG 2.2 SC 2.5.8 Target Size Minimum) [UNVERIFIED exact SC numbering; check against WCAG 2.2]. Calendar grids that aren't navigable by screen reader. Time ranges rendered as "19:00-21:00" without accessible text. Colour-only "Cancelled" badge. Alpine dropdowns trapping focus.
**Prevention:** Use a **list view** (not a grid) as the primary mobile UI. Use `<time datetime>`. "Cancelled" as text plus a strikethrough on the time. axe-core automated checks in CI (Pest browser tests or Playwright) and a manual VoiceOver/TalkBack pass before launch.
**Phase:** `PUBLIC`, `LAUNCH`.

### M10: PECR/analytics misconfiguration
**What goes wrong:** Choosing "cookieless" analytics but then adding a YouTube embed, Google Fonts from Google's CDN, or a map iframe that sets cookies or transfers IPs. That reintroduces consent requirements and privacy-notice gaps.
**Prevention:** Self-host fonts. No third-party embeds on public pages, or click-to-load placeholders. Plausible/Fathom/Umami without cookies. Note that the DUAA now lets some cookies for statistical purposes be set without consent [VERIFIED ICO summary], but staying fully cookieless keeps the "no banner" promise simple. Laravel session cookies only on admin and form routes (strictly necessary). Turnstile's storage behaviour [UNVERIFIED] should be checked and mentioned in the privacy notice.
**Phase:** `SUBMIT-LEGAL`, `PUBLIC`.

---

## Minor Pitfalls

### m1: Price text parsing
"Free entry, donations welcome", "£5/£3 conc", "Free for under 5s", "TBC". **Prevention:** `price_type` = Free only if the text matches an explicit free pattern *without* qualifiers. Otherwise Paid/Text, keeping the raw text. **Phase:** `PIPELINE`.

### m2: Slug collisions and changes
Weekly "Quiz Night" gives slug collisions, and title edits change URLs. **Prevention:** slug = `title-yyyy-mm-dd` for one-off events, `title` + short ID for series. Keep a `slug_history` with 301 redirects. **Phase:** `PUBLIC`.

### m3: "This weekend" edge cases
On Sunday evening, "This weekend" is almost empty. An event spanning Fri 18:00–Sat 02:00 must appear. **Prevention:** overlap queries (`starts_at < window_end AND ends_at > window_start`) with the computed effective end (spec expiry rule). Tests pinned to Sat/Sun/Mon dates with `setTestNow`, including a DST weekend (24–25 Oct 2026). **Phase:** `PUBLIC`.

### m4: Conditional request misuse
Sending `If-None-Match` with a weak ETag from a different URL variant, or storing the ETag before the parse succeeded. A parser bug then gets "frozen" by 304s. **Prevention:** store ETag/Last-Modified **only after** a successful parse, and add a "force refetch" button in Test run/Run now. **Phase:** `FETCH`.

### m5: User-Agent blocked or ignored
Generic `GuzzleHttp/7` UAs get blocked. Some sites block anything with "bot". **Prevention:** `AttleboroughEventsBot/1.0 (+https://<domain>/about-our-crawler; contact@...)` with an about page explaining frequency and opt-out. Never spoof browser UAs. **Phase:** `FETCH`.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|---|---|---|---|
| `Carbon::parse()` on scraped text | Fast to write | Silent wrong dates (Pitfall 4) | **Never** in `App\Extraction` |
| Ingest `expand()`ed ICS instances as items | Simple occurrence list | Daily diff noise, unique-key clashes | **Never** |
| Hash raw page body for change detection | One line | Update-draft floods | Only as a pre-check before semantic hash |
| `raw_content` column on `source_items` (spec §9) | Matches spec | Fat rows in Filament queries, disk growth | Acceptable if list queries select explicit columns; better as a separate table |
| `sleep()` for per-host delay | Simple politeness | Worker blocking, timeouts, double runs | Only in Test run (sync, admin-triggered) with small page caps |
| Single `notes` text for legal assessment | No schema work | Can't filter sources by legal basis or prove diligence | MVP only if a template is enforced |
| Skipping the restore drill | Saves an hour | Backups that don't restore | **Never** before launch |
| Lenient JSON repair without logging | More items parsed | Hidden data corruption | Only if each repair adds a quality reason |

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|---|---|---|
| Squarespace | Using `?format=json`/`?format=ical` | Disallowed by default robots.txt. Use HTML/JSON-LD + sitemap or skip [VERIFIED] |
| Eventbrite | Using listing-page JSON-LD times; RSS | Listing `startDate` is date-only; RSS disallowed. Fetch `/e/` detail pages, filter online + out-of-area [VERIFIED] |
| The Events Calendar | Default REST window; treating page-404 as failure; `per_page=100` | Explicit `start_date`/`end_date`; loop to `X-TEC-TotalPages`; max 50 [VERIFIED] |
| Google Calendar ICS | Importing full history; trusting update latency | Window filter (−1 d … +12 mo); expect hours of feed lag [UNVERIFIED lag] |
| Outlook/Exchange ICS | Windows TZIDs | sabre map + `failIfUncertain=true` [VERIFIED map exists] |
| WordPress sites | REST blocked / cached | Probe, fall back to ICS/JSON-LD, record in source notes |
| Cloudflare-protected sites | Retrying or spoofing UA | Mark `blocked`, manual entry, ask the owner to allowlist |
| Cloudflare Turnstile | Client-only check | Server `siteverify` + trusted proxies |
| `sabre/vobject` 5.0 | Following 4.x tutorials blindly | Check the 5.0 changelog; pin `^5.0` |

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|---|---|---|---|
| `select *` with `raw_content` in Filament tables | Review page > 1 s, high memory | Explicit columns, separate payload table | ~500+ items with 50–200 KB payloads |
| Occurrence generation on page view | Slow calendar, CPU spikes | Precompute `event_occurrences` by daily job; index `(starts_at, ends_at, status)` | Dozens of series |
| `pg_trgm` similarity without index | Slow draft creation | GIN `gin_trgm_ops` index on `match_title`; pre-filter by date first | ~5k events |
| One worker + long fetch jobs | Queue backlog, stale sources | Page-per-job, rate-limit middleware, `--timeout` < `retry_after` | ~20 sources with pagination |
| Unpruned `jobs`/`failed_jobs`/`source_runs` | DB growth, slow dashboard counts | Prune schedules; dashboard counts over a 30-day window | Months of operation |
| Sitemap built per request | Slow crawler hits | Cache/regenerate on publish | Hundreds of events |

## Security Mistakes

| Mistake | Risk | Prevention |
|---|---|---|
| Rendering scraped HTML descriptions | Stored XSS on public site and admin | Strip to text (or strict allow-list), escape in Blade, never `{!! !!}` on source content |
| Fetching admin- or organiser-supplied URLs without guards | SSRF to VPS metadata/localhost/Postgres | Resolve DNS, block private/link-local ranges, http(s) only, redirect re-check |
| XML feed parsing with entity expansion | XXE / billion laughs | libxml defaults in PHP 8 disable external entities; still set `LIBXML_NONET`, cap size |
| Admin panel on default `/admin` with password only | Credential stuffing | Filament MFA (built into v4+ [UNVERIFIED for v5 config]), rate-limited login, single user, IP allow-list optional |
| `.env`/backups readable from web root | Credential leak | Web root = `public/`, backups encrypted off-box |
| Publishing organiser personal email in plain text | Scraping/spam; GDPR complaint | Show contact only if consented; obfuscate or use contact-form relay |

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---|---|---|
| Showing cancelled events without strong signal | Wasted trips | "Cancelled" text badge + strikethrough time, sorted normally until expiry |
| Times in UTC or without day name | Confusion | Always "Sat 14 Nov, 7:30pm" in Europe/London; `<time datetime>` with offset |
| Empty "Today" with no fallback | Bounce | Show "Nothing today — here's this weekend" |
| Multi-day events repeating as N cards | Clutter | One card with a date range, in every overlapping window (spec) |
| Admin diff showing raw JSON | Slow review | Field-level human diff ("Start: 19:00 → 19:30") |
| "Low quality" without reasons | Admin distrust | Always list reasons (spec), each with a fix hint |

## "Looks Done But Isn't" Checklist

- [ ] **ICS parser:** passes in October, but have you run the DST fixture pack (Mar + Oct transitions, weekly RRULE across a change, floating time, unknown TZID → error)?
- [ ] **ICS parser:** CI run under `TZ=Pacific/Auckland` produces identical output?
- [ ] **Recurring events:** a daily run of an unchanged calendar creates **zero** drafts on the second day?
- [ ] **TEC adapter:** yesterday's events don't create cancellation drafts after two runs?
- [ ] **TEC adapter:** handles 404 on page N+1 and `X-TEC-TotalPages`?
- [ ] **Squarespace adapter:** makes no request to `?format=` URLs (robots test)?
- [ ] **Robots checker:** fixture tests for longest match, wildcards, multi-UA groups, 4xx → allow, 5xx → disallow?
- [ ] **Selector parser:** `"Sat 15th Nov 2026"` (wrong weekday) → parse error, not 21 Nov?
- [ ] **Selector parser:** December fixture with "Sat 9th Jan" resolves to next year via weekday rule?
- [ ] **Fetcher:** an HTML body on an ICS source → failed run `content_invalid`, not "0 items"?
- [ ] **Change detection:** a page differing only in tracking params/nonce/"days to go" → no update draft?
- [ ] **Dedup:** weekly quiz on 4 dates → no duplicate warnings among themselves?
- [ ] **Bulk approve:** excludes duplicate-flagged/parse-error/Low drafts?
- [ ] **Queue:** `--timeout` < `retry_after`; `ShouldBeUnique` on fetch job; heartbeat alert tested by stopping the worker?
- [ ] **Deploy:** `queue:restart` in deploy script; atomic symlink; zero 500s during deploy (curl loop during deploy)?
- [ ] **Backups:** restored to a scratch DB successfully in the last 30 days?
- [ ] **JSON-LD output:** every datetime has an offset; cancelled keeps `startDate`/`location`; no Event markup on listing pages; Rich Results Test passes on 3 sample pages?
- [ ] **Expired pages:** 200 + "ended" banner; removed from sitemap; hidden/rejected → 410; merged → 301?
- [ ] **Legal:** privacy notice, LIA, takedown form, complaints route (30-day acknowledgement) live; discovered descriptions excerpted ≤ 200 chars; phone/emails stripped?
- [ ] **Submission form:** Turnstile verified server-side; real client IP behind Cloudflare?
- [ ] **A11y:** axe clean; 24×24 px targets; Cancelled conveyed by text?

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---|---|---|
| Timezone bug published wrong times | MEDIUM | Fix resolver; re-parse stored `raw_content` (why we keep it); generate update drafts in bulk; bulk-apply after spot check |
| Recurrence stored as expanded instances | HIGH | Migration to series items; collapse items by UID; regenerate occurrences; delete superseded drafts |
| Mass false cancellation drafts | LOW | Bulk "ignore cancellation" filtered by run ID; fix missing-detection rule; add regression fixture |
| Queue double-processing created duplicate drafts | LOW | Dedupe drafts by `(source_item_id, semantic_hash)`; add unique index; fix timeouts |
| Source owner complains / takedown | LOW | Deactivate source, hide events (not delete), reply within SLA, record in source notes |
| Disk full from raw_content/logs | MEDIUM | Prune old payloads, rotate logs, add disk alert; check Postgres didn't corrupt (it usually won't, but verify) |
| VPS lost | MEDIUM | New VPS from provisioning script, restore latest `pg_dump`, DNS swap. Only possible if the restore drill was done |
| Squarespace adapter built on disallowed endpoints | MEDIUM | Rewrite as HTML/JSON-LD; audit fetch logs; apologise if contacted |

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---|---|---|
| P1 Timezone fallback | `ICS`, `FEEDS-ADAPTERS`, `JSONLD-SELECTORS` | DST fixture pack; CI under foreign `TZ`; unknown TZID → parse error |
| P2 All-day/end-time | `PIPELINE` (+ parser phases) | EventTiming unit tests; weekend-filter tests |
| P3 Recurrence model | `ICS` (decide early), `PIPELINE`, `PUBLIC` | Unchanged calendar → 0 drafts on day 2; override diff test |
| P4 Free-text dates | `JSONLD-SELECTORS`, `SRC-RESEARCH` | Arch test bans `strtotime`; weekday-mismatch + year-rollover fixtures |
| P5 Robots / disallowed endpoints | `SRC-RESEARCH`, `FETCH`, `FEEDS-ADAPTERS` | Robots fixture suite; no `?format=` requests in logs |
| P6 False missing cancellations | `PIPELINE`, `FEEDS-ADAPTERS`, `FETCH` | Past events never become cancellation drafts; partial run doesn't count |
| P7 Change-detection noise | `PIPELINE`, `FETCH` | Noise fixtures → no drafts; `items_changed` ratio on dashboard |
| P8 Dedup errors | `PIPELINE`, `REVIEW`, `SRC-RESEARCH` | Recurring-title fixtures; decisions table suppresses repeats |
| P9 Fake-success fetches | `FETCH` | Body-validation tests per rung; blocked state |
| P10 Queue/scheduler | `FOUNDATION`, `FETCH`, `LAUNCH` | Config assertions; heartbeat alert drill |
| P11 Backups/logs/deploys | `FOUNDATION`, `LAUNCH` | Restore drill; zero-downtime deploy check |
| P12 Review fatigue | `REVIEW`, `PIPELINE`, `FETCH` | Median review time tracked; bulk guard tests; query count tests |
| P13 Legal | `SRC-RESEARCH`, `PIPELINE`, `SUBMIT-LEGAL` | Legal basis per source; excerpt/PII tests; complaint route live |
| P14 SEO status codes/markup | `PUBLIC`, `REVIEW` | HTTP status tests per event state; JSON-LD schema tests; Rich Results Test |
| M1 RSS dates | `FEEDS-ADAPTERS` | `pubDate` never used as start (test) |
| M2 JSON-LD quirks | `JSONLD-SELECTORS` | Fixtures: @graph, arrays, subtypes, ItemList, invalid JSON, Eventbrite pages |
| M3 TEC variations | `FEEDS-ADAPTERS`, `SRC-RESEARCH` | Live verification on 2–3 installs; REST-disabled fallback test |
| M4 Selector brittleness | `JSONLD-SELECTORS`, `FETCH` | Sentinel selector; golden-output fixtures |
| M5 Malformed/huge ICS | `ICS` | Malformed fixtures; 10 MB feed under memory limit |
| M6 Geofence | `PIPELINE` | Postcode extraction tests; "Attleborough, Nuneaton" negative fixture |
| M7 Encoding | `PIPELINE` | Windows-1252 + double-entity fixtures |
| M8 Spam | `SUBMIT-LEGAL` | Turnstile server test; rate-limit behind proxy test |
| M9 A11y | `PUBLIC`, `LAUNCH` | axe in CI; manual screen-reader pass |
| M10 PECR | `SUBMIT-LEGAL`, `PUBLIC` | No third-party cookies on public pages (browser test inspecting `document.cookie` / network) |

## Sources

Primary sources fetched 2026-10-16 (seam tier for `webfetch`: LOW; content is verbatim from authoritative origins):
- RFC 5545 (iCalendar): §3.1 folding, §3.3.5 floating time, §3.3.10 RRULE (UNTIL/COUNT, invalid instances), §3.6.1 VEVENT DTEND non-inclusive, TZID ambiguity rules: https://www.rfc-editor.org/rfc/rfc5545.txt
- RFC 9309 (Robots Exclusion Protocol): longest match, 4xx/5xx handling, 24 h cache, 500 KiB: https://www.rfc-editor.org/rfc/rfc9309.txt
- `sabre/vobject` source: `lib/TimeZoneUtil.php` (silent default-tz fallback), `lib/Component/VCalendar.php` (`expand()` UTC default, UID requirement), `lib/Settings.php` (`maxRecurrences=3500`), `lib/timezonedata/windowszones.php`: https://github.com/sabre-io/vobject. Packagist: 5.0.0 released 2026-07-07
- The Events Calendar source: `src/Tribe/REST/V1/Endpoints/Archive_Event.php` (defaults, max 50, 404 past end, X-TEC headers) and `Post_Repository.php` (fields): https://github.com/the-events-calendar/the-events-calendar
- Laravel 13.x Queues docs (retry_after vs timeout, max-jobs/max-time, memory, queue:restart via cache, prune-failed, ShouldBeUnique): https://laravel.com/docs/13.x/queues. laravel/framework v13.37.0 (2026-10-16); filament/filament v5.10.2 (2026-10-13)
- Google Search Central, Event structured data (last updated 2026-09-08): https://developers.google.com/search/docs/appearance/structured-data/event
- ICO, "The Data (Use and Access) Act 2025 — what does it mean for organisations?" (updated 19 June 2026): https://ico.org.uk/about-the-ico/what-we-do/legislation-we-cover/data-use-and-access-act-2025/the-data-use-and-access-act-2025-what-does-it-mean-for-organisations/
- Copyright and Rights in Databases Regulations 1997, reg. 16: https://www.legislation.gov.uk/uksi/1997/3032/regulation/16
- Live checks: https://www.attleboroughtownfc.co.uk/robots.txt (Squarespace defaults), https://www.eventbrite.co.uk/robots.txt, Eventbrite `/d/united-kingdom--attleborough/events/` and two `/e/` pages (JSON-LD shapes)
- Local PHP 8.4.12 experiments: `strtotime`/`DateTime` behaviour for UK date strings and DST ambiguity (ambiguous 01:30 on 25 Oct 2026 resolved to GMT, i.e. the *second* occurrence, whereas RFC 5545 specifies the *first*. Low practical impact, but add a fixture)
- Packagist metadata: `brick/structured-data` 0.2.0 (2025-06-10), `rlanvin/php-rrule` v3.0.0 (2026-07-29), `spatie/robots-txt` 2.5.4 (2026-02-25)

[UNVERIFIED] items (training knowledge; verify in the named phase): Google Calendar ICS history size, override/cancel representation and update latency; Cloudflare challenge header names; TEC Pro occurrence ID scheme and ICS URL; Squarespace event-page JSON-LD; Filament 5 MFA/table defaults; Turnstile storage behaviour; WCAG SC numbering; VPS sizing figures; dedup thresholds; Eventbrite ToS terms.

---
*Pitfalls research for: deterministic local-events aggregator (Attleborough Events)*
*Researched: 2026-10-16*
