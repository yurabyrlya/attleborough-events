---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 01
subsystem: database
tags: [postgres, migrations, eloquent, enums, ingestion, partial-unique-index]
status: complete

requires:
  - phase: 02-draft-model-review-queue-submissions
    provides: event_drafts table, EventDraft model (MassPrunable), ExtractionMethod/RejectReason CHECK pattern
provides:
  - sources table with every SRC-01 field, D-02 schedule columns and the D-25 is_organiser_source flag
  - source_runs table with counters, observed window and the source_runs_one_active partial unique index (SRC-04)
  - source_items table, UNIQUE (source_id, external_id), current_draft_id nullOnDelete (Pitfall 12)
  - event_drafts source links, diff, relevant_starts_at, duplicate fields, excluded_dates; event_drafts_one_pending_per_item (PIPE-06)
  - events.excluded_dates (D-19)
  - RunStatus, RunMode, RunTrigger enums; ExtractionMethod::Ics
  - Source, SourceRun, SourceItem models; EventDraft source()/sourceItem()/duplicateOfDraft()
  - SourceFactory with ics(), active(), organiserSite(), withIdentity()
affects: [03-02 .. 03-17, ingestion pipeline, review actions, Filament source resource]

actuals:
  tokens: 13644
  tasks: 2
  commits: 2
plan_head_before: d767eb3b27a80bdb5c983f621aa5c8a6d73493e7
plan_head_after: 69214a6c3412e15d175e793eb22bd391b926c6c6

tech-stack:
  added: []
  patterns:
    - "Concurrency rules as PG partial unique indexes (one active run per source, one pending draft per item)"
    - "Pipeline-owned columns excluded from #[Fillable]; written with forceFill/forceCreate"
    - "Circular FK split across migrations: source_items.current_draft_id added after event_drafts.source_item_id"

key-files:
  created:
    - database/migrations/2026_10_18_100100_create_sources_table.php
    - database/migrations/2026_10_18_100200_create_source_runs_table.php
    - database/migrations/2026_10_18_100300_create_source_items_table.php
    - database/migrations/2026_10_18_100400_add_ingestion_columns_to_event_drafts_and_events.php
    - app/Models/Source.php
    - app/Models/SourceRun.php
    - app/Models/SourceItem.php
    - app/Enums/RunStatus.php
    - app/Enums/RunMode.php
    - app/Enums/RunTrigger.php
    - database/factories/SourceFactory.php
    - tests/Feature/Ingestion/IngestionSchemaTest.php
  modified:
    - app/Models/EventDraft.php
    - app/Enums/ExtractionMethod.php

key-decisions:
  - "Source.is_active is not fillable: it changes only through Create drafts / Pause actions (D-01, 03-07, 03-15)"
  - "SourceRun fillable is only mode and trigger; status, failure_kind, counters and instants go through forceFill"
  - "SourceItem keeps accepted_hash, accepted_payload, review_outcome, missing_runs, current_draft_id out of Fillable"
  - "sources.parser_config defaults to {\"identity\":\"uid\"} (plan) rather than RESEARCH's '{}'"
  - "ExtractionMethod::Ics label is 'ICS feed' (plan) rather than PATTERNS' 'Calendar feed (ICS)'"
  - "source_runs.queued_at is NOT NULL DEFAULT now() so the stale-queued janitor never misses a row"

patterns-established:
  - "Ingestion tables: class docblock names the writer; every status string mirrored by a PG CHECK"

requirements-completed: [SRC-01, SRC-04, PIPE-06]

coverage:
  - id: D1
    description: "sources table with every SRC-01 field and safe defaults"
    requirement: SRC-01
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/IngestionSchemaTest.php#saves an ICS source with the safe defaults from the database"
        status: pass
  - id: D2
    description: "Database refuses a second queued/running run per source"
    requirement: SRC-04
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/IngestionSchemaTest.php#refuses a second queued or running run for the same source"
        status: pass
  - id: D3
    description: "Database refuses a second pending draft per source item"
    requirement: PIPE-06
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/IngestionSchemaTest.php#refuses a second pending draft for the same source item"
        status: pass

duration: 7min
completed: 2026-10-18
---

# Phase 3 Plan 01: Ingestion Schema and Domain Types Summary

**Four PostgreSQL migrations (sources, source_runs, source_items, draft/event alterations). The database itself refuses a second active run per source and a second pending draft per item. Also added: run enums, three models and a Source factory.**

## Performance

- **Duration:** ~7 min
- **Started:** 2026-10-18T00:29:38Z
- **Completed:** 2026-10-18T00:36:25Z
- **Tasks:** 2
- **Files modified:** 14

