---
phase: 02-draft-model-review-queue-submissions
plan: 03
subsystem: review
tags: [laravel, review-queue, drafts, publishing, recurrence, pest-arch]

requires:
  - phase: 02-01
    provides: event_drafts table, EventDraft, ReviewStatus, DraftData, DraftWriter::create + derive(), ApproveDraft, PublishableEventData::fromDraft()
provides:
  - DraftPublishability (blockers() for single approve; bulkBlockerKeys() + static skipMessage() for bulk)
  - ApproveDraft re-checks blockers() on the locked row before PublishEvent
  - DraftWriter::update() (admin fixes to pending drafts; refuses decided drafts)
  - CategoryAssigner (preferred → Other flagged) + static normalise(); CategoryDecision DTO
  - QualityBand enum (+ EventDraft cast) with fromScore()
  - config/review.php (quality weights/bands, retention days, submission limits, venue similarity threshold)
  - EventDraftFactory with states (submission, manual, freeTextVenue, withRepeatsText, recurring, scored, withParseErrors, duplicateOf, approvedFor)
  - Arch test: PublishEvent usable only inside App\Publishing\Actions
affects: [02-04, 02-05, 02-07, 02-08, 02-11, 02-13, 02-14]

actuals:
  tokens: 12625
  tasks: 2
  commits: 4
plan_head_before: fe6dec31c69e80c5c4a2d2b901fd6d603f2b8a44
plan_head_after: 439a42b1503683809ea4c681fd141ebfa5e9f357

tech-stack:
  added: []
  patterns:
    - "Single publishability check: DraftPublishability is the one source for the A key, Approve button, bulk approve and ApproveDraft"
    - "Bulk skip keys in fixed order; specific keys (unresolved_venue, repeats_unset) never masked by 'blocked'"
    - "Field-level blockers first; PublishableEventData invariants only once fields are clear (no duplicate messages)"
    - "Pest arch choke point via toOnlyBeUsedIn (ignoring() would make it vacuous)"

key-files:
  created:
    - app/Enums/QualityBand.php
    - app/Review/CategoryAssigner.php
    - app/Review/Data/CategoryDecision.php
    - app/Review/DraftPublishability.php
    - config/review.php
    - database/factories/EventDraftFactory.php
    - tests/Feature/Review/ApproveDraftTest.php
    - tests/Feature/Review/DraftWriterTest.php
    - tests/Unit/Enums/ReviewStatusTransitionsTest.php
    - tests/Unit/Architecture/WriteChokePointTest.php
  modified:
    - app/Models/EventDraft.php
    - app/Publishing/Actions/ApproveDraft.php
    - app/Review/DraftWriter.php
    - phpunit.xml

key-decisions:
  - "Arch test uses expect(PublishEvent)->toOnlyBeUsedIn('App\\Publishing\\Actions'): the planned expect('App')->not->toUse()->ignoring('App\\Publishing\\Actions') can never fail because Pest's ignoring() also skips matching dependencies, and PublishEvent lives in that namespace (proved with a planted App\\Jobs bypass)"
  - "DraftWriter keeps submitted_category_id only when the category exists; a stale or tampered id falls back to Other instead of an FK violation"
  - "phpunit.xml memory_limit 512M: the Pest arch scan stays in memory for the rest of the run and the Feature suite then exceeded 128M"
  - "DraftPublishability::skipMessage() throws InvalidArgumentException for an unknown key (fail loud, not a generic reason)"

patterns-established:
  - "DraftPublishability::blockers(): ordered blocker ids kind, no_venue, no_category, no_title, no_description, no_start_date, no_start_time, no_contact, repeats_unacknowledged, then invariant"
  - "EventDraftFactory default = publishable pending manual draft scored High; states build each queue situation"

requirements-completed: [REV-02, REV-11]

