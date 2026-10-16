---
phase: 01-foundation-published-calendar
plan: 05
subsystem: publishing-status
status: complete
tags: [status, state-machine, backed-enum, filament-5, audit, row-locks, cancelled-badge, adm-03, cal-07]

requires:
  - phase: 01-foundation-published-calendar
    provides: "01-01 enums/tables/panel gate; 01-04 UpdatePublishedEvent, OccurrenceGenerator upsert preserving status, calendar:sync-occurrences with events-row lock, EditEvent, read-only OccurrencesRelationManager; 01-06 PublicOccurrenceQuery::paginate, OccurrenceCardData::isCancelled badge, freezeLondon/makeOccurrence/cardsIn helpers"
provides:
  - "Migration 2026_10_16_000150_add_status_audit_columns: status_changed_at (timestamptz null) + status_changed_by (FK users, null on delete) on events and event_occurrences"
  - "EventStatus / OccurrenceStatus: transitions(), canTransitionTo(), assertCanTransitionTo(), publiclyListed(), isPubliclyListed()"
  - "App\\Publishing\\Exceptions\\InvalidStatusTransition (DomainException, public readonly from/to)"
  - "App\\Publishing\\Actions\\ChangeEventStatus::__invoke(Event, EventStatus, User): Event — the only events.status writer after publish"
  - "App\\Publishing\\Actions\\ChangeOccurrenceStatus::__invoke(EventOccurrence, OccurrenceStatus, User): EventOccurrence — the only event_occurrences.status writer after insert"
  - "App\\Filament\\Resources\\Events\\Actions\\StatusActions::forEvent() (hide / unhide / cancel / reinstate) and ::forOccurrence() (cancel / hide / restore)"
  - "Event::statusChangedBy() and EventOccurrence::statusChangedBy() relations; status_changed_at immutable_datetime casts"
  - "Status SelectFilter on the events list and on the Occurrences table"
affects: [01-08 event page (404 via EventStatus::isPubliclyListed), 01-10 JSON-LD eventStatus, phase-2 review actions, phase-4 cancellation drafts]

tech-stack:
  added: []
  patterns:
    - "Backed-enum state machine: transitions() list + canTransitionTo() for UI visibility + assertCanTransitionTo() inside the domain action on the freshly locked row"
    - "Lock order everywhere: events row FOR UPDATE first, then the occurrence row (same as UpdatePublishedEvent and calendar:sync-occurrences)"
    - "Audit columns written via forceFill only by the two status actions; never fillable"
    - "Filament action factory: visible() asks the enum, requiresConfirmation(), one-line closure delegating to a domain action, conflict exceptions turned into a danger notification"

key-files:
  created:
    - database/migrations/2026_10_16_000150_add_status_audit_columns.php
    - app/Publishing/Exceptions/InvalidStatusTransition.php
    - app/Publishing/Actions/ChangeOccurrenceStatus.php
    - app/Publishing/Actions/ChangeEventStatus.php
    - app/Filament/Resources/Events/Actions/StatusActions.php
    - tests/Feature/Status/CancelOccurrenceTracerTest.php
    - tests/Unit/Enums/StatusTransitionsTest.php
    - tests/Feature/Publishing/ChangeOccurrenceStatusTest.php
    - tests/Feature/Publishing/ChangeEventStatusTest.php
    - tests/Feature/Filament/StatusActionsTest.php
    - tests/Feature/Public/StatusVisibilityTest.php
    - tests/Feature/Status/StatusSurvivesRegenerationTest.php
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-05-task{2,3}-red{.json,-junit.xml}
  modified:
    - app/Enums/EventStatus.php
    - app/Enums/OccurrenceStatus.php
    - app/Models/Event.php
    - app/Models/EventOccurrence.php
    - app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php
    - app/Filament/Resources/Events/Pages/EditEvent.php
    - app/Filament/Resources/Events/Tables/EventsTable.php
    - app/Calendar/PublicOccurrenceQuery.php

