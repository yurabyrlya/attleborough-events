---
phase: 01-foundation-published-calendar
verified: 2026-10-17T07:58:00Z
status: gaps_found
score: 132/139 must-haves verified (5 roadmap SCs + 134 plan truths)
covered_files: [".env.example",".github/workflows/ci.yml",".planning/phases/01-foundation-published-calendar/01-01-PLAN.md",".planning/phases/01-foundation-published-calendar/01-01-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-04-PLAN.md",".planning/phases/01-foundation-published-calendar/01-04-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-05-PLAN.md",".planning/phases/01-foundation-published-calendar/01-05-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-06-PLAN.md",".planning/phases/01-foundation-published-calendar/01-06-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-07-PLAN.md",".planning/phases/01-foundation-published-calendar/01-07-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-08-PLAN.md",".planning/phases/01-foundation-published-calendar/01-08-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-09-PLAN.md",".planning/phases/01-foundation-published-calendar/01-09-SUMMARY.md",".planning/phases/01-foundation-published-calendar/01-10-PLAN.md",".planning/phases/01-foundation-published-calendar/01-10-SUMMARY.md","app/Calendar/CalendarFilters.php","app/Calendar/DateWindow.php","app/Calendar/EventTiming.php","app/Calendar/Export/GoogleCalendarLink.php","app/Calendar/Export/OccurrenceIcs.php","app/Calendar/Export/OccurrenceUid.php","app/Calendar/ManualRecurrence.php","app/Calendar/OccurrenceGenerator.php","app/Calendar/OccurrenceTiming.php","app/Calendar/PublicOccurrenceQuery.php","app/Calendar/RecurrenceExpander.php","app/Calendar/UpcomingDates.php","app/Console/Commands/OpsDrill.php","app/Console/Commands/OpsHealth.php","app/Console/Commands/SyncOccurrences.php","app/Enums/DateFilter.php","app/Enums/EventOrigin.php","app/Enums/EventStatus.php","app/Enums/HealthState.php","app/Enums/OccurrenceStatus.php","app/Enums/PriceType.php","app/Enums/RepeatEnds.php","app/Enums/RepeatPattern.php","app/Filament/Pages/SystemHealth.php","app/Filament/Resources/Events/Actions/StatusActions.php","app/Filament/Resources/Events/EventResource.php","app/Filament/Resources/Events/Pages/CreateEvent.php","app/Filament/Resources/Events/Pages/EditEvent.php","app/Filament/Resources/Events/Pages/ListEvents.php","app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php","app/Filament/Resources/Events/Schemas/EventForm.php","app/Filament/Resources/Events/Tables/EventsTable.php","app/Http/Controllers/CalendarController.php","app/Http/Controllers/Controller.php","app/Http/Controllers/EventController.php","app/Http/Controllers/OccurrenceIcsController.php","app/Jobs/OpsDrillJob.php","app/Jobs/QueueHeartbeat.php","app/Models/Category.php","app/Models/Event.php","app/Models/EventOccurrence.php","app/Models/EventSlug.php","app/Models/FailedJob.php","app/Models/Heartbeat.php","app/Models/User.php","app/Models/Venue.php","app/Ops/HealthCheck.php","app/Ops/HealthReport.php","app/Providers/AppServiceProvider.php","app/Providers/Filament/AdminPanelProvider.php","app/Publishing/Actions/ChangeEventStatus.php","app/Publishing/Actions/ChangeOccurrenceStatus.php","app/Publishing/Actions/PublishEvent.php","app/Publishing/Actions/UpdatePublishedEvent.php","app/Publishing/Data/PublishableEventData.php","app/Publishing/EventSlugger.php","app/Publishing/Exceptions/InvalidStatusTransition.php","app/Seo/EventJsonLd.php","app/Support/EventPageData.php","app/Support/LondonFormatter.php","app/Support/OccurrenceCardData.php","app/Support/PublicUrl.php","app/Support/SafeLinks.php","app/Support/ShareLinks.php","app/Support/TextLimit.php","composer.json","config/calendar.php","config/logging.php","config/ops.php","config/site.php","database/migrations/0001_01_01_000000_create_users_table.php","database/migrations/0001_01_01_000001_create_cache_table.php","database/migrations/0001_01_01_000002_create_jobs_table.php","database/migrations/2026_10_16_000100_enable_pg_trgm.php","database/migrations/2026_10_16_000110_create_categories_table.php","database/migrations/2026_10_16_000120_create_venues_table.php","database/migrations/2026_10_16_000130_create_events_table.php","database/migrations/2026_10_16_000140_create_event_occurrences_table.php","database/migrations/2026_10_16_000150_add_status_audit_columns.php","database/migrations/2026_10_16_000160_create_heartbeats_table.php","database/migrations/2026_10_17_000100_make_event_slugs_unique.php","database/migrations/2026_10_17_000200_create_event_slugs_table.php","database/seeders/CategorySeeder.php","database/seeders/DatabaseSeeder.php","ops/check-event-page.sh","ops/check-public-assets.sh","phpstan.neon","phpunit.xml","resources/css/app.css","resources/js/share.js","resources/views/calendar/index.blade.php","resources/views/calendar/partials/chip.blade.php","resources/views/calendar/partials/empty-state.blade.php","resources/views/calendar/partials/filter-chips.blade.php","resources/views/calendar/partials/occurrence-card.blade.php","resources/views/components/brand/calendar-pin.blade.php","resources/views/components/brand/logo.blade.php","resources/views/components/category-icon.blade.php","resources/views/components/icons/category/HEROICONS-LICENSE.txt","resources/views/components/icons/category/arts-culture.blade.php","resources/views/components/icons/category/charity.blade.php","resources/views/components/icons/category/community.blade.php","resources/views/components/icons/category/family.blade.php","resources/views/components/icons/category/food-drink.blade.php","resources/views/components/icons/category/market.blade.php","resources/views/components/icons/category/music.blade.php","resources/views/components/icons/category/other.blade.php","resources/views/components/icons/category/school.blade.php","resources/views/components/icons/category/sport.blade.php","resources/views/events/partials/dates.blade.php","resources/views/events/partials/share.blade.php","resources/views/events/show.blade.php","resources/views/filament/pages/system-health.blade.php","resources/views/layouts/app.blade.php","routes/console.php","routes/web.php","tests/Feature/Console/SyncOccurrencesCommandTest.php","tests/Feature/Filament/CreateEventTest.php","tests/Feature/Filament/CreateRecurringEventTest.php","tests/Feature/Filament/EditEventTest.php","tests/Feature/Filament/StatusActionsTest.php","tests/Feature/Ops/AdminFontAndLoggingTest.php","tests/Feature/Ops/CalendarSyncHeartbeatTest.php","tests/Feature/Ops/FailedJobsTest.php","tests/Feature/Ops/HealthReportTest.php","tests/Feature/Ops/OpsFixtures.php","tests/Feature/Ops/QueueHeartbeatTest.php","tests/Feature/Ops/SchedulerHeartbeatTracerTest.php","tests/Feature/Public/AttributeFiltersTest.php","tests/Feature/Public/BrandHeadTest.php","tests/Feature/Public/CalendarFiltersTracerTest.php","tests/Feature/Public/CalendarListTest.php","tests/Feature/Public/DateFilterTest.php","tests/Feature/Public/EventCalendarAndShareTest.php","tests/Feature/Public/EventJsonLdTest.php","tests/Feature/Public/EventPageContentTest.php","tests/Feature/Public/EventPageTracerTest.php","tests/Feature/Public/EventSeoTest.php","tests/Feature/Public/EventSlugRedirectTest.php","tests/Feature/Public/NoThirdPartyAssetsTest.php","tests/Feature/Public/OccurrenceCardTest.php","tests/Feature/Public/PublicLookTest.php","tests/Feature/Public/StatusVisibilityTest.php","tests/Feature/Publishing/ChangeEventStatusTest.php","tests/Feature/Publishing/ChangeOccurrenceStatusTest.php","tests/Feature/Publishing/PublishEventTest.php","tests/Feature/Publishing/UpdatePublishedEventTest.php","tests/Feature/Recurrence/RecurringOccurrencesTest.php","tests/Feature/Recurrence/RecurringSeriesTracerTest.php","tests/Feature/Status/CancelOccurrenceTracerTest.php","tests/Feature/Status/StatusSurvivesRegenerationTest.php","tests/Feature/WalkingSkeletonTest.php","tests/Fixtures/public/build/manifest.json","tests/Fixtures/views/brand-og-push.blade.php","tests/Pest.php","tests/TestCase.php","tests/Unit/Brand/ContrastTest.php","tests/Unit/Calendar/DateWindowTest.php","tests/Unit/Calendar/EventTimingTest.php","tests/Unit/Calendar/ManualRecurrenceTest.php","tests/Unit/Calendar/RecurrenceExpanderTest.php","tests/Unit/Enums/StatusTransitionsTest.php","vite.config.js"]
covered_digest: "v3:sha256:8cd60362a8ae4dcb65d966967b7d144634c877a2e3e8dfc3c7b6df1b2f1fd2db"
behavior_unverified: 1
overrides_applied: 0
gaps:
  - truth: "The slug, event page, 301s and .ics work in the local app and in CI: ... with the local app served (`php artisan serve`) ops/check-public-assets.sh http://localhost:8000 still prints PUBLIC ASSETS OK (/ has no script element and no third-party host) (01-08 must-have; same check is in the 01-07 <verification> block)"
    status: failed
    reason: "Run by the verifier against the owner's running `php artisan serve` on 127.0.0.1:8000 with the repo's default local .env: the check exits 1 with 'FAIL: / contains a script element'. The element is <script id=\"browser-logger-active\">, injected by Laravel Boost (require-dev) because BOOST_BROWSER_LOGS_WATCHER defaults to true in APP_ENV=local. The executors noticed this in 01-07 (deviation 3) and from then on ran the check on `php -S 127.0.0.1:8010` with BOOST_BROWSER_LOGS_WATCHER=false, not on the local app the plan names. The application's own views emit no script on / (tests prove this in the testing env), and production is unaffected because Boost is require-dev. But the local app as configured from .env.example does not meet the 'no script element on /' check, and the owner's end-of-phase checks (01-07 and 01-10 human-checks) run on exactly this server."
    artifacts:
      - path: ".env.example"
        issue: "Does not set BOOST_BROWSER_LOGS_WATCHER=false, so every local copy injects Boost's browser-logger script into public pages"
      - path: "ops/check-public-assets.sh"
        issue: "Correctly fails against the local app as configured. The passing runs in the SUMMARYs used a different server (port 8010, watcher disabled)"
    missing:
      - "Add BOOST_BROWSER_LOGS_WATCHER=false to .env.example (and the owner's .env), or turn off Boost's browser-logs injection in a published config/boost.php, so the local app on :8000 serves / with no script element"
      - "Re-run `bash ops/check-public-assets.sh http://localhost:8000` against `php artisan serve` and confirm it prints PUBLIC ASSETS OK"