coverage:
  - id: D1
    description: "Unpublishable draft refused with its exact plain-English blocker(s); draft stays pending, no event"
    requirement: "REV-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/ApproveDraftTest.php#refuses an unpublishable draft with exactly its blocker (12 cases) + lists every field blocker in a fixed order"
        status: pass
    human_judgment: false
  - id: D2
    description: "After DraftWriter::update sets the venue, the same draft approves; event and draft record the same admin and time"
    requirement: "REV-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/ApproveDraftTest.php#publishes the same draft once the admin sets the venue"
        status: pass
    human_judgment: false
  - id: D3
    description: "Recurring draft approved once; occurrences at correct UTC instants across both DST changes"
    requirement: "REV-11"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/ApproveDraftTest.php#publishes a weekly series once ... end of BST; #publishes a monthly series ... BST begins"
        status: pass
    human_judgment: false
  - id: D4
    description: "Double approve creates one event (InvalidReviewTransition, reviewed_at unchanged); non-admin refused"
    requirement: "REV-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/ApproveDraftTest.php#creates one event when the same draft is approved twice; #refuses approval by anyone but the admin"
        status: pass
    human_judgment: false
  - id: D5
    description: "Bulk skip keys in fixed order with specific reasons; skipMessage wording"
    requirement: "REV-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Review/ApproveDraftTest.php (bulk key cases, unresolved_venue, repeats_unset, duplicate, skip messages)"
        status: pass
    human_judgment: false
  - id: D6
    description: "PublishEvent usable only inside App\\Publishing\\Actions"
    requirement: "REV-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Architecture/WriteChokePointTest.php"
        status: pass
    human_judgment: false
  - id: D7
    description: "ReviewStatus machine: all 25 pairs pinned"
    requirement: "REV-02"
    verification:
      - kind: unit
        ref: "tests/Unit/Enums/ReviewStatusTransitionsTest.php"
        status: pass
    human_judgment: false

duration: 9min
completed: 2026-10-17
status: complete
---

# Phase 2 Plan 03: Approval Contract and Write Choke Point Summary

**One `DraftPublishability` check now decides whether a draft can be published and says why not in plain English. `ApproveDraft` enforces it on the locked row, `DraftWriter::update()` lets the admin fix a pending draft, bulk approve gets specific skip keys, and a Pest arch test makes `PublishEvent` reachable only from the Publishing actions.**

## Performance

- **Duration:** 9 min
- **Started:** 2026-10-17T18:54:38Z
- **Completed:** 2026-10-17T19:03:24Z
- **Tasks:** 2
- **Files modified:** 14

## Accomplishments

- **`DraftPublishability`:**
  - `blockers()` returns the ordered plain-English blockers. The PublishableEventData invariants are only tried once every field-level blocker is clear, so no gap is reported twice.
  - `bulkBlockerKeys()` returns the skip keys in the fixed order parse_errors, duplicate, low_quality, unresolved_venue, repeats_unset, blocked. `blocked` appears only for blockers that no specific key covers.
  - `skipMessage()` gives the wording for each key.
- **`ApproveDraft`** throws `DraftNotPublishable` with those blockers before publishing, whatever the page showed.
- **`DraftWriter`:**
  - `update()` locks the row, refuses drafts that aren't pending, and recomputes derived state.
  - `derive()` now runs `CategoryAssigner`: the preferred category with a "Chosen by …" reason, otherwise Other, flagged "No match → Other".
- **Shared pieces for later plans:** `QualityBand` and `config/review.php`, the `EventDraftFactory` states, and `CategoryAssigner::normalise()`.
- **Recurrence (REV-11):** a weekly series approved once crosses the end of BST with correct UTC instants. A monthly series starting on the day BST begins has its first occurrence at 09:00 UTC.

## Task Commits

1. **Task 1: Tracer: blocker → admin fix → publish:** `c1c2129` (feat)
2. **Task 2: Contract tests and the choke-point arch test:** `a97500f` (fix: a DraftWriter bug the tests exposed) and `439a42b` (test)

`commits: 4` is measured with `git rev-list` from the ledger. It includes `49c7a4c docs(research): owner-facing event source inventory`, which the owner committed during execution. It is not part of this plan.

## Files Created/Modified

- `app/Review/DraftPublishability.php`: the single publishability check (single approve and bulk)
- `app/Review/CategoryAssigner.php`, `app/Review/Data/CategoryDecision.php`: the two ends of the PIPE-03 chain, plus `normalise()`
- `app/Enums/QualityBand.php`: High/Medium/Low with `fromScore()`. Cast on `EventDraft::quality_band`
- `config/review.php`: quality weights and bands, retention days, submission limits, venue similarity threshold
- `app/Review/DraftWriter.php`: `update()`, CategoryAssigner in `derive()`, and the submitted category is checked for existence
- `app/Publishing/Actions/ApproveDraft.php`: blockers check on the locked row
- `app/Models/EventDraft.php`: `HasFactory`, `QualityBand` cast
- `database/factories/EventDraftFactory.php`: test-only factory with states
- `phpunit.xml`: `memory_limit` 512M
- Tests: `ApproveDraftTest` (43), `DraftWriterTest` (10), `ReviewStatusTransitionsTest` (26), `WriteChokePointTest` (1)

## Decisions Made

