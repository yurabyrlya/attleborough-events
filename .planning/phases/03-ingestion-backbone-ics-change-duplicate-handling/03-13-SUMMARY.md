---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 13
subsystem: review-ui
tags: [filament, review, source-changes, duplicates, blade, accessibility, xss, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-09 update/cancellation drafts + SourceDiff cast; 03-10 ApplySourceUpdate / IgnoreSourceChange / ConfirmSourceCancellation; 03-11 duplicate columns, DuplicateMatch::formatScore(), MarkDraftDuplicate / RecordNotDuplicate; 03-12 ApproveAllLikeThis, ReviewQueue::isUrgent"
provides:
  - "Review page: 'Changes from {source}' three-column diff (Field | Published now | Source before | Source now) with Apply changes / Ignore"
  - "Review page: 'Cancelled at {source}' (dates or 'The whole event') with Confirm cancellation / Ignore; 'Cancelled at source' dates on new series drafts"
  - "Review page: side-by-side 'Possible duplicate' panel for event AND draft matches with reason, score and link; Mark as duplicate / Not a duplicate"
  - "Review page: Urgent badge (D-09) and Approve all like this (D-03) in the header"
  - "Kind-aware actions everywhere: Approve / Reject / Save & approve / A key only for new-event drafts; Apply / Confirm / Ignore in the queue table too; bulk reject skips source changes"
  - "App\\Filament\\Resources\\EventDrafts\\Schemas\\DiscoveredDraftDetails (diffRows, cancelledDates, sourceCancelledDates, duplicate, duplicateReason, duplicateTitle, duplicateUrl, date, time)"
  - "ReviewActions::applyChanges / confirmCancellation / ignore / markDuplicate / notDuplicate / approveAllLikeThis / canMarkDuplicate"
  - "Queue 'Possible duplicate' filter covers duplicate_of_event_id and duplicate_of_draft_id"
  - "EventForm 'Next dates' preview skips the record's excluded_dates"
affects: [03-17 UAT (browser flows for the review page), Phase 4 extractors (same review UI for RSS/TEC/JSON-LD drafts)]

estimate:
  tokens: 75000
  tasks: 2
actuals:
  tokens: 22450
  tasks: 2
  commits: 3
plan_head_before: 7ecbf2a7c7526037c1ac41ad2e5a109f45045fba
plan_head_after: eb03cc60219715b0b1586d71f101afc0519cf67f

tech-stack:
  added: []
  patterns:
    - "Presenter class (DiscoveredDraftDetails) turns diff/duplicate data into plain strings; Blade ViewEntries render them with {{ }} only"
    - "Kind-aware visibility: pendingOfKind($record, $livewire, DraftKind ...) = kind check + the existing shownStatus guard"
    - "run(..., refusalTitle:) maps DraftNotPublishable to a per-action danger notification title, body = the domain's plain message"
    - "One Action definition shared by the queue row and the review page header (approveAllLikeThis), with openNext only on the review page"

key-files:
  created:
    - app/Filament/Resources/EventDrafts/Schemas/DiscoveredDraftDetails.php
    - resources/views/filament/resources/event-drafts/entries/source-diff.blade.php
    - resources/views/filament/resources/event-drafts/entries/duplicate-match.blade.php
    - tests/Feature/Filament/SourceReviewActionsTest.php
    - tests/Feature/Filament/DuplicatePanelTest.php
    - tests/Feature/Filament/NextDatesExcludedTest.php
  modified:
    - app/Filament/Resources/EventDrafts/Actions/ReviewActions.php
    - app/Filament/Resources/EventDrafts/Schemas/EventDraftInfolist.php
    - app/Filament/Resources/EventDrafts/Pages/ReviewEventDraft.php
    - app/Filament/Resources/EventDrafts/Pages/EditEventDraft.php
    - app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php
    - app/Filament/Resources/Events/Schemas/EventForm.php
    - app/Review/DraftPublishability.php
    - resources/views/filament/resources/event-drafts/pages/review-event-draft.blade.php
    - resources/views/filament/resources/event-drafts/keyboard-help.blade.php
    - .planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/deferred-items.md

key-decisions:
  - "Reject is hidden for update/cancellation drafts (page and table) rather than routed to Ignore; the R key opens Ignore on those drafts ('Ignore (R)'), Reject on new drafts (03-10 handoff)"
  - "Bulk reject skips update/cancellation drafts under a new skip key 'source_change' ('N skipped: source change (use Apply, Confirm or Ignore)') so no baseline-less reject is possible from the queue either"
  - "Apply changes, Confirm cancellation, Ignore and Mark as duplicate open the next pending draft on the review page (Approve & next behaviour); Not a duplicate keeps the admin on the draft (it stays pending)"
  - "Mark as duplicate is visible when the match is a published event or an approved draft with an event (what MarkDraftDuplicate accepts); hidden for a pending or rejected draft match, with a hint in the panel"
  - "The duplicate panel compares the event's occurrence on the draft's date when there is one (the detector compares occurrences), else the event's own start"
  - "Approve all like this moved from EventDraftsTable into ReviewActions so the table row and the review header share one definition; EventDraftsTable::approveAllLikeThis() delegates"
  - "'Before you can publish' is hidden for source change drafts (it only said 'Only new-event drafts can be approved here')"
  - "The draft's origin entry in the Source section is relabelled 'Origin' and the source name added as 'Source' (matches 03-12's table columns)"
  - "03-08 deferred item 2 fixed (trivial, contained): EventForm::nextDates() receives the record's excluded_dates"
  - "REV-05, REV-06, REV-07 and PIPE-05 marked Complete: the domain halves shipped in 03-09..03-11 and this plan adds the last missing part, the admin UI. 03-17 still re-checks REV-05/06/07 in browser UAT"

patterns-established:
  - "Review-page Livewire tests for discovered drafts build drafts through ingestion (ingestFeed + ingestLiveRun + ingestApprove) and assert the domain result after callAction"

requirements-completed: [REV-05, REV-06, REV-07, PIPE-05]

coverage:
  - id: D1
    description: "Tracer: moved TC Yoga update draft → review page shows Published now / Source before / Source now, 10am / 11am / 12pm → Apply changes → event 11:00 (raw SQL 11:00 UTC on the GMT day), 'Changes applied'; stale tab click → 'This draft was already reviewed'; venue still needed → 'Can't apply this change' with VENUE_NEEDED"
    requirement: REV-06
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/SourceReviewActionsTest.php#shows the three-column diff and applies it: the event gets the new time"
        status: pass
  - id: D2
    description: "Ignore on an update draft: rejected/Ignored, event unchanged, baseline moved; A key on update/cancellation drafts notifies 'Use Apply changes for source updates.' / 'Use Confirm cancellation for source cancellations.'; R maps to Ignore"
    requirement: REV-06
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/SourceReviewActionsTest.php"
        status: pass
  - id: D3
    description: "Folk Club 4 Nov cancellation: page lists 'Wed 4 Nov' → Confirm cancellation → occurrence Cancelled, public event page shows Cancelled after Wed 4 Nov; whole-event cancellation shows 'The whole event'; new series draft lists 'Cancelled at source'"
    requirement: REV-07
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/SourceReviewActionsTest.php#lists the cancelled date and confirms it: the public page shows Cancelled next to 4 Nov"
        status: pass
  - id: D4
    description: "Urgent badge on the review page within 48 h only; queue table offers Apply/Ignore instead of Reject for source changes; bulk reject skips source changes"
    requirement: REV-07
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/SourceReviewActionsTest.php"
        status: pass
  - id: D5
    description: "Duplicate panel: 25 Oct TC Yoga vs published 'Yoga with [name removed]' side by side (date, time, venue, title), 'Same date, similar venue and title (similarity 0.78)', event link; Mark as duplicate → status duplicate, Event count unchanged; Not a duplicate → warning gone; 'Same web address'; pending-draft match shows its reference link and no Mark as duplicate; filter covers both columns"
    requirement: REV-05
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/DuplicatePanelTest.php"
        status: pass
  - id: D6
    description: "Script-tag feed title rendered escaped in the duplicate panel and the diff"
    requirement: PIPE-05
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/DuplicatePanelTest.php#escapes a script tag from a feed in the duplicate panel and the diff"
        status: pass
  - id: D7
    description: "Approve all like this on the review page: 'Approved 2 of 3.' + '1 skipped: possible duplicate', then the next draft opens; hidden on manual drafts"
    requirement: PIPE-05
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/DuplicatePanelTest.php#offers Approve all like this on the review page and counts the result like the table"
        status: pass
  - id: D8
    description: "Edit page: excerpt of a discovered new draft saved and published with Save & approve (D-15); update draft has Save only, and a venue picked there lets Apply succeed"
    requirement: REV-06
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/DuplicatePanelTest.php"
        status: pass
  - id: D9
    description: "Events edit form Next dates preview leaves out an excluded date (03-08 deferred item 2)"
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/NextDatesExcludedTest.php"
        status: pass

duration: ~25min
completed: 2026-10-18
---

# Phase 3 Plan 13: Review Page for Discovered Drafts Summary

**The review page now carries every discovered-draft decision. An update draft leads with a "Changes from {source}" table (Field | Published now | Source before | Source now, London dates and times) and offers Apply changes and Ignore. A cancellation draft lists the cancelled dates, or "The whole event", and offers Confirm cancellation and Ignore. A flagged draft shows its match side by side with the reason, the similarity and a link, for a published event or another pending draft, with Mark as duplicate and Not a duplicate. Urgent changes carry a red "Urgent" badge, and the page header offers Approve all like this. Approve, Reject, Save & approve and the A key now belong to new-event drafts only, so a source change can no longer be rejected without moving its baseline.**

## Performance

- **Duration:** about 25 min (12:18Z to 12:43Z on 2026-10-18)
- **Tasks:** 2 (1 tracer, 1 TDD), plus one small deferred fix
- **Files:** 6 created, 10 modified

## Accomplishments
- `DiscoveredDraftDetails` turns the data into plain strings:
  - `diffRows()` reads the 03-09 diff in the SourceDiff order. Dates come out as "Sun 25 Oct", times as "10am" / "7:30pm", booleans as Yes/No, price types as labels and date lists joined. Anything that is not the expected shape is shown as the text it is. Carbon 3's `createFromFormat` throws on a format mismatch, so it is guarded with a regex and a try/catch.
  - `cancelledDates()` handles the `{cancelled_keys}`, `{cancelled}` and `{status: missing}` shapes. `sourceCancelledDates()` reads `raw_payload.cancelled_keys`.
  - `duplicate()` returns the draft beside its match: the event's occurrence on the draft's date, or the other draft. It also gives the reason in words and the admin URL.
- Two Blade ViewEntries:
  - `source-diff` is a table with a caption and `th scope`.
  - `duplicate-match` is a two-column grid that stacks on narrow screens.

  Both use escaped output only, and a test covers a script tag in each.
- `ReviewActions`:
  - New actions: `applyChanges`, `confirmCancellation`, `ignore`, `markDuplicate`, `notDuplicate` and `approveAllLikeThis`. Each goes through the existing `run()`, which now takes a per-action refusal title, and keeps the shownStatus stale-click guard.
  - The queue table also gets Apply, Confirm and Ignore.
- `ReviewEventDraft`:
  - pressing A on a source change points the admin at the right button;
  - `rejectActionName()` sends R to Ignore on source changes;
  - "Before you can publish" is hidden for source changes.
- `EditEventDraft` offers Save & approve for new drafts only. Update drafts get Save, which is how the admin picks a venue before Apply.
- Queue:
  - the "Possible duplicate" filter now covers both duplicate columns;
  - bulk reject skips source changes under the counted reason "source change (use Apply, Confirm or Ignore)".

## Task Commits

1. **Task 1 (tracer): diff + Apply/Ignore, cancellation + Confirm/Ignore, Urgent badge, kind-aware actions:** `dbe2ba1` (feat)
2. **Task 2 (TDD): duplicate panel, Mark as duplicate / Not a duplicate, Approve all like this on the page, edit-page rules, escaping:** `2ecda42` (feat)
3. **03-08 deferred item 2: Next dates preview skips excluded dates:** `eb03cc6` (fix)

**Tracer gate (unattended):** after Task 1, I re-ran its `<verify>` (SourceReviewActionsTest, 12/12) and then the Filament and Review folders (431 passed) and PHPStan before starting Task 2.

## Orchestrator handoffs addressed
1. **03-10, Reject on change drafts:**
   - Reject is hidden for update and cancellation drafts on the page and in the table.
   - Those drafts get Apply changes / Confirm cancellation and Ignore instead.
   - Bulk reject skips them.
   - Tests: *offers Apply, Ignore and Confirm in the queue table instead of Reject for source changes*; *skips source change drafts in bulk reject…*; the page assertions in the diff and cancellation tests.
2. **03-11 deferred item 3:**
   - The panel reads both `duplicate_of_event_id` and `duplicate_of_draft_id`, and shows `duplicate_reason` with `formatScore()`.
   - Mark as duplicate and Not a duplicate are wired up.
   - The filter and the Checks entry cover both columns.
   - Marked resolved in deferred-items.md. Item 4 (Academy re-timed copies) stays deferred.
3. **03-12, review-page parity:**
   - Approve all like this is in the review page header, with the same counted notification, then the next draft opens.
   - The Urgent badge is in the review page summary.
4. **03-08 deferred item 2:** it was a trivial, contained fix, so it is done, with a test, and marked resolved.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Bulk reject could still reject source changes without moving the baseline**
- **Found during:** Task 1 (handoff 1)
- **Fix:** bulk reject skips non-new drafts under the new `source_change` skip key in `DraftPublishability::SKIP_REASONS`.
- **Files:** app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php, app/Review/DraftPublishability.php
- **Commit:** `dbe2ba1`

**2. [Rule 1 - Bug] The "Before you can publish" box showed on source change drafts**
- **Issue:** the box listed "Only new-event drafts can be approved here.", which is meaningless next to Apply or Confirm.
- **Fix:** `ReviewEventDraft::blockers()` returns nothing for non-new kinds.
- **Commit:** `dbe2ba1`

**3. Supporting files outside the plan's list:**
- `DiscoveredDraftDetails.php` keeps formatting logic out of Blade.
- `EventDraftsTable.php` holds the filter, the delegation and the bulk skip.
- `review-event-draft.blade.php` and `keyboard-help.blade.php` cover the R key.
- `DraftPublishability.php` holds the skip key.
- `EventForm.php` holds handoff 4.

### Judgement calls (unattended run)
- **Commits on `main`**, as instructed. Only my own files were staged.
- **Form actions:** they are not reachable through `assertActionExists` on EditRecord in Filament 5, so the edit-page tests check the rendered "Save & approve" label and call `save` / `saveAndApprove` directly.
- **RED order:** both test files were run RED before the code (Task 1: 11 of 12 failing; Task 2: 9 of 10). NextDatesExcludedTest also failed first, on the excluded date showing.

**Total deviations:** 2 auto-fixed, plus supporting edits. **Impact:** the plan was delivered as specified. No packages, migrations or schema changes.

## Known Stubs
None. `.planning/WINDOWS.md` does not exist, so nothing was appended.

## Threat Flags
None beyond the plan's register:
- T-03-36: escaped Blade only, with script-tag tests on the panel and the diff.
- T-03-37: every new action keeps the shownStatus guard, and the domain actions re-check on locked rows. A stale Apply is tested.

## Issues Encountered
None.

## Verification
- `pest tests/Feature/Filament/SourceReviewActionsTest.php`: 12 passed.
- `pest DuplicatePanelTest + SourceReviewActionsTest + ApproveAllLikeThisTest`: 29 passed.
- Full `./vendor/bin/pest --testsuite=Unit,Feature`: **1636 passed**.
- PHPStan: 0 errors. Pint: clean. `npm run build`: ok (the admin theme picks up the new Blade classes).
- Acceptance greps:
  - `ApplySourceUpdate`, `ConfirmSourceCancellation` and `IgnoreSourceChange` in ReviewActions: each 1 or more;
  - "Published now" in source-diff.blade.php: 1;
  - "Not a duplicate" in duplicate-match.blade.php and ReviewActions: present.

## Next Phase Readiness
- 03-17 (UAT) can run the review-page flows in the browser: diff, Apply, Ignore, Confirm, the duplicate panel, Approve all like this. The new UI flows are not yet in `docs/uat/browser-uat.md`; that belongs to 03-17's UAT pass.

## Self-Check: PASSED

All 6 created files exist. Commits dbe2ba1, 2ecda42 and eb03cc6 are ancestors of HEAD.
