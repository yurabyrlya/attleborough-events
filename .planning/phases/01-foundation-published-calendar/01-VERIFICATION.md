---
phase: 01-foundation-published-calendar
verified: 2026-10-17T12:48:41Z
status: passed
score: 168/173 must-haves verified (5 roadmap SCs + 168 plan truths across 13 plans; 1 by accepted override)
covered_files: [".env.example",".github/workflows/ci.yml",".planning/phases/01-foundation-published-calendar/01-01-PLAN.md",".planning/phases/01-foundation-published-calendar/01-01-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-04-PLAN.md",".planning/phases/01-foundation-published-calendar/01-04-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-05-PLAN.md",".planning/phases/01-foundation-published-calendar/01-05-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-06-PLAN.md",".planning/phases/01-foundation-published-calendar/01-06-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-07-PLAN.md",".planning/phases/01-foundation-published-calendar/01-07-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-08-PLAN.md",".planning/phases/01-foundation-published-calendar/01-08-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-09-PLAN.md",".planning/phases/01-foundation-published-calendar/01-09-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-10-PLAN.md",".planning/phases/01-foundation-published-calendar/01-10-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-11-PLAN.md",".planning/phases/01-foundation-published-calendar/01-11-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-12-PLAN.md",".planning/phases/01-foundation-published-calendar/01-12-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-13-PLAN.md",".planning/phases/01-foundation-published-calendar/01-13-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-14-PLAN.md",".planning/phases/01-foundation-published-calendar/01-14-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-15-PLAN.md",".planning/phases/01-foundation-published-calendar/01-15-SUMMARY.md","AGENTS.md","app/Calendar/CalendarFilters.php","app/Calendar/DateWindow.php","app/Calendar/EventTiming.php","app/Calendar/Export/GoogleCalendarLink.php","app/Calendar/Export/OccurrenceIcs.php","app/Calendar/Export/OccurrenceUid.php","app/Calendar/ManualRecurrence.php","app/Calendar/OccurrenceGenerator.php","app/Calendar/OccurrenceTiming.php","app/Calendar/PublicOccurrenceQuery.php","app/Calendar/RecurrenceExpander.php","app/Calendar/UpcomingDates.php","app/Console/Commands/OpsDrill.php","app/Console/Commands/OpsHealth.php","app/Console/Commands/SyncOccurrences.php","app/Enums/DateFilter.php","app/Enums/EventOrigin.php","app/Enums/EventStatus.php","app/Enums/HealthState.php","app/Enums/OccurrenceStatus.php","app/Enums/PriceType.php","app/Enums/RepeatEnds.php","app/Enums/RepeatPattern.php","app/Filament/Pages/SystemHealth.php","app/Filament/Resources/Events/Actions/StatusActions.php","app/Filament/Resources/Events/EventResource.php","app/Filament/Resources/Events/Pages/CreateEvent.php","app/Filament/Resources/Events/Pages/EditEvent.php","app/Filament/Resources/Events/Pages/ListEvents.php","app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php","app/Filament/Resources/Events/Schemas/EventForm.php","app/Filament/Resources/Events/Tables/EventsTable.php","app/Http/Controllers/CalendarController.php","app/Http/Controllers/Controller.php","app/Http/Controllers/EventController.php","app/Http/Controllers/OccurrenceIcsController.php","app/Jobs/OpsDrillJob.php","app/Jobs/QueueHeartbeat.php","app/Models/Category.php","app/Models/Event.php","app/Models/EventOccurrence.php","app/Models/EventSlug.php","app/Models/FailedJob.php","app/Models/Heartbeat.php","app/Models/User.php","app/Models/Venue.php","app/Ops/HealthCheck.php","app/Ops/HealthReport.php","app/Providers/AppServiceProvider.php","app/Providers/Filament/AdminPanelProvider.php","app/Publishing/Actions/ChangeEventStatus.php","app/Publishing/Actions/ChangeOccurrenceStatus.php","app/Publishing/Actions/PublishEvent.php","app/Publishing/Actions/UpdatePublishedEvent.php","app/Publishing/AdminGate.php","app/Publishing/Data/PublishableEventData.php","app/Publishing/EventSlugger.php","app/Publishing/Exceptions/InvalidStatusTransition.php","app/Seo/EventJsonLd.php","app/Support/EventPageData.php","app/Support/LondonFormatter.php","app/Support/OccurrenceCardData.php","app/Support/PublicUrl.php","app/Support/SafeLinks.php","app/Support/ShareLinks.php","app/Support/TextLimit.php","composer.json","config/calendar.php","config/logging.php","config/ops.php","config/site.php","database/migrations/0001_01_01_000000_create_users_table.php","database/migrations/0001_01_01_000001_create_cache_table.php","database/migrations/0001_01_01_000002_create_jobs_table.php","database/migrations/2026_10_16_000100_enable_pg_trgm.php","database/migrations/2026_10_16_000110_create_categories_table.php","database/migrations/2026_10_16_000120_create_venues_table.php","database/migrations/2026_10_16_000130_create_events_table.php","database/migrations/2026_10_16_000140_create_event_occurrences_table.php","database/migrations/2026_10_16_000150_add_status_audit_columns.php","database/migrations/2026_10_16_000160_create_heartbeats_table.php","database/migrations/2026_10_17_000100_make_event_slugs_unique.php","database/migrations/2026_10_17_000200_create_event_slugs_table.php","database/seeders/CategorySeeder.php","database/seeders/DatabaseSeeder.php","docs/uat/browser-uat.md","ops/check-event-page.sh","ops/check-public-assets.sh","phpstan.neon","phpunit.xml","resources/css/app.css","resources/css/filament/admin/theme.css","resources/js/share.js","resources/views/calendar/index.blade.php","resources/views/calendar/partials/chip.blade.php","resources/views/calendar/partials/empty-state.blade.php","resources/views/calendar/partials/filter-chips.blade.php","resources/views/calendar/partials/occurrence-card.blade.php","resources/views/calendar/partials/remove-chip.blade.php","resources/views/components/brand/calendar-pin.blade.php","resources/views/components/brand/logo.blade.php","resources/views/components/category-icon.blade.php","resources/views/components/icons/category/HEROICONS-LICENSE.txt","resources/views/components/icons/category/arts-culture.blade.php","resources/views/components/icons/category/charity.blade.php","resources/views/components/icons/category/community.blade.php","resources/views/components/icons/category/family.blade.php","resources/views/components/icons/category/food-drink.blade.php","resources/views/components/icons/category/market.blade.php","resources/views/components/icons/category/music.blade.php","resources/views/components/icons/category/other.blade.php","resources/views/components/icons/category/school.blade.php","resources/views/components/icons/category/sport.blade.php","resources/views/errors/404.blade.php","resources/views/errors/500.blade.php","resources/views/errors/503.blade.php","resources/views/errors/layout.blade.php","resources/views/events/partials/dates.blade.php","resources/views/events/partials/share.blade.php","resources/views/events/show.blade.php","resources/views/filament/pages/system-health.blade.php","resources/views/layouts/app.blade.php","routes/console.php","routes/web.php","tests/Browser/AdminCreateEventTest.php","tests/Browser/AdminEditEventTest.php","tests/Browser/EventPageTest.php","tests/Browser/PublicCalendarTest.php","tests/Browser/SystemHealthTest.php","tests/Browser/UatFixtures.php","tests/Feature/Console/SyncOccurrencesCommandTest.php","tests/Feature/Filament/CreateEventTest.php","tests/Feature/Filament/CreateRecurringEventTest.php","tests/Feature/Filament/EditEventResilienceTest.php","tests/Feature/Filament/EditEventTest.php","tests/Feature/Filament/EditRepeatTypeTest.php","tests/Feature/Filament/StatusActionsTest.php","tests/Feature/Ops/AdminFontAndLoggingTest.php","tests/Feature/Ops/AdminThemeTest.php","tests/Feature/Ops/CalendarSyncHeartbeatTest.php","tests/Feature/Ops/FailedJobsTest.php","tests/Feature/Ops/HealthReportTest.php","tests/Feature/Ops/OpsFixtures.php","tests/Feature/Ops/QueueHeartbeatTest.php","tests/Feature/Ops/SchedulerHeartbeatTracerTest.php","tests/Feature/Public/AttributeFiltersTest.php","tests/Feature/Public/BrandHeadTest.php","tests/Feature/Public/CalendarFiltersTracerTest.php","tests/Feature/Public/CalendarListTest.php","tests/Feature/Public/DateFilterTest.php","tests/Feature/Public/ErrorPagesTest.php","tests/Feature/Public/EventCalendarAndShareTest.php","tests/Feature/Public/EventJsonLdTest.php","tests/Feature/Public/EventPageContentTest.php","tests/Feature/Public/EventPageTracerTest.php","tests/Feature/Public/EventSeoTest.php","tests/Feature/Public/EventSlugRedirectTest.php","tests/Feature/Public/HostHeaderAssetsTest.php","tests/Feature/Public/NoThirdPartyAssetsTest.php","tests/Feature/Public/OccurrenceCardTest.php","tests/Feature/Public/PublicLookTest.php","tests/Feature/Public/SlugRedirectCachingTest.php","tests/Feature/Public/StatusVisibilityTest.php","tests/Feature/Publishing/ChangeEventStatusTest.php","tests/Feature/Publishing/ChangeOccurrenceStatusTest.php","tests/Feature/Publishing/OccurrenceUidStabilityTest.php","tests/Feature/Publishing/PublishEventTest.php","tests/Feature/Publishing/UpdatePublishedEventTest.php","tests/Feature/Recurrence/RecurringOccurrencesTest.php","tests/Feature/Recurrence/RecurringSeriesTracerTest.php","tests/Feature/Status/CancelOccurrenceTracerTest.php","tests/Feature/Status/StatusSurvivesRegenerationTest.php","tests/Feature/WalkingSkeletonTest.php","tests/Fixtures/public/build/manifest.json","tests/Fixtures/views/brand-og-push.blade.php","tests/Pest.php","tests/TestCase.php","tests/Unit/Brand/ContrastTest.php","tests/Unit/Calendar/DateWindowTest.php","tests/Unit/Calendar/EventTimingTest.php","tests/Unit/Calendar/ManualRecurrenceTest.php","tests/Unit/Calendar/RecurrenceExpanderTest.php","tests/Unit/Enums/StatusTransitionsTest.php","tests/Unit/Ops/EnvExampleTest.php","tests/Unit/Publishing/PublishableEventDataTest.php","vite.config.js"]
covered_digest: "v3:sha256:d01083cd595231b84c61e196b9c7ec9aeb33b2522c7ce2ad6e8f5562b18841b8"
behavior_unverified: 0
overrides_applied: 1
overrides:
  - must_have: "Editing a series to 'Does not repeat' sets recurrence_rule NULL and leaves exactly one future occurrence keyed by start_date"
    reason: "Superseded by 01-13 WR-06: one-off <-> repeating switches are refused so OccurrenceUid UIDs never change; workaround is hide + create new event (old page 404s while hidden, new event gets a suffixed slug). The end_time clause of 01-04 #17 still holds and is tested"
    accepted_by: "yurabyrlya (project owner)"
    accepted_at: "2026-10-17T09:35:02Z"
