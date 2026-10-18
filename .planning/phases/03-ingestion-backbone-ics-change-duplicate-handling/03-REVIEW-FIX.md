---
phase: 03
fixed_at: 2026-10-18T15:10:00+01:00
review_path: .planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-REVIEW.md
iteration: 2
findings_in_scope: 10
fixed: 10
skipped: 0
status: all_fixed
---

# Phase 03: Code Review Fix Report

**Fixed at:** 2026-10-18
**Source review:** .planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-REVIEW.md (plus T-03-11 from 03-SECURITY.md)
**Iteration:** 2 (pass 1 critical, pass 2 warnings)

**Summary:**
- Pass 1 (critical): 4 findings in scope (CR-01, CR-02, CR-03, security T-03-11), 4 fixed. Details below.
- Pass 2 (warnings, iteration 2): 6 findings in scope (WR-01, WR-02, WR-03, WR-05, WR-07, WR-08), 6 fixed. See "Warnings" below. WR-04 (Retry-After) and WR-06 (source_items retention) are deferred by the owner and were not touched; IN-01…IN-03 were out of scope.
- Total: 10 in scope, 10 fixed, 0 skipped.

**Where verification ran (pass 1):** in the main checkout on `main`. No worktree was used because the orchestrator asked for sequential commits on main. The numbers can be reproduced from this tree.
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


## Warnings (pass 2, iteration 2)

**Where verification ran:** in the main checkout on `main` (sequential commits, no worktree), so the numbers can be reproduced from this tree.
- Targeted tests for each fix. Each new test was also run against the pre-fix app code (`git stash` of the app files only) and failed there, except the control cases that pin unchanged behaviour.
- `./vendor/bin/pest --testsuite=Unit,Feature`: **1701 passed** (10607 assertions)
- `npm run build`, then `./vendor/bin/pest --testsuite=Browser`: **40 passed** (599 assertions; covers the review and sources flows)
- `./vendor/bin/phpstan analyse --memory-limit=1G`: 0 errors
- `vendor/bin/pint --dirty --format agent`: passed
- No migration was needed.

### WR-01: Ingestion and the review actions take locks in opposite orders; a deadlock victim on the ingestion side is silently lost behind conditional GET

**Files modified:** `app/Ingestion/Pipeline/SourceItemRecorder.php`, `app/Ingestion/Pipeline/MissingItemDetector.php`, `app/Ingestion/IngestRun.php`, `tests/Feature/Ingestion/ItemFailureValidatorsTest.php` (new)
**Commit:** 58baa43
**Applied fix:** I did both halves of the review's fix.
- **One lock order.** New `SourceItemRecorder::lockPendingDrafts(int $sourceItemId)` locks a known item's pending drafts (`for update`, by id) before the item row. `record()` reads the item id without a lock, locks its pending drafts, then locks the item. `MissingItemDetector::markMissing()` does the same. Ingestion now uses draft → item, the same order as every review action.
- **No validators after an item failure.** `IngestRun::process()` counts items that threw. `finish(..., keepValidators: false)` then stores `etag` and `last_modified` as null, so the next run fetches the whole body and records the item, instead of getting a 304 until the feed changes. The run still succeeds and `last_success_at` still moves.
**Tests:** A run where one item's draft insert throws (a PostgreSQL trigger). The source then keeps no validators, the next run sends no `If-None-Match`, gets a 200 and drafts the item, and a third clean run is a 304 again. A query-log test shows the per-item order is `draft`, then `item`. A real 40P01 could not be used inside RefreshDatabase's outer transaction, because Laravel skips the savepoint rollback for concurrency errors in nested transactions. A plain trigger exception stands in for it.
**Status:** fixed: requires human verification (lock-order logic)

### WR-02: `is_complete` latches off for good after a lasting drop in the feed, silently disabling missing-item detection

**Files modified:** `app/Ingestion/Health/SourceHealthTracker.php`, `app/Filament/Resources/Sources/Pages/EditSource.php`, `tests/Feature/Ingestion/SourceHealthTest.php`
**Commit:** 8099ba5
**Applied fix:**
- **Re-baselining after a lasting drop.** I used the review's second option. A run is also complete when it is the `STEADY_RUNS` = 3rd succeeded live run in a row at the new size: the 2 succeeded runs before it must be incomplete, must have found items, and must each be within `STEADY_TOLERANCE_PERCENT` = 20% of this run's count. That run becomes the new reference.
- **What stays incomplete.** A one-off short feed, or a feed that keeps shrinking, is still incomplete. So a partial fetch still never counts.
- **Source page.** New "Missing-item detection paused" callout. It shows when the source uses missing detection and the latest succeeded live run found items but was incomplete. It names the last complete check's date and says detection resumes after 3 checks at the new size.
**Tests:**
- 64 → 20, 22, 19, 20 gives `[false, false, true, true]`.
- 64 → 30, 20, 14, 64, 10, 64 gives `[false, false, false, true, false, true]`.
- The callout appears after the drop and disappears after the third steady run.
- The existing half-of-previous and "compares with the previous complete run" tests are unchanged and still pass.
**Status:** fixed: requires human verification (completeness rule)

