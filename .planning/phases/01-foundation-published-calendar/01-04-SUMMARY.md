---
phase: 01-foundation-published-calendar
plan: 04
subsystem: recurrence
status: complete
tags: [recurrence, rrule, sabre-vobject, dst, filament-5, scheduler, publishing-actions]

requires:
  - phase: 01-foundation-published-calendar
    provides: "01-01 walking skeleton: PublishEvent, PublishableEventData, EventTiming/OccurrenceTiming/OccurrenceGenerator (upsert preserving status), EventResource list+create, event_occurrences UNIQUE(event_id, recurrence_key)"
provides:
  - App\Enums\RepeatPattern (none, weekly, monthly_nth, monthly_last) and App\Enums\RepeatEnds (never, until, count)
  - App\Calendar\ManualRecurrence (toRrule / fromRrule / optionsFor / isValidFor / label / toFormState)
  - App\Calendar\RecurrenceExpander::instanceDates() as the only sabre/vobject expansion point (MAX_INSTANCES = 500)
  - OccurrenceGenerator recurring branch over the rolling [today 00:00, +90d) Europe/London window
  - App\Publishing\Actions\UpdatePublishedEvent as the edit choke point (admin check, row lock, one transaction)
  - Filament EditEvent page (/admin/events/{record}/edit), read-only OccurrencesRelationManager, Repeats/Ends/Next-dates form fields, Repeats column + edit action in EventsTable
  - Artisan calendar:sync-occurrences {--event=*} plus a daily 03:10 Europe/London schedule entry with withoutOverlapping
  - Stored format: events.recurrence_rule is a bare RRULE value (e.g. FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20270331T225959Z)
affects: [01-05 status actions, 01-08 event page / ics, 01-09 scheduler heartbeat, phase-2 approval path]

tech-stack:
  added: []
  patterns:
    - "Synthetic VEVENT (DTSTART;TZID=Europe/London or VALUE=DATE) plus sabre EventIterator built with an explicit DateTimeZone; recurrence_key = London Y-m-d of each instance"
    - "UNTIL is serialised in UTC (London 23:59:59) for timed series and as a DATE for all-day series; COUNT includes DTSTART"
    - "Edits and the daily sync both re-read the events row with lockForUpdate inside their transaction"
    - "Domain DTO validates recurrence (multi-day guard, pattern fits start date) independently of the form"

key-files:
  created:
    - app/Enums/RepeatPattern.php
    - app/Enums/RepeatEnds.php
    - app/Calendar/ManualRecurrence.php
    - app/Calendar/RecurrenceExpander.php
    - app/Publishing/Actions/UpdatePublishedEvent.php
    - app/Filament/Resources/Events/Pages/EditEvent.php
    - app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php
    - app/Console/Commands/SyncOccurrences.php
    - tests/Feature/Recurrence/RecurringSeriesTracerTest.php
    - tests/Feature/Recurrence/RecurringOccurrencesTest.php
    - tests/Unit/Calendar/ManualRecurrenceTest.php
    - tests/Unit/Calendar/RecurrenceExpanderTest.php
    - tests/Feature/Filament/CreateRecurringEventTest.php
    - tests/Feature/Filament/EditEventTest.php
    - tests/Feature/Publishing/UpdatePublishedEventTest.php
    - tests/Feature/Console/SyncOccurrencesCommandTest.php
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-04-task{2,3}-red{.json,-junit.xml}
  modified:
    - app/Calendar/EventTiming.php
    - app/Calendar/OccurrenceGenerator.php
    - app/Publishing/Data/PublishableEventData.php
    - app/Filament/Resources/Events/EventResource.php
    - app/Filament/Resources/Events/Schemas/EventForm.php
    - app/Filament/Resources/Events/Tables/EventsTable.php
    - routes/console.php

