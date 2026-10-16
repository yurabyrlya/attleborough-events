---
phase: 01-foundation-published-calendar
plan: 09
subsystem: ops-observability
status: complete
tags: [ops, heartbeat, scheduler, queue, failed-jobs, filament-5, logging, ops-04]

requires:
  - phase: 01-foundation-published-calendar
    provides: "01-01 panel gate (User::canAccessPanel, ADMIN_EMAIL), jobs/failed_jobs skeleton tables (database-uuids failer), discoverPages in AdminPanelProvider; 01-04 calendar:sync-occurrences (output line, exit codes, 03:10 Europe/London schedule) and its tests"
provides:
  - "Migration 2026_10_16_000160_create_heartbeats_table: heartbeats(name varchar(64) pk, last_seen_at timestamptz, meta jsonb null)"
  - "App\\Models\\Heartbeat: SCHEDULER / QUEUE / CALENDAR_SYNC / DRILL constants; static beat(name, meta) = one INSERT ... ON CONFLICT (name) DO UPDATE"
  - "App\\Models\\FailedJob (read-only): jobName(), exceptionSummary(); payload and exception hidden from serialisation"
  - "App\\Enums\\HealthState (Ok, Stale, NeverSeen, Failed, NotRunYet) with label()/color()/isHealthy()"
  - "App\\Ops\\HealthCheck value object (lastSeenLondon(), relativeAge()) and App\\Ops\\HealthReport (scheduler(), queue(), calendarSync(), failedJobCount(), pendingJobs(), oldestPendingAgeSeconds(), all())"
  - "App\\Jobs\\QueueHeartbeat (ShouldBeUnique, uniqueFor 3600, tries 1, database/default) and App\\Jobs\\OpsDrillJob (tries 1, fails until an ISO timestamp)"
  - "ops:health (exit 0 HEALTHY / 1 UNHEALTHY) and ops:drill {--fail-for=60} {--status}"
  - "calendar:sync-occurrences records the calendar-sync heartbeat (synced, failed, failed_ids ≤20, exit_code)"
  - "Schedule: heartbeat:scheduler every minute, App\\Jobs\\QueueHeartbeat every five minutes, queue:prune-failed --hours=720 daily, plus the 01-04 03:10 sync"
  - "Filament page App\\Filament\\Pages\\SystemHealth at /admin/system-health (group System, admin-only, danger nav badge) with status sections and the failed-jobs table (Retry/Delete)"
  - "config/ops.php thresholds; config/logging.php stack→daily, daily 14 files, 0640; .env.example LOG_STACK=daily, LOG_DAILY_DAYS=14"
  - "Admin panel font: LocalFontProvider with 'system-ui' (no web font, no third-party host)"
affects: [05-02 (ops/check.sh calls ops:health; production drill uses ops:drill --status; shared/.env LOG_* keys), 01-07 (closes the 'Filament admin fonts' note)]

tech-stack:
  added: []
  patterns:
    - "Liveness evidence in a table, never the cache: Heartbeat::beat is a single upsert with an explicit UTC string (upsert bypasses casts)"
    - "One HealthReport feeds the page, the nav badge and the CLI; freshness = max(0, now - last_seen) against a config threshold"
    - "Filament table actions re-read the uuid from the table before calling artisan (queue:retry / queue:forget); a vanished row resolves to an unsaved placeholder so the action can notify instead of silently doing nothing"
    - "Schedule::job(Class::class, ...) (class name, not instance) so constructor state is taken at dispatch time"

