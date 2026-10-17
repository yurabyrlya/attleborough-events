---
gsd_state_version: "1.0"
current_phase: 02
current_phase_name: Draft Model, Review Queue & Submissions
status: executing
stopped_at: Completed 02-08-PLAN.md
last_updated: "2026-10-17T19:56:16.717Z"
last_activity: 2026-10-17
last_activity_desc: "Completed 02-07 (Rules screen /admin/rules: Categories + Keyword rules tabs, SaveRules with refusals judged against the submitted state, CategoryAssigner keyword link)"
state_head: 12232428d01fc5a58c2cb11ffe8d27284377f817
progress:
  total_phases: 5
  completed_phases: 1
  total_plans: 31
  completed_plans: 21
  percent: 20
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-10-17)

**Core value:** Residents can open one trustworthy, up-to-date calendar on their phone and see what's on in Attleborough. Nothing reaches it without admin approval.
**Current focus:** Phase 2 — Draft Model, Review Queue & Submissions

## Current Position

Phase: 02 (Draft Model, Review Queue & Submissions) — EXECUTING
Plan: 8 of 16 complete (02-01 to 02-08 done; wave 3 in progress with 02-09 to 02-10)
Status: Ready to execute
Last activity: 2026-10-17 — Completed 02-07 (category_keyword_rules + CategoryAssigner link 3 by priority then id, whole-word NFC match; SaveRules admin-only, collect-all refusals inside one locked transaction, slugs fixed on create; Filament Rules page with Categories and Keyword rules tabs; PIPE-03/ADM-04 finish in 02-11)

Progress: 20/31 plans ([██░░░░░░░░] 20%); phase 2: 7/16; phases complete: 1/5

## Performance Metrics

**Velocity:**
- Total plans completed: 15
- Average duration: -
- Total execution time: 0.0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1 | 13 | - | - |

**Recent Trend:**
- Last 5 plans: -
- Trend: -

*Updated after each plan completion*
**Per-Plan Metrics:**

| Plan | Duration | Tasks | Files |
|------|----------|-------|-------|
| Phase 1 P01 | 23min | 3 tasks | 62 files |
| Phase 1 P04 | 17min | 3 tasks | 30 files |
| Phase 1 P06 | 11min | 3 tasks | 25 files |
| Phase 1 P05 | 12min | 3 tasks | 20 files |
| Phase 1 P09 | 17min | 3 tasks | 20 files |
| Phase 1 P07 | 22min | 4 tasks | 40 files |
| Phase 1 P08 | 15min | 2 tasks | 25 files |
| Phase 1 P10 | 14min | 2 tasks | 20 files |
| Phase 1 P11 | 11min | 3 tasks | 15 files |
| Phase 1 P14 | 4min | 2 tasks | 11 files |
| Phase 1 P12 | 5min | 2 tasks | 13 files |
| Phase 1 P13 | 11min | 2 tasks | 12 files |
| Phase 1 P15 | 9min | 2 tasks | 9 files |
| Phase 02 P01 | 7min | 2 tasks | 14 files |
| Phase 02 P02 | 40min | 4 tasks | 7 files |
| Phase 02 P03 | 9min | 2 tasks | 14 files |
| Phase 02 P04 | 6min | 2 tasks | 10 files |
| Phase 02 P05 | 10min | 3 tasks | 14 files |
| Phase 02 P06 | 8min | 2 tasks | 8 files |
| Phase 02 P07 | 8min | 3 tasks | 11 files |
| Phase 02 P08 | 5min | 2 tasks | 8 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- [Roadmap]: 5 coarse phases following research SUMMARY build order; published model first (live VPS deploy superseded by [Scope]: deferred to Phase 5), review queue before any crawling
- [Roadmap]: Only review actions and manual entry write published events (single choke point, REV-02)
- [Roadmap]: Source research (RES-01..03) runs alongside Phases 1-2 and gates Phase 3; RES-04 (P0 sources live) closes Phase 4
- [Roadmap]: TEC is the verified platform adapter; no Squarespace `?format=` endpoints (robots-disallowed)
- [Scope]: Infra/domain deferred to Phase 5; Phase 1 is local-dev only. (2026-10-16: 01-02/01-03 moved to 05-01/05-02; OPS-02 to Phase 5; OPS-01b/OPS-04b split out for production.)
- [Testing]: Browser UAT flows (F0–F10) live in docs/uat/browser-uat.md; every phase runs them before sign-off and adds flows for new UI
- [Phase 1]: Complete 2026-10-17. Verification passed (168/173 + owner-accepted backstops and prohibitions); security verified (0 open); UI review 17/24 with top 3 fixed (261017-hp9); open advisory review items WR-15, IN-09..16 in 01-REVIEW-DISPOSITION.md
- [Testing]: Browser suite runs in CI; tests/Pest.php forgets scoped instances after each in-process request; use Uat::settleFields() after filling Livewire forms
- [Phase 02]: [02-01]: Manual entry is a manual draft approved at once through ApproveDraft (D-07); PublishEvent is called only by ApproveDraft
- [Phase 02]: [02-01]: ApproveDraft logs via DB::afterCommit so nested approvals log only after the outermost commit; fromDraft blocks a missing start date
- [Phase 02]: Source research: a feed the site itself offers (rel=alternate, Export/Subscribe, robots Allow) puts a robots-allowed, ToS-silent source on List 1; HTML/JSON-LD with silent ToS is List 2 ToS-unclear
- [Phase 02]: Attleborough Baptist Church (Hubb ICS + dated RSS) is the only List 1 source (P0); terms checked by owner 2026-10-17
- [Phase 02]: 8 ToS-unclear/prohibited sources await owner permission and 5 bot walls await an owner home-network check; all stay List 2, never bypassed
- [Phase 02]: PublishEvent choke point enforced with Pest toOnlyBeUsedIn('App\Publishing\Actions'); ->ignoring() would be vacuous because it also skips matching dependencies
- [Phase 02]: DraftPublishability is the single publishability check; bulk skip keys report specific reasons (unresolved_venue, repeats_unset) before 'blocked'
- [Phase 02]: phpunit.xml memory_limit 512M so the arch scan does not exhaust memory for the Feature suite
- [Phase 02]: 02-04: contact_public defaults to true at DB, model and DTO (Phase 1 and admin entry stay public); submissions start private via DraftData
- [Phase 02]: 02-04: a source URL, an organiser contact or a website satisfies the 'how to check this' rule on the form and in PublishableEventData
- [Phase 02]: 02-05: Typed venue fields use exclude_unless:venue_choice,other so hidden, half-typed values with a known venue are never stored
- [Phase 02]: 02-05: Submission 'today' bounds come from CarbonImmutable::now('Europe/London'); dates strict Y-m-d, times strict H:i, stored as typed wall-clock
- [Phase 02]: 02-05: Public pages load per-page first-party JS from their own head stack (submit.js on /submit only); the layout loads only app.css
- [Phase 02]: 02-06: Rejecting overwrites review_notes with the trimmed note (or null); Reopen keeps review_notes
- [Phase 02]: 02-06: A rejection with no reason is retained as non-spam (90 days); prunable() only matches rejected rows with reviewed_at set
- [Phase 02]: SaveRules sections are optional: a state without 'categories' or 'keyword_rules' leaves that table untouched (02-11 adds mappings/geofence sections)
- [Phase 02]: Rules refusals are evaluated inside the transaction after locking categories FOR UPDATE; order upsert categories -> sync keyword rules -> delete removed categories
- [Phase 02]: Keywords stored NFC/whitespace-collapsed with case kept; matching and duplicate checks use CategoryAssigner::normalise(); new rows inserted in submitted order so earlier rows win ties
- [Phase 02]: A draft uses a category via category_id OR submitted_category_id; new categories default to icon heroicon-o-tag
- [Phase 02]: 02-08: 'Venue not recognised' (-20) only for a typed venue; a missing venue gets 'Missing venue' (-50) only
- [Phase 02]: 02-08: Venue recognised = venue_id set or VenueMatcher::exact(name/alias/postcode) hit; matching never writes venue_id (D-05, D-21)
- [Phase 02]: 02-08: 'No end time' applies to every non-all-day draft without an end time; parse errors deduct once, list 3 then 'and N more'

