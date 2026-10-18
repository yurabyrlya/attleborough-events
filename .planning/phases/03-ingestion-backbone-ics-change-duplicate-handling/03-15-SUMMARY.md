---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 15
subsystem: admin-ui
tags: [filament, sources, ingestion, preview, run-log, health, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-01 sources/source_runs/crawl_hosts schema; 03-02 SsrfGuard::checkUrl; 03-03 HostThrottle::keyFor and the crawl_hosts robots cache; 03-06 RunLauncher::runNow and RunAlreadyActive; 03-07 RunLauncher::testRun/createDrafts, run.preview, SourceHealth"
provides:
  - "Filament Sources resource (app/Filament/Resources/Sources): list, create, edit, run log relation manager"
  - "SourceForm: every SRC-01 field, D-04 title rules repeater (Skip / Keep only + keyword), D-25 'Organiser's own site', SsrfGuard URL rule"
  - "SourceActions: testRun, createDrafts, runNow, pause header actions; SourceActions::RUNS_CHANGED Livewire event"
  - "EditSource 'Latest test run' panel (preview.blade.php) that polls every 3 s only while a run is active; Crawl-delay / blocked / structure / paused callouts"
  - "RunsRelationManager: run log newest first (queued_at desc, id desc), London times, every counter, polls only while a run is active"
  - "SourcesTable: health badge, active, last live run status, structure alert, last success / next check in London; active + health filters; nav badge for live sources needing attention"
  - "Source::latestLiveRun() (HasOne ofMany, live runs only) and Source::hasActiveRun()"
affects: [03-11 duplicate note in preview rows, 03-16/03-17 review UI and browser UAT flow for sources, Phase 4 SRC-02 for the other extraction rungs]

actuals:
  tokens: 20000
  tasks: 3
  commits: 3
plan_head_before: 0cbc805b5b2efebf7441a59b6d045d9d06cb770b
plan_head_after: dcdb519889e2b0075ff9edf8872e968cd4347ae7

tech-stack:
  added: []
  patterns:
    - "Default Filament create/edit record saves for sources (C18), with SourceForm::prepareForSave() whitelisting the fillable configuration columns"
    - "Page-level wire:poll.3s inside a schema View, rendered only while a run is active, so the header actions follow the run without a reload"
    - "Header actions dispatch a Livewire event (RUNS_CHANGED) so the lazy relation manager re-renders and starts polling"
    - "Unique-insert in a savepoint; UniqueConstraintViolationException becomes a ValidationException on data.name"

key-files:
  created:
    - app/Filament/Resources/Sources/SourceResource.php
    - app/Filament/Resources/Sources/Schemas/SourceForm.php
    - app/Filament/Resources/Sources/Tables/SourcesTable.php
    - app/Filament/Resources/Sources/Pages/ListSources.php
    - app/Filament/Resources/Sources/Pages/CreateSource.php
    - app/Filament/Resources/Sources/Pages/EditSource.php
    - app/Filament/Resources/Sources/RelationManagers/RunsRelationManager.php
    - app/Filament/Resources/Sources/Actions/SourceActions.php
    - resources/views/filament/resources/sources/preview.blade.php
    - tests/Feature/Filament/SourceActionsTest.php
    - tests/Feature/Filament/SourceResourceTest.php
  modified:
    - app/Models/Source.php
    - app/Enums/FetchFailureKind.php

key-decisions:
  - "C18: sources use the default Filament create/edit saves (no SaveSource action). Validation is in SourceForm; SourceForm::prepareForSave() keeps only fillable configuration columns, so is_active/health/pipeline columns can never arrive through the form"
  - "'Create drafts' is visible when the source is inactive AND its newest run (queued_at desc, id desc) is a succeeded test run. A paused source therefore needs a fresh test run before it can go live again, and Create drafts hides while any run is queued or running"
  - "Pause sets is_active false and clears next_check_at (the table shows 'Paused'). A run that is already queued or running still finishes"
  - "The slug is generated once on create (Str::slug, 'source' if empty, base capped at 130 chars, -2/-3 suffix) and kept when the source is renamed"
  - "An empty short name becomes the first 40 characters of the name"
  - "Crawl-delay warning (Pitfall 7): shown when the host's Crawl-delay is longer than the check interval OR longer than the 2-hour queued-run limit (IngestDispatchDue::QUEUED_TIMEOUT_MINUTES). Against the check interval alone, the plan's own 12000 s example could never trigger (12000 s < 1 day, and crawl_hosts caps the value at 86400). A check needs up to two requests to the host, and a run that waits more than 2 hours in the queue is marked abandoned, so 12000 s really does stop checks from finishing"
  - "The preview panel uses a page-level wire:poll.3s (full re-render) instead of a section-level ->poll(): a partial render would leave 'Create drafts' hidden until a manual reload once the test run finishes. The run log uses the table ->poll() closure"
  - "The 'Last run' column and the needs-attention badge use the newest LIVE run. Previews are not checks, so a test run never hides a blocked live run"
  - "FetchFailureKind::ContentInvalid label is now 'Content invalid (not a feed)'. The run log shows failure kinds by label. No error message is built from this label"
  - "The item identity select uses plain string options. Enum options would put an IdentityStrategy object into the parser_config json state"
  - "Year policy offers only 'none' (ICS dates always carry the year) and the adapter select is disabled and empty: both matter only to Phase 4 extractors"

patterns-established:
  - "Sources admin tests run the real database queue with queue:work --once after each action (FetchSourceJob is pinned to the database connection)"
  - "Runs created by ingestLiveRun get queued_at from PostgreSQL's real clock, so tests that order runs set queued_at explicitly"

requirements-completed: [SRC-01, SRC-03, SRC-07, SRC-08]

coverage:
  - id: D1
    description: "Tracer: create a source (saved inactive), Test run drafts nothing and shows 'Would create N drafts', Create drafts makes the source live and drafts exactly N, the run log lists the activation run then the preview; the full list and edit pages render"
    requirement: SRC-01
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/SourceActionsTest.php#onboards a source: create inactive, test run drafts nothing, Create drafts drafts the preview count and logs both runs"
        status: pass
  - id: D2
    description: "Run now on a live source queues a manual live run; a second Run now or Test run while it is queued shows 'This source is already being checked.'"
    requirement: SRC-03
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/SourceActionsTest.php#runs an active source now and refuses a second run while one is queued"
        status: pass
  - id: D3
    description: "Pause; Create drafts only after a new succeeded test run (hidden after a failed one); preview polls only while a run is active; non-admin gets 403"
    requirement: SRC-01
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/SourceActionsTest.php (pause, failed test run, polling, 403 tests)"
        status: pass
  - id: D4
    description: "Sources table: Healthy / Degraded / Unhealthy (paused), Blocked by robots.txt (last live run), Possible structure change, London next check / last success, filters, nav badge"
    requirement: SRC-08
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/SourceResourceTest.php › sources table and run log"
        status: pass
  - id: D5
    description: "Run log: newest first by queued_at then id, 'Content invalid' and error message, counters, duration, polling only while active"
    requirement: SRC-07
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/SourceResourceTest.php#lists runs newest first by queued time, then id, with counters and the failure"
        status: pass
  - id: D6
    description: "Crawl-delay warning on the edit page (12000 s and 86400 s warn; 10 s daily and 7200 s weekly do not)"
    requirement: SRC-08
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/SourceResourceTest.php#warns on the edit page when the host Crawl-delay is too long for the checks"
        status: pass
  - id: D7
    description: "Form edges: required name/URL, empty rules and events page allowed, four URL refusals with the plain message, 120/121 characters (accented), 40-character short name, slug suffixes and stable slug on rename, parallel-save collision as a validation error, title rules and identity round trip, parser_config extra keys kept, inactive on create, queued run untouched by an edit"
    requirement: SRC-01
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/SourceResourceTest.php › source form (SRC-01)"
        status: pass

duration: ~16min
completed: 2026-10-18
---

# Phase 3 Plan 15: Sources Admin (Registry, Test Run, Create Drafts, Run Log, Health) Summary

**The admin can now manage ICS sources end to end in Filament. They can add a source with every SRC-01 field (it is saved inactive), click "Test run" to see what it would draft, and click "Create drafts" to make it live. After that, "Run now" and "Pause" are on the edit page. Health, the last run, structure alerts and the next check (London time) show in the sources table, and every run's counters and errors are in a run log that refreshes itself while a run is active.**

## Performance

- **Duration:** ~16 min (10:43Z–10:59Z on 2026-10-18)
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 11 created, 2 modified

## Accomplishments
- **Form (SourceForm):**
  - Fields: name (120), short name (40, defaults from the name), feed URL, events page URL, "Organiser's own site", extraction method (ICS), adapter (disabled), item identity (`parser_config.identity`), default time zone, year policy, location mode, default category and venue, check frequency and time, missing-item detection, title rules repeater, legal basis and notes.
  - Both URLs go through `SsrfGuard::checkUrl()`, with the message "Use an http or https web address on the standard port, without a username or password."
- **Edit page:**
  - Header actions: Test run, Create drafts, Run now and Pause. Each one calls `RunLauncher`, and `RunAlreadyActive` shows as a warning notification.
  - The "Latest test run" panel shows:
    - "Would create N drafts";
    - skipped counts for past, beyond 90 days, title rule and parse error;
    - up to 200 rows with title, London date and time, verdict, reason, and a "Possible duplicate" note when 03-11 adds one.
  - Callouts warn about a source paused after repeated failures, a robots.txt block, a possible structure change, or a Crawl-delay that is too long.
- **Run log:** read-only and newest first. It shows status, the problem (failure kind label), who started it, mode, queued time, duration, all 13 counters and the error. It polls every 3 s only while a run is queued or running.
- **Sources table:** health badge, active, last live run status, structure alert, last success and next check (London). It has active and health filters and a warning badge in the navigation.

## Task Commits

1. **Task 1 (tracer): Sources admin, test run, Create drafts, Run now, Pause, run log**: `c032957` (feat)
2. **Task 2 (tdd): Sources table with health, last run, structure alert and London times; run log edges**: `3867fa2` (feat)
3. **Task 3 (tdd): source form edges**: `dcdb519` (test)

**Tracer gate:** after Task 1, I re-ran the plan's `<verify>` from start to finish (`SourceActionsTest`, 6 passed, 107 assertions, including the full-page GETs). Only then did I start Task 2.

## Decisions Made
See `key-decisions` in the frontmatter. These are the ones later plans need:
- **Create drafts visibility:** the source must be inactive and its newest run must be a succeeded test run. Because of this, re-enabling a source always goes through a fresh preview (D-01).
- **Crawl-delay warning:** it also fires when the delay is longer than the 2-hour queued-run limit. The plan's 12000 s example can only trigger this way, and it describes a real failure: the janitor abandons the waiting run.
- **Polling:** the preview panel uses a page-level `wire:poll.3s`, so the header buttons update by themselves when a run finishes. The run log uses the table's `->poll()` closure.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The Crawl-delay rule as written could never fire**
- **Found during:** Task 2
- **Issue:** comparing Crawl-delay with the check interval cannot trigger for a daily source. `crawl_hosts` caps the value at 86400 s, and the plan's own test value (12000 s) is under a day.
- **Fix:** the warning also fires when the delay is longer than the 2-hour queued-run limit, because such runs are abandoned. The title wording still starts "Crawl-delay {n}s is longer than the check interval".
- **Files modified:** `app/Filament/Resources/Sources/Pages/EditSource.php`
- **Commit:** `c032957` (code) and `3867fa2` (test)

**2. [Rule 1 - Bug] Enum state in json form data**
- **Found during:** Task 3
- **Issue:** a Select over the `IdentityStrategy` enum put an enum object into `parser_config.identity`'s form state.
- **Fix:** the Select now uses plain string options.
- **Commit:** `dcdb519`

**3. [Rule 2 - Correctness] Files outside the plan's list**
- `app/Models/Source.php`: added `latestLiveRun()` (for the Last run column and the badge) and `hasActiveRun()` (for polling).
- `app/Enums/FetchFailureKind.php`: changed the ContentInvalid label to "Content invalid (not a feed)" so the run log matches the planned wording.
- **Commits:** `c032957`, `3867fa2`

### Judgement calls (unattended run)
- **Commits on `main`**, as instructed.
- **Pause** also clears `next_check_at` and leaves an in-flight run alone.
- **Slug** is not changed by a rename.
- **Modal copy:** "Create drafts and check this source daily?" says "weekly" for weekly sources.
- **RED order:**
  - SourceActionsTest failed first because the classes were missing.
  - In Task 2, three tests failed first: the table had no columns or filters yet, and the label was still "Not a valid feed".
  - In Task 3, the first run had one RED (the enum state). The other tests characterise the Task 1 form. A mutation that removed the savepoint and the unique-violation catch in `CreateSource` made the parallel-save test fail.

---

**Total deviations:** 3 auto-fixed, plus the judgement calls above. **Impact:** the plan was delivered as specified. No packages were added.

## Known Stubs
None. The disabled "Adapter" select and the single "Year policy" option are intentional. They only matter to Phase 4 extractors, and the form says so. (`.planning/WINDOWS.md` does not exist, so nothing was appended.)

## Threat Flags
None. All the new surfaces are covered by the plan's threat model:
- T-03-40: SsrfGuard rule on both URLs; the fetch-time guard is still in place.
- T-03-41: `canAccess` goes through AdminGate, with a 403 test.
- T-03-42: keywords only, and identity is an enum select.
- No packages were installed (T-03-SC).

## Issues Encountered
- Relation managers load lazily, so a full-page GET does not contain the run log HTML. The test asserts the preview panel instead, and checks the run log through the relation manager component.

## User Setup Required
None. `npm run build` was run, because the theme scans `resources/views/filament`.

## Next Phase Readiness
- **03-11:** preview rows can carry `duplicate` (true, a string title, or `{title}`). The panel shows "Possible duplicate" or "Possible duplicate of {title}".
- **03-17:** add the Sources flow (create → Test run → Create drafts → Run now → Pause) to `docs/uat/browser-uat.md`.
- Full Unit+Feature suite: 1460 passed (1 pre-existing PHPUnit warning). PHPStan: 0 errors. Pint: clean.

## Requirements Traceability
- **SRC-01 marked Complete:** create and edit every listed field. The active flag is changed through Create drafts and Pause.
- **SRC-03 marked Complete:** the "Run now" button exists.
- **SRC-07 and SRC-08** were already Complete (engine). The run log and health are now visible in the admin.
- **SRC-02 left Pending (Phase 4, D-26):** the ICS test-run preview button is delivered here, but the other extraction rungs come in Phase 4.

## Self-Check: PASSED