key-files:
  created:
    - database/migrations/2026_10_16_000160_create_heartbeats_table.php
    - app/Models/Heartbeat.php
    - app/Models/FailedJob.php
    - app/Enums/HealthState.php
    - app/Ops/HealthCheck.php
    - app/Ops/HealthReport.php
    - app/Jobs/QueueHeartbeat.php
    - app/Jobs/OpsDrillJob.php
    - app/Console/Commands/OpsHealth.php
    - app/Console/Commands/OpsDrill.php
    - app/Filament/Pages/SystemHealth.php
    - resources/views/filament/pages/system-health.blade.php
    - config/ops.php
    - tests/Feature/Ops/OpsFixtures.php
    - tests/Feature/Ops/SchedulerHeartbeatTracerTest.php
    - tests/Feature/Ops/HealthReportTest.php
    - tests/Feature/Ops/QueueHeartbeatTest.php
    - tests/Feature/Ops/FailedJobsTest.php
    - tests/Feature/Ops/CalendarSyncHeartbeatTest.php
    - tests/Feature/Ops/AdminFontAndLoggingTest.php
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-09-task2-red{.json,-junit.xml}
  modified:
    - app/Console/Commands/SyncOccurrences.php
    - app/Providers/Filament/AdminPanelProvider.php
    - config/logging.php
    - routes/console.php
    - .env.example

key-decisions:
  - "QueueHeartbeat is scheduled by class name (Schedule::job(QueueHeartbeat::class, 'default', 'database')), not `new QueueHeartbeat`, so queued_at/latency are measured from each dispatch rather than from when routes/console.php was loaded"
  - "QueueHeartbeat and ops:drill pin connection database / queue default themselves, so they never run inline on a sync default connection"
  - "A failed_jobs row that is already gone resolves (SystemHealth::resolveTableRecord) to an unsaved FailedJob placeholder; Filament 5 otherwise drops the click silently, which would have made the required 'already retried or deleted' notification impossible"
  - "Delete treats a non-zero queue:forget exit as 'already handled' too, so a race between the existence check and the forget still notifies"
  - "The daily channel keeps the skeleton's max_files key (Laravel 13 LogManager reads max_files ?? days); the plan's 'days' key name was not adopted"
  - "Calendar-sync freshness is checked before the failed count (a stale run reads Stale even if it had failures), as the plan orders it"

patterns-established:
  - "Ops tests that render a Filament page over HTTP call Livewire::flushState() in afterEach: Livewire keeps 'a component rendered this request' in a static that otherwise leaks into later public-page tests (Livewire script injected into /)"
  - "Shared Ops fixtures live in a namespaced Tests\\Feature\\Ops\\OpsFixtures class instead of tests/Pest.php (owned by 01-01)"

requirements-completed: [OPS-04]

