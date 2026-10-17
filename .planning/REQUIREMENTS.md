# Requirements: Attleborough Events

**Defined:** 2026-10-16
**Core Value:** Residents can open one trustworthy, up-to-date calendar on their phone and see what's on in Attleborough. Nothing reaches it without admin approval.
**Baseline:** `docs/product-specification.md` v1.1, refined by `.planning/research/SUMMARY.md` (deltas D1-D20)

## v1 Requirements

### Public Calendar (CAL)

- [x] **CAL-01**: Visitor sees published, non-expired event occurrences ordered by start time and grouped under date headings, on a mobile-first list layout
- [x] **CAL-02**: Visitor can filter by Today / This weekend / This week (Europe/London; weekend = upcoming Sat 00:00-Sun 23:59, or the current weekend on Sat/Sun; week = today to Sunday 23:59). Multi-day events appear in every window they overlap
- [x] **CAL-03**: Visitor can filter by category
- [x] **CAL-04**: Visitor can filter to free events only
- [x] **CAL-05**: Visitor can filter to family-friendly events (a flag independent of the primary category)
- [x] **CAL-06**: Filters work as plain query-string links without JavaScript, and filter URLs are `noindex`
- [x] **CAL-07**: Each card shows title, date/time, venue, short description, category, price (Free / Paid / text), source or organiser, and a text "Cancelled" badge when applicable
- [x] **CAL-08**: A recurring series shows one entry per upcoming occurrence within a rolling 90-day horizon
- [ ] **CAL-09**: Public pages meet WCAG 2.2 AA (contrast, keyboard access, 24x24px targets, `<time datetime>`)

### Event Detail, Sharing & SEO (EVT)

- [x] **EVT-01**: Visitor can open an event page showing date/time, venue/address, organiser contact, source attribution with link, and the description (submissions/manual: full; discovered: excerpt of up to 200 characters plus "Read more at {source}")
- [x] **EVT-02**: Event pages have readable slug URLs plus title, meta description, canonical and Open Graph tags
- [x] **EVT-03**: Event pages include Schema.org `Event` JSON-LD (one per upcoming occurrence, capped at about 10, with `eventStatus`)
- [ ] **EVT-04**: `sitemap.xml` lists upcoming published events
- [ ] **EVT-05**: Expired event pages still load (200) with an "ended" banner, leave the sitemap, become `noindex` after 30 days, and return 410 after 12 months or when hidden/rejected as wrong; merged duplicates 301 to the kept event
- [x] **EVT-06**: Visitor can add an occurrence to their calendar via `.ics` download (stable UID) or a Google Calendar link
- [x] **EVT-07**: Visitor can share an event via the Web Share API, with WhatsApp / Facebook / email / copy-link fallbacks
- [ ] **EVT-08**: Visitor can report a problem with an event from its page (opens the contact form prefilled with the event reference)

### Outbound Feeds (FEED)

- [ ] **FEED-01**: Visitor can subscribe to a public ICS feed of all published events, and per category, with stable UIDs
- [ ] **FEED-02**: Visitor can subscribe to an RSS feed of newly published events

### Organiser Submission (SUB)

- [x] **SUB-01**: Organiser can submit an event with title, start date/time, optional end, venue/address, description, category, price, organiser name, contact email or website, optional original link, free-text "repeats" field and a consent checkbox linking to the privacy notice
- [x] **SUB-02**: A valid submission creates a `pending_review` draft (origin `submission`) that is not public, and the organiser sees a "will be reviewed" confirmation
- [x] **SUB-03**: A submission missing date or venue (or another required field) shows validation errors and creates no draft
- [ ] **SUB-04**: The form is protected by a honeypot, per-IP rate limiting and Cloudflare Turnstile

### Review Queue (REV)

