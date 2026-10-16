---
phase: 01-foundation-published-calendar
plan: 06
subsystem: public-calendar
status: complete
tags: [filters, date-windows, dst, europe-london, blade, no-js, noindex, cal-07-card, xss]

requires:
  - phase: 01-foundation-published-calendar
    provides: "01-01 walking skeleton: PublicOccurrenceQuery base set, CalendarController grouping, LondonFormatter, layouts/app + calendar views, EventFactory, events.family_friendly column + Filament Toggle"
provides:
  - App\Enums\DateFilter (today, weekend, week) with label()
  - App\Calendar\DateWindow::for(DateFilter, CarbonImmutable) — half-open London window with startUtc()/endUtc()
  - App\Calendar\CalendarFilters (fromRequest, none, toQuery, queryWith, hasAttributeFilters, hasAny, summary)
  - App\Calendar\PublicOccurrenceQuery::paginate(CalendarFilters, CarbonImmutable) — overlap window + category/free/family AND constraints, appends() whitelisted params only
  - App\Support\OccurrenceCardData::fromOccurrence() card view model (excerpt, price label, safe source link, contact, single cancelled flag)
  - LondonFormatter::heading() appends the year outside the current London year
  - config calendar.card_description_chars = 140
  - Public URL contract /?when=today|weekend|week&category={slug}&free=1&family=1&page={n}; any query string → noindex, follow; canonical = config('app.url') + '/'
  - Layout extension point @stack('head')
  - Views calendar/partials/filter-chips, calendar/partials/empty-state; updated calendar/index and occurrence-card
  - Test helpers freezeLondon(), makeOccurrence(), cardsIn() in tests/Pest.php
affects: [01-05 status actions (card badge reads statuses), 01-07 brand look (restyles chips/cards/empty states), 01-08 event page (pushes head meta, links card title)]

tech-stack:
  added: []
  patterns:
    - "Date windows computed on London-zoned CarbonImmutable (addDay/next), bound to SQL as ->utc() instants"
    - "Overlap: starts_at < window end AND expires_at > max(now, window start)"
    - "Whitelisted filter state object drives every chip href and pagination append; unknown params never propagate"
    - "Card partial renders only a readonly view model; all output via escaped echo"

key-files:
  created:
    - app/Enums/DateFilter.php
    - app/Calendar/DateWindow.php
    - app/Calendar/CalendarFilters.php
    - app/Support/OccurrenceCardData.php
    - resources/views/calendar/partials/filter-chips.blade.php
    - resources/views/calendar/partials/empty-state.blade.php
    - tests/Feature/Public/CalendarFiltersTracerTest.php
    - tests/Unit/Calendar/DateWindowTest.php
    - tests/Feature/Public/DateFilterTest.php
    - tests/Feature/Public/AttributeFiltersTest.php
    - tests/Feature/Public/OccurrenceCardTest.php
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-06-task{2,3}-red{.json,-junit.xml}
  modified:
    - app/Calendar/PublicOccurrenceQuery.php
    - app/Http/Controllers/CalendarController.php
    - app/Support/LondonFormatter.php
    - config/calendar.php
    - resources/views/layouts/app.blade.php
    - resources/views/calendar/index.blade.php
    - resources/views/calendar/partials/occurrence-card.blade.php
    - tests/Pest.php

key-decisions:
  - "The card excerpt uses a small multibyte-safe truncation (code points, word boundary, '…') instead of Str::limit, because Str::limit's preserveWords path runs strip_tags and measures display width, which would mangle plain-text descriptions containing '<'"
  - "OccurrenceCardData is built inside the card partial from the $occurrence it already receives, so the index → partial interface that 01-05/01-08 build on is unchanged"
  - "Empty-state precedence: page > 1 → end-of-list; else attribute filters → 'No events match these filters'; else the date filter's message; else the 01-01 'Nothing on yet' text"
  - "Chips use a small inline <style> block for structural layout only (wrap, ≥24px targets); 01-07 replaces it with the brand look"

patterns-established:
  - "cardsIn(TestResponse) scopes assertions to <article> cards so chip labels (Free, Family-friendly, category names) never satisfy card assertions"
  - "TDD RED tests for views stay black-box through HTTP, so no class scaffolds are needed in RED commits"

