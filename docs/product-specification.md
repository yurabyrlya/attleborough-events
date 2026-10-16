# Attleborough Events - Product Specification

**Version:** 1.1 (revised baseline, October 2026)

## 1. Product Overview

**Attleborough Events** is a mobile-first public website that aggregates local events for Attleborough, Norfolk (NR17) and nearby villages.

The system discovers events from approved online sources using deterministic parsers, turns them into drafts, and sends them to an admin review queue. Approved events appear on a public calendar.

**Value proposition:** "See what's on in Attleborough in one place."

## 2. Problem

Event information is scattered across Facebook groups, posters, business, school and church websites, community pages, local news and regional directories. Residents miss events, information is outdated or duplicated, there is no consistent format, and small organisers have no easy way to reach a local audience.

## 3. Goals

1. Automatically discover events from approved sources.
2. Extract structured data deterministically (same input, same result).
3. Detect duplicates and changes to known events.
4. Queue everything as drafts for admin review.
5. Publish approved current and future events on a public calendar.

The admin's role is quality control, not searching. Events from non-parseable sources (Facebook posts, posters) are entered manually by the admin or submitted by organisers.

## 4. Non-Goals (MVP)

- Native apps, visitor accounts, comments, ratings, ticketing or payments
- Automated publishing without admin approval
- Any LLM/AI extraction, classification or summarisation
- Facebook scraping or Graph API integration
- JavaScript-rendered sources (headless browser) and poster OCR
- Coverage beyond Attleborough and nearby villages
- Notifications, digests, organiser accounts, recommendations, multi-language, advanced analytics
- Hosting third-party event images

## 5. Users

| User | Needs |
|---|---|
| **Public visitor** | See events today / this weekend / this week, filter by category, open details, contact organiser or visit source |
| **Administrator** | Review drafts fast, approve/edit/reject, enter events manually, manage sources, see source and method for each draft, see what changed on updates |
| **Organiser** | Submit an event via a public form (moderated) |

## 6. Event Discovery - The Extraction Ladder

Each source is assigned one extraction method. Use the most structured method available.

| Rung | Method | Typical sources |
|---|---|---|
| 1 | **ICS / iCalendar feed** (incl. `RRULE`, `EXDATE`, `STATUS:CANCELLED`) | Google Calendar public feeds, club/church calendars |
| 1 | **RSS / Atom feed** | News sites, CMS feeds |
| 2 | **Platform adapter** | WordPress + The Events Calendar (REST/ICS), Squarespace event collections |
| 3 | **Schema.org `Event` JSON-LD** (incl. subtypes, `eventStatus`) | Eventbrite event pages, SEO-optimised sites |
| 4 | **Per-source CSS-selector config** | Plain server-rendered HTML listing pages |

Rules:

- Only server-rendered content in the MVP.
- Nothing discovered is ever published automatically.
- Use conditional requests (`ETag` / `If-Modified-Since`); skip unchanged content (content hash).
- Parsers **reject, not guess**: an ambiguous date (e.g. no year) becomes a parse error on the draft.
- One fetched page can produce many source items and drafts.

**Future (not MVP):** Facebook Graph API, headless fetching, optional admin-triggered LLM assistance (still "extractor, not publisher").

## 7. MVP Features

### 7.1 Public Calendar

- List of published, non-expired occurrences ordered by start time.
- Date filters (Europe/London):
  - **Today:** occurrences overlapping today.
  - **This weekend:** upcoming Sat 00:00 to Sun 23:59 (current weekend if today is Sat/Sun).
  - **This week:** today to Sunday 23:59.
  - Multi-day events appear in every window they overlap. Custom range and title search optional.
- Category filter.
- Event card: title, date/time, venue, short description, category, price (Free / Paid / text), source link or organiser contact, "Cancelled" badge.
- Detail page with source attribution and link.
- SEO: readable slugs, meta/OG tags, Schema.org `Event` JSON-LD, `sitemap.xml`.
- Mobile-first; target WCAG 2.2 AA.

### 7.2 Categories

Family, Community, Market, Charity, Music, Sport, Food & Drink, School, Arts & Culture, Other. One primary category per event.

Assigned deterministically: source default, then mapping of source category values, then title keyword rules, else `Other` (flagged for review).

### 7.3 Source Registry

Each source has: name, URL, extraction method, adapter name, parser config (JSON), default category, default venue, location mode (`local` / `filter`), active flag, health, check frequency, last checked/status/error, failure count, notes (incl. robots/legal assessment).

Admin tools: **Test run** (parse and preview without creating drafts) and **Run now**.

### 7.4 Review Queue

