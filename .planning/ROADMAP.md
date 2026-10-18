# Roadmap: Attleborough Events

## Overview

The build starts with a local-dev public calendar fed by admin manual entry, with CI on PostgreSQL, so the published model, recurrence and DST handling, and app-level ops visibility are proven before anything is crawled. Infrastructure (domain registration, Cloudflare DNS, the Hetzner VPS, provisioning and zero-downtime deploys) is deferred to Phase 5 (scope change 2026-10-16); the brand domain whatsonattleborough.co.uk stays the intended name. Next comes the draft model and a fast review queue, fed first by organiser submissions, while the owner finishes the local source inventory. Then the ingestion backbone arrives with ICS as the first rung, including polite fetching, source health, and update/cancellation/duplicate handling. After that, the rest of the extraction ladder (The Events Calendar, JSON-LD, CSS selectors, RSS) plus the geofence and test-run tooling, ending with real P0 sources producing drafts in production. The last phase covers launch readiness: SEO lifecycle, outbound feeds, legal and complaints handling, analytics, dashboard, accessibility and a backup restore drill. Each phase ends with something usable and verifiable on the local app with green CI; Phase 5 takes it live.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Foundation & Published Calendar** - Local-dev mobile-first calendar (CI on PostgreSQL) fed by admin manual entry, with recurrence, detail pages, add-to-calendar and share (completed 2026-10-17)
- [x] **Phase 2: Draft Model, Review Queue & Submissions** - Organiser submissions become drafts that the admin triages in a fast review queue; source research completed (completed 2026-10-17)
- [ ] **Phase 3: Ingestion Backbone, ICS & Change/Duplicate Handling** - Polite scheduled fetching of ICS sources producing new/update/cancellation drafts with duplicate warnings and source health
- [ ] **Phase 4: Extraction Ladder, Geofence & Live Sources** - TEC, JSON-LD, selector and RSS rungs, geofence and test run; P0 sources producing drafts in production
- [ ] **Phase 5: Launch Readiness** - Domain, VPS and zero-downtime deploy (deferred from Phase 1), production ops, SEO lifecycle, public feeds, privacy and complaints, analytics, dashboard, accessibility and restore drill

## Phase Details

### Phase 1: Foundation & Published Calendar

**Goal**: Residents can open a mobile-first calendar on the local app and browse admin-entered events (including recurring ones), open event pages, add them to their calendar and share them
**Depends on**: Nothing (first phase)
**Requirements**: OPS-01, OPS-04, CAL-01, CAL-02, CAL-03, CAL-04, CAL-05, CAL-06, CAL-07, CAL-08, EVT-01, EVT-02, EVT-03, EVT-06, EVT-07, REC-01, REC-02, ADM-01, ADM-02, ADM-03
**Success Criteria** (what must be TRUE):
  1. A visitor on a phone (or mobile emulation) using the local app sees upcoming published occurrences grouped under date headings. They can narrow the list with Today / This weekend / This week, category, Free and family-friendly filters that work as plain links with JavaScript disabled, and the filter URLs are `noindex`.
  2. The admin logs in to Filament and creates a manual event repeating weekly or on the nth weekday of the month. It appears once per upcoming occurrence over the next 90 days. Multi-day events appear in every window they overlap. Finished occurrences drop off at end time, start + 3h, or end of day for all-day events, with correct local times across the 25 Oct 2026 clock change.
  3. The admin can edit, hide, unhide or cancel a whole event or a single occurrence. Cancelled items show a text "Cancelled" badge, and occurrence statuses set by the admin survive the scheduled occurrence regeneration.
  4. A visitor opens an event page with a readable slug, title/meta/canonical/OG tags, Schema.org `Event` JSON-LD per upcoming occurrence, and the full description and attribution. From there they can add an occurrence to their calendar (`.ics` with stable UID, or a Google Calendar link) and share it via Web Share or WhatsApp/Facebook/email/copy-link fallbacks.
  5. Pushing to `main` runs Pest, Larastan and Pint in CI on PostgreSQL (CI only; deploys are Phase 5). On the local app the admin can see the scheduler heartbeat and failed jobs, and logs rotate daily (14 days).

