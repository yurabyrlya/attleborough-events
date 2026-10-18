---
gsd_state_version: "1.0"
current_phase: 03
current_phase_name: Ingestion Backbone, ICS & Change/Duplicate Handling
status: executing
stopped_at: Phase 3 context gathered
last_updated: "2026-10-18T00:28:28.572Z"
last_activity: 2026-10-17
last_activity_desc: Phase 2 complete, transitioned to Phase 3
state_head: afe264692c543b796299c2110c34823c4fe8138a
progress:
  total_phases: 5
  completed_phases: 2
  total_plans: 48
  completed_plans: 29
  percent: 40
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-10-17)

**Core value:** Residents can open one trustworthy, up-to-date calendar on their phone and see what's on in Attleborough. Nothing reaches it without admin approval.
**Current focus:** Phase 3 — Ingestion Backbone, ICS & Change/Duplicate Handling

## Current Position

Phase: 03 (Ingestion Backbone, ICS & Change/Duplicate Handling) — READY TO EXECUTE
Plan: Not started
Status: Ready to execute
Last activity: 2026-10-17 — Phase 2 complete, transitioned to Phase 3

Progress: [███████████████████░] 29/31 planned plans ([████░░░░░░] 40%); phases complete: 2/5

## Performance Metrics

**Velocity:**
- Total plans completed: 29
- Average duration: -
- Total execution time: 0.0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 1 | 13 | - | - |
| 2 | 16 | - | - |

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
| Phase 02 P09 | 8min | 3 tasks | 14 files |
| Phase 02 P10 | 5min | 2 tasks | 10 files |
| Phase 02 P11 | 6min | 2 tasks | 12 files |
| Phase 02 P12 | 10min | 3 tasks | 16 files |
| Phase 02 P13 | 8min | 2 tasks | 5 files |
| Phase 02 P14 | 9min | 2 tasks | 8 files |
| Phase 02 P15 | 10min | 2 tasks | 7 files |
| Phase 02 P16 | 14min | 3 tasks | 7 files |

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
- [Phase 2]: Complete 2026-10-17. 16 plans; verification passed (owner override: Attleborough Primary fixture pending, capture before Phase 3 planning); security verified (45 threats, 0 open; T-02-24/T-02-29 production halves → Phase 5); code review CR-01/CR-02 fixed, 6 warnings deferred (02-REVIEW-DISPOSITION.md; WR-06 and WR-08 worth fixing before launch)
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
- [Phase 02]: 02-09: validSubmission() backdates its timing token 5 s (clock restored) because inline post(validSubmission()) can't travel between mint and post
- [Phase 02]: 02-09: a filled honeypot skips field rules (rules() returns []) so bots always get the silent thank-you
- [Phase 02]: 02-09: visible spam refusals go on the 'form' error key, shown in #form-check by the submit button
- [Phase 02]: 02-09: resting = production with empty Turnstile site key or secret; elsewhere a missing secret fails closed via the verifier
- [Phase 02]: 02-10: submissions limiter keys are ip:{address}:hour / :day — identical by() keys in one named limiter share (and double-hit) a counter
- [Phase 02]: 02-10: HealthReport::spamCheck() reuses StoreSubmissionRequest::formIsResting(); phpunit.xml pins TURNSTILE_* empty so a local .env can't leak into tests
- [Phase 02]: 02-11: Category mappings are PIPE-03 link 2 (first mapping by id, normalised equality, reason "Mapped from '{value as given}'"); Phase 3 adds category_mappings.source_id and widens the key
- [Phase 02]: 02-11: Geofence terms stored in geofence_terms (type postcode_district|place_name); districts upper-case without spaces matching ^[A-Z]{1,2}[0-9][0-9A-Z]?$; enforcement Phase 4
- [Phase 02]: 02-11: RulesSeeder is create-only (case-insensitive firstOrCreate), so re-seeding never reverts admin edits; deleted defaults return on the next seed
- [Phase 02]: 02-12: ReviewQueue next()/previous() keyset compares the shared SORT_KEY against a subquery over the current draft's row, so table order and queue navigation cannot disagree (null time before 00:00)
- [Phase 02]: 02-12: Review page action visibility follows a per-page status snapshot (shownStatus), so a stale click reports 'This draft was already reviewed' instead of being silently dropped
- [Phase 02]: 02-12: EventDraftResource and EventResource canAccess() use the AdminGate rule; global search covers drafts (title, reference, organiser, typed venue) and events (title, organiser)
- [Phase 02]: 02-13: ListEventDrafts ignores the tab scope for explicitly ticked rows (and tabs exclude their scope when resolving a record), so drafts decided elsewhere are reported as 'already reviewed' instead of silently dropped from bulk runs
- [Phase 02]: 02-13: Bulk approve checks review_status before bulkBlockerKeys(); skip messages use Filament's injected $failureCount
- [Phase 02]: 02-14: Draft form leaves WR-06 off for drafts (editedEventRepeats null); only hasUneditableRule() is draft-aware, so admins can add a rule to a submission
- [Phase 02]: 02-14: Admin category pick on the review form is stored as 'Chosen by admin' via DraftData::categoryChosenByAdmin (DraftWriter passes the Manual chooser); unchanged category re-runs the draft's original chain
- [Phase 02]: 02-14: Save & approve = DraftWriter::update + ApproveDraft in one DB::transaction; blockers roll back edits, form keeps typed values
- [Phase 02]: 02-15: Publish is autofocused in the approve dialog so A then Enter publishes (D-10); Filament confirmation modals put 'Keep as is' first
- [Phase 02]: 02-15: Single-key review shortcuts use one guarded Alpine keydown handler (inputs, dialogs, modifiers, repeat) with a localStorage 'woa-shortcuts' toggle, never Filament keyBindings()
- [Phase 02]: 02-16: F14 proves 'Other can't be removed' through the UI guard (no Delete on the Other row); the visible 'Rules not saved' refusal is checked with a category in use
- [Phase 02]: 02-16: browser tests stay offline with no Turnstile site key plus a container-bound passing TurnstileVerifier (UatFixtures::fakeTurnstile)

