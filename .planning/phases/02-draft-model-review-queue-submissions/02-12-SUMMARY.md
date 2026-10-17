---
phase: 02-draft-model-review-queue-submissions
plan: 12
subsystem: ui
tags: [filament-5, review-queue, infolist, global-search, keyset-pagination, postgres, xss]
status: complete

requires:
  - phase: 02-03
    provides: ApproveDraft, DraftPublishability::blockers(), EventDraftFactory states
  - phase: 02-06
    provides: RejectDraft, ReopenDraft, RejectReason enum
  - phase: 02-08
    provides: VenueMatcher::suggest(), quality score/band/reasons on every draft write
provides:
  - App\Review\ReviewQueue (static applyOrder(), query(), next(), previous()) built on one D-09 sort key
  - EventDraftResource (Review group, "Review queue", pending badge, no create, no delete), index + /{record}/review pages
  - EventDraftsTable (tracer columns, defaultSort via ReviewQueue::applyOrder, row → review page, reject/reopen row actions)
  - ReviewEventDraft page (InteractsWithRecord, "Before you can publish" blockers section, infolist, shownStatus snapshot)
  - EventDraftInfolist::configure() with every REV-01 item
  - ReviewActions::approve()/reject()/reopen()/rawContent(), forReviewPage(), forTable(), KEEP_LABEL, ALREADY_REVIEWED
  - LondonFormatter::wallClock() for stored local schedules ("Sat 24 Oct, 7:30pm")
  - Global search on drafts (title, reference, organiser_name, venue_name) and events (title, organiser_name)
affects: [02-13, 02-14, 02-15, 02-16]

actuals:
  tokens: 18307
  tasks: 3
  commits: 7
plan_head_before: 36fb1e16dc8a5e254f5c8eb1c39b84025f5eaf43
plan_head_after: 6d498492de321e91dfe2d00190f8046a5ee282bf

tech-stack:
  added: []
  patterns:
    - "Queue order = one SORT_KEY constant; ORDER BY uses the plain expressions, the next()/previous() row-value keyset uses the same list with nulls coalesced inside their flag group"
    - "Keyset bound values come from a subquery over the current draft's own row (only its id is bound), so the comparison uses exactly the stored values"
    - "Review page header actions are visible according to the status the page last showed (ReviewEventDraft::$shownStatus), so a stale click runs the domain action and reports 'This draft was already reviewed' rather than being silently dropped"
    - "Organiser links shown in the admin go through a ViewEntry (SafeLinks::http, rel=\"nofollow noopener noreferrer\") because TextEntry::url() cannot set rel"

key-files:
  created:
    - app/Review/ReviewQueue.php
    - app/Filament/Resources/EventDrafts/EventDraftResource.php
    - app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php
    - app/Filament/Resources/EventDrafts/Schemas/EventDraftInfolist.php
    - app/Filament/Resources/EventDrafts/Actions/ReviewActions.php
    - app/Filament/Resources/EventDrafts/Pages/ListEventDrafts.php
    - app/Filament/Resources/EventDrafts/Pages/ReviewEventDraft.php
    - resources/views/filament/resources/event-drafts/pages/review-event-draft.blade.php
    - resources/views/filament/resources/event-drafts/raw-content.blade.php
    - resources/views/filament/resources/event-drafts/entries/safe-link.blade.php
    - tests/Feature/Filament/ReviewQueueTracerTest.php
    - tests/Feature/Review/ReviewQueueOrderTest.php
    - tests/Feature/Filament/DraftDetailTest.php
    - tests/Feature/Filament/GlobalSearchTest.php
  modified:
    - app/Support/LondonFormatter.php
    - app/Filament/Resources/Events/EventResource.php

key-decisions:
  - "ReviewQueue keyset compares against a subquery over the current draft's row instead of PHP-bound values: no timestamp-precision or boolean-binding drift, and a just-approved draft still yields the following pending draft. If the current draft no longer exists, next()/previous() return null"
  - "Review page visibility follows a per-page status snapshot (shownStatus), so a draft decided in another tab still shows the buttons the admin sees and a click reports 'This draft was already reviewed' (REV-01 idempotency truth). It resyncs after every action"
  - "The queue table lists drafts of every status for now (so rejected drafts can be reopened from the table). 02-13 adds the Pending / Rejected / Approved tabs"
  - "EventDraftResource and EventResource override canAccess() with the AdminGate rule, so global search and the pages refuse a non-admin even outside the panel middleware"
  - "Raw content is a header action whose modal shows raw_payload key/value pairs as escaped text. A manual draft says 'No raw content: entered by admin'; a submission without a payload says 'No raw content was kept'"
  - "Duplicate and published-event links go to the event's admin edit page, so hidden and cancelled events stay reachable"

patterns-established:
  - "ReviewActions::run() maps InvalidReviewTransition|ModelNotFoundException → danger 'This draft was already reviewed', DraftNotPublishable → danger \"Can't publish yet\" with the blockers as a plain-text body"
  - "Reject from the review page redirects to the queue; from the table it stays put"

