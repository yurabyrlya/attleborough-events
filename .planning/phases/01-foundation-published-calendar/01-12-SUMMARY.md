---
phase: 01-foundation-published-calendar
plan: 12
subsystem: publishing
status: complete
gap_closure: true
tags: [gap-closure, wr-01, wr-02, wr-03, wr-07, authorization, mass-assignment, validation, recurrence]
requires:
  - phase: 01-foundation-published-calendar
    provides: "01-04 PublishEvent/PublishableEventData, 01-05 status actions, 01-08 UpdatePublishedEvent edit path, 01-14 suite aligned with the DTO invariants"
provides:
  - "App\\Publishing\\AdminGate: the single case-insensitive ADMIN_EMAIL rule (allows/authorize), used by PublishEvent, UpdatePublishedEvent, ChangeEventStatus, ChangeOccurrenceStatus and User::canAccessPanel"
  - "PublishEvent refuses a non-admin approver before the transaction (no rows written)"
  - "Event publishing state (status, origin, approved_by, approved_at) is not mass-assignable; PublishEvent forceFills it"
  - "PublishableEventData constructor enforces the form's rules for the non-Filament path"
  - "ManualRecurrence round-trip validates until dates (constructor, untilValue, stored UNTIL)"
affects: [01-13 (builds on PublishableEventData and UpdatePublishedEvent; the edit page must survive fromRrule rejecting a stored impossible UNTIL), Phase 2 approval path (inherits the gated, validated write path)]
actuals:
  tokens: 19700    # chars/4 over the realized diff db4d959..566dafd (78,852 added chars). TDD evidence JSON/JUnit is ~63,800 chars; code/tests/AGENTS.md are 15,011 chars (~3,750 tokens).
  tasks: 2
  commits: 4       # MEASURED: git rev-list --count plan_head_before..plan_head_after
plan_head_before: db4d959eace1c363b18abd95e0267840371493a7
plan_head_after: 566dafd173627d0f0b82e3afcdf8cf6b61cc189f
tech-stack:
  added: []
  patterns:
    - "One static gate class for the admin rule; actions call AdminGate::authorize($user, '<existing message>') as their first statement, outside the transaction"
    - "Publishing-state columns are never fillable; the creating action writes them with forceFill"
    - "Dates are validated by round trip: createFromFormat('!Y-m-d') must format back to the exact input (Carbon overflows otherwise); parse exceptions are caught and treated as invalid"
key-files:
  created:
    - app/Publishing/AdminGate.php
    - tests/Unit/Publishing/PublishableEventDataTest.php
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-12-task1-red.json
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-12-task1-red-junit.xml
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-12-task2-red.json
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-12-task2-red-junit.xml
  modified:
    - app/Publishing/Actions/PublishEvent.php
    - app/Publishing/Actions/UpdatePublishedEvent.php
    - app/Publishing/Actions/ChangeEventStatus.php
    - app/Publishing/Actions/ChangeOccurrenceStatus.php
    - app/Models/User.php
    - app/Models/Event.php
    - app/Publishing/Data/PublishableEventData.php
    - app/Calendar/ManualRecurrence.php
    - tests/Feature/Publishing/PublishEventTest.php
    - tests/Unit/Calendar/ManualRecurrenceTest.php
    - AGENTS.md
decisions:
  - "01-12: the ADMIN_EMAIL rule lives only in App\\Publishing\\AdminGate (allows + authorize); every write action and the panel gate delegate to it, keeping their existing exception messages"
  - "01-12: status, origin, approved_by and approved_at are not fillable on Event; PublishEvent writes them with forceFill after the admin check"
  - "01-12: PublishableEventData validates title/description, real dates, HH:MM[:SS] times, start time unless all-day, end/last date not before start, and source-or-contact in its constructor, with the form's wording"
  - "01-12: ManualRecurrence's until-date message is now 'A series that ends on a date needs that date (a real Y-m-d date).' (no test or UI depended on the old text)"
metrics:
  duration: "~5 min"
  completed: 2026-10-17
requirements: [ADM-01, ADM-02, ADM-03, CAL-08, REC-01]
---

# Phase 1 Plan 12: Hardened publish write path (admin gate, non-fillable state, DTO and UNTIL validation) Summary

PublishEvent now refuses any approver who is not the ADMIN_EMAIL user before it opens the transaction, through one `AdminGate` that all four write actions and the Filament panel share. Publishing state can no longer be mass-assigned on `Event`. `PublishableEventData` enforces the same rules as the Filament form. `ManualRecurrence` rejects impossible UNTIL dates instead of rolling them over into a later date. Suite: 440 passed (411 + 29 new). Pint and Larastan are clean.

## Tasks

| # | Task | Commits | Files |
|---|------|---------|-------|
| 1 | Tracer: AdminGate → PublishEvent → Event → DB, plus the other three actions, the panel gate and AGENTS.md | 3e63951 (RED), 4496458 (GREEN) | AdminGate, PublishEvent, UpdatePublishedEvent, ChangeEventStatus, ChangeOccurrenceStatus, User, Event, PublishEventTest, AGENTS.md |
| 2 | DTO invariants (WR-02) and strict UNTIL dates (WR-03); full suite green | 808c4bb (RED), 566dafd (GREEN) | PublishableEventData, ManualRecurrence, PublishableEventDataTest (new), ManualRecurrenceTest |

Tracer gate after Task 1: ran `<verify>` again end to end after the GREEN commit (`pest tests/Feature/Publishing tests/Feature/Filament tests/Feature/Status`: 81 passed). Task 2 started only after that.

## Finding → fix → test

