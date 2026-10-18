---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 17
subsystem: browser-uat
tags: [uat, browser, playwright, pest, sources, review, attribution]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-15 Sources admin (Test run, Create drafts, Run now, Pause, run log); 03-13 review page (diff, Apply / Ignore, Confirm cancellation, duplicate panel, Approve all like this, Urgent); 03-16 public attribution and SourceSeeder; 03-14 F14 step 5; owner commits 81d8b25 (field help) and a048cc8 (all-day preview rows)"
provides:
  - "docs/uat/browser-uat.md flows F15-F20 (Sources, update draft, cancellation draft, duplicate warning, Approve all like this + Urgent, public attribution), automation table rows and the trimmed later-phases list"
  - "tests/Browser/SourcesTest.php (F15 x2, F20) and tests/Browser/SourceReviewTest.php (F16-F19)"
  - "UatFixtures: fakeIngestion(), serveFeed(), feedFixture(), ingestedSource(), runSource(), workQueue(), reviewPath(), waitForModalClosed(), dismissNotifications(), waitForPageText(), OPEN_MODAL and the seeded feed URL constants"
affects: [Phase 3 verification / UAT sign-off, Phase 4 browser flows for the other extraction rungs]

actuals:
  tokens: 13000
  tasks: 2
  commits: 2
plan_head_before: fa55565a60b33d6dbb2908885caf6742c67aba13
plan_head_after: 8fa5bb735196da2de5cf560cd4d5c33f49d72cdf

tech-stack:
  added: []
  patterns:
    - "Offline ingestion in browser tests: Http::fake keyed by exact feed URL over the captured fixtures, robots allow-all, 404 for anything else, fake HostResolver, Sleep::fake(syncWithCarbon) and Storage::fake('local'); feeds swapped mid-test through a container-bound ArrayObject (serveFeed)"
    - "Queued runs worked from the test process with queue:work --once on the database connection (FetchSourceJob is pinned to it), so the in-process server and the worker share the RefreshDatabase transaction"
    - "State for review flows built only through ingestion: SourceSeeder source → RunLauncher testRun + createDrafts → ApproveDraft → edited fixture copy → runNow"
    - "Close toasts before clicking header actions (they cover the top-right of the page)"
    - "Helpers live as UatFixtures static methods or file-prefixed functions (sources*, srev*) so the whole Browser suite loads without redeclaring functions or constants"

key-files:
  created:
    - tests/Browser/SourcesTest.php
    - tests/Browser/SourceReviewTest.php
  modified:
    - tests/Browser/UatFixtures.php
    - docs/uat/browser-uat.md

key-decisions:
  - "ingestedSource() takes the SEEDED source live (SourceSeeder + RunLauncher test run + Create drafts) instead of a factory source, so the browser flows exercise the real seeded configuration (URLs, identity strategies, Town Council title rules); settings the admin would pick on the form (default venue, category) are applied first"
  - "F15 has two tests: the full onboarding of a source typed into the form (with the '?' Feed URL help dialog) and a seeded Academy test run that pins the all-day preview rows ('Mon 19 Oct' with no time; 'Thu 12 Nov, 6pm' timed), guarding the a048cc8 crash fix"
  - "F20 covers 'Also listed by' with a synthetic second calendar (one VEVENT copied from the Baptist fixture under a new UID) whose draft is marked a duplicate through the UI; the organiser's own source stays 'Listed by'"
  - "F16/F17 open drafts from Review queue → Pending after searching the table for 'Yoga' (the queue shows 10 rows a page and the Town Council feed alone gives 29 pending drafts); F18/F19 open the review page directly"
  - "F17 uses whole-event cancellations of two real Town Council one-offs (STATUS:CANCELLED added to the fixture copy); the event page then shows Cancelled in its heading and 'This event has been cancelled.'"
  - "F19 checks the urgent update leads Pending and is the only Urgent row; the non-urgent 25 Oct update's row position is not asserted (it is on a later page of the 10-row queue)"
  - "No requirement status changed: SRC-01, SRC-03, REV-05, REV-06, REV-07 and LEG-04 were already Complete; this plan re-checks them in the browser"

patterns-established:
  - "Phase 4+ browser flows that need feeds call Uat::fakeIngestion() in beforeEach and build state with Uat::ingestedSource() / Uat::runSource() / Uat::serveFeed()"

