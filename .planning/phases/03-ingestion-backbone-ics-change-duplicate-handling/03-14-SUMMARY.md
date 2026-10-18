---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 14
subsystem: review
tags: [review, categories, rules, ingestion, filament, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-09 DraftWriter source methods (createFromSource / replacePendingFromSource / createChangeDraft) passing ICS CATEGORIES; 03-01 sources table and event_drafts.source_id"
provides:
  - "category_mappings.source_id (nullable FK → sources, cascadeOnDelete); unique index category_mappings_source_value_unique ON (coalesce(source_id, 0), lower(source_value))"
  - "CategoryMapping::source() relation, source_id fillable + int cast"
  - "CategoryAssigner::assign(..., ?int $sourceId = null): the source's own mappings first, then global ones (each by id); another source's mappings never apply; reason \"Mapped from '{value}' ({source name})\" when a source mapping wins"
  - "DraftWriter::derive passes $draft->source_id (null for manual / submitted drafts → global mappings only)"
  - "Rules screen Category mappings tab: Source column (Select, placeholder 'All sources' = global)"
  - "SaveRules 'category_mappings' rows carry source_id; uniqueness key (source or 0, normalised value); refusals \"'x' is mapped twice for {source}.\" and \"'X' points at a source that no longer exists.\""
affects: [03-13 review UI (category reason shows the source name), 03-17 phase UAT (F14 step 5)]

actuals:
  tokens: 9050
  tasks: 2
  commits: 3
plan_head_before: 08a2d3b2a42f7dccb942938ba6d5ca29011818fa
plan_head_after: 31a0cfa62cca57728db8c73019fabc3ced1a6b2a

tech-stack:
  added: []
  patterns:
    - "Same uniqueness key in three places: DB index (coalesce(source_id, 0), lower(source_value)), SaveRules::mappingKey() and syncMappings' park-on-key-change"
    - "Referenced parent rows locked FOR KEY SHARE during a validated save: blocks deletion between check and write without blocking ordinary updates"

key-files:
  created:
    - database/migrations/2026_10_18_100800_add_source_id_to_category_mappings_table.php
  modified:
    - app/Models/CategoryMapping.php
    - app/Review/CategoryAssigner.php
    - app/Review/DraftWriter.php
    - app/Review/SaveRules.php
    - app/Filament/Pages/Rules.php
    - tests/Feature/Review/CategoryAssignerTest.php
    - tests/Feature/Filament/RulesMappingsGeofenceTest.php
    - docs/uat/browser-uat.md

key-decisions:
  - "Source preference is absolute within link 2: any matching source-scoped mapping beats any matching global one, even for a different label the source also sent (ordered by `source_id is null`, then id)"
  - "A draft keeps using its source's mappings after an admin edit (DraftWriter::update → derive passes the stored source_id)"
  - "The global duplicate message stays exactly \"'x' is mapped twice.\" (Phase 2 wording); a per-source duplicate adds \"for {source name}\""
  - "A mapping row naming a source id that does not exist is refused in plain English (instead of an FK 500); sources are read FOR KEY SHARE inside the save transaction"
  - "syncMappings parks a kept row when its source changes as well as when its value changes, so two rows can swap scope in one save"
  - "Source options list every source (active or not, ordered by name), since seeded sources start inactive (D-05)"
  - "EXT-01 stays Pending: this plan only scopes category mappings; ICS extraction itself is tracked by other plans"

patterns-established:
  - "Per-source configuration rows: nullable source_id = all sources, coalesce(source_id, 0) in the unique index"

requirements-completed: []

coverage:
  - id: D1
    description: "Tracer: Baptist-scoped 'Worship' → Community beats an older global 'Worship' → Music; reason names the source; Town Council and submissions get Music with the Phase 2 reason; another source's mapping never applies"
    requirement: EXT-01
    verification:
      - "tests/Feature/Review/CategoryAssignerTest.php per-source mappings describe block"
  - id: D2
    description: "Discovered draft via DraftWriter::createFromSource gets the source mapping and keeps it after DraftWriter::update"
    requirement: EXT-01
    verification:
      - "tests/Feature/Review/CategoryAssignerTest.php 'writes the source's mapping on a discovered draft through DraftWriter'"
  - id: D3
    description: "DB index: same value once globally and once per source allowed; two global or two same-source rows refused; deleting a source deletes its mappings"
    verification:
      - "tests/Feature/Review/CategoryAssignerTest.php"
  - id: D4
    description: "Rules screen Source column saves source_id / null; per-source duplicate refused naming the source; cross-source accepted; unknown source refused; scope swap in one save; resubmit keeps rows"
    verification:
      - "tests/Feature/Filament/RulesMappingsGeofenceTest.php per-source mappings describe block"

duration: 12min
completed: 2026-10-18
---

# Phase 3 Plan 14: Per-source category mappings Summary

**Category mappings can now be scoped to one source: a coalesced `(coalesce(source_id, 0), lower(source_value))` unique index, a CategoryAssigner that prefers the draft's own source and names it in the reason, and a Rules-screen Source column whose SaveRules checks use the same key (conflict C11 resolved).**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-10-18T11:32:25Z
- **Completed:** 2026-10-18T11:44Z
- **Tasks:** 2
- **Files modified:** 9 (1 created, 8 modified)

## Accomplishments

- Migration adds `category_mappings.source_id` (nullable, cascade on source delete) and swaps the unique index to the coalesced key. The down migration was run and re-applied cleanly on the dev DB.
- `CategoryAssigner::assign()` takes a trailing `?int $sourceId`. Link 2 loads only global mappings plus that source's mappings, source ones first. The reason reads "Mapped from 'Worship' (Attleborough Baptist Church)" when a source mapping wins. Global wins keep the Phase 2 reason.
- `DraftWriter::derive()` passes `$draft->source_id`, so discovered drafts (create, replace, change drafts and admin edits) use their source's mappings. Manual and submitted drafts have no source and behave as before.
- Rules screen: a "Source" select per mapping row, with the "All sources" placeholder meaning global. New helper text replaces "Sources arrive in a later phase."
- SaveRules: rows carry `source_id`. Uniqueness is judged per (source or 0, normalised value), the same key as the index, and a per-source duplicate is refused naming the source. Unknown sources are refused, and `syncMappings` parks rows whose key changes.

## Task Commits

1. **Task 1 (tracer): source-scoped mappings in the assigner and writer** - `676567c` (feat). Tracer gate: verify re-run green in unattended mode, then expanded.
2. **Task 2: Rules screen Source column + SaveRules per-source uniqueness** - `31a0cfa` (feat)

Note: `actuals.commits` is measured from the ledger (3). It includes the owner's unrelated `a048cc8 fix(sources): edit page crashed on an all-day test-run row`, which landed on main between the two task commits. This plan made 2 commits.

## Files Created/Modified

- `database/migrations/2026_10_18_100800_add_source_id_to_category_mappings_table.php`: the source_id FK and the coalesced unique index
- `app/Models/CategoryMapping.php`: `source()` relation, fillable + cast
- `app/Review/CategoryAssigner.php`: source-aware `matchMapping()`
- `app/Review/DraftWriter.php`: passes the draft's source id
- `app/Review/SaveRules.php`: source_id rows, `mappingKey()`, `lockedSourceNames()`, park-on-key-change
- `app/Filament/Pages/Rules.php`: Source column, helper text, fill source_id
- `tests/Feature/Review/CategoryAssignerTest.php`: 8 per-source tests
- `tests/Feature/Filament/RulesMappingsGeofenceTest.php`: 8 per-source tests; the state helper and stored-row assertion include source_id
- `docs/uat/browser-uat.md`: F14 step 5

## Decisions Made

See frontmatter `key-decisions`. The main one: a matching source-scoped mapping always beats a matching global mapping, even when the global one matches a different label from the same item.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing validation] Unknown source id refused in SaveRules**
- **Found during:** Task 2
- **Issue:** Without a check, a stale or tampered `source_id` would fail on the FK as a 500 instead of the "Rules not saved" refusal.
- **Fix:** "'X' points at a source that no longer exists." Sources are read `FOR KEY SHARE` inside the save transaction, so a source can't be deleted between the check and the write.
- **Files modified:** app/Review/SaveRules.php
- **Commit:** 31a0cfa