key-decisions:
  - "A rule whose UNTIL falls before DTSTART yields zero occurrences. sabre would still emit DTSTART, so RecurrenceExpander checks this first"
  - "RecurrenceExpander fast-forwards to window start minus one second and then filters with start >= windowStart. A timed VEVENT with no DTEND has zero duration, so an instance at exactly 00:00 would otherwise be skipped"
  - "ManualRecurrence's constructor enforces its own invariants: COUNT 1..200 and a Y-m-d until date for OnDate. PublishableEventData refuses a multi-day repeating event, or a pattern that doesn't fit the start date, even outside Filament"
  - "Repeats, Ends, Last date, Number of times and Next dates live in their own 'Repeats' form section, directly after 'When'"
  - "The events table 'Repeats' column shows only the pattern label (e.g. 'Monthly on the last Friday') or 'One-off'"

patterns-established:
  - "RED commits may carry behaviour-free interface scaffolds (enum types, no-op stubs) so RED failures are assertion failures rather than class-not-found errors"
  - "To prove transaction rollback without mocking final classes, force a real throw inside sync (horizon beyond MAX_INSTANCES)"

requirements-completed: [ADM-02, CAL-08, REC-01, REC-02]

coverage:
  - id: D1
    description: "Admin publishes a weekly series in Filament with origin manual, the admin as approver and FREQ=WEEKLY;BYDAY=FR; 13 UTC rows across 25 Oct 2026; one public entry per occurrence within 90 days"
    requirement: ADM-02
    verification:
      - kind: integration
        ref: "tests/Feature/Recurrence/RecurringSeriesTracerTest.php"
        status: pass
  - id: D2
    description: "Monthly nth/last weekday options, UNTIL/COUNT semantics, round-trip parsing and window adjacency (89 days in, 90 out)"
    requirement: ADM-02
    verification:
      - kind: unit
        ref: "tests/Unit/Calendar/ManualRecurrenceTest.php, tests/Unit/Calendar/RecurrenceExpanderTest.php"
        status: pass
  - id: D3
    description: "DST fixtures for recurring instances (25 Oct 2026, 28 Mar 2027, nonexistent 01:30, overnight, 25h/23h all-day, no-end +3h)"
    requirement: REC-02
    verification:
      - kind: integration
        ref: "tests/Feature/Recurrence/RecurringOccurrencesTest.php"
        status: pass
  - id: D4
    description: "Form rules: repeats re-validated on start date change, last date >= start, count 1..200, required Ends values, 10:00 BST stored verbatim, end date hidden while repeating, Next dates preview"
    requirement: ADM-02
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/CreateRecurringEventTest.php"
        status: pass
  - id: D5
    description: "Edits go through UpdatePublishedEvent: status survives a time-only edit, a weekday change prunes, an identical save is a no-op, publication fields never change, non-admin refused, rollback on sync failure"
    requirement: ADM-03
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/UpdatePublishedEventTest.php, tests/Feature/Filament/EditEventTest.php"
        status: pass
  - id: D6
    description: "calendar:sync-occurrences rolls the horizon forward (one new row, ids kept), preserves status, includes hidden events, prunes after UNTIL, is idempotent, isolates failures; scheduled 03:10 Europe/London withoutOverlapping"
    requirement: REC-01
    verification:
      - kind: integration
        ref: "tests/Feature/Console/SyncOccurrencesCommandTest.php"
        status: pass
  - id: D7
    description: "Manual local check: create 'Monthly on the last Friday, until 31 Mar 2027' in /admin, see the preview, save, see dates on / and in the Occurrences table, edit the time"
    requirement: ADM-02
    verification:
      - kind: manual_procedural
        ref: "Plan <verification> manual check: deferred to end-of-phase human verification (human_verify_mode: end-of-phase)"
        status: unknown

actuals:
  tokens: 179602   # chars/4 over the realized diff (718,406 chars). TDD evidence (JUnit XML embedded in the red.json records) is 627,927 chars; app/routes/tests code is 90,479 chars (about 22,620 tokens).
  tasks: 3
  commits: 5