### WR-03: Editing a live source's URL, item identity or default time zone reuses stale validators and can flood the queue with false cancellations

**Files modified:** `app/Filament/Resources/Sources/Pages/EditSource.php`, `app/Filament/Resources/Sources/Schemas/SourceForm.php`, `app/Filament/Resources/Sources/Schemas/SourceFieldHelp.php`, `app/Ingestion/IngestRun.php`, `tests/Feature/Filament/SourceEditSafetyTest.php` (new)
**Commit:** 899dead
**Applied fix:**
- **Stale validators.** `EditSource::handleRecordUpdate()` clears `etag` and `last_modified` when a save changes `url`, `default_timezone` or `parser_config.identity`. A run that was already fetching the old URL when the save happened does not write its validators back: `IngestRun::finish()` stores them only if the source's URL in the database still equals the URL the run fetched. There is no other run-level cache to clear: D-24 preview reuse already checks the URL.
- **Identity and time zone changes.** This follows the review's first recommendation. Once a source has any `source_items`, the identity and time zone are refused (`SourceForm::unchangedOnceRecorded()`, a validation rule on each field), with this message: "The item identity cannot be changed once this source has recorded feed items: every item would look new, and every published event from this source would be reported missing. To read the feed a different way, pause this source and add the feed again as a new source." The time zone message has the same shape. A new source, or one with no items, can still change them freely.
- **Why refuse rather than suppress detection.** Suppressing missing detection would still give every item a new identity. That means a second New draft for every item and no duplicate flag, because DuplicateDetector skips events already linked to the same source. Refusing avoids all of that. Adding the feed again as a new source runs through the normal test run (D-01), and its drafts are matched against the published events (PIPE-05).
- **Help text.** The helper text and the "?" help for both fields say they are fixed once items are recorded.
**Tests:**
- A URL change clears the validators, and the next run reads the new feed (the fake server would have answered 304 to the old ETag).
- A name or notes save keeps the validators.
- A run in flight whose URL changed mid-fetch stores none.
- Identity and time zone changes are refused with the message once items exist, and nothing changes.
- Both are allowed on a source with no items, and that save clears the validators.

### WR-05: Source health cannot see a source that is permanently blocked or not checking, so System Health says "all healthy"

**Files modified:** `app/Ops/HealthReport.php`, `tests/Feature/Ops/HealthReportTest.php`
**Commit:** 586d071
**Applied fix:**
- **Staleness leg.** `HealthReport::sources()` adds one, still inside its single aggregate query. An active source counts as not checked when `last_success_at` is older than `STALE_AFTER_INTERVALS` (2) check intervals (1 day, or 7 for weekly) plus `ingest.schedule.jitter_minutes`. A source that never succeeded counts when its first live run is that old.
- **How it reads.** It shows as "1 not checked successfully since Sun 18 Oct" (or "N …, the oldest since …") and "N never checked successfully". "Now" is bound from PHP, so frozen test time applies.
- **Still a warning.** It stays out of `all()`, so `ops:health` prints it as `STALE (warning)` and its exit code still ignores sources (T-03-21).
- **Expectation text.** Now "Every active source is healthy and was checked successfully within two check intervals".
**Tests:**
- Boundaries for daily: 2 days 19 minutes is OK, 2 days 21 minutes is stale. For weekly: 10 days is OK, 15 days is stale.
- A real source blocked by robots.txt on three daily checks stays `healthy` but is named "1 never checked successfully", and `ops:health` still exits 0 with HEALTHY.
- A freshly activated source with a 3-hour-old blocked first run is not counted.

### WR-07: The job timeout covers the fetch but not item processing