**2. [Rule 1 - Bug] Scope swap would collide on the new index**
- **Found during:** Task 2
- **Issue:** `syncMappings` parked a row only when its value changed. Swapping two same-value rows between "All sources" and a source would break the new unique index mid-save.
- **Fix:** A row is also parked when its `source_id` changes. Covered by the "swap two mappings' sources in one save" test.
- **Files modified:** app/Review/SaveRules.php
- **Commit:** 31a0cfa

**3. [Rule 2 - Project rule] UAT flow added**
- **Found during:** Task 2
- **Issue:** Project rules require a phase that changes UI to update `docs/uat/browser-uat.md`. That file was not in the plan's file list.
- **Fix:** F14 step 5 covers per-source mappings, the per-source refusal and the source-named reason.
- **Files modified:** docs/uat/browser-uat.md
- **Commit:** 31a0cfa

**4. [Test adjustment] DB-violation assertions split**
- **Found during:** Task 1
- **Issue:** The first unique violation aborts the PostgreSQL test transaction, so a second violation in the same test throws a generic QueryException.
- **Fix:** One violation per test.

## Issues Encountered

None beyond the above.

## Known Stubs

None.

## Threat Flags

None. No new endpoints. The Rules save keeps its AdminGate (T-03-39). The index, SaveRules and syncMappings all use the same key, with tests (T-03-38).

## Next Phase Readiness

- 03-13 (review UI) shows `category_reason` as stored, so the source-named reason appears without more work.
- Seeded sources (03-16) can be picked in the Source column straight away.

## Self-Check: PASSED

- FOUND: database/migrations/2026_10_18_100800_add_source_id_to_category_mappings_table.php
- FOUND: 676567c, 31a0cfa (ancestors of HEAD)
- Plan verify: migrate + CategoryAssignerTest + DraftWriterTest green; RulesMappingsGeofenceTest green; Pint clean; PHPStan 0 errors; full Unit+Feature 1547 passed
