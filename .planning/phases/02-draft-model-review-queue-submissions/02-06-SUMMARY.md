---
phase: 02-draft-model-review-queue-submissions
plan: 06
subsystem: review
tags: [laravel, eloquent, mass-prunable, scheduler, review-actions, retention, privacy, state-machine]

requires:
  - phase: 02-01
    provides: event_drafts table (reject_reason CHECK + reject_reason_only_when_rejected), ReviewStatus transitions, InvalidReviewTransition
  - phase: 02-03
    provides: ApproveDraft action shape, EventDraftFactory (approvedFor, submission), config review.retention_days
provides:
  - App\Enums\RejectReason (spam, out_of_area, not_an_event, past, duplicate, incomplete, other; HasLabel with the D-11 labels)
  - App\Publishing\Actions\RejectDraft (optional reason + trimmed note, reviewed_by / reviewed_at)
  - App\Publishing\Actions\ReopenDraft (rejected -> pending_review, clears reason/reviewer/time, keeps review_notes)
  - EventDraft reject_reason cast to RejectReason; MassPrunable prunable() (spam 30 days, others 90 days, rejected only)
  - Daily model:prune --model=EventDraft at 03:40 Europe/London (withoutOverlapping)
  - EventDraftFactory::rejected(RejectReason, CarbonImmutable) state
affects: [02-12, 02-14, 02-16, phase-05-dashboard]

actuals:
  tokens: 5519
  tasks: 2
  commits: 4
plan_head_before: 41827cb0bbd4571c362294cbfe89633163c355f4
plan_head_after: 90d8b40f9c5309bf3f6654a3d1aac6209dbcb257

tech-stack:
  added: []
  patterns:
    - "Review actions copy the ChangeEventStatus shape: AdminGate::authorize first, DB::transaction, lockForUpdate, assertCanTransitionTo on the locked value, forceFill, Log::info ids only (afterCommit, like ApproveDraft)"
    - "Retention is MassPrunable + a scheduled model:prune, cutoffs as UTC instants from config('review.retention_days.*')"

key-files:
  created:
    - app/Enums/RejectReason.php
    - app/Publishing/Actions/RejectDraft.php
    - app/Publishing/Actions/ReopenDraft.php
    - tests/Feature/Review/RejectReopenDraftTest.php
    - tests/Feature/Review/PruneRejectedDraftsTest.php
  modified:
    - app/Models/EventDraft.php
    - database/factories/EventDraftFactory.php
    - routes/console.php

key-decisions:
  - "Rejecting overwrites review_notes with the new (trimmed) note, or null when none is given; Reopen keeps review_notes so the admin still sees why it was rejected"
  - "A rejection with no reason counts as non-spam for retention (90 days)"
  - "prunable() also requires reviewed_at IS NOT NULL, so a rejected row without a timestamp is never deleted by accident"
  - "Reject/Reopen log after the outermost commit (DB::afterCommit), matching ApproveDraft, so a rolled-back decision is never logged"

patterns-established:
  - "EventDraftFactory::rejected($reason, $at) builds retention fixtures; boundary tests freeze London after the BST change (1 Nov 2026) so 29/31-day windows straddle the 25-hour day"

requirements-completed: [REV-04]

coverage:
  - id: D1
    description: "Admin rejects a draft with an optional preset reason and note; reason stored as the enum value, note in review_notes, with reviewed_by and reviewed_at (UTC)"
    requirement: REV-04
    verification:
      - kind: integration
        ref: "tests/Feature/Review/RejectReopenDraftTest.php#rejects as spam with a note, reopens, rejects again and the scheduled prune deletes it after 31 days"
        status: pass
    human_judgment: false
  - id: D2
    description: "Reopen returns a rejected draft to pending_review, clears reject_reason / reviewed_by / reviewed_at, keeps review_notes"
    requirement: REV-04
    verification:
      - kind: integration
        ref: "tests/Feature/Review/RejectReopenDraftTest.php#rejects as spam with a note, reopens, rejects again and the scheduled prune deletes it after 31 days"
        status: pass
    human_judgment: false
  - id: D3
    description: "Rejecting an approved draft throws InvalidReviewTransition and changes nothing; reopening a pending draft is refused; a non-admin gets AuthorizationException"
    requirement: REV-04
    verification:
      - kind: integration
        ref: "tests/Feature/Review/RejectReopenDraftTest.php#refuses to reject an approved draft and changes nothing"
        status: pass
      - kind: integration
        ref: "tests/Feature/Review/RejectReopenDraftTest.php#refuses a non-admin reject or reopen and changes nothing"
        status: pass
    human_judgment: false
  - id: D4
    description: "Empty reason and note store null; a whitespace-only note stores null (REV-04 flagged edge)"
    requirement: REV-04
    verification:
      - kind: integration
        ref: "tests/Feature/Review/RejectReopenDraftTest.php#rejects with no reason and no note, storing both as null"
        status: pass
      - kind: integration
        ref: "tests/Feature/Review/RejectReopenDraftTest.php#stores a whitespace-only note as null"
        status: pass
    human_judgment: false
  - id: D5
    description: "No Mail or Notification is sent on approve, reject or reopen (D-02)"
    requirement: REV-04
    verification:
      - kind: integration
        ref: "tests/Feature/Review/RejectReopenDraftTest.php#sends no mail or notification when drafts are approved, rejected or reopened (D-02)"
        status: pass
    human_judgment: false
  - id: D6
    description: "Retention: spam kept at 29 days, deleted at 31; others kept at 89, deleted at 91; pending and approved drafts 400 days old kept; daily model:prune at 03:40 London is scheduled"
    requirement: REV-04
    verification:
      - kind: integration
        ref: "tests/Feature/Review/PruneRejectedDraftsTest.php"
        status: pass
      - kind: manual-command
        ref: "php artisan schedule:list | grep model:prune (shows 40 2 * * * UTC = 03:40 BST)"
        status: pass
    human_judgment: false

