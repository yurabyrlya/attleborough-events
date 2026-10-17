---
phase: 02-draft-model-review-queue-submissions
plan: 11
subsystem: review
tags: [laravel, filament, rules-screen, category-mappings, geofence, seeder, pipe-03, adm-04]
status: complete

requires:
  - phase: 02-07
    provides: Rules page (Categories + Keyword rules tabs, one "Save rules" action), SaveRules (optional sections, collect-all refusals, one transaction), CategoryAssigner link 3 + normalise()
  - phase: 02-03
    provides: CategoryAssigner chain skeleton and its $sourceValues parameter; DraftWriter calls assign() on every write
provides:
  - category_mappings table (unique lower(source_value), non-blank CHECK, FK restrictOnDelete) + CategoryMapping model; Category::mappings()
  - geofence_terms table (type CHECK postcode_district|place_name, non-blank CHECK, unique (type, lower(value))) + GeofenceTerm model with POSTCODE_DISTRICT / PLACE_NAME constants
  - CategoryAssigner link 2: first mapping by id whose normalised source value equals a normalised given source value → "Mapped from '{value as given}'" (PIPE-03 chain complete)
  - SaveRules sections 'category_mappings' (rows id?, source_value, category_id) and 'geofence' (postcode_districts, place_names lists); mapping-aware category-removal refusals
  - Rules page tabs in order Categories, Keyword rules, Category mappings, Geofence (TagsInput fields under statePath 'geofence')
  - RulesSeeder (29 starter keyword rules, NR17/NR16/NR18/IP24 + Attleborough) via firstOrCreate, called from DatabaseSeeder after CategorySeeder
affects: [02-13, 02-14, 02-16, phase-03-ingestion, phase-04-geofence]

actuals:
  tokens: 12639
  tasks: 2
  commits: 4
plan_head_before: 43f1d3f63ca8d67d982e4fecf249024ceaea6f40
plan_head_after: 0e14a2c0561d9410c6cc16b6d14068695dacfeb5

tech-stack:
  added: []
  patterns:
    - "Nested form state on a custom Filament page: Group::make([...])->statePath('geofence') inside a Tab, so getState() yields ['geofence' => ['postcode_districts' => [...], 'place_names' => [...]]]"
    - "Case-insensitive firstOrCreate in seeders: Model::query()->whereRaw('lower(col) = ?', [...])->firstOrCreate([], $values) — the where clause is the key, so re-cased admin rows count as present"
    - "Geofence sync per type: keep rows whose normalised value is still listed (fix spelling in place), delete the rest (incl. stored near-duplicates), insert new in submitted order"

key-files:
  created:
    - database/migrations/2026_10_17_100410_create_category_mappings_table.php
    - database/migrations/2026_10_17_100420_create_geofence_terms_table.php
    - app/Models/CategoryMapping.php
    - app/Models/GeofenceTerm.php
    - database/seeders/RulesSeeder.php
    - tests/Feature/Filament/RulesMappingsGeofenceTest.php
    - tests/Feature/Review/RulesSeederTest.php
  modified:
    - app/Models/Category.php
    - app/Review/CategoryAssigner.php
    - app/Review/SaveRules.php
    - app/Filament/Pages/Rules.php
    - database/seeders/DatabaseSeeder.php

key-decisions:
  - "Mapping link loops over mappings by id (outer) and checks every given source value, so the earliest-created mapping wins regardless of the order the source lists its labels; the reason quotes the value as the source gave it"
  - "Geofence section: a list missing from the section is untouched, a null list is empty; blank tags are dropped silently; districts lose all whitespace and are upper-cased before the ^[A-Z]{1,2}[0-9][0-9A-Z]?$ check"
  - "Refusal wording: \"'{v}' is mapped twice.\" (normalised), \"Every category mapping needs a source value.\", \"'{v}' needs a category.\", \"'{v}' isn't a postcode district like NR17.\", \"Postcode district 'NR17' is listed twice.\", \"Place name '{normalised}' is listed twice.\", \"'{name}' still has 1 mapping. Move or remove it first.\" / \"{n} mappings … them\""
  - "GeofenceTerm type is a string with model constants (no enum) — the DB CHECK is the guard; Phase 4 may promote it to an enum when enforcement lands"
  - "RulesSeeder never updates: rows are matched case-insensitively and only created when missing; a default the admin deleted returns on the next seed (firstOrCreate semantics, as planned); rules for a removed category are skipped"