**Files modified:** `app/Ingestion/IngestRun.php`, `app/Jobs/FetchSourceJob.php`, `config/ingest.php`, `config/queue.php`, `tests/Feature/Ingestion/RunDeadlineTest.php` (new), `tests/Feature/Ingestion/SourceItemRecorderTest.php`, `03-SECURITY.md`
**Commit:** 0edfa1a
**Applied fix:** I used both options from the review.
- **Run deadline.** New `ingest.run_deadline` = 240 s, counted from when the job claims the run (`started_at`). `IngestRun::process()` checks it before each item. Once it has passed, the run stops and fails as `timeout`. It keeps the counters of the items it reached, plus "Timed out: recorded N of M items before the time limit. Items already recorded are quick to check again, so the next check gets further."
- **What a timed-out run keeps.** Items already recorded stay (one transaction each). As a failed run it stores no validators, runs no missing detection, and counts once towards health.
- **Timeouts.** `fetch_deadline` (55 s) is unchanged and sits inside the run deadline. `FetchSourceJob::$timeout` goes from 75 to 300 s: 240 s of run plus time to finish the run (health, missing detection). The database queue's `retry_after` default goes from 90 to 330 s, so a running fetch is never handed to a second worker.
- **Security doc.** 03-SECURITY.md T-03-16 evidence and the Phase 5 note are updated.
**Tests:**
- Each created item moves the clock 100 s. The run records 3 of 6 items, fails as timeout with that message and the partial counters, keeps no ETag and counts 1 failure. The next run finishes: 3 unchanged, 3 new, validators stored, health reset.
- The job-settings test now checks `timeout` 300, `fetch_deadline < run_deadline`, `run_deadline + 30 ≤ timeout`, and `retry_after > timeout`.

### WR-08: Documented domain and DB exceptions surface as 500s, and bulk approval stops halfway

**Files modified:** `app/Support/ConcurrencyFailure.php` (new), `app/Filament/Resources/EventDrafts/Actions/ReviewActions.php`, `app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php`, `app/Publishing/Actions/ApproveAllLikeThis.php`, `app/Review/DraftPublishability.php`, `tests/Feature/Filament/ApproveAllLikeThisTest.php`, `tests/Unit/Review/ConcurrencyFailureTest.php` (new)
**Commit:** 85d0b6b
**Applied fix:**
- **Detecting a concurrency failure.** `ConcurrencyFailure::causedBy()` searches the exception chain for SQLSTATE 40P01 (deadlock) or 40001 (serialization failure). It covers both shapes Laravel produces: a `QueryException` at the outermost transaction, and a `DeadlockException` wrapping one inside a nested transaction.
- **Single review actions.** `ReviewActions::run()` now also catches `InvalidArgumentException` and shows it as the action's refusal notification. A concurrency `PDOException` shows "Busy, please try again" / "Another change to this draft was being saved at the same moment, so nothing was changed. Try again." Any other database error is still rethrown.
- **Bulk paths.** `ApproveAllLikeThis::approveOne()` and the table's bulk approve skip a refused draft as `blocked` and a concurrency victim as the new `busy` skip key ("another change was being saved at the same moment, so try again"). Both then carry on, so the "Approved n of m" summary always appears and names them.
**Tests:**
- Approve all like this with a deadlock on the 2nd publish: "Approved 2 of 3." plus the busy line, and the 2nd draft is untouched.
- A documented refusal counts as `blocked`, and the others are approved.
- A non-concurrency database error still throws.
- Bulk approve skips the deadlock victim and approves the rest.
- Single approve on the review page shows the busy notification for a deadlock and the refusal message for an `InvalidArgumentException`, and the draft stays pending.
- Unit tests for the 40P01/40001 detection: top level, nested, and other errors.

### Behaviour changes the owner should know about (pass 2)

- A succeeded run in which any item threw now stores no ETag/Last-Modified, so the next check downloads the full feed once (WR-01).
- After a feed shrinks for good, missing-item detection resumes on the third check in a row at the new size. Until then the source page says detection is paused (WR-02).
- The item identity and default time zone of a source with recorded feed items can no longer be changed in the form. The admin pauses the source and adds the feed as a new one instead. Changing the feed URL clears the stored validators (WR-03).
- System health's Sources line now also warns about active sources that have not succeeded for two check intervals, including ones blocked every time. It is still a warning only (WR-05).
- A very large feed can now end a run as "Timed out" with partial counts instead of being killed by the worker. Job timeout is 300 s and the database queue's retry_after default is 330 s (WR-07). **Phase 5:** the worker's `--timeout` should be ≥ 300, systemd `TimeoutStopSec` should be ≥ 300 (e.g. 330), and any `DB_QUEUE_RETRY_AFTER` set in production must be > 300. The current 05-01 plan says `--timeout=60` and `TimeoutStopSec=90`.
- Review actions no longer show a 500 for documented refusals or deadlocks. Bulk approve and Approve all like this report them as skips (WR-08).

---

_Fixed: 2026-10-18_
_Fixer: the agent (gsd-code-fixer)_
_Iteration: 2 (pass 1 critical, pass 2 warnings)_
