---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 07
subsystem: ingestion
tags: [ingestion, health, preview, ops, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-01 schema (health columns, is_complete, structure_alert, preview jsonb); 03-05 IngestRun, SourceItemRecorder, FetchSourceJob; 03-06 RunLauncher, ingest:dispatch-due janitor; 03-08 ApproveDraft for sourced drafts"
provides:
  - "App\\Enums\\SourceHealth (Healthy, Degraded, Unhealthy; 'Unhealthy (paused)' label; forFailures(int)); cast on Source::health"
  - "App\\Ingestion\\Health\\SourceHealthTracker::record(SourceRun): health, structure alert and is_complete for finished live runs"
  - "RunLauncher::testRun(Source) (preview run) and RunLauncher::createDrafts(Source) (activate + live activation run)"
  - "SourceItemRecorder::verdict(source, item, nowLondon): the write-free bucket shared by preview and live"
  - "source_runs.preview = {url, would_create, skipped: {past, far_future, rule, parse_error}, rows ≤ 200: {title, starts, verdict, reason}}"
  - "HealthReport::sources(): HealthCheck (key 'sources'); System health lists it; ops:health prints it as a warning"
affects: [03-09 missing detection (reads is_complete), 03-15 source admin (Test run / Create drafts / Run now buttons, health badges, preview section), Phase 4 SRC-02, Phase 5 ops:health deploy check]

actuals:
  tokens: 17700
  tasks: 3
  commits: 3
plan_head_before: 39ad8a8ea84b8316e9f9b690a945b26d34aa55a3
plan_head_after: 24c1e52b55582417656d80c5371d600c3eb15ae2

tech-stack:
  added: []
  patterns:
    - "Health is written in one transaction that locks only the source row; runs never lock or write events, drafts or occurrences"
    - "Janitor abandons each stale run with its own guarded UPDATE, then records it (so a run that finished meanwhile is never counted)"
    - "Preview and live share SourceItemRecorder::classify(); the preview path only reads"
    - "Body reuse is guarded by the preview's stored URL and the body's sha256"

key-files:
  created:
    - app/Enums/SourceHealth.php
    - app/Ingestion/Health/SourceHealthTracker.php
    - tests/Feature/Ingestion/SourceHealthTest.php
    - tests/Feature/Ingestion/PreviewRunTest.php
  modified:
    - app/Ingestion/IngestRun.php
    - app/Ingestion/RunLauncher.php
    - app/Ingestion/Pipeline/SourceItemRecorder.php
    - app/Jobs/FetchSourceJob.php
    - app/Console/Commands/IngestDispatchDue.php
    - app/Console/Commands/OpsHealth.php
    - app/Filament/Pages/SystemHealth.php
    - app/Ops/HealthReport.php
    - app/Models/Source.php
    - tests/Feature/Ops/HealthReportTest.php
    - tests/Feature/Ingestion/IngestionSchemaTest.php
    - tests/Feature/Ingestion/SourceItemRecorderTest.php

key-decisions:
  - "structure_alert_at is cleared by the next live succeeded run that finds items. The plan only says when it is set; without clearing, System health would warn for ever after one empty run"
  - "is_complete compares with the previous run whose is_complete is true (not the previous success), and exactly 50% counts as complete (items × 2 ≥ previous)"
  - "Preview runs write nothing on the source: no last_run_at, etag/last_modified, next_check_at or health. An etag from a preview would let the next live run get a 304 for a body the live pipeline never processed"
  - "Previews never send conditional validators (a 304 has no body to preview)"
  - "Preview runs fill the run counters as 'would be' values (items_new = would_create) with drafts_created 0, so the run log reads the same for both modes"
  - "preview.rows lists every item in feed order up to 200: verdict new/changed/unchanged/skipped, reason past/far_future/rule/parse_error (null otherwise); starts is London 'Y-m-d' or 'Y-m-d H:i' (next instance for a series)"
  - "D-24 reuse uses the latest finished preview only (what the admin saw): it must have succeeded, fetched the source's current URL (stored as preview.url), finished strictly under preview_reuse_minutes ago, and its stored body must still match raw_sha256. The reused body is stored again under the activation run"
  - "createDrafts activates and inserts in one transaction: when a run is already active, nothing changes (is_active, next_check_at rolled back, model refreshed) and RunAlreadyActive is thrown"
  - "HealthReport::sources() is Stale (as the plan says) with the counts; Ok detail is 'N active sources, all healthy' or 'No active sources'. lastSeenAt = latest last_run_at of active sources"
  - "ops:health prints 'sources: OK' or 'sources: STALE (warning): <detail>' after the all() lines; the exit code ignores it. HealthReport::all() is unchanged"
  - "FetchSourceJob::handle() now takes SourceHealthTracker as a second injected argument; a tracker error in the catch-all is reported via rescue() and never hides the original exception"

requirements-completed: [SRC-08, SRC-09]

coverage:
  - id: D1
    description: "Tracer: five failing live runs → degraded at 3, unhealthy at 5; dispatcher skips it; published event still on /; System health and ops:health (exit 0, warning) name the paused source"
    requirement: SRC-08
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/SourceHealthTest.php#degrades a failing source at 3, pauses it at 5, keeps the published event and names it on System health"
        status: pass
  - id: D2
    description: "Abandoned runs count: three janitor-abandoned live runs → degraded; job catch-all +1; abandoned preview changes nothing; success heals"
    requirement: SRC-08
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/SourceHealthTest.php (janitor, catch-all, preview, recovery tests)"
        status: pass
  - id: D3
    description: "Edges: 2/3/4/5 boundaries, fail-fail-blocked-fail, not_modified reset, structure alert cases, 50% completeness at 30/31/32/40 of 64, previous-complete comparison"
    requirement: SRC-08
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/SourceHealthTest.php › health and completeness edges (SRC-08)"
        status: pass
  - id: D4
    description: "SRC-09: row snapshots of events, occurrences, drafts, items and links identical after repeated failed and blocked runs and after five failures; approval intact with a failed run in the same open transaction (both orders)"
    requirement: SRC-09
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/SourceHealthTest.php › failure isolation (SRC-09)"
        status: pass
  - id: D5
    description: "D-01 preview writes nothing (items, drafts, source state, health), stores would_create/skipped/rows (≤ 200); failed preview leaves health alone; known items show as unchanged"
    requirement: SRC-02
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/PreviewRunTest.php"
        status: pass
  - id: D6
    description: "D-24 Create drafts: activates, reuses a 30-minute-old preview body (no second feed request), refetches at 61 minutes or after a URL change; RunAlreadyActive leaves the source inactive"
    requirement: SRC-02
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/PreviewRunTest.php"
        status: pass
  - id: D7
    description: "System health Sources line and ops:health warning-only behaviour"
    requirement: SRC-08
    verification:
      - kind: integration
        ref: "tests/Feature/Ops/HealthReportTest.php › sources (SRC-08)"
        status: pass

duration: ~14min
completed: 2026-10-18
---

# Phase 3 Plan 07: Source Health, Completeness and the Onboarding Preview Summary

**Live runs now move a source's health: a success or 304 resets it, each failure (abandoned runs included) adds one, 3 failures make it degraded and 5 make it unhealthy, which the scheduler skips. A zero-item run after a run with items raises a structure alert. `is_complete` marks the runs that 03-09's missing detection may trust. "Test run" previews a source and writes nothing. "Create drafts" turns the source live and reuses the preview's body when it is under an hour old. No run outcome changes a published event.**

## Performance

- **Duration:** ~14 min (09:57Z–10:11Z on 2026-10-18)
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 4 created, 12 modified

## Accomplishments
- `SourceHealthTracker::record()` is called after every finished live run from three places:
  - `IngestRun::finish()`, before 03-09's missing-detection hook;
  - FetchSourceJob's catch-all, after it marks the run failed/abandoned;
  - the `ingest:dispatch-due` janitor, once for each run it abandons.
- It locks only the source row. It sets health and consecutive_failures, the run's `structure_alert` and `is_complete`, and the source's `structure_alert_at`. Previews, blocked runs and unfinished runs are ignored.
- The janitor now abandons each stale run with its own guarded UPDATE that re-checks status and age, then records it. A run that finished in the meantime is neither swept nor counted.
- Preview mode in `IngestRun`:
  - fetches the same polite way, without conditional validators, and stores the raw body;
  - classifies each item through `SourceItemRecorder::verdict()`, which uses the same `classify()` as `record()`;
  - stores `preview` and the "would be" counters;
  - skips every write to the source (state, validators, health).
- Activation runs reuse the latest preview's body under the D-24 guards: same URL, under 60 minutes old, sha256 still matches. Otherwise they fetch politely.
- `HealthReport::sources()` is shown on the System health page as a "Sources" line. `ops:health` prints it as a warning and its exit code ignores it. `all()` is unchanged, so the existing ops contract and its tests stay as they were.

## Task Commits

1. **Task 1 (tracer): failing live runs degrade then pause a source, published events stay, System health names it**: `4d1c7be` (feat)
2. **Task 2 (tdd): test-run preview (D-01) and Create drafts with preview-body reuse (D-24)**: `956b6c9` (feat)
3. **Task 3 (tdd): health and completeness edges, SRC-09 idempotency and concurrency**: `24c1e52` (test)

**Tracer gate:** after Task 1, I re-ran the plan's `<verify>` end to end (SourceHealthTest, HealthReportTest, plus the Ingestion and Ops folders: 218 passed) before starting Task 2.

## Decisions Made
See `key-decisions` in the frontmatter. The ones that matter for later plans:
- **03-09:** read `is_complete = true` runs only. The tracker sets it inside `finish()`, before your hook runs.
- **03-15:**
  - "Test run" calls `testRun()`.
  - "Create drafts" calls `createDrafts()`.
  - Both throw `RunAlreadyActive`; show `$e->getMessage()`.
  - Poll the latest preview run and render `preview.rows`. Because of jsonb, `skipped` keys come back in any order.
  - Badges: `$source->health` is a `SourceHealth` with label and colour; `structure_alert_at` drives the structure badge.
- **`structure_alert_at` clears itself** on the next live success that finds items. 03-15 may add a manual dismiss if wanted.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Correctness] Previews must not write source state**
- **Found during:** Task 2
- **Issue:** `finish()` updated last_run_at, etag and last_modified for every run. If a preview stored an etag, the next live run would send it, get a 304 and never process the body.
- **Fix:** `finish()` returns right after saving the run for non-live runs. Previews also never send conditional headers.
- **Commit:** `956b6c9`