key-decisions:
  - "No direct hidden <-> cancelled moves for events or occurrences (ARCHITECTURE Pattern 3); restore/reinstate first"
  - "ChangeEventStatus never touches occurrences or calls the generator; the public read combines event and occurrence status (hidden wins, then one Cancelled badge)"
  - "unhide and reinstate share the target Published; their visible() also checks the current status, the rule itself stays canTransitionTo()"
  - "After an event status action the page record is re-read (->after(refresh)), so the header immediately shows the actions for the new status"
  - "InvalidStatusTransition and ModelNotFoundException become a danger notification ('… changed in the meantime. Refresh the page and try again.'); AuthorizationException is rethrown"
  - "OccurrencesRelationManager keeps isReadOnly() = true: in Filament 5 that only denies the built-in Create/Edit/Delete/Associate… action classes, not custom Actions"

patterns-established:
  - "When Filament reports a missing table action as ActionNotResolvableException (even in assertActionExists), RED scaffolds register the action names as never-visible no-ops so RED failures stay assertion failures"
  - "Log assertions use Log::listen(MessageLogged) + expect(), so a missing entry is an assertion failure, not a Mockery count error"

requirements-completed: [ADM-03, CAL-07]

coverage:
  - id: D1
    description: "Tracer: admin cancels Fri 30 Oct in the Filament Occurrences table → ChangeOccurrenceStatus (audit) → only that card badged on / → survives calendar:sync-occurrences → listed at 12:59:59, gone at 13:00; repeat cancel refused"
    requirement: ADM-03
    verification:
      - kind: integration
        ref: "tests/Feature/Status/CancelOccurrenceTracerTest.php"
        status: pass
  - id: D2
    description: "9-pair transition matrices for both enums, refusal message/from/to, publiclyListed"
    requirement: ADM-03
    verification:
      - kind: unit
        ref: "tests/Unit/Enums/StatusTransitionsTest.php"
        status: pass
  - id: D3
    description: "ChangeEventStatus / ChangeOccurrenceStatus: audit write only, admin-only, stale-model refusal, pruned row → ModelNotFoundException, past dates, individual cancellations survive reinstate, ids-only log"
    requirement: ADM-03
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/ChangeEventStatusTest.php, tests/Feature/Publishing/ChangeOccurrenceStatusTest.php"
        status: pass
  - id: D4
    description: "EditEvent header actions and Occurrences record actions: visibility per status, confirmation, hidden action cannot run, order kept, status filter"
    requirement: ADM-03
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/StatusActionsTest.php"
        status: pass
  - id: D5
    description: "Hidden absent from / and every window/filter; cancelled badged exactly once until expiry; hidden wins; order unchanged"
    requirement: CAL-07
    verification:
      - kind: integration
        ref: "tests/Feature/Public/StatusVisibilityTest.php"
        status: pass
  - id: D6
    description: "Status + audit survive time-only edit, title edit and daily sync; hidden series still rolls forward; orphan rule pinned; no delete anywhere; events Status filter"
    requirement: ADM-03
    verification:
      - kind: integration
        ref: "tests/Feature/Status/StatusSurvivesRegenerationTest.php"
        status: pass
  - id: D7
    description: "Manual: in local /admin cancel one date, hide another, hide/show/cancel/reinstate the event, check / after each step; no delete button anywhere"
    requirement: ADM-03
    verification:
      - kind: manual_procedural
        ref: "Plan <verification> end-of-phase human check (human_verify_mode: end-of-phase)"
        status: unknown

actuals:
  tokens: 41524    # chars/4 over the 24 files this plan changed (166,097 chars). TDD evidence (JUnit XML embedded in red.json) is 85,077 chars; app/migration/tests are 81,020 chars (about 20,255 tokens).
  tasks: 3
  commits: 5       # MEASURED: git rev-list --count plan_head_before..plan_head_after
