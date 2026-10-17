---
phase: 02-draft-model-review-queue-submissions
plan: 01
subsystem: database
tags: [laravel, filament, postgres, review-queue, drafts, publishing]

requires:
  - phase: 01
    provides: PublishEvent (only creator of Event rows), PublishableEventData, ManualRecurrence, AdminGate, Filament CreateEvent page
provides:
  - event_drafts table (enum CHECKs, D-09 queue index, approved-has-event CHECK, partial unique index event_drafts_one_new_per_event)
  - EventDraft model with content-only #[Fillable]
  - ReviewStatus state machine, DraftKind, ExtractionMethod enums
  - InvalidReviewTransition and DraftNotPublishable exceptions
  - DraftData (fromManualForm, toAttributes), DraftWriter::create with derive() hook, ReferenceCode (WOA-XXXXXX)
  - ApproveDraft action (one transaction around PublishEvent) and PublishableEventData::fromDraft()
  - Manual entry rerouted through a manual draft plus immediate approval (D-07)
affects: [02-03, 02-04, 02-05, 02-06, 02-08, 02-12, 02-14, 02-15]

actuals:
  tokens: 12690
  tasks: 2
  commits: 2
plan_head_before: 8ca700eb0139d3def1a4263563081a4f02a737a9
plan_head_after: cb510e1c2c421ff9659e03e2af9637305d3e7239

tech-stack:
  added: []
  patterns:
    - "Draft-first publication: every new event is created by ApproveDraft -> PublishEvent; CreateEvent no longer calls PublishEvent"
    - "Review state is forceFilled only by DraftWriter and Publishing review actions; EventDraft #[Fillable] lists content columns only"
    - "Lock -> assertCanTransitionTo -> publish -> stamp inside one DB::transaction; log ids only via DB::afterCommit"

key-files:
  created:
    - app/Enums/ReviewStatus.php
    - app/Enums/DraftKind.php
    - app/Enums/ExtractionMethod.php
    - app/Review/Exceptions/InvalidReviewTransition.php
    - app/Review/Exceptions/DraftNotPublishable.php
    - database/migrations/2026_10_17_100200_create_event_drafts_table.php
    - app/Models/EventDraft.php
    - app/Review/Data/DraftData.php
    - app/Review/DraftWriter.php
    - app/Review/ReferenceCode.php
    - app/Publishing/Actions/ApproveDraft.php
    - tests/Feature/Review/ManualEntryThroughDraftTracerTest.php
  modified:
    - app/Publishing/Data/PublishableEventData.php
    - app/Filament/Resources/Events/Pages/CreateEvent.php

key-decisions:
  - "ApproveDraft logs 'draft approved' through DB::afterCommit, so an approval nested in CreateEvent's outer transaction is logged only after the outermost commit and a rolled-back approval is never logged"
  - "PublishableEventData::fromDraft() also blocks a draft with no start date ('Add a start date.'), beside the planned category/venue/repeat-rule blockers, because event_drafts.start_date is nullable"
  - "No EventDraftFactory or HasFactory yet: later plans (02-03 onward) own the factory; this plan's tests create drafts through DraftWriter or raw inserts"

patterns-established:
  - "DraftWriter::create(DraftData): the only writer of event_drafts field data, with a single private derive() hook run on every write"
  - "DraftNotPublishable carries plain-English blockers; Filament pages show them as a warning Notification and throw Halt"

requirements-completed: [REV-02]

coverage:
  - id: D1
    description: "Filament New event form creates one approved manual draft linked to the new event, reviewer and time matching the event's approver and approval time"
    requirement: "REV-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/ManualEntryThroughDraftTracerTest.php#publishes manual entry through an approved manual draft linked to the event"
        status: pass
    human_judgment: false
  - id: D2
    description: "Approval is one transaction: a failed draft stamp after PublishEvent leaves no event, occurrence or draft"
    requirement: "REV-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/ManualEntryThroughDraftTracerTest.php#rolls back the event, occurrence and draft when stamping the draft fails after publishing"
        status: pass
    human_judgment: false
  - id: D3
    description: "Schema guards: approved needs an event, reject_reason only on rejected rows, quality_score 0-100; review state not mass-assignable"
    requirement: "REV-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/ManualEntryThroughDraftTracerTest.php (3 CHECK cases + mass-assignment case)"
        status: pass
    human_judgment: false
  - id: D4
    description: "ApproveDraft by a non-admin throws AuthorizationException and writes nothing"
    requirement: "REV-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/ManualEntryThroughDraftTracerTest.php#refuses approval by anyone but the admin and writes nothing"
        status: pass
    human_judgment: false
  - id: D5
    description: "Real admin page still publishes a monthly series through the new path (browser F3 + F4)"
    requirement: "REV-02"
    verification:
      - kind: e2e
        ref: "tests/Browser/AdminCreateEventTest.php"
        status: pass
    human_judgment: false

duration: 7min
completed: 2026-10-17
status: complete
---

# Phase 2 Plan 01: Manual Entry Through the Draft Path Summary

**The admin's New event form now writes a `manual` event_drafts row and approves it at once through a new ApproveDraft action. ApproveDraft locks the draft, publishes through PublishEvent and stamps the draft, all in one transaction (D-07, REV-02).**

## Performance

- **Duration:** 7 min
- **Started:** 2026-10-17T17:40:15Z
- **Completed:** 2026-10-17T17:47:00Z
- **Tasks:** 2
- **Files modified:** 14

## Accomplishments