coverage:
  - id: D1
    description: "Tracer: heartbeat:scheduler (every minute) upserts one 'scheduler' row → HealthReport (180 s OK / 181 s Stale / Never seen) → admin-only /admin/system-health and ops:health; 403 for non-admin, login redirect for guests"
    requirement: OPS-04
    verification:
      - kind: integration
        ref: "tests/Feature/Ops/SchedulerHeartbeatTracerTest.php"
        status: pass
      - kind: manual_procedural
        ref: "Local: php artisan schedule:work → ops:health 'scheduler: OK (last seen 6 seconds ago)' / HEALTHY"
        status: pass
  - id: D2
    description: "Queue heartbeat: scheduled dispatch onto database/default, only the worker writes 'queue' (latency ≥ 0), uniqueness keeps one waiting job, 600/601 s thresholds, jobs waiting + oldest age; schedule entries for QueueHeartbeat and queue:prune-failed --hours=720"
    requirement: OPS-04
    verification:
      - kind: integration
        ref: "tests/Feature/Ops/QueueHeartbeatTest.php, tests/Feature/Ops/HealthReportTest.php"
        status: pass
  - id: D3
    description: "Sync result: calendar-sync meta (synced/failed/failed_ids/exit_code) on every run; OK / Failed / Stale (26 h + 1 s) / Not run yet; 01-04 output and exit codes unchanged"
    requirement: OPS-04
    verification:
      - kind: integration
        ref: "tests/Feature/Ops/CalendarSyncHeartbeatTest.php, tests/Feature/Console/SyncOccurrencesCommandTest.php"
        status: pass
  - id: D4
    description: "Failed jobs: drill end to end, Retry/Delete with confirmation and warning audit log (uuid + admin id only), stale-row notification with no table change, newest-first ordering with id tiebreak, 'No failed jobs', payload never rendered, 200-char first exception line, nav badge, ops:drill --status and --fail-for bounds"
    requirement: OPS-04
    verification:
      - kind: integration
        ref: "tests/Feature/Ops/FailedJobsTest.php, tests/Feature/Ops/HealthReportTest.php"
        status: pass
  - id: D5
    description: "Timezone and clock: 2026-10-25T00:30Z and 01:30Z both display 'Sun 25 Oct, 1:30am'; a future last_seen_at is age 0 and OK"
    requirement: OPS-04
    verification:
      - kind: integration
        ref: "tests/Feature/Ops/HealthReportTest.php"
        status: pass
  - id: D6
    description: "Logs: stack defaults to daily, daily keeps 14 files at 0640, single at 0640; .env.example LOG_* keys; admin login and System health load nothing from a third-party host"
    requirement: OPS-04
    verification:
      - kind: integration
        ref: "tests/Feature/Ops/AdminFontAndLoggingTest.php"
        status: pass
      - kind: manual_procedural
        ref: "Local drill: ops:drill --fail-for=10 → worker → failed=1 / ops:health exit 1 'failed jobs: 1' / storage/logs/laravel-2026-10-16.log contains the failure → queue:retry → worker → failed=0 last_success=2026-10-16T22:17:54+00:00"
        status: pass
  - id: D7
    description: "Local HEALTHY with the real processes: calendar:sync-occurrences, then schedule:work + queue:work database → ops:health HEALTHY at 22:20:09Z (queue heartbeat dispatched at the 22:20 boundary)"
    requirement: OPS-04
    verification:
      - kind: manual_procedural
        ref: "Task 3 second automated verify (local part)"
        status: pass
  - id: D8
    description: "Owner check: open /admin/system-health with composer dev running, two drill failures and the red badge, Retry one and Delete the other from the UI"
    requirement: OPS-04
    verification:
      - kind: manual_procedural
        ref: "Plan Task 3 <human-check> (human_verify_mode: end-of-phase)"
        status: unknown

actuals:
  tokens: 121567   # chars/4 over the 27 files this plan changed (486,269 chars). The two TDD evidence files are 422,318 chars (the JUnit report embeds the rendered failure HTML); app/config/migration/view/tests are 63,951 chars (about 15,988 tokens).
  tasks: 3
  commits: 4       # MEASURED: git rev-list --count plan_head_before..plan_head_after
plan_head_before: f51d5af87775bbfcdf93ae1d68f4fa93289f56f6
plan_head_after: 23bd5ddb7bf74dbf3a2d16ec05c854c654b2b98b

duration: 17min
completed: 2026-10-16
---

# Phase 1 Plan 09: Ops Visibility (Heartbeats, Failed Jobs, Daily Logs) Summary

**The admin now has a "System health" page in Filament. It shows the scheduler, the queue worker and the daily calendar sync as text states (OK, Stale, Never seen, Failed, Not run yet), each with a UK last-seen time. It also lists failed jobs without their payloads, with confirmed Retry and Delete. The scheduler and the worker prove they are alive by upserting rows in a new `heartbeats` table, never the cache. `ops:health` prints the same states for scripts and exits 1 when something is wrong. `ops:drill` makes a job fail on purpose so you can test it. Logs now rotate daily and are kept 14 days at 0640, and the admin panel uses the system font.**

## Performance

- **Duration:** about 17 min
- **Started:** 2026-10-16T22:04:29Z
- **Completed:** 2026-10-16T22:21Z
- **Tasks:** 3 of 3
- **Files changed:** 27 (13 app/config/migration/view files created, 5 modified, 7 test files, 2 TDD evidence files)
- **PLAN_BASE:** `f51d5af87775bbfcdf93ae1d68f4fa93289f56f6`