### Pending Todos

- [Phase 2→3] Capture the Attleborough Primary School fixture after 02:39 BST 2026-10-18: `ops/probe-sources.sh attleborough-primary-school`, redact, add to the Fixture index and drop "fixtures pending" from its List 1 heading (owner override in 02-VERIFICATION.md; must be done before Phase 3 planning) — owner chose to plan Phase 3 first; capture during Phase 3 execution, before the first ingestion plan runs against it.

### Blockers/Concerns

- [Phase 5]: Domain registration + VPS deferred (name decided: whatsonattleborough.co.uk; .co.uk availability unconfirmed)
- [Phase 2→3]: Inventory verified (02-02 + owner decisions D-23..D-26): List 1 has 10 sources (P0: Baptist Church ICS/RSS, Town Council TEC; P1: Academy ICS, Heritage Group selectors, Primary pending Crawl-delay) with redacted fixtures; 4 Cloudflare sites stay List 2 (D-25 routes). Open with the owner: Primary capture via ops/probe-sources.sh, optional clerk email (Town Council may block the Hetzner datacenter IP), local URLs for empty categories
- [Phase 5]: Umami Cloud UK GDPR data region / DPA unverified (fallback: Plausible)
- [Phase 5]: Apply for Cloudflare Verified Bots (BotBase) once the production crawler is public: bot info page, distinct UA + contact URL, published IP list or reverse DNS (or Web Bot Auth), robots/Crawl-delay compliance. Unlocks Breckland, Norfolk CC, Norfolk Museums, TicketSource public pages (D-25)
- [Phase 5]: Set TURNSTILE_HOSTNAMES=whatsonattleborough.co.uk in production (hostname check is skipped when empty; T-02-24 / review WR-03)
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

Last session: 2026-10-17T22:43:56.880Z
Stopped at: Phase 3 context gathered
Resume file: .planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-CONTEXT.md