requirements-completed: [SRC-01, SRC-03, REV-05, REV-06, REV-07, LEG-04]

coverage:
  - id: D1
    description: "F15: '?' Feed URL help dialog, New source saved inactive, Test run preview ('Would create 46 drafts', Beyond 90 days, rows with UK times), Create drafts drafts exactly the preview count, run log 2 then 3 rows, Run now, Pause hides Run now/Pause, Healthy in the sources table, drafts in the queue"
    requirement: SRC-01
    verification:
      - kind: e2e
        ref: "tests/Browser/SourcesTest.php#F15: registers an ICS source, previews it, creates drafts, runs it now and pauses it"
        status: pass
  - id: D2
    description: "F15: a seeded source's all-day preview rows show the date only"
    requirement: SRC-03
    verification:
      - kind: e2e
        ref: "tests/Browser/SourcesTest.php#F15: a seeded source previews its all-day items as dates without a time"
        status: pass
  - id: D3
    description: "F20: Listed by (item link), Also listed by after Mark as duplicate, Read more at, card 'via Baptist Church'"
    requirement: LEG-04
    verification:
      - kind: e2e
        ref: "tests/Browser/SourcesTest.php#F20: a discovered event credits its source on the event page and the card"
        status: pass
  - id: D4
    description: "F16: Update row → diff table rows Start/End time → Apply changes → event page 11am (11:00 UTC on 25 Oct)"
    requirement: REV-06
    verification:
      - kind: e2e
        ref: "tests/Browser/SourceReviewTest.php#F16"
        status: pass
  - id: D5
    description: "F17: Confirm cancellation → event Cancelled; Ignore → Rejected/Ignored, no Cancellation rows left"
    requirement: REV-07
    verification:
      - kind: e2e
        ref: "tests/Browser/SourceReviewTest.php#F17"
        status: pass
  - id: D6
    description: "F18: side-by-side panel with 'similarity 0.78', Not a duplicate clears it, Mark as duplicate publishes nothing"
    requirement: REV-05
    verification:
      - kind: e2e
        ref: "tests/Browser/SourceReviewTest.php#F18"
        status: pass
  - id: D7
    description: "F19: Approve all like this → 'Approved 3 of 3.', three cards with 'via Town Council'; urgent update first with the Urgent badge"
    requirement: REV-06
    verification:
      - kind: e2e
        ref: "tests/Browser/SourceReviewTest.php#F19"
        status: pass

duration: ~19min
completed: 2026-10-18
---

# Phase 3 Plan 17: Browser UAT flows F15-F20 Summary

**The Phase 3 admin and public UI now has runbook flows F15-F20 and seven offline Playwright tests. They cover onboarding a source (with the "?" help dialog, the Test run preview including all-day rows, Create drafts, Run now, Pause and the run log), the three-column update diff with Apply changes, Confirm cancellation / Ignore, the side-by-side duplicate panel, Approve all like this with the Urgent-first queue, and "Listed by" / "Also listed by" / "Read more at" / "via". The full Browser suite (40 tests) passed twice in a row.**

## Performance

- **Duration:** ~19 min (12:40Z to 12:59Z on 2026-10-18)
- **Tasks:** 2 (1 tracer, 1 auto)
- **Files:** 2 created, 2 modified

## Accomplishments

- **Runbook:**
  - F15 Sources: the field help, New source, Test run with the all-day rows, Create drafts, Run now, Pause, run log and table health.
  - F16 update draft, F17 cancellation draft, F18 duplicate warning, F19 Approve all like this + Urgent, F20 public attribution.
  - Each flow follows the existing shape: steps, selectors, expected result, public effect.
  - The F0 sidebar line now lists Sources.
  - The automation table lists both new files.
  - "Adding flows in later phases" drops the Phase 3 items. The old Phase 4 "test-run preview" item became "the other extraction rungs".
  - The automation notes now describe the offline ingestion helpers.
- **Offline ingestion helpers in UatFixtures:**
  - The three seeded feed URLs answer their captured fixtures, robots.txt allows everything and every other URL gets a 404.
  - The resolver, Sleep and the disk are faked.
  - The queue is worked in-process.
  - No test reaches the network: `Http::preventStrayRequests()` stays global.
- **Seven browser tests,** all with frozen London time, role/label locators, `settleFields` after the form fill, and `waitUntil` / `waitForPageText` for Livewire polls instead of sleeps.

## Task Commits

