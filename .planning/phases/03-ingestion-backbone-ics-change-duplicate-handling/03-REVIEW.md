---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
reviewed: 2026-10-18T13:50:00Z
depth: standard
diff_base: d767eb3
files_reviewed: 135
files_reviewed_list:
  - app/Calendar/OccurrenceGenerator.php
  - app/Calendar/PublicOccurrenceQuery.php
  - app/Calendar/RecurrenceExpander.php
  - app/Casts/SourceDiff.php
  - app/Console/Commands/IngestDispatchDue.php
  - app/Console/Commands/IngestPruneRaw.php
  - app/Console/Commands/OpsHealth.php
  - app/Enums/ExtractionMethod.php
  - app/Enums/FetchFailureKind.php
  - app/Enums/IdentityStrategy.php
  - app/Enums/RejectReason.php
  - app/Enums/RunMode.php
  - app/Enums/RunStatus.php
  - app/Enums/RunTrigger.php
  - app/Enums/SourceHealth.php
  - app/Enums/SourceItemStatus.php
  - app/Filament/Pages/Rules.php
  - app/Filament/Pages/SystemHealth.php
  - app/Filament/Resources/EventDrafts/Actions/ReviewActions.php
  - app/Filament/Resources/EventDrafts/Pages/EditEventDraft.php
  - app/Filament/Resources/EventDrafts/Pages/ReviewEventDraft.php
  - app/Filament/Resources/EventDrafts/Schemas/DiscoveredDraftDetails.php
  - app/Filament/Resources/EventDrafts/Schemas/EventDraftInfolist.php
  - app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php
  - app/Filament/Resources/Events/Schemas/EventForm.php
  - app/Filament/Resources/Sources/Actions/SourceActions.php
  - app/Filament/Resources/Sources/Pages/CreateSource.php
  - app/Filament/Resources/Sources/Pages/EditSource.php
  - app/Filament/Resources/Sources/Pages/ListSources.php
  - app/Filament/Resources/Sources/RelationManagers/RunsRelationManager.php
  - app/Filament/Resources/Sources/Schemas/SourceFieldHelp.php
  - app/Filament/Resources/Sources/Schemas/SourceForm.php
  - app/Filament/Resources/Sources/SourceResource.php
  - app/Filament/Resources/Sources/Tables/SourcesTable.php
  - app/Http/Controllers/EventController.php
  - app/Ingestion/CanonicalUrl.php
  - app/Ingestion/Data/ChangeVerdict.php
  - app/Ingestion/Data/DuplicateMatch.php
  - app/Ingestion/Data/ExtractedItem.php
  - app/Ingestion/Data/ExtractionResult.php
  - app/Ingestion/Data/NormalisedFacts.php
  - app/Ingestion/Data/RecordOutcome.php
  - app/Ingestion/Health/SourceHealthTracker.php
  - app/Ingestion/Http/BodyValidator.php
  - app/Ingestion/Http/CappedSink.php
  - app/Ingestion/Http/CappedSinkFilter.php
  - app/Ingestion/Http/DnsHostResolver.php
  - app/Ingestion/Http/FetchRequest.php
  - app/Ingestion/Http/FetchResult.php
  - app/Ingestion/Http/HostBusy.php
  - app/Ingestion/Http/HostResolver.php
  - app/Ingestion/Http/HostThrottle.php
  - app/Ingestion/Http/HttpFetcher.php
  - app/Ingestion/Http/RedirectTo.php
  - app/Ingestion/Http/RobotsFetch.php
  - app/Ingestion/Http/RobotsPolicy.php
  - app/Ingestion/Http/RobotsRules.php
  - app/Ingestion/Http/RobotsVerdict.php
  - app/Ingestion/Http/SsrfGuard.php
  - app/Ingestion/Ics/IcsExtractor.php
  - app/Ingestion/Ics/IcsItemNormaliser.php
  - app/Ingestion/Ics/IcsTimezoneResolver.php
  - app/Ingestion/IngestRun.php
  - app/Ingestion/Pipeline/ChangeDetector.php
  - app/Ingestion/Pipeline/ContentHasher.php
  - app/Ingestion/Pipeline/DuplicateDetector.php
  - app/Ingestion/Pipeline/ExcerptBuilder.php
  - app/Ingestion/Pipeline/MissingItemDetector.php
  - app/Ingestion/Pipeline/SourceItemRecorder.php
  - app/Ingestion/Pipeline/TitleRules.php
  - app/Ingestion/Pipeline/WindowClassifier.php
  - app/Ingestion/RawBodyStore.php
  - app/Ingestion/RunAlreadyActive.php
  - app/Ingestion/RunLauncher.php
  - app/Jobs/FetchSourceJob.php
  - app/Models/CategoryMapping.php
  - app/Models/DuplicateDecision.php
  - app/Models/Event.php
  - app/Models/EventDraft.php
  - app/Models/EventSource.php
  - app/Models/Source.php
  - app/Models/SourceItem.php
  - app/Models/SourceRun.php
  - app/Ops/HealthReport.php
  - app/Providers/AppServiceProvider.php
  - app/Publishing/Actions/ApplySourceUpdate.php
  - app/Publishing/Actions/ApproveAllLikeThis.php
  - app/Publishing/Actions/ApproveDraft.php
  - app/Publishing/Actions/ChangeEventStatus.php
  - app/Publishing/Actions/ChangeOccurrenceStatus.php
  - app/Publishing/Actions/ConfirmSourceCancellation.php
  - app/Publishing/Actions/IgnoreSourceChange.php
  - app/Publishing/Actions/LinkEventSource.php
  - app/Publishing/Actions/MarkDraftDuplicate.php
  - app/Publishing/Actions/RecordNotDuplicate.php
  - app/Publishing/Actions/RejectDraft.php
  - app/Publishing/Actions/ReopenDraft.php
  - app/Publishing/Data/PublishableEventData.php
  - app/Publishing/SourceBaseline.php
  - app/Review/CategoryAssigner.php
  - app/Review/Data/DraftData.php
  - app/Review/DraftPublishability.php
  - app/Review/DraftWriter.php
  - app/Review/Exceptions/DraftNotReopenable.php
  - app/Review/ReviewQueue.php
  - app/Review/SaveRules.php
  - app/Review/VenueMatcher.php
  - app/Support/EventPageData.php
  - app/Support/OccurrenceCardData.php
  - config/ingest.php
  - database/factories/SourceFactory.php
  - database/migrations/2026_10_18_100100_create_sources_table.php
  - database/migrations/2026_10_18_100200_create_source_runs_table.php
  - database/migrations/2026_10_18_100300_create_source_items_table.php
  - database/migrations/2026_10_18_100400_add_ingestion_columns_to_event_drafts_and_events.php
  - database/migrations/2026_10_18_100500_create_crawl_hosts_table.php
  - database/migrations/2026_10_18_100600_create_event_sources_table.php
  - database/migrations/2026_10_18_100700_add_cancelled_dates_to_events.php
  - database/migrations/2026_10_18_100800_add_source_id_to_category_mappings_table.php
  - database/migrations/2026_10_18_100900_create_duplicate_decisions_table.php
  - database/seeders/DatabaseSeeder.php
  - database/seeders/SourceSeeder.php
  - resources/views/calendar/partials/occurrence-card.blade.php
  - resources/views/events/show.blade.php
  - resources/views/filament/resources/event-drafts/entries/duplicate-match.blade.php
  - resources/views/filament/resources/event-drafts/entries/source-diff.blade.php
  - resources/views/filament/resources/event-drafts/keyboard-help.blade.php
  - resources/views/filament/resources/event-drafts/pages/review-event-draft.blade.php
  - resources/views/filament/resources/sources/field-help.blade.php
  - resources/views/filament/resources/sources/preview.blade.php
  - routes/console.php
