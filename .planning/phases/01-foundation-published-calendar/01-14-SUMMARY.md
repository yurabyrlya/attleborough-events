---
phase: 01-foundation-published-calendar
plan: 14
subsystem: test-suite
status: complete
gap_closure: true
tags: [gap-closure, wr-02, test-fixtures, publish-dto, rollback, seo, event-page]
requires:
  - phase: 01-foundation-published-calendar
    provides: "01-04 PublishEvent (single transaction: save then OccurrenceGenerator::sync), 01-05 status fixtures, 01-08 event page / slug redirects, tests/Pest.php makeOccurrence() + EventFactory"
provides:
  - "PublishEventTest rollback test driven by a valid DTO; failure injected after save via an Event::saved listener; asserts the save ran"
  - "EventSeoTest tagline fallback proven on a stored whitespace-only description (query-builder update after a valid publish)"
  - "EventPageContentTest 'Listed by' fallback proven on an unattributed stored row (factory + makeOccurrence)"
  - "Every DTO fixture base in the suite carries an organiser contact or source URL, so 01-12's WR-02 invariants can be switched on without touching tests"
affects: [01-12 (starts from a suite that already satisfies its PublishableEventData invariants; the missing-start-time input case moves to its PublishableEventDataTest)]
actuals:
  tokens: 2200     # chars/4 over the realized diff gsd/01-14-base..HEAD under tests/ (8,642 chars incl. diff context)
  tasks: 2
  commits: 2       # MEASURED: git rev-list --count plan_head_before..plan_head_after
plan_head_before: 59893b8bc09275d4a976e19c700777b45f2532f5
plan_head_after: 50943f15bfc59355dc28cf66b26c1791ddb45a6a
tech-stack:
  added: []
  patterns:
    - "Prove a transaction rolls back by injecting the failure AFTER the write (Event::saved listener mutating the in-memory model), and assert the write happened, so the test cannot pass because input was refused up front"
    - "Page fallbacks for data the publish DTO refuses are tested on stored rows (query-builder update or factory + makeOccurrence), matching what importers/SQL can write"
key-files:
  created:
    - .planning/phases/01-foundation-published-calendar/01-14-SUMMARY.md
  modified:
    - tests/Feature/Publishing/PublishEventTest.php
    - tests/Feature/Public/EventSeoTest.php
    - tests/Feature/Public/EventPageContentTest.php
    - tests/Feature/Publishing/ChangeOccurrenceStatusTest.php
    - tests/Feature/Publishing/ChangeEventStatusTest.php
    - tests/Feature/Status/StatusSurvivesRegenerationTest.php
    - tests/Feature/Filament/StatusActionsTest.php
    - tests/Feature/Public/EventCalendarAndShareTest.php
    - tests/Feature/Public/EventPageTracerTest.php
    - tests/Feature/Public/EventJsonLdTest.php
    - tests/Feature/Public/EventSlugRedirectTest.php
decisions:
  - "01-14: PublishEvent rollback is proven with a valid DTO and an Event::saved listener that clears start_time after save (EventTiming throws inside the transaction); the 'timed event without start time' input case belongs to 01-12's PublishableEventDataTest"
  - "01-14: page fallbacks for input the DTO will refuse (blank description, no attribution) are tested on stored rows, not through PublishEvent"
metrics:
  duration: "~4 min"
  completed: 2026-10-17
requirements: [ADM-02, ADM-03, EVT-01, EVT-02]
---

# Phase 1 Plan 14: Suite alignment with the WR-02 publish-DTO invariants Summary

Three tests that relied on input 01-12's DTO will refuse now reach their starting state from valid or stored data with byte-identical assertions (plus one added "save ran" assertion), and all eight remaining unattributed DTO fixture bases gained `organiser@example.test`. Suite green on the current code: 411 passed, Pint clean, Larastan clean.

## Tasks

| # | Task | Commit | Files |
|---|------|--------|-------|
| 1 | Tracer: rebuild rollback, tagline and "Listed by" tests on valid/stored data | 62c46fb | PublishEventTest, EventSeoTest, EventPageContentTest |
| 2 | Organiser contact in every other DTO fixture base; full suite green | 50943f1 | 8 fixture files (below) |

The tracer gate after Task 1 re-ran `<verify>` end to end (31 passed, no assertion line removed), then the plan moved on to Task 2.

## The three rebuilt tests

**1. PublishEventTest: "persists nothing when occurrence generation throws"**
- Old setup: `publishableData(['startTime' => null, 'allDay' => false])`. A timed event with no start time made EventTiming throw inside the transaction.
- New setup: the default `publishableData()` (19:30, valid). An `Event::saved` listener sets a `$saved` flag by reference and sets `start_time = null` on the in-memory model without saving it. `OccurrenceGenerator::sync` uses the passed model, so EventTiming throws `InvalidArgumentException` ("has no start time") inside `PublishEvent`'s `DB::transaction`.
- Why the intent is unchanged: the same `toThrow(InvalidArgumentException::class)` and `Event::count() === 0` / `EventOccurrence::count() === 0` assertions run. The new `expect($saved)->toBeTrue()` shows the row was written and then rolled back, so the test can no longer pass just because the DTO refused its input. No final class was mocked or un-finalled. The missing-start-time input case moves to 01-12's `PublishableEventDataTest` (noted in the inline comment).

