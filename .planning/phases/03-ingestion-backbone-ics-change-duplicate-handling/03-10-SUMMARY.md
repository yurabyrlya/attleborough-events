---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 10
subsystem: publishing
tags: [publishing, review, source-changes, baseline, cancellations, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-09 update/cancellation drafts (diff formats, SourceDiff cast), events.cancelled_dates; 03-08 accepted baseline, LinkEventSource; 03-04 ContentHasher::contentHashFromPayload"
provides:
  - "App\\Publishing\\Actions\\ApplySourceUpdate(EventDraft, User): Event — applies every applicable diff field through UpdatePublishedEvent, moves the baseline to the current payload"
  - "App\\Publishing\\Actions\\IgnoreSourceChange(EventDraft, User): EventDraft — rejected + RejectReason::Ignored; update → whole baseline, cancellation → cancelled part only"
  - "App\\Publishing\\Actions\\ConfirmSourceCancellation(EventDraft, User): EventDraft — listed dates via ChangeOccurrenceStatus + events.cancelled_dates, or whole event via ChangeEventStatus"
  - "App\\Publishing\\SourceBaseline::moveToCurrent() / moveCancelledPart()"
  - "RejectReason::Ignored ('Ignored (source change)') and RejectReason::rejectOptions() (Reject dialog list without Ignored)"
  - "PublishableEventData::withExcludedDates(?array)"
  - "Public constants for UI messages: ApplySourceUpdate::VENUE_NEEDED, NOT_AUTOMATIC, WRONG_KIND, BASELINE_ONLY; IgnoreSourceChange::WRONG_KIND; ConfirmSourceCancellation::WRONG_KIND"
  - "ChangeOccurrenceStatus / ChangeEventStatus log inside DB::afterCommit (C9)"
affects: [03-13 review UI (Apply / Ignore / Confirm buttons, refusal messages, venue pick on update drafts), 03-11 mark duplicate (same lock/baseline pattern)]

actuals:
  tokens: 18700
  tasks: 3
  commits: 3
plan_head_before: 770791d28a9883c86afc5e4d0eac5151a24aa3f7
plan_head_after: c42c03c85d45b89ec81a00ccc4e648ad39af4edc

tech-stack:
  added: []
  patterns:
    - "Source decisions: AdminGate → transaction { draft FOR UPDATE → transition + kind check → event FOR UPDATE → nested status/update actions → source item FOR UPDATE last } → ids-only DB::afterCommit log"
    - "Apply = PublishableEventData::fromEvent form state + diff 'now' values → fromForm (→ withExcludedDates) → UpdatePublishedEvent; InvalidArgumentException → DraftNotPublishable('This change can't be applied automatically: …')"
    - "Baseline moves are one helper (SourceBaseline), hashing only through ContentHasher::contentHashFromPayload"
    - "Forced-failure rollback tests via a model 'saving' listener on the last-locked row (no mocks of final classes)"

key-files:
  created:
    - app/Publishing/SourceBaseline.php
    - app/Publishing/Actions/ApplySourceUpdate.php
    - app/Publishing/Actions/IgnoreSourceChange.php
    - app/Publishing/Actions/ConfirmSourceCancellation.php
    - tests/Feature/Publishing/ApplySourceUpdateTest.php
    - tests/Feature/Publishing/IgnoreSourceChangeTest.php
    - tests/Feature/Publishing/ConfirmSourceCancellationTest.php
  modified:
    - app/Publishing/Actions/ChangeOccurrenceStatus.php
    - app/Publishing/Actions/ChangeEventStatus.php
    - app/Publishing/Data/PublishableEventData.php
    - app/Enums/RejectReason.php
    - app/Filament/Resources/EventDrafts/Actions/ReviewActions.php
    - tests/Feature/Publishing/LinkEventSourceTest.php

key-decisions:
  - "Apply skips diff keys that have no published field: moved_overrides and extra_dates (D-19), and cancelled (an un-cancel is the admin's Reinstate, not an automatic write). The baseline still moves past them (plan-checker handoff)"
  - "Venue for Apply: the admin's venue_id on the update draft first, else VenueMatcher::exact(venue_name, venue_postcode); otherwise refused with 'Pick the venue for this change first.'"
  - "A changed rrule is turned into Repeats/Ends form state with ManualRecurrence::tryFromStoredRule against the (possibly new) start date; a rule that does not round-trip is refused as not automatic"
  - "Parse errors on an update draft refuse Apply with the first error; checked before the venue so the admin sees the real blocker"
  - "Confirm of a date cancellation appends the keys to events.cancelled_dates (sorted, unique) and cancels only Scheduled occurrences for those keys; already-cancelled dates stay cancelled and the confirm succeeds; hidden dates stay hidden (hidden → cancelled is not an allowed transition)"
  - "Confirm of a whole-event cancellation ({cancelled} or missing {status}) changes the event only when it is Published; an already-cancelled or hidden event is left as it is and the draft is still approved"
  - "Ignore never touches events or events.cancelled_dates, so an ignored far date is created Scheduled by OccurrenceGenerator::sync() (03-09 caveat)"
  - "SourceBaseline::moveCancelledPart copies cancelled and cancelled_keys; 'status' is in the list as the plan asks, but NormalisedFacts payloads have no such key (item state lives in source_items.status), so only present keys move"
  - "RejectReason::Ignored is kept out of the Reject dialog (rejectOptions()) and a forged 'ignored' value is dropped by ReviewActions; only IgnoreSourceChange sets it"
  - "REV-06 and REV-07 stay Pending: the actions are done, but the admin cannot use them until 03-13 adds the diff view and buttons"

patterns-established:
  - "Decision actions return refusals as DraftNotPublishable with plain messages exposed as public constants so the UI can show them verbatim"

requirements-completed: []

coverage:
  - id: D1
    description: "Tracer: moved TC Yoga update draft → Apply → 25 Oct occurrence 11:00 UTC (raw SQL), / and event page show 11am, draft approved with reviewer, accepted_hash = content_hash, next run creates nothing; admin's title/description kept; non-admin refused"
    requirement: REV-06
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/ApplySourceUpdateTest.php#applies a moved Town Council session: new time on the public page, baseline moved, the next run raises nothing"
        status: pass
  - id: D2
    description: "Ignore update / date cancellation / whole-event cancellation; cancelled-part move raises the held-back time change on the next byte-identical run; cancellation-only Ignore leaves accepted_hash = content_hash; Ignored not offered in Reject dialog"
    requirement: REV-06
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/IgnoreSourceChangeTest.php"
        status: pass
  - id: D3
    description: "Confirm series dates (4 and 11 Nov cancelled, 28 Oct and 18 Nov scheduled, two 'Cancelled' labels), beyond-horizon date inserted Cancelled by sync, ignored far date Scheduled, one-off and missing-item whole-event cancellation, already-cancelled date, C9 rollback logs nothing, success logs after commit, wrong kind / non-admin / ApproveDraft refusal"
    requirement: REV-07
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/ConfirmSourceCancellationTest.php"
        status: pass
  - id: D4
    description: "Double Apply, Apply vs Ignore both ways, unknown venue then picked venue, exact venue match, one-off→series refusal, parse-error refusal + Ignore, baseline-only keys skipped, rule + EXDATE applied to a series, wrong kinds, non-admins on all three"
    requirement: REV-06
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/ApplySourceUpdateTest.php"
        status: pass

duration: ~12min
completed: 2026-10-18
---

# Phase 3 Plan 10: Apply, Ignore and Confirm Source Changes Summary

**The admin now has three decisions on source changes, all as Publishing actions. `ApplySourceUpdate` writes every changed field in an update draft's diff to the event through `UpdatePublishedEvent`. It starts from the published values, so the admin's own edits survive. `IgnoreSourceChange` rejects the draft as "ignored". `ConfirmSourceCancellation` cancels exactly the listed series dates, or the whole event. Every decision moves the source item's accepted baseline, so the same change is not raised again. Cancellations move only their cancelled part of the baseline. Confirmed dates go into `events.cancelled_dates`, so a date beyond the 90-day horizon arrives as Cancelled.**

## Performance

- **Duration:** ~12 min (11:17Z–11:29Z on 2026-10-18)
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 7 created, 6 modified

## Accomplishments
- `SourceBaseline` is the one place a baseline moves:
  - `moveToCurrent` takes the whole current payload;
  - `moveCancelledPart` copies `cancelled` and `cancelled_keys`, then rehashes with `ContentHasher::contentHashFromPayload`.
- `ApplySourceUpdate` builds form state from `fromEvent`, then overwrites each diff field with its `now` value:
  - times as `H:i`;
  - venue from the admin's pick or `VenueMatcher::exact`;
  - a rule via `ManualRecurrence::tryFromStoredRule`;
  - EXDATEs via the new `PublishableEventData::withExcludedDates`.

  Refusals have fixed messages: venue needed, not automatic (with the reason), wrong kind, parse error.
- `IgnoreSourceChange`: rejected with `RejectReason::Ignored`. An update moves the whole baseline. A cancellation moves only the cancelled part, and never writes events or `cancelled_dates`.
- `ConfirmSourceCancellation`:
  - for dates, it appends to `cancelled_dates` and cancels Scheduled occurrences through `ChangeOccurrenceStatus`;
  - for a whole event (`{cancelled}` or missing `{status}`), it uses `ChangeEventStatus`, so the event page shows "Cancelled" (A8).
- C9: `ChangeOccurrenceStatus` and `ChangeEventStatus` now log inside `DB::afterCommit`. A rolled-back confirm logs nothing (tested).

## Task Commits

1. **Task 1 (tracer): ApplySourceUpdate + SourceBaseline**: `d0e36f8` (feat)
2. **Task 2 (tdd): Ignore, Confirm, RejectReason::Ignored, afterCommit logs**: `16cfa4c` (feat)
3. **Task 3 (tdd): refusals, idempotency, concurrency**: `c42c03c` (test)

**Tracer gate (auto mode):** after Task 1, I re-ran its `<verify>` (ApplySourceUpdateTest, UpdatePublishedEventTest, WriteChokePointTest: 12 passed, PHPStan clean) before expanding.

## Orchestrator handoffs
1. **Keys without a published field are skipped.** `BASELINE_ONLY = [cancelled, moved_overrides, extra_dates]`. Test: *skips diff keys with no published counterpart and still moves the baseline past them*. It adds `moved_overrides` and `extra_dates` beside a time change; only the time is written, the event stays a one-off with one occurrence, and `accepted_hash` = `content_hash`.
2. **Confirm writes `events.cancelled_dates`.** Test: *keeps a confirmed date beyond the horizon in events.cancelled_dates…* (20 Jan 2027 is inserted Cancelled by `calendar:sync-occurrences`). The opposite case is also tested: *never adds an ignored cancellation to events.cancelled_dates, so the far date is created as Scheduled*.
3. **Diff formats from 03-09 are read as specified.** That covers `{published, before, now}` for updates, `{cancelled}`, `{cancelled_keys}` (ascending), and `{status: active → missing}`. The missing shape is covered by *cancels the whole event for a missing-item cancellation*.
4. **`RejectReason::Ignored` added.** LinkEventSourceTest's rejected update/cancellation draft fixture now uses it instead of `Other`. IngestionSchemaTest's generic rejected draft keeps `Other`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] Ignored was offered in the Reject dialog**
- **Found during:** Task 2
- **Issue:** `ReviewActions::rejectFields()` used `->options(RejectReason::class)`, so the new case would appear as a manual reject reason.
- **Fix:** added `RejectReason::rejectOptions()` without Ignored, and `ReviewActions::rejectReason()` drops a forged `ignored`. Test added.
- **Files:** app/Enums/RejectReason.php, app/Filament/Resources/EventDrafts/Actions/ReviewActions.php
- **Commit:** `16cfa4c`

