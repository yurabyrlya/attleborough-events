---
phase: 01-foundation-published-calendar
plan: 15
subsystem: publishing
status: complete
gap_closure: true
tags: [gap-closure, wr-10, wr-11, recurrence, round-trip, mass-assignment, filament, ical-uid]
requires:
  - phase: 01-foundation-published-calendar
    provides: "01-13 ManualRecurrence::tryFromRrule, UpdatePublishedEvent::canEdit + domain refusal, EditEvent read-only fallback, OccurrenceUid stability; 01-12 AdminGate; 01-05 ChangeOccurrenceStatus"
provides:
  - "ManualRecurrence::tryFromStoredRule(?string, CarbonImmutable, bool): ?self. A stored rule counts as editable only if parse, isValidFor(start) and toRrule(start, all_day) give back the exact stored string (WR-10)"
  - "PublishableEventData::editableRecurrence(Event): ?ManualRecurrence. The one Event adapter behind canEdit(), fromEvent() and the events table label"
  - "UpdatePublishedEvent::canEdit() uses the round trip, so the read-only form, the Halt on a forced Livewire save and the domain refusal on the locked row all treat BYDAY=MO / 2TU / -1FR on a Friday start, and non-canonical UNTIL/COUNT encodings, as not editable"
  - "EventsTable Repeats label: 'Repeats' for a non-round-tripping rule, never the start date's weekday"
  - "EventOccurrence #[Fillable] without status (WR-11). status and its audit columns are never mass-assignable"
affects: [Phase 2/3 importers (rules they store in a form the app does not emit open read-only and stay hideable/cancellable), Phase 5 feeds (UIDs of such series cannot be wiped by an unrelated edit)]
actuals:
  tokens: 39900    # chars/4 over the realized 01-15 diff (159,421 added chars). TDD evidence JSON/JUnit is ~147,400 chars; code/tests/AGENTS.md are 12,029 chars (~3,000 tokens).
  tasks: 2
  commits: 4       # MEASURED: git rev-list --count plan_head_before..plan_head_after (the SUMMARY docs commit comes after this)
plan_head_before: 065012130f34a5429a794631846fdf7df356f621
plan_head_after: 14bc3f4287d7416f22c5fd944c901d89564881bf
tech-stack:
  added: []
  patterns:
    - "Editability is an exact round trip (parse -> fits start date -> rebuild === stored), not a shape match. One pure check in the value object, one Event adapter in the DTO, used by every consumer"
    - "Model #[Fillable] lists schedule columns only. Status columns are written through upsert (insert default), forceFill (the action) or forceCreate (test helper)"
    - "Filament 5 table column state is tested with assertTableColumnStateSet('col', $state, $record) (confirmed in vendor/filament/tables/src/Testing/TestsColumns.php)"
key-files:
  created:
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-15-task1-red.json
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-15-task1-red-junit.xml
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-15-task2-red.json
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-15-task2-red-junit.xml
  modified:
    - app/Calendar/ManualRecurrence.php
    - app/Publishing/Data/PublishableEventData.php
    - app/Publishing/Actions/UpdatePublishedEvent.php
    - app/Models/EventOccurrence.php
    - app/Filament/Resources/Events/Tables/EventsTable.php
    - tests/Unit/Calendar/ManualRecurrenceTest.php
    - tests/Feature/Filament/EditEventResilienceTest.php
    - tests/Feature/Publishing/ChangeOccurrenceStatusTest.php
    - AGENTS.md
decisions:
  - "01-15 (WR-10): an event's stored recurrence_rule is editable only if ManualRecurrence::tryFromStoredRule() rebuilds exactly the stored string for the event's own start date and all_day. Any other rule takes the 01-13 read-only/refused path (hide and cancel still work)"
  - "01-15 (WR-10): the pure check lives in ManualRecurrence. The Event adapter (PublishableEventData::editableRecurrence) is the single source for UpdatePublishedEvent::canEdit, PublishableEventData::fromEvent and EventsTable::repeatsLabel"
  - "01-15 (WR-11): EventOccurrence status, status_changed_at and status_changed_by are not mass-assignable. Status is set on insert by OccurrenceGenerator's upsert and changed only by ChangeOccurrenceStatus via forceFill"
metrics:
  duration: "~9 min"
  completed: 2026-10-17
requirements: [ADM-03, EVT-06, REC-01]
---

# Phase 1 Plan 15: Round-trip editability for stored RRULEs (WR-10) and non-fillable occurrence status (WR-11) Summary

