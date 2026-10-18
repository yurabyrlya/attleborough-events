---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 08
subsystem: publishing
tags: [publishing, event-sources, attribution, baseline, exdate, recurrence, review, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-01 sources/source_items/excluded_dates schema; 03-04 IcsExtractor (excludedDates, cancelledKeys); 03-05 DraftWriter::createFromSource, raw_payload = NormalisedFacts::toPayload()"
provides:
  - "event_sources table (event_sources_one_primary partial unique, UNIQUE source_item_id, linked_via CHECK) and App\\Models\\EventSource (VIA_APPROVED / VIA_DUPLICATE)"
  - "Event::eventSources() (oldest first), Event::primarySource(), events.excluded_dates fillable + array cast"
  - "App\\Publishing\\Actions\\LinkEventSource(Event, Source, ?SourceItem, linkedVia, User): the only writer of event_sources; ITEM_ALREADY_LINKED refusal"
  - "ApproveDraft: sourced drafts get cancelled overrides, a primary/also-listed link and the item's accepted baseline in the approval transaction"
  - "RejectDraft: discovered new draft → item review_outcome rejected + baseline moved (D-23)"
  - "ReopenDraft: KIND_REFUSED / NEWER_DRAFT_PENDING via App\\Review\\Exceptions\\DraftNotReopenable; item back to pending with current_draft_id"
  - "PublishableEventData::$excludedDates (nullable, C2) and RecurrenceExpander EXDATE + summary()"
affects: [03-09 change detection, 03-10 apply/ignore/confirm, 03-11 mark duplicate, 03-13 review UI, 03-16 public attribution]

actuals:
  tokens: 14600
  tasks: 3
  commits: 4
plan_head_before: 817a6b623e4e22b786385d634e9ba66ac1ad86b4
plan_head_after: aef040ff4200eed6bf243c7187ab6ab2bb09c9e8

tech-stack:
  added: []
  patterns:
    - "Lock order for sourced approvals: draft → event (PublishEvent, ChangeOccurrenceStatus, LinkEventSource) → source item last"
    - "Primary switch clears the old flag before inserting the new primary (partial unique index)"
    - "A UniqueConstraintViolationException from a nested (savepoint) transaction is turned into a domain refusal after the savepoint rolls back"
    - "Nullable DTO field = 'leave stored value untouched' (C2), so edit forms need no hidden field"

key-files:
  created:
    - database/migrations/2026_10_18_100600_create_event_sources_table.php
    - app/Models/EventSource.php
    - app/Publishing/Actions/LinkEventSource.php
    - app/Review/Exceptions/DraftNotReopenable.php
    - tests/Feature/Publishing/ApproveSourcedDraftTest.php
    - tests/Feature/Publishing/LinkEventSourceTest.php
    - tests/Feature/Publishing/ExcludedDatesTest.php
  modified:
    - app/Models/Event.php
    - app/Publishing/Actions/ApproveDraft.php
    - app/Publishing/Actions/RejectDraft.php
    - app/Publishing/Actions/ReopenDraft.php
    - app/Publishing/Data/PublishableEventData.php
    - app/Calendar/RecurrenceExpander.php
    - app/Filament/Resources/EventDrafts/Actions/ReviewActions.php

key-decisions:
  - "linked_via is a string with EventSource::VIA_APPROVED / VIA_DUPLICATE constants plus a DB CHECK (no new enum); review_outcome stays the plain strings 'pending' / 'approved' / 'rejected' that 03-05 already writes"
  - "LinkEventSource refuses (InvalidArgumentException, ITEM_ALREADY_LINKED) both by a pre-check and by catching the UNIQUE violation, so a second link for an item never changes anything; it also refuses an item from another source and an unknown linked_via"
  - "item_url = the item payload's url when it is a non-empty string ≤ 2048 characters, else null (never cut); the public fallback to the events page is 03-16's job"
  - "event_sources.linked_by is restrictOnDelete (like events.approved_by); source_id restrictOnDelete, event_id cascade, source_item_id nullOnDelete"
  - "Cancelled overrides come from the draft's raw_payload['cancelled_keys'] (what the admin saw), applied only to Scheduled occurrences that exist inside the 90-day horizon"
  - "excludedDates: fromDraft passes the draft's list sorted and de-duplicated; the constructor refuses a non-real date ('Invalid excluded date [..].'); toAttributes writes [] for a one-off"
  - "RecurrenceExpander::summary(Event) = ManualRecurrence label (or 'Repeats') + ', except Wed 21 Oct' (year added when it differs from the start year); not wired into any page yet (03-13 / 03-16 can call it)"
  - "Reopen refusals use a new DraftNotReopenable domain exception, caught by ReviewActions::run as a danger notification; the Reopen action is hidden for update/cancellation drafts (C14)"

patterns-established:
  - "Feature tests that need sourced drafts build items with new SourceItem + forceFill (no SourceItem factory) and drafts with EventDraft::factory() (factories are unguarded)"

requirements-completed: []

coverage:
  - id: D1
    description: "Approving a Baptist Church draft publishes it on /, creates one primary event_sources row (approved, item_url, linked_by) and sets accepted_hash/accepted_payload/review_outcome approved"
    requirement: PIPE-06
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/ApproveSourcedDraftTest.php#publishes a discovered draft, links it to its source as primary and sets the accepted baseline"
        status: pass
  - id: D2
    description: "Non-admin approval writes nothing; a link failure rolls back the whole approval (LEG-04 prohibition: no discovered event without its source link)"
    requirement: LEG-04
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/ApproveSourcedDraftTest.php#refuses a non-admin and writes no link, event or baseline"
        status: pass
      - kind: integration
        ref: "tests/Feature/Publishing/ApproveSourcedDraftTest.php#rolls the whole approval back when the source link cannot be written"
        status: pass
  - id: D3
    description: "Primary attribution: first link primary, organiser source takes over, later non-organiser and second organiser links are also-listed; one primary at every step; one link per item"
    requirement: REV-05
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/LinkEventSourceTest.php"
        status: pass
  - id: D4
    description: "Rejected discovered items stay rejected with baseline moved; reopen resets to pending; update/cancellation drafts and items with a newer pending draft cannot be reopened"
    requirement: PIPE-06
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/LinkEventSourceTest.php (reject/reopen tests)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Review/RejectReopenDraftTest.php"
        status: pass
  - id: D5
    description: "EXDATE publishes as excluded_dates, no 21 Oct occurrence, 28 Oct at 19:00 UTC, survives an admin edit (C2) and calendar:sync-occurrences; summary says 'except 21 Oct'; all-day VALUE=DATE EXDATE honoured"
    requirement: EXT-01
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/ExcludedDatesTest.php"
        status: pass
  - id: D6
    description: "Cancelled override (s-cancelled 4 Nov) is Cancelled at approval and shows 'Cancelled' on the event page"
    requirement: EXT-01
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/ExcludedDatesTest.php#cancels the cancelled override at approval and shows it as Cancelled on the event page"
        status: pass

duration: ~13min
completed: 2026-10-18
---

# Phase 3 Plan 08: Source Links, Accepted Baseline and ICS Exclusions Summary

**Approving a discovered draft now does everything in one transaction. It publishes the event, cancels the series' cancelled dates, links the event to its source through the new `event_sources` table (primary or "also listed by" under D-12/D-25), and gives the source item its accepted baseline. Rejected items stay rejected. Update and cancellation drafts cannot be reopened. ICS EXDATEs are published as `events.excluded_dates` and become EXDATEs in RecurrenceExpander; an admin edit or the nightly sync does not remove them.**

## Performance

- **Duration:** ~13 min (09:43Z–09:56Z on 2026-10-18)
- **Tasks:** 3 (1 tracer + 2 TDD)
- **Files:** 7 created, 7 modified

## Accomplishments
- New `event_sources` table:
  - `event_sources_one_primary` partial unique index on `(event_id) WHERE is_primary`;
  - UNIQUE `source_item_id`;
  - `linked_via` CHECK;
  - `linked_at` timestampTz.
  - Model: `EventSource`. Relations on `Event`: `eventSources()` and `primarySource()`.
- `LinkEventSource` checks AdminGate first, then:
  - locks the event row, then the current primary link;
  - makes the first link primary;
  - lets an organiser's-own-site source take primary from a non-organiser source (the old flag is cleared first);
  - logs ids only, after commit.
- `ApproveDraft` with a sourced draft: publish → cancel overrides → link → stamp the draft → lock the item last → set the baseline. Manual and submission drafts behave as before.
- `RejectDraft` / `ReopenDraft` keep the item's bookkeeping up to date and add the C14 guards. The Filament Reopen action is hidden for non-new drafts, and refusals appear as a notification.
- `PublishableEventData::$excludedDates` is nullable: `fromDraft` passes the draft's list, `fromForm` passes null, and `toAttributes` writes the field only when it is set.

## Task Commits

1. **Task 1 (tracer): approving a discovered draft links its source and sets the baseline**: `40959b8` (feat)
2. **Task 2 (tdd): primary attribution rules, rejected items stay rejected, reopen guards**: `a3d1ff2` (feat)
3. **Task 3 (tdd): publish ICS exclusions and cancelled overrides without losing them on edits**: `33e5eae` (feat)
4. **Docblock fix so the KIND_REFUSED text appears once (acceptance grep)**: `aef040f` (style)

**Tracer gate:** after Task 1, I re-ran its `<verify>` end to end before expanding: ApproveSourcedDraftTest, ApproveDraftTest, WriteChokePointTest and IngestionSchemaTest, 75 passed. Unattended run, so no human checkpoint.

## Files Created/Modified
- `database/migrations/2026_10_18_100600_create_event_sources_table.php`, `app/Models/EventSource.php`: the link table
- `app/Publishing/Actions/LinkEventSource.php`: primary / also-listed-by rules (D-12, D-25)
- `app/Publishing/Actions/ApproveDraft.php`: cancelled overrides, link and baseline for sourced drafts
- `app/Publishing/Actions/RejectDraft.php`, `ReopenDraft.php`, `app/Review/Exceptions/DraftNotReopenable.php`: D-23 and C14
- `app/Filament/Resources/EventDrafts/Actions/ReviewActions.php`: Reopen visibility and notification for refusals
- `app/Publishing/Data/PublishableEventData.php`, `app/Calendar/RecurrenceExpander.php`, `app/Models/Event.php`: excluded dates (D-19, C2)
- Tests: ApproveSourcedDraftTest (4), LinkEventSourceTest (8), ExcludedDatesTest (7)

## Decisions Made
See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Correctness] Reopen refusals needed a UI path (C14)**
- **Found during:** Task 2
- **Issue:** a plain exception from ReopenDraft would reach the admin as a 500, and the Reopen button would still show on update and cancellation drafts. PATTERNS C14 asks for the guard in both places.
- **Fix:** added the `DraftNotReopenable` domain exception, caught in `ReviewActions::run()` and shown as a danger notification. Reopen is now visible only for `kind = new`.
- **Files modified:** app/Review/Exceptions/DraftNotReopenable.php, app/Filament/Resources/EventDrafts/Actions/ReviewActions.php
- **Commit:** `a3d1ff2`