## Accomplishments

- **Tracer (Task 1), scheduler heartbeat wired through every layer:**
  - the heartbeats migration and `Heartbeat::beat()` upsert
  - `heartbeat:scheduler` running every minute
  - `HealthState`, `HealthCheck` and `HealthReport`
  - the admin-only System health page
  - `ops:health`

  To prove it locally I emptied the heartbeats table and ran `php artisan schedule:work`. ops:health then printed `scheduler: OK (last seen 6 seconds ago)`.
- **Queue, sync and failed jobs (Task 2, TDD):**
  - `QueueHeartbeat` is unique for an hour and is written only by the worker.
  - The calendar sync now records its result in a heartbeat. Its output and exit codes are unchanged.
  - The failed-jobs table lists newest first and shows: failed time (London), job basename, queue, and the first exception line cut to 200 characters.
  - Retry calls `queue:retry` and Delete calls `queue:forget`. Both ask for confirmation, write a warning log line and send a notification. Acting on a row that is already gone shows "already retried or deleted".
  - The navigation item shows a danger badge with the failed count.
  - `OpsDrillJob` and `ops:drill`, plus a daily `queue:prune-failed --hours=720`.
- **Logs and font, proven locally (Task 3):**
  - The daily log channel is the default, keeps 14 files and writes them at 0640.
  - `.env.example` and the local `.env` now say `LOG_STACK=daily` and `LOG_DAILY_DAYS=14`.
  - The admin panel uses `LocalFontProvider` with 'system-ui'.
  - The local drill and the local HEALTHY run (real `schedule:work` + `queue:work database`) both passed.

## Task Commits

1. **Task 1 (tracer): scheduler heartbeat end to end:** `13ddbf7` (feat)
2. **Task 2 (TDD): queue heartbeat, sync result, failed jobs, drill**
   - RED: `6971bc6` (test)
   - GREEN: `b41b760` (feat)
3. **Task 3: daily logs, admin font, local proof:** `23bd5dd` (feat)

## TDD Gate Compliance

**Task 1 tracer gate:** `human_verify_mode` is end-of-phase and the local part of `<verify>` is automated, so I re-ran it before expanding.
- The 8 tracer tests passed.
- The migration ran on the local dev DB.
- schedule:work turned the scheduler OK within one minute.
- I did not run the CI part (`gh run watch`), because this executor must not push. See Deviations.

**Task 2 RED** (`6971bc6`)
- 29 tests: 26 failed, 3 passed, 0 errors.
- Classifier verdict: `RED_EVIDENCE_OK` (`target_test_failed`). Record at `tdd-evidence/01-09-task2-red.json`, with the JUnit report beside it.
- **Target:** `FailedJobsTest#it runs the drill end to end…`. It failed on "Output does not contain 'Drill job queued; it fails until'" because the `OpsDrill` scaffold is a no-op.
- **Semantic assessment:** every failure is an assertion failure for missing behaviour. These cover the HealthReport stubs, the ops:health lines and rules, the missing schedule entries, uniqueness, tries, never-visible actions, the table and empty state, and the sync heartbeat.
- **What passed:** the 3 passing tests are Task 1 behaviour that this task pins: the London display either side of 25 Oct 2026 and future timestamp = age 0.
- **Earlier RED runs:** before the recorded run, two errors were turned into assertion failures by adding a structural scaffold (the `resolveTableRecord` placeholder):
  1. a stale-row `ActionNotResolvableException`
  2. a `parent::` call on a trait method, fixed with a trait alias

**Task 2 GREEN** (`b41b760`): 43 of 43 pass in tests/Feature/Ops plus SyncOccurrencesCommandTest. No "RED scaffold" markers remain.

**REFACTOR:** not needed.

## Verification

