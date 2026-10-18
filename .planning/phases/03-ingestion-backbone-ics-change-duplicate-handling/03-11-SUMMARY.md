---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 11
subsystem: ingestion
tags: [ingestion, duplicates, pg_trgm, canonical-url, review, event-sources, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-01 event_drafts duplicate_of_draft_id/duplicate_reason/duplicate_score columns; 03-05 SourceItemRecorder/DraftWriter::createFromSource; 03-08 LinkEventSource + event_sources; 03-09 ChangeDetector (linked items raise update drafts); 03-10 SourceBaseline"
provides:
  - "duplicate_decisions table + App\\Models\\DuplicateDecision (record() upserts on the coalesced pair index)"
  - "App\\Ingestion\\CanonicalUrl::of()"
  - "App\\Ingestion\\Data\\DuplicateMatch (columns(), toPreview(), formatScore())"
  - "App\\Ingestion\\Pipeline\\DuplicateDetector::find(NormalisedFacts, Source, ?SourceItem, ?int venueId)"
  - "App\\Publishing\\Actions\\MarkDraftDuplicate(EventDraft, Event|EventDraft, User) and RecordNotDuplicate(EventDraft, User)"
  - "SourceItemRecorder::previewDuplicate(); D-01 preview rows carry duplicate {title, reason, score}"
  - "DraftWriter::createFromSource(..., ?DuplicateMatch) and replacePendingFromSource(..., DuplicateMatch|false|null)"
  - "DraftPublishability bulk key 'duplicate' covers duplicate_of_draft_id"
affects: [03-13 review UI (side-by-side duplicate panel, Mark as duplicate / Not a duplicate buttons), 03-17 UAT, Phase 4 extractors (same detector for RSS/TEC/JSON-LD items)]

actuals:
  tokens: 21000
  tasks: 3
  commits: 3
plan_head_before: b11a514ff6782db2c5508575efeb8691187b831a
plan_head_after: 54f2b4e39859f8ce138d50e445950f97a1650205

tech-stack:
  added: []
  patterns:
    - "pg_trgm similarity(lower(a), lower(?)) >= cast(? as real), bound parameters only; constant time-guard fragments chosen in PHP"
    - "URL matching: SQL prefilter on lower-cased host and path, exact comparison of CanonicalUrl forms in PHP"
    - "Decision memory keyed on the source item (stable across re-drafts), upserted with ON CONFLICT on an expression index"
    - "Sentinel parameter (false = leave as is) for optional column replacement in DraftWriter"

key-files:
  created:
    - database/migrations/2026_10_18_100900_create_duplicate_decisions_table.php
    - app/Models/DuplicateDecision.php
    - app/Ingestion/CanonicalUrl.php
    - app/Ingestion/Data/DuplicateMatch.php
    - app/Ingestion/Pipeline/DuplicateDetector.php
    - app/Publishing/Actions/MarkDraftDuplicate.php
    - app/Publishing/Actions/RecordNotDuplicate.php
    - tests/Unit/Ingestion/CanonicalUrlTest.php
    - tests/Feature/Ingestion/DuplicateDetectorTest.php
    - tests/Feature/Publishing/MarkDuplicateTest.php
  modified:
    - app/Ingestion/Pipeline/SourceItemRecorder.php
    - app/Ingestion/IngestRun.php
    - app/Review/DraftWriter.php
    - app/Review/Data/DraftData.php
    - app/Review/DraftPublishability.php

key-decisions:
  - "Migration timestamp 2026_10_18_100900 (the plan's 100700 is already add_cancelled_dates_to_events from 03-09)"
  - "Same-source exclusion applies to events too: an event linked to the item's source through event_sources is never a candidate (plan truth); RESEARCH F4's Academy re-timed-copy case is therefore not flagged (deferred item 4)"
  - "The detector runs for new identities, re-drafts AND replaces of a pending New draft (a moved item is re-checked); remembered not_duplicate pairs stay excluded"
  - "Ranking: URL match (1.0) before fuzzy; within fuzzy, score desc, events win ties over drafts, then id asc"
  - "Series items are compared by their next instance (nextInstanceStartUtc London date); draft candidates by their start_date/start_time"
  - "Venue leg: same venue_id, or similarity of the item's venue name (else the name of its D-22 default venue) with the event's venue / the draft's venue or typed venue name"
  - "CanonicalUrl keeps the scheme (http and https differ) and drops user info as well as the plan's list"
  - "MarkDraftDuplicate accepts Event|EventDraft: a pending draft target → 'Approve or reject the other draft first.'; an approved draft stands for its event; non-new drafts refused (WRONG_KIND); reason/score kept only when the target is the flagged event"
  - "RecordNotDuplicate on a draft with no warning is a no-op; on a sourced draft it re-runs the detector so the warning moves to the next match"
  - "duplicate_decisions has no candidate_source_item_id (plan schema: exactly one of candidate_event_id / candidate_draft_id) and no created/updated timestamps (decided_at only)"
  - "PIPE-05 and REV-05 stay Pending: 03-13 lists both and adds the side-by-side panel and buttons (same precedent as REV-06/07 in 03-10)"

patterns-established:
  - "Duplicate tests: dupPublish() publishes through PublishEvent (manual path); dupFacts() builds NormalisedFacts for direct detector calls; dupServeBoth() serves two sources in one Http::fake"

requirements-completed: []

coverage:
  - id: D1
    description: "Tracer: published 'Yoga with [name removed]' 25 Oct 10:00 → only the 25 Oct TC Yoga draft flagged fuzzy 0.783; bare 'Yoga' (0.2777778) not flagged; empty DB → 0 warnings; preview row notes the duplicate"
    requirement: PIPE-05
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/DuplicateDetectorTest.php#flags only the 25 Oct Town Council Yoga draft as a fuzzy duplicate of the published \"Yoga with [name removed]\""
        status: pass
  - id: D2
    description: "Mark as duplicate: status duplicate, event_id null, event unchanged, event_sources linked_via duplicate (primary only when none), item baseline + review_outcome duplicate, decision row; D-11 follow-up update draft; not-duplicate memory across runs; warning moves to next match; pending-draft target refused; submission draft without link; admin gate; transitions"
    requirement: REV-05
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/MarkDuplicateTest.php"
        status: pass
  - id: D3
    description: "Edges: canonical URL (events and drafts), threshold at/above measured value, 60/61 minutes both ways, all-day guard, Junior/Senior Youth same source and cross-source, no URL / no venue, tie → lower id, score ranking, numeric(4,3) rounding and two-decimal display, hidden event/occurrence, same-source drafts/linked events/own replaced draft"
    requirement: PIPE-05
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/CanonicalUrlTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Ingestion/DuplicateDetectorTest.php"
        status: pass

duration: ~16min
completed: 2026-10-18
---

# Phase 3 Plan 11: Duplicate Warnings, Mark as Duplicate and Not a Duplicate Summary

**New feed items are now checked against published events (by occurrence) and against pending drafts from other sources before their draft is written. A match by canonical URL, or by same London date, start within 60 minutes, same or similar venue and pg_trgm title similarity ≥ 0.3, is stored on the draft, so bulk approve skips it as "possible duplicate". "Mark as duplicate" links the feed item to the existing event (publishing nothing), so later feed changes raise update drafts for that event. "Not a duplicate" is remembered against the feed item, so the pair is never flagged again.**

## Performance

- **Duration:** ~16 min (11:44Z–12:00Z on 2026-10-18)
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files:** 10 created, 5 modified

## Accomplishments
- `DuplicateDetector` checks in two steps:
  - URL: it prefilters candidates in SQL by host and path, then compares the canonical forms in PHP;
  - fuzzy: two queries, one over occurrences joined to events and venues, one over pending New drafts.

  Both steps exclude anything from the same source, hidden events and occurrences, and pairs remembered as "not duplicate". Feed text reaches SQL only as bound parameters. The `%` operator is never used.
- `SourceItemRecorder` runs the detector when it writes a New draft: for a new identity, a re-draft, or a replace of a pending New draft. The D-01 preview gets the same check through `previewDuplicate()`. `EditSource::previewRow` already reads the `duplicate.title` key.
- `MarkDraftDuplicate` and `RecordNotDuplicate` follow the ApproveDraft skeleton:
  - the admin check comes first;
  - the draft is locked FOR UPDATE;
  - lock order is draft → event (through LinkEventSource) → item;
  - the log holds ids only and is written after commit.
- `duplicate_decisions` enforces two rules with `num_nonnulls` CHECKs: exactly one subject and exactly one candidate. A unique index on the coalesced pair keeps one row per pair. `DuplicateDecision::record()` upserts with `ON CONFLICT` on that expression index.

## Task Commits

1. **Task 1 (tracer): a feed item matching a published event gets a duplicate warning:** `3680600` (feat)
2. **Task 2 (tdd): Mark as duplicate / Not a duplicate:** `f26c86a` (feat)
3. **Task 3 (tdd): rule edges:** `54f2b4e` (test)

**Tracer gate:** after Task 1, I re-ran its `<verify>` (DuplicateDetectorTest + ApproveDraftTest, 52 passed), plus the Ingestion, Review, Publishing and Filament folders (713 passed), before starting Task 2.

## Decisions Made
See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Migration timestamp clash**
- **Issue:** the plan names `2026_10_18_100700_create_duplicate_decisions_table.php`, but 03-09 already used `2026_10_18_100700` for `add_cancelled_dates_to_events`.
- **Fix:** the migration is `2026_10_18_100900_create_duplicate_decisions_table.php`. It runs after every existing Phase 3 migration.
- **Commit:** `3680600`

**2. [Rule 2 - Missing critical] A detector failure in preview must not reclassify the item**
- **Issue:** putting the duplicate check inside the preview's existing `try` would count an item as a parse error if the detector threw.
- **Fix:** the check runs in its own `try`. On failure it logs ids only and the row is still shown, just without the note.
- **Commit:** `3680600`

**3. Small supporting changes outside the plan's file list:**
- `IngestRun.php`: preview rows.
- `DraftWriter.php`: the duplicate parameters.
- `DraftData::sourceVenueId()` made public, so the preview can use the D-22 venue.

### Judgement calls (unattended run)
- **Commits on `main`**, as instructed. Only my own files were staged.
- **RED order:**
  - Task 1 and Task 2 tests were run RED before the code: no warning was stored, and the action classes did not exist.
  - Task 3 tests passed on the Task 1 code, so they are characterisation tests. Mutations show they bite:
    - tolerance `<=` → `<`: 2 failures;
    - dropping the hidden filter: 2;
    - dropping the same-source filter on drafts: 1;
    - dropping it on events: 1.
  - Dropping `cast(? as real)` broke nothing, because PostgreSQL already infers `real` for the parameter. The cast stays as a guard.
- **A test I removed:** a draft-URL test that assumed http and https canonicalise alike. CanonicalUrl keeps the scheme, as specified. The same-scheme variant stays.

---

**Total deviations:** 2 auto-fixed, plus small supporting edits. **Impact:** the plan was delivered as specified. No packages were added; league/uri was already installed.

## Known Stubs
None. (`.planning/WINDOWS.md` does not exist, so nothing was appended.)

## Threat Flags
None beyond the plan's register:
- T-03-31: bound parameters only, and no `%` operator.
- T-03-32: a snapshot test shows the event is unchanged.
- T-03-33: the admin check runs first, with tests for both actions.

## Issues Encountered
- jsonb reorders the keys of a preview row's `duplicate` object, so the test compares with `toEqual`.
- The full suite still reports the one PHPUnit warning that predates this plan.

## Verification
- **Task 1 verify:** `php artisan migrate` + DuplicateDetectorTest + ApproveDraftTest. Green.
- **Task 2 verify:** MarkDuplicateTest + WriteChokePointTest, 11 passed.
- **Task 3 verify:** CanonicalUrlTest + DuplicateDetectorTest, 42 passed, then Pint and PHPStan.
- **Full `./vendor/bin/pest --testsuite=Unit,Feature`:** **1599 passed**.
- **PHPStan:** 0 errors. **Pint:** clean.
- **Acceptance greps:**
  - `similarity(` in DuplicateDetector: 7;
  - `duplicate_of_draft_id` in DraftPublishability: 1;
  - `num_nonnulls` in the migration: 2;
  - `LinkEventSource` in MarkDraftDuplicate: present;
  - `DuplicateDetector` in SourceItemRecorder: present.

## Requirements Traceability
- **PIPE-05 left Pending.** The backend is complete: warnings are stored and bulk approve skips them. But 03-13 also lists PIPE-05, and the review page shows only event matches until its side-by-side panel lands (deferred item 3).
- **REV-05 left Pending.** The actions are complete, but the admin has no button until 03-13. This follows the REV-06/07 precedent from 03-10.

## Next Phase Readiness
03-13 can build the D-13 panel on what this plan provides:
- `duplicateOf` / `duplicateOfDraft`, `duplicate_reason`, `DuplicateMatch::formatScore()`;
- the "Mark as duplicate" button: `MarkDraftDuplicate($draft, $event, $user)`, which throws `DraftNotPublishable` with the plain-English message for a pending draft target or a non-new draft;
- the "Not a duplicate" button: `RecordNotDuplicate($draft, $user)`;
- it should also widen the table's "Possible duplicate" filter to `duplicate_of_draft_id`.

## Self-Check: PASSED

All 10 created files exist. Commits 3680600, f26c86a and 54f2b4e are ancestors of HEAD.