**Plans**: TBD
- [x] 01-01-PLAN.md
- [x] 01-04-PLAN.md
- [x] 01-05-PLAN.md
- [x] 01-06-PLAN.md
- [x] 01-07-PLAN.md
- [x] 01-08-PLAN.md
- [x] 01-09-PLAN.md
- [x] 01-10-PLAN.md
- [x] 01-11-PLAN.md (gap closure: local Boost script gap, CR-01 Host-header URLs, WR-04/05/08)
- [x] 01-14-PLAN.md (gap closure: test fixture alignment for stricter publish validation)
- [x] 01-12-PLAN.md (gap closure: publish write path WR-01/02/03/07)
- [x] 01-13-PLAN.md (gap closure: UID stability WR-06, edit-page resilience WR-09)
- [x] 01-15-PLAN.md (gap closure: WR-10 RRULE round-trip check, WR-11 occurrence status not fillable)

**UI hint**: yes

### Phase 2: Draft Model, Review Queue & Submissions

**Goal**: Organisers can submit events, and the admin can triage every incoming draft quickly through one review queue that is the only path to publication. By the end, the local source inventory is verified and fixtures are saved.
**Depends on**: Phase 1
**Requirements**: SUB-01, SUB-02, SUB-03, SUB-04, REV-01, REV-02, REV-03, REV-04, REV-08, REV-09, REV-10, REV-11, PIPE-03, PIPE-04, ADM-04, ADM-06, RES-01, RES-02, RES-03
**Success Criteria** (what must be TRUE):
  1. An organiser completes the public form (including the "repeats" field and the consent checkbox linking to the privacy notice) and sees a "will be reviewed" confirmation, while the event stays off the public site. A submission missing date or venue shows validation errors and creates nothing. Bot submissions are stopped by the honeypot, per-IP rate limiting and Turnstile.
  2. The admin opens the review queue and sees each draft's fields, recurrence summary, source, quality band with reasons, deterministically assigned category (or a flagged `Other`) and parse errors. They can filter by kind, source, band, duplicate flag and date, search events and drafts, and adjust categories, mappings and keyword rules on one Rules screen.
  3. The admin can approve, edit-and-approve, or reject (with an optional reason) a draft. A recurring series is approved once and its occurrences appear automatically. Every published event records the approving admin and time, and manual entry publishes through the same review path.
  4. The admin can work through the queue on an "Approve & next" page with keyboard shortcuts, and can bulk approve and bulk reject. Bulk approve visibly skips drafts with parse errors, duplicate warnings or Low quality.
  5. `docs/research/event-sources.md` lists verified local sources with priority P0-P3, platform, extraction method, robots status and legal basis. TEC endpoints, dated RSS feeds and JSON-LD presence are verified live, and fixture samples are saved for every P0/P1 source.

**Plans**: 16/16 plans complete

Plans:
**Wave 1**
- [x] 02-01-PLAN.md — Tracer: manual entry through a draft + ApproveDraft choke point, event_drafts schema (wave 1)
- [x] 02-02-PLAN.md — Source research: two-list event-sources.md, live ladder probes, owner URL hand-off, redacted P0/P1 fixtures (wave 1)

**Wave 2** *(blocked on Wave 1 completion)*
- [x] 02-03-PLAN.md — Approval contract: blockers, bulk skip keys, DST series, category endpoints, choke-point arch test (wave 2)
- [x] 02-04-PLAN.md — Organiser contact choice: events.contact_public / organiser_website, admin form, public pages (wave 2)
- [x] 02-05-PLAN.md — Public /submit form, validation, /privacy, venue reveal, footer links (wave 2)

**Wave 3** *(blocked on Wave 2 completion)*
- [x] 02-06-PLAN.md — Reject / reopen and the 30/90-day rejected-draft purge (wave 3)
- [x] 02-07-PLAN.md — Keyword rules in the category chain + Rules screen (categories, keyword rules) (wave 3)
- [x] 02-08-PLAN.md — Quality score/band/reasons and venue matching (wave 3)
- [x] 02-09-PLAN.md — Spam defence: honeypot, timing token, Turnstile (fail closed), third-party guard (wave 3)
- [x] 02-10-PLAN.md — Per-IP rate limit + 429 page, D-17 rule text, test keys, spam-check health entry (wave 3)