Draft kinds: **New**, **Update** (field-level diff against the published event), **Cancellation**.

Each draft shows: extracted fields, recurrence summary, source name and URL (always visible), extraction method, raw content link, quality score with reasons, parse errors, duplicate warning with link.

Actions: Approve, Edit and approve, Reject, Mark duplicate, Apply/ignore update, Confirm/ignore cancellation, bulk approve/reject. Filters by kind, source, quality band, duplicate flag, date.

### 7.5 Organiser Submission Form

Fields: title, start date/time, optional end, venue/address, description, category, price, organiser name, contact email or website, optional original link, consent checkbox (with privacy notice link).

Creates a `pending_review` draft. Protected by honeypot, per-IP rate limiting and Cloudflare Turnstile. Shows a "will be reviewed" confirmation.

### 7.6 Administration (Filament)

- Sources, review queue, categories, category mappings, keyword rules, geofence settings
- **Manual event entry** (origin `manual`, with source note)
- Edit, hide, cancel published events
- Source run logs and dashboard counts: sources checked, failures, drafts created/approved/rejected/duplicates, approval rate per source

## 8. Business Rules

### 8.1 Publication

- Only `published` events are public.
- An event needs: title, start date/time (or all-day), venue/location, source URL or organiser contact.
- An occurrence expires at its end time (or start + 3h if no end; end of day if all-day). Expired events are hidden by default but kept.
- Cancelled events show a badge until they expire.

### 8.2 Review and Quality

- All discovered and submitted items become `pending_review` drafts. Every decision records reviewer and time.
- Quality score 0-100 from rules: extraction rung, required fields present, end time present, venue recognised, category mapped, no parse warnings. Bands: High 80+, Medium 50-79, Low <50. Reasons are always shown.

### 8.3 Duplicates

1. Same source + external ID (e.g. ICS `UID`): the same item, so handle as an update.
2. Same canonical URL.
3. Same date (and time) + similar venue + fuzzy title match (PostgreSQL `pg_trgm`).

Likely duplicates become drafts with a warning. The admin always makes the final call.

### 8.4 Updates and Cancellations

- A changed known item creates an **update draft** with a diff; pending drafts for the same item are replaced, not duplicated.
- `STATUS:CANCELLED` / `EventCancelled` creates a **cancellation draft**.
- An item missing from its source for 2 consecutive successful runs creates a cancellation draft (never auto-cancelled).
- Source failures never delete or hide events.

### 8.5 Recurring and Multi-Day

- ICS recurrence supported (`RRULE`, `RDATE`, `EXDATE`, overrides). A series is approved once; occurrences are expanded over a rolling 90-day horizon.
- Single occurrences can be cancelled or hidden.
- Multi-day events are one event with one long occurrence.

### 8.6 Location

- `local` sources: all events assumed in area.
- `filter` sources (regional directories): geofence, default 8 miles from Attleborough centre or postcode districts (default NR17) / place names. Out-of-area items are skipped and counted; unknown locations are drafted with a flag.

### 8.7 Sources and Crawling

- Only registered sources are processed.
- Respect `robots.txt` (e.g. Eventbrite disallows `/rss/` and `/atom/`; event pages are allowed).
- Descriptive User-Agent with contact URL, minimum 5s between requests per host, timeouts and size limits.
- 3 consecutive failures: `degraded`; 5: `unhealthy` (paused, flagged on dashboard). Zero results after previous results: flagged as possible structure change.
- No Facebook scraping.

### 8.8 Content, Privacy, Legal

- Store factual details with attribution and a source link; truncate discovered descriptions (default 500 chars); no third-party images (category placeholders instead).
- Organiser contacts can be personal data: legitimate interests basis with a short assessment, public privacy notice, takedown/correction contact.
- Future marketing email to organisers must comply with PECR.
- Cookieless analytics (Plausible, Fathom or Umami); no cookie banner.

## 9. Data Model

All timestamps stored in UTC, shown in Europe/London.