duration: 8min
completed: 2026-10-17
status: complete
---

# Phase 2 Plan 06: Reject, Reopen and Rejected-Draft Retention Summary

**The draft state machine is now complete. RejectDraft takes an optional D-11 preset reason and a trimmed optional note. ReopenDraft undoes a rejection. EventDraft is MassPrunable, so a daily `model:prune` at 03:40 London hard-deletes rejected drafts after 30 days (spam) or 90 days (any other reason).**

## Performance

- **Duration:** ~8 min
- **Completed:** 2026-10-17
- **Tasks:** 2 (1 tracer + 1 TDD)
- **Files:** 8 (5 created, 3 modified)

## Accomplishments

- `RejectReason` enum whose values match the `event_drafts_reject_reason_check` constraint and whose labels are the D-11 presets ("Past or expired", "Duplicate of an existing event", "Incomplete or can't be checked", …).
- `RejectDraft` / `ReopenDraft` follow the same shape as `ChangeEventStatus` and `ApproveDraft`. AdminGate is checked before the transaction, the row is locked, the transition is asserted on the locked status, and the log carries ids only.
- `EventDraft::prunable()` only ever matches `review_status = rejected` rows with a `reviewed_at`. Cutoffs are UTC instants taken from `config('review.retention_days.*')`.
- Daily `model:prune --model=EventDraft` runs at 03:40 Europe/London, which avoids the DST hour.
- 14 tests (51 assertions) cover the tracer path, empty and whitespace input, refusals, no email, and the exact retention boundaries.

## Task Commits

1. **Task 1 (tracer): reject → reopen → reject → prune.** RED `5431940`, GREEN `c83206b`
2. **Task 2 (TDD): retention boundaries, refusals, no-email.** Tests `c22c7be`, PHPStan type fix `90d8b40`

## TDD Gate Compliance

- Task 1 RED (`5431940`) failed with "Target class [App\Publishing\Actions\RejectDraft] does not exist", then went GREEN in `c83206b`.
- Task 2's tests passed on their first run because Task 1 had already built the behaviour. They pin that behaviour rather than drive new code. To show they can fail, I ran a mutation check: dropping the `review_status` filter and using the 90-day cutoff for spam made 2 tests fail (spam deleted at 31 days, approved draft kept). The file was then restored with `git checkout`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan return type on `prunable()`**
- **Found during:** Task 2 (full PHPStan run)
- **Issue:** `@return Builder<self>` but `static::query()` on a non-final model returns `Builder<static>` (`return.type`).
- **Fix:** Docblock changed to `@return Builder<static>`, which is the accurate type. No ignore comment or cast.
- **Files modified:** app/Models/EventDraft.php
- **Commit:** `90d8b40`

**2. [Rule 2 - Defensive] `reviewed_at IS NOT NULL` in `prunable()`**
- A rejected row always has `reviewed_at` when it comes through `RejectDraft`. The guard stops a malformed row from being treated as old. No separate commit (part of `c83206b`).

**Process note:** `branching_strategy` is `none`, and the orchestrator asked for sequential mode on the main working tree. So the commits sit on `main`, like plans 02-01 to 02-05.

## Verification

- `./vendor/bin/pest --testsuite=Unit,Feature`: 728 passed (3964 assertions)
- `vendor/bin/phpstan analyse --memory-limit=1G`: 0 errors
- `vendor/bin/pint --dirty --format agent`: passed
- `php artisan schedule:list`: `40 2 * * * php artisan model:prune --model='App\Models\EventDraft'` (UTC display of 03:40 BST)

## Next Phase Readiness

- 02-12 (queue UI) can call `RejectDraft` and `ReopenDraft` and use `RejectReason` for its select options.
- The /privacy retention wording (02-05) matches `config('review.retention_days')`.

## Self-Check: PASSED