**What changed:** a stored repeat rule now counts as editable only if rebuilding it for the event's own start date gives back the exact stored string. Before, a rule like `FREQ=WEEKLY;BYDAY=MO` on a Friday start opened as editable. Any save then rewrote it to `BYDAY=FR`, and sync deleted the old future dates, including admin cancellations and UIDs that residents had already imported. Such rules now open read-only and every save path refuses them. `EventOccurrence.status` can no longer be mass-assigned.

## Finding → fix → test

| Finding | Fix | Regression tests |
|---------|-----|------------------|
| **WR-10:** canEdit judged a stored RRULE by its shape only (tryFromRrule), so a BYDAY that disagrees with the start date, or a UNTIL/COUNT form the app never writes, opened as editable, and the next save rewrote the schedule and wiped occurrences | `ManualRecurrence::tryFromStoredRule()` (parse → `isValidFor(start)` → `toRrule(start, all_day) ===` stored). `PublishableEventData::editableRecurrence(Event)` is the adapter. `UpdatePublishedEvent::canEdit()` and `fromEvent()` use it. EditEvent needed no change: all three of its call sites already go through canEdit | `EditEventResilienceTest`: 5 new rows in the 'unsupported rules' dataset (BYDAY=MO, 2TU, -1FR, noon-UTC timed UNTIL, DATE UNTIL on a timed event), run by the read-only page, refused forced save and hide tests. A domain refusal test (MO, 2TU). A preservation test (MO, 2TU) that checks occurrence ids, keys, starts_at, the admin cancellation and its audit, and the `.ics` UID line, all unchanged after a forced page save and a direct action call. `ManualRecurrenceTest`: all 6 'emitted rules' accepted (GMT and 25 Oct 2026 DST-boundary UNTIL rows added), 7 rejected rules, null → "Does not repeat" |
| **WR-10 (display):** the events table labelled a BYDAY=MO rule "Weekly on Friday" | `EventsTable::repeatsLabel()` uses `editableRecurrence` and shows `Repeats` when the rule does not round-trip | `EditEventResilienceTest`: "labels Repeats in the events table…": MO rule → `Repeats`, app weekly series → `Weekly on Friday`, one-off → `One-off` |
| **WR-11:** `status` was in EventOccurrence's `#[Fillable]` | Removed. The list is exactly `event_id, recurrence_key, starts_at, ends_at, expires_at`. Docblock updated | `ChangeOccurrenceStatusTest`: "does not let occurrence status or its audit be mass-assigned": isFillable false for status and both audit columns, true for starts_at. A mass-assigned `cancelled` keeps `OccurrenceStatus::Scheduled` / raw `'scheduled'` |

## Placement decision