### Pending Todos

None yet.

### Blockers/Concerns

- [Phase 5]: Domain registration + VPS deferred (name decided: whatsonattleborough.co.uk; .co.uk availability unconfirmed)
- [Phase 2→3]: Inventory verified (02-02 + owner decisions D-23..D-26): List 1 has 10 sources (P0: Baptist Church ICS/RSS, Town Council TEC; P1: Academy ICS, Heritage Group selectors, Primary pending Crawl-delay) with redacted fixtures; 4 Cloudflare sites stay List 2 (D-25 routes). Open with the owner: Primary capture via ops/probe-sources.sh, optional clerk email (Town Council may block the Hetzner datacenter IP), local URLs for empty categories
- [Phase 5]: Umami Cloud UK GDPR data region / DPA unverified (fallback: Plausible)
- [Phase 5]: Apply for Cloudflare Verified Bots (BotBase) once the production crawler is public: bot info page, distinct UA + contact URL, published IP list or reverse DNS (or Web Bot Auth), robots/Crawl-delay compliance. Unlocks Breckland, Norfolk CC, Norfolk Museums, TicketSource public pages (D-25)
- [Phase 5]: Configure trusted proxies (Caddy / Cloudflare) so per-IP rate limiting on /submit and Turnstile hostname/remoteip checks see the real client IP (Phase 2 research)
- [Phase 3]: Crawling needs a real User-Agent contact/about URL; until Phase 5 is live use a config placeholder (e.g. CRAWLER_CONTACT_URL in config/site.php / .env) and swap in the live URL at launch
- [Phase 4]: Success criterion 5 / RES-04 say P0 sources are configured "in production", but production now arrives in Phase 5. Until then P0 sources run on the local app; revisit the wording when planning Phase 4 (or move the production half to Phase 5)

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 261017-e46 | Set up a Filament custom theme so Tailwind classes work in admin views | 2026-10-17 | b7e8527 | [261017-e46-set-up-a-filament-custom-theme-so-tailwi](./quick/261017-e46-set-up-a-filament-custom-theme-so-tailwi/) |
| 261017-gac | Fix top UX findings from browser UAT: mobile More filters disclosure, Occurrences refresh after save, honest Repeats display for unsupported rules, explicit dialog buttons, live failed-jobs badge | 2026-10-17 | 25265bc | [261017-gac-fix-top-ux-findings-from-browser-uat-mob](./quick/261017-gac-fix-top-ux-findings-from-browser-uat-mob/) |
| 261017-hp9 | Fix top 3 UI review findings: branded error pages, filtered views fold, event page date/calendar layout | 2026-10-17 | 1035d92 | [261017-hp9-fix-top-3-ui-review-findings-branded-err](./quick/261017-hp9-fix-top-3-ui-review-findings-branded-err/) |

## Deferred Items

Items acknowledged and deferred at milestone close, most recent first:

| Category | Item | Status | Deferred At | Milestone |
|----------|------|--------|-------------|-----------|
| *(none)* | | | | |

## Session Continuity

Last session: 2026-10-17T19:56:16.363Z
Stopped at: Completed 02-08-PLAN.md
Resume file: None
