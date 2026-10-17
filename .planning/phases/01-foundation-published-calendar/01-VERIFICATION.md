---
phase: 01-foundation-published-calendar
verified: 2026-10-17T09:30:47Z
status: gaps_found
score: 159/166 must-haves verified (5 roadmap SCs + 161 plan truths across 12 plans)
covered_files: [".env.example",".github/workflows/ci.yml",".planning/phases/01-foundation-published-calendar/01-01-PLAN.md",".planning/phases/01-foundation-published-calendar/01-01-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-04-PLAN.md",".planning/phases/01-foundation-published-calendar/01-04-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-05-PLAN.md",".planning/phases/01-foundation-published-calendar/01-05-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-06-PLAN.md",".planning/phases/01-foundation-published-calendar/01-06-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-07-PLAN.md",".planning/phases/01-foundation-published-calendar/01-07-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-08-PLAN.md",".planning/phases/01-foundation-published-calendar/01-08-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-09-PLAN.md",".planning/phases/01-foundation-published-calendar/01-09-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-10-PLAN.md",".planning/phases/01-foundation-published-calendar/01-10-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-11-PLAN.md",".planning/phases/01-foundation-published-calendar/01-11-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-12-PLAN.md",".planning/phases/01-foundation-published-calendar/01-12-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-13-PLAN.md",".planning/phases/01-foundation-published-calendar/01-13-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-14-PLAN.md",".planning/phases/01-foundation-published-calendar/01-14-SUMMARY.md","AGENTS.md","app/Calendar/CalendarFilters.php","app/Calendar/DateWindow.php","app/Calendar/EventTiming.php","app/Calendar/Export/GoogleCalendarLink.php","app/Calendar/Export/OccurrenceIcs.php","app/Calendar/Export/OccurrenceUid.php","app/Calendar/ManualRecurrence.php","app/Calendar/OccurrenceGenerator.php","app/Calendar/OccurrenceTiming.php","app/Calendar/PublicOccurrenceQuery.php","app/Calendar/RecurrenceExpander.php","app/Calendar/UpcomingDates.php","app/Console/Commands/OpsDrill.php","app/Console/Commands/OpsHealth.php","app/Console/Commands/SyncOccurrences.php","app/Enums/DateFilter.php","app/Enums/EventOrigin.php","app/Enums/EventStatus.php","app/Enums/HealthState.php","app/Enums/OccurrenceStatus.php","app/Enums/PriceType.php","app/Enums/RepeatEnds.php","app/Enums/RepeatPattern.php","app/Filament/Pages/SystemHealth.php","app/Filament/Resources/Events/Actions/StatusActions.php","app/Filament/Resources/Events/EventResource.php","app/Filament/Resources/Events/Pages/CreateEvent.php","app/Filament/Resources/Events/Pages/EditEvent.php","app/Filament/Resources/Events/Pages/ListEvents.php","app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php","app/Filament/Resources/Events/Schemas/EventForm.php","app/Filament/Resources/Events/Tables/EventsTable.php","app/Http/Controllers/CalendarController.php","app/Http/Controllers/Controller.php","app/Http/Controllers/EventController.php","app/Http/Controllers/OccurrenceIcsController.php","app/Jobs/OpsDrillJob.php","app/Jobs/QueueHeartbeat.php","app/Models/Category.php","app/Models/Event.php","app/Models/EventOccurrence.php","app/Models/EventSlug.php","app/Models/FailedJob.php","app/Models/Heartbeat.php","app/Models/User.php","app/Models/Venue.php","app/Ops/HealthCheck.php","app/Ops/HealthReport.php","app/Providers/AppServiceProvider.php","app/Providers/Filament/AdminPanelProvider.php","app/Publishing/Actions/ChangeEventStatus.php","app/Publishing/Actions/ChangeOccurrenceStatus.php","app/Publishing/Actions/PublishEvent.php","app/Publishing/Actions/UpdatePublishedEvent.php","app/Publishing/AdminGate.php","app/Publishing/Data/PublishableEventData.php","app/Publishing/EventSlugger.php","app/Publishing/Exceptions/InvalidStatusTransition.php","app/Seo/EventJsonLd.php","app/Support/EventPageData.php","app/Support/LondonFormatter.php","app/Support/OccurrenceCardData.php","app/Support/PublicUrl.php","app/Support/SafeLinks.php","app/Support/ShareLinks.php","app/Support/TextLimit.php","composer.json","config/calendar.php","config/logging.php","config/ops.php","config/site.php","database/migrations/0001_01_01_000000_create_users_table.php","database/migrations/0001_01_01_000001_create_cache_table.php","database/migrations/0001_01_01_000002_create_jobs_table.php","database/migrations/2026_10_16_000100_enable_pg_trgm.php","database/migrations/2026_10_16_000110_create_categories_table.php","database/migrations/2026_10_16_000120_create_venues_table.php","database/migrations/2026_10_16_000130_create_events_table.php","database/migrations/2026_10_16_000140_create_event_occurrences_table.php","database/migrations/2026_10_16_000150_add_status_audit_columns.php","database/migrations/2026_10_16_000160_create_heartbeats_table.php","database/migrations/2026_10_17_000100_make_event_slugs_unique.php","database/migrations/2026_10_17_000200_create_event_slugs_table.php","database/seeders/CategorySeeder.php","database/seeders/DatabaseSeeder.php","ops/check-event-page.sh","ops/check-public-assets.sh","phpstan.neon","phpunit.xml","resources/css/app.css","resources/css/filament/admin/theme.css","resources/js/share.js","resources/views/calendar/index.blade.php","resources/views/calendar/partials/chip.blade.php","resources/views/calendar/partials/empty-state.blade.php","resources/views/calendar/partials/filter-chips.blade.php","resources/views/calendar/partials/occurrence-card.blade.php","resources/views/components/brand/calendar-pin.blade.php","resources/views/components/brand/logo.blade.php","resources/views/components/category-icon.blade.php","resources/views/components/icons/category/HEROICONS-LICENSE.txt","resources/views/components/icons/category/arts-culture.blade.php","resources/views/components/icons/category/charity.blade.php","resources/views/components/icons/category/community.blade.php","resources/views/components/icons/category/family.blade.php","resources/views/components/icons/category/food-drink.blade.php","resources/views/components/icons/category/market.blade.php","resources/views/components/icons/category/music.blade.php","resources/views/components/icons/category/other.blade.php","resources/views/components/icons/category/school.blade.php","resources/views/components/icons/category/sport.blade.php","resources/views/events/partials/dates.blade.php","resources/views/events/partials/share.blade.php","resources/views/events/show.blade.php","resources/views/filament/pages/system-health.blade.php","resources/views/layouts/app.blade.php","routes/console.php","routes/web.php","tests/Feature/Console/SyncOccurrencesCommandTest.php","tests/Feature/Filament/CreateEventTest.php","tests/Feature/Filament/CreateRecurringEventTest.php","tests/Feature/Filament/EditEventResilienceTest.php","tests/Feature/Filament/EditEventTest.php","tests/Feature/Filament/EditRepeatTypeTest.php","tests/Feature/Filament/StatusActionsTest.php","tests/Feature/Ops/AdminFontAndLoggingTest.php","tests/Feature/Ops/AdminThemeTest.php","tests/Feature/Ops/CalendarSyncHeartbeatTest.php","tests/Feature/Ops/FailedJobsTest.php","tests/Feature/Ops/HealthReportTest.php","tests/Feature/Ops/OpsFixtures.php","tests/Feature/Ops/QueueHeartbeatTest.php","tests/Feature/Ops/SchedulerHeartbeatTracerTest.php","tests/Feature/Public/AttributeFiltersTest.php","tests/Feature/Public/BrandHeadTest.php","tests/Feature/Public/CalendarFiltersTracerTest.php","tests/Feature/Public/CalendarListTest.php","tests/Feature/Public/DateFilterTest.php","tests/Feature/Public/EventCalendarAndShareTest.php","tests/Feature/Public/EventJsonLdTest.php","tests/Feature/Public/EventPageContentTest.php","tests/Feature/Public/EventPageTracerTest.php","tests/Feature/Public/EventSeoTest.php","tests/Feature/Public/EventSlugRedirectTest.php","tests/Feature/Public/HostHeaderAssetsTest.php","tests/Feature/Public/NoThirdPartyAssetsTest.php","tests/Feature/Public/OccurrenceCardTest.php","tests/Feature/Public/PublicLookTest.php","tests/Feature/Public/SlugRedirectCachingTest.php","tests/Feature/Public/StatusVisibilityTest.php","tests/Feature/Publishing/ChangeEventStatusTest.php","tests/Feature/Publishing/ChangeOccurrenceStatusTest.php","tests/Feature/Publishing/OccurrenceUidStabilityTest.php","tests/Feature/Publishing/PublishEventTest.php","tests/Feature/Publishing/UpdatePublishedEventTest.php","tests/Feature/Recurrence/RecurringOccurrencesTest.php","tests/Feature/Recurrence/RecurringSeriesTracerTest.php","tests/Feature/Status/CancelOccurrenceTracerTest.php","tests/Feature/Status/StatusSurvivesRegenerationTest.php","tests/Feature/WalkingSkeletonTest.php","tests/Fixtures/public/build/manifest.json","tests/Fixtures/views/brand-og-push.blade.php","tests/Pest.php","tests/TestCase.php","tests/Unit/Brand/ContrastTest.php","tests/Unit/Calendar/DateWindowTest.php","tests/Unit/Calendar/EventTimingTest.php","tests/Unit/Calendar/ManualRecurrenceTest.php","tests/Unit/Calendar/RecurrenceExpanderTest.php","tests/Unit/Enums/StatusTransitionsTest.php","tests/Unit/Ops/EnvExampleTest.php","tests/Unit/Publishing/PublishableEventDataTest.php","vite.config.js"]
covered_digest: "v3:sha256:243b8ed81ed3c572620a8d1c3a3791deee4870bd81c9c511078180639c86186b"
behavior_unverified: 1
overrides_applied: 1
overrides:
  - must_have: "Editing a series to 'Does not repeat' sets recurrence_rule NULL and leaves exactly one future occurrence keyed by start_date"
    reason: "Superseded by 01-13 WR-06: one-off <-> repeating switches are refused so OccurrenceUid UIDs never change; workaround is hide + create new event (old page 404s while hidden, new event gets a suffixed slug). The end_time clause of 01-04 #17 still holds and is tested"
    accepted_by: "yurabyrlya (project owner)"
    accepted_at: "2026-10-17T09:35:02Z"