**2. [Rule 2 - Correctness] D-24 reuse guarded by URL and body fingerprint**
- **Found during:** Task 2
- **Issue:** if the admin edits the source URL after a preview, reusing that body would draft events from the old address.
- **Fix:** the preview stores `preview.url`. Reuse requires it to equal the current `sources.url`, and the stored body must still hash to `raw_sha256`. Tested.
- **Commit:** `956b6c9`

**3. [Rule 1 - Bug] Janitor could count a run that had already finished**
- **Found during:** Task 1
- **Issue:** the 03-06 janitor used one mass UPDATE, so it could not tell which runs to record. Selecting ids first and then updating them would race with a run finishing.
- **Fix:** each candidate gets its own UPDATE that re-checks status and age. Only rows actually updated are recorded.
- **Commit:** `4d1c7be`

### Judgement calls (unattended run)
- **Commits on `main`**, as instructed.
- **Files outside the plan's list:**
  - `tests/Feature/Ingestion/IngestionSchemaTest.php`: expects the `SourceHealth` cast.
  - `tests/Feature/Ingestion/SourceItemRecorderTest.php`: calls `handle()` with the new injected tracker.
- **Concurrency test:** one PHP process cannot hold two real DB connections inside the test transaction. The test runs the failed run and `ApproveDraft` inside one open transaction, in both orders, and compares row snapshots. This is safe because the tracker locks only the source row and runs never touch events or drafts.
- **RED order:** SourceHealthTest and HealthReportTest failed before Task 1 code existed (missing enum, method and hooks). PreviewRunTest failed before Task 2 code existed (`testRun` missing). Task 3's tests passed on the Task 1–2 code, so they are characterisation tests. Three mutations of the tracker (`>=` → `>` on completeness, blocked counted as a failure, comparing against the previous success instead of the previous complete run) each made a Task 3 test fail. A mutation that turned off body reuse made the D-24 test fail.

