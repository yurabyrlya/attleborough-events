---
phase: 02-draft-model-review-queue-submissions
plan: 07
subsystem: review
tags: [laravel, filament, rules-screen, categories, keyword-rules, pipe-03, adm-04]
status: complete

requires:
  - phase: 02-03
    provides: CategoryAssigner (preferred → Other flagged) + static normalise(); CategoryDecision; DraftWriter calls assign() on every write
provides:
  - category_keyword_rules table (unique lower(keyword), non-blank CHECK, FK restrictOnDelete) + CategoryKeywordRule model; Category::keywordRules()
  - CategoryAssigner link 3: keyword rules by (priority asc, id asc), whole-word normalised match; static titleContainsKeyword()
  - App\Review\SaveRules (AdminGate, collect-all validation against the submitted state, one transaction; sections 'categories' and 'keyword_rules', absent section = untouched) + RulesNotSaved
  - Filament page App\Filament\Pages\Rules (/admin/rules, group Review) with Categories and Keyword rules tabs and one "Save rules" header action
affects: [02-11, 02-13, 02-14, 02-16]

actuals:
  tokens: 13434
  tasks: 3
  commits: 5
plan_head_before: 09e8a8e12e0509efc4411dc1943ffe001cd1cf4a
plan_head_after: 5dbf69f8a56d82aa7f12c8c0d9b1adaa69a2ba59

tech-stack:
  added: []
  patterns:
    - "Custom Filament page form: public ?array $data + form(Schema)->statePath('data') + view rendering {{ $this->form }}; header action hands getState() to a domain action"
    - "Table repeater with Hidden::make('id') carrying row identity (Filament renders Hidden without consuming a TableColumn)"
    - "Rules save: lock categories FOR UPDATE, compute problems inside the transaction, throw before any write"
    - "Keyword swap safety: changed keywords parked on a unique placeholder before final update (lower(keyword) unique index is non-deferrable)"

key-files:
  created:
    - database/migrations/2026_10_17_100400_create_category_keyword_rules_table.php
    - app/Models/CategoryKeywordRule.php
    - app/Review/SaveRules.php
    - app/Review/Exceptions/RulesNotSaved.php
    - app/Filament/Pages/Rules.php
    - resources/views/filament/pages/rules.blade.php
    - tests/Feature/Review/KeywordRuleTracerTest.php
    - tests/Feature/Review/CategoryAssignerTest.php
    - tests/Feature/Filament/RulesCategoriesTest.php
  modified:
    - app/Models/Category.php
    - app/Review/CategoryAssigner.php

key-decisions:
  - "SaveRules sections are optional: a state without 'categories' or 'keyword_rules' leaves that table untouched, so 02-11 can add mappings/geofence sections without changing callers"
  - "Validation runs inside the transaction after locking categories, so the usage counts and the delete see the same rows; RulesNotSaved message is 'Rules not saved: ' + problems joined by spaces"
  - "Keywords and category names stored NFC, whitespace-collapsed, trimmed, case kept; duplicate check and matching use CategoryAssigner::normalise(); duplicate message shows the normalised form ('café' is listed twice.)"
  - "New keyword rows are inserted in submitted order so, at equal priority, earlier rows get the lower id and win; unknown/stale ids are treated as new rows"
  - "A draft counts as using a category via category_id OR submitted_category_id (organiser's pick), even though submitted_category_id is nullOnDelete"
  - "New categories without an icon get heroicon-o-tag (the factory's generic icon); icon must be a kebab-case name; sort 0-9999 (column is unsigned smallint)"
  - "Category repeater is not drag-reorderable: order is the explicit Sort number; the Other row has no delete button (SaveRules refuses anyway)"

patterns-established:
  - "Refusal wording: \"The Other category can't be removed.\"; \"'{name}' is used by N events and M drafts.\" (zero parts omitted); \"'{name}' still has N keyword rules. Move or remove them first.\""

requirements-completed: []
requirements-partial: [PIPE-03, ADM-04]  # keyword link + categories done here; mappings and geofence finish both in 02-11