re_verification:
  previous_status: human_needed
  previous_score: 167/173
  gaps_closed:
    - "(01-09 #13) Live HEALTHY-within-6-minutes run plus ops drill: was PRESENT_BEHAVIOR_UNVERIFIED; now observed in 01-UAT.md test 6 (pass) and corroborated by local.ERROR drill lines in storage/logs/laravel-2026-10-17.log"
    - "Previous advisory (blank disabled Repeats select on a non-round-tripping series): replaced by the display-only 'Custom rule (can't be edited here)' option plus an inline Callout (quick 261017-gac)"
  gaps_remaining: []
  regressions: []
advisory:
  - finding: "WR-15 (01-REVIEW.md, open): on the read-only page of a series whose rule cannot be edited, Repeats = 'custom' maps to RepeatPattern::None in EventForm::repeats(), so End date (hidden only when repeats !== None, EventForm.php:90) shows for a series, while Ends / Last date / Number of times / Next dates (visible only when repeats !== None, lines 166-192) are hidden"
    category: other
    reason: "Confirmed by reading EventForm.php. Cosmetic and read-only: the form is disabled, Save is absent, the save is refused in beforeValidate, handleRecordUpdate and UpdatePublishedEvent::canEdit, and F8 / EditEventResilienceTest pass. Only reachable for rules the app never writes (tinker / future imports). Resolve with the review's isCustomRule() visibility fix"
    evidence_status: "code reading only; no failing test"