| Finding | Fix | Regression test |
|---------|-----|-----------------|
| **WR-01**: PublishEvent had no admin check, and the email rule was copied in 4 places | `App\Publishing\AdminGate::allows()/authorize()` holds the rule once (case-insensitive; an empty or missing ADMIN_EMAIL denies everyone). The first statement of `PublishEvent::__invoke` is `AdminGate::authorize($approver, 'Only the admin can publish events.')`, before `DB::transaction`. UpdatePublishedEvent, ChangeEventStatus and ChangeOccurrenceStatus dropped their private `authorize()` and call the gate with the same message text as before. `User::canAccessPanel` returns `AdminGate::allows($this)`. | PublishEventTest: "refuses an approver who is not the admin and writes nothing" (0 rows in events, event_occurrences, event_slugs), "matches the admin email case-insensitively when publishing", "refuses everyone, the former admin included, when ADMIN_EMAIL is empty". The existing AuthorizationException tests for edit and status changes pass unchanged. |
| **WR-07**: `status`, `origin`, `approved_by` and `approved_at` were mass-assignable | Removed from `#[Fillable]`. PublishEvent builds `new Event($data->toAttributes())` and then `forceFill`s the four columns from the gated approver. The model docblock was updated. A grep found no other app code mass-assigning these keys. | PublishEventTest: "does not let publishing state or approval data be mass-assigned on Event" (`isFillable` false for all four, true for title; `new Event([... 'status' => 'hidden'])` has no status attribute), "records the approval time as the current moment". "publishes with origin, approver and approval time recorded" still passes. |
| **WR-02**: the DTO only validated recurrence | `assertValid()` runs first in the constructor and throws `InvalidArgumentException` for: a blank title or description; a start, end or last date that is not a real Y-m-d date (round-trip check); a time that does not match HH:MM[:SS] within 00:00–23:59:59; a timed event with no start time; an end date before the start date; an ends-on-a-date series whose last date is before the start date; no source URL and no organiser contact (trimmed). Messages reuse the form's wording where the form has one. | New `tests/Unit/Publishing/PublishableEventDataTest.php`: 14 refusal cases (one per invariant, plus month-13, unparseable and whitespace-only variants) and 6 valid baselines (all-day with no start time, HH:MM and HH:MM:SS, source URL only, a valid ends-on-date series). |
| **WR-03**: `2026-13-45` silently became 14 Feb 2027 | New `parseExact()`/`localDate()` helpers catch Carbon parse exceptions and require the parsed value to format back to the input. They are used in the constructor (OnDate), `untilValue()` and `untilToLocalDate()` for both the `Ymd` and `Ymd\THis\Z` shapes. | ManualRecurrenceTest: "refuses an impossible until date instead of overflowing it" (2026-13-45, 2026-02-30), "keeps a real until date such as 28 Feb 2026 as London end of day" (`UNTIL=20260228T235959Z`), "rejects a stored UNTIL that is not a real date" (`UNTIL=20261345`). |

## Fixtures 01-14 missed

None. With the invariants switched on, the full suite passed without any fixture edits. `git diff --stat gsd/01-12-base -- tests/Feature` lists only `tests/Feature/Publishing/PublishEventTest.php` (+50, additions only). The removed-assertion check prints `0`.

## TDD Gate Compliance

- **Task 1 RED** (`tdd-evidence/01-12-task1-red.json`, classifier `RED_EVIDENCE_OK`, target `it refuses an approver who is not the admin and writes nothing`): 12 tests ran with 3 assertion failures and 0 errors. The non-admin approver and the empty-ADMIN_EMAIL case published instead of throwing, and `isFillable('status')` was true. Semantic assessment: every failure came from the missing behaviour. The case-insensitive and approval-time cases passed already, as intended, because they cover behaviour to keep. There were no load, DB or fixture faults.
- **Task 2 RED** (`tdd-evidence/01-12-task2-red.json`, `RED_EVIDENCE_OK`, target `it rejects a stored UNTIL that is not a real date`): 40 tests ran with 17 assertion failures and 0 errors. All 14 DTO refusal cases failed with "InvalidArgumentException not thrown". Both impossible until dates constructed successfully, and `UNTIL=20261345` parsed. The valid baselines and all earlier ManualRecurrence cases passed.
- **GREEN**: 4496458 and 566dafd. No refactor commit was needed.

## Verification

- `./vendor/bin/pest`: **440 passed, 2546 assertions** (baseline 411). The single PHPUnit warning with no details is pre-existing (recorded in 01-08/01-10/01-11/01-14).
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: 0 errors.
- `grep -rln "config('app.admin_email')" app` → only `app/Publishing/AdminGate.php`. `grep -rn "AdminGate::" app | wc -l` → 5. `grep -c AdminGate AGENTS.md` → 1.
- Local tag `gsd/01-12-base` → db4d959 (not pushed).

## Deviations from Plan

None. The plan was executed as written. One cadence note: each TDD task was committed as a RED `test(01-12)` commit followed by a GREEN `fix(01-12)` commit, per tdd.md and the 01-11 precedent, so there are 4 task commits for 2 tasks.

## Known Stubs

None.

## Threat Flags

None. No new endpoints or trust-boundary surface. T-01-75…T-01-79 are mitigated as planned, and no packages were added (T-01-SC).

## Self-Check: PASSED

- FOUND: app/Publishing/AdminGate.php, tests/Unit/Publishing/PublishableEventDataTest.php, the 4 tdd-evidence files, all modified files
- FOUND: 3e63951, 4496458, 808c4bb, 566dafd (ancestors of HEAD)
