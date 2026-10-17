---
phase: 01-foundation-published-calendar
plan: 13
subsystem: publishing
status: complete
gap_closure: true
tags: [gap-closure, wr-06, wr-09, ical-uid, recurrence, filament, resilience]
requires:
  - phase: 01-foundation-published-calendar
    provides: "01-04/01-08 UpdatePublishedEvent + EditEvent, 01-05 StatusActions/ChangeEventStatus, 01-08 OccurrenceUid + .ics route, 01-12 AdminGate, validated PublishableEventData and strict ManualRecurrence UNTIL"
provides:
  - "UpdatePublishedEvent refuses one-off <-> repeating switches on the locked row before any write (WR-06), so OccurrenceUid's 'never change' contract holds"
  - "EventForm edit page mirrors the guard: a one-off event's Repeats offers only 'Does not repeat' (disabled); a series' Repeats drops it; helper text explains the workaround and its side effects"
  - "ManualRecurrence::tryFromRrule(): ?self for display paths; fromRrule stays strict"
  - "PublishableEventData::fromEvent falls back to display-only 'Does not repeat' state for unsupported stored rules"
  - "EditEvent read-only fallback: persistent warning, disabled form, Cancel-only form actions, forced save refused (warning + Halt)"
  - "UpdatePublishedEvent::canEdit() + domain refusal of edits to events whose stored rule the form cannot represent (WR-09 backstop)"
  - "EventsTable record actions: Hide / Show / Cancel / Reinstate (StatusActions::forEvent())"
affects: [Phase 3 source-update drafts (a one-off -> series change must create a new event or come with a UID migration plan), Phase 5 feeds (inherit the stable UID scheme unchanged), any importer that stores RRULEs the manual form cannot represent (events stay hideable/cancellable)]
actuals:
  tokens: 55800    # chars/4 over the realized 01-13 diff (223,257 added chars). TDD evidence JSON/JUnit is ~199,300 chars; code/tests/AGENTS.md are 23,996 chars (~6,000 tokens).
  tasks: 2
  commits: 7       # MEASURED: git rev-list --count plan_head_before..plan_head_after. 4 are 01-13 commits; 3 (15d2172, b7e8527, f3a0f73) are a concurrent quick task (quick-261017-e46) that committed on main during this run.
plan_head_before: b52a9289a02c8b9a09e37913dca0856194ece639
plan_head_after: 94b5e952e3c74d6e4d82929593eddc977f0c2c01
tech-stack:
  added: []
  patterns:
    - "Domain invariants that protect a public contract live in the write action, checked on the lockForUpdate row before fill/save/sync; the Filament form only mirrors them"
    - "Filament field closures inject `string $operation` and `mixed $record` to vary edit-page options from create-page options"
    - "Strict parser + try* wrapper: fromRrule() throws for write paths, tryFromRrule() returns null for display paths"
    - "Read-only edit fallback: #[Locked] display flag, form()->disabled(), getFormActions() Cancel-only, and a server-side refusal (beforeValidate + handleRecordUpdate -> Notification + Halt) that re-checks the record, not the flag"
    - "Form actions are tested with TestAction::make('save')->schemaComponent('form-actions', 'content')"
key-files:
  created:
    - tests/Feature/Publishing/OccurrenceUidStabilityTest.php
    - tests/Feature/Filament/EditRepeatTypeTest.php
    - tests/Feature/Filament/EditEventResilienceTest.php
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-13-task1-red.json
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-13-task1-red-junit.xml
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-13-task2-red.json
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-13-task2-red-junit.xml
  modified:
    - app/Publishing/Actions/UpdatePublishedEvent.php
    - app/Filament/Resources/Events/Schemas/EventForm.php
    - app/Calendar/Export/OccurrenceUid.php
    - app/Calendar/ManualRecurrence.php
    - app/Publishing/Data/PublishableEventData.php
    - app/Filament/Resources/Events/Pages/EditEvent.php
    - app/Filament/Resources/Events/Tables/EventsTable.php
    - tests/Feature/Publishing/UpdatePublishedEventTest.php
    - AGENTS.md
    - .planning/phases/01-foundation-published-calendar/deferred-items.md