**2. [Rule 1 - Bug] RecurrenceExpander must not fail on a model loaded without excluded_dates**
- **Found during:** Task 3
- **Issue:** reading the typed property directly would crash, with a TypeError or a missing-attribute error, on an Event loaded with a partial `select`.
- **Fix:** read it with `getAttribute()`. A missing or non-array value means no exclusions.
- **Commit:** `33e5eae`

**3. Test adjustment: RejectReason::Ignored does not exist yet.** The update/cancellation reopen tests use `RejectReason::Other` for the rejected state. The `Ignored` case arrives with 03-10. The behaviour being tested is the same.

## Known Stubs
None.

## Deferred Issues
Logged in `deferred-items.md`:
- A cancelled override beyond the 90-day horizon is published as Scheduled once the sync reaches it. The plan scopes cancellation to dates inside the horizon. A fix would belong to 03-09/03-10.
- The events edit form's "Next dates" preview ignores `excluded_dates`. This affects the preview only.

## Issues Encountered
- None blocking. The full suite reports one PHPUnit warning that predates this plan (noted in 03-05 and 03-06).

## Verification
- Task verifies: ApproveSourcedDraftTest + ApproveDraftTest + WriteChokePointTest (green); LinkEventSourceTest + tests/Feature/Review (138 passed); ExcludedDatesTest (green).
- Full `./vendor/bin/pest --testsuite=Unit,Feature`: 1354 passed, 0 failed.
- `./vendor/bin/phpstan analyse --memory-limit=1G`: 0 errors. `vendor/bin/pint --dirty --format agent`: passed.
- Requirements LEG-04, REV-05, PIPE-06 and EXT-01 are only partly delivered here: display is 03-16, mark-duplicate is 03-11, apply/ignore baselines are 03-10, and the wider EXT-01 scope is spread across the phase. They stay Pending.

## Next Phase Readiness
- 03-11 (mark duplicate) can call `LinkEventSource(..., EventSource::VIA_DUPLICATE, ...)` and reuse the baseline move.
- 03-16 can eager-load `primarySource.source` and `eventSources.source` for attribution.
- 03-09 and 03-10 should note the horizon gap for cancelled overrides (deferred item 1).

## Self-Check: PASSED

All 7 created files exist; commits 40959b8, a3d1ff2, 33e5eae and aef040f are ancestors of HEAD.