findings:
  critical: 3
  warning: 8
  info: 3
  total: 14
status: issues_found
---

# Phase 3: Code Review Report

**Reviewed:** 2026-10-18T13:50:00Z
**Depth:** standard. I traced calls across modules wherever a finding depended on a callee: recorder ↔ ChangeDetector ↔ DraftWriter ↔ Publishing actions, HttpFetcher ↔ RobotsPolicy/HostThrottle/SsrfGuard, and sabre `EventIterator`/`Settings`.
**Diff base:** d767eb3
**Files Reviewed:** 135
**Status:** issues_found

## Summary

I reviewed the Phase 3 ingestion backbone against AGENTS.md, `.ai/guidelines/attleborough.md`, the locked decisions D-01..D-26 and the deliberate choices recorded in the 03-xx SUMMARYs. I did not re-report the deferred items in `deferred-items.md` (Academy re-timed copies, item 4).

**What holds up (checked, not assumed):**

- **Fetcher safety.** The fetcher is careful. The DNS pin really reaches curl: there is an explicit `CurlHandler` and no `stream => true`. Every resolved address must be public. I probed `FILTER_FLAG_GLOBAL_RANGE` on PHP 8.4.12: it rejects IPv4-mapped, 6to4, Teredo, CGNAT, link-local and metadata addresses. Redirects re-run the SSRF guard and robots.txt on every hop. The byte cap counts decoded bytes, so a gzip bomb aborts the transfer. A refused `HostThrottle::reserve` books nothing.
- **Robots.txt** is matched without regex, so remote text cannot cause regex blow-up.
- **Write path.** Every `App\Publishing\Actions\*` call I traced runs `AdminGate::authorize` before its transaction. `OccurrenceGenerator` still never updates `status`; `cancelled_dates` only affects inserts.
- **Time zones.** ICS times are handled correctly. Floating times use the source zone, unknown TZIDs fail strictly, and all-day DATE values never go through UTC. Every date sum (`nextCheckAt`, the window, missing bounds) runs on Europe/London values.
- **Public pages.** They eager-load `event.primarySource.source` / `eventSources.source`, so attribution adds no N+1. Every Blade output is escaped.