patterns-established:
  - "Every Rules-screen section follows the same shape in SaveRules: *Rows() cleaner → *Problems() collected before writing → sync*() inside the one transaction"

requirements-completed: [ADM-04, PIPE-03]

coverage:
  - id: D1
    description: "Admin adds a category mapping and geofence terms on the Rules screen; one Save rules writes them in one transaction"
    requirement: ADM-04
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/RulesMappingsGeofenceTest.php#saves a category mapping and geofence terms from the Rules screen in one go"
        status: pass
    human_judgment: false
  - id: D2
    description: "CategoryAssigner link 2: mapping beats keyword rules, preferred beats mapping, reason \"Mapped from '…'\""
    requirement: PIPE-03
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/RulesMappingsGeofenceTest.php (5 assigner cases)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Refusals (duplicate/blank mappings, invalid or duplicate districts/place names, removing a still-mapped category) save nothing in any rules table"
    requirement: ADM-04
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/RulesMappingsGeofenceTest.php (refusal cases)"
        status: pass
    human_judgment: false
  - id: D4
    description: "RulesSeeder seeds defaults idempotently and never overrides admin edits; DatabaseSeeder calls it"
    requirement: ADM-04
    verification:
      - kind: integration
        ref: "tests/Feature/Review/RulesSeederTest.php"
        status: pass
      - kind: other
        ref: "php artisan db:seed --class=RulesSeeder (dev DB, run twice → 29 rules, 5 geofence terms)"
        status: pass
    human_judgment: false

duration: 6min
completed: 2026-10-17
---

# Phase 2 Plan 11: Category mappings, geofence and RulesSeeder Summary

**The one Rules screen now manages all four rule sets (categories, keyword rules, category mappings, geofence) behind a single transactional "Save rules"; source-value mappings are link 2 of the PIPE-03 category chain, and a firstOrCreate RulesSeeder ships starter keyword rules plus NR17/NR16/NR18/IP24 + Attleborough without ever overwriting admin edits.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-10-17T20:19:05Z
- **Completed:** 2026-10-17T20:25:03Z
- **Tasks:** 2 (1 tracer, 1 TDD)
- **Files modified:** 12 (7 created, 5 modified)

## Accomplishments

- `category_mappings` and `geofence_terms` tables with case-insensitive unique indexes and CHECK constraints; `CategoryMapping`, `GeofenceTerm` models; `Category::mappings()`
- `CategoryAssigner` link 2 ("Mapped from '{value}'"), between the preferred category and keyword rules — the chain is complete (preferred → mapping → keyword → Other flagged)
- `SaveRules` gains `category_mappings` and `geofence` sections with collect-all validation; category removal now also counts mappings in the submitted state; transaction order: categories → keyword rules → mappings → geofence → delete removed categories
- Rules page: "Category mappings" table repeater and "Geofence" tab (two TagsInputs), in the planned tab order
- `RulesSeeder` (29 keyword rules over nine categories, four districts, one place name), wired into `DatabaseSeeder` after `CategorySeeder`; dev DB seeded

## Task Commits

1. **Task 1 (tracer): mappings + geofence on the Rules screen → one transaction → assigner maps through it**
   - `196db33` test(02-11): add failing category mappings and geofence tracer test (RED)
   - `3c5c7d4` feat(02-11): category mappings and geofence tabs on the Rules screen; mappings are link 2 of the category chain (GREEN)
   - Tracer feedback gate: automated `<verify>` re-run green (50 tests) → expanded
2. **Task 2 (TDD): RulesSeeder with firstOrCreate defaults, wired into DatabaseSeeder**
   - `0d058b8` test(02-11): add failing RulesSeeder tests (RED)
   - `0e14a2c` feat(02-11): RulesSeeder seeds starter keyword rules and geofence defaults with firstOrCreate (GREEN)

## Files Created/Modified