decisions:
  - "01-13 (WR-06): keep the UID scheme ({id}@domain one-off, {id}-{key}@domain series) and block one-off <-> repeating switches in UpdatePublishedEvent and the edit form. Rejected: always {id}-{key} (changes every one-off's published UID, and a moved one-off date would get a new UID); a stored per-occurrence UID column (migration + backfill for no extra benefit). Workaround for a type change: hide + create a new event (hidden URL 404s; new event gets a suffixed slug)"
  - "01-13 (WR-09): an event whose stored rule ManualRecurrence cannot represent opens read-only with a persistent warning and cannot be saved; UpdatePublishedEvent refuses such edits as the domain backstop; Hide/Show/Cancel/Reinstate are also events-table record actions"
metrics:
  duration: "~10 min"
  completed: 2026-10-17
requirements: [ADM-03, EVT-06]
---

# Phase 1 Plan 13: Stable occurrence UIDs and an edit page that survives any repeat rule Summary

An occurrence's iCalendar UID can no longer change. `UpdatePublishedEvent` refuses to switch an event between one-off and repeating, and the edit form offers only the options that guard allows. An event whose stored repeat rule the form can't represent (for example `FREQ=DAILY`, `COUNT=1000` or an impossible `UNTIL`) now opens read-only with a persistent warning. It can't be saved, by the page or by the action. It can still be hidden or cancelled, from the edit page header or from the events table. Suite: 465 passed (440 baseline + 22 new from this plan + 3 from a concurrent quick task). Pint and Larastan are clean.

## Tasks

| # | Task | Commits | Files |
|---|------|---------|-------|
| 1 | Tracer: UID stays the same through allowed edits; one-off ↔ repeating switch refused (UpdatePublishedEvent guard → EventForm edit options → .ics over HTTP) | 530a1dd (RED), 3e14d97 (GREEN) | UpdatePublishedEvent, EventForm, OccurrenceUid, OccurrenceUidStabilityTest (new), EditRepeatTypeTest (new), UpdatePublishedEventTest |
| 2 | The admin can always open, hide or cancel an event, whatever its repeat rule (WR-09) | f5423f0 (RED), 94b5e95 (GREEN) | ManualRecurrence, PublishableEventData, EditEvent, EventsTable, UpdatePublishedEvent, AGENTS.md, EditEventResilienceTest (new) |

Tracer gate after Task 1: I re-ran `<verify>` end to end after the GREEN commit (OccurrenceUidStabilityTest, EditRepeatTypeTest, EditEventTest, UpdatePublishedEventTest and EventPageTracerTest, then the full suite: 451 passed). Task 2 started only after that passed.

## WR-06 decision and its side effects for the owner

**Decision: fix by blocking the switch. The UID scheme is unchanged.** One-off events keep `{event_id}@whatsonattleborough.co.uk` and series dates keep `{event_id}-{Y-m-d}@whatsonattleborough.co.uk`, byte-identical to before. EventPageTracerTest's UID assertions pass unmodified. A one-off event whose date moves still updates the same calendar entry.

Rejected alternatives:
- **Always use `{event_id}-{recurrence_key}`.** This changes the published UID format for every one-off event, and a moved one-off date would then get a new UID, so residents' calendars would end up with duplicates.
- **Store a UID per occurrence row.** This needs a schema migration and a backfill, and gives no benefit over the guard.

**What the admin does instead: hide the event and create a new one.** The form's helper text tells them this. Two side effects to know about, both stated in the helper text:
1. **The hidden event's page stops working.** `/events/{slug}` and its `.ics` links return 404 while the event is hidden.
2. **The new event gets its own web address.** The hidden event keeps its slug, so EventSlugger gives the new event a suffixed slug, such as `quiz-night-2`.

The guard is rated `costly` to reverse. If a later phase (for example Phase 3 source-update drafts) needs to turn a one-off event into a series, it must come with a UID migration plan. The OccurrenceUid docblock and the AGENTS.md Writes bullet both say this.

## Finding → fix → test