| Table | Key fields |
|---|---|
| `sources` | name, url, extraction_method, adapter, parser_config, default_category_id, default_venue_id, location_mode, active, health, check_frequency, last_checked_at, last_status, last_error, failure_count, etag, last_modified, notes |
| `source_runs` | source_id, started/finished_at, status (success / failed / not_modified), items_found/new/changed/out_of_area, drafts_created, error_message |
| `source_items` | source_id, external_id, url, content_hash, raw_content, first/last_seen_at, missing_since, processed_at, status. Unique on (source_id, external_id) |
| `event_drafts` | origin (discovered / submission / manual), kind (new / update / cancellation), source_item_id, event_id, event fields (title, description, starts_at, ends_at, all_day, recurrence_rule, venue, address, postcode, category_id, price_type, price_text, organiser, contact, source_url), extraction_method, quality_score, quality_reasons, parse_errors, diff, duplicate_of_event_id, review_status (pending_review / approved / rejected / duplicate / superseded), reviewed_by/at, review_notes |
| `events` | title, slug, description, timezone, all_day, recurrence_rule, venue_id, venue_name, address, postcode, lat/lng, category_id, price_type, price_text, organiser, contact, primary_source_id, source_url, external_id, status (published / hidden / cancelled), approved_by/at |
| `event_occurrences` | event_id, starts_at, ends_at, status (scheduled / cancelled / hidden) |
| `event_sources` | event_id, source_item_id, draft_id, relationship (primary / duplicate / update), for traceability |
| `categories`, `category_mappings`, `category_keyword_rules`, `venues` (name, aliases, address, postcode, lat/lng) | Supporting tables |

## 10. Architecture Principles

- **Laravel-first** with built-in scheduler, queues and Eloquent.
- **Simple frontend:** Blade, Tailwind, Alpine.js, Livewire. No React/Inertia.
- **Admin-first quality control:** clear draft cards, visible source link, quality reasons, diffs, one-click and bulk actions.
- **Traceability:** every event links to its source(s), raw items, method, review decision and approver.
- **Deterministic extraction:** fixture-based tests with saved real samples for every parser; fail loudly.
- **Modular components:** `HttpFetcher`, `IcsParser`, `FeedParser`, `TheEventsCalendarAdapter`, `SquarespaceEventsAdapter`, `JsonLdEventParser`, `SelectorParser`, `EventNormalizer`, `LocationFilter`, `QualityScorer`, `DuplicateDetector`, `ChangeDetector`.

## 11. Technical Stack

| Layer | Choice |
|---|---|
| Backend | Laravel (current stable), PHP 8.3+ |
| Database | PostgreSQL + `pg_trgm`, full-text search |
| Frontend | Blade, Tailwind CSS, Alpine.js, Livewire |
| Admin | Filament |
| Jobs | Laravel Queues (database driver; Redis optional later), Laravel Scheduler |
| ICS | `sabre/vobject` (+ `rlanvin/php-rrule` if needed) |
| Feeds | Maintained RSS/Atom parser (chosen during planning) |
| JSON-LD | `brick/structured-data` (pre-1.0, pin version) or small in-house parser |
| HTML selectors | Symfony DomCrawler + CssSelector |
| JSON-LD output | `spatie/schema-org` |
| Spam | Honeypot, rate limiting, Cloudflare Turnstile |
| Analytics | Cookieless (Plausible / Fathom / Umami) |
| Hosting | VPS or managed Laravel hosting (Railway, Fly.io, Laravel Cloud) |

## 12. System Flow

```text
Source Registry -> Scheduler -> FetchSourceJob -> HttpFetcher (robots, ETag, rate limit)
   -> Parser by rung (ICS | RSS | Adapter | JSON-LD | Selectors)
   -> Source Items -> ProcessSourceItemJob
   -> Normalizer -> LocationFilter -> QualityScorer -> ChangeDetector + DuplicateDetector
   -> Draft (new | update | cancellation)        <- Organiser submission form
   -> Filament Review Queue
   -> Approve / Edit / Reject / Duplicate / Apply update / Confirm cancel
   -> Published event + occurrences              <- Admin manual entry
```

## 13. Key Acceptance Criteria

```gherkin
Scenario: View upcoming events
  Given published future events exist
  When I open the calendar
  Then I see occurrences ordered by start time with title, date, time, venue and category

Scenario: Weekend filter
  When I select "This weekend"
  Then I only see occurrences overlapping the upcoming Saturday or Sunday

Scenario: Recurring event
  Given a published market recurs every Friday
  Then the calendar shows one entry per Friday within the horizon

Scenario: Expired and cancelled events
  Given one event has finished and another future event is cancelled
  Then the finished event is hidden by default
  And the cancelled event shows a "Cancelled" badge

Scenario: Discover from ICS
  Given an active ICS source
  When the scheduled job runs
  Then new VEVENTs become source items and pending_review drafts

Scenario: Listing page with many events
  Given a selector source lists 12 events
  When the job runs
  Then 12 source items are created and a draft for each new in-area event

Scenario: Unchanged source
  Given the source returns 304 or identical content
  Then no drafts are created and the run is logged "not_modified"

Scenario: Source fails
  Given a source is unavailable
  Then the failure is recorded, the failure count increments, and no events are removed

Scenario: Ambiguous date
  Given an item's date has no determinable year
  Then the draft has a parse error and Low quality

Scenario: Approve
  Given a valid draft
  When I click "Approve"
  Then the event is public, linked to the draft, with my user and time recorded

Scenario: Update
  Given a published event's source changes its start time
  Then an update draft shows old vs new
  And "Apply update" changes the published event

Scenario: Duplicate
  Given a published event with the same date, venue and similar title
  When another source yields it
  Then a draft with a duplicate warning is created and nothing is auto-published

Scenario: Submission
  Given I complete the form and pass the spam check
  Then a pending_review draft is created, not public, and I see a confirmation
  And missing date or venue shows validation errors and creates nothing

Scenario: Manual entry
  When the admin creates an event with required fields and a source note
  Then it is published with origin "manual" and the admin recorded as approver
```