**2. [Rule 3 - Blocking] No way to carry EXDATE changes through form state**
- **Issue:** `fromForm()` deliberately has no excluded dates (C2), so it could not apply an `excluded_dates` diff.
- **Fix:** added `PublishableEventData::withExcludedDates()`, a validated copy. The admin edit path is unchanged.
- **Commit:** `d0e36f8`

### Judgement calls (unattended run)
- **Commits on `main`**, as instructed. Only this plan's files were staged.
- **RED order:**
  - Task 1's tests were run RED (class missing) before implementing.
  - Task 2's tests were written first and passed on the first implementation run. Mutations show they bite:
    - logging outside afterCommit: the C9 test fails;
    - Ignore moving the whole baseline for a cancellation: 1 failure;
    - Confirm not writing `cancelled_dates`: 3 failures.
  - Task 3 is tests only. They passed against the Task 1–2 code, so they are characterisation tests.
- **Concurrency:** Apply vs Ignore is tested in both orders, one after the other. A real two-connection race cannot run inside RefreshDatabase's wrapping transaction. Both actions take `SELECT … FOR UPDATE` on the draft first and check the transition on the locked row, so PostgreSQL serialises them.
- **RejectDraft on update/cancellation drafts is still allowed.** It rejects without moving the baseline, so the change is raised again by the next run. The plan's behaviour line only requires that ApproveDraft refuse them (tested). 03-13 should offer Apply/Ignore/Confirm for those kinds, not Reject.