plan_head_before: 189a81816afefaa54ac2328190d5cc9ee2343196
plan_head_after: eee8e1dee0fcec1c47d849b13aa83409c27b3619

duration: 12min
completed: 2026-10-16
---

# Phase 1 Plan 05: Event and Occurrence Status Control Summary

**The admin can now hide, show, cancel and reinstate a whole event from its edit page, and cancel, hide or restore any single date from the Occurrences table. Every change goes through `ChangeEventStatus` or `ChangeOccurrenceStatus`. Each of these checks the admin, locks the rows (events row first) and enforces the backed-enum `canTransitionTo()` rules on the freshly locked status. Each one also records `status_changed_at` / `status_changed_by`. Hidden items vanish from every public window and filter. Cancelled items keep one text "Cancelled" badge until they expire. Statuses survive edits and the daily sync, and the admin has no way to hard delete anything.**

## Performance

- **Duration:** about 12 min
- **Started:** 2026-10-16T21:26:00Z
- **Completed:** 2026-10-16T21:38Z
- **Tasks:** 3 of 3
- **Files changed:** 24 (5 app files created, 8 modified, 7 test files, 4 TDD evidence files)

## Accomplishments

- **Tracer (Task 1):** wired occurrence scheduled → cancelled through every layer:
  - audit migration
  - `OccurrenceStatus` state machine
  - `ChangeOccurrenceStatus`, which locks the events row and then the occurrence row, asserts on the fresh status, uses forceFill for exactly 3 attributes and logs ids only
  - the `StatusActions` "Cancel this date" record action
  - the public card badge (01-06)
  - survival through the daily sync

  The end-to-end test publishes 'Repair Café' through the real CreateEvent form.
- **Full vocabulary (Task 2):**
  - The `EventStatus` machine: published ↔ hidden and published ↔ cancelled.
  - `ChangeEventStatus`, which never touches occurrences.
  - Four confirmed header actions on `EditEvent`: Hide from calendar, Show on calendar, Mark as cancelled, Reinstate.
  - Cancel, Hide and Restore on each Occurrences row.
  - A status filter on the Occurrences table.
- **Public effect and survival (Task 3):**
  - `PublicOccurrenceQuery` now takes its base status constraints from `publiclyListed()`. Behaviour is unchanged.
  - The events list has a Status filter.
  - New tests cover:
    - visibility across `/`, today, weekend, week, category, free and family
    - one badge per card
    - the all-day midnight expiry
    - hidden wins over cancelled
    - order kept as starts_at then id
    - survival through a time-only edit, a title edit and the sync
    - the orphan rule
    - no delete action anywhere

## Task Commits

1. **Task 1 (tracer): cancel one date end to end:** `e7f83be` (feat)
2. **Task 2 (TDD): full status vocabulary**
   - RED: `37ac1d1` (test)
   - GREEN: `3614e80` (feat)
3. **Task 3 (TDD): public effect, survival, no delete, events Status filter**
   - RED: `c78592b` (test)
   - GREEN: `eee8e1d` (feat)

## TDD Gate Compliance

**Task 1 tracer gate.** The `<verify>` is automated and `human_verify_mode` is end-of-phase, so I re-ran it before expanding.
- It passed 18/18: the 5 tracer tests plus the 01-04 EditEventTest and SyncOccurrencesCommandTest. I continued with no checkpoint.
- I wrote the tracer test before the implementation but did not record a failing run for it. Tracer tasks don't require RED evidence.

**Task 2 RED** (`37ac1d1`)
- 45 tests: 20 failed, 25 passed, 0 errors.
- Classifier verdict: `RED_EVIDENCE_OK` (`target_test_failed`). Record at `tdd-evidence/01-05-task2-red.json`, with the JUnit report beside it.
- **Target:** `ChangeEventStatusTest#it hides a published event, records who and when, and changes nothing else`. The status was still published because the scaffold action was a no-op.
- **Semantic assessment:** all failures were assertion failures for missing behaviour:
  - the event transition matrix and publiclyListed
  - the admin check, fresh-status assert and log
  - the header actions
  - occurrence hide/restore
  - the status filter