**Wave 4** *(blocked on Wave 3 completion)*
- [x] 02-11-PLAN.md — Rules screen: category mappings, geofence terms, RulesSeeder (wave 4)
- [x] 02-12-PLAN.md — Review queue: draft detail, approve/reject/reopen, ordering + next/previous, global search (wave 4)

**Wave 5** *(blocked on Wave 4 completion)*
- [x] 02-13-PLAN.md — Queue tabs, filters, columns, bulk approve (named skips) / bulk reject (wave 5)
- [x] 02-14-PLAN.md — Edit-and-approve (shared form sections, venue confirm/create, repeats translation) (wave 5)

**Wave 6** *(blocked on Wave 5 completion)*
- [x] 02-15-PLAN.md — "Approve & next" with guarded keyboard shortcuts (wave 6)

**Wave 7** *(blocked on Wave 6 completion)*
- [x] 02-16-PLAN.md — Browser UAT flows F11-F14 in the runbook, automated in tests/Browser (wave 7)

**UI hint**: yes

### Phase 3: Ingestion Backbone, ICS & Change/Duplicate Handling

**Goal**: The admin can register ICS sources that are fetched politely on a schedule. Each run turns new, changed, cancelled and missing items into the right drafts with duplicate warnings, and source health is visible. Nothing published is ever lost to a source failure.
**Depends on**: Phase 2
**Requirements**: SRC-01, SRC-03, SRC-04, SRC-05, SRC-06, SRC-07, SRC-08, SRC-09, EXT-01, EXT-07, PIPE-01, PIPE-05, PIPE-06, PIPE-07, PIPE-08, PIPE-09, REV-05, REV-06, REV-07, LEG-04
**Success Criteria** (what must be TRUE):
  1. The admin registers an ICS source and clicks "Run now", or waits for the scheduler. New future events appear as pending drafts: one per series UID, with correct Europe/London times across DST, overrides and exclusions. Past and far-future (>12 months) events are skipped and counted in the logged run.
  2. Re-running an unchanged calendar creates zero drafts. When the source moves a start time, the admin sees an update draft as a three-column diff (published now / source before / source now) and can apply or ignore it. A `STATUS:CANCELLED` event, or a future in-window item missing for 2 complete runs, produces a cancellation draft the admin can confirm or ignore.
  3. When an incoming event matches a published event or pending draft (same canonical URL, or same date + similar venue + similar title), the draft shows a duplicate warning linking the suspected event. The admin can mark it as a duplicate without anything new being published.
  4. Every fetch respects robots.txt (a disallow shows as a `blocked` run), sends the descriptive User-Agent and waits at least 5s per host. A bot wall or wrong content type is logged as a failed `content_invalid` run. After 3 / 5 consecutive failures the source shows as degraded / unhealthy (paused), a sudden zero-items run is flagged, and no previously published event disappears.
  5. Approved discovered events show visible source attribution with a link, plus an excerpt of at most 200 characters with phone numbers and emails stripped and a "Read more at {source}" link.

**Plans**: 3/17 plans executed

Plans:
**Wave 1**
- [x] 03-01-PLAN.md — Ingestion schema: sources, source_runs, source_items, draft/event columns, run enums, Source factory (wave 1)
- [x] 03-02-PLAN.md — HttpFetcher core: UA, timeouts, size cap, manual redirects, conditional GET, SSRF guard, body validation (wave 1)

**Wave 2** *(blocked on Wave 1 completion)*
- [x] 03-03-PLAN.md — Robots.txt (in-house RFC 9309), per-host throttle with Crawl-delay, raw body store (wave 2)
- [ ] 03-04-PLAN.md — ICS extractor: one item per identity, DST/TZID rules, D-19 recurrence scope, hashes, 90-day window (wave 2)

**Wave 3** *(blocked on Wave 2 completion)*
- [ ] 03-05-PLAN.md — Run pipeline end to end: job → fetch → extract → pending drafts, run counters, excerpts, title rules (wave 3)

**Wave 4** *(blocked on Wave 3 completion)*
- [ ] 03-06-PLAN.md — Run now + daily scheduler, one run per source, janitor, raw pruning (wave 4)
- [ ] 03-08-PLAN.md — Approve discovered drafts: event_sources links, accepted baseline, EXDATE publishing, rejected stays rejected (wave 4)