re_verification:
  previous_status: gaps_found
  previous_score: 132/139
  gaps_closed:
    - "(01-08 #16) With the local app served (`php artisan serve`), ops/check-public-assets.sh http://localhost:8000 prints PUBLIC ASSETS OK (/ has no script element and no third-party host)"
  gaps_remaining: []
  regressions:
    - "(01-04 #17, first clause) Editing a series to 'Does not repeat' sets recurrence_rule NULL and leaves exactly one future occurrence keyed by start_date. This is now deliberately refused by 01-13 (WR-06)"
gaps:
  - truth: "(01-04 #17, first clause) Editing a series to 'Does not repeat' sets recurrence_rule NULL and leaves exactly one future occurrence keyed by start_date"
    status: failed
    reason: "Superseded by a design decision, not a bug. Gap plan 01-13 (WR-06) made UpdatePublishedEvent refuse every one-off <-> repeating switch with InvalidArgumentException (\"An event can't switch between one-off and repeating. Hide it and create a new event instead.\"), so OccurrenceUid's 'UID never changes' contract holds. The edit form now leaves 'Does not repeat' out of a series' Repeats options. The 01-04 test asserting the old behaviour was rewritten to assert the refusal (01-13-SUMMARY deviation 1). The two plan truths contradict each other and both cannot hold. The newer one is in force, and no override records that the owner accepts losing the 01-04 behaviour. No code change is needed. The admin's workaround (hide the series, create a new one-off) takes the old event page offline and gives the new event a new web address, which is why this needs the owner's sign-off rather than the verifier's."
    artifacts:
      - path: "app/Publishing/Actions/UpdatePublishedEvent.php"
        issue: "Type-switch guard `($locked->recurrence_rule === null) === $data->isRecurring()` throws before any write. Intended by 01-13, but it contradicts the 01-04 must-have"
      - path: "tests/Feature/Publishing/UpdatePublishedEventTest.php"
        issue: "The 01-04 test 'leaves exactly one future occurrence keyed by start_date when a series becomes \"Does not repeat\"' was renamed and inverted to 'refuses to make a series \"Does not repeat\"…'"
    missing:
      - "Owner decision: accept the WR-06 supersession by adding the override shown under Gaps Summary to this file's frontmatter (no code change), OR revert the WR-06 approach (needs a UID migration plan, per AGENTS.md)"
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
  - test: "Filament recurring-series walkthrough (01-04, updated by 01-13): create a monthly last-Friday series, check the 'Next dates' preview, save, edit only the time. Then open a one-off event's edit page and a series' edit page and read the Repeats field"
    expected: "Preview in UK format, occurrences on /, time change keeps dates and statuses. One-off: Repeats disabled at 'Does not repeat' with helper text about hiding and creating a new repeating event (new web address). Series: no 'Does not repeat' option, and helper text explains how to end or hide it"
    why_human: "Filament UI flow and wording"
  - test: "Filament status walkthrough (01-05 + 01-13 table actions): cancel and hide single dates, then hide / show / cancel / reinstate the whole event from the edit page AND from the events table row actions, watching /"
    expected: "Confirmations, correct badges, hidden items gone, per-date statuses kept. No delete action anywhere"
    why_human: "UI flow"
  - test: "Unsupported-rule edit page (01-13 WR-09): in tinker set an event's recurrence_rule to 'FREQ=DAILY', open its edit page"
    expected: "Page opens with a persistent warning \"This event's repeat rule can't be edited here. You can still hide or cancel it.\", no Save button, and Hide / Cancel still work"
    why_human: "Visual check of a Filament page state (behaviour is covered by EditEventResilienceTest)"
  - test: "System health and ops drill (01-09): `composer dev`, open /admin/system-health, `ops:drill --fail-for=60` twice, Retry one, Delete one. Also check the admin theme (quick task 261017-e46) lays the sections out with spacing in light and dark mode"
    expected: "OK states with UK times, 2 failed rows and a red badge that then clear; `ops:drill --status` shows failed=0; sections spaced in a grid, readable in dark mode"
    why_human: "Live scheduler/worker and visual look"
  - test: "Share and add-to-calendar (01-10): laptop at localhost: Share… (cancel, then share), Copy link, open the .ics in the calendar app, open the Google link. Phone at the LAN IP: fallbacks open WhatsApp / Facebook / email, .ics imports on iOS and Android. Firefox / no-JS: fallbacks show, with no dead button"
    expected: "As in 01-10 PLAN. Times are correct after 25 Oct. Assets still load from the LAN IP (they are root-relative since 01-11)"
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
**Verified:** 2026-10-17T09:30:47Z
**Status:** gaps_found. The prior gap is closed. One gap remains: a design change that needs your decision, with no code change required. Human checks follow once that is settled.
**Re-verification:** Yes, after gap closure (01-11, 01-14, 01-12, 01-13; quick task 261017-e46 ran alongside).

