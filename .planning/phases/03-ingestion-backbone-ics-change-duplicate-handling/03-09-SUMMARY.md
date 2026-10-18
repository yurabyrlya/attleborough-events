---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 09
subsystem: ingestion
tags: [ingestion, change-detection, update-drafts, cancellation, missing-items, baseline, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-04 ContentHasher/NormalisedFacts; 03-05 SourceItemRecorder, DraftWriter::createFromSource; 03-07 SourceHealthTracker (is_complete); 03-08 event_sources links, accepted baseline, RejectDraft D-23"
provides:
  - "App\\Ingestion\\Data\\ChangeVerdict (none, quiet, replace, update, cancel, redraft, supersede) and RecordOutcome (bucket + drafts created/replaced + cancelled)"
  - "App\\Ingestion\\Pipeline\\ChangeDetector::detect() / missing() / changedFields() / values() / published(); FIELDS = the fixed D-06 diff order"
  - "App\\Ingestion\\Pipeline\\MissingItemDetector::detect(SourceRun), called by IngestRun after health for succeeded runs"
  - "SourceItemRecorder::record() returns RecordOutcome; public act(), pendingDraft(), link()"
  - "DraftWriter::createChangeDraft(), replacePendingFromSource() (null when decided meanwhile), supersedeFromSource()"
  - "App\\Casts\\SourceDiff: event_drafts.diff read back in the fixed D-06 order (jsonb reorders keys)"
  - "DraftData::with(array) copy helper; raw_payload['auto_excerpt_hash'] on every ingested draft"
  - "events.cancelled_dates jsonb (ApproveDraft writes it; OccurrenceGenerator inserts listed dates as Cancelled)"
  - "tests/Pest.php: ingestFeed(), ingestLiveRun(), ingestAdmin(), ingestApprove(), ingestEditEvent()"
affects: [03-10 apply/ignore/confirm (diff format, baseline moves, cancelled_dates), 03-11 mark duplicate, 03-13 review UI (diff, parse errors, urgent), 03-15 run log counters, Phase 4 extractors (NormalisedFacts::cancelled → PIPE-07)]

actuals:
  tokens: 32000
  tasks: 3
  commits: 4
plan_head_before: 5c9673ed5ecb84c3ec977f07b3b488f614568cef
plan_head_after: d255351675c794b9896b5bccc6f7f7ac8d0a85b9

tech-stack:
  added: []
  patterns:
    - "Detect, then store, then act: the verdict is computed against the item's previous content_hash before the new content is stored"
    - "Tracked items (linked, or with a pending new draft) bypass title-rule and window buckets; only 'past' stops them"
    - "Deferred change detection: no pending draft AND content_hash ≠ accepted_hash routes an item through detection even on a byte-identical feed"
    - "Writer refuses a decided draft by returning null; the recorder re-detects once from fresh state instead of overwriting"
    - "Custom cast restores a fixed key order for jsonb objects"
    - "Detector reads occurrences through relations so unit tests can preload them (no DB)"

key-files:
  created:
    - app/Ingestion/Data/ChangeVerdict.php
    - app/Ingestion/Data/RecordOutcome.php
    - app/Ingestion/Pipeline/ChangeDetector.php
    - app/Ingestion/Pipeline/MissingItemDetector.php
    - app/Casts/SourceDiff.php
    - database/migrations/2026_10_18_100700_add_cancelled_dates_to_events.php
    - tests/Unit/Ingestion/ChangeDetectorTest.php
    - tests/Feature/Ingestion/UpdateDraftTest.php
    - tests/Feature/Ingestion/CancellationDraftTest.php
    - tests/Feature/Ingestion/MissingItemTest.php
    - tests/Feature/Publishing/CancelledDatesBeyondHorizonTest.php
  modified:
    - app/Ingestion/Pipeline/SourceItemRecorder.php
    - app/Ingestion/IngestRun.php
    - app/Review/DraftWriter.php
    - app/Review/Data/DraftData.php
    - app/Models/EventDraft.php
    - app/Models/Event.php
    - app/Publishing/Actions/ApproveDraft.php
    - app/Calendar/OccurrenceGenerator.php
    - tests/Pest.php
    - tests/Feature/Ingestion/SourceItemRecorderTest.php

key-decisions:
  - "Update diff = {field: {published, before, now}} over FIELDS (title, all_day, start_date, start_time, end_date, end_time, venue, price_type, family_friendly, cancelled, rrule, excluded_dates, moved_overrides, extra_dates). Times 'H:i', venue 'name, address' (published = venue name), lists as stored; title and venue compared normalised like the content hash"
  - "Cancellation diffs: whole event {cancelled: {published, before: false, now: true}}; dates {cancelled_keys: [ascending Y-m-d]} with target_recurrence_key = earliest; missing {status: {published: <event status>, before: active, now: missing}}"
  - "Newly cancelled dates are those in the feed's cancelled_keys but not the baseline's, from today (London) on, with no upper bound (cancelled_dates makes far ones safe to confirm)"
  - "Pending-draft folding: same proposal → none; different update → replace; cancellation replaces a pending update (update superseded, new cancellation draft); an update never replaces a pending cancellation; back to baseline (or no D-06 difference) → supersede"
  - "Hash differs but no D-06 field differs on a linked item (a past cancelled date, a date the source un-cancelled) → no draft and the baseline is not moved"
  - "An undecided, unlinked item with no pending draft that is not cancelled gets a new draft (e.g. after its draft was superseded for cancelled/missing and it came back); a cancelled one gets none"
  - "A linked item whose event link is gone (approved/duplicate outcome but no event_sources row) raises nothing"
  - "A never-seen STATUS:CANCELLED item is stored (status cancelled, review pending) with no draft; counted items_new + items_cancelled, drafts_created 0 (changes the 03-05 behaviour as 03-05 announced)"
  - "items_cancelled = items the source newly cancelled in this run (status became cancelled, or a cancel verdict); items_missing = items counted missing in this run (incremented), not only those reaching 2"
  - "Bucket for a known item: changed when raw_hash changed or a draft was written/replaced; otherwise unchanged. Tracked items moved beyond the window count as changed, not skipped_far_future"
  - "Pitfall 3 replace: venue_id kept while the feed's venue text is unchanged; 'Chosen by admin' category kept; description kept when it differs from raw_payload.auto_excerpt_hash (missing hash counts as edited, so pre-03-09 drafts never lose an edit)"
  - "Superseding is a system write in DraftWriter::supersedeFromSource (reviewed_at set, no reviewer); only pending drafts move"
  - "Missing detection upper bound = min(end of observed_until London day, now + horizon_days); lower bound strictly after now; 'is distinct from' on last_seen_run_id"
  - "Deferred item 1 (03-08) fixed with events.cancelled_dates rather than reading accepted_payload, because 03-10's Ignore moves the cancelled part of the baseline too: an ignored cancellation must not cancel the date"

patterns-established:
  - "Ingestion feature tests: ingestFeed($bodyOrClosure, $robots) registers one Http::fake per test and swaps answers; ingestEditEvent() edits one VEVENT by needle"

requirements-completed: [PIPE-06, PIPE-08]

coverage:
  - id: D1
    description: "Tracer: approved TC Yoga moved +1h → exactly one pending update draft (start_time/end_time diff, relevant_starts_at 10:00 UTC), zero cancellations, items_changed 1; same moved feed again → drafts_created 0"
    requirement: PIPE-06
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/UpdateDraftTest.php#raises one update draft with a published/before/now diff when an approved Town Council item moves an hour"
        status: pass
  - id: D2
    description: "Quiet description change, Pitfall 3 replace keeping venue/category/description, source excerpt taken when unedited, decided-during-replace, D-23 rejected items, deferred change on identical feed, beyond-window update, moved-override parse error on a linked series"
    requirement: PIPE-06
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/UpdateDraftTest.php"
        status: pass
      - kind: unit
        ref: "tests/Unit/Ingestion/ChangeDetectorTest.php"
        status: pass
  - id: D3
    description: "Whole-event cancellation (idempotent), ascending cancelled_keys with earliest target, past date → none, never-approved cancelled → none + item cancelled, pending new superseded, cancellation replaces pending update, cancellation + time change → cancellation only"
    requirement: PIPE-07
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/CancellationDraftTest.php"
        status: pass
  - id: D4
    description: "Missing after two complete runs (Baptist fixture), consecutive-only, reappearance supersedes, five non-complete run kinds, exact-now/observed_until/horizon boundaries, no detection when unsupported, listed-but-skipped identities never missing, pending new draft superseded"
    requirement: PIPE-08
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/MissingItemTest.php"
        status: pass
  - id: D5
    description: "Cancelled override beyond the horizon inserted as Cancelled when the sync reaches it; existing rows keep an admin restore"
    requirement: EXT-01
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/CancelledDatesBeyondHorizonTest.php"
        status: pass

duration: ~24min
completed: 2026-10-18
---

# Phase 3 Plan 09: Change, Cancellation and Missing-Item Drafts Summary

**When a known feed item changes, the run now raises the right draft. A moved time on an approved item gives one update draft with a published / before / now diff in the fixed D-06 order. STATUS:CANCELLED and newly cancelled series dates give one cancellation draft. Wording-only changes move the baseline quietly. Each item keeps at most one pending draft, and a replace keeps the admin's venue, category and edited description. Rejected items stay rejected until their facts change. An item missing from two consecutive complete runs, inside the observed window and the horizon, gives a cancellation draft. As an extra, a series date the source cancelled beyond the 90-day horizon now arrives as Cancelled when the nightly sync reaches it.**

## Performance

- **Duration:** ~24 min (10:13Z–10:37Z on 2026-10-18)
- **Tasks:** 3 (1 tracer, 2 TDD) plus the deferred-item fix
- **Files:** 11 created, 10 modified

## Accomplishments
- `ChangeDetector` gives one verdict per known item from the baseline, the pending draft and the event link. It reads only.
- `SourceItemRecorder` works in four steps:
  - it marks the item seen and handles a reappearance;
  - it sends tracked items (linked, or with a pending new draft) to detection without applying title rules or the window;
  - it detects, stores and acts;
  - it reports a `RecordOutcome`, so `drafts_created`, `drafts_replaced` and `items_cancelled` are now real counters.
- `DraftWriter` has three new methods:
  - `createChangeDraft` (sets event_id, diff, target key and relevant_starts_at; uses the event's venue when the venue is not changing);
  - `replacePendingFromSource` (Pitfall 3; returns null when the draft was decided meanwhile);
  - `supersedeFromSource`.
- `MissingItemDetector` counts an item only after a live, succeeded, complete run of a source that supports missing detection. It uses bound parameters and London bounds converted to UTC. At 2 it raises a cancellation or supersedes the pending draft.
- `SourceDiff` cast: jsonb sorts object keys (shortest first), so without it the diff came back as `end_time, start_time`.

## Task Commits

1. **Task 1 (tracer): moved approved TC item → one update draft**: `2708785` (feat)
2. **Task 2 (tdd): verdict rules and cancellation drafts**: `a82acfb` (feat)
3. **Task 3 (tdd): missing-item detection over complete runs**: `99ad347` (feat)
4. **Extra: cancelled dates beyond the horizon (deferred item 1 from 03-08)**: `d255351` (fix)

**Tracer gate:** after Task 1, I re-ran its `<verify>` (UpdateDraftTest + RunNowTracerTest, 5 passed), plus the Ingestion, Publishing, Review and Unit folders, before starting Task 2.

## Decisions Made
See `key-decisions` in the frontmatter. For 03-10:
- **Apply** reads `diff[field]['now']`. Times are `H:i`. Venue is the feed's "name, address" text, so it needs VenueMatcher or the admin's venue_id.
- **ConfirmSourceCancellation:**
  - for date cancellations, read `diff['cancelled_keys']`;
  - for a whole-event cancellation (STATUS:CANCELLED or missing), check that `target_recurrence_key` is null;
  - **append the confirmed keys to `events.cancelled_dates`**, so a date beyond the horizon is safe.
- **Ignore** of a missing-cancellation: the item stays `missing`. A reappearance makes it active again and supersedes any pending missing-cancellation.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] jsonb lost the fixed D-06 diff order**
- **Found during:** Task 1 (tracer assertion)
- **Fix:** new `App\Casts\SourceDiff` on `event_drafts.diff` puts keys back in `ChangeDetector::FIELDS` order on read.
- **Commit:** `2708785`

**2. [Rule 3 - Blocking] The recorder needed to report drafts, not just a bucket**
- **Issue:** IngestRun counted `drafts_created` as "bucket = new". That is wrong now that changed items create or replace drafts and a cancelled new item creates none.
- **Fix:** `record()` returns `RecordOutcome`. IngestRun is the only caller.
- **Commit:** `2708785`

**3. [Rule 1 - Bug] The detector queried occurrences directly**
- **Issue:** with direct queries the plan's unit test could not run without a database.
- **Fix:** it now reads `nextOccurrence` and `occurrences` through relations, which lazy-load in production and can be preloaded in tests.
- **Commit:** `a82acfb`

**4. Test adjustment (behaviour change the plan requires).** `SourceItemRecorderTest › marks a known item seen even when a new title rule now skips it` used an item with a pending new draft. Such items now bypass title rules (plan truth), so the test rejects the draft first. It still proves "seen even when skipped".

### Extra item (orchestrator request)
- **Deferred item 1 from 03-08 is resolved**, using `events.cancelled_dates` and an insert-only Cancelled status in `OccurrenceGenerator::sync()`.
- It is marked resolved in `deferred-items.md`.
- It adds one column, written only from `App\Publishing\Actions`.

### Judgement calls (unattended run)
- **Commits on `main`**, as instructed.
- **New files outside the plan list:**
  - `RecordOutcome.php` and `SourceDiff.php`, needed for the deviations above;
  - the migration and test for the extra item;
  - helpers in `tests/Pest.php`.
- **RED order:**
  - UpdateDraftTest's tracer could not pass before Task 1: no update drafts existed, and the 03-05 CHANGED branch only stored content.
  - CancelledDatesBeyondHorizonTest was run RED (`cancelled_dates` null) before the fix.
  - The Task 2 and 3 tests passed on the code written for Task 1 and Task 3, so they are characterisation tests. Mutations show they bite:
    - dropping the past-date filter: 2 failures;
    - dropping the Pitfall 3 venue keep: 1;
    - unhooking MissingItemDetector from IngestRun: 8;
    - dropping its is_complete guard: 1.
- **Concurrency:** the decided-during-replace case is tested at the writer and recorder level, by approving first and then acting with the stale draft. A real race would deadlock on lock order and PostgreSQL would abort one side:
  - the recorder locks item → draft;
  - ApproveDraft locks draft → item.

  Either outcome is safe: an aborted item counts as a parse error and is retried by the next run.

---

**Total deviations:** 3 auto-fixed, 1 test adjustment, 1 requested extra. **Impact:** the plan was delivered as specified. No packages were added.

## Known Stubs
None. (`.planning/WINDOWS.md` does not exist, so nothing was appended.)

## Threat Flags
None beyond the plan's register:
- T-03-25: one pending draft per item, plus idempotency tests.
- T-03-26: complete-run, observed-window and two-run rules, with tests for all five non-complete run kinds.
- T-03-27: Pitfall 3 test.

## Issues Encountered
- jsonb key order (see deviation 1). Nested override objects are compared with `toEqual` in tests for the same reason.
- The full suite reports one PHPUnit warning that predates this plan.

## Verification
- Plan verifies: UpdateDraftTest + RunNowTracerTest; ChangeDetectorTest + UpdateDraftTest + CancellationDraftTest; MissingItemTest. All green.
- Full `./vendor/bin/pest --testsuite=Unit,Feature`: **1433 passed**. PHPStan: 0 errors. Pint: clean.
- Acceptance greps:
  - `function replacePendingFromSource` = 1;
  - `ChangeDetector` in SourceItemRecorder = 2;
  - `MissingItemDetector` in IngestRun = 3.

## Requirements Traceability
- **PIPE-06 Complete:** update drafts only when the D-06 facts differ from the accepted baseline; unchanged raw content is skipped; one pending draft per item, replaced in place. Apply/Ignore baseline moves are REV-06 (03-10).
- **PIPE-08 Complete:** two consecutive complete runs, future start, inside the observed window, source supports missing detection.
- **PIPE-07 left Pending:**
  - Delivered here: the ICS `STATUS:CANCELLED` half, at master and override level.
  - Remaining: the `eventStatus: EventCancelled` half needs Phase 4's JSON-LD extractor. That extractor only has to set `NormalisedFacts::cancelled`, and no other cancellation code is needed.
- **EXT-01 left Pending** (wider scope, spread across the phase).

## Next Phase Readiness
- 03-10 can build ApplySourceUpdate, IgnoreSourceChange and ConfirmSourceCancellation on the diff formats above. ConfirmSourceCancellation must also append to `events.cancelled_dates`.
- 03-13 can show `diff` in the order the cast returns it, the draft's `parse_errors`, and `relevant_starts_at` for "Urgent".

## Self-Check: PASSED

All 11 created files exist. Commits 2708785, a82acfb, 99ad347 and d255351 are ancestors of HEAD.