## 14. Source Research Phase

Before configuring sources, produce `docs/research/event-sources.md`.

Automated research (October 2026) could not find most local URLs because web search was blocked, so this phase **combines agent research with manual URL collection by the owner**.

**Research:** Town Council, Breckland Council, schools, churches, halls (e.g. Connaught Hall), sports clubs (Attleborough Town FC is on Squarespace), charities (Rotary, Lions), businesses, farms, pubs and venues, library and heritage centre, nearby attractions (Snetterton is JS-rendered, so likely deferred), Norfolk directories, Eventbrite (low Attleborough volume seen), TicketSource, local news, Facebook Pages/Groups (recorded for manual entry only), any site with ICS/RSS/JSON-LD.

**Record per source:** name, verified URL, coverage, platform, ladder rung, server-rendered (y/n), frequency, est. events/month, access method, robots status, feasibility, legal risk, priority, example event URL, notes.

**Priorities:**

- **P0:** trusted, regular local events with a rung 1-3 method.
- **P1:** local sources needing selectors (rung 4).
- **P2:** regional directories (geofenced), low-volume or complex sources.
- **P3:** Facebook, posters, JS-rendered, high risk. Manual entry only.

**Verify against live sites before building:** The Events Calendar REST endpoint and ICS URL (2-3 installs); Squarespace `?format=ical` / `?format=json`; TicketSource JSON-LD and robots rules; library maintenance status.

**Output also includes:** excluded sources with reasons, technical and legal risks, rung and config notes per P0/P1 source, saved fixture samples for parser tests.

## 15. Success Metrics

**Product (first 4-8 weeks):** unique and returning visitors, event views per session, traffic from local shares, submissions, organiser inclusion requests, published events per week by origin.

**Operational:** drafts and approval rate per source, % rejected as irrelevant, % duplicates, median review time, source failures and structure-change alerts.

**MVP is validated if:** consistent traffic, many visitors view more than one event, organisers start submitting, and the admin maintains the calendar in under 2 hours per week.

## 16. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Few sources have structured data | High | Adapters, selectors, manual entry, submissions; verify inventory in research |
| Much activity is Facebook-only | High | Admin manual entry; promote submission form; revisit Graph API later |
| Incorrect extraction | High | Review queue, fixture-tested deterministic parsers, parse errors not guesses |
| HTML structure changes | Medium | Prefer feeds/adapters; zero-result and failure alerts; test-run tool |
| Duplicates / stale events | Medium | ID, URL and fuzzy matching; update and cancellation drafts |
| Out-of-area events | Medium | Geofence per source |
| Form spam | Medium | Honeypot, rate limit, Turnstile, moderation |
| No public interest | High | Launch small, measure, promote locally |
| Content reuse / personal data | Medium | Factual extracts, attribution, no images, privacy notice, takedown route, robots.txt |

## 17. Definition of Done

1. Mobile visitors see upcoming Attleborough events, filterable by date and category.
2. Detail pages are accessible and SEO-friendly, with `Event` JSON-LD.
3. ICS (with recurrence), RSS/Atom, JSON-LD, selector sources and at least one verified platform adapter work.
4. Discovered events only reach the public site through the review queue.
5. Admin can approve, edit, reject, mark duplicates, apply updates, confirm cancellations and enter events manually.
6. Every discovered event shows source attribution and URL.
7. The submission form creates pending drafts and is spam-protected.
8. Source runs, failures and unhealthy sources are logged and visible.
9. Privacy notice, takedown contact and cookieless analytics are in place.

## 18. Open Questions

1. Hosting platform.
2. Number of admins at launch.
3. Geofence radius and included villages.
4. Domain name and brand.
5. Analytics provider.
6. Recurrence horizon (default 90 days).