- [ ] **REV-01**: Admin sees drafts of kind new / update / cancellation, each showing extracted fields, recurrence summary, source name and URL, extraction method, raw content link, quality band with reasons, parse errors and any duplicate warning with a link to the suspected event
- [x] **REV-02**: Admin can approve a draft. The event (and its occurrences) is published in one transaction, linked to the draft, with approver and time recorded. Only review actions and manual entry can write published events
- [ ] **REV-03**: Admin can edit a draft's fields and approve
- [ ] **REV-04**: Admin can reject a draft with an optional reason
- [ ] **REV-05**: Admin can mark a draft as a duplicate of an existing event. It is linked through `event_sources` and nothing new is published
- [ ] **REV-06**: Admin sees update drafts as a three-column diff (published now / source before / source now) and can apply or ignore. Both actions update the accepted baseline
- [ ] **REV-07**: Admin can confirm a cancellation draft (event or occurrence becomes cancelled) or ignore it
- [ ] **REV-08**: Admin can bulk approve and bulk reject. Bulk approve skips drafts with parse errors, duplicate warnings or Low quality
- [ ] **REV-09**: Admin can filter the queue by kind, source, quality band, duplicate flag and date
- [ ] **REV-10**: Admin can review on an "Approve & next" page with keyboard shortcuts
- [x] **REV-11**: Admin approves a recurring series once, and its occurrences are generated automatically

### Administration (ADM)

- [x] **ADM-01**: Admin can log in to a Filament admin panel (single admin account; no public accounts)
- [x] **ADM-02**: Admin can create an event manually, with simple recurrence (weekly, nth weekday of month) and a source note. It is published with origin `manual` and the admin recorded as approver
- [x] **ADM-03**: Admin can edit, hide, unhide and cancel published events and individual occurrences. Occurrence status set by the admin survives occurrence regeneration
- [ ] **ADM-04**: Admin can manage categories and a single Rules screen covering category mappings, title keyword rules, and geofence districts / place names
- [ ] **ADM-05**: Admin sees dashboard counts per period: sources checked, source failures, drafts created / approved / rejected / duplicates, and approval rate per source
- [ ] **ADM-06**: Admin can search events and drafts
- [ ] **ADM-07**: Admin can manage contact requests (problem reports, takedowns, data-protection complaints) with a visible 30-day acknowledgement deadline

### Sources & Fetching (SRC)

- [ ] **SRC-01**: Admin can create and edit sources: name, URL, extraction method, adapter, parser config, default category, default venue, location mode, default timezone, year policy, legal basis, missing-detection support, active flag, check frequency, notes
- [ ] **SRC-02**: Admin can test-run a source and preview the extracted events without creating drafts
- [ ] **SRC-03**: Admin can trigger "Run now" for a source
- [ ] **SRC-04**: The scheduler fetches each due active source automatically, never running two fetches of the same source at once
- [ ] **SRC-05**: All fetching goes through one fetcher that checks robots.txt for every URL (disallowed means a `blocked` run, not a failure), sends a descriptive User-Agent with contact URL, waits at least 5 seconds per host, uses timeouts, size limits, conditional GET and an SSRF guard
- [ ] **SRC-06**: Fetched content is validated for the extraction method. Bot walls and wrong content types count as a failed `content_invalid` run, not a successful empty run
- [ ] **SRC-07**: Each run is logged with status, items found / new / changed, skipped (past, far future, out of area), drafts created and error message
- [ ] **SRC-08**: A source becomes `degraded` after 3 consecutive failures and `unhealthy` (paused, flagged on dashboard) after 5. Zero items after previously returning items is flagged as a possible structure change
- [ ] **SRC-09**: Source failures never delete or hide previously discovered events

### Extraction Ladder (EXT)

