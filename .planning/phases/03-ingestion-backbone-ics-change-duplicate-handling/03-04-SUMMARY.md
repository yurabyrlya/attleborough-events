---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 04
subsystem: ingestion
tags: [ics, sabre-vobject, recurrence, timezones, dst, hashing, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-01 Source model, factory withIdentity(), parser_config default {identity: uid}; 03-02 sourceFixture() helper and config/ingest.php (max_events)"
provides:
  - "App\\Ingestion\\Ics\\IcsExtractor::extract(string $body, Source $source, ?CarbonImmutable $now = null): ExtractionResult"
  - "IcsTimezoneResolver (failIfUncertain TZID pre-validation, Greenwich Standard Time flag, source zone)"
  - "IcsItemNormaliser (title/description text rules, LOCATION → venue name/address/UK postcode, URL via SafeLinks rule, CATEGORIES, internal ORGANIZER)"
  - "NormalisedFacts (toPayload / fromPayload), ExtractedItem, ExtractionResult DTOs"
  - "ContentHasher::rawHash / contentHash / contentHashFromPayload (one hashing contract)"
  - "WindowClassifier::classify(facts, nowLondon): past | in_window | far_future over calendar.horizon_days"
  - "IdentityStrategy enum (uid, tec_post_id, summary_start) with TEC_POST_ID_PATTERN"
  - "Synthetic fixtures tests/Fixtures/ics/series.ics and edge-cases.ics; icsFixture() Pest helper"
affects: [03-05 runs and counters, 03-06 jobs, 03-09 change detection and draft writer, 03-10 publishing actions (excluded_dates / cancelled keys)]

actuals:
  tokens: 24224
  tasks: 2
  commits: 2
plan_head_before: 019a458585b9a3be23fc2671931852575b7df257
plan_head_after: c6e895bbaf1136586c2ea2692ba940be970a72bb

tech-stack:
  added: []
  patterns:
    - "Group VEVENTs by UID first (overrides always join their master), then merge UID groups by the identity strategy"
    - "Feed RRULEs rebuilt into ManualRecurrence's exact shape and kept only if tryFromStoredRule round-trips (D-19)"
    - "Window classification reuses EventTiming + RecurrenceExpander on an unsaved Event built from the facts (same REC-02 expiry as published occurrences)"
    - "PHPStan level 6 with sabre Traversable classes: VEvent<mixed> / Component<mixed> docblocks"

key-files:
  created:
    - app/Enums/IdentityStrategy.php
    - app/Ingestion/Data/NormalisedFacts.php
    - app/Ingestion/Data/ExtractedItem.php
    - app/Ingestion/Data/ExtractionResult.php
    - app/Ingestion/Ics/IcsExtractor.php
    - app/Ingestion/Ics/IcsTimezoneResolver.php
    - app/Ingestion/Ics/IcsItemNormaliser.php
    - app/Ingestion/Pipeline/ContentHasher.php
    - app/Ingestion/Pipeline/WindowClassifier.php
    - tests/Fixtures/ics/series.ics
    - tests/Fixtures/ics/edge-cases.ics
    - tests/Fixtures/ics/.gitattributes
    - tests/Unit/Ingestion/IcsExtractorTest.php
    - tests/Unit/Ingestion/ContentHasherTest.php
  modified:
    - tests/Pest.php

key-decisions:
  - "The window end is now + calendar.horizon_days in London (plan truth), not startOfDay + 90 like OccurrenceGenerator: an instance starting exactly at the window end is far_future"
  - "Feed RRULEs are canonicalised before the D-19 check: parts reordered to FREQ;BYDAY;UNTIL|COUNT, INTERVAL=1 and WKST dropped, a WEEKLY rule with no BYDAY gets the start's weekday, and a timed UNTIL becomes the London end of day of the last date it still includes. Kept only if ManualRecurrence round-trips it; otherwise facts.rrule keeps the feed's rule and the item gets the parse error"
  - "Items with facts = null (unknown TZID, end before start, sabre recurrence exception, orphan override, no DTSTART) also have contentHash = null; source_items.content_hash is nullable"
  - "Greenwich Standard Time keeps its facts (read as sabre maps it, Atlantic/Reykjavik) plus a parse error asking the admin to check the times, so the draft still shows and bulk approval is blocked"
  - "An end before the start (compared as UTC instants, each in its own TZID) makes facts null, like an unknown TZID; a DTEND equal to DTSTART is zero duration and gives a null end"
  - "An override that keeps its time but changes the title or location is also a parse error and is recorded in movedOverrides (with its unchanged start/end), so it changes content_hash"
  - "summary_start external id = '{raw DTSTART value}|{normalised SUMMARY}', using the raw value so the identity never depends on a time zone lookup; ids over 255 bytes are cut to 190 bytes + '#' + sha256"
  - "A missing UID falls back to the summary_start key under every strategy; a TEC UID that does not match the pattern falls back to the whole UID"
  - "venue_norm in content_hash covers the whole LOCATION (name + address), so an address change reaches the admin; postcode, description, URL, categories, organiser and next instance never change the hash"
  - "nextInstanceStartUtc is the first instance whose REC-02 expiry is after now (sabre EventIterator, fastForward to now - no_end_expiry_hours), falling back to DTSTART when every instance has expired"
  - "max_events is checked by counting BEGIN:VEVENT lines before parsing, so an oversized feed is refused without a full sabre parse (T-03-11)"

patterns-established:
  - "Ingestion text: strip tags → html_entity_decode → NFC → collapse whitespace (including \\p{Z}); never shown unescaped"
  - "Parse error text is plain British English naming the date (\"Wed 28 Oct\") and the fix"

requirements-completed: [EXT-01, EXT-07, PIPE-01]

coverage:
  - id: D1
    description: "Baptist feed → 64 items (uid), 25 Oct 10:30 London = 10:30 UTC, 64-char raw and content hashes"
    requirement: EXT-01
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/IcsExtractorTest.php#turns the Baptist Church feed into 64 hashed items with London wall-clock times"
        status: pass
  - id: D2
    description: "Town Council feed → 30 items keyed by TEC post id; 24 Oct 12:00 BST = 11:00 UTC; a moved session keeps its id and changes content_hash"
    requirement: EXT-01
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/IcsExtractorTest.php#keeps the TEC identity when the source moves a session by an hour"
        status: pass
  - id: D3
    description: "Academy feed keyed by title + start, stable across churning UIDs/DTSTAMPs; half-term all-day end_date 30 Oct"
    requirement: EXT-01
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/IcsExtractorTest.php#keys the Academy feed by title and start so churning UIDs and DTSTAMPs do not matter"
        status: pass
  - id: D4
    description: "D-19 shapes: EXDATE and cancelled overrides publishable; moved override, RDATE and unsupported rule become parse errors with facts kept"
    requirement: EXT-01
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/IcsExtractorTest.php (series.ics tests)"
        status: pass
  - id: D5
    description: "Floating times in the source zone (01:30 repeated hour), unknown TZID → parse error with no facts, GMT Standard Time → London, Greenwich Standard Time flagged, mixed TZIDs compared in UTC"
    requirement: EXT-07
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/IcsExtractorTest.php (edge-cases.ics tests)"
        status: pass
  - id: D6
    description: "Window classes: past, exact window end far_future, one minute earlier in_window, all-day exclusive end, series by remaining instances"
    requirement: PIPE-01
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/IcsExtractorTest.php#classifies past items and the exact window end"
        status: pass
  - id: D7
    description: "content_hash: quiet vs loud fields, fixed key order, override/RDATE changes, payload round trip for every fixture item"
    requirement: EXT-01
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/ContentHasherTest.php"
        status: pass

duration: 13min
completed: 2026-10-18
---

# Phase 3 Plan 04: ICS Extractor, Hashes and Window Classifier Summary

**sabre/vobject extractor that turns an ICS body into one hashed item per series identity (uid, TEC post id or title + start). Times are correct London wall clock across both DST changes. D-19 recurrence shapes are classified, and items are sorted into past / in-window / far-future over the 90-day horizon.**

## Performance

- **Duration:** ~13 min of execution (after reading the context)
- **Started:** 2026-10-18T08:58:06Z
- **Completed:** 2026-10-18T09:10:40Z
- **Tasks:** 2
- **Files modified:** 15

## Accomplishments
- `IcsExtractor` reads the body with `Reader::read(..., OPTION_FORGIVING)`. It counts VEVENTs against `ingest.max_events` before parsing, groups components by UID and then by identity, and checks every TZID strictly. Instances are walked only with sabre's `EventIterator` in the source zone, under its own 500-instance cap. sabre's recurrence exceptions are caught per item. `Settings::$maxRecurrences` is never touched (grep finds 0 matches in app/Ingestion).
- All three real feeds are proven: Baptist 64 items, Town Council 30, Academy 59. Times across BST end are checked: Baptist 10:30Z = 10:30 GMT, TC 12:00 BST = 11:00Z.
- D-19 classification:
  - EXDATE values become `excludedDates`.
  - A cancelled override becomes a `cancelledKeys` entry.
  - A moved override, an RDATE or an unsupported rule becomes a parse error that names the date or rule. The facts are kept, so the draft still shows.
  - RRULEs the form can publish are rebuilt in the exact stored shape.
- `ContentHasher` provides two hashes. `raw_hash` ignores DTSTAMP, CREATED, LAST-MODIFIED and SEQUENCE, plus UID when the UID is not the identity. `content_hash` covers the D-06 facts in a fixed order. `contentHashFromPayload(toPayload())` equals `contentHash(facts)` for every fixture item, including after a JSON round trip.
- `WindowClassifier` applies the published REC-02 expiry rules (EventTiming / RecurrenceExpander) against `calendar.horizon_days`.

## Task Commits

1. **Task 1 (tracer): Baptist feed → 64 normalised, hashed, window-classified items**: `859ebcf` (feat)
2. **Task 2: all three feeds, synthetic series and edge cases**: `c6e895b` (test)

**Plan metadata:** recorded in the docs commit that follows this SUMMARY.

## Files Created/Modified
- `app/Enums/IdentityStrategy.php`: the uid / tec_post_id / summary_start strategies, and the TEC pattern as a code constant
- `app/Ingestion/Data/{NormalisedFacts,ExtractedItem,ExtractionResult}.php`: the DTOs, with payload round trip
- `app/Ingestion/Ics/IcsExtractor.php`: grouping, D-19 classification, schedule, next instance, ordering
- `app/Ingestion/Ics/IcsTimezoneResolver.php`: strict TZID check, the Greenwich flag, the source zone
- `app/Ingestion/Ics/IcsItemNormaliser.php`: text, venue/postcode, URL, categories and organiser rules
- `app/Ingestion/Pipeline/ContentHasher.php`: raw_hash and content_hash
- `app/Ingestion/Pipeline/WindowClassifier.php`: past / in_window / far_future
- `tests/Fixtures/ics/{series,edge-cases}.ics` (+ `.gitattributes` keeping CRLF): synthetic fixtures with no personal data
- `tests/Unit/Ingestion/IcsExtractorTest.php` (35 tests) and `ContentHasherTest.php` (31 tests)
- `tests/Pest.php`: the `icsFixture()` loader

## Decisions Made
See `key-decisions` in the frontmatter. These are judgement calls made during the unattended run, each within the plan's truths.

## Deviations from Plan

### Judgement calls (unattended run)

**1. Commits on `main`.** Per the orchestrator (`branching_strategy: none`, sequential on the main tree), as in every earlier phase.

**2. `icsFixture()` added to `tests/Pest.php`** (not in `files_modified`). Both new test files need it, and PATTERNS "Testing" says shared helpers belong there.

**3. `tests/Fixtures/ics/.gitattributes`** (not in `files_modified`). It copies the `-text` rule from `tests/Fixtures/sources/`, so git never normalises the fixtures' CRLF endings (PATTERNS "Synthetic fixtures").

**4. RRULE canonicalisation (Rule 2: correctness).** Feeds rarely emit ManualRecurrence's exact part order or its 23:59:59 UNTIL. Without canonicalisation, most publishable weekly series would have been parse errors. The rebuilt rule is only kept when `tryFromStoredRule` round-trips it, so the D-19 guarantee is unchanged.

**5. Optional `$now` parameter on `extract()`.** This is additive: callers that pass only `(body, source)` get `CarbonImmutable::now()`. It keeps the extractor pure for 03-05's preview and live runs.

**6. RED step for Task 2.** Task 1 implemented the whole design, so the Task 2 tests passed on their first run. To prove they bite, I ran two mutations: disabling strict TZID checks failed "refuses an unknown TZID", and adding description to the hash failed the quiet-field and key-order tests. Both mutations were reverted before commit.

**7. Requirements left Pending in REQUIREMENTS.md.** EXT-01 ("storing one source item per series UID"), EXT-07 ("stored in UTC") and PIPE-01 ("skipped and counted") are finished only when 03-05/03-09 store items and count skips. `requirements-completed` lists the plan's IDs per the template contract, for the extraction and classification slice delivered here.

---

**Total deviations:** 7 recorded (no scope change)
**Impact on plan:** None. Every must_have truth is covered by a test.

## Known Stubs
None. `priceType = Unknown` and `familyFriendly = false` are by design: ICS has no such properties (RESEARCH Pattern 4). They stay in the hash so Phase 4 extractors share it.

## Issues Encountered
- My first draft used the PHP 8.5 pipe operator. The Composer platform is pinned to 8.4.12, so I replaced it before running anything.
- PHPStan level 6 flags sabre `Component` parameters as un-typed iterables. I fixed this with `VEvent<mixed>` / `Component<mixed>` docblocks, without ignores.

## User Setup Required
None.

## Next Phase Readiness
- 03-05 can call `IcsExtractor::extract()` and treat `failed()` as a parse_error run. It can count `WindowClassifier` classes into skipped_past / skipped_far_future and items with `facts === null` into skipped_parse_error.
- 03-09 can store `toPayload()` and use `contentHashFromPayload()` for baseline moves.
- Full Unit+Feature suite: 1260 passed. PHPStan: 0 errors. Pint: clean. Both new test files pass under `TZ=America/New_York`.

## Self-Check: PASSED
