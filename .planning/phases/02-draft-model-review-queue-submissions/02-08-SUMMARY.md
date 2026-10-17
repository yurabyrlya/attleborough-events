---
phase: 02-draft-model-review-queue-submissions
plan: 08
subsystem: review
tags: [laravel, quality-score, venue-matching, pg_trgm, pipe-04, d-05, d-06]
status: complete

requires:
  - phase: 02-03
    provides: DraftWriter::derive() hook, QualityBand::fromScore(), config/review.php quality.weights/bands and venue_similarity_threshold, event_drafts quality_* and parse_errors columns
provides:
  - App\Review\QualityScorer::score(EventDraft, bool $venueRecognised, bool $categoryFlagged): QualityResult (deterministic 0-100, band, ordered reasons)
  - App\Review\Data\QualityResult (final readonly: int score, QualityBand band, list<string> reasons)
  - App\Review\VenueMatcher::exact(?name, ?postcode): ?Venue and suggest(?name, ?postcode): ?VenueSuggestion (read-only)
  - App\Review\Data\VenueSuggestion (final readonly: venueId, venueName, matchedBy name|alias|postcode|similar, float score)
  - DraftWriter::derive() stores quality_score / quality_band / quality_reasons on every create and update
affects: [02-12, 02-13, 02-14, 02-16]

actuals:
  tokens: 7062
  tasks: 2
  commits: 3
plan_head_before: 3479595c44b8dc0f50bd08107fc5fac675d797ef
plan_head_after: 12232428d01fc5a58c2cb11ffe8d27284377f817

tech-stack:
  added: []
  patterns:
    - "Pure scorer: fixed-order deduction list, integer weights from config, clamp max(0, min(100, …)), band via QualityBand::fromScore()"
    - "Venue matching cascade: lower(name) = lower(?) → jsonb_array_elements_text(aliases) exists → replace(upper(postcode),' ','') = ? → similarity(name, ?) >= threshold ordered by score desc, id asc; all bound parameters"

key-files:
  created:
    - app/Review/QualityScorer.php
    - app/Review/Data/QualityResult.php
    - app/Review/VenueMatcher.php
    - app/Review/Data/VenueSuggestion.php
    - tests/Feature/Review/DraftScoringTest.php
    - tests/Unit/Review/QualityScorerTest.php
    - tests/Feature/Review/VenueMatcherTest.php
  modified:
    - app/Review/DraftWriter.php

key-decisions:
  - "'Venue not recognised' (-20) applies only to a typed venue; a draft with no venue_id and no venue name gets 'Missing venue' (-50) only, so the venue factor is never counted twice"
  - "'No end time' (-10) applies to every non-all-day draft without an end time, even when the start time is also missing (title-only draft lists it after the six missing-field reasons)"
  - "Parse errors: blank entries ignored; one -40 deduction; up to three \"Couldn't read: {error}\" lines then 'and N more'"
  - "Venue recognised = venue_id set OR VenueMatcher::exact(venue_name, venue_postcode) hits; venue_id is never written by matching (D-05, D-21)"
  - "Exact matching trims input; postcode input has all whitespace removed and is upper-cased; ties at each step resolve to the lowest venue id"

patterns-established:
  - "Reason wording: 'Missing title|start date|start time|venue|description', 'Missing a contact, website or link', \"Couldn't read: …\", 'and N more', \"Venue not recognised: '{name}'\", 'No category matched, set to Other', 'No end time', \"Organiser says it repeats ('{text}') but no repeat rule is set\" (raw text; escape at display)"

requirements-completed: [PIPE-04]

duration: 5min
completed: 2026-10-17
---

# Phase 02 Plan 08: Quality scoring and venue matching Summary

**Deterministic 0-100 draft quality score with band and fixed-order plain-English reasons, re-computed on every DraftWriter write, plus a read-only venue matcher (exact name/alias/postcode, then pg_trgm similarity) that suggests but never creates or assigns a venue.**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-10-17T19:50:45Z
- **Completed:** 2026-10-17T19:55:25Z
- **Tasks:** 2
- **Files modified:** 8 (7 created, 1 modified)

## Accomplishments