plan_head_before: 7d13de9d32bd1a4918f2bae43d0108cacb3b5d83
plan_head_after: 8455b4c4fe2b92962ee81ddd210c8347bb9e1ac5

duration: 17min
completed: 2026-10-16
---

# Phase 1 Plan 04: Recurring Series and Edit Summary

**The admin can now publish and edit weekly, nth-weekday and last-weekday series in Filament, ending never, on a date or after N times. The rule is stored as an RRULE; sabre/vobject 5 alone expands it in Europe/London, and the occurrences are stored in UTC, keyed by London date. Edits go through `UpdatePublishedEvent` and a daily 03:10 `calendar:sync-occurrences` rolls the 90-day horizon forward. Admin-set occurrence statuses survive both.**

## Performance

- **Duration:** about 17 min
- **Started:** 2026-10-16T20:53Z
- **Completed:** 2026-10-16T21:10Z
- **Tasks:** 3 of 3
- **Files changed:** 27 (8 created in app/, 7 modified, 8 test files, 4 TDD evidence files)

## Accomplishments

- **Tracer (Task 1):** wired the weekly series end to end: Filament Repeats select → `PublishableEventData` → `ManualRecurrence::toRrule` → `PublishEvent` → `OccurrenceGenerator` recurring branch → `RecurrenceExpander` (sabre `EventIterator` with an explicit Europe/London zone) → `EventTiming`, anchored on the instance date → public list.
  - Result: 13 rows, with 23 Oct at 09:00Z and 30 Oct at 10:00Z.
- **Full vocabulary (Task 2):**
  - "Monthly on the {first..fourth} {Day}" (offered when n ≤ 4) and "Monthly on the last {Day}" (offered when start + 7 days falls in the next month).
  - UNTIL in UTC for timed series and as a DATE for all-day series. COUNT includes the first date.
  - Strict `fromRrule`.
  - A Next dates preview in UK format ("Thu 8 Oct, 10am", "Sun 25 Oct, all day").
  - The DTO refuses multi-day repeating events and patterns that don't fit the start date.
- **Edit and daily sync (Task 3):**
  - `UpdatePublishedEvent`: checks the admin, locks the row, fills only the allowlisted attributes, and re-syncs in one transaction.
  - `EditEvent` hydrates the form from the stored rule and delegates the save. It has no delete action.
  - Read-only Occurrences table, shown in London time and sorted by start.
  - Events table gains a Repeats column and an edit action.
  - `calendar:sync-occurrences` covers every recurring event, whatever its status, and isolates per-event failures (exit 1).
  - Schedule entry: daily 03:10 Europe/London, withoutOverlapping.

## Task Commits

1. **Task 1 (tracer): weekly series end to end:** `f8f12b0` (feat)
2. **Task 2 (TDD): full recurrence vocabulary**
   - RED: `6fd5b00` (test)
   - GREEN: `0458f15` (feat)
3. **Task 3 (TDD): edit + occurrences table + daily sync**
   - RED: `96c1814` (test)
   - GREEN: `8455b4c` (feat)

## TDD Gate Compliance

**Task 1 tracer gate.** The `<verify>` was automated and `human_verify_mode` is end-of-phase, so I re-ran it before expanding. It passed (13/13, including the 01-01 suites), so I continued with no checkpoint.

**Task 2 RED** (`6fd5b00`)
- 49 tests: 29 failed, 20 passed.
- Classifier verdict: `RED_EVIDENCE_OK` (`target_test_failed`). Record at `tdd-evidence/01-04-task2-red.json`, with the JUnit report beside it.
- **Target:** `RecurringOccurrencesTest#it yields exactly one occurrence for COUNT=1`. It failed on the stored rule assertion because `ManualRecurrence` didn't emit `;COUNT=` yet.
- **Semantic assessment:** every failure was intended missing behaviour:
  - The monthly patterns hit the existing "Unsupported repeat pattern" guard.
  - UNTIL wasn't emitted, and the `isValidFor` stub returned true.
  - The DTO had no guards, and the expander returned DTSTART when UNTIL preceded it.
  - The Ends fields and the preview didn't exist yet.