- The arch test uses `toOnlyBeUsedIn` instead of `->ignoring()` (see Deviations).
- A stale or tampered organiser category id is dropped rather than stored.
- An unknown bulk skip key throws instead of falling back to a generic reason.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The planned arch test could never fail**
- **Found during:** Task 2 (mutation check)
- **Issue:** I planted an `App\Jobs` class that injects `PublishEvent`, and `expect('App')->not->toUse(PublishEvent)->ignoring('App\Publishing\Actions')` still passed. Pest's `ignoring()` skips matching targets *and dependencies*, and `PublishEvent` is itself in `App\Publishing\Actions`.
- **Fix:** Switched to `arch()->expect('App\Publishing\Actions\PublishEvent')->toOnlyBeUsedIn('App\Publishing\Actions')`. It fails on the planted bypass ("not to be used on 'App\Jobs\TempBypassProbe'") and passes on the real code. It has no namespace allow-list, so the T-02-05 intent holds for jobs and future ingestion namespaces. The docblock mentions in `Event` and `EventSlugger` don't trigger it, so they were left alone. The test's comment explains why `->ignoring()` isn't used. The word "ignoring" appears only in that comment, which still meets the acceptance grep.
- **Files modified:** tests/Unit/Architecture/WriteChokePointTest.php
- **Commit:** 439a42b

**2. [Rule 1 - Bug] A submission with a non-existent category id crashed on the foreign key**
- **Found during:** Task 2 (DraftWriterTest)
- **Issue:** `DraftWriter::create` copied `preferredCategoryId` into `submitted_category_id` without checking it, so a deleted or tampered id raised `event_drafts_submitted_category_id_foreign`.
- **Fix:** The id is kept only when the category exists. The draft then falls back to Other, flagged.
- **Files modified:** app/Review/DraftWriter.php
- **Commit:** a97500f

**3. [Rule 3 - Blocking] The full suite ran out of memory once the arch test was added**
- **Found during:** Task 2 (full Unit+Feature run)
- **Issue:** The full suite hit the 128M memory limit (Fatal in Filament's Schema). Without the arch test it passes: 626 tests at 128M. Pest's arch scan keeps the parsed codebase in memory for the rest of the process.
- **Fix:** Added `<ini name="memory_limit" value="512M"/>` to `phpunit.xml`, with a comment. Result: 627 passed. phpunit.xml was outside the plan's `files_modified`.
- **Files modified:** phpunit.xml
- **Commit:** 439a42b

### Other notes

- **Task 2 tests did not start RED.** Task 1's implementation already covered them. To check they would catch a regression, I made two temporary changes: disabled the blockers check in ApproveDraft (7 of 43 ApproveDraftTest cases failed), and planted the arch bypass described above. Both were reverted before committing.
- **Monthly DST case freezes time at 1 Mar 2027.** The occurrence horizon is 90 days (`calendar.horizon_days`), so a series starting 28 Mar 2027 would not be generated from 16 Oct 2026.
- **Commits went to `main`.** I committed there because the orchestrator asked for sequential mode on the main tree, `git.branching_strategy` is `none`, and 02-01 did the same. Nothing was pushed.

**Total deviations:** 3 auto-fixed (2 Rule 1, 1 Rule 3)
**Impact on plan:** The planned arch test would not have enforced anything, and its replacement does. The DraftWriter fix is small. No change in scope.

## Notes for Later Plans

- **Website-only contact:** a draft whose only contact is `organiser_website` passes the `no_contact` blocker. It is then refused by the PublishableEventData invariant "Add a source URL or an organiser contact." because `fromDraft()` doesn't carry `organiser_website`. 02-04 owns PublishableEventData and the new events columns, and can let the website count there.
- **`DraftWriter::update()` reason:** the category reason follows `DraftData::origin`. 02-14's edit page should pass the origin that gives the reason it wants (e.g. `Manual` for "Chosen by admin" when the admin changes the category).
- **Unscored drafts:** they count as Low for bulk until 02-08 scores every write. The factory default is High/100.

## Issues Encountered

None beyond the deviations above.

## Known Stubs

None. `CategoryAssigner::assign()` accepts `$sourceValues` and `$title` but doesn't use them yet. This is intentional and documented: 02-11 and 02-07 insert their chain links between the two that exist now.

## Verification

- Task 1 verify: ApproveDraftTest and ManualEntryThroughDraftTracerTest, 10 passed
- Task 2 verify: the four plan test files, 80 passed
- Full Unit+Feature suite: 627 passed. Pint passed. PHPStan (`--memory-limit=1G`): 0 errors
- Acceptance greps: the five DST strings (1 each), `toBe(['unresolved_venue'])` (1), `toBe(['repeats_unset'])` (1), `=> [ReviewStatus::` (25), `ignoring` (2, in the explanatory comment), `bulkBlockerKeys` (3), `blockers(` in ApproveDraft (1)

## Self-Check: PASSED