- [ ] **EXT-01**: System extracts events from ICS feeds, including VTIMEZONE, RRULE / RDATE / EXDATE, RECURRENCE-ID overrides, all-day events and `STATUS:CANCELLED`, storing one source item per series UID
- [ ] **EXT-02**: System extracts events from WordPress sites running The Events Calendar via its REST API (explicit date window, full pagination), verified against live installs
- [ ] **EXT-03**: System extracts Schema.org `Event` JSON-LD from pages, including `@graph` wrappers, arrays, Event subtypes and `eventStatus`
- [ ] **EXT-04**: System extracts multiple events from a server-rendered listing page using a per-source CSS-selector configuration with per-source date patterns
- [ ] **EXT-05**: System extracts events from RSS/Atom feeds only when items carry event dates (`date_required`) or by following item links to JSON-LD (`link_follow`). It never uses `pubDate` as the event date. (Part of the Definition of Done only if source research finds a qualifying feed)
- [ ] **EXT-06**: Dates are parsed only with explicit per-source patterns. Ambiguous dates become parse errors. A date with no year is resolved only when a weekday is present, exactly one year in [fetch-7d, fetch+365d] matches, and the source's year policy allows it. An architecture test bans `strtotime` / `Carbon::parse` in ingestion code
- [ ] **EXT-07**: Floating or timezone-less times are interpreted in the source's default timezone (Europe/London), unknown TZIDs become parse errors, and all times are stored in UTC
- [ ] **EXT-08**: Every extractor is covered by fixture tests using saved real-world samples, including DST transition dates. Tests never touch the network

### Processing Pipeline (PIPE)

- [ ] **PIPE-01**: Past events and events more than 12 months ahead are skipped and counted, not drafted
- [ ] **PIPE-02**: Events from `filter` sources are kept only if they match postcode districts NR17, NR16, NR18 or IP24 or an allowlisted place name. Online-only events are skipped and unknown locations become flagged drafts. `local` sources skip this filter
- [ ] **PIPE-03**: Category is assigned deterministically: source default, then mapping of source values, then title keyword rules, else `Other` (flagged)
- [ ] **PIPE-04**: Each draft gets a 0-100 quality score with band (High 80+, Medium 50-79, Low <50) and the reasons behind it
- [ ] **PIPE-05**: Likely duplicates (same canonical URL, or same date + similar venue + `pg_trgm` title similarity above a configured threshold, against published events and pending drafts) produce a duplicate warning. Same source + external ID is treated as the same item
- [ ] **PIPE-06**: A changed known item creates an update draft only when its normalised fields differ from the accepted baseline. Unchanged raw content is skipped. Only one pending draft exists per source item (replaced in place)
- [ ] **PIPE-07**: Items marked cancelled at the source (`STATUS:CANCELLED`, `eventStatus: EventCancelled`) create a cancellation draft
- [ ] **PIPE-08**: An item missing from its source creates a cancellation draft only after 2 consecutive complete runs, and only if its last known start is in the future, inside the run's observed date window, and the source supports missing detection
- [ ] **PIPE-09**: Public descriptions of discovered events are excerpts of up to 200 characters with phone numbers and emails stripped. The full text is kept internally for review

### Recurrence & Time (REC)

- [x] **REC-01**: A scheduled job materialises occurrences over a rolling 90-day horizon, upserting by recurrence key and preserving admin-set occurrence status
- [x] **REC-02**: Multi-day events are one occurrence spanning several days. All-day events expire at end of day, and events with no end time expire 3 hours after start

### Privacy & Compliance (LEG)

- [ ] **LEG-01**: Visitor can read a privacy notice covering organiser contact data (legitimate interests), with a Legitimate Interests Assessment kept in the repo
- [ ] **LEG-02**: Visitor can send a takedown, correction or data-protection complaint through a public contact form
- [ ] **LEG-03**: Site uses cookieless analytics (no cookie banner, self-hosted fonts) with custom events for source clicks, add-to-calendar, shares and submissions
- [ ] **LEG-04**: Every discovered event shows visible source attribution with a link

### Platform & Operations (OPS)

