---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 12
subsystem: review-queue
tags: [review, bulk-approve, queue-order, filament, sources, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-01 relevant_starts_at column; 03-05 SourceItemRecorder (D-21 fallback description, D-22 default venue); 03-09/03-10 update and cancellation drafts with relevant_starts_at; 03-11 'duplicate' bulk key covering duplicate_of_draft_id"
provides:
  - "App\\Publishing\\Actions\\ApproveAllLikeThis(EventDraft $seed, User): array{approved, total, skipped}; matching(EventDraft); applies(EventDraft); LIMIT = 200"
  - "EventDraftsTable::approveAllLikeThis() row action (counted confirmation, one notification)"
  - "ReviewQueue::URGENT_SQL, URGENT_HOURS, isUrgent(), whereUrgent(), urgentCutoff(); applyOrder(..., ?CarbonImmutable $now)"
  - "Queue table: Urgent badge on the Kind column, Source column (source name or origin label), Source filter on source_id, Origin filter"
affects: [03-13 review page (Urgent badge on the review page, D-09), 03-17 UAT]

estimate:
  tokens: 65000
  tasks: 2
actuals:
  tokens: 10200
  tasks: 2
  commits: 3
plan_head_before: 140fc5a51440ee0852af4eaf0212ef318f2b6f71
plan_head_after: fe1a4a775895e3ebb789e050615dfa48d19c8710

tech-stack:
  added: []
  patterns:
    - "Record action that loops a domain action and builds its own Notification from counts (no reportBulkProcessingFailure, F7)"
    - "SQL twin of CategoryAssigner::normalise(): btrim(regexp_replace(lower(normalize(title, NFC)), '\\s+', ' ', 'g')) = ?"
    - "Time-dependent sort key with a bound cutoff from the app clock, the same value for the keyset's outer and inner expressions"

key-files:
  created:
    - app/Publishing/Actions/ApproveAllLikeThis.php
    - tests/Feature/Filament/ApproveAllLikeThisTest.php
  modified:
    - app/Review/ReviewQueue.php
    - app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php
    - tests/Feature/Review/ReviewQueueOrderTest.php
    - tests/Feature/Filament/ReviewQueueTableTest.php

key-decisions:
  - "Urgency cutoff is bound from the app clock (Carbon::now() in UTC + 48 hours), not PostgreSQL now() + interval '48 hours' as the plan wrote: frozen-time tests (and the DST case) need the same clock as the rest of the app. One cutoff per query, and next()/previous() compute one $now for both the keyset and the order"
  - "SORT_KEY's first entry is '(not URGENT_SQL)', so urgent rows (false) sort first ascending; URGENT_SQL is never null"
  - "A past relevant_starts_at still counts as urgent (the predicate is just '< cutoff'), as the plan's SQL says"
  - "Title matching: SQL expression mirroring CategoryAssigner::normalise() (NFC, lower, whitespace collapsed, trimmed) with the normalised seed title bound"
  - "venue IS NOT DISTINCT FROM implemented as whereNull / where('venue_id', …) to avoid binding an untyped null in PostgreSQL"
  - "applies(): pending + kind new + source_id set + non-blank title. The action is only on the table row (the review-page button belongs with 03-13's review-page work)"
  - "Notification: title 'Approved {n} of {total}.'; body one <p> per skip reason (escaped), success when nothing skipped, warning otherwise"
  - "Urgent badge: the Kind column shows a list of badges [kind label, 'Urgent' (danger)], so the existing column order and labels stay the same"
  - "Source column renamed from 'origin' to 'source' (state = source name or origin label); the Source filter is SelectFilter source_id via the source relation"
  - "EXT-01, REV-06, REV-07 stay Pending: this plan supports them; 03-13/03-17 still list REV-06/07 and EXT-01 is the ICS extraction requirement"

patterns-established:
  - "likeNotificationLines() test helper reads title + <p> body lines from NotificationMailbox (same as BulkReviewTest)"

requirements-completed: []

coverage:
  - id: D1
    description: "Tracer: Town Council fixture with default venue Town Hall → 'Approve all like this' on one Yoga draft approves all three (18 Oct 10:00, 21 Oct 18:00, 25 Oct 10:00), listed on /, notification 'Approved 3 of 3.'"
    requirement: EXT-01
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/ApproveAllLikeThisTest.php#approves every Town Council Yoga draft at the Town Hall in one click and counts them"
        status: pass
  - id: D2
    description: "Skips: duplicate + parse errors → 'Approved 1 of 3.', '1 skipped: possible duplicate', '1 skipped: has parse errors'; other source / other venue untouched; case/space-insensitive title; queue order; counted confirmation; visibility; non-admin refused"
    requirement: EXT-01
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/ApproveAllLikeThisTest.php"
        status: pass
  - id: D3
    description: "Urgent-first order (update 3 h, update 47 h, then Phase 2 order); next()/previous() walks; table order; isUrgent == SQL; new never urgent; DST 47.5 h urgent / 48.5 h not"
    requirement: REV-06
    verification:
      - kind: integration
        ref: "tests/Feature/Review/ReviewQueueOrderTest.php#urgent changes first"
        status: pass
  - id: D4
    description: "Urgent badge only on urgent updates/cancellations; Source filter on sources; Origin filter; Source column shows source name or origin label"
    requirement: REV-07
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/ReviewQueueTableTest.php"
        status: pass

duration: ~15min
completed: 2026-10-18
---

# Phase 3 Plan 12: Approve All Like This, Urgent-First Queue and Source Filter Summary

**The queue gets an "Approve all like this" row action. It publishes every pending new draft from the same source with the same normalised title and venue in one click, each draft through the D-21 bulk checks and ApproveDraft, and one notification counts the approvals and names each skip. Update and cancellation drafts due within 48 elapsed hours now lead the queue with an "Urgent" badge, in the one sort key the table and Approve & next share. The Source filter now uses the real sources table.**

## Performance

- **Duration:** ~15 min
- **Tasks:** 2 (1 tracer, 1 TDD)
- **Files:** 2 created, 4 modified

## Accomplishments
- `ApproveAllLikeThis`:
  - checks AdminGate first;
  - matches on source, normalised title (SQL twin of `CategoryAssigner::normalise`, bound parameter) and venue;
  - takes matches in `ReviewQueue::applyOrder()` order, at most 200;
  - per draft, the first `bulkBlockerKeys()` key skips it, otherwise `ApproveDraft` runs in its own transaction (`InvalidReviewTransition`/`ModelNotFoundException` → already_reviewed, `DraftNotPublishable` → blocked);
  - returns `{approved, total, skipped}`.
- Queue row action "Approve all like this": confirmation "Approve all drafts like this?" with "{n} pending drafts from {source} titled "{title}" at {venue}.", submit "Approve all", cancel "Keep as is". Afterwards one notification shows "Approved {n} of {total}." plus one `skipMessage()` line per reason.
- With the D-21 fallback description and the D-22 default venue, the Town Council's three Yoga drafts publish with no skips (tracer).
- `ReviewQueue` urgent key:
  - `URGENT_SQL` sits first in `SORT_KEY`;
  - `qualify()` knows `kind` and `relevant_starts_at`;
  - the cutoff is bound once per query;
  - `isUrgent()` / `whereUrgent()` share the same cutoff, and a test checks that they agree row by row, including across the October clock change.
- Table: the Kind column shows the kind plus a red "Urgent" badge, the Source column shows the source name (or the origin label for manual and submitted drafts), the Source filter is on `source_id`, and the old filter is kept as "Origin".

## Task Commits

1. **Task 1 (tracer): Approve all like this**: `432a94d` (feat)
2. **Task 2 (TDD): urgent-first order, badge, source filter**: `2fa42f0` (test, RED), `fe1a4a7` (feat, GREEN)

Tracer feedback gate: verify re-run passed end-to-end before Task 2 (ApproveAllLikeThisTest + BulkReviewTest, 16/16).

## Files Created/Modified
- `app/Publishing/Actions/ApproveAllLikeThis.php`: D-03 action.
- `app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php`: row action, notification, Source column, Urgent badge, Source/Origin filters, eager-loads `source`.
- `app/Review/ReviewQueue.php`: urgent key, isUrgent/whereUrgent/urgentCutoff, one `$now` per navigation call.
- Tests: `ApproveAllLikeThisTest` (7), `ReviewQueueOrderTest` (+5, "urgent changes first"), `ReviewQueueTableTest` (origin filter renamed, +2).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Urgency cutoff bound from the app clock instead of PostgreSQL now()**
- **Found during:** Task 2
- **Issue:** The plan's SQL used `now() + interval '48 hours'`. The database clock ignores the app's frozen time, so the queue order would follow the real wall clock, not the clock the badge (`isUrgent`), tests and the rest of the app use. The "isUrgent mirrors the SQL" truth would not hold under frozen time.
- **Fix:** `URGENT_SQL` ends in `relevant_starts_at < ?`, bound to `Carbon::now()->utc()->addHours(48)` (elapsed hours, so a clock change does not move it). The keyset binds the same value on both sides, and `next()`/`previous()` use one `$now` for the keyset and the order.
- **Files modified:** app/Review/ReviewQueue.php
- **Commit:** fe1a4a7

**2. [Rule 1 - Bug] Test fixes during GREEN (test-only)**
- The badge test's in-order expectation assumed id order for equal start dates and created_at. Corrected to the real tie-break.
- The urgent table-order test has 11 drafts, so it now checks the first page (10 rows).

## Known Stubs

None.

## Deferred Issues

None new. Deferred items 3 and 4 (03-11) and the 03-10 Reject-on-change-draft baseline note remain for 03-13, as briefed.

## Verification

- `./vendor/bin/pest --testsuite=Unit,Feature tests/Feature/Filament/ApproveAllLikeThisTest.php tests/Feature/Filament/BulkReviewTest.php`: 16 passed
- `./vendor/bin/pest --testsuite=Unit,Feature tests/Feature/Review/ReviewQueueOrderTest.php tests/Feature/Filament/ReviewQueueTableTest.php`: 28 passed
- Full Unit+Feature: 1613 passed; PHPStan: 0 errors; Pint: passed
- `grep -c bulkBlockerKeys app/Publishing/Actions/ApproveAllLikeThis.php` → 2; `grep -c relevant_starts_at app/Review/ReviewQueue.php` → 6

## Self-Check: PASSED

- Files: app/Publishing/Actions/ApproveAllLikeThis.php, tests/Feature/Filament/ApproveAllLikeThisTest.php present
- Commits: 432a94d, 2fa42f0, fe1a4a7 are ancestors of HEAD
