---
phase: 01-foundation-published-calendar
verified: 2026-10-17T10:03:27Z
status: human_needed
score: 167/173 must-haves verified (5 roadmap SCs + 168 plan truths across 13 plans; 1 by accepted override)
covered_files: [".env.example",".github/workflows/ci.yml",".planning/phases/01-foundation-published-calendar/01-01-PLAN.md",".planning/phases/01-foundation-published-calendar/01-01-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-04-PLAN.md",".planning/phases/01-foundation-published-calendar/01-04-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-05-PLAN.md",".planning/phases/01-foundation-published-calendar/01-05-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-06-PLAN.md",".planning/phases/01-foundation-published-calendar/01-06-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-07-PLAN.md",".planning/phases/01-foundation-published-calendar/01-07-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-08-PLAN.md",".planning/phases/01-foundation-published-calendar/01-08-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-09-PLAN.md",".planning/phases/01-foundation-published-calendar/01-09-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-10-PLAN.md",".planning/phases/01-foundation-published-calendar/01-10-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-11-PLAN.md",".planning/phases/01-foundation-published-calendar/01-11-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-12-PLAN.md",".planning/phases/01-foundation-published-calendar/01-12-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-13-PLAN.md",".planning/phases/01-foundation-published-calendar/01-13-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-14-PLAN.md",".planning/phases/01-foundation-published-calendar/01-14-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-15-PLAN.md",".planning/phases/01-foundation-published-calendar/01-15-SUMMARY.md","AGENTS.md","app/Calendar/CalendarFilters.php","app/Calendar/DateWindow.php","app/Calendar/EventTiming.php","app/Calendar/Export/GoogleCalendarLink.php","app/Calendar/Export/OccurrenceIcs.php","app/Calendar/Export/OccurrenceUid.php","app/Calendar/ManualRecurrence.php","app/Calendar/OccurrenceGenerator.php","app/Calendar/OccurrenceTiming.php","app/Calendar/PublicOccurrenceQuery.php","app/Calendar/RecurrenceExpander.php","app/Calendar/UpcomingDates.php","app/Console/Commands/OpsDrill.php","app/Console/Commands/OpsHealth.php","app/Console/Commands/SyncOccurrences.php","app/Enums/DateFilter.php","app/Enums/EventOrigin.php","app/Enums/EventStatus.php","app/Enums/HealthState.php","app/Enums/OccurrenceStatus.php","app/Enums/PriceType.php","app/Enums/RepeatEnds.php","app/Enums/RepeatPattern.php","app/Filament/Pages/SystemHealth.php","app/Filament/Resources/Events/Actions/StatusActions.php","app/Filament/Resources/Events/EventResource.php","app/Filament/Resources/Events/Pages/CreateEvent.php","app/Filament/Resources/Events/Pages/EditEvent.php","app/Filament/Resources/Events/Pages/ListEvents.php","app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php","app/Filament/Resources/Events/Schemas/EventForm.php","app/Filament/Resources/Events/Tables/EventsTable.php","app/Http/Controllers/CalendarController.php","app/Http/Controllers/Controller.php","app/Http/Controllers/EventController.php","app/Http/Controllers/OccurrenceIcsController.php","app/Jobs/OpsDrillJob.php","app/Jobs/QueueHeartbeat.php","app/Models/Category.php","app/Models/Event.php","app/Models/EventOccurrence.php","app/Models/EventSlug.php","app/Models/FailedJob.php","app/Models/Heartbeat.php","app/Models/User.php","app/Models/Venue.php","app/Ops/HealthCheck.php","app/Ops/HealthReport.php","app/Providers/AppServiceProvider.php","app/Providers/Filament/AdminPanelProvider.php","app/Publishing/Actions/ChangeEventStatus.php","app/Publishing/Actions/ChangeOccurrenceStatus.php","app/Publishing/Actions/PublishEvent.php","app/Publishing/Actions/UpdatePublishedEvent.php","app/Publishing/AdminGate.php","app/Publishing/Data/PublishableEventData.php","app/Publishing/EventSlugger.php","app/Publishing/Exceptions/InvalidStatusTransition.php","app/Seo/EventJsonLd.php","app/Support/EventPageData.php","app/Support/LondonFormatter.php","app/Support/OccurrenceCardData.php","app/Support/PublicUrl.php","app/Support/SafeLinks.php","app/Support/ShareLinks.php","app/Support/TextLimit.php","composer.json","config/calendar.php","config/logging.php","config/ops.php","config/site.php","database/migrations/0001_01_01_000000_create_users_table.php","database/migrations/0001_01_01_000001_create_cache_table.php","database/migrations/0001_01_01_000002_create_jobs_table.php","database/migrations/2026_10_16_000100_enable_pg_trgm.php","database/migrations/2026_10_16_000110_create_categories_table.php","database/migrations/2026_10_16_000120_create_venues_table.php","database/migrations/2026_10_16_000130_create_events_table.php","database/migrations/2026_10_16_000140_create_event_occurrences_table.php","database/migrations/2026_10_16_000150_add_status_audit_columns.php","database/migrations/2026_10_16_000160_create_heartbeats_table.php","database/migrations/2026_10_17_000100_make_event_slugs_unique.php","database/migrations/2026_10_17_000200_create_event_slugs_table.php","database/seeders/CategorySeeder.php","database/seeders/DatabaseSeeder.php","ops/check-event-page.sh","ops/check-public-assets.sh","phpstan.neon","phpunit.xml","resources/css/app.css","resources/css/filament/admin/theme.css","resources/js/share.js","resources/views/calendar/index.blade.php","resources/views/calendar/partials/chip.blade.php","resources/views/calendar/partials/empty-state.blade.php","resources/views/calendar/partials/filter-chips.blade.php","resources/views/calendar/partials/occurrence-card.blade.php","resources/views/components/brand/calendar-pin.blade.php","resources/views/components/brand/logo.blade.php","resources/views/components/category-icon.blade.php","resources/views/components/icons/category/HEROICONS-LICENSE.txt","resources/views/components/icons/category/arts-culture.blade.php","resources/views/components/icons/category/charity.blade.php","resources/views/components/icons/category/community.blade.php","resources/views/components/icons/category/family.blade.php","resources/views/components/icons/category/food-drink.blade.php","resources/views/components/icons/category/market.blade.php","resources/views/components/icons/category/music.blade.php","resources/views/components/icons/category/other.blade.php","resources/views/components/icons/category/school.blade.php","resources/views/components/icons/category/sport.blade.php","resources/views/events/partials/dates.blade.php","resources/views/events/partials/share.blade.php","resources/views/events/show.blade.php","resources/views/filament/pages/system-health.blade.php","resources/views/layouts/app.blade.php","routes/console.php","routes/web.php","tests/Feature/Console/SyncOccurrencesCommandTest.php","tests/Feature/Filament/CreateEventTest.php","tests/Feature/Filament/CreateRecurringEventTest.php","tests/Feature/Filament/EditEventResilienceTest.php","tests/Feature/Filament/EditEventTest.php","tests/Feature/Filament/EditRepeatTypeTest.php","tests/Feature/Filament/StatusActionsTest.php","tests/Feature/Ops/AdminFontAndLoggingTest.php","tests/Feature/Ops/AdminThemeTest.php","tests/Feature/Ops/CalendarSyncHeartbeatTest.php","tests/Feature/Ops/FailedJobsTest.php","tests/Feature/Ops/HealthReportTest.php","tests/Feature/Ops/OpsFixtures.php","tests/Feature/Ops/QueueHeartbeatTest.php","tests/Feature/Ops/SchedulerHeartbeatTracerTest.php","tests/Feature/Public/AttributeFiltersTest.php","tests/Feature/Public/BrandHeadTest.php","tests/Feature/Public/CalendarFiltersTracerTest.php","tests/Feature/Public/CalendarListTest.php","tests/Feature/Public/DateFilterTest.php","tests/Feature/Public/EventCalendarAndShareTest.php","tests/Feature/Public/EventJsonLdTest.php","tests/Feature/Public/EventPageContentTest.php","tests/Feature/Public/EventPageTracerTest.php","tests/Feature/Public/EventSeoTest.php","tests/Feature/Public/EventSlugRedirectTest.php","tests/Feature/Public/HostHeaderAssetsTest.php","tests/Feature/Public/NoThirdPartyAssetsTest.php","tests/Feature/Public/OccurrenceCardTest.php","tests/Feature/Public/PublicLookTest.php","tests/Feature/Public/SlugRedirectCachingTest.php","tests/Feature/Public/StatusVisibilityTest.php","tests/Feature/Publishing/ChangeEventStatusTest.php","tests/Feature/Publishing/ChangeOccurrenceStatusTest.php","tests/Feature/Publishing/OccurrenceUidStabilityTest.php","tests/Feature/Publishing/PublishEventTest.php","tests/Feature/Publishing/UpdatePublishedEventTest.php","tests/Feature/Recurrence/RecurringOccurrencesTest.php","tests/Feature/Recurrence/RecurringSeriesTracerTest.php","tests/Feature/Status/CancelOccurrenceTracerTest.php","tests/Feature/Status/StatusSurvivesRegenerationTest.php","tests/Feature/WalkingSkeletonTest.php","tests/Fixtures/public/build/manifest.json","tests/Fixtures/views/brand-og-push.blade.php","tests/Pest.php","tests/TestCase.php","tests/Unit/Brand/ContrastTest.php","tests/Unit/Calendar/DateWindowTest.php","tests/Unit/Calendar/EventTimingTest.php","tests/Unit/Calendar/ManualRecurrenceTest.php","tests/Unit/Calendar/RecurrenceExpanderTest.php","tests/Unit/Enums/StatusTransitionsTest.php","tests/Unit/Ops/EnvExampleTest.php","tests/Unit/Publishing/PublishableEventDataTest.php","vite.config.js"]
covered_digest: "v3:sha256:4a6a3f85f7b5709196bd7473dac4bd2f627009820e5479c9083cc93c24bdba6d"
behavior_unverified: 1
overrides_applied: 1
overrides:
  - must_have: "Editing a series to 'Does not repeat' sets recurrence_rule NULL and leaves exactly one future occurrence keyed by start_date"
    reason: "Superseded by 01-13 WR-06: one-off <-> repeating switches are refused so OccurrenceUid UIDs never change; workaround is hide + create new event (old page 404s while hidden, new event gets a suffixed slug). The end_time clause of 01-04 #17 still holds and is tested"
    accepted_by: "yurabyrlya (project owner)"
    accepted_at: "2026-10-17T09:35:02Z"