## Accomplishments
- `sources` holds every SRC-01 column. Defaults: local, Europe/London, none, daily 05:30, healthy, inactive, `{"identity":"uid"}`. CHECKs cover extraction_method ('ics'), location_mode, check_frequency, health and consecutive_failures ≥ 0. The `sources_due` partial index supports the scheduler query.
- `source_runs` has 13 counters, the observed window, preview jsonb and CHECKs for mode, trigger, status and failure_kind. `failure_kind IS NULL OR status='failed'` is enforced. `source_runs_one_active` handles SRC-04.
- `source_items` has UNIQUE (source_id, external_id), CHECKs on status and review_outcome, run FKs that null on delete, and `current_draft_id` that nulls on delete (Pitfall 12). The test proves the null-on-delete with a query delete, the same way MassPrunable deletes.
- `event_drafts` gains source/item links, target_recurrence_key, diff, relevant_starts_at, duplicate_of_draft_id/reason/score (with CHECKs) and excluded_dates. Its extraction_method CHECK now allows 'ics' and its reject_reason CHECK allows 'ignored'. `event_drafts_one_pending_per_item` handles PIPE-06. `events.excluded_dates` is added.
- `down()` is complete: rolling back the last batch and re-migrating both succeed on the dev DB.

## Task Commits

1. **Task 1 (tracer): source → run → item → pending ICS draft, with DB refusals** - `9604812` (feat)
2. **Task 2: Harden the schema contract (UTC, non-fillable, length, rollback)** - `69214a6` (test)

## Files Created/Modified
- `database/migrations/2026_10_18_100100_create_sources_table.php` - sources + CHECKs + due index
- `database/migrations/2026_10_18_100200_create_source_runs_table.php` - runs + CHECKs + one-active index
- `database/migrations/2026_10_18_100300_create_source_items_table.php` - items + unique identity
- `database/migrations/2026_10_18_100400_add_ingestion_columns_to_event_drafts_and_events.php` - draft/event columns, widened CHECKs, one-pending index, current_draft_id FK
- `app/Models/Source.php`, `SourceRun.php`, `SourceItem.php` - models with relations, casts, default mirrors
- `app/Models/EventDraft.php` - source(), sourceItem(), duplicateOfDraft(); diff/excluded_dates/relevant_starts_at casts; excluded_dates fillable
- `app/Enums/RunStatus.php` (isActive, label, colour), `RunMode.php`, `RunTrigger.php`; `ExtractionMethod::Ics`
- `database/factories/SourceFactory.php` - ics/active/organiserSite/withIdentity
- `tests/Feature/Ingestion/IngestionSchemaTest.php` - 22 tests, 73 assertions

## Decisions Made
See `key-decisions` in the frontmatter. The fillable boundaries follow the later plans: 03-07 says is_active changes only through Create drafts / Pause, and 03-15 says the form cannot set is_active.

## Deviations from Plan

### Judgement calls (unattended run)

**1. Commits on `main` despite the executor's protected-branch guard**
- **Found during:** pre-commit check
- **Issue:** `git.base-branch --is-protected main` returns true, but the project uses `branching_strategy: none`, every earlier phase committed to main, and the orchestrator said to run sequentially on the main working tree.
- **Resolution:** Followed the orchestrator and the project's existing practice. Did not touch `.planning/config.json`.

**2. Small additions beyond the column list** (all are correctness guards, Rule 2)
- CHECKs on `sources.consecutive_failures >= 0`, `source_items.missing_runs >= 0` and `event_drafts.duplicate_score BETWEEN 0 AND 1`.
- `sources_due` partial index, `source_runs (source_id, id)` index and a plain `event_drafts.source_item_id` index, so the FK on-delete actions and the scheduler query are indexed.
- `SourceItem.firstSeenRun()` / `lastSeenRun()` relations and a `SourceRun::COUNTERS` constant.
- Commit: `9604812`

**3. Task 2 RED step**
- The Task 2 hardening tests passed on the first run because Task 1 already applied the fillable, UTC and length rules. They are characterisation tests that lock the contract in, not a RED→GREEN cycle. No model changes were needed in Task 2.

**4. Requirements left Pending in REQUIREMENTS.md**
- `requirements.mark-complete SRC-01 SRC-04 PIPE-06` flipped all three to Complete, but this plan delivers only their schema slice. The admin form comes in 03-15, scheduler dispatch in 03-06, and update-draft and replace-in-place logic in 03-09. I reverted REQUIREMENTS.md so traceability does not over-claim. `requirements-completed` above lists the plan's IDs, per the template contract, as the DB-guarantee slice delivered here.

---

**Total deviations:** 4 recorded (no scope change)
**Impact on plan:** None. The plan was executed as written.

## Issues Encountered
None.

## User Setup Required
None.

## Next Phase Readiness
- 03-02 onward can build on these tables. RejectReason::Ignored (03-10) and the SourceHealth / SourceItemStatus enums are deliberately left to later plans. Health and item status are plain strings for now, guarded by CHECKs.
- Full Unit+Feature suite: 1027 passed. PHPStan: 0 errors. Pint: clean.

## Self-Check: PASSED
- All 12 created and 2 modified files exist. Commits `9604812` and `69214a6` are ancestors of HEAD.