behavior_unverified_items:
  - truth: "(01-09) Locally, with `php artisan schedule:work` and `php artisan queue:work database` running (or `composer dev`), ops:health prints HEALTHY within 6 minutes; the local drill shows the failed job in failed_jobs, in ops:health and in today's daily log, then sees it succeed"
    test: "Run `composer dev` (or serve + schedule:work + queue:work), wait up to 6 minutes, then run `php artisan ops:health`. Then run `php artisan ops:drill --fail-for=10`, let the worker run, `queue:retry`, let it run again, and run `php artisan ops:drill --status`"
    expected: "HEALTHY within 6 minutes. The drill shows failed jobs: 1 in ops:health and a line in storage/logs/laravel-YYYY-MM-DD.log, then failed=0 with a fresh last_success"
    why_human: "Needs a real cron-like scheduler and worker running over several minutes. Feature tests cover the parts (heartbeat writes, thresholds, drill job), but not the live processes together"
---

# Phase 1: Foundation & Published Calendar Verification Report

**Phase Goal:** Residents can open a mobile-first calendar on the local app and browse admin-entered events (including recurring ones), open event pages, add them to their calendar and share them
**Verified:** 2026-10-17T07:58:00Z
**Status:** gaps_found (one small gap, with an override option below; human checks are still needed after it is closed)
**Re-verification:** No. This is the first verification.