- [x] **OPS-01**: Project runs on Laravel 13, PHP 8.4+ (Composer floor 8.4), PostgreSQL 18 and Filament 5, with Pest, Larastan, Pint and GitHub Actions CI running tests on PostgreSQL
- [ ] **OPS-01b**: Production runs PHP 8.5 (split from OPS-01, scope change 2026-10-16; delivered with the Phase 5 deploy)
- [ ] **OPS-02**: Site is deployed to a low-cost VPS with HTTPS, supervised queue workers, cron scheduler and zero-downtime deploys from GitHub Actions
- [ ] **OPS-03**: Nightly database backups go to off-site storage, and a restore drill has been performed before launch
- [x] **OPS-04**: Admin can see scheduler heartbeat and failed jobs. Logs are rotated (local app; app-level config)
- [ ] **OPS-04b**: In production the queue worker runs under systemd, production `.env` sets daily 14-day logs, the ops drill passes and `ops:health` is HEALTHY within 7 minutes of a deploy (split from OPS-04, scope change 2026-10-16)

### Source Research (RES)

- [x] **RES-01**: `docs/research/event-sources.md` lists verified local sources with coverage, platform, extraction method, robots status, legal basis, estimated volume and priority (P0-P3)
- [x] **RES-02**: The Events Calendar endpoints, RSS feeds with event dates, and JSON-LD presence are verified live on candidate sources
- [x] **RES-03**: Fixture samples are saved for each P0/P1 source for parser tests
- [ ] **RES-04**: Initial P0 sources are configured in production and producing drafts

## v2 Requirements

Deferred. Tracked but not in the current roadmap.

### Discovery

- **DISC-01**: Visitor can search events by title
- **DISC-02**: Visitor can filter by custom date range
- **DISC-03**: Visitor can browse venue pages and filter by village
- **DISC-04**: Third-party sites can embed a calendar widget

### Engagement

- **ENG-01**: Organiser receives an email when their submission is approved
- **ENG-02**: Visitor can subscribe to an email digest (PECR-compliant)
- **ENG-03**: Visitor can filter by structured accessibility features

### Admin & Ingestion

- **ADM2-01**: Admin can merge two published events
- **ADM2-02**: Admin can use LLM assistance to pre-fill a draft from a URL, text or poster (extractor, not publisher)
- **ADM2-03**: Multiple admins / moderator roles
- **ING2-01**: Facebook Pages via the official Graph API (if App Review is feasible)
- **ING2-02**: JavaScript-rendered sources via headless fetching

## Out of Scope