- **What passed:** the 25 passing tests are Task 1 behaviour (the whole OccurrenceStatus matrix and `ChangeOccurrenceStatus`), the refused event pairs and two negative guards.
- **Earlier RED runs:** before the recorded run, I fixed three test-harness issues so failures would be assertions rather than errors:
  - Filament's `callAction` asserts visibility first.
  - Missing table actions raise `ActionNotResolvableException`.
  - A Mockery count error was reported as an error, not a failure.

**Task 2 GREEN** (`3614e80`): 45 of 45 pass, plus the tracer and EditEventTest (57 in total).

**Task 3 RED** (`c78592b`)
- 16 tests: 1 failed, 15 passed, 0 errors.
- Classifier verdict: `RED_EVIDENCE_OK`.
- **Target:** `StatusSurvivesRegenerationTest#it filters the events list by status…`. It failed because no `status` filter existed on ListEvents.
- **Why 15 passed:** this is what the plan expects. It says Task 3 needs no new public behaviour: 01-06 already excludes hidden rows and badges cancelled ones, and 01-04's upsert already preserves status. These tests prove that behaviour through the real Tasks 1–2 actions and pin the behaviour-preserving query refactor.
- **Test bug fixed before the recorded run:** one test failed because of the test itself. The Picnic event's Sunday card kept the title visible after Saturday was hidden. The test now asserts on the Saturday card's label.

**Task 3 GREEN** (`eee8e1d`): 16 of 16 pass.

**REFACTOR:** not needed for either task.

## Verification