human_verification:
  - test: "Acknowledge the 5 insufficient_spec backstop truths: (a) edit / sync / status row-lock serialisation (01-04, 01-05); (b) heartbeat upsert under overlapping runs (01-09); (c) share.js re-entry and AbortError (01-10); (d) accepted ZWJ-emoji split at the 140-character excerpt cut (01-06)"
    expected: "Owner accepts each, or asks for a concurrency test"
    why_human: "Concurrency or accepted limits with no deterministic test. OWNER-DEFERRED (2026-10-17), then OWNER-ACCEPTED all 5 as-is (yurabyrlya, 2026-10-17T12:52:46Z; 01-UAT.md test 8 pass)"
  - test: "Review the 4 flagged prohibitions (1 judgment-tier, 3 test-tier with no enforcement test). See the Prohibitions table"
    expected: "Accept the verifier's manual check or add enforcement tests (e.g. a Pest arch / composer.json assertion)"
    why_human: "Unverified prohibitions; the verifier's check is not authoritative. OWNER-DEFERRED (2026-10-17), then OWNER-ACCEPTED the manual check of all 4 (yurabyrlya, 2026-10-17T12:52:46Z; 01-UAT.md test 9 pass)"
---

# Phase 1: Foundation & Published Calendar Verification Report

**Phase Goal:** Residents can open a mobile-first calendar on the local app and browse admin-entered events (including recurring ones), open event pages, add them to their calendar and share them
**Verified:** 2026-10-17T12:48:41Z
**Status:** human_needed. No automated gaps, no regressions. Only two sign-off items remain, and the owner has already deferred both to backlog Phase 999.1: acknowledging the 5 backstops and reviewing the 4 flagged prohibitions.
**Re-verification:** Yes, round 4. The previous report went stale after quick tasks 261017-gac and 261017-hp9, the committed Pest browser suite (F0–F10), the CI change and test-harness fixes (through 06f097a).