| Finding | Fix | Regression test |
|---------|-----|-----------------|
| **WR-06**: switching one-off ↔ repeating changed every occurrence's UID (`{id}@` ↔ `{id}-{key}@`), which broke OccurrenceUid's "never change" contract and would create duplicate or unmatched entries in residents' calendars | `UpdatePublishedEvent`: after the row lock and before fill/save/sync, `($locked->recurrence_rule === null) === $data->isRecurring()` throws `InvalidArgumentException("An event can't switch between one-off and repeating. Hide it and create a new event instead.")`. The transaction rolls back and nothing is written. Switching between repeating patterns, and editing times, titles and end conditions, still works. | `OccurrenceUidStabilityTest` (5 cases, UID lines from real `GET /events/{slug}/{date}.ics` before and after): a one-off start-time edit keeps the UID; one-off → weekly throws, the rule stays null, the occurrence count and UID are unchanged; a series start-time edit keeps `UID:{id}-2026-10-30@…`; series → none throws, the rule and UID are unchanged; weekly → monthly 4th Friday is allowed (`FREQ=MONTHLY;BYDAY=4FR`). |
| **WR-06 (form)** | `EventForm` Repeats Select injects `$operation`/`$record`. On the edit page, a one-off event gets only `['none' => 'Does not repeat']`, the field is disabled, and the helper text reads "To make this repeat, hide it and create a new repeating event. The hidden event's page will stop working and the new event gets its own web address." A series gets `optionsFor(start)` without None and the helper text "To stop it repeating, set a last date or hide it." The create page is unchanged. | `EditRepeatTypeTest`: one-off options exactly None, field disabled, helper copy rendered; series has no None but has weekly, with the helper copy; the create page offers None and weekly, the field is enabled, and there is no edit helper copy. |
| **WR-09**: the edit page threw a 500 on any stored rule `fromRrule` rejects, which hid the Hide/Cancel header actions | `ManualRecurrence::tryFromRrule()` returns null instead of throwing (it catches `InvalidArgumentException` only). `PublishableEventData::fromEvent()` falls back to the None/Never form state, documented as display-only. `EditEvent`: a `#[Locked] $recurrenceEditable` flag is set in `mutateFormDataBeforeFill`; when false it sends a persistent warning ("This event's repeat rule can't be edited here. You can still hide or cancel it."), disables the whole form, and `getFormActions()` returns only Cancel. `beforeValidate()` and `handleRecordUpdate()` re-check the record itself (not the client-visible flag) and send the warning and throw `Halt`. The header status actions are unchanged. There is still no delete action. | `EditEventResilienceTest` with `FREQ=DAILY`, `FREQ=WEEKLY;BYDAY=FR;COUNT=1000` and `FREQ=WEEKLY;BYDAY=FR;UNTIL=20261345`: the page renders and warns, with no Save action and title/repeats disabled; a forced `save` with a forged title and repeats=none leaves title and rule unchanged in the DB; header `hide` sets status hidden with `status_changed_by` = admin. A supported weekly series still hydrates Repeats, shows Save and sends no warning. |
| **WR-09 (domain)** | `UpdatePublishedEvent::canEdit(Event)` is true for a null rule or one that `tryFromRrule` can represent. The action checks it on the locked row before the WR-06 guard and throws `InvalidArgumentException(UpdatePublishedEvent::UNSUPPORTED_RULE_REFUSED)`. EditEvent reuses `canEdit()` and the same message constant. | `EditEventResilienceTest`: calling `UpdatePublishedEvent` directly on the `FREQ=DAILY` event with valid weekly-Friday data throws "repeat rule can't be edited here", and title and rule are unchanged. The same call on a supported weekly series succeeds. |
| **WR-09 (table)** | `EventsTable::recordActions` spreads `...StatusActions::forEvent()` beside `EditAction`. These are the same definitions, confirmations, `canTransitionTo` visibility and `ChangeEventStatus` → `AdminGate` path as the edit page. There is still no delete, bulk delete or force delete. `StatusActions` needed no change: its `Event $record` closures resolve in table context. | `EditEventResilienceTest`: table `hide` and `cancel` work on unsupported-rule events (status and `status_changed_by` checked); `unhide` is visible only for a hidden record and `hide` only for a published one; a non-admin gets 403 on `/admin/events`. |
| **Rules** | AGENTS.md "Writes" now has one new bullet covering both `UpdatePublishedEvent` guards. Nothing else in AGENTS.md changed. | `grep -c UpdatePublishedEvent AGENTS.md` → 2 (the new bullet plus the existing Panel access line). |

## TDD Gate Compliance