coverage:
  - id: D1
    description: "Keyword rule saved on the Rules screen categorises the next draft with reason \"Keyword rule: 'pumpkin' → Community\""
    requirement: "PIPE-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/KeywordRuleTracerTest.php#categorises the next draft by a keyword rule saved on the Rules screen"
        status: pass
    human_judgment: false
  - id: D2
    description: "Non-admin gets 403 on /admin/rules; SaveRules by a non-admin throws AuthorizationException and writes nothing"
    requirement: "ADM-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/KeywordRuleTracerTest.php#keeps the Rules screen admin-only; #refuses SaveRules from anyone but the admin"
        status: pass
    human_judgment: false
  - id: D3
    description: "Chain order and tie-break: preferred beats keyword; priority asc then id asc; deterministic"
    requirement: "PIPE-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/CategoryAssignerTest.php (priority, lower id, same input every time, preferred)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Whole words, case, whitespace and Unicode: quiz/quizzical, car boot across whitespace, café NFC/NFD, literal punctuation"
    requirement: "ADM-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/CategoryAssignerTest.php (quizzical, car boot, café, literal dots)"
        status: pass
    human_judgment: false
  - id: D5
    description: "Duplicate CAFÉ/café, blank keyword, missing category, bad priority refused with nothing written; empty list deletes all rules"
    requirement: "ADM-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/CategoryAssignerTest.php (refuses …, deletes every rule …)"
        status: pass
    human_judgment: false
  - id: D6
    description: "Categories: slug from name (-2 on collision), never changes on rename; reorder; remove unused; remove with its rules in one save"
    requirement: "ADM-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/RulesCategoriesTest.php"
        status: pass
    human_judgment: false
  - id: D7
    description: "Three verbatim refusals; refused saves leave category and keyword-rule counts unchanged; page shows both tabs; Other has no delete button"
    requirement: "ADM-04"
    verification:
      - kind: integration
        ref: "tests/Feature/Filament/RulesCategoriesTest.php (refuses …, saves nothing at all …, shows the Categories and Keyword rules tabs, no delete button)"
        status: pass
    human_judgment: false

duration: 8min
completed: 2026-10-17
---

# Phase 2 Plan 07: Rules Screen — Categories and Keyword Rules Summary

**There is now one admin Rules screen at `/admin/rules` with Categories and Keyword rules tabs and a single "Save rules" action. A saved title keyword rule decides the category of the next draft written. It is a whole-word, NFC-normalised match, ordered by priority then id, and the draft's reason reads "Keyword rule: 'pumpkin' → Community". Removing a category is checked against the state being saved, and a refusal names the actual reason.**

## Performance

- **Duration:** 8 min
- **Started:** 2026-10-17T19:38:28Z
- **Completed:** 2026-10-17T19:46:37Z
- **Tasks:** 3
- **Files modified:** 11

## Accomplishments

- `category_keyword_rules` table: `lower(keyword)` unique index, non-blank CHECK, `category_id` restrictOnDelete. Ships with the `CategoryKeywordRule` model and `Category::keywordRules()`.
- `CategoryAssigner` link 3. Rules run in `orderBy('priority')->orderBy('id')` order. Each keyword is passed through `preg_quote` and checked against the normalised title with Unicode letter/digit boundaries. No AI and no network calls, so the same input always gives the same category.
- `SaveRules`:
  - only the admin can run it (AdminGate);
  - it checks the submitted state against itself and lists every problem it finds;
  - it writes everything in one transaction, in this order: upsert categories → sync keyword rules → delete removed categories.
- Slugs are generated once from the name, with -2/-3 added on a collision, and never change afterwards.
- The Rules page follows the SystemHealth skeleton plus a form. It has a Categories tab (slug read-only, no delete on Other) and a Keyword rules tab, both table repeaters. The page shows "Rules saved" on success, or "Rules not saved" with the list of problems.

## Task Commits

1. **Task 1: Tracer, keyword rule → next draft.** RED `9c4438a` (test), GREEN `dfabfea` (feat)
2. **Task 2: Keyword matching rules.** `c61116a` (test). It passed on the first run because the tracer already covered it; a mutation check confirmed the tests catch breakage (see Deviations).
3. **Task 3: Categories tab.** RED `893a75b` (test), GREEN `5dbf69f` (feat)

## Files Created/Modified