- **Tests:** `./vendor/bin/pest` gives 305 passed (1455 assertions), up from a baseline of 265. It also gives 305/305 under `TZ=America/New_York`.
- **Static checks:** `vendor/bin/pint --test` passed and `vendor/bin/phpstan analyse` found 0 errors.
- **Local proof:**
  - **Drill:** I ran the plan's first Task 3 automated verify exactly as written. The output ended with `DRILL-OK` and `failed=0 uuids= last_success=2026-10-16T22:17:54+00:00`. Along the way ops:health printed `failed jobs: 1` and UNHEALTHY with exit 1, and `storage/logs/laravel-2026-10-16.log` (mode `-rw-r-----`) contained the failure.
  - **HEALTHY:** the local part of the second Task 3 verify printed HEALTHY at 22:20:09Z. All three checks were OK, with 0 failed and 0 waiting.
  - **Processes:** I stopped both background processes through the script's EXIT trap and confirmed none of mine was left running.
- **Acceptance greps:**
  - `upsert` in Heartbeat: 1. `Cache::` in Heartbeat and HealthReport: 0.
  - `heartbeat:scheduler` 1 and `dailyAt('03:10')` 1 in routes/console.php.
  - `ShouldBeUnique` 2 and `uniqueFor` 1 in QueueHeartbeat.
  - In SystemHealth: `queue:retry` 1, `queue:forget` 1, `requiresConfirmation` 2.
  - In SyncOccurrences: `Synced ` ≥1 and `CALENDAR_SYNC` 1.
  - `queue:prune-failed --hours=720` 1.
  - In config/logging.php: `LOG_DAILY_DAYS` 2 and `0640` 2.
  - The three `.env.example` LOG_* lines: 3.
  - `LocalFontProvider` 1 in AdminPanelProvider.
  - Exactly one dated laravel-*.log exists.
  - `schedule:list` shows heartbeat:scheduler, App\Jobs\QueueHeartbeat (`*/5`), queue:prune-failed (`0 0 * * *`) and calendar:sync-occurrences.
  - `route:list --path=admin/system-health` lists the page.
- **Ownership check:** for `(01-09)` commits since PLAN_BASE, the grep for ci.yml or ops/ printed 0.

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Test isolation: Livewire asset injection leaked into public-page tests**
- **Found during:** Task 2, full suite.
- **Issue:** `CalendarFiltersTracerTest` ("renders no form and no script on /") failed only when it ran after an Ops test that fetched `/admin/system-health` over HTTP. Livewire's `SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest` is a static that survives between tests, so the next public response had livewire.js injected. In production each request is a fresh PHP request, so this only affects tests.
- **Fix:** each Ops test file that renders a Filament page over HTTP runs `afterEach(fn () => Livewire::flushState())`. `tests/Pest.php` is owned by 01-01 and was not touched.
- **Files modified:** the five Ops test files.
- **Commits:** `b41b760`, `23bd5dd`

**2. [Rule 2 - Missing functionality] The stale-row notification was unreachable in Filament 5**
- **Found during:** Task 2 RED.
- **Issue:** When a record no longer exists, Filament's `mountAction` and `callMountedAction` catch `ActionNotResolvableException` and drop the action silently. The required "This job was already retried or deleted. Refresh the page." notification could therefore never be shown.
- **Fix:** `SystemHealth::resolveTableRecord()` (via a trait alias) returns an unsaved `FailedJob` placeholder for a missing numeric key. The action then re-reads the uuid with `whereKey` and notifies when it is gone. Delete also notifies when `queue:forget` exits non-zero.
- **Commit:** `b41b760`

### Other adjustments

- **No push and no CI watch:** the plan's Task 1 and Task 3 verify blocks push to main and run `gh run watch`. The dispatch says the orchestrator pushes, so I ran only the local parts. CI on the final commit is for the orchestrator to confirm after it pushes. The last pre-plan commit `f51d5af` is green.
- **QueueHeartbeat scheduling:**
  - **Plan:** `Schedule::job(new QueueHeartbeat, 'default', 'database')`.
  - **What I did:** I scheduled it by class name, as the decision above explains. The schedule description is `App\Jobs\QueueHeartbeat`.
