# Attleborough Events

## What This Is

A mobile-first public website that shows "what's on" in Attleborough, Norfolk (NR17) and nearby villages. It automatically discovers events from registered online sources using deterministic parsers (no LLM), queues them as drafts for a single admin to review, and publishes approved events on a filterable public calendar. Organisers can submit events, and the admin can enter events manually from non-parseable sources such as Facebook posts and posters.

Baseline specification: `docs/product-specification.md` (v1.1).

## Core Value

Residents can open one trustworthy, up-to-date calendar on their phone and see what's on in Attleborough. Nothing reaches it without admin approval.

## Business Context

- **Customer**: Attleborough residents and nearby villages (free public site); local organisers as contributors
- **Revenue model**: None for MVP (validation project)
- **Success metric**: Consistent traffic with multiple events viewed per session, and the admin maintaining the calendar in under 2 hours per week
- **Strategy notes**: See `docs/product-specification.md` sections 15-16

## Requirements

### Validated

- ✓ Public mobile-first calendar of published, non-expired event occurrences with Today / This weekend / This week, category, Free and family-friendly filters (no-JS links, noindex filter URLs) — Phase 1
- ✓ Admin manual event entry (Filament, single admin, writes only via `App\Publishing\Actions`, hide / cancel / per-date status) — Phase 1
- ✓ Deterministic category assignment: organiser choice → source mapping → whole-word keyword rules → flagged Other, managed on one Rules screen with geofence terms — Phase 2
- ✓ Public organiser submission form with spam protection (honeypot, single-use timing token, Cloudflare Turnstile on `/submit` only, per-IP rate limit, no-JS email route, interim privacy notice) — Phase 2
- ✓ Source research: verified, prioritised inventory (`docs/research/source-inventory.md`, evidence in `event-sources.md`) with redacted P0/P1 fixtures (Attleborough Primary fixture captured 2026-10-18) — Phase 2
- ✓ Source Registry: Filament Sources form (identity and time zone frozen once a source has items, "?" field help), Test run preview → Create drafts, Run now, Pause, daily scheduler that never runs two fetches of one source, per-run log, health (degraded after 3 failures, unhealthy after 5, structure-change alert); source failures never hide events (SRC-01, SRC-03..SRC-09; SRC-02 preview delivered for ICS only, every-rung preview in Phase 4) — Phase 3
- ✓ Robots-aware polite fetching through one fetcher: robots.txt on every hop (`blocked` runs), descriptive User-Agent, ≥5 s per host plus Crawl-delay, timeouts, size cap, conditional GET, SSRF guard with DNS pin; bot walls and wrong content are `content_invalid` runs (SRC-05, SRC-06) — Phase 3
- ✓ ICS extraction with recurrence: VTIMEZONE, RRULE/EXDATE, all-day, `STATUS:CANCELLED`, one source item per series UID; repeat rules published only when they round-trip the admin form (EXT-01 within D-19: RDATE additions and moved RECURRENCE-ID overrides go to manual review as parse errors); floating times in Europe/London, unknown TZIDs are parse errors (EXT-07); past and beyond-90-day items skipped and counted (PIPE-01) — Phase 3
- ✓ Unified draft model: new / update / cancellation drafts with quality reasons, one pending draft per source item, update drafts only when resident-facing facts differ from the accepted baseline (PIPE-06) — Phase 3
- ✓ Filament review queue: approve, edit, reject, reopen, mark duplicate / not a duplicate, three-column diff with Apply / Ignore, Confirm / Ignore cancellations (all moving the accepted baseline), Urgent (48 h) sorting, bulk actions and "Approve all like this" (REV-05..REV-07) — Phase 3
- ✓ Duplicate detection: same source + external ID is the same item; canonical URL or same date + similar venue + `pg_trgm` title similarity warns side by side; admin decides (PIPE-05) — Phase 3
- ✓ Update and cancellation handling, including missing-item detection after 2 consecutive complete runs inside the observed window (PIPE-07 ICS half, PIPE-08; JSON-LD `eventStatus` cancellations arrive with that rung in Phase 4) — Phase 3
- ✓ Discovered events show source attribution with a link ("Listed by" / "Also listed by", "via" on cards) and a ≤200-character excerpt with phones and emails stripped; feed organiser names and contacts never published (PIPE-09, LEG-04, D-17) — Phase 3

### Active