> **MVP-mode guard: resolved.** Commit f95623f removed `**Mode:** mvp` from Phase 1 in ROADMAP.md, so this phase uses standard goal-backward verification. The user-flow table below is kept as a cross-check.

## What changed since round 3 (5e5fb03 → 06f097a)

I read the code changes with `git diff 5e5fb03 HEAD` (37 files). I did not take them from the SUMMARYs.

| Change | Code evidence | Tests | Effect on must-haves |
|--------|---------------|-------|----------------------|
| gac: "More filters" no-JS disclosure | `filter-chips.blade.php`: When chips stay outside; Category / Show only sit inside a native `<details>` with no script. `CalendarFilters::attributeFilterCount()` | PublicLookTest (+3), F1/F2 browser | SC1 still holds. Live `/?category=music&free=1` has no `<script>`, has `noindex, follow`, shows "More filters (2)", and has root-relative remove links `/?free=1` and `/?category=music` |
| hp9: removable active-filter chips | `remove-chip.blade.php`: plain `<a>` with `sr-only` "Remove filter:" text | PublicLookTest "lets each chip remove only itself" | Plain-link filtering still works (CAL-02..05) |
| gac: Occurrences table refreshes after save | `EditEvent::afterSave` dispatches `OccurrencesRelationManager::REFRESH_EVENT`; `#[On]` no-op re-render. A refused save never reaches afterSave (Halt) | EditEventTest (+2) | ADM-03 UX fix; no write path changed |
| gac: honest "Custom rule" Repeats display + inline Callout | `EventForm::CUSTOM_RULE_OPTION` and `hasUneditableRule()` → `UpdatePublishedEvent::canEdit` (static, single check). `'custom'` never reaches `fromForm`, because `beforeValidate` → `refuseUnsupportedRule` halts first. The Callout escapes the stored rule | EditEventResilienceTest "escapes the stored rule…", F8 | WR-09/WR-10 truths unchanged. See WR-15 advisory |
| gac: explicit dialog buttons | `StatusActions`: `modalSubmitActionLabel` (e.g. "Cancel date") and `modalCancelActionLabel('Keep as is')` | StatusActionsTest (+2), F5/F7 | Wording only; actions still delegate to ChangeEventStatus / ChangeOccurrenceStatus |
| gac: live failed-jobs badge | `SystemHealth::refreshNavigationBadge()` dispatches `refresh-sidebar` on retry, delete and already-handled | FailedJobsTest, F10 (badge 2→1→none, WR-12 fix e1edcb3) | SC5 / OPS-04 strengthened |
| hp9: branded static-safe 404 / 500 / 503 | `errors/layout.blade.php`: no queries, no `route()`, no scripts, `noindex`, `@vite` only when a manifest or hot file exists | ErrorPagesTest (5 tests), F7 expects the branded 404 title | Live unknown slug → 404 "Page not found \| What's On Attleborough", `noindex`. Hidden events still 404 (SC3) |
| hp9: event-page date rows | `dates.blade.php`: one `<li>` per date, indented row with "Apple / Outlook (.ics)" and "Google Calendar" (sr-only "for {date}"); cancelled dates get text and no links | EventCalendarAndShareTest (+1), F9 | SC4 / EVT-06 still hold; live EVENT PAGE OK |
| Browser suite + CI | `phpunit.xml` adds a Browser testsuite. `ci.yml`: Pest `--testsuite=Unit,Feature`, then Playwright Chromium, then `--testsuite=Browser`, plus failure screenshots. `pestphp/pest-plugin-browser` is in **require-dev** | 18 browser tests (F0–F10) | SC5 strengthened: CI now also runs the UAT flows |

## Evidence run by the verifier (not taken from SUMMARYs)