> **MVP-mode guard (carried forward, still open).** ROADMAP still marks Phase 1 `**Mode:** mvp`, but the goal is not a user story. As before, this report uses the ROADMAP success criteria plus a user-flow table built from the goal's clauses. To fix: run `/gsd-mvp-phase 1`, or remove `**Mode:** mvp`.

## Gaps closed

| Previous gap | Fix in code | Evidence run by the verifier |
|--------------|-------------|------------------------------|
| (01-08 #16) `ops/check-public-assets.sh http://localhost:8000` failed with "/ contains a script element" (Laravel Boost browser logger) | `.env.example:73` and `.env:72` set `BOOST_BROWSER_LOGS_WATCHER=false`. `tests/Unit/Ops/EnvExampleTest.php` locks the key in place | Run against the owner's running `php artisan serve` (php84 PID 90850 on 127.0.0.1:8000, left running): **exit 0, `PUBLIC ASSETS OK: css=/build/assets/app-CbcdgzcN.css fonts=2 third-party=0 host-probe=clean`**. `curl / \| grep -c '<script'` = 0 |

Code-review findings closed in the same round (all checked in code plus passing named tests):

| Finding | Closed by | Evidence |
|---------|-----------|----------|
| CR-01 Host-header URLs | `AppServiceProvider::pinUrlsToAppUrl` (`URL::forceRootUrl(app.url)`, https only when app.url is https, root-relative `Vite::createAssetPathsUsing`). `ops/check-public-assets.sh` spoofed-Host probe | HostHeaderAssetsTest passes. Live: `Host: evil.example` on the event page → 0 matches; stylesheet `href="/build/assets/…"` |
| WR-01 non-admin publish | `App\Publishing\AdminGate` used by PublishEvent (before the transaction), UpdatePublishedEvent, ChangeEventStatus, ChangeOccurrenceStatus, `User::canAccessPanel` | PublishEventTest "refuses an approver who is not the admin and writes nothing", case-insensitive match, empty ADMIN_EMAIL denies everyone |
| WR-02 DTO invariants | `PublishableEventData` constructor throws on 11 invalid cases | PublishableEventDataTest (one case per invariant) |
| WR-03 impossible UNTIL | `ManualRecurrence` constructor, `untilValue()`, `fromRrule()` round-trip checks | ManualRecurrenceTest (`2026-13-45`, `2026-02-30`, `UNTIL=20261345`) |
| WR-04 cached slug 301s | `EventController::REDIRECT_CACHE_CONTROL = 'no-cache, private'` on both event-page and .ics redirects | SlugRedirectCachingTest. Live `/events/FIRST-DEMO-EVENT` → `301`, `Location: http://localhost:8000/events/first-demo-event`, `Cache-Control: no-cache, private` |
| WR-05 midnight end | `LondonFormatter::lastDay` treats a timed end exactly at local midnight as exclusive | `pest --filter=midnight` 3/3 |
| WR-06 UID stability | UpdatePublishedEvent type-switch guard; EventForm edit-context Repeats options | OccurrenceUidStabilityTest, EditRepeatTypeTest (**this supersedes a 01-04 truth, see Gaps**) |
| WR-07 mass assignment | Event `#[Fillable]` without status/origin/approved_by/approved_at; PublishEvent `forceFill` | PublishEventTest "does not let publishing state … be mass-assigned" |
| WR-08 CI matrix | ci.yml `matrix.php` 8.4 + 8.5, `fail-fast: false` | `gh run list --limit 1`: run 39020901940 on 856e0ac = success; jobs `test (PHP 8.4)` success, `test (PHP 8.5)` success |
| WR-09 edit-page resilience | `ManualRecurrence::tryFromRrule`, `UpdatePublishedEvent::canEdit`, EditEvent read-only fallback, `StatusActions::forEvent()` as EventsTable record actions | EditEventResilienceTest (FREQ=DAILY, COUNT>999, impossible UNTIL) |

## Evidence run by the verifier (not taken from SUMMARYs)

| Check | Result |
|-------|--------|
| `docker start ae-pg; ./vendor/bin/pest` (full suite, once; Herd PHP 8.4.12) | **passed: 465 tests, 2732 assertions**, 1 warning, 0 failures (was 401) |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpstan analyse --memory-limit=1G` | passed, 0 errors |
| `gh run list --limit 1` | run 39020901940, `docs(01): record gap plan 01-13 completion`, main, **success**, headSha 856e0acc… = local HEAD. Both PHP legs green. The previous runs for 01-14 (39019366566) and 01-12 (39019933266) were also success |
| Named gap-closure tests (8 files) | 62/62 passed |
| `verify.artifacts`, 12 plans | 76/77. The one miss is the known moved pattern (`aria-current` lives in `chip.blade.php`, included by `filter-chips.blade.php`); the rendered chip carries `aria-current="true"`. Not a gap |
| `verify.key-links`, 12 plans | 43/44. The one miss is the known malformed `from` (`ci.yml (job test)`); checked by hand that ci.yml line 64 runs `npm run build` before the asset gates. Not a gap |
| `ops/check-public-assets.sh http://localhost:8000` | **exit 0, PUBLIC ASSETS OK … host-probe=clean** |
| `ops/check-event-page.sh http://localhost:8000` | exit 0, `EVENT PAGE OK: path=/events/first-demo-event jsonld=1 uid=1@whatsonattleborough.co.uk` |
| Debt markers (TBD/FIXME/XXX, TODO/HACK/placeholder) in the 46 files changed since c77976f | none |

## User Flow Coverage (from the goal's clauses)

| Step | Expected | Evidence in codebase | Status |
|------|----------|----------------------|--------|
| Open a mobile-first calendar on the local app | `/` lists upcoming occurrences under date headings, with no script and no third-party assets | `CalendarController` + `PublicOccurrenceQuery`. Live check-public-assets OK. Assets are root-relative, so they also load from a LAN IP | ✓ (look needs a human) |
| Browse admin-entered events incl. recurring | Admin creates one-off and weekly / nth-weekday series. Each occurrence appears once over 90 days | `CreateEvent` → `PublishEvent` (AdminGate, forceFill) → `OccurrenceGenerator::sync` → `RecurrenceExpander`. Recurrence tests pass | ✓ |
| Open event pages | `/events/{slug}` with SEO and JSON-LD; old slugs 301 (no-cache) | Live 200, uppercase-slug 301 with `no-cache, private`, unknown slug 404 | ✓ |
| Add to calendar | `.ics` with a UID that never changes, plus a Google link | `OccurrenceIcs` / `OccurrenceUid`. UID stability is now enforced (WR-06). Live `uid=1@whatsonattleborough.co.uk` | ✓ (phone import needs a human) |
| Share | Web Share plus fallbacks | `share.blade.php`, `share.js` (event page only). Host-spoof-safe share URLs | ✓ (share sheets need a human) |

## Goal Achievement

### Observable Truths: ROADMAP success criteria (the contract)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| SC1 | Phone visitor sees grouped upcoming occurrences; date / category / Free / Family filters work as plain links without JS; filter URLs `noindex` | ✓ VERIFIED (server side) | Unchanged code paths, tests green. Live `/?when=weekend` → `<meta name="robots" content="noindex, follow">`. `/` has 0 `<script` on the local app (the gap is closed) |
| SC2 | Admin creates weekly / nth-weekday manual series → once per occurrence over 90 days; multi-day windows; expiry rules; correct times across 25 Oct 2026 | ✓ VERIFIED | RecurringOccurrencesTest, RecurrenceExpanderTest, DateFilterTest pass in the full run. The WR-05 midnight display fix is in place |
| SC3 | Admin can edit, hide, unhide, cancel an event or a single occurrence; "Cancelled" badge; admin statuses survive regeneration | ✓ VERIFIED | Still true. 01-13 adds table record actions. The one-off ↔ series switch is no longer an allowed edit (see Gaps), but SC3 never required that switch |
| SC4 | Event page: slug, meta / canonical / OG, JSON-LD per occurrence, description and attribution; `.ics` stable UID / Google; share | ✓ VERIFIED (server side) | EventSeoTest, EventJsonLdTest, EventCalendarAndShareTest, OccurrenceUidStabilityTest, HostHeaderAssetsTest pass. Live EVENT PAGE OK |
| SC5 | CI on push to main runs Pest, Larastan, Pint on PostgreSQL; local heartbeat and failed jobs visible; logs rotate daily for 14 days | ✓ VERIFIED | CI green at 856e0ac on PHP 8.4 and 8.5 (postgres:18). SystemHealth page and logging config unchanged; tests pass |

### Observable Truths: PLAN must-haves (161 truths across 12 plans)

| Plan | Truths | Verified | Not verified | Notes |
|------|--------|----------|--------------|-------|
| 01-01 skeleton | 15 | 15 | 0 | Regression check. The PublishEvent rollback test is now driven by a valid DTO that fails after save (`Event::saved` flag asserted) |
| 01-04 recurrence | 19 | 17 | **1 FAILED**, 1 insufficient_spec | **#17 first clause ("series → 'Does not repeat' sets recurrence_rule NULL…") is superseded by 01-13 WR-06** and is now refused. The second clause (clearing end_time → +3h) is still tested ("sets ends_at NULL and expires_at = starts_at + 3h…"). The backstop row-lock truth is unchanged |
| 01-05 status | 18 | 17 | 1 insufficient_spec | #11 "no delete": the EventsTable record actions are `EditAction` + `StatusActions::forEvent()` only; `grep DeleteAction app/Filament` finds none |
| 01-06 filters / cards | 24 | 23 | 1 insufficient_spec | #20 (no source line when source and contact are missing) still holds for stored rows; the tests keep their assertions |
| 01-07 look | 19 | 19 | 0 | #2 "/ contains no script element" now also holds live on the local app |
| 01-08 event page | 16 | 16 | 0 | **#16 now VERIFIED (gap closed).** #5 tagline fallback is still asserted, now for a stored whitespace description (01-14). #10 "Listed by" is still asserted, now for a stored unattributed event |
| 01-09 ops | 16 | 14 | 1 insufficient_spec, 1 ⚠️ PRESENT_BEHAVIOR_UNVERIFIED | Unchanged. The live HEALTHY-within-6-minutes run plus drill needs running processes |
| 01-10 JSON-LD / share | 7 | 6 | 1 insufficient_spec | Unchanged |
| 01-11 gap: Boost / CR-01 / WR-04/05/08 | 7 | 7 | 0 | All seven checked live or by named test (see Gaps closed) |
| 01-14 gap: fixture alignment | 6 | 6 | 0 | `organiser@example.test` present in all 8 named fixture files (StatusSurvivesRegeneration ×2). Diffs read: EventSeoTest and EventPageContentTest changed only setup lines, assertions untouched. Suite green on 01-14's own commit (CI 39019366566) |
| 01-12 gap: publish write path | 6 | 6 | 0 | AdminGate is a single rule wired into 5 callers. Fillable verified. DTO / UNTIL tests pass. AGENTS.md line 59 updated |
| 01-13 gap: UID stability / edit resilience | 8 | 8 | 0 | Guards run on the locked row before any write. Form options, table actions and AGENTS.md line 40 match. "Existing behaviour preserved" holds, except the one inverted 01-04 test (counted under 01-04, not double-counted here) |

**Score:** 159/166 truths verified. 1 failed (an intended supersession that needs an override). 1 present but behaviour unverified. 5 `insufficient_spec` backstops routed to a human.

### Required Artifacts

All 77 declared artifacts across 12 plans exist and are substantive (76 pattern matches plus the moved `aria-current`). New artifacts read by the verifier: `AdminGate`, `PublishEvent`, `UpdatePublishedEvent`, the `Event` fillable attribute, `PublishableEventData` invariants, the `ManualRecurrence` UNTIL checks and `tryFromRrule`, `AppServiceProvider::pinUrlsToAppUrl`, both slug-redirect controllers, `LondonFormatter::lastDay`, `EventsTable`, `ops/check-public-assets.sh` host probe, `vite.config.js` and the Filament theme. None are stubs.

### Key Link Verification

| From | To | Via | Status |
|------|----|-----|--------|
| PublishEvent / UpdatePublishedEvent / ChangeEventStatus / ChangeOccurrenceStatus / User | AdminGate | `AdminGate::authorize` / `AdminGate::allows` | WIRED |
| PublishableEventData | ManualRecurrence | `new ManualRecurrence` in `recurrence()`; `tryFromRrule` in `fromEvent()` | WIRED |
| EditEvent / EventForm | UpdatePublishedEvent | `canEdit()` + `UNSUPPORTED_RULE_REFUSED`; edit-context Repeats options | WIRED |
| EventsTable | StatusActions → ChangeEventStatus | `recordActions([EditAction, ...StatusActions::forEvent()])` | WIRED |
| AppServiceProvider | layouts/app `@vite` | `Vite::createAssetPathsUsing` root-relative; live href `/build/assets/…` | WIRED |
| OccurrenceIcsController | EventController | shared `REDIRECT_CACHE_CONTROL` | WIRED |
| .env.example | Boost config | `BOOST_BROWSER_LOGS_WATCHER=false` (live: 0 script tags) | WIRED |
| AdminPanelProvider (quick task) | resources/css/filament/admin/theme.css | `->viteTheme(...)`; Vite entry added; public pages still serve only app.css | WIRED |
| Earlier links (01-01 … 01-10) | — | regression check via verify.key-links plus the green suite | WIRED |

### Data-Flow Trace (Level 4)

| Artifact | Data | Source | Real data | Status |
|----------|------|--------|-----------|--------|
| calendar/index | `$groups` | `PublicOccurrenceQuery::paginate` | yes, the live demo event | ✓ FLOWING |
| events/show | `EventPageData` | `EventController` → `upcomingForEvent` | yes | ✓ FLOWING |
| `.ics` | VCALENDAR | `findListedOccurrence` → `OccurrenceIcs` | yes, live UID | ✓ FLOWING |
| System health | heartbeats / failed_jobs | `HealthReport` | yes (tests) | ✓ FLOWING |

The only absolute non-app host on `/` is `http://127.0.0.1:8000/admin/events/create`. It is the demo event's admin-typed `source_url` (stored data, not a generated URL). Info only.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| No script, no third-party host, Host probe | `bash ops/check-public-assets.sh http://localhost:8000` | PUBLIC ASSETS OK … host-probe=clean | ✓ PASS |
| Uppercase-slug redirect cache header | `curl -D - /events/FIRST-DEMO-EVENT` | 301, Location from APP_URL, `Cache-Control: no-cache, private` | ✓ PASS |
| Spoofed Host on the event page | `curl -H 'Host: evil.example' /events/first-demo-event \| grep -c evil.example` | 0 | ✓ PASS |
| Filter URL noindex | `curl '/?when=weekend'` | robots noindex, follow | ✓ PASS |
| Unknown slug / admin gate | `curl /events/no-such-event`, `curl /admin` | 404; 302 → /admin/login | ✓ PASS |
| WR-01/02/03/06/09, CR-01, WR-04 | 8 named test files | 62/62 | ✓ PASS |
| WR-05 midnight | `pest --filter=midnight` | 3/3 | ✓ PASS |

### Probe Execution

There are no `scripts/*/tests/probe-*.sh`. The phase's live probes are the `ops/` scripts, run read-only against the owner's server on :8000.

| Probe | Command | Result | Status |
|-------|---------|--------|--------|
| `ops/check-public-assets.sh` | `bash ops/check-public-assets.sh http://localhost:8000` | exit 0, PUBLIC ASSETS OK, host-probe=clean | PASS |
| `ops/check-event-page.sh` | `bash ops/check-event-page.sh http://localhost:8000` | exit 0, EVENT PAGE OK | PASS |

### Requirements Coverage

All 20 Phase 1 IDs are claimed by at least one plan (gap plans re-claim CAL-01/06/07/08, EVT-01/02/06, OPS-01, ADM-01/02/03, REC-01). REQUIREMENTS.md maps no other ID to Phase 1, so nothing is orphaned. OPS-01b, OPS-02 and OPS-04b are Phase 5 by the owner's scope change and are not assessed.

| Requirement | Source Plan(s) | Status | Evidence |
|-------------|----------------|--------|----------|
| OPS-01 | 01-01, 01-11 | ✓ SATISFIED | php ^8.4, Laravel ^13.17, Filament ^5.10, PostgreSQL 18; CI green on PHP 8.4 and 8.5 |
| OPS-04 | 01-09 | ✓ SATISFIED (local) | Live process run is a human item |
| CAL-01 | 01-01, 01-06, 01-07, 01-11 | ✓ SATISFIED | Live list; root-relative assets |
| CAL-02 / 03 / 04 / 05 | 01-06 | ✓ SATISFIED | DateFilterTest, AttributeFiltersTest |
| CAL-06 | 01-06, 01-07, 01-11 | ✓ SATISFIED | No script on `/` now holds live on the local app |
| CAL-07 | 01-05, 01-06, 01-07, 01-11 | ✓ SATISFIED | OccurrenceCardTest incl. WR-05 |
| CAL-08 | 01-04, 01-12 | ✓ SATISFIED | 90-day horizon; strict UNTIL |
| EVT-01 / EVT-02 | 01-08, 01-11, 01-14 | ✓ SATISFIED | Event page, slug 301s with no-cache |
| EVT-03 | 01-10 | ✓ SATISFIED | EventJsonLdTest |
| EVT-06 | 01-08, 01-10, 01-11, 01-13 | ✓ SATISFIED | `.ics` with a UID that cannot change (WR-06) |
| EVT-07 | 01-10 | ✓ SATISFIED (markup) | Native share is a human item |
| REC-01 / REC-02 | 01-01, 01-04, 01-12 | ✓ SATISFIED | Generator upsert preserves status; EventTiming |
| ADM-01 | 01-01, 01-12 | ✓ SATISFIED | AdminGate is the single rule |
| ADM-02 | 01-01, 01-04, 01-12, 01-14 | ✓ SATISFIED | PublishEvent: origin manual, gated approver, forceFill |
| ADM-03 | 01-04, 01-05, 01-12, 01-13, 01-14 | ✓ SATISFIED | Edit / hide / unhide / cancel on events and occurrences; statuses survive regeneration. A narrower edit scope (no one-off ↔ series switch) is the open gap on a plan truth, not on ADM-03's wording |

### Prohibitions (ADR-550)

The 16 test-enforced prohibitions still pass in the full run. The gap plans declare no new prohibitions. **Four are still flagged as unverified prohibitions; a human review is recommended:**

| Prohibition | Tier | Disposition | Verifier's manual check (not authoritative) |
|-------------|------|-------------|------------------------------------------|
| 01-01 ADM-02: no public event bypasses `App\Publishing\Actions`; no seeded demo events | judgment | needs human resolution | `new Event(` only in PublishEvent (saved) and EventForm (unsaved preview). Status written only by PublishEvent / ChangeEventStatus / ChangeOccurrenceStatus `forceFill`. Holds |
| 01-01 OPS-01: no LLM/AI runtime package in `require` | test | unverified: no enforcing test | `require` = php, filament, laravel/framework, tinker, sabre/vobject, spatie/schema-org. Holds |
| 01-04 REC-01: sabre/vobject only; no hand-rolled recurrence loops | test | unverified: no enforcing test | No `addWeek(s)` / `addMonth(s)` / `nthOfMonth` in app/. Holds |
| 01-09 OPS-04: liveness not in cache; no HTTP route or Filament action writes a heartbeat | test | unverified: no enforcing test | `Heartbeat::beat` only in routes/console.php, QueueHeartbeat, OpsDrillJob, SyncOccurrences. Holds |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| 46 files changed since c77976f | — | TBD / FIXME / XXX / TODO / HACK / placeholder | none | none found |
| `tests/Feature/Publishing/UpdatePublishedEventTest.php` | ~110 | Earlier plan's test inverted to match a later decision | ⚠️ Warning (root of the gap) | Recorded as a deviation in 01-13-SUMMARY; needs the owner's acceptance (override) |
| `ops/check-public-assets.sh` | 103 | Host probe uses `curl … \|\| true`, so a failed probe request counts as clean | ℹ️ Info | The script's earlier requests to the same server would already have failed; low risk |
| `composer.json` require-dev | — | `deployer/deployer` present early | ℹ️ Info | Phase 5 tooling; dev-only |

### Human Verification Required

These remain once the gap is settled. The status becomes `human_needed` after you add the override.

1. **Phone / mobile look (01-07).** Light and dark themes, chips, home-screen icon, keyboard, 320px reflow. *Why human:* real screen.
2. **Browse with JavaScript disabled.** *Why human:* real browser.
3. **Filament recurring-series walkthrough, incl. the new Repeats wording (01-04 + 01-13).** One-off: Repeats disabled with workaround text. Series: no "Does not repeat". *Why human:* UI and wording.
4. **Filament status walkthrough from the edit page and the events table (01-05 + 01-13).** *Why human:* UI flow.
5. **Unsupported-rule edit page (WR-09).** Warning visible, no Save, Hide / Cancel work. *Why human:* visual state.
6. **System health, ops drill, and the admin theme look (01-09 + quick task).** Includes the PRESENT_BEHAVIOR_UNVERIFIED "HEALTHY within 6 minutes" run. *Why human:* live processes, visuals.
7. **Share and add-to-calendar on laptop and phone (01-10).** Includes .ics import on iOS / Android and assets loading from the LAN IP. *Why human:* native apps.
8. **Acknowledge the 5 insufficient_spec backstops.** *Why human:* concurrency or accepted limits.
9. **Review the 4 flagged prohibitions.** *Why human:* unverified prohibitions.

### Gaps Summary

**The previous gap is closed.** The local app on :8000 now serves `/` with no script element and no third-party host, and the Host-spoof probe is clean. Every code-review finding (CR-01, WR-01…WR-09) has code plus a passing named test. There are no regressions in tests or tooling: 465/465 Pest, Pint clean, Larastan 0 errors, CI green on PHP 8.4 and 8.5 at 856e0ac.

**One gap remains, and it needs a decision rather than code.** 01-04 must-have #17 promised that the admin can turn a series into a one-off ("Does not repeat" sets recurrence_rule NULL and keeps one occurrence). Gap plan 01-13 (WR-06) deliberately removed that ability so an occurrence's calendar UID can never change. The executor rewrote the 01-04 test to assert the refusal and recorded it as a deviation, but nothing records the owner accepting it. The admin-visible cost is real: to change an event's type, the admin hides it and creates a new one, which takes the old page offline and gives the event a new web address. The phase goal and all five ROADMAP success criteria still hold. No later phase covers this, so it is not deferred.

**This looks intentional.** To accept the deviation, add to this file's frontmatter (then re-run the status check; status becomes `human_needed`):

```yaml
overrides:
  - must_have: "Editing a series to 'Does not repeat' sets recurrence_rule NULL and leaves exactly one future occurrence keyed by start_date"
    reason: "Superseded by 01-13 WR-06: one-off <-> repeating switches are refused so OccurrenceUid UIDs never change; workaround is hide + create new event. The end_time clause of 01-04 #17 still holds and is tested"
    accepted_by: "{name}"
    accepted_at: "{ISO timestamp}"
```

---

_Verified: 2026-10-17T09:30:47Z_
_Verifier: the agent (gsd-verifier)_