- [ ] Event detail pages with source attribution, SEO metadata, Schema.org `Event` JSON-LD and sitemap (Phase 1 shipped the page, SEO metadata, JSON-LD, .ics / Google Calendar and share; Phase 3 added source attribution; sitemap remains)
- [ ] Deterministic extraction ladder: ICS (with recurrence), RSS/Atom, platform adapters (The Events Calendar, Squarespace), Schema.org JSON-LD, per-source CSS selectors (Phase 3 shipped the ICS rung; the other rungs, every-rung test-run preview (SRC-02) and P0 sources live (RES-04) are Phase 4)
- [ ] Recurring and multi-day events (series approval, 90-day occurrence horizon) (Phase 1 shipped admin-entered series, multi-day and all-day expiry; Phase 3 imports ICS series as one draft when the rule fits the admin form (D-19); RDATE additions and moved overrides stay manual)
- [ ] Location geofence for regional sources (Phase 2 shipped the geofence terms on the Rules screen: NR17/NR16/NR18/IP24 and nine place names; applying them to regional sources remains, Phase 4)
- [ ] Source run logs and admin dashboard counts (Phase 3 shipped per-run logs on each source and a Sources line in `ops:health`; dashboard counts remain, ADM-05 Phase 5)
- [ ] Privacy notice, takedown contact and cookieless analytics (Phase 2 shipped an interim `/privacy`; sessions keep no IP and are encrypted)
- [ ] Retention period and pruning for feed personal data kept in `source_items` (organiser names/emails, full descriptions) before launch (Phase 3 review WR-06; needs an owner decision)
- [ ] Honour `Retry-After` on 429/503 responses before launch (Phase 3 review WR-04)

### Out of Scope

- LLM/AI extraction, classification or summarisation - non-deterministic, costly, weak on ambiguous dates; reconsider later only as admin-triggered assistance
- Facebook scraping or Graph API - Facebook prohibits automated collection; Graph API is App Review-gated. Admin manual entry covers it
- JavaScript-rendered sources / headless browser and poster OCR - complexity; deferred
- Native apps, visitor accounts, comments, ratings - not needed to validate demand
- Ticketing, payments, bookings - outside the product's purpose
- Automated publishing - quality control is the product's trust promise
- Notifications, digests, organiser accounts, recommendations, multi-language, advanced analytics - post-validation
- Coverage beyond Attleborough and nearby villages - focus
- Hosting third-party images - copyright risk; category placeholders instead

## Context

- **Owner:** experienced Laravel developer; solo admin at launch.
- **Dual purpose:** a learning project in AI-assisted development (GSD workflow) that should launch for real in Attleborough.
- **Local sources are not yet known.** Automated research in October 2026 found only a few (Attleborough Town FC on Squarespace; Snetterton Circuit is JS-rendered; Eventbrite has thin Attleborough inventory). Web search was blocked during that research, so source discovery needs agent research plus manual URL collection.
- **Verified technical facts:** Eventbrite event pages emit Schema.org `Event` JSON-LD (and robots.txt disallows `/rss/` and `/atom/`); The Events Calendar bundles a REST API and ICS export (exact endpoints to verify on live installs); `sabre/vobject` + `rlanvin/php-rrule` for ICS/recurrence; `brick/structured-data` reads JSON-LD (pre-1.0); `spatie/schema-org` only generates.
- **Spec history:** v1.0 used an LLM for plain-HTML extraction; replaced in v1.1 by the deterministic extraction ladder. Original v1.0 is kept in the session archive, not the repo.

## Constraints

