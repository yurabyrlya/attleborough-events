# Project Research Summary

**Project:** Attleborough Events
**Domain:** Hyperlocal "what's on" aggregator. It ingests events with deterministic parsers (ICS / RSS / platform adapters / JSON-LD / CSS selectors), sends everything through a single-admin review queue, and publishes a mobile-first public calendar. Built as a Laravel monolith on a tiny VPS.
**Researched:** 2026-10-16
**Confidence:** MEDIUM. Version facts and library/protocol behaviour are HIGH: they were read directly from registries, RFCs, library source and official docs. Design choices, prioritisation and legal readings are MEDIUM. The local source inventory is LOW and is the biggest unknown.
**Baseline:** `docs/product-specification.md` v1.1. Where research contradicts or refines it, see **Spec v1.1 Deltas** below.
**Roadmap granularity:** project config is **`coarse`**, so **3-5 phases**. This summary recommends **5**.

## Executive Summary

Attleborough Events is a small, trust-first community calendar. Comparable products (Gancio, The Events Calendar, Calagator, Visit Norfolk, Visit Wymondham) show what to build. They all offer chronological lists with date and category filters, per-event "add to calendar", share links, and moderated submissions. Experts build the ingestion side as a strict pipeline: **fetch politely → extract to a typed candidate → normalise → detect change and duplicates → draft → human approval → materialised occurrences**. The single most important architectural property is a **single choke point**. Only the review actions (`ApproveDraft`, `ApplyUpdate`, `ConfirmCancellation`, plus manual entry as an auto-approved draft) can write to the published `events` / `event_occurrences` model. That property is how the product keeps its promise that nothing goes public without approval. It is also a real differentiator, because Gancio's moderation is off by default and Calagator lets anyone edit.

The recommended approach is a **Laravel 13 / PHP 8.5 / PostgreSQL 18 / Filament 5 / Livewire 4** monolith. It runs on one Hetzner CX23 VPS with Ubuntu 26.04 native packages, Caddy, a database queue (two connections and two workers), cron, and Deployer from GitHub Actions. Backups go to Cloudflare R2. The total cost is about £4-6/month. Build the **published core and review queue first**, using manual entry and organiser submissions as the first draft producers. Then add the ingestion backbone with ICS as the first rung, then the remaining rungs. This order puts a usable site and the admin workflow in place before any crawling. Each parser can then be fixture-tested in isolation against the same `EventCandidate` contract. `sabre/vobject` 5 is the **only** recurrence-expansion engine. `rlanvin/php-rrule` is used only for human-readable summaries. The JSON-LD parser is in-house (≈200 LOC) rather than pre-1.0 `brick/structured-data`.

The key risks are mostly **silent correctness failures**, not scale:
- **Timezone fallbacks** in sabre, floating ICS times and the PHP default timezone shift events by an hour during BST. Clocks change on **25 Oct 2026**, nine days from now.
- **Free-text date parsing.** `strtotime("Sat 15th Nov 2026")` returns 21 Nov.
- **False "missing item" cancellations**, for example from TEC's default `start_date = yesterday`.
- **Change-detection noise and duplicate false positives**, which would wear out a solo admin with a 2 h/week budget.
- **Squarespace's `?format=` endpoints are disallowed by robots.txt.**

On the business side, the main risk is that **few local sources have structured data**. Automated source discovery was blocked, and the source research track (with owner-collected URLs and saved fixtures) is the gating dependency for every parser phase. Mitigation: a strict "reject, not guess" parsing discipline backed by fixture and snapshot tests, a DST fixture pack, an architecture test that bans `strtotime`/`Carbon::parse` in extraction code, two-hash change detection against an accepted baseline, guarded missing-item rules, and good manual entry, because Facebook-only events will be a large share of the inventory.

## Spec v1.1 Deltas (research contradicts or refines the baseline)

The roadmapper and requirements author should treat these as **decisions to carry into REQUIREMENTS.md**. Items marked ⚠ need an owner decision.