| Feature | Reason |
|---------|--------|
| LLM extraction in the pipeline | Non-deterministic, costly, invents dates; replaced by the extraction ladder |
| Facebook scraping | Prohibited by Facebook without written permission; manual entry covers it |
| Squarespace `?format=ical/json` adapter | Disallowed by Squarespace's default robots.txt |
| Poster OCR | Complexity; manual entry covers it |
| Month grid / map view | Mobile list-first is enough; map needs geocoding |
| Visitor or organiser accounts, comments, ratings | Not needed to validate demand |
| Ticketing, payments, bookings | Outside the product's purpose |
| Automated publishing | Admin approval is the trust promise |
| Hosting third-party images | Copyright risk; category placeholders instead |
| Coverage beyond Attleborough and nearby villages | Focus |
| Redis | Database queue/cache is enough at this volume |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| CAL-01 | Phase 1 | Complete |
| CAL-02 | Phase 1 | Complete |
| CAL-03 | Phase 1 | Complete |
| CAL-04 | Phase 1 | Complete |
| CAL-05 | Phase 1 | Complete |
| CAL-06 | Phase 1 | Complete |
| CAL-07 | Phase 1 | Complete |
| CAL-08 | Phase 1 | Complete |
| CAL-09 | Phase 5 | Pending |
| EVT-01 | Phase 1 | Complete |
| EVT-02 | Phase 1 | Complete |
| EVT-03 | Phase 1 | Complete |
| EVT-04 | Phase 5 | Pending |
| EVT-05 | Phase 5 | Pending |
| EVT-06 | Phase 1 | Complete |
| EVT-07 | Phase 1 | Complete |
| EVT-08 | Phase 5 | Pending |
| FEED-01 | Phase 5 | Pending |
| FEED-02 | Phase 5 | Pending |
| SUB-01 | Phase 2 | Complete |
| SUB-02 | Phase 2 | Complete |
| SUB-03 | Phase 2 | Complete |
| SUB-04 | Phase 2 | Pending |
| REV-01 | Phase 2 | Pending |
| REV-02 | Phase 2 | Complete |
| REV-03 | Phase 2 | Pending |
| REV-04 | Phase 2 | Pending |
| REV-05 | Phase 3 | Pending |
| REV-06 | Phase 3 | Pending |
| REV-07 | Phase 3 | Pending |
| REV-08 | Phase 2 | Pending |
| REV-09 | Phase 2 | Pending |
| REV-10 | Phase 2 | Pending |
| REV-11 | Phase 2 | Complete |
| ADM-01 | Phase 1 | Complete |
| ADM-02 | Phase 1 | Complete |
| ADM-03 | Phase 1 | Complete |
| ADM-04 | Phase 2 | Pending |
| ADM-05 | Phase 5 | Pending |
| ADM-06 | Phase 2 | Pending |
| ADM-07 | Phase 5 | Pending |
| SRC-01 | Phase 3 | Pending |
| SRC-02 | Phase 4 | Pending |
| SRC-03 | Phase 3 | Pending |
| SRC-04 | Phase 3 | Pending |
| SRC-05 | Phase 3 | Pending |
| SRC-06 | Phase 3 | Pending |
| SRC-07 | Phase 3 | Pending |
| SRC-08 | Phase 3 | Pending |
| SRC-09 | Phase 3 | Pending |
| EXT-01 | Phase 3 | Pending |
| EXT-02 | Phase 4 | Pending |
| EXT-03 | Phase 4 | Pending |
| EXT-04 | Phase 4 | Pending |
| EXT-05 | Phase 4 | Pending |
| EXT-06 | Phase 4 | Pending |
| EXT-07 | Phase 3 | Pending |
| EXT-08 | Phase 4 | Pending |
| PIPE-01 | Phase 3 | Pending |
| PIPE-02 | Phase 4 | Pending |
| PIPE-03 | Phase 2 | Pending |
| PIPE-04 | Phase 2 | Pending |
| PIPE-05 | Phase 3 | Pending |
| PIPE-06 | Phase 3 | Pending |
| PIPE-07 | Phase 3 | Pending |
| PIPE-08 | Phase 3 | Pending |
| PIPE-09 | Phase 3 | Pending |
| REC-01 | Phase 1 | Complete |
| REC-02 | Phase 1 | Complete |
| LEG-01 | Phase 5 | Pending |
| LEG-02 | Phase 5 | Pending |
| LEG-03 | Phase 5 | Pending |
| LEG-04 | Phase 3 | Pending |
| OPS-01 | Phase 1 | Complete |
| OPS-02 | Phase 5 | Pending |
| OPS-03 | Phase 5 | Pending |
| OPS-04 | Phase 1 | Complete |
| OPS-01b | Phase 5 | Pending |
| OPS-04b | Phase 5 | Pending |
| RES-01 | Phase 2 | Complete |
| RES-02 | Phase 2 | Complete |
| RES-03 | Phase 2 | Complete |
| RES-04 | Phase 4 | Pending |

**Coverage:**
- v1 requirements: 83 total (81 + OPS-01b, OPS-04b split out on 2026-10-16)
- Mapped to phases: 83 (Phase 1: 20, Phase 2: 19, Phase 3: 20, Phase 4: 9, Phase 5: 15)
- Unmapped: 0 ✓

---
*Requirements defined: 2026-10-16*
*Last updated: 2026-10-16 after scope change (infra/domain deferred to Phase 5; OPS-02 moved, OPS-01b/OPS-04b split out)*