- **Logging key:** the plan named the daily retention key `days`. The real skeleton uses `max_files`, and Laravel 13 reads `max_files ?? days`, so I kept `max_files` and cast it to int. The test asserts `max_files` = 14.
- **Admin font finding:**
  - **Before:** Filament 5.10's default already serves Inter from local files. The login page showed no deny-list host and no third-party resource before the change; the new test passed on both page checks then.
  - **Change:** I made the switch to `LocalFontProvider` with 'system-ui' anyway, as the plan asks. It also drops Filament's Inter preload.
  - **Result:** the panel CSS stack `var(--font-family), ui-sans-serif, system-ui, sans-serif` provides the fallback, which I confirmed in `public/css/filament/filament/app.css`.
- **Extra test file:** `tests/Feature/Ops/OpsFixtures.php` holds the shared fixtures (admin, `beatAgo`, `failedJob`, `workerPass`, scheduled-event lookup) as a namespaced class, so `tests/Pest.php` stays untouched.
- **Table column name:** the exception column is named `reason` (label "Reason") and gets its state from `exceptionSummary()`. A column named after the raw `exception` attribute can never fall back to rendering the full text.
- **Tracer test edits in Task 2:** once ops:health also required the queue to be OK, the Task 1 tracer's HEALTHY case seeds a fresh queue beat. Its threshold dataset asserts inside the Scheduler section (`assertSeeInOrder`).
- **Branch and staging:**
  - Commits went to `main`, as the dispatch instructed.
  - I did not stage `.planning/config.json`, `state.json`, `milestone.lock`, STATE.md, ROADMAP.md, REQUIREMENTS.md, or anything under phases 02/05.
  - The local `.env` (gitignored) now has `LOG_STACK=daily` and `LOG_DAILY_DAYS=14`.
- **`migrate --env=testing`:** I ran this once. With no `.env.testing` it applied the heartbeats migration to the local dev DB, which the plan wanted anyway.

## Issues Encountered

- A `php artisan serve` on 127.0.0.1:8000 (PID 60803, from an interactive `zsh -il` on ttys002, started 23:18 BST) is running. It is the owner's, not mine: my one attempt to start a server on port 8765 was refused by the tool before it ran. I left it alone.

## Known Stubs

None. The word "placeholder" in `SystemHealth.php` refers to the deliberate unsaved record for vanished rows, not a stub.

## Threat Flags

None. The new surface is the admin-only page, its two confirmed record actions and two CLI commands. These are covered by T-01-46 to T-01-55, each mitigation has a test, and no HTTP route writes a heartbeat.

## User Setup Required

None. For the end-of-phase owner check (Task 3 `<human-check>`):
1. Run `composer dev`.
2. Open http://localhost:8000/admin/system-health.
3. Run `php artisan ops:drill --fail-for=60` twice.
4. Retry one row and Delete the other.

## Next Phase Readiness

- **05-02:**
  - `ops/check.sh` can gate on `php artisan ops:health` (exit code and the final HEALTHY/UNHEALTHY line).
  - The production drill can use `ops:drill --fail-for=N` and `ops:drill --status` (`failed=… uuids=… last_success=…`).
  - `shared/.env` needs `LOG_CHANNEL=stack`, `LOG_STACK=daily`, `LOG_DAILY_DAYS=14` and `LOG_LEVEL=warning`.
- **01-07:** the "Filament admin fonts" open note is closed.

## Self-Check: PASSED

- FOUND: all 13 created app/config/migration/view files, all 7 test files (incl. OpsFixtures.php) and both TDD evidence files
- FOUND commits: 13ddbf7, 6971bc6, b41b760, 23bd5dd (all ancestors of HEAD)
