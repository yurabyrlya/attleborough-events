---
phase: quick-261017-hp9
plan: 01
subsystem: public-site
status: complete
tags: [ui-review, error-pages, filters, event-page, accessibility, blade, tailwind]
requires: [CalendarFilters, layouts.app look, 261017-gac More filters disclosure]
provides: [errors.layout, errors::404, errors::500, errors::503, remove-chip partial, CalendarFilters::attributeFilterCount]
affects: [public calendar filter chips, event page dates list, browser UAT F1/F2/F7/F9]
tech-stack:
  added: []
  patterns:
    - "Static-safe error layout: no queries, route(), stacks or scripts; Vite CSS guarded by manifest/hot file check"
    - "Active secondary filters rendered as plain-link 'Remove filter:' chips outside an always-closed <details>"
key-files:
  created:
    - resources/views/errors/layout.blade.php
    - resources/views/errors/404.blade.php
    - resources/views/errors/500.blade.php
    - resources/views/errors/503.blade.php
    - resources/views/calendar/partials/remove-chip.blade.php
    - tests/Feature/Public/ErrorPagesTest.php
  modified:
    - app/Calendar/CalendarFilters.php
    - resources/views/calendar/partials/filter-chips.blade.php
    - resources/views/events/partials/dates.blade.php
    - tests/Feature/Public/PublicLookTest.php
    - tests/Feature/Public/EventCalendarAndShareTest.php
    - tests/Browser/PublicCalendarTest.php
    - tests/Browser/EventPageTest.php
    - docs/uat/browser-uat.md
decisions:
  - "Error pages use their own static-safe errors.layout (a sibling of layouts.app, which stays untouched) with an inlined wordmark linking to '/', so 500/503 render with the DB, routes or Vite manifest broken"
  - "More filters <details> is always closed at every width; active Category/Show only filters show as 'Remove filter: X' chips under 'Filtering by' outside it, and the summary shows 'More filters (N)'"
  - "Event-page add links are labelled 'Apple / Outlook (.ics)' and 'Google Calendar' on an indented row under each date"
metrics:
  duration: 8m
  completed: 2026-10-17
  tasks: 3
  files: 14
actuals:
  tokens: 9450
  tasks: 3
  commits: 3
plan_head_before: f95623fadb997dae236f7b997abc960606488ab3
plan_head_after: 1035d921bc2d836ea30da811ee72ca0543d7e659
---

# Quick 261017-hp9: Fix top 3 UI-review findings Summary

Branded, static-safe 404/500/503 pages; filtered calendar views that keep More filters closed and show "Filtering by" remove-chips with a "More filters (N)" count; and event-page dates on their own line with an indented "Apple / Outlook (.ics)" / "Google Calendar" row.

## Tasks

| # | Task | Commit | Key files |
|---|------|--------|-----------|
| 1 | Tracer: branded static-safe error pages | 45062d8 | resources/views/errors/*, tests/Feature/Public/ErrorPagesTest.php |
| 2 | More filters closed + remove chips + count | 4597573 | CalendarFilters.php, filter-chips / remove-chip partials, PublicLookTest, Browser/PublicCalendarTest |
| 3 | Event-page date rows + UAT runbook | 1035d92 | events/partials/dates.blade.php, EventCalendarAndShareTest, Browser/EventPageTest, docs/uat/browser-uat.md |

## What changed

- **Error pages (fix 1):** `errors/layout` has the same shell as `layouts.app` (en-GB, skip link, `main#main`, footer, `noindex`) but no `route()`, no `@stack`, no scripts and no DB. `@vite` runs only when `public/build/manifest.json` or `public/hot` exists. Copy: 404 "We can't find that page", 500 "Sorry, something went wrong on our side", 503 "We're updating the calendar", each with a `tap-target` "See what's on" link to `/`. Tests cover an unknown slug, a hidden event, an unknown category (value not echoed), 0 queries with no exception text for 500/503, and a missing manifest with Vite turned on. Checked live: `curl /events/no-such-thing` on the dev server returns 404 with the branded page.
- **Filters (fix 2):** `CalendarFilters::attributeFilterCount()` is now what `hasAttributeFilters()` uses. `<details>` no longer opens when a filter is active. A `role=group` "Filtering by" row holds one `remove-chip` per active filter (category, Free, Family-friendly). Each chip's href drops only that filter via `queryWith`, built with `route(..., absolute: false)`. Its accessible name is "Remove filter: X" and its cross icon is decorative.
- **Dates (fix 3):** each `<li>` holds the `<time>` and then an indented `div.pl-4` row with the two links, or "Cancelled" as text. The existing cancelled-date regex test still passes.

## Deviations from Plan

1. **[Plan correction from orchestrator] tests/Browser updated and committed.** I updated `PublicCalendarTest.php` F2: it reopens the summary through the `nav[...] summary` selector before each pick, asserts "More filters (1)", "(2)" and "(3)", and uses the "Remove filter: Family-friendly" chip. I also added a new 375×812 above-the-fold check for `/?category=community` and `/?free=1`. In `EventPageTest.php` F9 I changed the link name to "Apple / Outlook (.ics) for …". Both were committed with the task that changed the behaviour. The runbook no longer has any handoff wording.
2. **[Rule 1 - test fix] Scoped the existing "keys chip styles to aria-current" test** in PublicLookTest so it skips the new remove chips. They are not toggles and are tested separately.
3. **Small structure change:** the h1 and the "See what's on" link sit in `errors/layout`, filled through `@section('heading')`, so they are not repeated in each code view. The rendered output is the same as the plan describes.
4. **Branch:** commits are on `main`. The GSD protected-branch check flags `main`, but the project uses `branching_strategy: none`, earlier quick tasks (for example 25265bc) were committed on main, and the orchestrator told me to commit here and will push itself. Nothing was pushed.

## Verification

- `./vendor/bin/pest --testsuite=Unit,Feature`: 522 passed (3210 assertions). It shows 1 warning at suite level with no details, which is unrelated to this task.
- `./vendor/bin/pest --testsuite=Browser tests/Browser/EventPageTest.php tests/Browser/PublicCalendarTest.php`: 7 passed (123 assertions). Playwright is available here.
- `vendor/bin/pint --dirty --format agent`: passed. `vendor/bin/phpstan analyse`: 0 errors. `npm run build`: succeeded.

## Deferred Issues

- `tests/Browser/SystemHealthTest.php` "F10: shows stale workers in words" hit a Playwright `Timeout 5000ms exceeded` when I ran it out of curiosity. It covers the Filament admin, which this task didn't touch. I didn't investigate it, so it may be flaky.
- I did not run `php artisan down --render="errors::503"` against the owner's dev server, because that would take the site down. 503 rendering is covered by ErrorPagesTest.

## Threat Flags

None. The surface is only fixed copy plus resolved, escaped labels with root-relative hrefs, which matches T-hp9-01..04.

## Self-Check: PASSED

- FOUND: resources/views/errors/{layout,404,500,503}.blade.php, resources/views/calendar/partials/remove-chip.blade.php, tests/Feature/Public/ErrorPagesTest.php
- FOUND commits: 45062d8, 4597573, 1035d92 (ancestors of HEAD)