- **Tech stack**: Laravel (current stable), PHP 8.3+, PostgreSQL (`pg_trgm`), Blade + Tailwind + Alpine + Livewire, Filament admin; no React/Inertia - matches owner expertise and keeps the frontend simple
- **Budget**: Cheapest simple hosting - target a small VPS (~GBP 4-6/month, e.g. Hetzner) with a free or cheap deploy panel or Docker; database queue driver, no Redis
- **Timeline**: Original target was about 1 month, but the owner chose the full v1.1 Definition of Done before launch and accepts that it may take longer
- **Operations**: Single admin - review must be fast (bulk actions, quality reasons, diffs)
- **Legal**: Respect robots.txt, polite crawling, factual extracts with attribution, UK GDPR (legitimate interests, privacy notice), PECR for any organiser email
- **Determinism**: Every parser must be fixture-tested; reject rather than guess ambiguous data

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| No LLM in the MVP; deterministic extraction ladder | Reproducible, testable, cheap; avoids fabricated dates and turning review into fact-checking | ✓ Holds after Phase 3 (no AI runtime in composer `require`; ICS rung fixture-tested, other rungs in Phase 4) |
| Unified draft model; `events` only holds reviewed events; expiry derived | Removes v1.0's overlapping status models | ✓ Phase 3 (new, update and cancellation drafts all publish through `ApproveDraft` or the source-change actions) |
| Admin manual entry instead of Facebook automation | Facebook policy; covers posters too | - Pending |
| Rule-based quality score with visible reasons | Explainable to the reviewer | - Pending |
| Database queue, no Redis; cheapest VPS hosting | Low volume; minimal cost and ops | ✓ Database queue, cache and session work locally with heartbeats and a System health page (Phase 1); VPS in Phase 5 |
| Full v1.1 Definition of Done before launch | Owner preference over a lean launch | - Pending |
| Revised spec kept as `docs/product-specification.md` v1.1 | Single baseline for planning | - Pending |
| Laravel Boost as a dev-only tool (MCP + AI guidelines for coding agents) | Learning project built with AI-assisted development; Boost improves agent output. Never a production dependency, so no LLM in the product | ✓ Good (require-dev only; project rules in `.ai/rules`) |
| Phase 1 is local-dev only; domain, VPS and deploy moved to Phase 5 | Ship the published model and admin first without infra cost | ✓ Phase 1 complete locally (2026-10-17) |
| sabre/vobject is the only recurrence engine; occurrences upserted on (event, local date) | Correct DST handling; admin cancellations survive regeneration | ✓ Good (DST fixtures 25 Oct 2026 / 28 Mar 2027 pass) |
| One-off ↔ repeating switches are refused (WR-06) | iCalendar UIDs must never change for subscribers | ✓ Accepted by owner; workaround is hide + create new |
| Browser UAT flows F0–F10 automated with Pest browser tests in CI | Every phase signs off with the same flows; catches UI regressions | ✓ Green on PHP 8.4 and 8.5 |
| Approval wraps the single write choke point: `ApproveDraft` → `PublishEvent`; manual entry is a manual draft approved in one transaction | One path to publication, admin and timestamps recorded on every event | ✓ Phase 2 (arch test: PublishEvent only inside App\\Publishing\\Actions) |
| Turnstile is the single third-party script, on `/submit` only (D-17); in-house verifier fails closed | Spam protection without a package; every other public page stays third-party-free | ✓ Phase 2 (guard test) |
| Organiser contact public only by choice (`contact_public`); sessions store no IP and are encrypted | Privacy notice must be true (D-03, review CR-01) | ✓ Phase 2 |
| Sources: never bypass bot walls; ToS-unclear sources crawlable on owner decision; Cloudflare-walled sites via official routes or Verified Bots (Phase 5); Meta APIs not used for now | Honest, defensible crawling (D-23..D-26) | ✓ Inventory in docs/research/source-inventory.md |
| Import window = the 90-day occurrence horizon (D-20); past and far-future items skipped and counted, far-future items drafted once they enter the window | One horizon for imports and occurrences; still meets PIPE-01 | ✓ Phase 3 |
| Per-source item identity (`uid`, `tec_post_id` or `summary_start`); identity and time zone refused once a source has items | Changing either would orphan accepted baselines and re-draft every item | ✓ Phase 3 (review recommendation, `SourceForm::unchangedOnceRecorded()`) |
| One draft per feed item plus "Approve all like this" (same source + normalised title + venue, cap 200) (D-03); feed repeat rules kept only if they round-trip the admin form (D-19) | No guessing series from weekly items; review stays fast for ~60 items/month feeds | ✓ Phase 3 |
| Source changes are update/cancellation drafts: Apply / Ignore / Confirm, each moving the accepted baseline; Reject hidden for them; urgent ones (< 48 h) jump the queue (D-06..D-09) | Admin stays the gate for changes too, without re-raising the same change | ✓ Phase 3 (REV-06/07) |
| Duplicate rule: same canonical URL, or same London date + start within 60 min + same/similar venue + `pg_trgm` title similarity ≥ 0.3; Mark as duplicate keeps published details and links the source (D-10..D-13) | Catches cross-source repeats; admin makes the final call | ✓ Phase 3 (PIPE-05, REV-05) |
| Discovered descriptions are ≤200-character excerpts with no phone or email, even after an admin edit; feed organiser names/contacts never published (D-15, D-17) | Feeds name private people; UK GDPR and the privacy notice must hold | ✓ Phase 3 (review CR-01, verification VER-01) |
| `SourceSeeder` pre-loads Baptist Church, Town Council and Academy ICS sources inactive; activation only via Test run → Create drafts; "?" field help on the source form (D-01, D-05) | Owner reviews a preview before any source drafts; form explains itself | ✓ Phase 3 (browser flow F15); non-ICS sources added in Phase 4 |
| `FetchSourceJob` timeout 300 s, run deadline 240 s, fetch deadline 55 s, database `retry_after` 330 s | A slow source must not be killed mid-run or run twice | — Phase 5: worker `--timeout` ≥ 300 (330), systemd `TimeoutStopSec` 360 |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? -> Move to Out of Scope with reason
2. Requirements validated? -> Move to Validated with phase reference
3. New requirements emerged? -> Add to Active
4. Decisions to log? -> Add to Key Decisions
5. "What This Is" still accurate? -> Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check - still the right priority?
3. Audit Out of Scope - reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-10-18 after Phase 3*
