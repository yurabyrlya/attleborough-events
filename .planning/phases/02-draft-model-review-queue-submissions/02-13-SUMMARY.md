---
phase: 02-draft-model-review-queue-submissions
plan: 13
subsystem: ui
tags: [filament-5, review-queue, bulk-actions, tabs, filters, table-search]
status: complete

requires:
  - phase: 02-03
    provides: DraftPublishability::bulkBlockerKeys() / skipMessage(), ApproveDraft
  - phase: 02-06
    provides: RejectDraft, RejectReason
  - phase: 02-12
    provides: EventDraftsTable, ListEventDrafts, ReviewActions, ReviewQueue::applyOrder
provides:
  - EventDraftsTable::approveSelected() (named, counted skips) and rejectSelected() bulk actions
  - Pending (default) / Rejected / Approved / All tabs with counts on ListEventDrafts
  - Filters for kind, source (origin), quality band, possible duplicate and an inclusive event-date range
  - Full queue column set, table search over title/reference/organiser/typed venue, Pending empty state
  - ReviewActions::rejectFields() / rejectInput(), shared by the row and bulk reject dialogs
affects: [02-14, 02-15, 02-16]

tech-stack:
  added: []
  patterns:
    - "Bulk skip = reportBulkProcessingFailure(firstKey, message: fn (int $failureCount) => DraftPublishability::skipMessage(...)). The message closure must use Filament's injected parameter names ($failureCount / $count); a closure written as fn (int $n) would fail to resolve"
    - "Status tabs use excludeQueryWhenResolvingRecord(), and ListEventDrafts drops the tab scope while building the query for explicitly ticked rows, so a draft decided elsewhere still reaches the domain action and is reported as 'already reviewed'"

key-files:
  created:
    - tests/Feature/Filament/BulkReviewTest.php
    - tests/Feature/Filament/ReviewQueueTableTest.php
  modified:
    - app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php
    - app/Filament/Resources/EventDrafts/Pages/ListEventDrafts.php
    - app/Filament/Resources/EventDrafts/Actions/ReviewActions.php

key-decisions:
  - "Bulk approve checks review_status before bulkBlockerKeys(). A draft that is no longer pending is reported as 'already reviewed', not as whatever blocker it happens to have"
  - "Filament scopes the selected-rows query by the active tab. Left alone, a ticked draft decided in another tab would vanish from the run without being counted. ListEventDrafts::getSelectedTableRecordsQuery() therefore ignores the tab for explicitly ticked rows. 'Select all' still means everything on the current tab"
  - "A hidden-by-default Status column is kept, so the All tab can still show status when the admin turns it on. The visible columns match the must_haves order exactly"
  - "The empty state reads 'Nothing waiting for review' only on the Pending tab. Other empty tabs say 'No drafts here'"

patterns-established:
  - "Bulk review actions live in EventDraftsTable and only call ApproveDraft / RejectDraft, one record per domain transaction (Filament's action-level transaction stays off)"

requirements-completed: [REV-08, REV-09]

actuals:
  tokens: 9631
  tasks: 2
  commits: 4
plan_head_before: 85669f84681401a00f7ced41ce08eca3b4dfcbd6
plan_head_after: 6e1e266f1ee18bb7d360b7732a8f4e695b27e58f