**What does not hold up.** Three problems should block shipping. All three break the core promise ("trustworthy, up-to-date… nothing published is lost"):

1. **CR-01: phone numbers reach the public site.** `ExcerptBuilder` misses the most common UK phone layout, "(01953) 452300", and local numbers. Bulk approve and "Approve all like this" then publish them without the admin reading the excerpt. That breaks D-17.
2. **CR-02: an edit-page save can silently undo a feed change.** Saving on the draft edit page overwrites a draft that ingestion replaced meanwhile. `ApproveDraft` then sets the baseline to the *new* feed facts, so the event publishes the old facts and the source change is never raised.
3. **CR-03: reopening an old rejected draft breaks a linked item.** If the item has since been approved, reopening flips it back to a pending new draft:
   - its event stops getting update drafts;
   - approving it crashes with a 500;
   - rejecting it again moves the linked item's baseline and swallows any change made in between.

The warnings cover:

- lock-order deadlocks combined with conditional GET;
- the "complete run" check, which latches off for good once a feed shrinks;
- editing a live source's URL, identity or time zone (stale validators, mass false cancellations);
- Retry-After being ignored;
- source health that cannot see a permanently blocked source;
- personal data in `source_items` that is never deleted;
- the job timeout not covering item processing;
- domain exceptions surfacing as 500s.

## Critical Issues

### CR-01: The excerpt leaves common UK phone formats in, and bulk approval publishes them (D-17, PIPE-09)

**File:** `app/Ingestion/Pipeline/ExcerptBuilder.php:29,42`
**Issue:** `UK_PHONE` needs a word boundary and then an unbroken run of digits, with at most one space or hyphen between digits. It does not match a bracketed area code, a dotted separator or a local number. I ran it against the real class:

```
"Call (01953) 452300 to book" => "Call (01953) 452300 to book"
"Ring 01953.452300"           => "Ring 01953.452300"
"Call 452300 locally"         => "Call 452300 locally"
```

"(01953) 452300" is the standard way UK numbers are written, and CONTEXT says the Town Council feed "carries personal names, emails and phones". The excerpt is the draft's `description`. `DraftPublishability::bulkBlockerKeys` has no contact or PII key, so bulk approve and `ApproveAllLikeThis` (D-03, built for ~60 Town Council items a month) publish it without the admin opening the draft.

**Why it matters:** this is a locked-decision privacy breach (D-17: "Never publish organiser names or contacts from feeds") on public pages. The site cannot undo it once crawlers have cached the page.