**2. EventSeoTest: "falls back to the site tagline when the description is empty"**
- Old setup: `seoPublish($this->base, ['title' => '!!!', 'description' => " \n "])`.
- New setup: `seoPublish($this->base, ['title' => '!!!'])` with a valid description, then `Event::query()->whereKey($event->id)->update(['description' => " \n "])`. The beforeEach base also gained `'organiserContact' => 'organiser@example.test'`.
- Why the intent is unchanged: the '!!!' title still goes through PublishEvent and the slugger's `event` fallback, and the page still renders a stored whitespace-only description. Same GET `/events/event` and the same two assertions: meta description is `[config('site.tagline')]` and the title is not blank.

**3. EventPageContentTest: "leaves no label or empty element for blank optional fields and falls back to 'Listed by'"**
- Old setup: `contentPublish($this->base, [organiserName/organiserContact/sourceUrl/sourceNote/priceText => null])`.
- New setup: `makeOccurrence([...])` with snake_case attributes that mirror the base: title 'Quiz Night', explicit slug 'quiz-night', the same description, the base category_id and venue_id, 2026-10-23 19:30–22:00, and all five optional fields null. Status, origin and approval come from the factory defaults (published/manual/approved). The occurrence runs 2026-10-23 19:30 → 22:00 London.
- Why the intent is unchanged: the page sees the same unattributed published event. The same GET `/events/quiz-night`, the five text assertions and the empty p/li/dd/dt loop all run unchanged. The file's base and `contentPublish` helper were not touched.

## Fixture files given an organiser contact (Task 2)

- Constructor bases, `organiserContact` (one base each): ChangeOccurrenceStatusTest and ChangeEventStatusTest (named argument); StatusActionsTest, EventCalendarAndShareTest, EventPageTracerTest, EventJsonLdTest and EventSlugRedirectTest (spread arrays).
- fromForm bases, `organiser_contact`: StatusSurvivesRegenerationTest, in both literal arrays (beforeEach series and the "filters the events list by status" `$publish` closure). The `$this->edit` closure rebuilds from `fromEvent()` of the stored row, so it now carries the contact as well.

`git diff --stat gsd/01-14-base..HEAD -- tests/Feature` lists exactly the 11 files in files_modified (44 insertions, 10 deletions). No line removed since the base contains `expect(`, a `->toX` matcher, `assert` or `it(`.

## Suite audit (input 01-12 will refuse)

I read every test file that builds `PublishableEventData`, calls `fromForm` or uses `fillForm` (24 files under tests/), looking for blank titles or descriptions, impossible dates, malformed times, a timed event without a start time, an end date before the start date, a last date before the start date, and missing source plus missing contact. **Nothing new was found outside this plan's files.**
- Every DTO base already carries `organiserContact`/`organiser_contact` or a source URL.
- Null start times: all of them pass `allDay` true (PublishEventTest Sat–Sun case, RecurringOccurrencesTest, EventCalendarAndShareTest, CalendarListTest, EventJsonLdTest, CreateEventTest all-day case).
- `CreateEventTest` "requires a source URL when there is no organiser contact", "rejects an end date before the start date" and `CreateRecurringEventTest` "rejects an end date for the series before its start date" are Filament form-validation tests. They assert form errors and never reach the DTO.
- RecurringOccurrencesTest "refuses a repeating event that spans several days" keeps its existing message, because its end date (2026-10-24) is after the start date (2026-10-23). The "end date equal to start date" normalisation case is valid.

## Verification

- `./vendor/bin/pest`: **411 passed, 2488 assertions** (baseline 411 / 2485: +1 is the new "save ran" assertion; the other +2 come from per-element assertion loops that now iterate over slightly different page content (e.g. a rendered contact). No assertion was removed). The suite still prints the single PHPUnit warning without details, as recorded in 01-08/01-10/01-11 (pre-existing).
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: 0 errors.
- Task 2 verify command 1 (DTO key spelling, per-file contact counts, assertion diff check): OK.

## Deviations from Plan

None. The plan was executed exactly as written. The Host-spoof test idioms listed in deferred-items.md (some in files touched here) were left alone, as instructed.

## Known Stubs

None.

## Self-Check: PASSED

- FOUND: all 11 modified test files; this SUMMARY
- FOUND: 62c46fb, 50943f1 (ancestors of HEAD)
- Local tag `gsd/01-14-base` → 59893b8 (not pushed)