---

**Total deviations:** 3 auto-fixed, plus the judgement calls above. **Impact:** the plan was delivered as specified. No packages were added.

## Known Stubs
None. (`.planning/WINDOWS.md` does not exist, so nothing was appended.)

## Threat Flags
None. All the new surfaces are covered by the plan's threat model:
- T-03-19: preview writes are covered by count and snapshot tests.
- T-03-20: published events are covered by snapshot tests and by checking that `/` still shows the event.
- T-03-21: the sources line is a warning and does not affect the exit code (tested).

## Issues Encountered
- Two test-only mistakes, both fixed before commit:
  - A second `Http::fake()` appends instead of replacing (known 03-05 pattern). Tests now change one shared state object.
  - jsonb reorders object keys, so the test checks the set of keys, not their order.

## User Setup Required
None.

## Next Phase Readiness
- Full Unit+Feature suite: 1391 passed. PHPStan: 0 errors. Pint: clean. The new tests also pass under `TZ=America/New_York`.

## Requirements Traceability
- **SRC-08 marked Complete:**
  - degraded at 3 and unhealthy (paused) at 5, flagged on the System health dashboard;
  - a structure alert for zero items after items.
- **SRC-09 marked Complete:**
  - no run outcome deletes, hides or changes any published event or occurrence (snapshot tests);
  - incomplete runs are marked, so 03-09's missing detection can skip them.
- **SRC-02 left Pending (Phase 4, D-26):** the ICS preview engine and stored preview are delivered here. The admin button arrives in 03-15, and the other extraction rungs in Phase 4.

## Self-Check: PASSED