**Fix:** widen the pattern and keep a deny-by-default backstop for bulk paths:
```php
// Area code optionally in brackets; separators: space, hyphen, dot; also 6-digit local numbers after a label.
private const string UK_PHONE = '(?:\+44\s?\(0\)\s?|\+44\s?|\(?\b0\d{2,4}\)?[\s.\-]?)(?:\d[\s.\-]?){5,8}\d\b';
private const string LOCAL_AFTER_LABEL = '\b(?:tel(?:ephone)?|phone|ring|call|mob(?:ile)?|text)\b\s*(?:on\s*)?[:.]?\s*\d(?:[\s.\-]?\d){5,7}\b';
```
Add a `contact_like` bulk blocker key to `DraftPublishability::bulkBlockerKeys()` for any discovered draft whose description still matches a loose `\d{3,}[\s.\-)]+\d{3,}` or `@` pattern after stripping, so that only a human approves those. Add these strings to `ExcerptBuilderTest`.

### CR-02: An edit-page save overwrites a draft that ingestion replaced meanwhile, and approval then sets the baseline to facts that were never published

**Files:**
- `app/Review/DraftWriter.php:313-327` (`update()`)
- `app/Review/Data/DraftData.php:246-248` (`fromReviewForm` carries the page's stale `excluded_dates` / `raw_payload`)
- `app/Filament/Resources/EventDrafts/Pages/EditEventDraft.php:141-156,163-180`
- `app/Publishing/Actions/ApproveDraft.php:165-174` (`acceptBaseline`)

**Issue:** `DraftWriter::update()` locks the draft and checks only that it is still pending. It then `fill()`s every content column from the form state loaded when the page opened. There is no version or `updated_at` check. Sequence:

1. The admin opens the edit page for a pending New draft (feed says 19:00).
2. A scheduled run (or Run now) sees the feed move to 20:00. `SourceItemRecorder` → `ChangeDetector::detect` returns REPLACE, and `replacePendingFromSource` rewrites the draft to 20:00 and stores the item's `content_hash`/`payload` for 20:00.
3. The admin clicks **Save & approve**. `update()` writes 19:00 back, plus the stale `excluded_dates`. `ApproveDraft` publishes 19:00, then `acceptBaseline()` sets `accepted_hash = $item->content_hash`, which is the **20:00** hash.
4. Every later run sees `content_hash === accepted_hash` → QUIET. The 19:00 event never gets an update draft.

**Why it matters:** the public calendar shows a wrong time with nothing in the queue to flag it. This is exactly the failure PIPE-06/D-06 exist to prevent. The admin does nothing wrong, and only one timing of a routine 05:30 run is needed.

**Fix:** add optimistic concurrency to the review save and tie the baseline to what was approved:
```php
// DraftWriter::update(EventDraft $draft, DraftData $data)
if ($locked->updated_at?->ne($draft->updated_at)) {
    throw new InvalidReviewTransition($locked->review_status, ReviewStatus::PendingReview); // "changed since you opened it"
}
```
Also store the item's `content_hash` on the draft when ingestion writes or replaces it (e.g. `raw_payload['item_content_hash']`). In `ApproveDraft::acceptBaseline()`, use that hash and the matching payload rather than whatever the item holds now. If the two differ, leave the baseline where it is (or set it to the draft's facts), so the next run raises the difference as an update.

### CR-03: Reopening an old rejected draft whose item has since been approved turns a linked item back into a "pending new" one

**Files:**
- `app/Publishing/Actions/ReopenDraft.php:59-81`
- `app/Publishing/Actions/RejectDraft.php:62-70`
- `app/Ingestion/Pipeline/ChangeDetector.php:71-78`
- `app/Publishing/Actions/LinkEventSource.php:63-64`
- `app/Filament/Resources/EventDrafts/Actions/ReviewActions.php:630-648`

**Issue:** D-23 re-drafts a rejected item when its facts change. So an item can have rejected draft A *and* a later approved draft B that linked it to an event through `event_sources`. Draft A stays visible until the retention purge, and Reopen is offered on it. `ReopenDraft` only refuses when another *pending* draft exists. It then sets the item's `review_outcome = 'pending'` and `current_draft_id = A`. From then on:

- **Ingestion stops raising changes for the event.** `SourceItemRecorder` sees a pending New draft. `ChangeDetector::detect()` takes the `pending->kind === New` branch *before* the linked branch, so a time or venue change REPLACEs draft A instead of raising an Update draft for the published event. A cancellation only supersedes A.
- **Approving A crashes.** `PublishEvent` creates a second event, then `LinkEventSource` throws `InvalidArgumentException(ITEM_ALREADY_LINKED)`. `ReviewActions::run()` does not catch it, so the whole thing rolls back and the admin gets a 500. "Mark as duplicate" fails the same way.
- **Rejecting A again silently drops the change.** `RejectDraft` sets `accepted_hash`/`accepted_payload` of a **linked** item to the current payload, which may hold the change absorbed in the first bullet. The event never receives it.

**Why it matters:** a single routine admin click (Reopen) silently stops updates for a published event, and the only ways out are a crash or permanent loss of the change.

**Fix:** refuse to reopen when the item is linked or already approved, and never move a linked item's baseline from RejectDraft:
```php
// ReopenDraft, after locking $item
if ($item !== null && (EventSource::query()->where('source_item_id', $item->id)->exists()
        || in_array($item->review_outcome, ['approved', 'duplicate'], true))) {
    throw new DraftNotReopenable('This feed item is already published as an event.');
}
// RejectDraft: only touch the item when it is not linked
if ($item !== null && ! EventSource::query()->where('source_item_id', $item->id)->exists()) { ... }
```
In `ChangeDetector::detect()`, check `$link` before the pending-New branch. Also hide Reopen in `ReviewActions::reopen()` under the same condition.

## Warnings

### WR-01: Ingestion and the review actions take locks in opposite orders; a deadlock victim on the ingestion side is silently lost behind conditional GET

**Files:**
- `app/Ingestion/Pipeline/SourceItemRecorder.php:104-109,276-282` (item → draft)
- `app/Publishing/Actions/ApproveDraft.php:71,167`, `MarkDraftDuplicate.php:77,115`, `RejectDraft.php`, `ApplySourceUpdate.php`, `IgnoreSourceChange.php` (draft → item)
- `app/Ingestion/IngestRun.php:143,180-190`

**Issue:** this is a classic ABBA lock pattern. 03-09 records that "a real race would deadlock … and PostgreSQL would abort one side" and treats that as acceptable. Two consequences were not considered:

- **Ingestion is the victim.** `IngestRun::process()` catches the `QueryException` as a per-item `skipped_parse_error` and the run still finishes **Succeeded**. `finish()` stores the response's ETag/Last-Modified, and `request()` sends them next time because the previous run succeeded. If the server answers 304, the failed item is never re-processed until the feed body changes, so a cancellation or time change can be held back for days.
- **The admin is the victim.** `QueryException` is not caught by `ReviewActions::run()`, so the admin gets a 500.

**Fix:** use one lock order everywhere. Simplest: in `SourceItemRecorder::record()`, lock the pending draft first (`select … from event_drafts where source_item_id = ? and review_status = 'pending_review' for update`), then the item. Separately, when any item ends in an exception, don't store validators: pass a `$hadItemFailures` flag to `finish()` and skip `etag`/`last_modified`, so the next run fetches the whole body again.

### WR-02: `is_complete` latches off for good after a lasting drop in the feed, silently disabling missing-item detection

**File:** `app/Ingestion/Health/SourceHealthTracker.php:76,82-83`
**Issue:** a run is complete only if `items_found * 2 >= previousComplete->items_found`, where `previousComplete` is the newest run that was itself complete. An incomplete run never becomes the reference. If a feed lastingly halves (Town Council default window, a seasonal lull, the organiser trimming old items), every later run compares against the old large count. `is_complete` then stays false for good, and `MissingItemDetector::detect()` returns early on every run. Nothing in the UI says so.
**Fix:** compare against a moving reference that can recover, for example the median `items_found` of the last 5 succeeded live runs, or accept the new level after N (e.g. 3) consecutive runs within ±20% of each other. Show "Missing-item detection paused: last complete check {date}" on the source page when the latest succeeded run is incomplete.

### WR-03: Editing a live source's URL, item identity or default time zone reuses stale validators and can flood the queue with false cancellations

**Files:**
- `app/Filament/Resources/Sources/Pages/EditSource.php:48-56`
- `app/Filament/Resources/Sources/Schemas/SourceForm.php:105-111,143-160`
- `app/Ingestion/IngestRun.php:143`

**Issue:** `prepareForSave()` lets an active source change `url`, `parser_config.identity` and `default_timezone`, and nothing else is reset:

- **URL change:** `etag`/`last_modified` from the old URL are still sent. `If-Modified-Since` with the old feed's date can draw a 304 from the new server, so the new feed is never read.
- **Identity change** (e.g. `uid` → `summary_start`): every item gets a new `external_id`. All old items go missing, so after two complete runs every linked event gets a whole-event cancellation draft. Every current item becomes a fresh New draft, and none is flagged as a duplicate, because `DuplicateDetector` excludes events already linked to the same source.
- **Time zone change:** every floating time shifts, so every linked event gets an update draft.

Unlike re-activation, none of these paths goes through a test run (D-01).

**Fix:**
- In `mutateFormDataBeforeSave`, when `url` changes, clear `etag` and `last_modified`.
- Refuse `parser_config.identity` and `default_timezone` changes once the source has any `source_items`, or require pausing plus a fresh test run whose preview shows the consequence ("N linked events would be reported missing").

### WR-04: 429/503 `Retry-After` is parsed and then thrown away

**File:** `app/Ingestion/Http/HttpFetcher.php:403-413`; `FetchResult::$retryAfterSeconds` has no reader.
**Issue:** `retryAfter()` computes the delay, but nothing writes it to `crawl_hosts.next_allowed_at` and nothing reads it. After a 429, "Run now" (or the next due slot) hits the host again as soon as the 5-second minimum spacing allows. That falls short of D-02/SRC-05 politeness, and a rate-limiting host may start blocking the crawler for good.
**Fix:** in `fetchHop()`, when the result is `RateLimited` with `retryAfterSeconds`, push the host back:
```php
DB::table('crawl_hosts')->where('host', HostThrottle::keyFor($url))
    ->update(['next_allowed_at' => DB::raw("greatest(next_allowed_at, now())")]); // or compute now()+retryAfter in UTC
```
Better, add `HostThrottle::backOff(string $host, int $seconds)`. When that wait exceeds `max_inline_wait`, `waitForSlot` then throws HostBusy and later runs respect it.

### WR-05: Source health cannot see a source that is permanently blocked or not checking, so System Health says "all healthy"

**Files:** `app/Ops/HealthReport.php:113-160`; `app/Ingestion/Health/SourceHealthTracker.php:44`
**Issue:** by design, blocked runs never move health. `HealthReport::sources()` looks only at `health` and `structure_alert_at`. A source whose robots.txt or bot wall blocks every run, or whose runs keep being abandoned in the queue (the Crawl-delay case 03-15 warns about only on the edit page), stays "healthy". System Health and `ops:health` then report "N active sources, all healthy" while the calendar silently goes stale (SRC-08/SRC-09).
**Fix:** add a staleness leg. Treat an active source as stale when `last_success_at` is null or older than 2× its check interval (plus jitter), and report "{n} not checked successfully since {date}". Keep this warning-only, like the rest of `sources()`.

### WR-06: Personal data from feeds is kept for ever in `source_items`

**Files:**
- `app/Ingestion/Pipeline/SourceItemRecorder.php` (`storeContent`)
- `app/Models/SourceItem.php` (no `Prunable`)
- `app/Console/Commands/IngestPruneRaw.php`

**Issue:** `raw_fragment`, `payload` (organiser name and email, full description with phones) and `accepted_payload` are written for every item and never deleted, even long after the event has ended. Raw bodies are pruned after 30 days and drafts after the retention period, but source items are not. D-17 allows keeping organiser fields internally *for review*. Keeping them indefinitely after review breaks storage limitation for the private individuals the Town Council feed names.
**Fix:** make `SourceItem` prunable, or extend `ingest:prune-raw`:
- for items whose `last_known_start_at` is more than the published retention in the past, null `raw_fragment` and drop `organiser`/`description` from `payload` and `accepted_payload`;
- delete unlinked items (no `event_sources` row) once they are past and not seen for 90 days.

### WR-07: The job timeout covers the fetch but not item processing