coverage:
  - id: D1
    description: "Bulk approve of six drafts publishes the 2 clean ones and names the Low quality, parse errors, possible duplicate and no confirmed venue skips; the skipped drafts stay pending"
    requirement: REV-08
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/BulkReviewTest.php#approves the publishable drafts and names every skipped draft by its reason"
        status: pass
    human_judgment: false
  - id: D2
    description: "Skip counts add up to the drafts that were not approved; unscored counts as Low; a draft with two problems is counted once"
    requirement: REV-08
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/BulkReviewTest.php#adds up the skip counts to the drafts that were not approved"
        status: pass
    human_judgment: false
  - id: D3
    description: "Repeats text without a rule is skipped as 'repeat rule not set', even if acknowledged"
    requirement: REV-08
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/BulkReviewTest.php#skips a draft whose organiser says it repeats but has no rule"
        status: pass
    human_judgment: false
  - id: D4
    description: "REV-01 concurrency: a draft decided elsewhere is reported as 'already reviewed', including after it has left the Pending tab, and the rest are approved"
    requirement: REV-01
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/BulkReviewTest.php#reports a draft approved elsewhere first as already reviewed and approves the rest"
        status: pass
      - kind: integration
        ref: "tests/Feature/Filament/ReviewQueueTableTest.php#still reports a selected draft as already reviewed after it left the Pending tab"
        status: pass
    human_judgment: false
  - id: D5
    description: "Bulk reject with an optional reason and note rejects Low and parse-error drafts too; non-pending drafts are reported as already reviewed"
    requirement: REV-08
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/BulkReviewTest.php#bulk rejects every selected pending draft, including Low-quality and parse-error drafts"
        status: pass
      - kind: integration
        ref: "tests/Feature/Filament/BulkReviewTest.php#reports an already-approved draft in a bulk reject as already reviewed"
        status: pass
    human_judgment: false
  - id: D6
    description: "Tabs Pending (default) / Rejected / Approved / All with counts; filters kind, source, band, duplicate, inclusive date range (dateless excluded only when a range is set)"
    requirement: REV-09
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/ReviewQueueTableTest.php"
        status: pass
    human_judgment: false
  - id: D7
    description: "Column order, 'Other (check)', '(not confirmed)', London received time, table search, empty state, escaped titles"
    requirement: REV-09
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/ReviewQueueTableTest.php"
        status: pass
    human_judgment: false
  - id: D8
    description: "Visual check of the queue table (tabs, filter panel, bulk dialog, notification readability) in a real browser"
    requirement: REV-09
    verification:
      - kind: manual_procedural
        ref: "docs/uat/browser-uat.md review-queue flow (02-13..02-16 browser UAT)"
        status: unknown
    human_judgment: true

duration: 8min
completed: 2026-10-17
---

# Phase 2 Plan 13: Bulk review, tabs and filters Summary

**The review queue now handles drafts in bulk:**

- **Approve selected** publishes every clean draft through ApproveDraft, one transaction per draft. Each draft it skips is counted under one reason, for example "1 skipped: Low quality".
- **Reject selected** takes one optional reason and note for the whole selection.
- **Tabs:** Pending, Rejected, Approved and All, each with a count.
- **Filters:** kind, source, quality band, possible duplicate and event date.
- **Columns and search:** the full column set, plus one search box over title, reference, organiser and typed venue.

## Performance

- **Duration:** ~8 min
- **Started:** 2026-10-17T20:42:37Z
- **Completed:** 2026-10-17T20:49:30Z
- **Tasks:** 2 (1 tracer + 1 TDD)
- **Files:** 5 (2 created, 3 modified)

## Accomplishments

**Approve selected**
- Dialog text: "Approve the selected drafts?" / "Drafts with problems are skipped and listed afterwards." Buttons: "Approve" / "Keep as is".
- A draft that is no longer pending is reported as `already_reviewed`.
- Otherwise the first `bulkBlockerKeys()` key is reported through `reportBulkProcessingFailure()`.
- Clean drafts go to ApproveDraft. If ApproveDraft throws, it is mapped back to a key: InvalidReviewTransition or ModelNotFound → `already_reviewed`, DraftNotPublishable → `blocked`.
- Result title: "Approved {n} of {total}. The rest were skipped:", followed by one "{count} skipped: {reason}" line per reason.

**Reject selected**
- Uses the same optional reason and note fields as the row action. Buttons: "Reject drafts" / "Keep as is".
- Drafts that are no longer pending are reported as "already reviewed".

**Selection and tabs**
- There is no record-selectability rule, so every draft can be ticked (Pitfall 6).
- Tabs: Pending (default), Rejected, Approved, All, with counts from a single grouped query.
- The tab scope is skipped in two cases:
  - when Filament looks up the record for a row action;
  - when it builds the query for rows the admin ticked explicitly.

**Filters**
- Kind, Source (origin, D-20), Quality, Possible duplicate (ternary on `duplicate_of_event_id`).
- Event date From/Until, inclusive at both ends, on the stored London wall-clock `start_date`. Active-filter indicators show the chosen dates.

**Columns and search**
- Columns in order: Quality, Score "n/100", Title, When, Venue (confirmed name, or "{typed} (not confirmed)"), Category ("Other (check)" when flagged), Source, Kind, Received (Europe/London, sortable).
- Status is available as a toggleable column, hidden by default.
- Venue and category are eager-loaded.
- Search covers title, reference, organiser_name and venue_name.

**Empty state**
- The Pending tab shows "Nothing waiting for review" / "New submissions appear here. Manual entries are published straight away."

## Task Commits

1. **Task 1 (tracer): bulk approve with named skips, bulk reject.** RED `cb6e81e`, GREEN `288703e`
2. **Task 2 (TDD): tabs, filters, columns, search, empty state.** RED `e8b87c4`, GREEN `6e1e266`