- **Pure round-trip check:** `ManualRecurrence::tryFromStoredRule()`. Unit-testable against the same 'emitted rules' dataset as toRrule/fromRrule, which proves every rule the app writes stays editable (T-01-89).
- **Event → start-date adapter:** `PublishableEventData::editableRecurrence()`. This class already maps Event ↔ form state, and it uses the `createFromFormat('!Y-m-d', …, $event->timezone)` idiom. No Carbon::parse.
- **Consumers:** `UpdatePublishedEvent::canEdit()` (used by EditEvent's mount/beforeValidate/handleRecordUpdate and by the domain refusal on the locked row), `PublishableEventData::fromEvent()`, and `EventsTable::repeatsLabel()`. All three use the one check, so the page, the domain and the list cannot disagree.
- `tryFromRrule()` and `fromRrule()` are unchanged.

## Occurrence writers confirmed (WR-11)

- `OccurrenceGenerator::sync()`: `EventOccurrence::upsert(...)` (bypasses fillable; status is in the insert payload and is not an update column).
- `ChangeOccurrenceStatus`: `$locked->forceFill([...])`.
- `tests/Pest.php` `makeOccurrence()`: `EventOccurrence::forceCreate([...])`.
- Grep gate: no app/ code calls `EventOccurrence::create/make` or `occurrences()->create/make/update`. Tests that write status raw use `DB::table(...)->update` (SyncOccurrencesCommandTest) or `makeOccurrence`. No writer needed changing.

## Task Commits

1. **Task 1 (tracer), RED:** `e743f64` test(01-15): add failing WR-10 round-trip repro tests
2. **Task 1, GREEN:** `38838ac` fix(01-15): treat a stored RRULE as editable only if it round-trips exactly (WR-10)
3. **Task 2, RED:** `9f9f83c` test(01-15): add failing WR-11 mass-assignment and WR-10 table label tests
4. **Task 2, GREEN:** `14bc3f4` fix(01-15): drop status from EventOccurrence fillable (WR-11); label rules via the round-trip check

Tracer feedback gate (interactive, `human_verify_mode` end-of-phase, automated-only verify): Task 1's verify command was re-run after GREEN (94 passed) before any Task 2 work.

## TDD Gate Compliance

- **Task 1 RED** (`tdd-evidence/01-15-task1-red.json`, classifier `RED_EVIDENCE_OK`). Target: `it keeps occurrences, an admin cancellation and the .ics UID when a save of a non-round-tripping rule is refused with data set "dataset "weekly Monday""`.
  - 71 tests ran: 43 passed, 14 failed on assertions, 14 errored.
  - The target passed its fixture guards (both keys generated, cancellation recorded, UID line correct) and then failed on `assertNotified`: the forced save of the MO/2TU rule was accepted. That is WR-10 reproduced.
  - The 5 new page rows failed on the missing warning. The domain rows failed with "InvalidArgumentException not thrown".
  - All 14 errors are `Call to undefined method ManualRecurrence::tryFromStoredRule()`, which is the planned new API.
  - The existing 3 unsupported rows, the positive control, and the toRrule/fromRrule cases for the two new emitted rows passed.
  - No syntax, load, DB or fixture faults.
- **Task 2 RED** (`tdd-evidence/01-15-task2-red.json`, `RED_EVIDENCE_OK`). Target: `it does not let occurrence status or its audit be mass-assigned`.
  - 42 tests ran: 40 passed, 2 failed on assertions, 0 errors.
  - The target failed on `isFillable('status')` returning true (WR-11).
  - The table-label test failed because the MO rule showed the start weekday instead of `Repeats`.
- **GREEN:** both fix commits follow their test commits, and the targeted verify commands pass (Task 1: 94 passed; Task 2: 66 passed).
- **REFACTOR:** none needed.

## Verification

- `./vendor/bin/pest`: **504 passed** (465 baseline + 39 new), 2,992 assertions.
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: 0 errors.
- Acceptance greps:
  - `function tryFromStoredRule` = 1;
  - `function editableRecurrence` = 1;
  - `editableRecurrence` appears in UpdatePublishedEvent and EventsTable;
  - the `#[Fillable]` line matches exactly;
  - `tryFromStoredRule` appears in AGENTS.md = 1;
  - `UNTIL=20270101T235959Z` = 1 and `UNTIL=20261025T235959Z` = 1 in ManualRecurrenceTest;
  - the app mass-assignment grep prints nothing.
- No change to the UID format, the schema or any URL. STATE.md and ROADMAP.md were not touched.

## Deviations from Plan

None. The plan was executed as written.

Notes:
- `ManualRecurrenceTest`'s rejection dataset holds `[rule, allDay]` pairs (all `allDay` false, as specified), so the timed/date UNTIL rows state their all_day explicitly.
- The one-off row in the table-label test reuses the `$this->stored` closure with `recurrence_rule => null`.
- The Filament table assertion API was confirmed from the installed vendor source (`TestsColumns::assertTableColumnStateSet`). The Boost `search-docs` tool is not available to this executor.

## Issues Encountered

- The full suite reports `"warnings":1` with an empty `warning_details`. It is **pre-existing**: running `tests/Feature` on a temporary checkout of the base commit `0650121` also shows `"warnings":1`. No file touched by this plan produces it on its own. The temporary worktree was removed afterwards. Not fixed (out of scope).
- No shared-DB "relation does not exist" flakes were seen.

## Known Stubs

None. (`EventsTable`'s `->placeholder('None upcoming')` is a Filament empty-state label, not a stub.)

## Threat Flags

None. No new endpoints, auth paths or schema. T-01-86..T-01-89 are mitigated as planned.

## Next Phase Readiness

WR-10 and WR-11 have passing regression tests with RED evidence. The orchestrator can mark them `fixed` in 01-REVIEW-DISPOSITION.md.

## Self-Check: PASSED

- FOUND: all 9 modified source/test/AGENTS.md files and the 4 tdd-evidence files (JUnit reports contain 14 and 2 `<failure` elements)
- FOUND commits: e743f64, 38838ac, 9f9f83c, 14bc3f4 (all ancestors of HEAD)