- `database/migrations/2026_10_17_100410_create_category_mappings_table.php` — mappings table; docblock notes Phase 3 adds `source_id` and widens the key
- `database/migrations/2026_10_17_100420_create_geofence_terms_table.php` — geofence terms table
- `app/Models/CategoryMapping.php`, `app/Models/GeofenceTerm.php` — models
- `app/Models/Category.php` — `mappings()` relation
- `app/Review/CategoryAssigner.php` — link 2 `matchMapping()`
- `app/Review/SaveRules.php` — mappings/geofence sections, mapping-aware removal refusals
- `app/Filament/Pages/Rules.php` — two new tabs + fill from DB
- `database/seeders/RulesSeeder.php`, `database/seeders/DatabaseSeeder.php` — seeded defaults
- `tests/Feature/Filament/RulesMappingsGeofenceTest.php` (19 tests), `tests/Feature/Review/RulesSeederTest.php` (8 tests)

## TDD Gate Compliance

- Task 1: RED `196db33` (19 tests failing — classes/tabs missing) → GREEN `3c5c7d4`
- Task 2: RED `0d058b8` (8 tests failing — seeder missing) → GREEN `0e14a2c`
- No REFACTOR commits needed.

## Verification

- `./vendor/bin/pest --testsuite=Unit,Feature` — 894 passed, 0 failed
- `vendor/bin/pint --dirty --format agent` — passed; `vendor/bin/phpstan analyse --memory-limit=1G` — 0 errors
- `php artisan migrate` (dev) — both migrations ran; `php artisan db:seed --class=RulesSeeder` run twice — 29 rules / 5 terms both times
- Acceptance greps: "Mapped from" in CategoryAssigner = 1; firstOrCreate in RulesSeeder = 3, updateOrCreate = 0; RulesSeeder in DatabaseSeeder = 1

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Case-insensitive seeding key**
- **Found during:** Task 2
- **Issue:** A plain `firstOrCreate(['keyword' => 'quiz'], …)` matches exactly; if the admin re-cased a seeded keyword ('Quiz') or place name, re-seeding would try to insert 'quiz' and hit the `lower()` unique index.
- **Fix:** Seeder matches via `whereRaw('lower(col) = ?')` and calls `firstOrCreate([], $values)`; test "treats a keyword or place name the admin re-cased as already there".
- **Files modified:** database/seeders/RulesSeeder.php
- **Commit:** 0e14a2c

**2. [Rule 2 - Robustness] Geofence sync tolerates stored near-duplicates**
- **Found during:** Task 1
- **Issue:** Two stored values with the same normalised form (only possible via direct DB writes) would have left one undeletable by keyed sync.
- **Fix:** Sync walks every stored row, keeping the first per normalised key and deleting the rest.
- **Files modified:** app/Review/SaveRules.php
- **Commit:** 3c5c7d4

**3. [Test adjustment] Select holds category ids as strings**
- The page-fill test compares with `toEqual` and a string category id, because Filament's Select hydrates option keys as strings (SaveRules already accepts digit strings).

**Process note:** Commits landed on `main`, as for every earlier plan in this phase — the orchestrator dispatched sequential mode on the main working tree (`workflow.use_worktrees=false`). `git.base-branch --is-protected main` reports true with no `git.allow_default_branch_commits` override set; the owner may want to set that key to make this convention explicit.

## Known Stubs

None. Mappings are not fed by any Phase 2 input (no sources yet) — intentional per the plan; Phase 3 ingestion passes source values. Geofence terms are stored but not enforced until Phase 4 (planned).

## Threat Flags

None — no new surface beyond the planned SaveRules sections, which sit behind the existing `AdminGate::authorize()` + panel gate (T-02-41). Re-seed tampering (T-02-42) mitigated by create-only seeding and tests.

## Next Phase Readiness

- Phase 3 ingestion can pass source labels as `$sourceValues` to `CategoryAssigner::assign()`; it should add `category_mappings.source_id` and widen the unique key as the migration docblock notes.
- Phase 4 geofence enforcement reads `geofence_terms` (`type` = `postcode_district` | `place_name`; districts canonical upper-case).
- Village names for the geofence remain an open owner question (spec §15); the admin can add them on the Geofence tab.

## Self-Check: PASSED

All 7 created files present; commits 196db33, 3c5c7d4, 0d058b8, 0e14a2c are ancestors of HEAD.