requirements-completed: [REV-01, REV-04, ADM-06]

coverage:
  - id: D1
    description: "Admin opens the Review queue, opens a draft and publishes it through ApproveDraft; it appears on /"
    requirement: REV-01
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/ReviewQueueTracerTest.php#opens the review page, approves the draft and shows it on the public calendar"
        status: pass
    human_judgment: false
  - id: D2
    description: "D-09 order (null date last, null time before 00:00, ties by created_at then id) is identical in the table, ReviewQueue::query() and next()/previous() walks"
    requirement: REV-01
    verification:
      - kind: integration
        ref: "tests/Feature/Review/ReviewQueueOrderTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "Approve is disabled while blockers exist and the blockers are listed under 'Before you can publish'"
    requirement: REV-01
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/ReviewQueueTracerTest.php#lists the blockers and disables approve while the draft has no venue"
        status: pass
    human_judgment: false
  - id: D4
    description: "Review page shows every REV-01 item (kind, status, reference, band → n/100 → reasons, fields, recurrence/One-off, repeats text, venue confirmed/typed/suggested, organiser, source with a safe link, extraction method, category + Check category, parse errors, duplicate link)"
    requirement: REV-01
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/DraftDetailTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "Reject with an optional reason and note (Reject draft / Keep as is), from the page or the table; a rejected draft shows reason, note and Reopen, which returns it to pending"
    requirement: REV-04
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/DraftDetailTest.php#rejects with a reason and a note"
        status: pass
      - kind: integration
        ref: "tests/Feature/Filament/DraftDetailTest.php#shows a rejected draft with its reason, note and a Reopen action that returns it to pending"
        status: pass
    human_judgment: false
  - id: D6
    description: "A stale approve or reject reports 'This draft was already reviewed' and creates no second event"
    requirement: REV-01
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/DraftDetailTest.php#reports a stale approve as already reviewed and creates no second event"
        status: pass
    human_judgment: false
  - id: D7
    description: "Script tags in title, description, repeats text and raw payload render as escaped text on the page and in the raw content modal; long titles wrap"
    requirement: REV-01
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/DraftDetailTest.php#escapes organiser text on the page and in the raw content modal"
        status: pass
    human_judgment: false
  - id: D8
    description: "Global search finds drafts (title, lower-cased reference, organiser, typed venue) and events (title, organiser); non-admins get none"
    requirement: ADM-06
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/GlobalSearchTest.php"
        status: pass
    human_judgment: false
  - id: D9
    description: "Visual layout of the review page (section order, wrapping, badge readability) in a real browser"
    requirement: REV-01
    verification:
      - kind: manual_procedural
        ref: "docs/uat/browser-uat.md review-queue flow (added with 02-13..02-16 browser UAT)"
        status: unknown
    human_judgment: true

duration: 10min
completed: 2026-10-17
---

# Phase 2 Plan 12: Review queue, draft review page and global search Summary

**The admin panel now has a Review queue. Drafts are listed soonest event first using one D-09 sort key, which the table and ReviewQueue::next()/previous() both use, so they cannot drift apart. Each draft has a review page showing every REV-01 item. Approve, reject, reopen and raw-content actions only call the Publishing actions. Global search covers drafts and published events.**

## Performance

- **Duration:** ~10 min
- **Started:** 2026-10-17T20:28:43Z
- **Completed:** 2026-10-17T20:38:22Z
- **Tasks:** 3 (1 tracer + 2 TDD)
- **Files:** 16 (14 created, 2 modified)

## Accomplishments

- `ReviewQueue` holds one `SORT_KEY`:
  - `(start_date is null)`, `start_date`, `(start_time is not null)`, `start_time`, `created_at`, `id`.
  - `applyOrder()` orders by these expressions.
  - `next()`/`previous()` use a row-value keyset over the same expressions, with nulls coalesced only inside their flag group. A null time therefore always sorts before 00:00.
- Review queue resource:
  - Navigation: group Review, label "Review queue", with a pending-count badge.
  - No create page and no delete action.
  - Rows open `/{record}/review`.
- Review page:
  - Shows "Before you can publish" with the `DraftPublishability` blockers. Approve stays disabled until the list is empty.
  - Infolist sections, in order: Summary, What, When, Where, Who, Source, Checks, Review.
  - A venue suggestion appears as "Suggested: Connaught Hall, matched by name" and is never applied.
  - Organiser links go through SafeLinks and carry `rel="nofollow noopener noreferrer"`.
- Reject takes an optional preset reason and note; the dialog buttons read "Reject draft" / "Keep as is". Reopen returns a rejected draft to pending. Raw content opens "What the organiser sent" as escaped key/value text.
- Stale clicks (draft decided in another tab) show "This draft was already reviewed" and create no second event.
- Global search covers drafts (title, reference, organiser, typed venue) and events (title, organiser). Matching is case-insensitive substring matching on PostgreSQL.

