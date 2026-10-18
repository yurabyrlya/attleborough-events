---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 05
subsystem: ingestion
tags: [ingestion, queue, ics, drafts, excerpt, privacy, title-rules, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-01 schema and models; 03-02 HttpFetcher; 03-03 robots, HostThrottle/HostBusy, RawBodyStore; 03-04 IcsExtractor, ContentHasher, WindowClassifier"
provides:
  - "App\\Jobs\\FetchSourceJob(int $runId): guarded queued → running claim, HostBusy release, uniqueFor 900, timeout 75, maxExceptions 1, retryUntil +6 h"
  - "App\\Ingestion\\IngestRun::execute(SourceRun): fetch → raw store → extract → record → run status, counters, source state; IngestRun::errorMessage()"
  - "App\\Ingestion\\Pipeline\\SourceItemRecorder::record(run, source, item, nowLondon): bucket; COUNTER map; fallbackDescription() (D-21)"
  - "App\\Ingestion\\Pipeline\\ExcerptBuilder::make(?string): ?string (PIPE-09)"
  - "App\\Ingestion\\Pipeline\\TitleRules::allows(rules, title) with MODE_SKIP / MODE_KEEP (D-04)"
  - "DraftWriter::createFromSource(data, kind, source, item, sourceValues); DraftData::fromSourceItem(facts, source, description, parseErrors, rawPayload); DraftData excludedDates + parseErrors"
  - "App\\Enums\\SourceItemStatus (Active, Cancelled, Missing), cast on SourceItem"
affects: [03-06 dispatch/prune commands, 03-07 health and preview, 03-08 approval baseline, 03-09 change detection, 03-13 review UI, 03-16 public excerpt]

actuals:
  tokens: 18400
  tasks: 3
  commits: 3
plan_head_before: e841a1bbefeb2c9aa1f3c3884807cd0aa29f0ce4
plan_head_after: 5103b9fc069367651790f5d244ab062371e985f4

tech-stack:
  added: []
  patterns:
    - "Job takes only the run id; a guarded UPDATE ... WHERE status = 'queued' claims the run (0 rows → return)"
    - "Released polite waits never count as attempts: retryUntil (fixed at dispatch) + maxExceptions 1, properties not attributes (C8)"
    - "One DB transaction per item; a throwing item is a skipped_parse_error counter plus an ids-only log line"
    - "Seen-first: a known identity is touched before it is bucketed"
    - "Feed text fitted to draft columns on a grapheme boundary (never lost to a DB error)"

key-files:
  created:
    - app/Enums/SourceItemStatus.php
    - app/Ingestion/IngestRun.php
    - app/Ingestion/Pipeline/SourceItemRecorder.php
    - app/Ingestion/Pipeline/ExcerptBuilder.php
    - app/Ingestion/Pipeline/TitleRules.php
    - app/Jobs/FetchSourceJob.php
    - tests/Feature/Ingestion/RunNowTracerTest.php
    - tests/Feature/Ingestion/SourceItemRecorderTest.php
    - tests/Unit/Ingestion/ExcerptBuilderTest.php
    - tests/Unit/Ingestion/TitleRulesTest.php
  modified:
    - app/Models/SourceItem.php
    - app/Review/Data/DraftData.php
    - app/Review/DraftWriter.php
    - app/Review/VenueMatcher.php
    - tests/Feature/Ingestion/IngestionSchemaTest.php

key-decisions:
  - "Unknown identities that are skipped (past, far future, rule, parse error) get no source_items row, so an item far ahead today is still drafted when it enters the 90-day window"
  - "Conditional GET validators are sent only when the previous finished live run was succeeded or not_modified; a 304 also refreshes stored validators; a validator too long for its column is dropped, never cut"
  - "error_message: our own wording plus enum labels/status codes, trimmed and cut with mb_substr to 500 characters"
  - "observed_until = latest London date of any item's next instance (or first date), all buckets included; is_complete stays false (03-07 owns it)"
  - "duration_ms is the fetch duration from FetchResult; total run time is finished_at - started_at"
  - "Preview runs throw a LogicException in IngestRun until 03-07 adds the dry mode (nothing dispatches previews yet)"
  - "A label (tel/phone/mobile/email) is removed only together with the contact it introduces, so 'Bring your phone' survives; a space is put back where removal leaves ' ,'"
  - "D-22 lives in DraftData::fromSourceItem (VenueMatcher::exact compared with sources.default_venue_id); venue name/address are kept as typed either way"
  - "DraftWriter::update re-derives ingested drafts with the ICS CATEGORIES kept in raw_payload, so a review save does not change the category inputs"
  - "fromReviewForm carries the draft's excluded_dates, so adding excluded_dates to toAttributes() cannot wipe EXDATEs on a review save"
  - "A master STATUS:CANCELLED on a never-seen item is still drafted as new here (plan Task 1 wording); 03-09 owns the PIPE-07-empty 'no draft' rule"

patterns-established:
  - "Ingestion tests: route Http::fake through one closure (robots path vs feed) — repeated Http::fake calls append stubs and the first one keeps winning"
  - "Final classes are not mocked: per-item failures are provoked with a transactional PostgreSQL trigger; unexpected job errors with a RawBodyStore subclass"

requirements-completed: [SRC-05, SRC-06, SRC-07, PIPE-01, PIPE-09]

coverage:
  - id: D1
    description: "Queued Baptist run → succeeded, 64 found, one pending discovered ICS draft per in-window item, counters filled, raw body stored, source state updated"
    requirement: SRC-07
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/RunNowTracerTest.php#turns a queued Baptist Church run into one pending draft per in-window item"
        status: pass
      - kind: integration
        ref: "tests/Feature/Ingestion/RunNowTracerTest.php#stores the raw body and updates the source after a successful run"
        status: pass
  - id: D2
    description: "Same unchanged calendar again → zero drafts, every known item touched as unchanged"
    requirement: EXT-01
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/RunNowTracerTest.php#creates no drafts when the same unchanged calendar is fetched again"
        status: pass
  - id: D3
    description: "Run outcomes: blocked, not_modified, content_invalid (body stored, no body text in message), http_status, parse_error, HostBusy release, abandoned"
    requirement: SRC-06
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/SourceItemRecorderTest.php (outcome tests)"
        status: pass
  - id: D4
    description: "Skips counted (past/far future/rule/parse error), one bucket per item on every run, zero-VEVENT run all zeros"
    requirement: PIPE-01
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/SourceItemRecorderTest.php (afterEach counter invariant, title rules, parse errors, zero events)"
        status: pass
  - id: D5
    description: "Excerpt: tags/entities, emails, UK phones and labels removed, 200 graphemes with ellipsis, emoji/accent safe, D-21 fallback"
    requirement: PIPE-09
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/ExcerptBuilderTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Ingestion/SourceItemRecorderTest.php#applies title rules, the D-21 fallback and the D-22 default venue to the Town Council feed"
        status: pass
  - id: D6
    description: "D-17: feed organiser never copied to draft fields; kept in raw_payload only"
    requirement: PIPE-09
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/SourceItemRecorderTest.php#never copies the feed organiser and strips contacts from the excerpt"
        status: pass
  - id: D7
    description: "D-04 title rules: whole word, case-insensitive, NFC, skip beats keep, malformed ignored"
    requirement: PIPE-01
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/TitleRulesTest.php"
        status: pass

duration: ~15min
completed: 2026-10-18
---

# Phase 3 Plan 05: Run Engine, Recorder, Excerpts and Title Rules Summary

**A queued run now goes FetchSourceJob → HttpFetcher → RawBodyStore → IcsExtractor → SourceItemRecorder → DraftWriter. Each item in the window becomes a pending discovered ICS draft with a 200-grapheme excerpt with contacts removed (or the D-21 fallback), the source's default venue (D-22) and default category, and no organiser data (D-17). The unchanged calendar run again creates no drafts. Each fetch outcome is recorded as a run status, and each item found is counted in exactly one bucket.**

## Performance

- **Duration:** ~15 min (09:13Z–09:28Z on 2026-10-18)
- **Tasks:** 3 (1 tracer + 2 TDD)
- **Files:** 10 created, 5 modified

## Accomplishments
- `FetchSourceJob` takes only the run id and claims the run with a guarded `UPDATE ... WHERE status='queued'`.
  - On `HostBusy` it puts the run back to queued (started_at null) and calls `release($seconds)`.
  - Any other Throwable marks the run `failed/abandoned`, with the exception class only in the message, and is rethrown.
  - Settings are properties: uniqueFor 900, timeout 75, maxExceptions 1, retryUntil +6 h, connection database, queue default. `uniqueId()` is the source.
- `IngestRun::execute()`:
  - sends conditional validators only after a successful live run;
  - records http_status, final_url, bytes and duration_ms;
  - stores any body that arrived, so a bot wall's page is kept;
  - maps the outcome to blocked, not_modified, failed (with kind) or failed parse_error;
  - loops the items with per-item isolation;
  - fills the counters and observed_from/until;
  - updates the source's last_run_at, last_success_at, last_items_found, etag and last_modified.
- `SourceItemRecorder` touches a known item first, then sorts it into one bucket in this order: parse error → title rule → window → unchanged/changed (raw_hash) → new. A new item gets a source item (active, review pending) and a New draft, both in the same transaction.
- `DraftWriter::createFromSource()` forceFills kind, source and item links, raw_payload and parse_errors. It passes the ICS CATEGORIES to CategoryAssigner and sets `source_items.current_draft_id` in the same transaction.
- `ExcerptBuilder` and `TitleRules` are the plan's pure helpers, with unit tables.

## Task Commits

1. **Task 1 (tracer): run-now tracer — queued ICS run to pending drafts with counters**: `b31205d` (feat)
2. **Task 2 (tdd): PII-free 200-grapheme excerpts and per-source title rules**: `2c8595f` (feat)
3. **Task 3 (tdd): run outcomes, counters, privacy and source defaults (+ column-fit fix)**: `5103b9f` (test)

**Tracer gate:** after Task 1, `<verify>` was re-run end to end (RunNowTracerTest + DraftWriterTest + IngestionSchemaTest, 36 passed) before expanding.

## Files Created/Modified
- `app/Jobs/FetchSourceJob.php`: thin queued job
- `app/Ingestion/IngestRun.php`: the run engine
- `app/Ingestion/Pipeline/SourceItemRecorder.php`: per-item buckets and draft creation
- `app/Ingestion/Pipeline/ExcerptBuilder.php`, `TitleRules.php`: PIPE-09 and D-04
- `app/Enums/SourceItemStatus.php`, `app/Models/SourceItem.php` (status cast)
- `app/Review/DraftWriter.php`: createFromSource, sourceValues threaded into derive, D-22 docblock
- `app/Review/Data/DraftData.php`: fromSourceItem, excludedDates (in toAttributes, kept by fromReviewForm), parseErrors, column fitting
- `app/Review/VenueMatcher.php`: docblock names D-22 (C5)
- Tests: RunNowTracerTest (4), SourceItemRecorderTest (18 plus an afterEach invariant), ExcerptBuilderTest, TitleRulesTest. IngestionSchemaTest now expects the status enum.

## Decisions Made
See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Long feed text would have lost the whole item**
- **Found during:** Task 3, while designing the per-item failure test.
- **Issue:** `event_drafts.title` is varchar(255) and `venue_name` is varchar(120), but the extractor does not cut SUMMARY or LOCATION. A long title or location would make the draft insert fail. The item would roll back and count as a parse error, never reaching the admin.
- **Fix:** `DraftData::fromSourceItem` fits title, venue name and address on a grapheme boundary with an ellipsis. A postcode over 10 characters becomes null. A source URL over 2048 characters falls back to the events page, then the feed URL. All three are tested.
- **Commit:** `5103b9f`

**2. [Rule 2 - Correctness] Review saves would have wiped EXDATEs and ICS categories**
- Adding `excluded_dates` to `toAttributes()` (as the plan requires) means `fromReviewForm` must carry the draft's value, otherwise an edit-page save writes `[]`. It now carries it.
- `DraftWriter::update()` also re-derives with the ICS CATEGORIES stored in raw_payload.
- **Commit:** `b31205d`

**3. [Rule 2 - Correctness] Validators dropped, not cut**
- `sources.last_modified` is varchar(64). Cutting a header to fit would make every later conditional GET mismatch, so an over-long validator is stored as null.
- **Commit:** `b31205d`

### Judgement calls (unattended run)
- **Commits on `main`**, as the orchestrator instructed (`branching_strategy: none`).
- **SourceItemRecorder returns the bucket string**, as planned. drafts_created is counted by IngestRun as "new bucket". Each new item always gets exactly one draft in the same transaction, so the two counts cannot differ.
- **Master-cancelled new items** are drafted, following Task 1's literal rule. 03-09's "PIPE-07 empty" truth will change this to "no draft, item cancelled". None of the three real fixtures has a master STATUS:CANCELLED.
- **Test technique:** SourceItemRecorder, IcsExtractor and DraftWriter are `final`, so nothing is mocked. A transactional PostgreSQL trigger makes one item's draft insert fail. A `RawBodyStore` subclass provokes the unexpected-error path.
- **RED order:** RunNowTracerTest was RED (job class missing) before Task 1 code was written. The Task 2 unit tests were RED (classes missing) before ExcerptBuilder and TitleRules existed. Task 3's tests are mostly characterisation tests of the Task 1–2 code. They found the column-fit bug and the arrow-function counter mistake in the test itself.

---

**Total deviations:** 3 auto-fixed, plus the judgement calls above. **Impact:** the plan was delivered as specified; the fixes stop feed text from being silently lost.

## Known Stubs
- `IngestRun::execute()` refuses preview runs with a LogicException. This is intentional: the D-01 preview arrives in 03-07, and nothing dispatches previews yet.
- `is_complete` and source health (consecutive_failures, health) are not touched here. They are owned by 03-07, as that plan specifies.

(`.planning/WINDOWS.md` does not exist in this project, so nothing was appended.)

## Threat Flags
None. The new surfaces are the planned ones:
- T-03-14: the organiser is never mapped, and the excerpt strips contacts (tested).
- T-03-15: feed text is stored as plain text.
- T-03-16: retryUntil plus maxExceptions, with the release tested.

## Issues Encountered
- Two test-only mistakes, both fixed before commit:
  - Repeated `Http::fake()` calls append stubs, so the first fake kept answering.
  - An arrow function captured the request counter by value.
- The full suite reports one PHPUnit warning that predates this plan.

## User Setup Required
None.

## Next Phase Readiness
- **03-06:** `ingest:dispatch-due` and "Run now" insert a queued run and `FetchSourceJob::dispatch($run->id)`. `ingest:prune-raw` calls `RawBodyStore::prune(config('ingest.raw_retention_days'))`.
- **03-07:** add the preview dry mode. Split `SourceItemRecorder::classify()`; it is already write-free and private. Call SourceHealthTracker after `finish()` and from FetchSourceJob's abandoned path. Compute is_complete.
- **03-09:** extend the CHANGED branch (it currently stores the new raw/content hash and payload only). Add the never-approved cancelled rule for new items.
- Full Unit+Feature suite: 1303 passed. PHPStan: 0 errors. Pint: clean. The new tests also pass under `TZ=America/New_York`.

## Requirements Traceability
- Marked **Complete** in REQUIREMENTS.md:
  - SRC-05: robots refusal is now a recorded `blocked` run, and the 03-02/03-03 fetcher already provides every other clause.
  - SRC-06: a bot wall or wrong content is now a recorded failed `content_invalid` run.
  - SRC-07: runs are logged with status, all counters and a safe error message. `skipped_out_of_area` already exists and stays 0 until Phase 4's geofence.
  - PIPE-01: past and far-future items are skipped and counted.
  - PIPE-09: drafts and published events use the excerpt, and the full text stays internal.
- **EXT-01 left Pending:** STATUS:CANCELLED handling (cancellation drafts) arrives in 03-09.

## Self-Check: PASSED