requirements-completed: [CAL-01, CAL-02, CAL-03, CAL-04, CAL-05, CAL-06, CAL-07]

coverage:
  - id: D1
    description: "No-JS visitor taps This weekend on / and gets only weekend-overlapping occurrences on a noindex page with a config canonical"
    requirement: CAL-06
    verification:
      - kind: integration
        ref: "tests/Feature/Public/CalendarFiltersTracerTest.php"
        status: pass
  - id: D2
    description: "Today / This weekend / This week windows pinned in UTC across 25 Oct 2026 and 28 Mar 2027 (49h/73h/25h/47h/23h), also under TZ=America/New_York"
    requirement: CAL-02
    verification:
      - kind: unit
        ref: "tests/Unit/Calendar/DateWindowTest.php"
        status: pass
  - id: D3
    description: "Overlap adjacency, multi-day in every window under the right heading, cancelled kept until expiry, hidden never shown, ordering, pagination walk, year in headings, D-05 empty states"
    requirement: CAL-02
    verification:
      - kind: integration
        ref: "tests/Feature/Public/DateFilterTest.php"
        status: pass
  - id: D4
    description: "Category / Free / Family-friendly AND-combined with date; 404 for unknown or malformed slugs without lookup or echo; invalid/array params 200; unknown params noindexed and dropped from links; chip hrefs"
    requirement: CAL-03
    verification:
      - kind: integration
        ref: "tests/Feature/Public/AttributeFiltersTest.php"
        status: pass
  - id: D5
    description: "Full CAL-07 card: fields, price labels, http(s)-only source, mailto/plain contact, 140-char excerpt with UTF-8 safety, escaping, single Cancelled badge, no empty elements, time precision"
    requirement: CAL-07
    verification:
      - kind: integration
        ref: "tests/Feature/Public/OccurrenceCardTest.php"
        status: pass
  - id: D6
    description: "Manual: browse http://127.0.0.1:8000/ with JavaScript disabled, tap chips, view-source shows noindex on filtered URLs"
    requirement: CAL-06
    verification:
      - kind: manual_procedural
        ref: "Plan <verification> optional manual check — deferred to end-of-phase human verification (human_verify_mode: end-of-phase)"
        status: unknown

actuals:
  tokens: 63267    # chars/4 over the 24 files this plan changed (253,068 chars). TDD evidence (JUnit XML embedded in red.json) is 188,891 chars; app/config/views/tests are 64,177 chars (about 16,044 tokens).
  tasks: 3
  commits: 8       # MEASURED rev-list plan_head_before..plan_head_after. 5 are 01-06 commits; 3 (de5bfce, f718bf1, e9a3f2f) are phase-02 docs commits made concurrently by another session.
plan_head_before: c6c8ecf15e8d512b707a4b10b6b6640efb5a45ca
plan_head_after: d2e533867263fd179eb744ae9121d18d44c36590

duration: 11min
completed: 2026-10-16
---

# Phase 1 Plan 06: Public Calendar Filters and Full Card Summary

**The list at `/` is now a full calendar browser that works without JavaScript. Visitors can filter by Today, This weekend and This week, which are half-open Europe/London windows and stay correct across both clock changes; a multi-day event shows in every window it overlaps. They can also filter by category, Free and Family-friendly, and all filters combine with AND. Every filter is a plain anchor. Any URL with a query string is `noindex, follow`, with the canonical built from config. Bad params never cause a 500 or leak into links. Each card shows every CAL-07 field, with a single text "Cancelled" badge when it applies.**

## Performance

- **Duration:** about 11 min
- **Started:** 2026-10-16T21:12:20Z
- **Completed:** 2026-10-16T21:23Z
- **Tasks:** 3 of 3
- **Files changed:** 24 (4 app classes created and 3 modified, 1 config, 5 views, 6 test files, 4 TDD evidence files)

## Accomplishments

- **Tracer (Task 1):** wired "This weekend" through every read-side layer:
  - plain anchor → `CalendarFilters::fromRequest` → `PublicOccurrenceQuery::paginate(filters, now)` → `DateWindow::for` bound as UTC instants → grouped Blade list.
  - The page has the robots noindex meta and the config-based canonical in the new `@stack('head')`.
  - The "More events" link now uses `appends($filters->toQuery())` instead of `withQueryString()`.
