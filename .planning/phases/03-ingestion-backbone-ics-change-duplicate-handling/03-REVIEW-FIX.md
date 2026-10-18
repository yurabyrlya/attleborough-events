---
phase: 03
fixed_at: 2026-10-18T14:16:25+01:00
review_path: .planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-REVIEW.md
iteration: 1
findings_in_scope: 4
fixed: 4
skipped: 0
status: all_fixed
---

# Phase 03: Code Review Fix Report

**Fixed at:** 2026-10-18
**Source review:** .planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-REVIEW.md (plus T-03-11 from 03-SECURITY.md)
**Iteration:** 1

**Summary:**
- Findings in scope: 4 (CR-01, CR-02, CR-03, security T-03-11). Warnings WR-01…WR-08 and info IN-01…IN-03 were out of scope and left alone.
- Fixed: 4
- Skipped: 0

**Where verification ran:** in the main checkout on `main`. No worktree was used because the orchestrator asked for sequential commits on main. The numbers can be reproduced from this tree.
- Targeted tests for each fix, then `./vendor/bin/pest --testsuite=Unit,Feature`: **1674 passed** (10455 assertions)
- `./vendor/bin/phpstan analyse --memory-limit=1G`: 0 errors
- `vendor/bin/pint --dirty --format agent`: passed
- For each fix, the new tests were also run against the pre-fix app code and failed there, as they should.

## Fixed Issues

### T-03-11: max_events pre-count bypassed by a folded BEGIN:VEVENT line

**Files modified:** `app/Ingestion/Ics/IcsExtractor.php`, `tests/Unit/Ingestion/IcsExtractorTest.php`
**Commit:** 3c2c8b9
**Applied fix:** The cheap raw-text pre-count is unchanged. After `$calendar->select('VEVENT')`, the extractor now counts the parsed VEVENTs and returns the same fatal result (`tooManyEvents()`, same message, `eventCount` = parsed count) when the count exceeds `ingest.max_events`. This happens before any per-event work.
**Tests:** "fails the extraction when folded BEGIN:VEVENT lines hide events from the pre-count (T-03-11)" (3 folded events, cap 2) and "reads folded BEGIN:VEVENT lines when the parsed count is within ingest.max_events".
**Docs:** 03-SECURITY.md has T-03-11 as **closed** with file:line and test evidence. `threats_open` stays 0, an audit-trail row was added and the sign-off follow-up is ticked.

### CR-01: The excerpt leaves common UK phone formats in, and bulk approval publishes them

**Files modified:** `app/Ingestion/Pipeline/ExcerptBuilder.php`, `app/Review/DraftPublishability.php`, `tests/Unit/Ingestion/ExcerptBuilderTest.php`, `tests/Feature/Review/ApproveDraftTest.php`, `tests/Feature/Filament/ApproveAllLikeThisTest.php`, `tests/Feature/Filament/BulkReviewTest.php`
**Commit:** 62cdb67
**Applied fix:**
- `UK_PHONE` now matches a bracketed area code (`(01953) 452300`), space, hyphen or dot separators (`01953.452.300`) and `+44` with or without `(0)`. It needs 10-12 digits after the leading 0, so 24-hour ranges such as `0930-1630` and prices or times are kept.
- New `LOCAL_PHONE` strips 6-8 digit local numbers, but only when a phone word comes before them. After tel/telephone/phone/mobile the label goes with the number, in the same way as the existing labels. After call/ring/text (and "ring us on") the verb stays, the same as the existing "Call today" behaviour. "Bring your phone" and "Call in any time" are left unchanged.
- Publish-side backstop, as the review recommended: `ExcerptBuilder::mayContainContact()` is a loose deny-by-default check (`@`, a 6+ digit run, or two 3+ digit groups split by space, dot, hyphen or bracket). `DraftPublishability::bulkBlockerKeys()` adds the `contact_like` key ("description may contain a phone number or email") for **discovered** drafts that match. Bulk approve and "Approve all like this" skip them and list the skip. A single approve, after a human has read the draft, still works.
**Tests:** Excerpt cases for bracketed, dotted, dotted groups, international without (0), local after call/ring us on/text, local with a label, and dotted local. Keep cases for "Bring your phone, it is 2026", "Call in any time", "Open 0930-1630 daily" and "Doors 7.30pm, tickets £5.00". A `mayContainContact` dataset. Bulk key tests (discovered vs manual drafts, single approve still allowed). Skip message. Bulk approve and Approve-all-like-this skip tests.

### CR-02: An edit-page save overwrites a draft that ingestion replaced meanwhile, and approval then sets the baseline to facts that were never published