**Wave 5** *(blocked on Wave 4 completion)*
- [ ] 03-07-PLAN.md — Source health (3/5 failures, structure alert, abandoned runs), test-run preview, Create drafts with body reuse (wave 5)

**Wave 6** *(blocked on Wave 5 completion)*
- [ ] 03-09-PLAN.md — Change detection: update drafts with diff, cancellations, missing items over complete runs (wave 6)
- [ ] 03-15-PLAN.md — Filament Sources resource: form, Test run / Create drafts / Run now / Pause, preview, run log, health (wave 6)
- [ ] 03-16-PLAN.md — Public attribution ("Listed by", "Also listed by", "Read more at", card "via") + SourceSeeder (wave 6)

**Wave 7** *(blocked on Wave 6 completion)*
- [ ] 03-10-PLAN.md — Apply update / Ignore / Confirm cancellation actions (wave 7)
- [ ] 03-14-PLAN.md — Per-source category mappings (Rules screen, SaveRules, CategoryAssigner) (wave 7)

**Wave 8** *(blocked on Wave 7 completion)*
- [ ] 03-11-PLAN.md — Duplicate detection, Mark as duplicate, Not a duplicate memory (wave 8)

**Wave 9** *(blocked on Wave 8 completion)*
- [ ] 03-12-PLAN.md — Queue: Approve all like this, urgent-first order, source filter on sources (wave 9)

**Wave 10** *(blocked on Wave 9 completion)*
- [ ] 03-13-PLAN.md — Review page: three-column diff, cancellation and duplicate panels, kind-aware actions (wave 10)

**Wave 11** *(blocked on Wave 10 completion)*
- [ ] 03-17-PLAN.md — Browser UAT flows F15-F20 in the runbook, automated in tests/Browser (wave 11)

**UI hint**: yes

### Phase 4: Extraction Ladder, Geofence & Live Sources

**Goal**: Every rung of the deterministic extraction ladder works and regional sources are geofenced to the Attleborough area. The admin can preview any source safely, and the real P0 sources are producing drafts in production.
**Mode:** mvp
**Depends on**: Phase 3
**Requirements**: EXT-02, EXT-03, EXT-04, EXT-05, EXT-06, EXT-08, PIPE-02, SRC-02, RES-04
**Success Criteria** (what must be TRUE):
  1. The admin can add a WordPress site running The Events Calendar, a page with Schema.org `Event` JSON-LD (e.g. an Eventbrite event) and a server-rendered listing page with a CSS-selector config as sources. Each produces correct drafts: TEC across the full date window and all pages, JSON-LD including `@graph`, subtypes and `eventStatus`, and a selector listing of 12 events giving 12 source items. An RSS/Atom feed works too if research found one carrying event dates.
  2. The admin can test-run any source and preview what it would extract (events, skips and parse errors) without creating a single draft.
  3. A listing date with an ambiguous format, or with no year where the year can't be resolved by the rules, arrives as a draft with a parse error and Low quality instead of a guessed date. Every extractor passes offline fixture tests built from real samples, including DST dates.
  4. Events from a regional (`filter`) source outside NR17 / NR16 / NR18 / IP24 and the allowlisted place names are skipped and counted, online-only events are skipped, and events with unknown locations arrive as flagged drafts.
  5. The initial P0 sources from `docs/research/event-sources.md` are configured in production and producing pending drafts on schedule.

**Plans**: TBD

### Phase 5: Launch Readiness