re_verification:
  previous_status: gaps_found
  previous_score: 159/166
  gaps_closed:
    - "(01-04 #17, first clause) Editing a series to 'Does not repeat' sets recurrence_rule NULL and leaves exactly one future occurrence keyed by start_date: closed by the owner's override (superseded by 01-13 WR-06), no code change"
  gaps_remaining: []
  regressions: []
advisory:
  - finding: "On the read-only edit page of a series whose stored rule does not round-trip, the hydrated Repeats state is 'none', but a series' edit-context options (EventForm::repeatOptions with editedEventRepeats=true) leave out 'none'. The disabled select may therefore show blank instead of 'Does not repeat', next to the series helper text about end dates. This dates from 01-13 (FREQ=DAILY rows) and 01-15 now also routes BYDAY=MO / 2TU / -1FR here"
    category: other
    reason: "Raised by reading EventForm.php:114-121, 237-271 against PublishableEventData::fromEvent. Purely cosmetic: the persistent warning shows, Save is absent and every save path refuses. Resolve by looking at the page (human item 5) and, if it is confusing, adding 'none' back to the options when canEdit() is false"
    evidence_status: "none provided"
behavior_unverified_items:
  - truth: "(01-09 #13) Locally, with `php artisan schedule:work` and `php artisan queue:work database` running (or `composer dev`), ops:health prints HEALTHY within 6 minutes; the local drill shows the failed job in failed_jobs, in ops:health and in today's daily log, then sees it succeed"
    test: "Run `composer dev` (or serve + schedule:work + queue:work), wait up to 6 minutes, run `php artisan ops:health`. Then `php artisan ops:drill --fail-for=10`, let the worker run, `queue:retry`, let it run again, `php artisan ops:drill --status`"
    expected: "HEALTHY within 6 minutes. The drill shows failed jobs: 1 in ops:health and a line in storage/logs/laravel-YYYY-MM-DD.log, then failed=0 with a fresh last_success"
    why_human: "Needs a real scheduler and worker running together for several minutes. Feature tests cover each part (heartbeat writes, thresholds, drill job), not the live processes together"