- **Task 1 RED** (`tdd-evidence/01-13-task1-red.json`, classifier `RED_EVIDENCE_OK`, target `it refuses to turn a one-off event into a repeating one and changes nothing`): 8 tests ran, 4 failed on assertions, 0 errors. Both switch directions succeeded instead of throwing (WR-06 reproduced). The edit-page Repeats field offered the wrong options and had no workaround text. Semantic assessment: every failure came from the missing behaviour. The allowed-edit UID cases, the pattern-to-pattern switch and the create-page case already passed, as intended, because they cover behaviour to keep.
- **Task 2 RED** (`tdd-evidence/01-13-task2-red.json`, `RED_EVIDENCE_OK`, target `it refuses an edit in UpdatePublishedEvent itself when the stored rule is unsupported`): 14 tests ran, 2 passed, 1 failed on an assertion, 11 errors. The target failed on its assertion: the edit overwrote `FREQ=DAILY`. The 9 edit-page errors are `InvalidArgumentException: Unsupported recurrence rule` / `Invalid UNTIL` thrown during render. That is the WR-09 defect itself, as the plan predicted. The 2 table cases errored with "Action [hide|unhide] not found on table". The supported-series positive control and the non-admin 403 case passed. There were no syntax, load, DB or fixture faults.
- **GREEN**: 3e14d97 and 94b5e95. No refactor commit was needed.

## Verification

- `./vendor/bin/pest`: **465 passed** (baseline 440; +22 from 01-13; +3 from the concurrent quick task's AdminThemeTest).
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: 0 errors.
- Acceptance greps: "can't switch between one-off and repeating" appears once in UpdatePublishedEvent. `tryFromRrule` appears 1 / 1 / 2 times in ManualRecurrence / PublishableEventData / UpdatePublishedEvent. `StatusActions::forEvent` appears once in EventsTable.
- EventPageTracerTest's UID assertions pass unmodified (no change to the UID format).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] An existing 01-04 test asserted the behaviour WR-06 removes**
- **Found during:** Task 1 GREEN
- **Issue:** `UpdatePublishedEventTest` "leaves exactly one future occurrence keyed by start_date when a series becomes 'Does not repeat'" expected the series → one-off switch to succeed. That switch is exactly what the plan's WR-06 must-have forbids, so the test contradicted the plan's decision. It was not a fixture problem.
- **Fix:** I rewrote it as "refuses to make a series 'Does not repeat' and leaves its occurrences as they were". It asserts the `InvalidArgumentException`, the unchanged `FREQ=WEEKLY;BYDAY=FR` rule, and an identical occurrence snapshot (ids, instants, statuses). The OccurrenceGenerator deletion of rows a rule no longer produces is still covered by the other rule-change tests (end conditions, pattern switches).
- **Files modified:** tests/Feature/Publishing/UpdatePublishedEventTest.php
- **Commit:** 3e14d97

### Process notes

- **Commits on `main`.** `git.base-branch --is-protected main` reports true. The orchestrator stated that `branching_strategy: none` makes committing on `main` intended, as for 01-12. I did not push.
- **Concurrent session.** A quick task (`quick-261017-e46`) committed three times on `main` during this run and ran its own Pest suite against the shared `attleborough_testing` DB. Two of my full-suite runs hit random `relation "…" does not exist` errors in unrelated Public tests while that run rebuilt the schema. Once it was quiet the suite passed (451, then 465). I staged only 01-13 files. The measured `commits: 7` includes its 3 commits. This is logged in deferred-items.md.
- **Form-action test idiom.** In Filament 5, `assertActionExists('save')` can't find form actions. I used `TestAction::make('save')->schemaComponent('form-actions', 'content')` and proved the lookup with the supported-series positive control.

## Deferred Issues

Logged in `deferred-items.md`:
- `.ai/guidelines/attleborough.md` (the Boost source for AGENTS.md) has neither the 01-12 AdminGate line nor this plan's Writes bullet. A Boost regenerate would drop both. This drift started before 01-13, and the file is outside `files_modified`.
- Parallel sessions share the testing DB (see the process notes).

## Known Stubs

None. (`placeholder` matches in EventForm are the existing UI placeholders "No upcoming dates" and "e.g. £5, under-12s free", not stubs.)

## Threat Flags

None. The only new surface is the events-table record actions (T-01-83). They reuse StatusActions → ChangeEventStatus → AdminGate, and a test checks that a non-admin gets 403 on the list. T-01-80…T-01-83 are mitigated as planned. No packages were added (T-01-SC).

## Self-Check: PASSED

- FOUND: OccurrenceUidStabilityTest, EditRepeatTypeTest, EditEventResilienceTest, the 4 tdd-evidence files, 01-13-SUMMARY.md, all modified files
- FOUND: 530a1dd, 3e14d97, f5423f0, 94b5e95 (ancestors of HEAD)
- Verified side-effect claims: EventController aborts 404 for a non-publicly-listed (hidden) event; EventSlugger picks base-2, base-3 … when the base slug is taken