**Goal**: The site is discoverable, legally compliant, measurable and operationally safe enough to launch publicly in Attleborough
**Mode:** mvp
**Depends on**: Phase 4
**Requirements**: EVT-04, EVT-05, EVT-08, CAL-09, FEED-01, FEED-02, LEG-01, LEG-02, LEG-03, ADM-05, ADM-07, OPS-03, OPS-02, OPS-01b, OPS-04b
**Success Criteria** (what must be TRUE):
  1. A visitor can read the privacy notice and send a takedown, correction or data-protection complaint through the contact form, or use "Report a problem" from an event page with the event reference prefilled. The admin manages these requests with a visible 30-day acknowledgement deadline.
  2. `sitemap.xml` lists upcoming published events. An expired event page still loads with an "ended" banner, becomes `noindex` after 30 days, and returns 410 after 12 months or when hidden or rejected as wrong. Merged duplicates 301 to the kept event.
  3. A visitor can subscribe to a public ICS feed (all events or per category) and an RSS feed of newly published events, both with stable identifiers.
  4. The admin dashboard shows per-period counts: sources checked, failures, drafts created / approved / rejected / duplicates, and approval rate per source. Cookieless analytics (no cookie banner) records source clicks, add-to-calendar, shares and submissions.
  5. Public pages pass a WCAG 2.2 AA check (automated and manual screen-reader pass). Nightly off-site backups run, and a full restore drill has succeeded.
  6. whatsonattleborough.co.uk is registered, on Cloudflare DNS (DNS-only) with hello@ email routing, and serves the app over HTTPS from the hardened Hetzner VPS (PHP 8.5, PostgreSQL 18, Caddy, apex canonical with www 301). Pushing to `main` deploys with zero downtime after CI passes (built assets uploaded from CI; rollback documented), and the production admin exists. *(Deferred from Phase 1.)*
  7. In production the systemd queue worker and cron scheduler run, `ops:health` reports HEALTHY within 7 minutes of a deploy, the ops drill (failed job visible, then retried) passes, production logs rotate daily for 14 days, and the event page/share/.ics checks and Rich Results Test pass against the live URL. *(Deferred from Phase 1.)*

**Plans**: 2 planned so far (more added when Phase 5 is planned)
- [ ] 05-01-PLAN.md (server provisioning + owner infra checkpoints; moved from 01-02)
- [ ] 05-02-PLAN.md (zero-downtime deploy + production ops checks; moved from 01-03)

**UI hint**: yes

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Foundation & Published Calendar | 13/13 | Complete    | 2026-10-17 |
| 2. Draft Model, Review Queue & Submissions | 16/16 | Complete    | 2026-10-17 |
| 3. Ingestion Backbone, ICS & Change/Duplicate Handling | 3/17 | In Progress | - |
| 4. Extraction Ladder, Geofence & Live Sources | 0/TBD | Not started | - |
| 5. Launch Readiness | 0/2+ | Not started | - |

## Planning Notes

- **Research flags:** Phase 3 (sabre/vobject 5 typed API and `failIfUncertain`, spatie/robots-txt matching semantics, Laravel 13 database cache locks for the host throttle, Google Calendar ICS behaviour) and Phase 4 (live TEC REST/ICS on Norfolk installs, Eventbrite ToS, real UK date formats from P1 sources) should use `--research-phase`. Phases 1, 2 and 5 follow standard patterns.
- **Source research track:** RES-01..03 is mostly owner-collected URLs plus live checks. It runs alongside Phases 1-2 and must be complete before Phase 3 starts, because ICS and later rungs need real fixtures.
- **EXT-05 (RSS/Atom)** is only part of the Definition of Done if source research finds a qualifying feed. Otherwise it ships as "supported, unused" with fixture tests.
- **Split rule:** if Phase 3 proves too large during planning, split out "Change & Duplicates" (REV-05..07, PIPE-05..08) as an inserted Phase 3.1 rather than re-cutting the other phases.
- **Scope change 2026-10-16:** infrastructure/domain deferred to Phase 5 (plans 01-02/01-03 moved to 05-01/05-02). Phase 1 is local-dev + CI only. Note: Phase 4 success criterion 5 and RES-04 say "in production"; until Phase 5 is live, P0 sources run on the local app (see STATE concerns).

## Backlog

### Phase 999.1: Follow-up — Phase 01 deferred UAT follow-up: Tests 8, 9 (RESOLVED 2026-10-17)

**Goal:** Resolve the UAT checkpoints deferred during Phase 01 verification
**Source phase:** 01
**Deferred at:** 2026-10-17 during /gsd-verify-work 1 session completion
**Follow-ups:**
- [x] Test 8: Acknowledge the 5 insufficient_spec backstops (row-lock serialisation x2, heartbeat upsert, share.js re-entry, ZWJ excerpt cut) or add concurrency tests (deferred 2026-10-17) — owner accepted all 5 as-is 2026-10-17
- [x] Test 9: Review the 4 flagged prohibitions (accept, or add Pest arch/composer.json enforcement tests); owner: "do it at the end once we finish all phases" (deferred 2026-10-17) — owner accepted all 4 as-is 2026-10-17