**Files modified:** `app/Review/Exceptions/DraftChangedMeanwhile.php` (new), `app/Review/DraftWriter.php`, `app/Filament/Resources/EventDrafts/Pages/EditEventDraft.php`, `app/Publishing/Actions/ApproveDraft.php`, `tests/Feature/Review/DraftWriterTest.php`, `tests/Feature/Filament/EditDraftConcurrencyTest.php` (new)
**Commit:** 2fa8f8c
**Applied fix:**
- Optimistic concurrency. `DraftWriter::version()` is a sha256 of the draft row's raw stored attributes (`getRawOriginal()`), so it is not affected by updated_at's one-second precision or by unsaved in-memory picks. `EditEventDraft` keeps it in a `#[Locked] $draftVersion` taken at mount and refreshed after each successful save. `DraftWriter::update(..., $expectedVersion)` compares it on the locked row and throws `DraftChangedMeanwhile`. Save and Save & approve catch it, change nothing (Save & approve rolls back the whole transaction), and show the persistent notification "This draft changed while you were editing — reload to see the latest version." The "already reviewed" check still runs first.
- Baseline follows what was approved. `ApproveDraft::acceptBaseline()` now uses the feed facts the draft was built from (`raw_payload`, normalised through `NormalisedFacts` so it hashes exactly like `source_items.payload`). If those match the item's current `content_hash`, nothing changes from before. If they differ, `accepted_hash`/`accepted_payload` are set to the draft's facts, so the next run raises the difference as an Update draft instead of going quiet. Drafts with no feed facts fall back to the item's current content.
**Tests:** DraftWriter refuses a stale version and writes nothing, accepts a current one, and in-memory changes leave the version unchanged. End-to-end Filament plus ingestion: an edit page opened at 10:00, a run replacing the draft with 11:00, then Save & approve and Save are both refused with the message, with no event and the draft still at 11:00. After a reload it publishes 11:00 with `accepted_hash = content_hash`. Two saves in a row on one page succeed. A baseline test checks that when the item moved without the draft being replaced, approval keeps the draft's hash and the next run raises a 10:00 → 11:00 update.
**Status:** fixed: requires human verification (concurrency and baseline logic)

### CR-03: Reopening an old rejected draft whose item has since been approved turns a linked item back into a "pending new" one

**Files modified:** `app/Publishing/Actions/ReopenDraft.php`, `app/Publishing/Actions/RejectDraft.php`, `app/Publishing/Actions/ApproveDraft.php`, `app/Publishing/Actions/MarkDraftDuplicate.php`, `app/Publishing/Actions/LinkEventSource.php`, `app/Models/EventSource.php`, `app/Ingestion/Pipeline/ChangeDetector.php`, `app/Filament/Resources/EventDrafts/Actions/ReviewActions.php`, `tests/Feature/Publishing/ReopenLinkedItemTest.php` (new), `tests/Feature/Publishing/ApproveSourcedDraftTest.php`
**Commit:** f9829c2
**Applied fix:**
- `ReopenDraft` refuses with `DraftNotReopenable` ("This feed item has since been published as an event, so this draft cannot be reopened. Changes from the feed reach that event as update drafts.") when the locked item is linked (`EventSource::linksItem()`) or its outcome is approved or duplicate. Reopen is also hidden in that case (`ReopenDraft::itemAllowsReopen()`).
- `ApproveDraft` refuses a draft whose item is already linked before anything is published, and maps a racing `ITEM_ALREADY_LINKED` from `LinkEventSource` to the same error. `MarkDraftDuplicate` does both too. Both throw `DraftNotPublishable` with `LinkEventSource::ITEM_ALREADY_PUBLISHED`, so the review page shows "Can't publish yet" or "Can't mark as duplicate" instead of a 500. Bulk paths count it as "not ready to publish".
- `RejectDraft` never moves a linked item's `accepted_hash`/`accepted_payload`. It restores `review_outcome` to the link's `linked_via` (approved/duplicate), which repairs anything a pre-fix reopen left behind.
- `ChangeDetector::detect()` checks the link before the pending-New branch, as the review suggested. `withPending()` passes the raised verdict through when the pending draft is a stale New one. A feed change then raises an Update/Cancellation for the published event, and `act()` supersedes the stale draft.
- `ApproveSourcedDraftTest` "rolls the whole approval back when the source link cannot be written" now expects `DraftNotPublishable` with the new message instead of `InvalidArgumentException`. This is the intended behaviour change, and all the rollback assertions are kept.
**Tests:** A real ingestion scenario (A rejected, then re-drafted as B, B approved and linked). Reopen of A is refused and the item stays approved, current draft B, baseline unchanged. Reopen is hidden on A's review page. A control case where Reopen still works for an unpublished item. Approve (action and review page) refused with the message and nothing published. Mark as duplicate (action and review page) refused with the message, still one link. Rejecting again leaves the baseline alone and the outcome stays approved. A later feed change raises an Update for the event (published 11:00 → now 12:00) and supersedes stale A.
**Status:** fixed: requires human verification (state-machine logic)

---

_Fixed: 2026-10-18_
_Fixer: the agent (gsd-code-fixer)_
_Iteration: 1_