**Total deviations:** 2 auto-fixed. **Impact:** the plan was delivered as specified. No packages were added and no migrations were needed: the 'ignored' CHECK value already existed from 03-01.

## Known Stubs
None. `.planning/WINDOWS.md` does not exist, so nothing was appended.

## Threat Flags
None beyond the plan's register:
- T-03-28: AdminGate runs first in all three actions; non-admins are tested on each.
- T-03-29: one transaction, with lock order draft → event → occurrence → item.
- T-03-30: afterCommit logging, with a rollback test.

## Issues Encountered
None. The full suite still reports the one PHPUnit warning that predates this plan.

## Verification
- Task verifies all green:
  - ApplySourceUpdate + UpdatePublishedEvent + WriteChokePoint;
  - Ignore + Confirm + ChangeOccurrenceStatus + ChangeEventStatus (28 passed);
  - ApplySourceUpdateTest (16 passed).
- Full `./vendor/bin/pest --testsuite=Unit,Feature`: **1530 passed**. PHPStan: 0 errors. Pint: clean.
- Acceptance greps:
  - `AdminGate::authorize` in ApplySourceUpdate = 1;
  - `afterCommit` in ChangeOccurrenceStatus = 1, and in ChangeEventStatus = 1;
  - `case Ignored` = 1.

## Requirements Traceability
- **REV-06 and REV-07 left Pending.** The decision actions and their baseline moves are complete and tested. The requirement wording ("Admin sees … a three-column diff … and can apply or ignore") needs the review UI from 03-13, which also lists both IDs.

## Next Phase Readiness
- 03-13 can wire up the buttons:
  - Apply → `ApplySourceUpdate`;
  - Ignore → `IgnoreSourceChange`;
  - Confirm → `ConfirmSourceCancellation`.
- Catch `DraftNotPublishable` and show `->blockers[0]`, and catch `InvalidReviewTransition`.
- For a venue change, the update draft's edit page must let the admin set `venue_id` (`DraftWriter::update` keeps the diff, as tested).

## Self-Check: PASSED

All 7 created files exist. Commits d0e36f8, 16cfa4c and c42c03c are ancestors of HEAD.