- `./vendor/bin/pest`: 265 passed (1253 assertions), up from a baseline of 199. Also 265/265 under `TZ=America/New_York`.
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse`: 0 errors.
- The migration is reversible:
  - on the dev DB, `migrate:rollback --step=1` then `migrate`
  - on `attleborough_testing`, `migrate:fresh --seed`, then rollback and migrate
- Acceptance greps all hold:
  - `status_changed_by` ≥2 in the migration
  - `lockForUpdate` 2 in ChangeOccurrenceStatus and 1 in ChangeEventStatus
  - `assertCanTransitionTo` 1 in each action
  - `canTransitionTo` in both enums and in StatusActions
  - `ChangeOccurrenceStatus::class` / `ChangeEventStatus::class` in StatusActions
  - `requiresConfirmation` 2
  - "until its dates have passed" 1
  - `StatusActions::forEvent` / `forOccurrence` are wired
  - no `update/forceFill/save` in StatusActions
  - no `'status' =>` in app/Filament
  - no `->delete(` in the status actions
  - no Delete, DeleteBulk or ForceDelete action in app/Filament
  - `publiclyListed` 3 in the query
  - the status SelectFilter appears 1 time in EventsTable
  - `status_changed_by` appears only in the two actions and two models
  - "Cancelled" ≥4 in StatusVisibilityTest

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

None. No production bugs were found in code from earlier plans.

### Other adjustments

- **Boost search-docs:** the Boost MCP tool isn't available to this executor. I checked the Filament 5 action, notification and testing APIs against the vendored source instead:
  - `InteractsWithActions::mountAction`: hidden or disabled actions are unmounted and never run.
  - `CanBeDisabled::isDisabled()` includes `isHidden()`.
  - `TestsNotifications::assertNotified`.
  - `RelationManager::isReadOnly()` only denies built-in action classes.

  I used a manual danger Notification rather than a built-in failure mechanism, as the plan allowed.
- **Record refresh:**
  - **Plan:** step 5 suggested `refreshFormData([])` or re-fetching `$this->record` in EditEvent.
  - **What I did:** the event actions use `->after(fn (Event $record) => $record->refresh())` in StatusActions instead, which re-reads the page's record. EditEvent itself only returns `StatusActions::forEvent()`.
  - **Test:** "hide then unhide" and "cancel then reinstate" run on the same Livewire instance.
- **`run()` helper signature:** it takes a third argument, the conflict notification title, so event and occurrence actions can say "This event…" or "This date…" as the plan's copy requires.
- **Log assertions:** I used `Log::listen(MessageLogged)` instead of `Log::spy()`. A missing entry is then an assertion failure rather than a Mockery error. The check is the same: one info entry whose context is exactly ids, from, to and user_id.
- **Hidden-action test:** this is a crafted Livewire call, `mountAction('reinstate')` then `callMountedAction`, after asserting the action exists but is hidden. Filament's `callAction` helper asserts visibility first, so it can't express this case.
- **Test placement:** the no-delete and events Status filter tests live in `StatusSurvivesRegenerationTest.php`. The plan listed only that file and `StatusVisibilityTest.php` for Task 3, and these are admin-side guarantees.
- **Fixture times:** the ordering test uses two same-time events at Sat 31 Oct 11:00. The shared fixture already occupies 10:00, and 11:00 keeps "lower id then higher id" unambiguous.
- **migrate:fresh:** I ran `migrate:fresh --seed` against `attleborough_testing` rather than the dev DB, so local dev data the owner may use for the manual check is kept.
- **RED scaffolds:** the RED commits carried interface-only scaffolds:
  - `EventStatus` methods returning `[]`
  - a no-op `ChangeEventStatus`
  - an empty `forEvent()`
  - never-visible `hide` and `restore` names
  - the `status_changed_at` cast on Event

  GREEN replaced all of them. A grep finds no remaining "RED scaffold" markers.
- **Branch:** commits went to `main`, as the dispatch instructed (sequential, `branching_strategy: none`).
- **Untouched files:**
  - I did not edit or stage `.planning/STATE.md`, `.planning/ROADMAP.md`, `.planning/REQUIREMENTS.md`, `.planning/config.json`, `.planning/state.json`, `.planning/milestone.lock` or `.planning/phases/02-*`.
  - Nothing under `resources/`, `routes/console.php`, `AdminPanelProvider` or `tests/Pest.php` was touched.
  - The tests assert the badge by its text "Cancelled", never by CSS class.

## Issues Encountered

- Two parallel tool calls (rm + create) raced while I was replacing the `ChangeEventStatus` scaffold. I noticed and re-created the file, and the final content is as committed.
- The orchestrator files (`.planning/config.json` modified, plus the untracked `milestone.lock` and `state.json`) were already in the tree. I left them alone.

## Known Stubs

None.

## Threat Flags

None. The new surface (Filament status actions and the audit columns) is covered by T-01-30..T-01-36, and each mitigation has a test.

## User Setup Required

None. The plan's manual check (cancel and hide dates, then hide, show, cancel and reinstate the event in local /admin and watch `/`) is left for end-of-phase human verification.

## Next Phase Readiness

- **Plan 01-08:** return 404 on the event page when `! $event->status->isPubliclyListed()`. Upcoming dates can be listed through the same publiclyListed constraints.
- **Plan 01-10:** map `EventStatus::Cancelled`, or a cancelled occurrence, to JSON-LD `eventStatus` EventCancelled.
- **Phase 2 / Phase 4:** review and cancellation flows call `ChangeEventStatus` / `ChangeOccurrenceStatus` and handle `InvalidStatusTransition`. They never write status directly.

## Self-Check: PASSED

- FOUND: database/migrations/2026_10_16_000150_add_status_audit_columns.php, app/Publishing/Exceptions/InvalidStatusTransition.php, app/Publishing/Actions/ChangeOccurrenceStatus.php, app/Publishing/Actions/ChangeEventStatus.php, app/Filament/Resources/Events/Actions/StatusActions.php, all 7 test files and the 4 TDD evidence files
- FOUND commits: e7f83be, 37ac1d1, 3614e80, c78592b, eee8e1d (all ancestors of HEAD)