human_verification:
  - test: "Phone / mobile look (01-07): `npm run build`, `php artisan serve --host 0.0.0.0 --port 8000`, open on a phone or in DevTools emulation in light and dark themes; tap This weekend / a category / Free; add to home screen; Tab through; 320px width with a long unbroken title"
    expected: "Wordmark, Atkinson font, Norfolk green, AA-readable text, thumb-sized chips with a tick on the active one, category icons, readable 'Cancelled', green calendar-pin home icon, 'Skip to main content' first Tab stop, focus rings, no sideways scroll"
    why_human: "Colour, touch and reflow on a real screen"
  - test: "Browse with JavaScript disabled: use every chip and 'More events'"
    expected: "The same filtered lists, no dead controls"
    why_human: "Real browser behaviour; server tests prove only the markup"
  - test: "Filament recurring-series walkthrough (01-04, updated by 01-13): create a monthly last-Friday series, check the 'Next dates' preview, save, edit only the time. Then open a one-off event's edit page and a series' edit page and read the Repeats field. Also check the events list's Repeats column"
    expected: "Preview in UK format, occurrences on /, time change keeps dates and statuses. One-off: Repeats disabled at 'Does not repeat' with helper text about hiding and creating a new repeating event (new web address). Series: no 'Does not repeat' option, and helper text explains how to end or hide it. The app-created series stays editable (Save present, no warning) and the list says e.g. 'Monthly on the last Friday'"
    why_human: "Filament UI flow and wording"
  - test: "Filament status walkthrough (01-05 + 01-13 table actions): cancel and hide single dates, then hide / show / cancel / reinstate the whole event from the edit page AND from the events table row actions, watching /"
    expected: "Confirmations, correct badges, hidden items gone, per-date statuses kept. No delete action anywhere"
    why_human: "UI flow"
  - test: "Unsupported / non-round-tripping rule edit page (01-13 WR-09 + 01-15 WR-10): in tinker set one event's recurrence_rule to 'FREQ=DAILY' and another Friday-start event's to 'FREQ=WEEKLY;BYDAY=MO'. Open both edit pages and the events list"
    expected: "Both pages open with the persistent warning \"This event's repeat rule can't be edited here. You can still hide or cancel it.\", no Save button, and Hide / Cancel still work. The list shows 'Repeats' (not 'Weekly on Friday') for the Monday rule. Decide whether a blank Repeats select on these pages is acceptable (see Advisory)"
    why_human: "Visual check of a Filament page state (behaviour is covered by EditEventResilienceTest)"
  - test: "System health and ops drill (01-09): `composer dev`, open /admin/system-health, `ops:drill --fail-for=60` twice, Retry one, Delete one. Also check the admin theme (quick task 261017-e46) lays the sections out with spacing in light and dark mode"
    expected: "OK states with UK times, 2 failed rows and a red badge that then clear; `ops:drill --status` shows failed=0; sections spaced in a grid, readable in dark mode"
    why_human: "Live scheduler/worker and visual look"
  - test: "Share and add-to-calendar (01-10): laptop at localhost: Share… (cancel, then share), Copy link, open the .ics in the calendar app, open the Google link. Phone at the LAN IP: fallbacks open WhatsApp / Facebook / email, .ics imports on iOS and Android. Firefox / no-JS: fallbacks show, with no dead button"
    expected: "As in 01-10 PLAN. Times are correct after 25 Oct. Assets and the .ics link load from the LAN IP (root-relative). Note (review IN-11): shared / canonical URLs are built from APP_URL, so they read http://localhost:8000/… unless APP_URL is set to the LAN address for the phone test. That is by design, not a defect"
    why_human: "Native share sheets, calendar apps, Google's undocumented template URL"
  - test: "Acknowledge insufficient_spec backstop truths: (a) edit / sync / status row-lock serialisation (01-04, 01-05); (b) heartbeat upsert under overlapping runs (01-09); (c) share.js re-entry and AbortError (01-10, covered by the share item); (d) accepted ZWJ-emoji split at the 140-character excerpt cut (01-06)"
    expected: "Owner accepts each, or asks for a concurrency test"
    why_human: "Concurrency or accepted limits with no deterministic test"
  - test: "Review 4 flagged prohibitions (1 judgment-tier, 3 test-tier with no enforcement test). See the Prohibitions table"
    expected: "Accept the verifier's manual check or add enforcement tests (e.g. a Pest arch / composer.json assertion)"
    why_human: "Unverified prohibitions; the verifier's check is not authoritative"