- **Windows and headings (Task 2):**
  - The five RESEARCH DST fixtures are pinned as exact UTC strings.
  - Grouping uses the London date of max(starts_at, window start or today). An ongoing multi-day event shows under "Today", or under the Saturday heading in This weekend.
  - Headings outside the current year get the year ("Friday 1 January 2027").
  - D-05 empty states for each window, for attribute filters and for a page past the end. The 01-01 "Nothing on yet" text is kept.
  - A "Showing:" summary and the page title are built from resolved values only.
- **Attribute filters and card (Task 3):**
  - Category slugs are lowercased and checked against `^[a-z0-9-]{1,64}$` before a bound lookup; anything else is a 404 that never echoes the input.
  - free and family are active only when the value is exactly `1`. Array values are ignored.
  - Category and "Show only" chip groups, with toggle hrefs and `aria-current`.
  - `OccurrenceCardData` provides the 140-character excerpt, the price label, an http(s)-only source link labelled by host, a mailto or plain contact fallback, and a single cancelled flag.

## Task Commits

1. **Task 1 (tracer): This weekend chip end to end:** `0ae9d53` (feat)
2. **Task 2 (TDD): windows across DST, filter-aware headings, D-05 empty states**
   - RED: `9c0dead` (test)
   - GREEN: `3e94c16` (feat)
3. **Task 3 (TDD): category/free/family filters, safe params, full card**
   - RED: `61879df` (test)
   - GREEN: `d2e5338` (feat)

## TDD Gate Compliance

**Task 1 tracer gate.** The `<verify>` was automated and `human_verify_mode` is end-of-phase, so I re-ran it before expanding. It passed 13/13, including 01-01's `CalendarListTest`, so I continued with no checkpoint. I wrote the tracer test first: 4 of 6 failed on missing filtering, chips and meta, and the 2 no-form/no-script guards passed by construction.

**Task 2 RED** (`9c0dead`)
- 42 tests: 13 failed, 29 passed.
- Classifier verdict: `RED_EVIDENCE_OK` (`target_test_failed`). Record at `tdd-evidence/01-06-task2-red.json`, with the JUnit report beside it.
- **Target:** `DateFilterTest#it shows the Today empty state linking to the weekend chip`. It failed because only the 01-01 generic empty state existed.
- **Semantic assessment:** every failure was an assertion failure for missing behaviour:
  - the Saturday grouping clamp
  - the Cancelled badge
  - the year in headings
  - the D-05 empty states
  - the summary and page title
- The 29 passing tests cover behaviour Task 1 had already built in its final shape: DST fixtures, overlap edges, ordering, the pagination walk and hidden exclusion. They also include the 01-01 regression tests.

**Task 2 GREEN** (`3e94c16`): 48 of 48 pass, including the tracer.

**Task 3 RED** (`61879df`)
- 55 tests: 31 failed, 24 passed.
- Classifier verdict: `RED_EVIDENCE_OK`.
- **Target:** `OccurrenceCardTest#it shows every CAL-07 field on the card`. It failed because the description excerpt was missing.
- All 31 failures were `ExpectationFailedException`.
- The first run also had 2 `InvalidExpectationValue` errors: `toEndWith` was called on a null excerpt. Those tests now assert `toBeString()` first, so a missing excerpt shows up as an assertion failure. The recorded run has no errors.
- The 24 passing tests are either negative guards or behaviour already delivered in Tasks 1 and 2 (invalid params → absent, utm stripping, escaping, badge, ordering, time labels).

**Task 3 GREEN** (`d2e5338`): 55 of 55 pass.

**REFACTOR:** not needed for either task.

## Verification