## Task Commits

1. **Task 1 (tracer): list → review → approve → public; one sort key.** RED `28182b4`, GREEN `9c9f335`
2. **Task 2 (TDD): full REV-01 detail, reject/reopen, raw content.** RED `37e6ff6`, GREEN `eccab6a`
3. **Task 3 (TDD): global search (ADM-06).** RED `f6be3de`, GREEN `6d49849`

_The measured `commits: 7` includes `789bbaf feat(02-11): seed nearby villages and Wymondham as geofence place names`, an unrelated commit that landed on main from elsewhere while this plan ran. It touches only `database/seeders/RulesSeeder.php` and its test. This plan made 6 commits._

## TDD Gate Compliance

- Task 1 RED (`28182b4`) failed with "Unable to find component: ListEventDrafts" in all 15 tests, then went GREEN in `9c9f335`. The tracer feedback gate re-ran the `<verify>` command end to end and it passed before expansion.
- Task 2 RED (`37e6ff6`): 22 of 25 tests failed (missing `reject()`, missing table actions, missing detail text). GREEN in `eccab6a`.
- Task 3 RED (`f6be3de`): 5 of 6 tests failed (URLs went to the table, no reference/organiser search, non-admin allowed). GREEN in `6d49849`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Stale clicks were silently dropped instead of reporting "already reviewed"**
- **Found during:** Task 1 (design check against the REV-01 idempotency truth)
- **Issue:** Filament re-reads the page record on every request and refuses hidden actions. A draft approved in another tab would hide Approve, so the click did nothing and showed no message.
- **Fix:** Visibility now follows `ReviewEventDraft::$shownStatus`, a snapshot of the status the page last showed, which resyncs after every action. The domain action re-checks the status on the locked row and the runner reports the conflict.
- **Files modified:** ReviewActions.php, ReviewEventDraft.php
- **Commit:** `9c9f335`

**2. [Rule 2 - Security] `canAccess()` on both resources**
- **Issue:** Calling global search outside the panel middleware would return results to a non-admin.
- **Fix:** `canAccess()` uses the AdminGate rule, the same check as `SystemHealth::canAccess()`.
- **Files modified:** EventDraftResource.php, EventResource.php
- **Commit:** `6d49849`

**3. [Rule 3 - Blocking] Supporting files outside `files_modified`**
- `LondonFormatter::wallClock()` formats stored local schedules. It is shared by the table, the infolist and the search details.
- Two Blade partials were added: the raw-content modal body and the safe-link entry. `TextEntry::url()` cannot set `rel`.
- **Commits:** `9c9f335`, `eccab6a`

**4. [Design choice] Keyset values from a subquery, not PHP-bound values**
- The plan said "bound values computed from the current draft the same way". Instead, the keyset compares against `(select <same expressions> from event_drafts as current_draft where id = ?)`. This gives identical semantics with no timestamp-precision or boolean-binding risk.

**5. [Test fix] One order test approved a draft that wasn't publishable**
- The null-time draft has the blocker "Add a start time.", so the test approves the 00:00 draft instead. Test-only change, committed with `9c9f335`.

## Flagged Assumptions (from plan)

- The ADM-06 edge row is `unclassified`. It is covered by the case-insensitive search truth and the lower-cased reference test.
- Only `new` drafts exist in Phase 2. Kind is shown as "New event".
- After Approve, the review page redirects to the list. 02-15 switches this to Approve & next.

## Verification

- `pest ReviewQueueTracerTest ReviewQueueOrderTest`: 15 passed
- `pest DraftDetailTest ReviewQueueTracerTest`: 32 passed (190 assertions)
- `pest GlobalSearchTest`: 6 passed
- Full Unit+Feature suite: **940 passed** (4713 assertions). The single unexplained `warnings: 1` existed before this plan (noted in 02-08).
- `vendor/bin/phpstan analyse --memory-limit=1G`: 0 errors. `vendor/bin/pint --dirty`: passed.
- Acceptance greps:
  - `ReviewQueue::applyOrder` in EventDraftsTable: 1
  - `'review' => ReviewEventDraft::route`: 1
  - `Keep as is` in ReviewActions: 2
  - `getGloballySearchableAttributes`: 1 in each resource

## Threat Flags

None beyond the plan's register. T-02-32 is covered by `canAccess()`, AdminGate in the actions and non-admin tests. T-02-33 is covered by escaped entries only, no `->html()`, and the XSS dataset. T-02-34 is covered by SafeLinks http/https plus `rel="nofollow noopener noreferrer"`.

## Next Phase Readiness

- 02-13 can add tabs, filters, columns and bulk actions to `EventDraftsTable` and `ListEventDrafts`, and reuse `ReviewQueue::applyOrder` and `ReviewActions::forTable()`.
- 02-14 (edit-and-approve) and 02-15 (Approve & next) can use `ReviewQueue::next()`/`previous()` and the `ReviewEventDraft` page.

## Self-Check: PASSED