> **MVP-mode guard (warning, needs your decision).** ROADMAP marks Phase 1 `**Mode:** mvp`, but the goal is not written as a user story. `user-story.validate` returned `valid: false`: it lacks the "As a …, I want to …, so that …." form. Under the MVP rules the verifier should refuse. The orchestrator asked for verification explicitly, so this report uses the standard goal-backward method against the ROADMAP success criteria, plus a user-flow table built from the goal's clauses. To keep MVP mode, run `/gsd-mvp-phase 1` to rewrite the goal as a user story. Otherwise remove `**Mode:** mvp` from the Phase 1 section.

## Evidence run by the verifier (not taken from SUMMARYs)

| Check | Result |
|-------|--------|
| `./vendor/bin/pest` (full suite once, local PostgreSQL 18.6 in `ae-pg`) | passed: 401 tests, 2438 assertions, 1 warning (no failures) |
| `vendor/bin/pint --test` | passed |
| `vendor/bin/phpstan analyse` (level 6, no ignoreErrors) | passed, 0 errors |
| GitHub CI run 39014046075 on `main` | success, headSha `c77976f77c98…` = local HEAD. Steps: npm ci --ignore-scripts, build, asset gates, Pint, Larastan, Pest on the postgres:18 service, TZ=America/New_York |
| `verify.artifacts` for all 8 plans | 51/52. The one miss is a moved pattern: `aria-current` now lives in `calendar/partials/chip.blade.php`, which `filter-chips.blade.php` includes. The rendered page carries `aria-current="true"` (curl). Not a gap |
| `verify.key-links` for all 8 plans | 33/34. The one miss is a malformed `from` (`ci.yml (job test)`). Checked by hand: ci.yml runs `npm run build` before the asset gates. Not a gap |
| `ops/check-event-page.sh http://localhost:8000` (owner's running server) | exit 0, `EVENT PAGE OK: path=/events/first-demo-event jsonld=1 uid=1@whatsonattleborough.co.uk` |
| `ops/check-public-assets.sh http://localhost:8000` (owner's running server) | **exit 1, `FAIL: / contains a script element`** (Boost dev browser-logger, see Gaps) |
| `verify.commits` (SUMMARY hashes) | all real commit hashes valid. The 3 "invalid" entries are dates (20261001 and so on), not hashes |

## User Flow Coverage (from the goal's clauses)

| Step | Expected | Evidence in codebase | Status |
|------|----------|----------------------|--------|
| Open a mobile-first calendar on the local app | `/` lists upcoming occurrences under date headings, single column, readable | `CalendarController` + `PublicOccurrenceQuery`, `calendar/index.blade.php`. Live `curl /` returns 200 with headings and cards. CSS and both woff2 fonts are served 200 from `/build/assets/` | ✓ (looks need a human) |
| Browse admin-entered events incl. recurring | Admin creates one-off and weekly / nth-weekday series in Filament. Each occurrence appears once over 90 days | `CreateEvent::handleRecordCreation` → `PublishEvent` → `OccurrenceGenerator::sync` → `RecurrenceExpander` (sabre EventIterator). CreateRecurringEventTest, RecurringOccurrencesTest and RecurrenceExpanderTest pass | ✓ |
| Open event pages | `/events/{slug}` with full details, SEO and JSON-LD | `EventController`, `events/show.blade.php`, `EventSlugger`, `EventJsonLd`. Live card link → 200. `/events/no-such-event` → 404 | ✓ |
| Add to calendar | `.ics` with stable UID, Google Calendar link | `OccurrenceIcsController` / `OccurrenceIcs` / `OccurrenceUid`. Live `.ics` → 200 text/calendar, attachment, X-Robots-Tag noindex, `UID:1@whatsonattleborough.co.uk`. `GoogleCalendarLink` is rendered in `dates.blade.php` | ✓ (import on a phone needs a human) |
| Share | Web Share plus WhatsApp / Facebook / email / copy fallbacks | `share.blade.php` (plain anchors plus a link box, native and copy buttons `hidden`), `resources/js/share.js` loaded only on the event page | ✓ (share sheets need a human) |

## Goal Achievement

### Observable Truths: ROADMAP success criteria (the contract)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| SC1 | On a phone or emulator, a visitor sees upcoming published occurrences grouped under date headings. Today / This weekend / This week, category, Free and Family filters work as plain links without JS. Filter URLs are `noindex` | ✓ VERIFIED (server side) | `DateWindow` (half-open, London) and `CalendarFilters::fromRequest`. Chips are `<a>` built via `queryWith`. `index.blade.php` pushes `noindex, follow` when `hasQueryString`. Live: `/?when=weekend` has the robots meta and the active chip has `aria-current="true"` plus a tick SVG; `/` has no robots meta. Tests: DateFilterTest (28), AttributeFiltersTest, CalendarFiltersTracerTest (`assertDontSee('<script')`, `<form`). Real-phone look and a JS-off browser are human items |
| SC2 | Admin creates a weekly / nth-weekday manual event. It appears once per occurrence over 90 days. Multi-day events appear in every overlapping window. Expiry is end time, start + 3h, or end of day, with correct local times across 25 Oct 2026 | ✓ VERIFIED | `ManualRecurrence::toRrule`, `RecurrenceExpander` (sabre, window `[today, today+90d)`), `EventTiming` (no end → +3h; all-day → next local midnight; overnight roll). RecurringOccurrencesTest DST fixtures (Sat 23:30–02:30 over 25 Oct, 25h and 23h all-day Sundays, 01:30 on 28 Mar 2027 shifted to 02:30 BST), RecurrenceExpanderTest 89/90-day edges, DateFilterTest multi-day and clock-change cases. All passed in the run (named files re-run: 12/12, 28/28) |
| SC3 | Admin can edit, hide, unhide or cancel a whole event or one occurrence. Cancelled items show a text "Cancelled" badge. Admin-set occurrence statuses survive regeneration | ✓ VERIFIED | `ChangeEventStatus` / `ChangeOccurrenceStatus` (admin check, events row locked first, `assertCanTransitionTo`, audit columns). `StatusActions::forEvent/forOccurrence` wired in EditEvent and OccurrencesRelationManager. The upsert updates only `starts_at/ends_at/expires_at`, never `status`. StatusSurvivesRegenerationTest 7/7 (sync and start-time edit keep status and audit values), StatusActionsTest, StatusVisibilityTest |
| SC4 | Event page: readable slug, title/meta/canonical/OG, one JSON-LD `Event` per upcoming occurrence, full description and attribution. Add to calendar (`.ics` stable UID / Google) and share (Web Share plus fallbacks) | ✓ VERIFIED (server side) | EventSeoTest, EventSlugRedirectTest, EventJsonLdTest (≤10 scripts, EventCancelled, London offsets over 25 Oct, Host-header safe), EventCalendarAndShareTest 13/13, EventPageTracerTest 11/11. Live `check-event-page.sh` gives EVENT PAGE OK and a stable UID across two fetches. Native share and calendar import are human items |
| SC5 | Pushing to `main` runs Pest, Larastan and Pint in CI on PostgreSQL. Locally the admin sees the scheduler heartbeat and failed jobs. Logs rotate daily for 14 days | ✓ VERIFIED | ci.yml as above; run at c77976f green. `SystemHealth` Filament page (Scheduler / Queue / Calendar sync / Failed jobs, Retry/Delete via `queue:retry` / `queue:forget`, 403 for non-admin). `Heartbeat::beat` scheduled every minute. `config/logging.php`: stack→daily, `max_files` = LOG_DAILY_DAYS (14), 0640. `.env.example` and `.env` carry LOG_STACK=daily, LOG_DAILY_DAYS=14. Tests: SchedulerHeartbeatTracerTest, HealthReportTest, FailedJobsTest, AdminFontAndLoggingTest |

### Observable Truths: PLAN must-haves (134 truths across 8 plans)

| Plan | Truths | Verified | Not verified | Notes |
|------|--------|----------|--------------|-------|
| 01-01 skeleton | 15 | 15 | 0 | PublishEvent wraps save + `sync()` in `DB::transaction` (rollback test). Strict `expires_at > now`. UTC storage. TimePicker 10:00 stays 10:00:00. CI on postgres:18 with TZ=America/New_York. Boost is require-dev only |
| 01-04 recurrence | 19 | 18 | 1 insufficient_spec | Backstop: "edit and daily sync never interleave". Both take `lockForUpdate()` on the events row (UpdatePublishedEvent, SyncOccurrences), but no concurrency test exists |
| 01-05 status | 18 | 17 | 1 insufficient_spec | Backstop concurrency (lock order) is the same case as above. The orphan-rule backstop is VERIFIED by a passing named test ("prunes the cancelled Friday when the series moves to Saturdays…") |
| 01-06 filters/cards | 24 | 23 | 1 insufficient_spec | Backstop "ZWJ emoji may be split at the excerpt cut" is an accepted limitation; the owner should acknowledge it |
| 01-07 look | 19 | 19 | 0 | Verified in the testing env (NoThirdPartyAssetsTest, ContrastTest, PublicLookTest, BrandHeadTest) and live: CSS and fonts 200 same-origin, favicon / apple-touch / og images 200. The "/ contains no script element" truth holds for the app's own HTML. The served local app adds Boost's dev script, which is the root cause of the gap below |
| 01-08 event page | 16 | 15 | **1 FAILED** | Truth 16: `ops/check-public-assets.sh http://localhost:8000` on `php artisan serve` does not print PUBLIC ASSETS OK (see Gaps). Its other clauses hold: CI green, `/events/no-such-event` 404, card link 200 |
| 01-09 ops | 16 | 14 | 1 insufficient_spec, 1 ⚠️ PRESENT_BEHAVIOR_UNVERIFIED | Backstop: heartbeat is a single INSERT … ON CONFLICT (code: `upsert(..., ['name'], ...)` on pgsql), but overlapping runs are not tested. Live local "HEALTHY within 6 minutes + drill" needs running processes (behavior_unverified_items) |
| 01-10 JSON-LD/share | 7 | 6 | 1 insufficient_spec | Backstop share concurrency: share.js has a `sharing` re-entry guard and silences AbortError (code read), but only a real browser can show it |

**Score:** 132/139 truths verified. 1 failed. 1 present but behaviour unverified. 5 `insufficient_spec` backstop truths routed to a human.

### Required Artifacts

All 52 declared artifacts exist and are substantive (51 pattern matches plus the moved `aria-current` explained above). Key artifacts read in full by the verifier: `PublishEvent`, `UpdatePublishedEvent`, `ChangeOccurrenceStatus`, `OccurrenceGenerator`, `RecurrenceExpander`, `EventTiming`, `PublicOccurrenceQuery`, `CalendarController`, `SyncOccurrences`, `Heartbeat::beat`, `OccurrenceIcs` / `OccurrenceUid`, `events/show`, `dates`, `share` views, `share.js`, `ci.yml`, `config/logging.php`. None are stubs. No `return []` / placeholder paths reach user output.

### Key Link Verification

| From | To | Via | Status |
|------|----|-----|--------|
| CreateEvent | PublishEvent | `handleRecordCreation` → `app(PublishEvent::class)(…, EventOrigin::Manual)` | WIRED |
| EditEvent | UpdatePublishedEvent / StatusActions::forEvent | `handleRecordUpdate`, `getHeaderActions` | WIRED |
| PublishEvent / UpdatePublishedEvent / SyncOccurrences | OccurrenceGenerator::sync | same transaction, events row locked | WIRED |
| OccurrenceGenerator | RecurrenceExpander::instanceDates | recurring branch | WIRED |
| routes/console.php | calendar:sync-occurrences, heartbeat:scheduler, QueueHeartbeat, queue:prune-failed | `dailyAt('03:10')->timezone('Europe/London')->withoutOverlapping()` and so on | WIRED |
| CalendarController | CalendarFilters / PublicOccurrenceQuery / DateWindow | `fromRequest`, `paginate`, `DateWindow::for` | WIRED |
| occurrence-card | EventController | `eventPath` anchor (live `/events/first-demo-event`) | WIRED |
| events/show | EventJsonLd, share.js | `$page->jsonLd`, `@vite('resources/js/share.js')` (event page only) | WIRED |
| dates partial | GoogleCalendarLink / .ics route | `googleUrl`, `icsPath` | WIRED |
| ci.yml | vite.config.js | `npm run build` then asset gates | WIRED (checked by hand) |

### Data-Flow Trace (Level 4)

| Artifact | Data | Source | Real data | Status |
|----------|------|--------|-----------|--------|
| calendar/index | `$groups` | `PublicOccurrenceQuery::paginate` (join events, status + expiry filters) | yes, live demo event rendered | ✓ FLOWING |
| events/show | `EventPageData` | `EventController` → `upcomingForEvent` | yes | ✓ FLOWING |
| `.ics` | VCALENDAR | `findListedOccurrence` → `OccurrenceIcs` | yes, live 200 | ✓ FLOWING |
| System health | heartbeats / failed_jobs | `HealthReport` over tables | yes (tests) | ✓ FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Status survives regeneration | `pest tests/Feature/Status/StatusSurvivesRegenerationTest.php` | 7/7 | ✓ PASS |
| DST / horizon recurrence | `pest tests/Feature/Recurrence/RecurringOccurrencesTest.php` | 12/12 | ✓ PASS |
| Date windows and multi-day | `pest tests/Feature/Public/DateFilterTest.php` | 28/28 | ✓ PASS |
| ICS / Google / share | `pest tests/Feature/Public/EventCalendarAndShareTest.php` | 13/13 | ✓ PASS |
| Filter URL noindex (live) | `curl '/?when=weekend'` | `<meta name="robots" content="noindex, follow">` | ✓ PASS |
| .ics headers and UID (live) | `curl -D - …/2026-10-17.ics` | 200, text/calendar, attachment, X-Robots-Tag noindex, stable UID | ✓ PASS |
| Admin gate (live) | `curl /admin` | 302 → /admin/login | ✓ PASS |

### Probe Execution

No `scripts/*/tests/probe-*.sh` exist and no plan declares one. The phase's live checks are `ops/` scripts. Both were run read-only against the server already running on :8000.

| Probe | Command | Result | Status |
|-------|---------|--------|--------|
| `ops/check-event-page.sh` | `bash ops/check-event-page.sh http://localhost:8000` | exit 0, EVENT PAGE OK | PASS |
| `ops/check-public-assets.sh` | `bash ops/check-public-assets.sh http://localhost:8000` | exit 1, `FAIL: / contains a script element` (`<script id="browser-logger-active">`) | FAILED |

### Requirements Coverage

All 20 Phase 1 IDs are claimed by at least one plan. REQUIREMENTS.md maps no other IDs to Phase 1, so nothing is orphaned.

| Requirement | Source Plan(s) | Status | Evidence |
|-------------|----------------|--------|----------|
| OPS-01 | 01-01 | ✓ SATISFIED | composer: php ^8.4, laravel/framework ^13.17, filament ^5.10. PostgreSQL 18.6 locally, postgres:18 in CI. Pest, Larastan and Pint in green CI |
| OPS-04 | 01-09 | ✓ SATISFIED (local) | System health page, heartbeats, failed jobs, daily 14-day logs. The live process run is a human item |
| CAL-01 | 01-01, 01-06, 01-07 | ✓ SATISFIED | grouped list, London headings, mobile layout |
| CAL-02 | 01-06 | ✓ SATISFIED | DateWindow and DateFilterTest |
| CAL-03 / CAL-04 / CAL-05 | 01-06 | ✓ SATISFIED | AttributeFiltersTest |
| CAL-06 | 01-06, 01-07 | ✓ SATISFIED (app). See the Boost gap for the served local app | anchors only, noindex on query strings |
| CAL-07 | 01-05, 01-06, 01-07 | ✓ SATISFIED | OccurrenceCardTest, text "Cancelled" badge |
| CAL-08 | 01-04 | ✓ SATISFIED | 13 weekly rows, 90-day horizon |
| EVT-01 / EVT-02 | 01-08 | ✓ SATISFIED | EventPageContentTest, EventSeoTest, EventSlugRedirectTest |
| EVT-03 | 01-10 | ✓ SATISFIED | EventJsonLdTest |
| EVT-06 | 01-08, 01-10 | ✓ SATISFIED | .ics and Google link |
| EVT-07 | 01-10 | ✓ SATISFIED (markup). Native share is a human item | share partial and share.js |
| REC-01 / REC-02 | 01-01, 01-04 | ✓ SATISFIED | generator upsert, daily schedule, EventTiming |
| ADM-01 / ADM-02 / ADM-03 | 01-01, 01-04, 01-05 | ✓ SATISFIED | Filament panel gated by ADMIN_EMAIL, PublishEvent origin manual, status actions |

Production items (OPS-01b, OPS-02, OPS-04b) are Phase 5 by the 2026-10-16 scope change and are not assessed here.

### Prohibitions (ADR-550)

16 of 20 prohibitions are enforced by passing tests: CAL-06 no-JS (CalendarFiltersTracerTest, PublicLookTest, EventCalendarAndShareTest "still none on /"), CAL-07 cancelled stays listed (DateFilterTest, StatusVisibilityTest), ADM-03 no hard delete / status only via actions / edit only via UpdatePublishedEvent (StatusSurvivesRegenerationTest, EditEventTest, UpdatePublishedEventTest "never changes status…", StatusActionsTest), CAL-01 / EVT-07 no third-party (NoThirdPartyAssetsTest), voice strings asserted verbatim, EVT-02 no EVT-05 behaviour (EventPageContentTest), OPS-04 no payloads (FailedJobsTest).

**Flagged (4). These are unverified prohibitions; a human review is recommended. None passed silently:**

| Prohibition | Tier | Disposition | Verifier's manual check (not authoritative) |
|-------------|------|-------------|------------------------------------------|
| 01-01 ADM-02: no public event bypasses `App\Publishing\Actions`; no seeded demo events | judgment | needs human resolution | Only `PublishEvent` creates events. The `new Event` in EventForm is an unsaved "Next dates" preview. DatabaseSeeder calls only CategorySeeder. Looks satisfied |
| 01-01 OPS-01: no LLM/AI runtime package in `require`; Boost require-dev only | test | unverified: no test enforces it | `require` = php, filament, laravel/framework, tinker, sabre/vobject, spatie/schema-org. Holds today |
| 01-04 REC-01: sabre/vobject only; no second RRULE library or hand-rolled loops | test | unverified: no test enforces it | no other RRULE package; no `addWeek/addMonth/nthOfMonth` loops in app/. Holds today |
| 01-09 OPS-04: liveness not in the cache store; no HTTP route or Filament action writes a heartbeat | test | unverified: no test enforces it | `Heartbeat::beat` is called only from routes/console.php, QueueHeartbeat, OpsDrillJob and SyncOccurrences. It upserts the heartbeats table. Holds today |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (app, resources, routes, config, database, ops, tests, ci.yml) | none | TBD / FIXME / XXX | none | no debt markers |
| same | none | TODO / HACK / placeholder | none | none |
| `.env.example` | n/a | dev tool (Boost) injects a script into public pages by default | ⚠️ Warning (root cause of the gap) | local `/` fails the no-script check; production unaffected |
| `composer.json` require-dev | n/a | `deployer/deployer` already present | ℹ️ Info | Phase 5 tooling arrived early; dev-only, harmless |

### Human Verification Required

These remain after the gap is closed. The status becomes `human_needed` once the gap is fixed or overridden.

1. **Phone / mobile look (01-07 human-check).** `npm run build`, `php artisan serve --host 0.0.0.0 --port 8000`, open on a phone or in DevTools emulation. Check light and dark themes, tap This weekend / a category / Free, add to the home screen, Tab through, and use a 320px width with a long unbroken title. *Expected:* wordmark, Atkinson font, Norfolk green, AA-readable text, thumb-sized chips with a tick on the active one, category icons, "Cancelled" readable, green calendar-pin home icon, "Skip to main content" as the first Tab stop, focus rings, no sideways scroll. *Why human:* colour, touch and reflow on a real screen.
2. **Browse with JavaScript disabled.** Turn JS off and use every chip and "More events". *Expected:* the same filtered lists, no dead controls. *Why human:* real browser behaviour (server tests prove the markup only).
3. **Filament recurring series walkthrough (01-04).** In /admin create a monthly last-Friday series, check the "Next dates" preview, save, then edit only the time. *Expected:* preview in UK format, occurrences listed on /, the time change keeps dates and statuses. *Why human:* Filament UI flow.
4. **Filament status walkthrough (01-05).** Cancel and hide single dates, then hide / show / cancel / reinstate the whole event, watching /. *Expected:* confirmations, the right badges, hidden items gone, earlier per-date statuses kept. *Why human:* UI flow.
5. **System health and ops drill (01-09 human-check).** Run `composer dev`, open /admin/system-health, run `ops:drill --fail-for=60` twice, then Retry one and Delete one. *Expected:* OK states with UK times, 2 failed rows and a red badge, then they clear and `ops:drill --status` shows failed=0. Also: ops:health HEALTHY within 6 minutes. *Why human:* live scheduler and worker.
6. **Share and add-to-calendar (01-10 human-check).** On the laptop at localhost: Share… (cancel, then share), Copy link, open the .ics in the calendar app, open the Google Calendar link. On a phone at the LAN IP: the hidden buttons stay hidden, the fallbacks open WhatsApp / Facebook / email, the .ics imports. In Firefox / no-JS: the fallbacks show, with no dead button. *Expected:* see 01-10 PLAN. Times are correct after 25 Oct. *Why human:* native share sheets, calendar apps, Google's undocumented template URL.
7. **Backstop truths to acknowledge (insufficient_spec).** (a) edit and sync row-lock serialisation (01-04, 01-05); (b) heartbeat upsert under overlapping runs (01-09); (c) share.js re-entry and AbortError handling (01-10, covered in item 6); (d) the accepted ZWJ-emoji split at the 140-character excerpt cut (01-06). *Why human:* concurrency or accepted limits with no deterministic test.
8. **Flagged prohibitions (4).** Review the table above. For the three test-tier items, either accept the manual check or add an enforcement test (for example a Pest arch or composer.json assertion).

### Gaps Summary

There is one gap with one root cause: **Laravel Boost's dev browser-logger script is injected into public pages of the local app**, so `ops/check-public-assets.sh http://localhost:8000` fails with "/ contains a script element". The phase goal is otherwise met in the codebase. The tests, Larastan, Pint and green CI on c77976f back every ROADMAP success criterion, and the event-page live check passes. The executors found this problem in 01-07 and worked around it by running the check on a separate `php -S :8010` with the watcher disabled. They never changed the local config the plan names, so the passing runs in the SUMMARYs were not against the local app.

The fix is one line (`BOOST_BROWSER_LOGS_WATCHER=false` in `.env.example` and `.env`) plus a re-run of the check. **This looks intentional/environmental.** If you accept the deviation instead, add to this file's frontmatter:

```yaml
overrides:
  - must_have: "with the local app served (php artisan serve) ops/check-public-assets.sh http://localhost:8000 still prints PUBLIC ASSETS OK"
    reason: "The only script on / is Laravel Boost's require-dev browser logger (APP_ENV=local). App views emit none (tests), and production never installs Boost. Check passes with BOOST_BROWSER_LOGS_WATCHER=false"
    accepted_by: "{name}"
    accepted_at: "{ISO timestamp}"
```

No gap matches a later phase's goal or success criteria. Phase 5 covers only the production run of this check, so nothing is deferred.

---

_Verified: 2026-10-17T07:58:00Z_
_Verifier: the agent (gsd-verifier)_