- `./vendor/bin/pest`: 199 passed (688 assertions), up from a baseline of 103. Also 199/199 under `TZ=America/New_York`.
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse`: 0 errors.
- Acceptance greps all hold:
  - `DateWindow::for` appears in the query.
  - No `withQueryString` in the query; `appends(` is used instead.
  - `@stack('head')` appears exactly once.
  - No `wire:`, `@livewire`, `<script` or `<form` in the calendar or layout views.
  - No `addHours(24)`.
  - Both DST fixtures are present.
  - The D-05 strings are present.
  - `datetime=` is in the index.
  - `price_type` and `family_friendly` are in the query.
  - `abort(404)` is in CalendarFilters.
  - No `{!!` in the calendar views.
  - The http/https allow-list is present.
  - `card_description_chars` is in config.

## Decisions Made

See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Cancelled badge added to the card in Task 2**
- **Found during:** Task 2
- **Issue:** Task 2's `<behavior>` requires cancelled occurrences to show "Cancelled" in every window. The card partial was listed only under Task 3's files.
- **Fix:** Task 2 added a minimal text badge to `occurrence-card.blade.php`. Task 3 then rebuilt the card on `OccurrenceCardData->isCancelled`.
- **Files modified:** resources/views/calendar/partials/occurrence-card.blade.php
- **Commit:** 3e94c16

**2. [Rule 1 - Bug] Excerpt truncation without Str::limit**
- **Found during:** Task 3
- **Issue:** The plan said to use `Str::limit` with word preservation. Its preserveWords path calls `strip_tags` and measures `mb_strwidth`. That would silently delete text such as "under 5s <3" in plain-text descriptions, and it would count each emoji as 2.
- **Fix:** `OccurrenceCardData::excerpt()` does its own multibyte-safe cut: code points, word boundary, then '…'. The tests cover the 140 and 141 boundaries, whitespace collapsing and UTF-8 validity.
- **Files modified:** app/Support/OccurrenceCardData.php
- **Commit:** d2e5338

### Other adjustments

- **Small additions:**
  - `CalendarFilters::hasAny()`, used for the "Showing:" line and the page title.
  - A `cardsIn()` helper in `tests/Pest.php`.
  - An extra DateWindow fixture: a UTC instant that is still Friday in UTC but already Saturday in London.
- **Heading:** the h1 reads "What's on", as Task 2 step 5 specifies. 01-01 had "What's on in Attleborough", and no test asserted it.
- **Structural CSS:** an inline `<style>` in the chips partial makes chips wrap and keeps targets at least 24px. This is not a script, so the no-JS gate still holds. 01-07 replaces it.
- **Concurrent commits:** another session committed `de5bfce`, `f718bf1` and `e9a3f2f` (phase-02 docs and state) to main while this plan ran. They are counted in the measured `commits: 8`. This plan did not touch them.
- **Untouched files:**
  - I did not edit or stage `.planning/STATE.md`, `.planning/ROADMAP.md`, `.planning/config.json`, `.planning/state.json`, `.planning/milestone.lock` or `.planning/phases/02-*`.
  - I did not change any 01-04-owned file, model or `ci.yml`.
- **Branch:** commits went to `main`, as the dispatch instructed (sequential, `branching_strategy: none`).

## Issues Encountered

None blocking.

## Known Stubs

None. The card title is intentionally not linked yet; 01-08 adds the event page.

## Threat Flags

None. The new surface (query params, card links, canonical) is covered by T-01-23..T-01-28, and each mitigation has a test.

## User Setup Required

None. The optional manual no-JS browse check is left for end-of-phase human verification.

## Next Phase Readiness

- **Plan 01-05:** status actions only need to set `events.status` or `event_occurrences.status` to cancelled. The card badge and every filter already handle it.
- **Plan 01-07:** can restyle `filter-chips`, `occurrence-card` and `empty-state` without changing their semantics: anchors, `aria-current`, `<time datetime>` and text badges.
- **Plan 01-08:** can `@push('head')` event-page meta and link the card `<h3>` title to the event route.

## Self-Check: PASSED

- FOUND: app/Enums/DateFilter.php, app/Calendar/DateWindow.php, app/Calendar/CalendarFilters.php, app/Support/OccurrenceCardData.php, resources/views/calendar/partials/filter-chips.blade.php, resources/views/calendar/partials/empty-state.blade.php, and all 5 new test files plus the 4 TDD evidence files
- FOUND commits: 0ae9d53, 9c0dead, 3e94c16, 61879df, d2e5338 (all ancestors of HEAD)