- `QualityScorer`: starts at 100 and deducts the `config('review.quality.weights')` values once per factor in the fixed order (missing fields → parse errors → venue → category → end time → repeats). Clean draft 100/High with no reasons; unknown venue + no end 70/Medium; + Other + unset repeats 30/Low; title-only clamps to 0/Low.
- `VenueMatcher`: `exact()` and `suggest()` with bound parameters only and no writes. `suggest()` returns the exact match at score 1.0 with `matchedBy`, otherwise the most similar name at or above `review.venue_similarity_threshold` (0.3).
- `DraftWriter::derive()` scores every create and update, so a reviewer who picks the venue and adds an end time lifts a submission from 70/Medium to 100/High on save (tracer test).

## Task Commits

1. **Task 1: Tracer: score on every draft write; venue matcher** - `e80ebce` (feat)
2. **Task 2: Scoring edges and venue matching cases** - `1223242` (test)

**Plan metadata:** see final docs commit

_Note: the measured `commits: 3` includes `b344e41 docs(research): gap-category research…`, an unrelated commit that landed on main from elsewhere while this plan ran (it touches only `docs/research/`). This plan made 2 task commits._

## Files Created/Modified

- `app/Review/QualityScorer.php` - pure deterministic scorer; weights from config
- `app/Review/Data/QualityResult.php` - score/band/reasons value object
- `app/Review/VenueMatcher.php` - exact + trigram venue matching, read-only
- `app/Review/Data/VenueSuggestion.php` - suggestion value object
- `app/Review/DraftWriter.php` - derive() now runs VenueMatcher::exact + QualityScorer
- `tests/Feature/Review/DraftScoringTest.php` - tracer: 70/Medium → 100/High, exact typed-venue recognised without setting venue_id, 30/Low
- `tests/Unit/Review/QualityScorerTest.php` - band boundaries, clamping, ordering, parse-error lines, all-day / acknowledged / rule-set exemptions, config weights
- `tests/Feature/Review/VenueMatcherTest.php` - name/alias/postcode, trigram ranking and threshold, SQL-looking input, Venue::count() unchanged after every test

## Decisions Made

See `key-decisions` in frontmatter. The main judgement calls (left open by the plan) were not counting the venue twice when it is missing, and still listing "No end time" on a timed draft that has no start time.

## TDD Notes (Task 2)

Task 2's tests passed on their first run because Task 1 had already built both services. Following "if the test passes, check whether the feature exists or the test is wrong", I ran a mutation check: I temporarily broke the code (parse-error line cap 3→4, removed the lower clamp, removed postcode space-stripping, flipped the similarity order), then restored it with `git checkout -- <file>`. The first three mutations broke 5 tests. The order flip broke nothing, so I added "picks the most similar of several candidate names", which now fails under that mutation. No RED commit exists for Task 2, because there was no missing behaviour to implement.

## Deviations from Plan

None in behaviour. Process notes:
- Committed on `main`: `branching_strategy: none` and the orchestrator set sequential main-tree mode. The #3819 protected-branch probe reports `main` as protected, but every earlier phase-02 plan committed here as well.
- Added one extra VenueMatcher test (similarity ranking) found by the mutation check. It is a test-only addition.

## Issues Encountered

- The full suite prints `warnings: 1` with no details. Running this plan's test files with `--display-warnings` shows no warnings, so it comes from elsewhere and is out of scope.

## Verification

- Task 1: `pest DraftScoringTest ApproveDraftTest DraftWriterTest`: 57 passed
- Task 2: `pest QualityScorerTest VenueMatcherTest`: 34 passed; Pint passed; PHPStan 0 errors; full Unit+Feature suite **801 passed**
- Acceptance greps: `similarity(` in VenueMatcher = 2; `->(create|insert|save)(` in VenueMatcher = 0; `QualityScorer` in DraftWriter = 2; `review.quality.weights` in QualityScorer = 2

## Next Phase Readiness

- 02-12 / 02-14 can call `VenueMatcher::suggest()` to pre-select the venue Select (D-05) and show `quality_reasons` (escaped).
- Bulk approve's Low skip (02-03 keys) now gets real bands on new and updated drafts. Drafts written before this plan keep their old (factory or null) scores until their next write.

## Self-Check: PASSED

- FOUND: app/Review/QualityScorer.php, app/Review/Data/QualityResult.php, app/Review/VenueMatcher.php, app/Review/Data/VenueSuggestion.php, tests/Feature/Review/DraftScoringTest.php, tests/Unit/Review/QualityScorerTest.php, tests/Feature/Review/VenueMatcherTest.php
- FOUND commits: e80ebce, 1223242 (ancestors of HEAD)