- No load, syntax or fixture faults. The 20 passing tests cover Task 1 behaviour.

**Task 2 GREEN** (`0458f15`): 49 of 49 pass.

**Task 3 RED** (`96c1814`)
- 21 tests: 18 failed, 3 passed.
- Classifier verdict: `RED_EVIDENCE_OK`.
- **Target:** `UpdatePublishedEventTest#it keeps every recurrence key, id and admin status when only the start time changes`. starts_at stayed at 10:00Z because the action scaffold was a no-op.
- All 18 failures were assertion failures.
- The 3 passing tests are negative guards (an identical save changes nothing, no delete action, no create/edit/delete actions on the occurrences table). A no-op scaffold satisfies them trivially.

**Task 3 GREEN** (`8455b4c`): 21 of 21 pass.

**REFACTOR:** not needed for either task.

## Verification

- `./vendor/bin/pest`: 103 passed (365 assertions), also under `TZ=America/New_York`. That's 29 from 01-01, the 4 tracer tests and 70 new ones from Tasks 2 and 3.
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse`: 0 errors (level 6).
- `php artisan schedule:list`: lists `calendar:sync-occurrences`. It shows as `10 2 * * *` because the display is in app-timezone UTC; the event's own expression is `10 3 * * *` with timezone Europe/London, which the test asserts.
- Acceptance greps all hold:
  - EventIterator is used in exactly one file.
  - No second RRULE library is in composer.json.
  - No `FilamentTimezone::set`.
  - No hand-rolled addWeek/addMonth.
  - `maxValue(200)` is present.
  - EditEvent has no DeleteAction and no update/save calls.
  - `lockForUpdate` is in both the action and the command.
  - `dailyAt('03:10')` and Europe/London are in routes/console.php.
  - No `Event::create` or `->update([` in app/Filament.

## Decisions Made

See `key-decisions` in the frontmatter. The two behavioural ones:
- UNTIL before the first date means the series has no occurrences.
- The window start is inclusive, even for an instance at exactly 00:00.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] sabre yields DTSTART even when UNTIL precedes it**
- **Found during:** Task 1, in a probe before Task 2
- **Issue:** `FREQ=WEEKLY;BYDAY=FR;UNTIL=20261001…` with DTSTART on 30 Oct still produced 2026-10-30. The plan requires "UNTIL before DTSTART returns []".
- **Fix:** `RecurrenceExpander::untilPrecedes()` parses UNTIL with sabre's `DateTimeParser` and returns an empty list. It's covered by a RED-first test.
- **Files modified:** app/Calendar/RecurrenceExpander.php
- **Commit:** 0458f15

**2. [Rule 1 - Bug] An instance at exactly window start would be skipped**
- **Found during:** Task 1
- **Issue:** sabre's `fastForward()` skips instances whose end is ≤ the given instant. A timed VEVENT without DTEND has zero duration, so a 00:00 instance on "today" would be dropped.
- **Fix:** fast-forward to window start minus one second, then keep only instances with `start >= windowStart`, as the plan requires.
- **Files modified:** app/Calendar/RecurrenceExpander.php
- **Commit:** f8f12b0

**3. [Rule 2 - Correctness/Security] ManualRecurrence enforces its own invariants**
- **Found during:** Task 2
- **Issue:** T-01-17/T-01-18 bound COUNT to 1..200 and require a valid UNTIL date. The form enforces this, but the Phase 2 approval path won't go through the form.
- **Fix:** the constructor throws InvalidArgumentException for an out-of-range COUNT or a missing or malformed until date.
- **Files modified:** app/Calendar/ManualRecurrence.php
- **Commit:** 0458f15

**4. [Rule 3 - Blocking] The "mocked generator" rollback test uses a real throw**
- **Found during:** Task 3
- **Issue:** `OccurrenceGenerator` is `final` (from 01-01), so Mockery can't mock it.
- **Fix:** the test raises `calendar.horizon_days` to 5000, so a weekly rule exceeds `RecurrenceExpander::MAX_INSTANCES` and sync throws a real RuntimeException inside the transaction. The event's title and start_time are then asserted unchanged. This proves the same guarantee without changing production code.
- **Files modified:** tests/Feature/Publishing/UpdatePublishedEventTest.php
- **Commit:** 96c1814

### Other adjustments

- **Larastan-driven shape:** `RecurrenceExpander` uses sabre's calendar+UID constructor mode (`new EventIterator($calendar, $uid, $tz)`) and adds DTSTART/RRULE through a `describe(mixed $vevent, …)` helper. sabre's Node classes are iterable without generics, which level 6 rejects in signatures. Behaviour is the same.
- **Form layout:** the plan placed Repeats in the "When" section. I put Repeats, Ends, Last date, Number of times and Next dates in their own "Repeats" section straight after "When", so the recurrence controls sit together. Field names and behaviour are exactly as planned.
- **Generators:** I wrote `EditEvent` and `OccurrencesRelationManager` by hand rather than with the `make:filament-*` generators, which are interactive in Filament 5. They follow the existing resource layout.
- **RED scaffolds:**
  - Task 2's RED commit included the `RepeatEnds` enum, the enum-typed `ends`/`repeatEnds` params and an always-true `isValidFor` stub.
  - Task 3's RED commit included a no-op action, command and edit handler, an empty relation manager and the edit route.
  - These keep RED failures assertion-based, and every stub was replaced in GREEN. A grep finds no remaining TODO or "RED scaffold" markers.
- **Command output:** when there are failures, the command prints an extra line, "Failed event ids: 1, 2".
- **Branch:** commits went to `main`, as the dispatch instructed (sequential, `branching_strategy: none`).
- **Untouched files:** `.planning/STATE.md`, `.planning/ROADMAP.md` and `.planning/REQUIREMENTS.md` were not edited; the orchestrator owns those writes. ADM-03 is only half delivered here (plan 01-05 does hide/unhide/cancel), so it's left out of `requirements-completed`.
- **Wave-2 boundary:** this plan did not touch `PublicOccurrenceQuery`, `CalendarController`, `LondonFormatter`, `config/calendar.php`, `tests/Pest.php` or `resources/views/`.

## Issues Encountered

- Untracked orchestrator files (`.planning/milestone.lock`, `.planning/state.json`, `.planning/phases/02-…/`) and a modified `.planning/config.json` were already in the tree. I left them alone.

## Known Stubs

None.

## User Setup Required

None. The plan's manual local check (create a last-Friday series in /admin, watch the preview, save, then edit the time) is left for end-of-phase human verification.

## Next Phase Readiness

- **Plan 01-05:** can add Hide/Unhide/Cancel/Reinstate header actions to `EditEvent::getHeaderActions()` (currently empty) and Cancel/Hide/Restore record actions to `OccurrencesRelationManager` (currently read-only via `isReadOnly()`). Those actions belong in `App\Publishing\Actions`, and the sync already preserves occurrence status.
- **Plan 01-08:** can read `events.recurrence_rule` as a bare RRULE and list upcoming dates from `event_occurrences`.
- **Plan 01-09:** can add heartbeat entries next to the `calendar:sync-occurrences` schedule line in routes/console.php.

## Self-Check: PASSED

- FOUND: app/Enums/RepeatPattern.php, app/Enums/RepeatEnds.php, app/Calendar/ManualRecurrence.php, app/Calendar/RecurrenceExpander.php, app/Publishing/Actions/UpdatePublishedEvent.php, app/Filament/Resources/Events/Pages/EditEvent.php, app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php, app/Console/Commands/SyncOccurrences.php, and all 8 test files
- FOUND commits: f8f12b0, 6fd5b00, 0458f15, 96c1814, 8455b4c (all ancestors of HEAD)