| Check | Result |
|-------|--------|
| `./vendor/bin/pest --testsuite=Unit,Feature` (Herd PHP 8.4.12, ae-pg up) | **passed: 522 tests, 3210 assertions**, 0 failures (was 504). 1 warning with empty details (pre-existing) |
| `./vendor/bin/pest --testsuite=Browser` | **passed: 18 tests, 277 assertions** (F0–F10) |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | passed, 0 errors |
| `gh run view 39033747032` (HEAD **06f097a**, main) | **success**. Jobs `test (PHP 8.4)` and `test (PHP 8.5)`: Pint, Larastan, Pest (PostgreSQL), Install Playwright Chromium and Browser UAT steps all success. The previous main run (26e3f7d) was also success |
| `verify.artifacts`, 13 plans | 78/80. The same two known stale-literal misses as round 3: 01-06 `aria-current` (now in `chip.blade.php:12`, still present) and 01-13 `tryFromRrule` (replaced by design in 01-15 with `editableRecurrence` → `tryFromStoredRule`) |
| `verify.key-links`, 13 plans | 44/46. Same known misses: the malformed 01-07 `from` `ci.yml (job test)` (checked by hand: `npm run build` at ci.yml:64) and the 01-13 `tryFromRrule` literal |
| `ops/check-public-assets.sh http://localhost:8000` (owner's server, left running) | **exit 0**: `PUBLIC ASSETS OK: css=/build/assets/app-GebJoryV.css fonts=2 third-party=0 host-probe=clean` |
| `ops/check-event-page.sh http://localhost:8000` | **exit 0**: `EVENT PAGE OK: path=/events/first-demo-event jsonld=1 uid=1@whatsonattleborough.co.uk` |
| Requirements union (13 plans) vs REQUIREMENTS.md Phase 1 rows | identical 20 IDs, 0 orphaned |
| Debt markers (TBD / FIXME / XXX / TODO / HACK) in every app / resources / tests / CI file changed since 5e5fb03 | none |

## User Flow Coverage (cross-check from the goal's clauses)

| Step | Expected | Evidence | Status |
|------|----------|----------|--------|
| Open a mobile-first calendar on the local app | Grouped upcoming occurrences, no script, no third-party assets, fits a phone | Live probe OK. F1 "fits a phone in light and dark mode", F1/F2 "first result above the fold". UAT 1 pass | ✓ |
| Browse admin-entered events incl. recurring | Admin creates one-off and series; each occurrence listed | F3+F4 "creates a monthly series that the public calendar lists date by date". RecurringOccurrencesTest. UAT 3 pass | ✓ |
| Open event pages | Slug, SEO, JSON-LD, branded 404 for missing or hidden events | Live EVENT PAGE OK; F9; ErrorPagesTest | ✓ |
| Add to calendar | `.ics` with a stable UID, Google link per date | F9; EventCalendarAndShareTest; OccurrenceUidStabilityTest. UAT 7: owner imported .ics on a phone at the LAN address | ✓ |
| Share | Web Share plus WhatsApp / Facebook / email / copy-link fallbacks | F9 "copies the event link and announces it"; UAT 7 phone fallbacks pass. Native share needs HTTPS, so it is rechecked in Phase 5 | ✓ |

## Goal Achievement

### Observable Truths: ROADMAP success criteria (the contract)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| SC1 | Phone visitor sees grouped upcoming occurrences; Today / weekend / week, category, Free, family filters work as plain links without JS; filter URLs `noindex` | ✓ VERIFIED | The `<details>` disclosure is native HTML. Live filtered page: 0 scripts, `noindex, follow`, root-relative links. F1/F2 browser tests, DateFilterTest, AttributeFiltersTest, PublicLookTest green. UAT 1–2 pass |
| SC2 | Admin creates weekly / nth-weekday series → once per occurrence over 90 days; multi-day windows; expiry; correct times across 25 Oct 2026 | ✓ VERIFIED | Recurrence / expander / date-filter tests green; F3+F4 green. UAT 3 shows the preview correct across the clock change. Generator code unchanged since round 3 |
| SC3 | Admin can edit, hide, unhide, cancel an event or one occurrence; "Cancelled" badge; statuses survive regeneration | ✓ VERIFIED | F5, F6 ("keeps date statuses"), F7 (table and header) green; StatusSurvivesRegenerationTest green. UAT 4 pass. Dialog relabelling does not change the delegation |
| SC4 | Event page: slug, meta / canonical / OG, JSON-LD per occurrence, description and attribution; `.ics` stable UID / Google; share | ✓ VERIFIED | Live probe; EventSeoTest, EventJsonLdTest, EventCalendarAndShareTest (+date-rows test), F9 green. UAT 7 pass |
| SC5 | CI on push to main runs Pest, Larastan, Pint on PostgreSQL; local heartbeat and failed jobs visible; daily logs for 14 days | ✓ VERIFIED | CI green at HEAD on PHP 8.4 and 8.5 (now including the browser suite). F10 and FailedJobsTest; AdminFontAndLoggingTest (daily, 14 files, 0640). UAT 6: HEALTHY within 6 minutes with live processes |

### Observable Truths: PLAN must-haves (168 truths across 13 plans)

| Plan | Truths | Verified | Not verified | Notes |
|------|--------|----------|--------------|-------|
| 01-01 skeleton | 15 | 15 | 0 | Regression: artifacts 7/7, links 3/3, suite green |
| 01-04 recurrence | 19 | 18 (1 by override) | 1 insufficient_spec | #17 first clause PASSED (override), carried forward. Row-lock backstop owner-deferred (999.1) |
| 01-05 status | 18 | 17 | 1 insufficient_spec | Still no delete action (F7 and UAT 4) |
| 01-06 filters / cards | 24 | 23 | 1 insufficient_spec | Filter UI restructured by gac/hp9. Plain links, `aria-current` on toggles and the "Showing" summary all still pass |
| 01-07 look | 19 | 19 | 0 | Live `/` has no script; F1 light / dark |
| 01-08 event page | 16 | 16 | 0 | Live probes pass; branded 404 keeps the 404 status for unknown and hidden events |
| 01-09 ops | 16 | **15** | 1 insufficient_spec | **#13 now ✓ VERIFIED (observed)**: UAT 6 ran schedule:work + queue:work (HEALTHY within 6 minutes; drill failure listed, retried, failed=0, fresh last_success). The local drill failures appear as `local.ERROR: Deliberate ops drill failure` in `storage/logs/laravel-2026-10-17.log` (4 lines) |
| 01-10 JSON-LD / share | 7 | 6 | 1 insufficient_spec | share.js unchanged |
| 01-11 gap | 7 | 7 | 0 | Host probe clean; CI matrix green |
| 01-12 gap | 6 | 6 | 0 | `new Event(` only in PublishEvent and the EventForm preview |
| 01-13 gap | 8 | 8 | 0 | WR-06 lock still holds (F6 "locks Repeats on a one-off event"); WR-09 read-only page (F8) |
| 01-14 gap | 6 | 6 | 0 | Regression |
| 01-15 gap | 7 | 7 | 0 | `canEdit` is the single check, now also used by `EventForm::hasUneditableRule` |

**Score:** 168/173 truths verified (1 of them PASSED by override). 0 present but behaviour unverified. 5 `insufficient_spec` backstops routed to a human (owner-deferred). 0 failed.

### Required Artifacts

All 80 declared artifacts exist and are substantive (78 match their tool patterns; the 2 misses are the known stale literals explained above). The new post-phase artifacts are substantive and wired:
- `remove-chip.blade.php` is included by `filter-chips`.
- `errors/{404,500,503,layout}.blade.php` are picked up by Laravel's error-view lookup; live 404 confirmed.
- `tests/Browser/*` (6 files) are registered in phpunit.xml and run in CI.

### Key Link Verification

| From | To | Via | Status |
|------|----|-----|--------|
| EditEvent::afterSave | OccurrencesRelationManager | `dispatch(REFRESH_EVENT)` → `#[On(REFRESH_EVENT)] refreshOccurrences()` | WIRED (EditEventTest re-render test) |
| EventForm (Callout, Repeats options / helper) | UpdatePublishedEvent::canEdit | `hasUneditableRule()` | WIRED |
| EditEvent::mutateFormDataBeforeFill | EventForm::CUSTOM_RULE_OPTION | `[...fromEvent($event), 'repeats' => 'custom']` when `!canEdit` | WIRED; save refused before `fromForm` |
| SystemHealth retry / forget / already-handled | Filament sidebar badge | `dispatch('refresh-sidebar')` | WIRED (F10 badge 2→1→none) |
| filter-chips | CalendarFilters | `queryWith([...=>null])`, `attributeFilterCount()` | WIRED (live HTML) |
| ci.yml | Browser suite | `vendor/bin/pest --ci --testsuite=Browser` | WIRED (CI step success on both legs) |
| Earlier links (01-01 … 01-15) | — | verify.key-links 44/46 + green suites | WIRED |

### Data-Flow Trace (Level 4)

| Artifact | Data | Source | Real data | Status |
|----------|------|--------|-----------|--------|
| calendar/index + filter-chips | `$groups`, `$filters` | `PublicOccurrenceQuery`, `CalendarFilters` from the request | yes (live demo event; chip hrefs reflect the live query) | ✓ FLOWING |
| events/show dates rows | `EventPageData->dates` | `EventController` → upcoming occurrences | yes (live uid) | ✓ FLOWING |
| Occurrences relation table | rows | `occurrences` relationship, re-queried on render | yes (test) | ✓ FLOWING |
| System health badge | failed-job count | `getNavigationBadge()` from DB on sidebar refresh | yes (F10) | ✓ FLOWING |
| Error pages | site name / contact | `config('site.*')` (static by design) | n/a (static-safe by design) | ✓ (intentional) |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Public assets, no scripts, Host probe | `bash ops/check-public-assets.sh http://localhost:8000` | PUBLIC ASSETS OK … host-probe=clean | ✓ PASS |
| Event page, JSON-LD, UID | `bash ops/check-event-page.sh http://localhost:8000` | EVENT PAGE OK … uid=1@whatsonattleborough.co.uk | ✓ PASS |
| Branded 404 | `curl /events/does-not-exist-xyz` | 404, `<title>Page not found \| What's On Attleborough</title>`, `noindex` | ✓ PASS |
| No-JS filter disclosure + remove chips | `curl "/?category=music&free=1"` | `<details>`, "More filters (2)", remove hrefs `/?free=1` and `/?category=music`, `noindex, follow`, no `<script>` | ✓ PASS |
| UAT flows F0–F10 | `pest --testsuite=Browser` | 18/18 | ✓ PASS |

### Probe Execution

There are no `scripts/*/tests/probe-*.sh`. The phase's live probes are the `ops/` scripts, run read-only against the owner's server on :8000.

| Probe | Command | Result | Status |
|-------|---------|--------|--------|
| `ops/check-public-assets.sh` | `bash ops/check-public-assets.sh http://localhost:8000` | exit 0 | PASS |
| `ops/check-event-page.sh` | `bash ops/check-event-page.sh http://localhost:8000` | exit 0 | PASS |

### Requirements Coverage

All 20 Phase 1 IDs are claimed by at least one plan. The plans' union matches the REQUIREMENTS.md Phase 1 rows exactly (20 = 20). Nothing is orphaned. OPS-01b, OPS-02 and OPS-04b are Phase 5 by the owner's scope change and were not assessed.

| Requirement | Source Plan(s) | Status | Evidence |
|-------------|----------------|--------|----------|
| OPS-01 | 01-01, 01-11 | ✓ SATISFIED | Stack and CI green on PHP 8.4 / 8.5; browser plugin is dev-only |
| OPS-04 | 01-09 | ✓ SATISFIED | F10, FailedJobsTest, UAT 6 live run |
| CAL-01 | 01-01, 01-06, 01-07, 01-11 | ✓ SATISFIED | Live list; F1 |
| CAL-02 / 03 / 04 / 05 | 01-06 | ✓ SATISFIED | DateFilterTest, AttributeFiltersTest, PublicLookTest, F2 |
| CAL-06 | 01-06, 01-07, 01-11 | ✓ SATISFIED | No script on `/` and on filtered URLs (live); native `<details>` |
| CAL-07 | 01-05, 01-06, 01-07, 01-11 | ✓ SATISFIED | OccurrenceCardTest; F5 |
| CAL-08 | 01-04, 01-12 | ✓ SATISFIED | 90-day horizon tests |
| EVT-01 / EVT-02 | 01-08, 01-11, 01-14 | ✓ SATISFIED | Live event page; slug 301 tests; branded 404 |
| EVT-03 | 01-10 | ✓ SATISFIED | EventJsonLdTest; live jsonld=1 |
| EVT-06 | 01-08, 01-10, 01-11, 01-13, 01-15 | ✓ SATISFIED | Stable UID tests; new date-row links; UAT 7 phone import |
| EVT-07 | 01-10 | ✓ SATISFIED | F9 copy link; UAT 7 fallbacks on a phone (native share is HTTPS-only, Phase 5) |
| REC-01 | 01-01, 01-04, 01-12, 01-15 | ✓ SATISFIED | Status survives regeneration |
| REC-02 | 01-01, 01-04 | ✓ SATISFIED | EventTiming tests |
| ADM-01 | 01-01, 01-12 | ✓ SATISFIED | AdminGate; F0 login |
| ADM-02 | 01-01, 01-04, 01-12, 01-14 | ✓ SATISFIED | PublishEvent only write path; F3 |
| ADM-03 | 01-04, 01-05, 01-12, 01-13, 01-14, 01-15 | ✓ SATISFIED | F5–F8; narrower type-switch scope accepted by override |

### Prohibitions (ADR-550)

The test-enforced prohibitions still pass in the green suites. **Four remain flagged as unverified prohibitions; human review is recommended.** The owner has deferred this review (UAT test 9 → backlog Phase 999.1).

| Prohibition | Tier | Disposition | Verifier's manual check (not authoritative), re-run this round |
|-------------|------|-------------|------------------------------------------|
| 01-01 ADM-02: no public event bypasses `App\Publishing\Actions`; no seeded demo events | judgment | needs human resolution (deferred) | `new Event(` only in PublishEvent:48 and EventForm:357 (unsaved preview). No `Event::create` / `forceCreate` in app/ or seeders. Holds |
| 01-01 OPS-01: no LLM/AI runtime package in `require` | test | unverified: no enforcing test | `require` = php, filament/filament, laravel/framework, laravel/tinker, sabre/vobject, spatie/schema-org (the new pest-plugin-browser is require-dev). Holds |
| 01-04 REC-01: sabre/vobject only; no hand-rolled recurrence loops | test | unverified: no enforcing test | No `addWeek(s)` / `addMonth(s)` / `nthOfMonth` in app/. Holds |
| 01-09 OPS-04: liveness not in cache; no HTTP route or Filament action writes a heartbeat | test | unverified: no enforcing test | `Heartbeat::beat` only in routes/console.php:22, QueueHeartbeat, OpsDrillJob, SyncOccurrences. The SystemHealth changes dispatch a UI event only. Holds |

### Advisory (New Scope, Unevidenced)

| # | Finding | Category | Why Advisory |
|---|---------|----------|--------------|
| 1 | WR-15: on the read-only "Custom rule" page, one-off field visibility applies (End date shown; Ends / Last date / Count / Next dates hidden), because `'custom'` → `RepeatPattern::None` | other | New scope from quick 261017-gac; confirmed by code reading (EventForm.php:90, 166-192). Read-only and cosmetic, with no data path, so no must-have is affected. No failing test. It is already tracked as open in 01-REVIEW-DISPOSITION.md |

The round-3 advisory (blank disabled Repeats select) is **resolved**: the page now shows "Custom rule (can't be edited here)" and an inline Callout with the stored rule.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| All app / resources / tests / CI files changed since 5e5fb03 | — | TBD / FIXME / XXX / TODO / HACK | none | none found |
| `app/Filament/Resources/Events/Schemas/EventForm.php` | 90, 166-192 | WR-15 visibility mapping for `'custom'` | ⚠️ Warning | Misleading read-only display on an edge-case page; no writes possible |
| `resources/views/errors/` | — | IN-12: only 404 / 500 / 503 branded (403 / 405 / 419 / 429 framework pages) | ℹ️ Info | Cosmetic |
| `tests/Browser/AdminCreateEventTest.php` | 97 | IN-14: clicks auto-generated `key-bindings-1` id | ℹ️ Info | Test brittleness on a Filament upgrade |
| `tests/Browser/PublicCalendarTest.php` | 151-159 | IN-15: "above the fold" checks the day heading, not the first card | ℹ️ Info | The test is weaker than its title |
| `tests/Feature/Public/ErrorPagesTest.php` | 65-80 | IN-16: 500 page tested as a direct render only | ℹ️ Info | The exception-handler path is not exercised |
| `ops/check-public-assets.sh` | 103 | Host probe `\|\| true` (carried forward) | ℹ️ Info | Low risk |

### Human Verification Required

UAT (01-UAT.md, 7 pass) and the automated browser suite (F0–F10, green locally and in CI) cover every UI, phone, no-JS, Filament, ops-drill and share/.ics item from round 3. Those checks are **not** re-requested. Two sign-off items remain, and the owner has deferred both to **backlog Phase 999.1**:

### 1. Acknowledge the 5 insufficient_spec backstops

**Test:** Review (a) the edit / sync / status row-lock serialisation (01-04, 01-05), (b) the heartbeat upsert under overlapping runs (01-09), (c) share.js re-entry / AbortError (01-10), and (d) the accepted ZWJ-emoji split at the 140-character excerpt cut (01-06).
**Expected:** The owner accepts each, or asks for a concurrency test.
**Why human:** No deterministic test exists; presence and wiring never count as evidence for backstop truths. Deferred (UAT test 8 → 999.1).

### 2. Review the 4 flagged prohibitions

**Test:** See the Prohibitions table.
**Expected:** Accept the manual checks, or add enforcement tests (a Pest arch test for recurrence helpers and heartbeat writers, and a composer.json `require` assertion).
**Why human:** Unverified prohibitions must not be silently passed. Deferred (UAT test 9 → 999.1).

### Gaps Summary

**No automated gaps and no regressions.**

The post-phase changes are real and wired, and they are tested:
- quick 261017-gac: no-JS "More filters" disclosure, Occurrences refresh after save, "Custom rule" display with an escaped Callout, explicit dialog buttons, live failed-jobs badge;
- quick 261017-hp9: static-safe branded 404 / 500 / 503 pages, removable filter chips, per-date "Apple / Outlook (.ics)" and "Google Calendar" rows;
- the F0–F10 browser suite.

Results this round:
- 522/522 Unit+Feature and 18/18 Browser locally;
- Pint clean, PHPStan 0 errors;
- CI green at HEAD 06f097a on PHP 8.4 and 8.5, including the new Browser UAT step;
- both live ops probes pass against the owner's server.

Changes since round 3:
- The last ⚠️ PRESENT_BEHAVIOR_UNVERIFIED truth (01-09 #13) is now verified by the owner's UAT 6 live run and matching local drill log lines.
- The WR-06 override is carried forward unchanged.
- WR-15 is the one new warning: a read-only display issue with no data path. It is advisory and does not block.

The status is `human_needed` only because the verification rules never let backstop truths or flagged prohibitions pass silently. Both sit in the owner's backlog Phase 999.1. If the owner keeps that deferral, nothing in the codebase blocks moving on to Phase 2.

---

_Verified: 2026-10-17T12:48:41Z_
_Verifier: the agent (gsd-verifier)_