## TDD Gate Compliance

- **Task 1:**
  - RED `cb6e81e`: all 8 tests failed with "Action [approveSelected]/[rejectSelected] not found on table".
  - GREEN `288703e`: 8/8 passed.
  - Tracer gate: the `<verify>` command was re-run end to end before expanding. It passed, along with DraftDetailTest and ReviewQueueTracerTest (40/40).
- **Task 2:**
  - RED `e8b87c4`: 12 of 13 failed. Only the escaping test passed, because titles were already escaped.
  - GREEN `6e1e266`: 13/13 passed.
  - Check that the tab fix is needed: with the selected-rows tab bypass switched off, 3 race tests failed. These were the 2 in BulkReviewTest and the one in ReviewQueueTableTest. Switching it back on made all 21 pass.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Tabs would silently drop ticked drafts that were decided elsewhere**
- **Found during:** Task 2
- **Issue:** Filament's `getSelectedTableRecordsQuery()` applies the active tab's scope. Suppose a ticked draft is approved or rejected in another tab before the admin clicks. On the Pending tab it would simply be missing from the run: no count, no message. That breaks the REV-08 prohibition on silent skips and the REV-01 concurrency truth.
- **Fix:**
  - Tabs use `excludeQueryWhenResolvingRecord()`.
  - `ListEventDrafts` overrides `getSelectedTableRecordsQuery()` and `modifyQueryWithActiveTab()`, so the tab scope is skipped for explicitly ticked rows.
  - "Select all" mode (tracking deselected rows) keeps the tab scope.
- **Files modified:** ListEventDrafts.php
- **Commit:** `6e1e266`

**2. [Rule 1 - Bug] Plan's message closure `fn (int $n)` would not resolve**
- Filament evaluates the message closure with the named parameters `count`, `failureCount`, `total`, `totalCount` and `isAll`. The closure therefore uses `fn (int $failureCount)`.
- **Commit:** `288703e`

**3. [Rule 2 - Correctness] Status checked before blockers in bulk approve**
- An already-approved or rejected draft is reported as "already reviewed" rather than under a blocker key it might also have.
- **Commit:** `288703e`

**4. [Rule 3 - Blocking] Shared reject fields (file outside `files_modified`)**
- `ReviewActions::rejectFields()` and `rejectInput()` were extracted so the bulk dialog asks exactly what the row action asks.
- The row action's behaviour is unchanged. DraftDetailTest still passes.
- **Commit:** `288703e`

**5. [Test adjustment] Event count in the six-draft case**
- The duplicate draft needs an existing event (`duplicate_of_event_id` is a foreign key), so the test asserts `Event::count() === $eventsBefore + 2` rather than a literal 2.
- The intent, exactly two events created, is unchanged.

**6. [Process] Commits on `main`**
- `git.base-branch --is-protected main` returns true, and `allow_default_branch_commits` is unset.
- The orchestrator explicitly dispatched sequential mode on the main working tree with `branching_strategy: none`, and every earlier phase-2 plan committed to `main`. I followed that instruction and did not push.

## Flagged Assumptions (from plan)

- The REV-08 and REV-09 edge rows stay `unclassified`. They are covered by the named-skip and counts tests (REV-08) and by the inclusive date-range test (REV-09).
- "Source" is `EventOrigin` until Phase 3 adds `source_id` (D-20, add-alongside debt).

## Verification

- `pest BulkReviewTest`: 8 passed
- `pest ReviewQueueTableTest BulkReviewTest`: 21 passed (153 assertions)
- Full Unit+Feature suite: **961 passed** (4866 assertions). The `warnings: 1` existed before this plan.
- `vendor/bin/phpstan analyse --memory-limit=1G`: 0 errors. `vendor/bin/pint --dirty`: passed.
- Acceptance greps in EventDraftsTable.php: `reportBulkProcessingFailure` 1, `bulkBlockerKeys` 2, `checkIfRecordIsSelectableUsing` 0. `getTabs` in ListEventDrafts.php: 1.

## Threat Flags

None beyond the plan's register.
- **T-02-35:** per-record `bulkBlockerKeys()`, plus ApproveDraft's own re-check on the server, with every skip reported.
- **T-02-36:** one domain transaction per draft, and Filament's action transaction stays off. Covered by the race tests.

## Next Phase Readiness

- 02-14 (edit-and-approve) and 02-15 (Approve & next) can keep using `ReviewActions` and `ReviewQueue`. The queue now opens on the Pending tab by default.

## Self-Check: PASSED