- `event_drafts` table with every planned column. It has CHECK constraints for each enum column plus `approved_has_event` and `reject_reason_only_when_rejected`, the D-09 queue index, and the partial unique index `event_drafts_one_new_per_event`.
- The `EventDraft` model, the `ReviewStatus` state machine, `DraftKind`, `ExtractionMethod`, `DraftData`, `DraftWriter` and `ReferenceCode`. These are the base that the later Phase 2 plans extend.
- `ApproveDraft`: AdminGate, then lock, then transition, then `fromDraft`, then `PublishEvent`, then stamp, in one transaction. `PublishEvent` is still the only creator of Event rows.
- `CreateEvent` sends manual entry through draft plus approval. The redirect and all existing tests are unchanged: the full Unit+Feature suite passed (523 tests), and browser F3/F4 passed.

## Task Commits

1. **Task 1: Tracer: manual entry → draft → ApproveDraft → PublishEvent** - `88189cc` (feat)
2. **Task 2: Harden the tracer (rollback, CHECKs, non-fillable state, non-admin, browser F3)** - `cb510e1` (test)

## Files Created/Modified

- `app/Enums/ReviewStatus.php`: review state machine (pending → approved/rejected/duplicate/superseded; rejected → pending)
- `app/Enums/DraftKind.php`, `app/Enums/ExtractionMethod.php`: draft kind and how the fields were obtained
- `app/Review/Exceptions/InvalidReviewTransition.php`, `DraftNotPublishable.php`: domain exceptions
- `database/migrations/2026_10_17_100200_create_event_drafts_table.php`: drafts schema and guards
- `app/Models/EventDraft.php`: model with content-only `#[Fillable]`, enum casts and relations
- `app/Review/Data/DraftData.php`: draft DTO; `fromManualForm()` normalises through PublishableEventData
- `app/Review/DraftWriter.php`: the only writer of draft field data, with the `derive()` hook (preferred category plus reason)
- `app/Review/ReferenceCode.php`: unique `WOA-` references from an unambiguous alphabet
- `app/Publishing/Actions/ApproveDraft.php`: one-transaction approval
- `app/Publishing/Data/PublishableEventData.php`: adds `fromDraft()`
- `app/Filament/Resources/Events/Pages/CreateEvent.php`: manual entry now goes through draft plus ApproveDraft; blockers are shown as a warning and the save stops with Halt
- `tests/Feature/Review/ManualEntryThroughDraftTracerTest.php`: tracer plus hardening cases (7 tests)

## Decisions Made

- The approval log line is written through `DB::afterCommit`. When it is nested in CreateEvent's transaction, it is written only after the outermost commit.
- `fromDraft()` also blocks a missing start date, as well as the category, venue and repeat-rule blockers in the plan.
- No `EventDraftFactory` yet. Later plans own it.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing null check] Start-date blocker in `PublishableEventData::fromDraft()`**
- **Found during:** Task 1
- **Issue:** `event_drafts.start_date` is nullable (D-01), so `fromDraft()` would hit a null `format()` call on a draft with no date.
- **Fix:** It now throws `InvalidArgumentException('Add a start date.')`, which ApproveDraft turns into a `DraftNotPublishable` blocker.
- **Files modified:** app/Publishing/Data/PublishableEventData.php
- **Committed in:** 88189cc

**2. [Rule 1 - Correctness] The approval log fires after the outermost commit**
- **Found during:** Task 1
- **Issue:** The plan says "after commit". If the log ran straight after ApproveDraft's inner transaction, a nested approval (CreateEvent) could be logged and then rolled back.
- **Fix:** `DB::afterCommit(fn () => Log::info('draft approved', [ids]))`.
- **Files modified:** app/Publishing/Actions/ApproveDraft.php
- **Committed in:** 88189cc

### Other notes

- **Mass-assignment test wording:** The model's `$attributes` default `review_status` to `pending_review`, matching the migration as the plan requires. So the test asserts that `review_status` stays at that default (not `approved`), and that `reference` and `event_id` stay null.
- **Task 2 tests did not start RED:** Task 1 had already built the guards, so all the new cases passed on the first run. To check that they would catch a regression, I made two temporary changes: removed CreateEvent's outer transaction, and made `review_status` fillable. The rollback test and the mass-assignment test each failed, and I restored both files before committing.
- **Commits went to `main`:** The executor's protected-branch check flags `main`. I still committed there because the orchestrator explicitly asked for sequential mode on the main working tree, `git.branching_strategy` is `none`, and all of Phase 1 was committed on `main`. Nothing was pushed.

**Total deviations:** 2 auto-fixed (1 Rule 2, 1 Rule 1)
**Impact on plan:** Both are small correctness fixes, with no change in scope.

## Issues Encountered

None.

## Known Stubs

None. `DraftWriter::derive()` deliberately sets only the preferred category. The plan documents that 02-03 replaces it with CategoryAssigner and 02-08 adds scoring.

## Verification

- `php artisan migrate`, then the tracer test, CreateEventTest and WalkingSkeletonTest: 10 passed
- Full Unit+Feature suite: 523 passed
- Pint: passed. PHPStan (`--memory-limit=1G`): 0 errors
- `npm run build`, then `pest --testsuite=Browser tests/Browser/AdminCreateEventTest.php`: 2 passed
- Acceptance greps: `event_drafts_one_new_per_event` (1), `ApproveDraft` in CreateEvent (3), `lockForUpdate` in ApproveDraft (1). `create_event_drafts_table` shows as Ran.

## Self-Check: PASSED