1. **Task 1 (tracer): F15 + F20 in the browser, offline ingestion fixtures**: `d03f00d` (test)
2. **Task 2: F16-F19 review flows**: `8fa5bb7` (test)

**Tracer gate:** after Task 1, I re-ran its `<verify>` from start to finish (`npm run build`, then `SourcesTest.php`: 3 passed). Only then did I start Task 2.

## Verification

- `npm run build`, then `pest --testsuite=Browser`: **40/40 passed in run 1 (599 assertions) and 40/40 in run 2.** The baseline before this plan was 33/33.
- The new files also pass with `TZ=America/New_York` (CI timezone): 7/7.
- `pest --testsuite=Unit,Feature`: 1636/1636 passed.
- `phpstan analyse --memory-limit=1G`: 0 errors. `pint --dirty`: clean.
- Acceptance greps:
  - `^## F15\.` = 1, `^## F20\.` = 1, `^## F1[6-9]\.` = 4;
  - `settleFields` is in SourcesTest;
  - `SourceReviewTest.php` is in the runbook;
  - `Create drafts`, `Apply changes` and `fakeIngestion` are present.
- The only warning is the plugin's suppressed `rmdir(tests/Browser/Traces)` notice, which was already there before this plan.

## Decisions Made

See `key-decisions` in the frontmatter. The main ones:
- **ingestedSource() uses the seeded sources.** State comes from SourceSeeder plus RunLauncher (D-01 path), not factory rows, so the browser run also checks the real seeded configuration.
- **"Also listed by" uses a synthetic second calendar.** It is one copied VEVENT under a new UID. Marking its draft a duplicate in the UI gives the Baptist event two links.
- **The queue is searched.** F16 and F17 search Review queue → Pending for "Yoga", because the queue pages at 10 rows.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Toasts covered the header actions**
- **Found during:** Task 1 (the Pause click timed out after Run now)
- **Issue:** Filament's toast sits over the top-right header actions, so Playwright waited until the click timed out.
- **Fix:** I added `Uat::dismissNotifications()`, which closes the toasts and waits until they have gone. Both new files call it before each header-action click. The runbook mentions it in F15's selectors note.
- **Files modified:** tests/Browser/UatFixtures.php, tests/Browser/SourcesTest.php, tests/Browser/SourceReviewTest.php
- **Commit:** d03f00d, 8fa5bb7

**2. [Rule 3 - Blocking] Review queue pagination**
- **Found during:** Task 2
- **Issue:** the Town Council feed gives 29 pending drafts and the queue shows 10 rows a page, so the Update and Cancellation rows were not on page 1.
- **Fix:** the flows search the table for "Yoga" first. The runbook says so.
- **Commit:** 8fa5bb7

### Judgement calls (unattended run)
- **Field-help step:** F15 opens the "?" next to Feed URL (owner commit 81d8b25), as the handoff asked.
- **All-day rows:** they are tested on the seeded Academy source. The Baptist feed has no all-day items.
- **Ingestion helpers:** the plan's proposed `ingestedSource(string $slugFixture, string $identity, bool $approveFirst)` became `ingestedSource(string $slug, array $settings)`. The identity comes from the seeder, and approval stays an explicit step (`ingestApprove`) in each test.
- **Out of scope:** the CI workflow comment still says "Browser UAT flows F0-F10". I did not touch it because it is outside this plan's files.

**Total deviations:** 2 auto-fixed (both test harness issues, no app code changed). **Impact:** none on scope. No packages were installed.

## Known Stubs
None. (`.planning/WINDOWS.md` does not exist, so nothing was appended.)

## Threat Flags
None. These are tests and docs only. T-03-46 is mitigated: every feed and robots request is answered by `Http::fake` under the global `preventStrayRequests`, and the fake HostResolver means no DNS lookups.

## Next Phase Readiness
- Phase 3 verification can run the UAT on the new flows. The phase's `03-UAT.md` should record F15-F20, all automated and passing.
- Phase 4: new extraction rungs reuse `Uat::fakeIngestion()` (add their fixture URLs to `FEED_FIXTURES`) and add their Sources form and preview checks to F15.

## Self-Check: PASSED

- FOUND: tests/Browser/SourcesTest.php, tests/Browser/SourceReviewTest.php, tests/Browser/UatFixtures.php, docs/uat/browser-uat.md
- FOUND commits: d03f00d, 8fa5bb7 (both ancestors of HEAD)