| # | Spec v1.1 says | Research finding | Recommendation | Source |
|---|---|---|---|---|
| D1 | PHP 8.3+ (§11, PROJECT constraints) | Symfony 8.x DomCrawler/CssSelector, `spatie/schema-org` 5, `spatie/laravel-sitemap` 8, Pest 5 and `spatie/pest-plugin-snapshots` 2.4 all need **PHP ≥8.4**. Ubuntu 26.04 ships 8.5 natively. | Composer floor **`^8.4`**. **Run 8.5** in production and CI. `composer config platform.php 8.5.11`. | STACK |
| D2 | Squarespace adapter via `?format=ical` / `?format=json` (§6, §14) | Squarespace's **default robots.txt disallows `/*?format=json` and `/*?format=ical`** (checked live on attleboroughtownfc.co.uk). The one known Squarespace source has no events collection in its nav. | **The Events Calendar (TEC) is the "at least one verified platform adapter" for DoD #3.** Drop the Squarespace adapter, or limit it to server-rendered HTML plus event-page JSON-LD discovered via sitemap. Never append `?format=`. STACK.md's "try `?format=ical` first" is **overruled**. | PITFALLS P5 |
| D3 | "Missing for 2 consecutive successful runs → cancellation draft" (§8.4) | TEC REST's default `start_date` is `yesterday 23:59`, so every finished event drops out. That would create a cancellation draft **for every past event**. Paginated or "latest N" listings and partial runs cause the same problem. | Count an item as missing only if **all** of these hold: its last-known start is **in the future**; it is inside the run's **observed date window**; the run is **complete** (all pages fetched, `items_found ≥ 50%` of the previous run, non-empty); and the source has `supports_missing_detection = true` (default false for feeds and paginated listings). The TEC adapter sends explicit `start_date`/`end_date`, uses `per_page=50` (server max), loops to `X-TEC-TotalPages`, treats a 404 past the last page as the end, and prefers `utc_start_date`. | PITFALLS P6, M3; ARCH P5 |
| D4 | RSS/Atom is rung 1, and the DoD requires it to work (§6, §17.3) | RSS items carry `pubDate` (publication time), **not the event date**. Under reject-not-guess, most items become parse errors. RSS is weak as an event source. | Build a **minimal** `FeedExtractor` with `rss_mode: date_required \| link_follow`. Never use `pubDate`/`updated` as the start. Link-follow hands off to the JSON-LD rung. ⚠ **Owner:** keep RSS in the DoD only if source research finds a feed carrying event dates; otherwise demote it to "supported, unused". | FEATURES §6; PITFALLS M1 |
| D5 | No calendar export or sharing features | Every comparable has per-event **add to calendar** and most have **share**. The spec lists "traffic from local shares" as a success metric but has no feature to drive it. | Add in v1 (each is small): per-event `.ics` + Google Calendar link (stable occurrence UID), a Web Share button with WhatsApp/Facebook/email/copy fallbacks, a **Free** filter chip (`price_type` already exists), a per-event **report-a-problem** link, date-grouped list headings, and analytics custom events (outbound/source click, add-to-calendar, share, submit). **v1.x:** public ICS feed (all + per category), RSS feed of new events, family-friendly flag. | FEATURES |
| D6 | "ICS recurrence supported" (§8.5); no timezone rules beyond "store UTC, show London" | sabre `expand()` / `EventIterator` **treat floating times as UTC by default**. `TimeZoneUtil` silently falls back to the PHP default timezone for unknown TZIDs. `Greenwich Standard Time` maps to a zone with no DST. | **Force floating ICS times to `Europe/London`** (per-source `default_timezone`, plus a quality reason). Always pass the zone explicitly. Call `TimeZoneUtil::getTimeZone(..., failIfUncertain: true)` and turn failures into parse errors. Expand recurrences in local time, then convert to UTC. Run CI under a foreign `TZ`. Keep a DST fixture pack (29 Mar 2026, 25 Oct 2026, 28 Mar 2027, 31 Oct 2027). | PITFALLS P1; ARCH P7 |
| D7 | "Ambiguous date (no year) → parse error" (§6) | `strtotime`/`Carbon::parse`/`new DateTime($text)` silently mis-parse UK listing text: weekday overrides date, US m/d order, silent current year, `7.30pm` → false. | **Ban `strtotime` / `Carbon::parse` / free-text `new DateTime` in `App\Ingestion`**, enforced by a Pest `arch()` test. Use a `UkDateParser` with ordered explicit per-source `date_patterns` (`createFromFormat('!…')` + `getLastErrors()`), a **weekday cross-check**, and relative dates resolved against `fetched_at`. ⚠ **Owner clarification:** allow deterministic year resolution when a weekday is present (only one matching year in [fetch−7d, fetch+365d]), with a per-source `year_policy: reject \| next_occurrence` that defaults to `reject`. | PITFALLS P4 |
| D8 | Truncate discovered descriptions to 500 chars (§8.8) | Copyright substantiality is qualitative, so a 500-char blurb may be most of the original. UK database right (reg. 16) covers repeated systematic extraction. | ⚠ Publish discovered descriptions as an **excerpt of ≤200 chars plus "Read more at [source]"**, and keep the full text internal for review. Strip phone numbers and emails from discovered text. Submissions and manual entries can be full length. Add a structured per-source `legal_basis` enum instead of free-text `notes`. (STACK.md assumed 500. PITFALLS recommends 200. Adopt 200.) | PITFALLS P13 |
| D9 | Privacy notice + takedown contact (§8.8, DoD #9) | The **Data (Use and Access) Act 2025** (fully in force per the ICO, June 2026) requires controllers to make it easy to raise data-protection complaints (e.g. an electronic form), **acknowledge them within 30 days** and respond without undue delay. | Add a **takedown/correction + data-protection complaints form** backed by a Filament `contact_requests` resource that tracks the 30-day acknowledgement SLA. Also add a short Legitimate Interests Assessment in the repo. | PITFALLS P13 |
| D10 | Hosting is an open question (§18 Q1); lists Railway/Fly/Laravel Cloud | Managed options likely exceed £6/month with an always-on worker and Postgres. Ploi Free allows 5 deploys a month. | Hetzner CX23 + Ubuntu 26.04 + Caddy + PHP-FPM 8.5 + PG 18 + systemd workers + Deployer 8. **Deploy to the VPS in Phase 1.** | STACK |
| D11 | Analytics provider is an open question (§18 Q5) | Umami Cloud Hobby costs $0 (100K events/month). Plausible costs $9/month (≈2× the VPS). | **Umami Cloud Hobby**, pending a check of UK GDPR data region and DPA. Fallback is Plausible. Stay fully cookieless and self-host fonts: no third-party embeds. | STACK; PITFALLS M10 |
| D12 | JSON-LD via `brick/structured-data` or in-house (§11); feed parser TBD | brick is pre-1.0, weakly typed and adds Microdata/RDFa we don't need. | **In-house `JsonLdEventParser`** (flatten `@graph`/arrays/`ItemList`, whitelist Event subtypes, normalise status by suffix). **`laminas/laminas-feed`**, fed only bytes from `HttpFetcher`. `spatie/robots-txt` used only on content we fetched ourselves. | STACK; PITFALLS M2 |
| D13 | Content hash skip (§6); update drafts with diff (§8.4) | Hashing raw bytes (DTSTAMP, nonces, "3 days to go", utm params) floods the queue with empty updates. Diffing against the live event re-raises admin typo fixes. | Use **two hashes**: `raw_hash` to skip, and a semantic `content_hash` of canonical normalised fields. Decide changes against an **accepted baseline** (`accepted_hash` + `accepted_payload`, updated on approve, apply *and* ignore). Show a three-column diff: Published now / Source before / Source now. Canonicalise URLs. | ARCH P5; PITFALLS P7 |
| D14 | Unique on `(source_id, external_id)`; RRULE/EXDATE/overrides supported | Ingesting expanded instances shifts the window every day and floods the queue. Overrides share the UID. | **One source item per ICS series** (UID). Master + overrides + EXDATE/RDATE go in one raw fragment and one hash. `OccurrenceGenerator` upserts on `(event_id, recurrence_key)` and **preserves admin-set occurrence status**. Manual series are synthetic VEVENTs expanded by the same engine. | ARCH P6; PITFALLS P3 |
| D15 | Geofence: 8 miles or postcode districts / place names (§8.6) | The radius needs a geocoder (none chosen). "Attleborough" is also a district of Nuneaton. Eventbrite's "Attleborough" page is mostly Norwich and online events. | Use a **postcode-district + word-boundary place-name allowlist** with a county/postcode check first. Use the radius only when the source supplies lat/lng. Skip online-only events. No geocoding API in MVP. ⚠ Owner: decide the district list (NR17 + NR16/IP24/NR18?). | FEATURES; PITFALLS M6 |
| D16 | Separate Filament UIs for category mappings, keyword rules, geofence (§7.6) | That is three CRUD resources for rules the developer-admin rarely changes. | One "Rules" resource, or seeded config, at launch. Show the quality **band + reasons** first and the 0-100 number second. | FEATURES |
| D17 | Manual entry fields; submission form fields (§7.5, §7.6) | Local events are mostly weekly or monthly. Manual entry (for Facebook-only events) needs recurrence. | **Manual entry supports simple recurrence** (weekly / nth-weekday-monthly → RRULE). The submission form gets a free-text **"repeats"** field. | FEATURES |
| D18 | Expired events "hidden by default but kept" (§8.1) | Shared links outlive events, and a 404 throws away local link equity. Filter and feed URLs create crawl traps. | Expired event pages return **200 with an "ended" banner** and are removed from the sitemap; add `noindex` 30 days after expiry; return 410 after 12 months or when the admin hides or rejects an event as wrong. Merged duplicates 301. Filter URLs are `noindex`. Event JSON-LD goes on detail pages only, one Event per upcoming occurrence (cap about 10). | FEATURES; PITFALLS P14 |
| D19 | Drafts for everything discovered | A first ICS run can contain years of history. Regional sources are mostly out of area. | **Never draft past events or events more than 12 months ahead** (count them as `skipped_past` / `skipped_far_future`). Add a new-source onboarding preview. **Bulk approve excludes** parse-error, duplicate-flagged and Low drafts. | PITFALLS P12 |
| D20 | (silent) | Bot walls and DNS filters return 200 HTML instead of ICS. A body that fails validation must not count as a successful run with 0 items. | **Validate the body per rung** (`BEGIN:VCALENDAR`, XML root, JSON shape, per-source sentinel selector). Invalid content means a failed run (`content_invalid`). A robots disallow means a `blocked` run, which doesn't degrade health. Never bypass bot protection. Add an SSRF guard. | PITFALLS P9 |

## Key Findings

### Recommended Stack

The stack follows the spec's Laravel-first choices, updated to current majors. Version numbers were pulled from Packagist, npm and php.net on 2026-10-16. Everything runs on one cheap VPS with native Ubuntu 26.04 packages: no Docker, no Redis. Tests run on PostgreSQL 18, never SQLite, with `Http::preventStrayRequests()` globally and snapshot tests per fixture. Decline any Laravel Boost / AI SDK prompts at scaffold time.

**Core technologies:**
- **PHP 8.5 (floor `^8.4`)**: the runtime. Required by Symfony 8 DomCrawler, spatie/schema-org 5, sitemap 8 and Pest 5.
- **Laravel `^13.37`**: framework, scheduler, database queue/cache/session. Upgrade to 14 after launch, not before.
- **PostgreSQL 18 + `pg_trgm`** (raw SQL migration, trusted extension): fuzzy dedup via `similarity()` with a config threshold, never the `%` GUC.
- **Filament `^5.10` / Livewire `^4.4.7`**: admin review queue, sources, manual entry. Do **not** start on Filament 4, whose bug fixes end 2027-01-15.
- **Tailwind `^4.3` + Vite `^8.3` + Alpine `^3.17`** (Alpine only in the non-Livewire public bundle).
- **sabre/vobject `^5.0`**: ICS parsing and the **only** recurrence expander (a v5 major with strict types; don't rely on 4.x tutorials).
- **rlanvin/php-rrule `^3.0`**: `humanReadable()` summaries and RRULE validation only. Upstream CI stops at PHP 8.4, so our CI must cover it on 8.5.
- **laminas/laminas-feed `^2.26.3`**, **symfony/dom-crawler + css-selector `^8.1`**, **spatie/robots-txt `^2.5.4`**, **in-house JSON-LD parser**.
- **spatie/schema-org `^5.0.2`**, **spatie/laravel-sitemap `^8.2`**, **spatie/laravel-honeypot `^4.7.3`**, **ryangjchandler/laravel-cloudflare-turnstile `^3.0.4`**. Avoid coderflex/laravel-turnstile, which conflicts with Guzzle 8.
- **Dev:** Pest `^5.3` + pest-plugin-laravel/livewire + **spatie/pest-plugin-snapshots** (the determinism guarantee), Larastan `^3.13`, Pint.
- **Ops:** Hetzner CX23, Caddy 2.11, systemd queue workers, cron `schedule:run`, Deployer `^8.0` via GitHub Actions, spatie/laravel-backup `^10.3.3` → Cloudflare R2 (`pg_dump` must be version 18), Umami Cloud Hobby.

### Expected Features

The v1.1 feature set is sound. The research adds cheap table-stakes items the spec missed (D5, D17, D18) and flags several over-scoped items (D2, D4, D15, D16).

**Must have (table stakes):**
- Upcoming list with Today / This weekend / This week chips (Europe/London overlap semantics), a category filter, and a **Free** chip
- Date-grouped headings, readable dates with `<time datetime>`, and filters as plain query-string links (they work without JS and get `noindex`)
- Detail page with source attribution, meta/OG, Event JSON-LD and a sitemap; expired URLs keep resolving
- **Add to calendar** (.ics + Google link) and a **share** button with fallbacks
- Moderated organiser submission (honeypot + rate limit + Turnstile) with a "repeats" field
- Admin manual entry **with recurrence**
- **Report-a-problem** link per event (it doubles as the GDPR correction route)
- Review queue: new / update / cancellation, diffs, quality reasons, duplicate warnings, guarded bulk actions
- Cookieless analytics **with custom events** (without them the success metrics can't be measured)
- WCAG 2.2 AA, mobile list-first (no month grid), 24×24px targets, "Cancelled" shown as text

**Should have (differentiators):**
- Mandatory review before publish (the core trust promise)
- Draft-time duplicate detection across sources, compared against both events **and** pending drafts
- Deterministic, reject-not-guess extraction with visible reasons
- "Approve & next" + keyboard shortcuts on a custom Review page, which directly serves the <2 h/week metric
- v1.x: public ICS subscription feed, RSS feed of new events, family-friendly flag, "New this week" strip

**Defer (v1.x / v2+):**
- Venue pages, village filter, embeddable widget, organiser "approved" email (v1.x)
- Email digest (PECR), structured accessibility filters, merging published events, admin-triggered LLM assistance (v2+)
- **Won't (MVP):** month grid, map view, visitor/organiser accounts, comments/ratings, image uploads, Facebook scraping, headless browser, federation, paid listings

### Architecture Approach

A domain-grouped Laravel monolith with **two write models and one read model**:
- The ingest model (`sources → source_runs → source_items → event_drafts`) is machine-written and never public.
- The published model (`events → event_occurrences + event_sources`) is written only by review actions.
- The read model is `event_occurrences ⋈ events WHERE published`, queried by a half-open UTC date window with a denormalised `expires_at`.

Extractors are pure (bytes in, `EventCandidate` DTOs out, with local wall-clock time plus timezone provenance, and parse errors carried on the candidate rather than thrown). Jobs are thin and actions are fat. State machines are backed enums with `canTransitionTo()`, backed by DB constraints. A partial unique index enforces one pending draft per source item. Filament closures stay one line and call the domain actions, so bulk approve and single approve can't diverge.

**Major components:**
1. **`HttpFetcher` + `RobotsPolicy` + `HostThrottle`**: the only network path. Handles UA with contact URL, an RFC 9309-correct robots check on **every** URL, ≥5s per host via a cache lock, conditional GET (store the ETag only after a successful parse), size and timeout limits, body validation, and the SSRF guard.
2. **`ExtractorRegistry` + six extractors** (ICS, Feed, TEC, Squarespace-HTML/optional, JSON-LD, Selector) behind one `Extractor` contract with `followUps()` for detail pages.
3. **`SourceItemRecorder`**: upserts by `(source_id, external_id)`, applies the two hashes and guarded missing-item counters, and records run stats.
4. **Pipeline** (`EventNormalizer` with `EventTiming`, `LocationFilter`, `CategoryAssigner`, `ChangeDetector`, `DuplicateDetector`, `QualityScorer`) → **`DraftWriter`**, the single writer, which supersedes and inserts in one transaction.
5. **Review actions** (`ApproveDraft`, `RejectDraft`, `MarkDuplicate`, `ApplyUpdate`/`IgnoreUpdate`, `ConfirmCancellation`/`IgnoreCancellation`, `CreateManualEvent`) follow one shape: lock → assert → mutate → `OccurrenceGenerator::sync` → baseline → audit → bump the cache version after commit.
6. **`OccurrenceGenerator`**: sabre `EventIterator` in the event's timezone over a 90-day horizon, upserting on `recurrence_key` and preserving status. Re-runs daily.
7. **`DateWindow` + `PublicOccurrenceQuery` + `CalendarCacheVersion`**: the database cache has no tags, so cache keys are versioned.
8. **Queues:** an `ingest` database connection (`retry_after 960`, single worker, `FetchSourceJob` `#[Timeout(600)]` + `ShouldBeUnique` + `WithoutOverlapping`) and a `default` connection (~90s) for everything else. Expected fetch failures are recorded on `source_runs`, **not** retried by the queue, so "3 failures" means 3 runs.

### Critical Pitfalls

1. **Silent timezone fallback**: sabre's floating time defaults to UTC, unknown TZIDs fall back to the PHP default, and Windows TZID mis-maps. *Avoid:* force Europe/London for floating times, use `failIfUncertain`, expand in local time, run the DST fixture pack and CI under a foreign `TZ`. Phase 3.
2. **Recurrence model mismatch**: ingesting expanded instances or keying overrides separately floods the queue and breaks unique keys. *Avoid:* one item per UID series, hash master + overrides, our own `OccurrenceGenerator` from the *approved* event. Decide the data shape **before** writing the ICS parser. Phases 1 and 3.
3. **Silent wrong dates from free text**: *Avoid:* the `strtotime`/`Carbon::parse` ban as an arch test, `UkDateParser` with explicit patterns, weekday cross-check, `fetched_at`-relative resolution. Phase 4.
4. **False missing-item cancellations, plus fetches that look successful**: TEC defaults, pagination, bot walls returning 200. *Avoid:* the D3 eligibility rules, per-rung body validation, a `complete` flag on runs, ">50% drop = structure-change alert, not N cancellations". Phases 3 and 4.
5. **Review fatigue from noise and dedup errors**: raw-hash changes, weekly same-title events flagged as duplicates, history floods. *Avoid:* semantic hash vs accepted baseline, occurrence-level dedup (same local date, ±60 min, minimum title length 6), a `duplicate_decisions` table so "not duplicate" sticks, skip past and far-future items, bulk-approve guards, explicit-column Filament queries with the raw payload kept separate. Track median review time from day one. Phases 2 and 3.
6. **Ops on a £5 VPS**: `retry_after` ≤ timeout leads to double runs, a missing cron or worker fails silently, logs are unbounded, backups are never restored. *Avoid:* the two-connection queue config, a scheduler/worker heartbeat with an external monitor, daily logs, atomic deploys + `queue:restart`, an off-box `pg_dump` **with a restore drill before launch**. Phases 1 and 5.
7. **Robots/legal exposure**: the Squarespace `?format=` endpoints, Eventbrite `/rss/`, database right, description copying, DUAA complaints. *Avoid:* D2, D8, D9, a per-source `legal_basis`, asking P0 organisers for permission. Phases 2/3 and 5.

**Reconciled conflicts between research files:**
- **Per-host politeness.** PITFALLS P10 says "no `sleep`, use `RateLimited` / page-per-job". ARCHITECTURE argues job-level rate limiting can't enforce per-request spacing and burns attempts. **Adopt ARCHITECTURE's design:** `HostThrottle` inside the fetcher, a dedicated `ingest` connection with `retry_after 960` > job timeout 600, exactly one ingest worker, and a cap on follow-ups (default 25). This addresses the real concern behind P10, which is timeout < `retry_after` and no double runs.
- **Raw content.** The spec puts it in a `source_items` column. **Keep the item fragment** (needed for reprocessing after a parser fix) but either move it to a separate `source_item_payloads` table or always select explicit columns. Full response bodies go to gzip on disk with 30-day retention, never into Postgres.
- **Web server / workers.** STACK says Caddy and one worker. ARCHITECTURE says Nginx and two workers. **Use Caddy (STACK owns hosting) with two workers (ARCHITECTURE owns queue topology).**
- **Missing-item rules.** Combine ARCHITECTURE (future-dated only, non-empty run, `supports_missing_detection`) with PITFALLS (observed window, run completeness ≥50%).

## Implications for Roadmap

The suggested phase structure is **5 coarse phases**, reconciling ARCHITECTURE's P0-P6 build order with the PITFALLS area mapping. Source research is a **non-code parallel track** that must finish before Phase 3. It needs owner-collected URLs, because automated search was blocked.

| Suggested phase | ARCHITECTURE build steps | PITFALLS areas covered |
|---|---|---|
| 1. Foundation & Published Calendar | P0 + P1 | FOUNDATION, PUBLIC (core), part of PIPELINE (`EventTiming`), REVIEW (manual entry) |
| 2. Draft Model, Review Queue & Submissions (+ source research complete) | P2 + Source research | REVIEW, PIPELINE (quality/category), SUBMIT-LEGAL (form/spam), SRC-RESEARCH |
| 3. Ingestion Backbone, ICS & Change/Duplicate Handling | P3 + P4 | FETCH, ICS, PIPELINE (change/dedup/missing) |
| 4. Remaining Extraction Ladder, Geofence & Test Run | P5 | FEEDS-ADAPTERS, JSONLD-SELECTORS, PIPELINE (geofence/encoding) |
| 5. Launch Readiness: SEO, Legal, Dashboard, Hardening | P6 | PUBLIC (SEO/a11y polish), SUBMIT-LEGAL (privacy/complaints/analytics), LAUNCH |

### Phase 1: Foundation & Published Calendar
**Rationale:** Ops risk on a £5 VPS is cheapest to retire first. Enums and schema are shared by everything. The published model is the target that every later approval writes into. DST, all-day and window logic can be tested before any parser exists.
**Delivers:** Laravel 13 / Filament 5 skeleton on PHP 8.5 + PG 18 with `pg_trgm`. UTC storage with a London display formatter. Two database queue connections. Pest with `preventStrayRequests` and a PG test DB. CI. All enums and core migrations (categories, venues, events, event_occurrences, event_sources). **Live VPS deploy** (Caddy, systemd workers, cron, heartbeat, daily logs, backup job). `EventTiming`, `OccurrenceGenerator` (single, multi-day and manual weekly/monthly series via synthetic VEVENT), `DateWindow`, `PublicOccurrenceQuery` with versioned cache. Livewire calendar with date, category and **Free** chips as query-string links. Date-grouped list. Detail page with meta/OG + JSON-LD, **add-to-calendar**, **share**, expired-page "ended" banner. `EventResource` with per-occurrence cancel/hide. **Manual entry with recurrence** (writes directly for now and is rewired in Phase 2).
**Addresses:** public browsing table stakes, D1, D5 (partly), D10, D14 (occurrence side), D17 (manual), D18 (partly).
**Avoids:** P2 all-day off-by-one, P3 (occurrence upsert preserving status), P10/P11 ops, m3 weekend edge cases. DST-boundary tests (25 Oct 2026 is imminent).

### Phase 2: Draft Model, Review Queue & Organiser Submissions
**Rationale:** The review UX (the core value and the 2 h/week metric) can be built and tested with no crawling at all. The submission form is the simplest draft producer. The source research track must finish here, because Phase 3 needs real fixtures.
**Delivers:** `event_drafts` with the partial unique index. `DraftWriter`. `QualityScorer` (band + reasons first). `CategoryAssigner` (source default → mapping → keyword → Other, with one "Rules" resource). Review actions (`ApproveDraft`, `RejectDraft`, edit-and-approve). `EventDraftResource`: tabs, filters, explicit-column queries, **custom Review page with "approve & next"**, keyboard shortcuts, bulk approve/reject excluding parse-error/duplicate/Low drafts. Organiser submission form (honeypot, per-IP + per-email rate limit, server-side Turnstile, trusted proxies, "repeats" field, consent + privacy link). Manual entry rewired as an auto-approved draft. Review-time metric. **Source research output:** `docs/research/event-sources.md` with a per-source rung, robots status, `legal_basis`, ToS notes, saved fixtures (with `.meta.json`), real UK date strings, a dedup calibration corpus, and live verification of TEC REST/ICS on 2-3 installs.
**Addresses:** review-queue table stakes and differentiators, submission, D16, D17, D19 (bulk guards), D2/D4 decisions informed by research.
**Avoids:** P12 review fatigue, M8 spam/proxy IP, Anti-Pattern 7 (logic in Filament closures).

### Phase 3: Ingestion Backbone, ICS & Change/Duplicate Handling
**Rationale:** ICS is the highest-yield rung and exercises recurrence end to end. PITFALLS P3 says the recurrence data shape, hashing and change detection must be designed **together**, so the ARCHITECTURE steps P3 and P4 are merged. That fits coarse granularity, and it means every later rung gets updates, cancellations and dedup for free.
**Delivers:** `sources`, `source_runs`, `source_items` (payload kept separate). `HttpFetcher` (UA + about page, RFC 9309 robots on every URL with a fixture suite, `HostThrottle`, conditional GET, limits, per-rung body validation, `blocked` / `content_invalid` statuses, 429 backoff, SSRF guard). `ExtractorRegistry`. **`IcsExtractor`** (UID grouping, `IcsTimezoneResolver` with `failIfUncertain`, floating → Europe/London, all-day exclusive DTEND, MaxInstances caught as a parse error, past/far-future skip). `FetchSourceJob` / `ProcessSourceItemJob` / `DispatchDueSources`. Health tracking (3 → degraded, 5 → unhealthy/paused, zero-after-results / >50% drop alert). `SourceResource` with Run now + runs relation manager. Raw body storage + pruning. **Change and duplicate handling:** semantic `content_hash`, accepted baseline, `ChangeDetector` + three-column diff, update/cancellation drafts, `ApplyUpdate`/`Ignore`/`ConfirmCancellation`, guarded missing-item detection (D3), `DuplicateDetector` (canonical URL + occurrence-level trigram vs events and pending drafts), `duplicate_decisions`, `MarkDuplicate`, per-occurrence cancellation from ICS overrides.
**Uses:** sabre/vobject 5, spatie/robots-txt, Laravel Http, pg_trgm.
**Avoids:** P1, P3, P5 (robots), P6, P7, P8, P9, M5. Day-2 check: an unchanged calendar produces 0 drafts.

### Phase 4: Remaining Extraction Ladder, Geofence & Test Run
**Rationale:** Every extractor shares the contract and fixture harness from Phase 3, so each can be an independent plan. The geofence only matters once regional sources (Eventbrite / Visit Norfolk / JSON-LD) are configured.
**Delivers:** **`JsonLdEventParser`** (in-house; `@graph`/`ItemList`/subtype whitelist, `eventStatus` by suffix, online-only events skipped, postcode regex, Eventbrite detail-page follow-ups because listing pages give dates only, numeric `/e/` ID as external_id). **`TheEventsCalendarAdapter`** as the verified platform adapter (explicit date window, `per_page=50`, `X-TEC-TotalPages`, 404-as-end, `utc_start_date`, REST-disabled → ICS fallback, version noted). **`SelectorExtractor`** + validated `SelectorConfig` (sentinel selector, `expected_min_items`, `date_patterns`) + **`UkDateParser`** + arch test banning `strtotime`/`Carbon::parse`. Minimal **`FeedExtractor`** (`date_required | link_follow`; never `pubDate`). Optional Squarespace HTML/JSON-LD path (never `?format=`). **`LocationFilter`** (postcode districts + place names + venue + lat/lng when present). Encoding/entity/NFC normalisation, excerpt ≤200 chars + PII strip. **Test run** (dry-run pipeline, same robots rules, outcome counts) and `ingest:reprocess`.
**Addresses:** DoD #3, D2, D4, D7, D8 (pipeline side), D12, D15.
**Avoids:** P4, P5 (Squarespace), P6 (TEC), M1, M2, M3, M4, M6, M7.

### Phase 5: Launch Readiness: SEO, Legal, Dashboard, Hardening
**Rationale:** Polish and compliance. Everything depends on Phases 1-4. Parts could move earlier if the owner wants a soft launch after Phase 2.
**Delivers:** SEO completion (sitemap of published non-expired events via a scheduled job, the expired → noindex → 410 / 301 status rules, `noindex` on filter URLs, JSON-LD CI assertions + Rich Results Test on sample pages, category placeholder images as og/JSON-LD image). Privacy notice, LIA, **takedown/correction + DUAA complaints form** with a `contact_requests` resource and 30-day SLA. Crawler about page. Umami with custom events (outbound, add-to-calendar, share, submit) after the DPA check. Filament dashboard widgets (runs, failures, unhealthy sources, drafts created/approved/rejected/duplicates, approval rate per source, median review time, heartbeat ages). Accessibility pass (axe in CI + a manual VoiceOver/TalkBack pass). **Restore drill**, disk alert, worker-kill heartbeat drill, zero-500 deploy check. Verify `calendar:extend-occurrences` across a horizon roll. Seed and onboard P0 sources.
**Addresses:** DoD #2, #8, #9, D9, D11, D18.
**Avoids:** P11, P13, P14, M9, M10.

### Phase Ordering Rationale

- **Dependencies:** published model → drafts/review → ingestion. Approvals need a target to write into, and parsers need a queue to land in. Source fixtures gate every parser, so research runs in parallel with Phases 1-2 and finishes by the end of Phase 2.
- **Grouping by architecture:** `Ingestion/` depends on the rest of the app only through `DraftWriter`, so Phases 3-4 are fixture-testable without the UI. `Review/Actions` is the single entry point to publishing, built in Phase 2 before any untrusted input exists.
- **Pitfall avoidance:** the ICS data shape and change detection are designed together (Phase 3) to avoid the HIGH-cost recurrence rewrite. Ops basics come first (Phase 1) so silent scheduler/worker death is caught from the beginning. Bulk-approve guards and noise controls land before ingestion volume arrives.
- **Coarse fit:** ARCHITECTURE P0+P1 and P3+P4 were merged to stay within 3-5 phases. If Phase 3 proves too large during planning, split it back into "Backbone + ICS" and "Change & Duplicates" by moving SEO/legal into Phase 4 or 5. Avoid going above 5.

### Research Flags

Phases likely to need `/gsd-plan-phase --research-phase <N>`:
- **Phase 3:** sabre/vobject **5.0** typed API (EventIterator constructor, `TimeZoneUtil` `failIfUncertain`, forgiving reader flag name, `maxRecurrences`), Google Calendar ICS behaviour (history size, override/cancel representation), whether spatie/robots-txt supports `$` anchors and longest-match (fixture-test it), and the database cache-lock semantics for `HostThrottle` in L13.
- **Phase 4:** live TEC REST/ICS verification on 2-3 Norfolk installs (Pro occurrence IDs, ICS URL), Squarespace event-page JSON-LD, Eventbrite ToS, whether any local RSS feed carries event dates, real UK date formats from P1 selector sources, ONS/Code-Point Open licence if lat/lng is ever needed.
- **Source research track (in Phase 2):** this is primarily discovery, not technical research. It needs agent research plus manual URL collection by the owner.

Phases with standard patterns (skip research-phase):
- **Phase 1:** standard Laravel/Filament/Livewire scaffolding plus well-documented VPS provisioning. Only quick checks: does the Laravel 13 skeleton default to Pest or PHPUnit, and the Hetzner price/stock.
- **Phase 2:** Filament 5 resources, tabs, bulk actions and custom record pages were verified in the docs. Spam packages are documented.
- **Phase 5:** mostly standard, except a short legal/compliance check (Umami data region and DPA, Turnstile storage behaviour for the privacy notice, Filament 5 MFA config, WCAG 2.2 SC numbering).

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH (versions) / MEDIUM (choices) | Every version and constraint was read from the Packagist/npm/php.net/Ubuntu registries on 2026-10-16. Hetzner pricing, Umami DPA and CI action versions are unverified. The seam tier is LOW only because of the fetch method (`webfetch`). |
| Features | MEDIUM | Based on first-party pages of 11 comparables, with no cross-check. Table-stakes labels are judgement. Event volume for Attleborough is unknown. |
| Architecture | MEDIUM-HIGH | Laravel 13 queue/cache/scheduler, Filament 5 and sabre/vobject mechanics were read verbatim from docs and source. Baseline diffing, the secondary-source policy, the storage split and the build order are design judgement. |
| Pitfalls | MEDIUM-HIGH | The highest-impact findings (Squarespace robots, TEC defaults, sabre timezone fallbacks, PHP date parsing, Eventbrite JSON-LD, RFC 5545/9309, DUAA) were **verified** against primary sources or local runs. Thresholds and Google Calendar behaviour are unverified. |

**Overall confidence:** MEDIUM. The technical plan is well grounded. Product viability depends on a local source inventory that hasn't been established yet.

### Gaps to Address

- **Local source inventory (critical):** only a handful of sources are known. *Handle:* a source research track in Phase 2 with owner URL collection. Re-prioritise the Phase 4 rungs according to what is actually found. If structured sources are scarce, put more effort into manual entry and submission UX.
- ⚠ **Owner decisions needed before or in requirements:**
  - D4: keep RSS in the DoD?
  - D7: year-resolution policy
  - D8: 200-char excerpt vs the spec's 500
  - D15: geofence districts and villages (spec Q3)
  - Secondary-source update policy (ARCH: only cancellations from duplicate-linked sources create drafts)
  - Domain/brand (needed for the crawler UA and about page)
  - Number of admins (spec Q2)
- **TEC live endpoints and Pro recurrence IDs:** verify on 2-3 installs during source research, before Phase 4 planning.
- **Dedup thresholds** (title similarity ~0.45, minimum length 6, ±60 min): calibrate on a fixture corpus in Phase 3.
- **php-rrule v3 on PHP 8.5 / `humanReadable()` API:** cover it with our CI in Phase 3. Fall back to an in-house summariser if it breaks.
- **Umami UK GDPR region/DPA, Turnstile storage, Hetzner price, Filament 5 MFA:** check in Phase 5 (Hetzner in Phase 1).
- **Google Calendar ICS history and override behaviour:** capture real fixtures in source research. The past/far-future skip limits the damage either way.

## Sources

### Primary (HIGH confidence in content; seam tier LOW because of `webfetch`)
- Packagist p2 API, npm registry, php.net releases, packages.ubuntu.com (resolute): all versions and PHP constraints (D1)
- Laravel 13.x docs (queues, scheduling, cache, eloquent, http-client, releases) and `laravel/framework` source: queue uniqueness, `retry_after` vs timeout, database cache locks / no tags
- Filament 5.x docs: install requirements, support policy, tabs, bulk actions, custom record pages
- `sabre/vobject` v5 source: `TimeZoneUtil` fallback, `expand()` UTC default, `EventIterator`, `maxRecurrences`, Windows zone map
- The Events Calendar source: `Archive_Event.php` defaults (`start_date`, max 50, 404 past end, `X-TEC-*` headers), `Post_Repository.php` fields
- RFC 5545 (DTEND non-inclusive, floating time, RRULE UNTIL/COUNT) and RFC 9309 (longest match, 4xx/5xx, 24h cache)
- Google Search Central Event structured data (updated 2026-09-08); MDN Web Share API
- ICO DUAA 2025 guidance (updated 19 June 2026); Copyright and Rights in Databases Regulations 1997 reg. 16
- Live checks: attleboroughtownfc.co.uk/robots.txt (Squarespace defaults), eventbrite.co.uk/robots.txt and JSON-LD on `/d/` and `/e/` pages; local PHP 8.4.12 `strtotime` experiments

### Secondary (MEDIUM confidence)
- Comparable product pages: Gancio, TEC (wordpress.org + vendor), Mobilizon, Calagator, Visit Norfolk, Visit Wymondham, Eventbrite Attleborough, Patch, Diss Town Council
- Vendor pricing: Umami, Plausible, Fathom, Ploi, Forge, Cloudflare R2
- Design judgement in ARCHITECTURE.md (accepted-baseline diff, queue topology, storage split, build order)

### Tertiary (LOW confidence: validate during the named phase)
- Hetzner CX23 price and backup surcharge (Phase 1); Google Calendar ICS history, overrides and refresh latency (Phase 3); Cloudflare challenge headers (Phase 3); TEC Pro occurrence IDs and ICS URL, Squarespace event JSON-LD, Eventbrite ToS (Phase 4); dedup thresholds (Phase 3); Filament 5 MFA, Turnstile storage, WCAG SC numbering, VPS sizing (Phase 5)

Detailed research: [STACK.md](./STACK.md) · [FEATURES.md](./FEATURES.md) · [ARCHITECTURE.md](./ARCHITECTURE.md) · [PITFALLS.md](./PITFALLS.md)

---
*Research completed: 2026-10-16*
*Ready for roadmap: yes*
