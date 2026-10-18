---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 06
subsystem: ingestion
tags: [ingestion, scheduler, queue, dst, retention, janitor, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-01 source_runs_one_active index and models; 03-03 RawBodyStore::prune; 03-05 FetchSourceJob(runId) and IngestRun"
provides:
  - "App\\Ingestion\\RunLauncher::runNow(Source, RunTrigger = Manual): SourceRun — queued live run in a savepoint, FetchSourceJob dispatched after commit"
  - "App\\Ingestion\\RunLauncher::nextCheckAt(Source, CarbonImmutable $nowLondon): CarbonImmutable (UTC)"
  - "App\\Ingestion\\RunAlreadyActive(sourceId) — message 'This source is already being checked.'"
  - "Artisan ingest:dispatch-due {--source=*}: janitor + due-source dispatch (every 10 min, withoutOverlapping)"
  - "Artisan ingest:prune-raw: raw bodies > 30 days, raw_path cleared, year-old run logs (daily 03:50 London)"
  - "RawBodyStore::exists(sourceId, runId)"
affects: [03-07 health/preview (abandoned path, preview launch), 03-15 Run now / Create drafts actions, Phase 5 ops (dispatcher heartbeat optional)]

actuals:
  tokens: 9100
  tasks: 2
  commits: 2
plan_head_before: 6999d8087c052cf134188c4ee37a2fc6cc89ced7
plan_head_after: c377dddd0db602bdbe3b06fee56e107c3bbc231a

tech-stack:
  added: []
  patterns:
    - "Unique-index insert wrapped in DB::transaction (a savepoint when nested) so a refused insert never aborts the caller's transaction"
    - "Next-slot maths: London date + N days, CarbonImmutable::create(..., 'Europe/London'), + jitter, ->utc()"
    - "Tracer runs the real database queue in-test: artisan queue:work database --once"

key-files:
  created:
    - app/Ingestion/RunLauncher.php
    - app/Ingestion/RunAlreadyActive.php
    - app/Console/Commands/IngestDispatchDue.php
    - app/Console/Commands/IngestPruneRaw.php
    - tests/Feature/Ingestion/DispatchDueTest.php
    - tests/Feature/Ingestion/RunLauncherTest.php
    - tests/Feature/Ingestion/PruneRawTest.php
  modified:
    - routes/console.php
    - app/Ingestion/RawBodyStore.php

key-decisions:
  - "next_check_at is advanced only after the insert succeeds or is refused as already active; any other error leaves the source due so the next 10-minute pass retries (instead of silently skipping today's check)"
  - "A source with an active run is skipped by the dispatcher but still moves to its next slot (the active run is today's check)"
  - "nextCheckAt = London date of now + 1 (daily) / + 7 (weekly) days at check_time + (id × 7) mod ingest.schedule.jitter_minutes; it does not look at whether today's slot has passed"
  - "Janitor: running runs judged by started_at (< now − 15 min), queued runs by queued_at (< now − 2 h); strict '<' so exactly 15 min / 2 h is untouched"
  - "queued_at is always set from the (frozen-able) app clock; the column's DB default would use PostgreSQL's real clock"
  - "Run-log pruning keeps each source's latest run by id and never deletes queued/running runs; raw_sha256 is kept as the body fingerprint when raw_path is cleared"
  - "raw_path is cleared only when the body file is really gone (exists check) on runs queued before the retention cutoff, so mtime/row-time skew can never orphan a kept body"
  - "Jobs stay on the database connection / default queue (F8); no dispatcher heartbeat added (optional in PATTERNS, not in the plan)"

patterns-established:
  - "Dispatcher tests read push order via Queue::pushed(FetchSourceJob::class) mapped to source ids"
  - "Queue::fake keeps ShouldBeUnique locks; tests that simulate a finished job call Queue::releaseUniqueJobLocks()"

requirements-completed: [SRC-04]

coverage:
  - id: D1
    description: "Tracer: dispatch-due starts a due Baptist source, the database-queue worker runs it, drafts appear, next_check_at = 18 Oct 05:30 London + jitter (UTC)"
    requirement: SRC-04
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/DispatchDueTest.php#starts a due active source on schedule, creates its drafts and moves the next check to tomorrow 05:30 London plus jitter"
        status: pass
  - id: D2
    description: "Both commands registered with the scheduler"
    requirement: SRC-04
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/DispatchDueTest.php#registers the dispatcher and the raw-body pruner with the scheduler"
        status: pass
  - id: D3
    description: "One active run per source: RunAlreadyActive on a second runNow while queued or running; works again after finish; caller transaction survives"
    requirement: SRC-03
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/RunLauncherTest.php"
        status: pass
  - id: D4
    description: "Janitor, exact-now adjacency, inactive/unhealthy excluded, empty case, next_check_at/id ordering, already-active skip, DST storage on 25 Oct 2026 and 28 Mar 2027, weekly"
    requirement: SRC-04
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/DispatchDueTest.php (with a fake queue)"
        status: pass
  - id: D5
    description: "Raw retention 31 vs 29 days, raw_path cleared, year-old run logs deleted except the source's latest"
    requirement: SRC-04
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/PruneRawTest.php"
        status: pass

duration: ~8min
completed: 2026-10-18
---

# Phase 3 Plan 06: Run Launcher, Scheduler, Janitor and Raw Pruning Summary

**Sources now get checked without anyone pressing a button. `ingest:dispatch-due` runs every 10 minutes. It first marks abandoned runs as failed, then queues a scheduled run for each due active source (not unhealthy) through `RunLauncher::runNow`, and moves the source's next check to 05:30 London plus a fixed jitter of 0–19 minutes, stored in UTC. The `source_runs_one_active` index makes sure a source never has two runs at once: a refused insert raises `RunAlreadyActive`. `ingest:prune-raw` deletes raw bodies after 30 days and run logs after a year.**

## Performance

- **Duration:** ~8 min (09:31Z–09:39Z on 2026-10-18)
- **Tasks:** 2 (1 tracer + 1 TDD)
- **Files:** 7 created, 2 modified

## Accomplishments
- `RunLauncher::runNow()` sets `queued_at` from the app clock and inserts the queued live run in its own savepoint. A unique violation becomes `RunAlreadyActive`. It then calls `FetchSourceJob::dispatch($run->id)->afterCommit()`. The 03-15 "Run now" button and the dispatcher both use it.
- `RunLauncher::nextCheckAt()` takes the London date, adds 1 day (daily) or 7 days (weekly), sets `check_time` with `CarbonImmutable::create(..., 'Europe/London')`, adds `(id × 7) mod 20` minutes and converts with `->utc()`. On Sun 25 Oct 2026, 05:30 London is stored as 05:30 UTC; on Sun 28 Mar 2027 it is stored as 04:30 UTC.
- `ingest:dispatch-due`:
  - Janitor: two guarded UPDATEs mark runs `failed/abandoned` with our own message and `finished_at`.
  - Due query: `is_active`, `health <> 'unhealthy'`, `next_check_at IS NULL OR <= now`, ordered by `next_check_at NULLS FIRST, id`.
  - Each source is handled on its own. Logs carry ids and the exception class only, and the exit code is 1 if any source failed.
  - The `--source=*` filter follows the SyncOccurrences pattern.
- `ingest:prune-raw`:
  - calls `RawBodyStore::prune(30)`;
  - clears `raw_path` where the body is really gone;
  - deletes runs queued more than a year ago that are not their source's latest run and are not active. `source_items` run links are set to null by their foreign keys.
- Schedule entries: dispatch-due runs every 10 minutes with `withoutOverlapping`; prune-raw runs daily at 03:50 London with `withoutOverlapping`, outside the DST hour. Each entry has a one-line comment explaining why.

## Task Commits

1. **Task 1 (tracer): scheduler tracer — ingest:dispatch-due starts due sources via RunLauncher**: `9b4a139` (feat)
2. **Task 2 (tdd): one run per source, janitor, due-time edges, DST next check and raw pruning**: `c377ddd` (feat)

**Tracer gate:** after Task 1 I re-ran the plan's `<verify>` from start to finish: DispatchDueTest and RunNowTracerTest (6 passed), `schedule:list | grep -c ingest:dispatch-due` = 1, and `grep -c` on routes/console.php = 1. Only then did I start Task 2.

## Files Created/Modified
- `app/Ingestion/RunLauncher.php`: runNow and nextCheckAt
- `app/Ingestion/RunAlreadyActive.php`: the exception, carrying the source id
- `app/Console/Commands/IngestDispatchDue.php`: janitor and dispatch
- `app/Console/Commands/IngestPruneRaw.php`: retention
- `app/Ingestion/RawBodyStore.php`: new `exists(sourceId, runId)`
- `routes/console.php`: two schedule entries
- Tests:
  - DispatchDueTest: 14 tests, including the tracer, which runs the real database queue with `queue:work --once`
  - RunLauncherTest: 14 tests, including 7 next-check dataset rows
  - PruneRawTest: 4 tests

## Decisions Made
See `key-decisions` in the frontmatter. These are the ones that matter for later plans:
- **When next_check_at moves:** only after the insert succeeds, or is refused because a run is already active. The plan's order was "set next_check_at, then runNow". With that order, a database error would have silently skipped the day's check.
- **Already active:** a source that already has a run is skipped, but its next check still moves. The active run counts as today's check.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] next-check date taken from the London calendar, proven at London midnight in BST**
- **Found during:** Task 2, mutation check
- **Issue:** Swapping the London date for the UTC date passed every planned case. The two dates only differ between 00:00 and 01:00 London in BST. A dispatch at 00:30 would then have scheduled the next check for 05:30 the same day, a gap of only 5 hours.
- **Fix:** The code was already correct. I added the dataset row "just after London midnight in BST (UTC is still the day before)" so a regression would fail.
- **Commit:** `c377ddd`

**2. [Rule 2 - Correctness] `RawBodyStore::exists()` (file not in the plan's list)**
- Clearing `raw_path` safely needs a check that the body is really gone. File modification time and the run's `queued_at` can differ, and clearing by run age alone could leave a body on disk that the UI no longer points to. A small method on the store keeps path-building in one place.
- **Commit:** `c377ddd`

### Judgement calls (unattended run)
- **Commits on `main`**, as instructed.
- **Tracer worker:** FetchSourceJob pins itself to the `database` connection, so a plain dispatch in tests does not run in sync mode. The tracer therefore runs `queue:work database --once` inside the test transaction. This is the real queue path, and it also releases the unique lock.
- **ShouldBeUnique interaction (left as designed in 03-05):** the job's unique lock is per source and lasts 900 s, and `Queue::fake` keeps it. In production the lock is released when the job finishes or fails. In the rare case where a lock is left behind (for example the jobs table was flushed), a new run could stay queued without a job until the 2-hour janitor clears it. The DB index is still what guarantees one active run per source.
- **No heartbeat** for the dispatcher. PATTERNS marks it optional and the plan leaves it out; it can go with Phase 5 ops.
- **RED order:** both Task 1 tests failed before any code existed (the command did not exist). In Task 2, PruneRawTest failed first (command missing). The RunLauncher and dispatcher tests are characterisation tests of the Task 1 code. Six mutations were tried (`<=` → `<`, `NULLS LAST`, a 17-minute timeout, no health filter, no savepoint, UTC date maths). All of them now make a test fail.

---

**Total deviations:** 2 auto-fixed, plus the judgement calls above. **Impact:** the plan was delivered as specified. No packages were added.

## Known Stubs
None. Source health (consecutive_failures, health) is not updated when the janitor abandons a run. 03-07 owns SourceHealthTracker and should hook it into both FetchSourceJob's abandoned path and this janitor. (`.planning/WINDOWS.md` does not exist, so nothing was appended.)

## Threat Flags
None. T-03-17 is covered: there is one active run per source (DB index, tested), plus the 03-03 host throttle. T-03-18 is covered: the janitor is tested at the 14/16-minute and 119/121-minute boundaries. No packages were installed (T-03-SC).

## Issues Encountered
None. The PHPUnit warning from earlier plans is still there.

## User Setup Required
None. Production already runs `schedule:run` from cron and the database queue worker (Phase 1 ops).

## Next Phase Readiness
- **03-07:**
  - Preview runs need a launcher entry point. Add `RunLauncher::preview()` or a `RunMode` parameter; the insert and savepoint pattern can be reused.
  - Call SourceHealthTracker for runs the janitor abandons.
- **03-15:**
  - "Run now" calls `app(RunLauncher::class)->runNow($source)`, catches `RunAlreadyActive` and shows `$e->getMessage()`.
  - "Create drafts" uses `RunTrigger::Activation`.
- Full Unit+Feature suite: 1335 passed. PHPStan: 0 errors. Pint: clean. The new tests also pass under `TZ=America/New_York`.

## Requirements Traceability
- **SRC-04 marked Complete:** due active sources are fetched automatically every early morning (London), each source has at most one queued or running run (index, RunAlreadyActive, tested), and abandoned runs are cleared.
- **SRC-03 left Pending:** the engine (`runNow`) is done, but the admin's "Run now" button arrives in 03-15.

## Self-Check: PASSED