**Files:** `app/Jobs/FetchSourceJob.php:42`; `config/ingest.php:32,58`; `app/Ingestion/IngestRun.php:172-197`
**Issue:**
- **The budget.** `timeout = 75` while `fetch_deadline = 55`, which leaves about 20 seconds for extraction plus up to `max_events = 5000` per-item transactions. Each new item runs two pg_trgm duplicate queries, a category assignment and quality scoring.
- **Large feeds.** A large first activation (or a feed that grows) will be killed by the worker's SIGALRM partway through the loop.
- **What a kill leaves.** The run stays `running` until the 15-minute janitor marks it abandoned, and it counts as a health failure. Validators are not stored and missing detection does not run. Every day the same thing happens again, and after 3 days the source is degraded.

**Fix:** bound processing explicitly. Either raise `timeout` (and the database queue's `retry_after`) to cover `fetch_deadline + processing budget`, or check a processing deadline in `process()`. On hitting it, finish the run as `failed`/`timeout` with partial counters, and still release the items already written.

### WR-08: Documented domain and DB exceptions surface as 500s, and bulk approval stops halfway

**Files:**
- `app/Filament/Resources/EventDrafts/Actions/ReviewActions.php:630-648`
- `app/Publishing/Actions/ApproveAllLikeThis.php:138-146`
- `app/Publishing/Actions/MarkDraftDuplicate.php:66` (`@throws InvalidArgumentException`)

**Issue:**
- `ReviewActions::run()` catches only `InvalidReviewTransition`, `ModelNotFoundException`, `DraftNotPublishable` and `DraftNotReopenable`. It misses `InvalidArgumentException`, which `MarkDraftDuplicate` and `LinkEventSource` both declare, and `QueryException` (deadlock or serialization failure, see WR-01).
- `ApproveAllLikeThis::approveOne()` has the same gap. One such exception stops "Approve all like this" after some drafts are already published, and the admin gets an error page instead of the "Approved n of m" summary.

**Fix:** catch `InvalidArgumentException` in both places and turn it into a refusal notification or a `blocked` skip. Catch `QueryException` with SQLSTATE `40P01`/`40001` as "Busy, try again" (or skip with a `retry` key in the bulk path).

## Info

### IN-01: SsrfGuard lets the local-use NAT64 prefix and IPv4-translated addresses through

**File:** `app/Ingestion/Http/SsrfGuard.php:115-116`
**Issue:** I probed PHP 8.4.12. `FILTER_FLAG_GLOBAL_RANGE` accepts `64:ff9b:1::a00:1` (RFC 8215 local-use NAT64, which embeds 10.0.0.1) and `::ffff:0:a00:1` (IPv4-translated, SIIT). The explicit block only covers `64:ff9b::/96`. These are harmless on a VPS without a NAT64/SIIT gateway, but the guard's docblock claims full coverage.
**Fix:** also refuse `str_starts_with($hex, '0064ff9b0001')` (64:ff9b:1::/48) and `str_starts_with($hex, '00000000000000000000ffff0000')` (::ffff:0:0/96).

### IN-02: A preview can disagree with the live run for items already linked to events, despite the docblock's guarantee

**File:** `app/Ingestion/Pipeline/SourceItemRecorder.php:166-175` versus `126-139`
**Issue:** `verdict()` calls `classify()`, which applies title rules and the window to every known item. `record()` skips both for tracked items (linked, or with a pending New draft). When a paused source is re-previewed (D-01), a linked event that now matches a skip rule or has moved beyond 90 days shows as "skipped" in the preview. The live run then compares it, and may raise an update. The docblock says previews and live runs "cannot drift".
**Fix:** in `verdict()`, apply the same tracked bypass (`self::link($existing) !== null || pending New`) before calling `classify()`.

### IN-03: A draft's `source_url` falls back to the `.ics` feed URL

**File:** `app/Review/Data/DraftData.php:308-317`
**Issue:** when an item has no URL and the source has no events page, `sourceUrl()` stores `$source->url`, the feed file, as the draft's and then the event's `source_url`. 03-16 deliberately never links the feed file publicly (it uses `https://{host}/` instead). Today the attribution hides this field on public pages, but the review page links it, and any event that loses its link (or a future export) would show the raw `.ics`.
**Fix:** use the same `https://{feed host}/` fallback as `EventPageData::attributionFor()`, or leave `source_url` null and rely on `event_sources` for attribution.

---

_Reviewed: 2026-10-18T13:50:00Z_
_Reviewer: the agent (gsd-code-reviewer)_
_Depth: standard_
