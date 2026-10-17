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
- ✓ Source research: verified, prioritised inventory (`docs/research/source-inventory.md`, evidence in `event-sources.md`) with redacted P0/P1 fixtures (Attleborough Primary capture pending, owner override) — Phase 2

### Active

- [ ] Event detail pages with source attribution, SEO metadata, Schema.org `Event` JSON-LD and sitemap (Phase 1 shipped the page, SEO metadata, JSON-LD, .ics / Google Calendar and share; source attribution and sitemap remain)
- [ ] Source Registry with test run / run now, health tracking and robots-aware polite fetching
- [ ] Deterministic extraction ladder: ICS (with recurrence), RSS/Atom, platform adapters (The Events Calendar, Squarespace), Schema.org JSON-LD, per-source CSS selectors
- [ ] Unified draft model: everything incoming becomes a draft (new / update / cancellation) with a rule-based quality score and reasons (Phase 2 shipped `event_drafts` with quality band + reasons for new drafts from submissions and manual entry; update/cancellation drafts arrive with ingestion in Phase 3)
- [ ] Filament review queue: approve, edit, reject, mark duplicate, apply updates, confirm cancellations, bulk actions (Phase 2 shipped approve / edit-and-approve / reject / reopen, Approve & next with keyboard shortcuts, bulk approve with named skips, bulk reject, tabs, filters, global search; duplicates, updates and cancellations remain)
- [ ] Duplicate detection (external ID, URL, fuzzy title + date + venue) with admin making the final decision
- [ ] Update and cancellation handling, including missing-item detection
- [ ] Recurring and multi-day events (series approval, 90-day occurrence horizon) (Phase 1 shipped admin-entered weekly/monthly series via sabre/vobject, multi-day and all-day expiry, daily sync; series approval for imported drafts remains)
- [ ] Location geofence for regional sources (Phase 2 shipped the geofence terms on the Rules screen: NR17/NR16/NR18/IP24 and nine place names; applying them to regional sources remains)
- [ ] Source run logs and admin dashboard counts
- [ ] Privacy notice, takedown contact and cookieless analytics (Phase 2 shipped an interim `/privacy`; sessions keep no IP and are encrypted)

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
| No LLM in the MVP; deterministic extraction ladder | Reproducible, testable, cheap; avoids fabricated dates and turning review into fact-checking | ✓ Holds after Phase 1 (no AI runtime in composer `require`; extraction ladder still to build in Phase 3) |
| Unified draft model; `events` only holds reviewed events; expiry derived | Removes v1.0's overlapping status models | - Pending |
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
*Last updated: 2026-10-17 after Phase 2*