---

# Phase 1: Foundation & Published Calendar Verification Report

**Phase Goal:** Residents can open a mobile-first calendar on the local app and browse admin-entered events (including recurring ones), open event pages, add them to their calendar and share them
**Verified:** 2026-10-17T10:03:27Z
**Status:** human_needed. No automated gaps remain. What is left is the human checklist below (phone look, no-JS browsing, Filament walkthroughs, live ops drill, native share / .ics import, backstop and prohibition sign-off).
**Re-verification:** Yes, round 3: after the owner's override of the 01-04 #17 supersession and gap plan 01-15 (WR-10, WR-11).

> **MVP-mode guard (carried forward, still open).** ROADMAP still marks Phase 1 `**Mode:** mvp`, but the goal is not a user story. As before, this report uses the ROADMAP success criteria plus a user-flow table built from the goal's clauses. To fix: run `/gsd-mvp-phase 1`, or remove `**Mode:** mvp`.

## What changed since round 2

| Item | Resolution | Evidence |
|------|------------|----------|
| Gap: (01-04 #17 first clause) series → "Does not repeat" | **PASSED (override)**. Override: superseded by 01-13 WR-06 so occurrence UIDs never change; workaround is hide + create new event. Accepted by yurabyrlya (project owner) on 2026-10-17T09:35:02Z | Override entry in this file's frontmatter (preserved). The second clause (clearing end_time → +3h) is still tested and passes |
| WR-10: stored RRULE editable only if it round-trips (gap plan 01-15) | Fixed in code | `ManualRecurrence::tryFromStoredRule` (parse → `isValidFor` → `toRrule(...) ===` stored). `PublishableEventData::editableRecurrence` is the one Event adapter, used by `UpdatePublishedEvent::canEdit` (locked row, before any write), `fromEvent`, and `EventsTable::repeatsLabel`. EditEvent's mount / `beforeValidate` / `handleRecordUpdate` all go through `canEdit` |
| WR-11: EventOccurrence status not fillable (gap plan 01-15) | Fixed in code | `#[Fillable(['event_id', 'recurrence_key', 'starts_at', 'ends_at', 'expires_at'])]`. Writers: generator `upsert` (update columns are starts_at / ends_at / expires_at only), `ChangeOccurrenceStatus` `forceFill`, test helper `forceCreate`. The mass-assignment grep gate over app/ prints nothing |

## Evidence run by the verifier (not taken from SUMMARYs)

| Check | Result |
|-------|--------|
| `docker start ae-pg; ./vendor/bin/pest` (full suite, once; Herd PHP 8.4.12) | **passed: 504 tests, 2992 assertions**, 0 failures (was 465). 1 warning with empty details. 01-15-SUMMARY shows it also occurs at the base commit |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | passed, 0 errors |
| `gh run list` / `gh run view 39023124365` | run on **969ebdb** (`docs(01): record gap plan 01-15 completion`, main) = local HEAD: **success**. Jobs `test (PHP 8.4)` success, `test (PHP 8.5)` success. The two previous runs (0650121, f332b0a) were also success |
| 01-15 named test files (EditEventResilienceTest, ManualRecurrenceTest, ChangeOccurrenceStatusTest), JUnit parsed | 80/80 passed, 0 failures. Cases present: 6 "accepts every emitted RRULE as editable" rows (incl. GMT and 25 Oct 2026 BST-ends rows), 7 rejection rows, null → "Does not repeat", 2 domain-refusal rows, 2 "keeps occurrences, an admin cancellation and the .ics UID…" rows, table label test, mass-assignment test |
| Pure spot-check (no DB) | `tryFromStoredRule('FREQ=WEEKLY;BYDAY=MO', Fri 23 Oct)` → null → fallback `toFormState()` = `repeats => none, repeat_ends => never`. `tryFromStoredRule('FREQ=WEEKLY;BYDAY=FR', …)->label()` = `Weekly on Friday` |
| `verify.artifacts`, 13 plans | 78/80. Misses: (1) known `aria-current` moved to `chip.blade.php` (01-06, carried forward); (2) **new**: 01-13 artifact `UpdatePublishedEvent.php contains tryFromRrule`, see below |
| `verify.key-links`, 13 plans | 44/46. Misses: (1) known malformed `from` `ci.yml (job test)` (01-07, checked by hand); (2) **new**: 01-13 link `EditEvent → PublishableEventData via tryFromRrule`, see below |
| `ops/check-public-assets.sh http://localhost:8000` (owner's `php artisan serve`, php84 PID 90850, left running) | **exit 0**, `PUBLIC ASSETS OK: css=/build/assets/app-CbcdgzcN.css fonts=2 third-party=0 host-probe=clean` |
| `ops/check-event-page.sh http://localhost:8000` | **exit 0**, `EVENT PAGE OK: path=/events/first-demo-event jsonld=1 uid=1@whatsonattleborough.co.uk` |
| Debt markers (TBD / FIXME / XXX / TODO / HACK) in the 8 code and test files 01-15 changed | none |

**Two new tool misses, both stale literal patterns rather than broken wiring.** 01-15 deliberately replaced the direct `ManualRecurrence::tryFromRrule` call in `UpdatePublishedEvent::canEdit` and `PublishableEventData::fromEvent` with `editableRecurrence()` → `tryFromStoredRule()`, which calls `tryFromRrule` first (`ManualRecurrence.php:157`). Every rule `tryFromRrule` rejects is still rejected. The check is now stricter, and the 01-13 truths ("refuses any edit to an event whose stored rule ManualRecurrence::tryFromRrule cannot represent"; fromEvent "falls back to Does-not-repeat form state instead of throwing") still hold. The 01-13 FREQ=DAILY / COUNT>999 / impossible-UNTIL rows still pass in EditEventResilienceTest. Both are classified ✓ VERIFIED (indirect). Neither is a gap.

## User Flow Coverage (from the goal's clauses)

| Step | Expected | Evidence in codebase | Status |
|------|----------|----------------------|--------|
| Open a mobile-first calendar on the local app | `/` lists upcoming occurrences under date headings, with no script and no third-party assets | `CalendarController` + `PublicOccurrenceQuery`. Live check-public-assets OK. Assets and nav links are root-relative | ✓ (look needs a human) |
| Browse admin-entered events incl. recurring | Admin creates one-off and weekly / nth-weekday series. Each occurrence appears once over 90 days | `CreateEvent` → `PublishEvent` (AdminGate, forceFill) → `OccurrenceGenerator::sync` → `RecurrenceExpander`. Recurrence tests pass. 01-15 keeps every app-written rule editable (6 emitted-rule rows) | ✓ |
| Open event pages | `/events/{slug}` with SEO and JSON-LD; old slugs 301 (no-cache) | Live EVENT PAGE OK; slug / SEO tests pass | ✓ |
| Add to calendar | `.ics` with a UID that never changes, plus a Google link | `OccurrenceIcs` / `OccurrenceUid`. WR-06 (no type switch) plus WR-10: an unrelated edit can no longer rewrite a stored rule and delete imported UIDs. The preservation test checks the UID line before and after a refused save | ✓ (phone import needs a human) |
| Share | Web Share plus fallbacks | `share.blade.php`, `share.js` (event page only). Share URLs come from APP_URL (Host-spoof-safe) | ✓ (share sheets need a human) |

## Goal Achievement

### Observable Truths: ROADMAP success criteria (the contract)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| SC1 | Phone visitor sees grouped upcoming occurrences; date / category / Free / Family filters work as plain links without JS; filter URLs `noindex` | ✓ VERIFIED (server side) | Code paths unchanged by 01-15; suite green; live `/` has no script and no third-party host |
| SC2 | Admin creates weekly / nth-weekday manual series → once per occurrence over 90 days; multi-day windows; expiry rules; correct times across 25 Oct 2026 | ✓ VERIFIED | RecurringOccurrencesTest, RecurrenceExpanderTest, DateFilterTest pass. 01-15 adds a 25 Oct 2026 BST-ends UNTIL row that round-trips |
| SC3 | Admin can edit, hide, unhide, cancel an event or a single occurrence; "Cancelled" badge; admin statuses survive regeneration | ✓ VERIFIED | Strengthened by 01-15: a refused save of a non-round-tripping series leaves occurrence ids, keys, starts_at, the admin cancellation and its audit identical (preservation test, 2 rows). Occurrence status can be changed only through ChangeOccurrenceStatus (WR-11) |
| SC4 | Event page: slug, meta / canonical / OG, JSON-LD per occurrence, description and attribution; `.ics` stable UID / Google; share | ✓ VERIFIED (server side) | EventSeoTest, EventJsonLdTest, EventCalendarAndShareTest, OccurrenceUidStabilityTest pass. Live EVENT PAGE OK |
| SC5 | CI on push to main runs Pest, Larastan, Pint on PostgreSQL; local heartbeat and failed jobs visible; logs rotate daily for 14 days | ✓ VERIFIED | CI green at 969ebdb on PHP 8.4 and 8.5. SystemHealth and logging unchanged; tests pass. The live run is a human item (01-09 #13) |

### Observable Truths: PLAN must-haves (168 truths across 13 plans)

| Plan | Truths | Verified | Not verified | Notes |
|------|--------|----------|--------------|-------|
| 01-01 skeleton | 15 | 15 | 0 | Regression check (suite green, artifacts and links 7/7, 3/3) |
| 01-04 recurrence | 19 | 18 (1 by override) | 1 insufficient_spec | **#17 first clause: PASSED (override)**, owner-accepted supersession by WR-06. Second clause still tested. Row-lock backstop unchanged |
| 01-05 status | 18 | 17 | 1 insufficient_spec | No delete action in app/Filament (EventsTable: EditAction + StatusActions only) |
| 01-06 filters / cards | 24 | 23 | 1 insufficient_spec | `aria-current` pattern lives in `chip.blade.php`, as before |
| 01-07 look | 19 | 19 | 0 | Live `/` has no script |
| 01-08 event page | 16 | 16 | 0 | Live probes pass |
| 01-09 ops | 16 | 14 | 1 insufficient_spec, 1 ⚠️ PRESENT_BEHAVIOR_UNVERIFIED | Unchanged. The live HEALTHY-within-6-minutes run plus drill needs running processes |
| 01-10 JSON-LD / share | 7 | 6 | 1 insufficient_spec | Unchanged |
| 01-11 gap: Boost / CR-01 / WR-04/05/08 | 7 | 7 | 0 | Live host-probe clean; CI matrix green |
| 01-14 gap: fixture alignment | 6 | 6 | 0 | Regression check |
| 01-12 gap: publish write path | 6 | 6 | 0 | Regression check; `new Event(` only in PublishEvent and the EventForm preview |
| 01-13 gap: UID stability / edit resilience | 8 | 8 | 0 | WR-09 truths now hold through the stricter round-trip check (see the tool-miss note above). AGENTS.md "Writes" bullet reworded by 01-15 and still states both refusals |
| **01-15 gap: WR-10 / WR-11** | 7 | 7 | 0 | See the breakdown below |

**01-15 truths in detail**

| # | Truth (short) | Status | Evidence |
|---|---------------|--------|----------|
| 1 | WR-10: editable only if the stored rule round-trips byte-identically; one-offs editable; all 6 emitted rules editable | ✓ VERIFIED | `tryFromStoredRule` implements parse → `isValidFor` → `toRrule ===`; null → `new self`. "accepts every emitted RRULE…" 6/6 incl. `UNTIL=20270101T235959Z` and `UNTIL=20261025T235959Z` (each appears once in the test file) |
| 2 | WR-10 (form): the 5 listed rules open read-only with the warning and no Save; a forced save is refused and changes nothing | ✓ VERIFIED | 5 new rows in the 'unsupported rules' dataset, run by the read-only, forced-save and hide tests; all pass |
| 3 | WR-10 (domain): direct UpdatePublishedEvent call throws UNSUPPORTED_RULE_REFUSED for MO / 2TU; app-created weekly still succeeds | ✓ VERIFIED | `canEdit($locked)` runs inside the transaction before the WR-06 guard and before `fill/save/sync`. 2 domain rows pass; existing UpdatePublishedEventTest / EditEventTest pass |
| 4 | WR-10 (data): occurrence rows, admin cancellation and audit, and the .ics UID line unchanged after a refused page save and a refused action call | ✓ VERIFIED (behavioral) | State invariant exercised by "keeps occurrences, an admin cancellation and the .ics UID…" (2 rows). It guards against a vacuous pass (keys asserted present, cancellation and UID asserted before the save attempts) |
| 5 | WR-10 (display): form state and table label share the round-trip check; a non-round-tripping rule shows 'Does not repeat' state / 'Repeats' label; app weekly shows 'Weekly on Friday' | ✓ VERIFIED | Table: `assertTableColumnStateSet` for 'Repeats' / 'Weekly on Friday' / 'One-off'. Form: `fromEvent` uses `editableRecurrence ?? new ManualRecurrence`; spot-check gives `repeats => none`. How the disabled select renders is in Advisory and human item 5 |
| 6 | WR-11: fillable is exactly the 5 schedule columns; status and audit not mass-assignable; all writers still work; no app code mass-assigns occurrences | ✓ VERIFIED | Fillable line matches exactly; mass-assignment test passes (`isFillable` false ×3, forged `cancelled` stays `Scheduled` / raw `'scheduled'`); writers read in code; grep gate empty; StatusSurvivesRegeneration / SyncOccurrences / ChangeOccurrenceStatus tests pass in the full run |
| 7 | Existing behaviour preserved: full suite (≥465), Pint, PHPStan | ✓ VERIFIED | 504/504, Pint clean, PHPStan 0 errors, CI green on both PHP legs |

**Score:** 167/173 truths verified (1 of them PASSED by override). 1 present but behaviour unverified. 5 `insufficient_spec` backstops routed to a human. 0 failed.

### Required Artifacts

All 80 declared artifacts across 13 plans exist and are substantive. 78 match their tool pattern. The 2 misses are the moved `aria-current` (01-06) and the 01-13 `UpdatePublishedEvent contains tryFromRrule` literal, which 01-15 replaced by design with `editableRecurrence`. The 01-15 artifacts all pass (6/6): `tryFromStoredRule`, `editableRecurrence` (×2 files), `BYDAY=2TU` repro dataset, the exact Fillable line, and `isFillable`. None are stubs.

### Key Link Verification

| From | To | Via | Status |
|------|----|-----|--------|
| EditEvent (mount, beforeValidate, handleRecordUpdate) | UpdatePublishedEvent | `UpdatePublishedEvent::canEdit` (lines 51, 114 via `refuseUnsupportedRule`) | WIRED |
| UpdatePublishedEvent::canEdit | ManualRecurrence | `PublishableEventData::editableRecurrence` → `tryFromStoredRule` → `tryFromRrule` | WIRED |
| EventsTable::repeatsLabel | PublishableEventData | `editableRecurrence($event)`; `'Repeats'` when null | WIRED |
| PublishableEventData::fromEvent | ManualRecurrence | `self::editableRecurrence($event) ?? new ManualRecurrence` (01-13 link's literal `tryFromRrule` is now one level down) | WIRED (indirect) |
| ChangeOccurrenceStatus / OccurrenceGenerator | event_occurrences | `forceFill` / `upsert` (update columns exclude status) | WIRED |
| PublishEvent / UpdatePublishedEvent / ChangeEventStatus / ChangeOccurrenceStatus / User | AdminGate | `AdminGate::authorize` / `allows` | WIRED (regression) |
| EventsTable | StatusActions → ChangeEventStatus | `recordActions([EditAction, ...StatusActions::forEvent()])` | WIRED (regression) |
| Earlier links (01-01 … 01-14) | — | verify.key-links 44/46 plus the green suite | WIRED |

### Data-Flow Trace (Level 4)

| Artifact | Data | Source | Real data | Status |
|----------|------|--------|-----------|--------|
| calendar/index | `$groups` | `PublicOccurrenceQuery::paginate` | yes, the live demo event | ✓ FLOWING |
| events/show | `EventPageData` | `EventController` → `upcomingForEvent` | yes | ✓ FLOWING |
| `.ics` | VCALENDAR | `findListedOccurrence` → `OccurrenceIcs` | yes, live UID | ✓ FLOWING |
| Events table Repeats column | label | `repeatsLabel` → `editableRecurrence` → stored `recurrence_rule` | yes (table test) | ✓ FLOWING |
| System health | heartbeats / failed_jobs | `HealthReport` | yes (tests) | ✓ FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| No script, no third-party host, Host probe | `bash ops/check-public-assets.sh http://localhost:8000` | PUBLIC ASSETS OK … host-probe=clean | ✓ PASS |
| Event page, JSON-LD, UID | `bash ops/check-event-page.sh http://localhost:8000` | EVENT PAGE OK … uid=1@whatsonattleborough.co.uk | ✓ PASS |
| Event-page link forms | `curl /events/first-demo-event \| grep href` | `.ics`, nav and assets root-relative; share / canonical from APP_URL (`http://localhost:8000/…`) | ✓ PASS |
| Round-trip fallback | `php -r` with `tryFromStoredRule` (no DB) | MO on Friday → `repeats => none`; FR → `Weekly on Friday` | ✓ PASS |
| WR-10 / WR-11 behaviour | 3 named test files | 80/80 | ✓ PASS |

### Probe Execution

There are no `scripts/*/tests/probe-*.sh`. The phase's live probes are the `ops/` scripts, run read-only against the owner's server on :8000.

| Probe | Command | Result | Status |
|-------|---------|--------|--------|
| `ops/check-public-assets.sh` | `bash ops/check-public-assets.sh http://localhost:8000` | exit 0, PUBLIC ASSETS OK, host-probe=clean | PASS |
| `ops/check-event-page.sh` | `bash ops/check-event-page.sh http://localhost:8000` | exit 0, EVENT PAGE OK | PASS |

### Requirements Coverage

All 20 Phase 1 IDs are claimed by at least one plan. The union of the 13 plans' `requirements:` equals the ROADMAP / REQUIREMENTS.md Phase 1 list exactly (20 rows map to Phase 1). Nothing is orphaned. 01-15 re-claims ADM-03, EVT-06 and REC-01. OPS-01b, OPS-02 and OPS-04b are Phase 5 by the owner's scope change and are not assessed.

| Requirement | Source Plan(s) | Status | Evidence |
|-------------|----------------|--------|----------|
| OPS-01 | 01-01, 01-11 | ✓ SATISFIED | php ^8.4, Laravel, Filament, PostgreSQL; CI green on PHP 8.4 and 8.5 |
| OPS-04 | 01-09 | ✓ SATISFIED (local) | Live process run is a human item |
| CAL-01 | 01-01, 01-06, 01-07, 01-11 | ✓ SATISFIED | Live list; root-relative assets |
| CAL-02 / 03 / 04 / 05 | 01-06 | ✓ SATISFIED | DateFilterTest, AttributeFiltersTest |
| CAL-06 | 01-06, 01-07, 01-11 | ✓ SATISFIED | No script on `/`, live |
| CAL-07 | 01-05, 01-06, 01-07, 01-11 | ✓ SATISFIED | OccurrenceCardTest |
| CAL-08 | 01-04, 01-12 | ✓ SATISFIED | 90-day horizon; strict UNTIL |
| EVT-01 / EVT-02 | 01-08, 01-11, 01-14 | ✓ SATISFIED | Event page, slug 301s with no-cache |
| EVT-03 | 01-10 | ✓ SATISFIED | EventJsonLdTest |
| EVT-06 | 01-08, 01-10, 01-11, 01-13, 01-15 | ✓ SATISFIED | UID cannot change by type switch (WR-06) or be deleted by an unrelated edit (WR-10 preservation test) |
| EVT-07 | 01-10 | ✓ SATISFIED (markup) | Native share is a human item |
| REC-01 | 01-01, 01-04, 01-12, 01-15 | ✓ SATISFIED | Generator upsert keeps status; WR-10 stops regeneration from wiping admin cancellations via a rewritten rule |
| REC-02 | 01-01, 01-04 | ✓ SATISFIED | EventTiming |
| ADM-01 | 01-01, 01-12 | ✓ SATISFIED | AdminGate is the single rule |
| ADM-02 | 01-01, 01-04, 01-12, 01-14 | ✓ SATISFIED | PublishEvent: origin manual, gated approver, forceFill |
| ADM-03 | 01-04, 01-05, 01-12, 01-13, 01-14, 01-15 | ✓ SATISFIED | Edit / hide / unhide / cancel on events and occurrences; occurrence status changes only via ChangeOccurrenceStatus (WR-11). The narrower edit scope (no one-off ↔ series switch) is accepted by override |

### Prohibitions (ADR-550)

The 16 test-enforced prohibitions still pass in the full run. 01-15 declares no prohibitions. **Four are still flagged as unverified prohibitions; a human review is recommended:**

| Prohibition | Tier | Disposition | Verifier's manual check (not authoritative), re-run this round |
|-------------|------|-------------|------------------------------------------|
| 01-01 ADM-02: no public event bypasses `App\Publishing\Actions`; no seeded demo events | judgment | needs human resolution | `new Event(` only in PublishEvent (saved) and EventForm (unsaved preview). Occurrence status is now also unfillable (WR-11). Holds |
| 01-01 OPS-01: no LLM/AI runtime package in `require` | test | unverified: no enforcing test | `require` = php, filament/filament, laravel/framework, laravel/tinker, sabre/vobject, spatie/schema-org. Holds |
| 01-04 REC-01: sabre/vobject only; no hand-rolled recurrence loops | test | unverified: no enforcing test | No `addWeek(s)` / `addMonth(s)` / `nthOfMonth` in app/. Holds |
| 01-09 OPS-04: liveness not in cache; no HTTP route or Filament action writes a heartbeat | test | unverified: no enforcing test | `Heartbeat::beat` only in routes/console.php, QueueHeartbeat, OpsDrillJob, SyncOccurrences. Holds |

### Advisory (New Scope, Unevidenced)

| # | Finding | Category | Why Advisory |
|---|---------|----------|--------------|
| 1 | The read-only edit page of a non-round-tripping series hydrates Repeats = 'none', but a series' edit options leave 'none' out, so the disabled select may render blank next to the series helper text | other | New scope (from 01-13, now reached by more rules), cosmetic, no deterministic evidence. Checked under human item 5 |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| 8 code and test files changed by 01-15 | — | TBD / FIXME / XXX / TODO / HACK | none | none found |
| `.planning/phases/01-…/01-REVIEW-DISPOSITION.md` | 6-13 | WR-10 / WR-11 still `disposition: open` | ℹ️ Info | Bookkeeping only. The code and tests close both; the orchestrator should mark them `fixed` |
| `.planning/phases/01-…/01-13-PLAN.md` | must_haves | Artifact and key-link patterns `tryFromRrule` are stale after 01-15 | ℹ️ Info | Tool misses only; the truths hold indirectly (see Evidence) |
| `ops/check-public-assets.sh` | 103 | Host probe `curl … \|\| true` counts a failed request as clean | ℹ️ Info | Carried forward; low risk |
| `composer.json` require-dev | — | `deployer/deployer` present early | ℹ️ Info | Phase 5 tooling; dev-only |

### Human Verification Required

1. **Phone / mobile look (01-07).** Light and dark themes, chips, home-screen icon, keyboard, 320px reflow. *Why human:* real screen.
2. **Browse with JavaScript disabled.** *Why human:* real browser.
3. **Filament recurring-series walkthrough, incl. the Repeats wording and the list's Repeats column (01-04 + 01-13 + 01-15).** *Why human:* UI and wording.
4. **Filament status walkthrough from the edit page and the events table (01-05 + 01-13).** *Why human:* UI flow.
5. **Unsupported and non-round-tripping rule edit pages (WR-09 + WR-10).** Try `FREQ=DAILY` and a Monday rule on a Friday start: warning visible, no Save, Hide / Cancel work, list shows "Repeats". Decide whether a blank Repeats select is acceptable. *Why human:* visual state.
6. **System health, ops drill, and the admin theme look (01-09 + quick task).** Includes the PRESENT_BEHAVIOR_UNVERIFIED "HEALTHY within 6 minutes" run. *Why human:* live processes, visuals.
7. **Share and add-to-calendar on laptop and phone (01-10).** Includes .ics import on iOS / Android. Shared links use APP_URL (localhost) unless APP_URL is set to the LAN address (review IN-11). *Why human:* native apps.
8. **Acknowledge the 5 insufficient_spec backstops.** *Why human:* concurrency or accepted limits.
9. **Review the 4 flagged prohibitions.** *Why human:* unverified prohibitions.

### Gaps Summary

**No automated gaps remain.**

- The only round-2 gap (01-04 #17 first clause) is carried as PASSED (override), as the owner accepted.
- Gap plan 01-15 closes WR-10 and WR-11 in code, with the tests it promised:
  - a stored rule is editable only if it round-trips exactly;
  - the form, the forged Livewire save, the domain action and the table label all use that one check;
  - a refused save provably leaves occurrences, admin cancellations, audit data and .ics UIDs untouched;
  - occurrence status cannot be mass-assigned.
- Every rule the app writes itself still round-trips, including the GMT and 25 Oct 2026 UNTIL encodings.
- No regressions: 504/504 Pest, Pint clean, PHPStan 0 errors, CI green on PHP 8.4 and 8.5 at 969ebdb, and both live ops probes pass against the owner's server.

Two tool misses on 01-13's literal `tryFromRrule` patterns come from 01-15's planned refactor. They are not broken wiring. Production / infra items (OPS-01b, OPS-02, OPS-04b) are Phase 5 by the owner's scope change and were not assessed.

The phase stays `human_needed` until the nine checks above are done: one ⚠️ PRESENT_BEHAVIOR_UNVERIFIED ops truth, five backstops, four flagged prohibitions, and the UI / phone / native-app walkthroughs.

---

_Verified: 2026-10-17T10:03:27Z_
_Verifier: the agent (gsd-verifier)_