- `database/migrations/2026_10_17_100400_create_category_keyword_rules_table.php`: keyword rules table.
- `app/Models/CategoryKeywordRule.php`: model with `category()`.
- `app/Models/Category.php`: adds `keywordRules()`.
- `app/Review/CategoryAssigner.php`: keyword link and `titleContainsKeyword()`.
- `app/Review/SaveRules.php`: the single writer for the Rules screen.
- `app/Review/Exceptions/RulesNotSaved.php`: domain exception that carries `problems`.
- `app/Filament/Pages/Rules.php` and `resources/views/filament/pages/rules.blade.php`: the screen.
- `tests/Feature/Review/KeywordRuleTracerTest.php`, `tests/Feature/Review/CategoryAssignerTest.php`, `tests/Feature/Filament/RulesCategoriesTest.php`: 35 tests.

## Decisions Made

See `key-decisions` in the frontmatter. In short:

- A section left out of the state is not touched, which makes room for 02-11.
- Validation runs after the category rows are locked.
- Changed keywords are moved to a placeholder first, so swapping two keywords works.
- A draft counts as using a category if its `submitted_category_id` points there.
- New categories get `heroicon-o-tag` as their icon.
- Category order comes from the explicit Sort field, not drag-and-drop.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Correctness] Swapping keywords in one save would hit the unique index**
- **Found during:** Task 1
- **Issue:** `lower(keyword)` is a non-deferrable unique index. Updating row A to B's keyword before B is renamed would fail with a 500, even though the submitted state is valid.
- **Fix:** In `syncKeywordRules`, changed keywords are first set to a unique per-id placeholder and then given their final values, all in the same transaction. A test covers the swap.
- **Files modified:** app/Review/SaveRules.php
- **Commit:** dfabfea (test in c61116a)

**2. [Rule 2 - Correctness] Refusal checks are evaluated inside the locked transaction**
- **Found during:** Task 3
- **Issue:** If validation ran before the transaction, the usage counts could disagree with the delete.
- **Fix:** Categories are locked `FOR UPDATE` first, then problems are computed, and `RulesNotSaved` is thrown before any write. The FK restrictOnDelete remains as a backstop.
- **Files modified:** app/Review/SaveRules.php
- **Commit:** 5dbf69f

**3. [Rule 2 - UX guard] No delete button on the Other row** (RESEARCH Pitfall 10)
- Hidden through the repeater's `deleteAction` callback and covered by a test. A mutation check confirmed the test fails without the guard.
- **Commit:** 5dbf69f

### Process notes

- **Task 2 never had a genuine RED.** The Task 1 tracer implementation already met every Task 2 behaviour. To show the tests are not vacuous, I temporarily switched the ordering to `orderByDesc('priority')` and removed the lookahead boundary. Two tests failed, and I reverted the change.
- **Commits on `main`:** the GSD protected-branch gate reports `main` as protected. The project runs `git.branching_strategy: none` and the orchestrator asked for sequential execution on the main working tree, which every earlier plan also committed to. I followed that convention.
- `.ai/rules/` does not exist in this repo (02-PATTERNS confirms this). I followed `.ai/guidelines/attleborough.md` and AGENTS.md.
- Total deviations: 3 auto-fixed (all Rule 2). Impact: correctness hardening only, no scope change.
- **Requirements not ticked yet:** `requirements.mark-complete PIPE-03 ADM-04` ran, and I reverted it. 02-11 also lists both requirements and still has to add the mapping link and the geofence. Ticking them now would overstate progress, so 02-11 should mark them complete.

## Issues Encountered

None.

## Threat Flags

None. The only new surface is the admin page and the `SaveRules` action, both covered by T-02-16, T-02-17 and T-02-18. Mitigations in place:

- T-02-16: `canAccess()` plus `AdminGate::authorize` in `SaveRules`.
- T-02-17: `preg_quote`, an 80-character limit, and no patterns written by the admin.
- T-02-18: refusals before any write, inside one transaction, with the FK backstop.

## Next Phase Readiness

- 02-11 can add `category_mappings` and `geofence` sections to `SaveRules` and tabs to `Rules::form()`. A state without a given section leaves that table alone.
- The mapping link belongs between the preferred category and the keyword rules in `CategoryAssigner`.
- `RulesSeeder` should use `firstOrCreate`, keyed by keyword.

## Self-Check: PASSED

- FOUND: all 9 created files and 2 modified files
- FOUND: commits 9c4438a, dfabfea, c61116a, 893a75b, 5dbf69f (all ancestors of HEAD)
- Full Unit+Feature suite: 763 passed. Pint and PHPStan are clean.

---
*Phase: 02-draft-model-review-queue-submissions*
*Completed: 2026-10-17*
