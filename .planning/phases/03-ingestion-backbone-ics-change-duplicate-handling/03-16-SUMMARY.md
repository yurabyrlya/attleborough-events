---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 16
subsystem: public-site
tags: [attribution, leg-04, pipe-09, seeder, blade, eager-loading, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-05 excerpt + null organiser fields for discovered drafts; 03-07 SourceHealth cast; 03-08 EventSource, LinkEventSource, Event::eventSources()/primarySource(); 03-15 SourceForm rules (validation the seeded sources must pass)"
provides:
  - "EventPageData attribution / alsoListedBy / readMore (item URL → events page → feed site home page, all via SafeLinks::http)"
  - "Event page: 'Listed by {source}' + 'Also listed by …' in Who's running it; 'Read more at {source}' straight after the description"
  - "OccurrenceCardData::via and the card line 'via {short name}' (name when short name is empty) replacing 'Source: {host}' for discovered events (C10)"
  - "Eager loading: event.primarySource.source (calendar list), eventSources.source (event page, both slug branches)"
  - "database/seeders/SourceSeeder.php: three inactive ICS sources (create-only, firstOrCreate on slug), called from DatabaseSeeder"
affects: [03-17 browser UAT (sources flow, public attribution), Phase 4 (extend SourceSeeder with non-ICS List 1 sources)]

actuals:
  tokens: 12200
  tasks: 3
  commits: 5
plan_head_before: a44e7c57ea9f0ca898d634cdf5667e0f7383e480
plan_head_after: bf112d4189d3ad072bde82ba01a409a8804a5cf3

tech-stack:
  added: []
  patterns:
    - "Attribution is rendered, never stored: view models build it from the eager-loaded event_sources links"
    - "Create-only reference seeder for sources (RulesSeeder pattern), with constructor-injected VenueMatcher for an optional default venue"
    - "Seeder values proven against the admin form by round-tripping each seeded record through EditSource::save"

key-files:
  created:
    - database/seeders/SourceSeeder.php
    - tests/Feature/Public/SourceAttributionTest.php
    - tests/Feature/Review/SourceSeederTest.php
  modified:
    - app/Support/EventPageData.php
    - app/Support/OccurrenceCardData.php
    - resources/views/events/show.blade.php
    - resources/views/calendar/partials/occurrence-card.blade.php
    - app/Calendar/PublicOccurrenceQuery.php
    - app/Http/Controllers/EventController.php
    - database/seeders/DatabaseSeeder.php
    - tests/Feature/Public/OccurrenceCardTest.php
    - tests/Feature/Public/EventPageContentTest.php

key-decisions:
  - "C10: with a source link, the event page drops 'More information: {host}' and the 'Listed by What's On Attleborough.' fallback; the card drops 'Source: {host}'. The card contact fallback is computed exactly as in Phase 2 (still hidden when the event has a source URL), so an admin-typed organiser contact behaves as before (D-17)"
  - "Card 'via {short name}' is plain text (D-14 asks for a short line); the linked attribution lives on the event page"
  - "'Read more at {source}' is one link whose text is the whole phrase (clear link purpose), using the existing tap-target utility; it renders only when a safe URL exists"
  - "Link fallback chain: item URL → source events page → https://{feed host}/ (never the .ics file), so every discovered event links its source (LEG-04)"
  - "If an event somehow has links but none primary, the oldest link is credited (defensive; LinkEventSource always sets a primary)"
  - "SourceSeeder: is_organiser_source true for the Baptist Church and the Academy (their own sites list their own events), false for the Town Council (it lists many groups' events), so D-25 credits the organiser when feeds overlap"
  - "SourceSeeder short names: 'Baptist Church', 'Town Council', 'Attleborough Academy' ('via Academy' alone would be ambiguous)"
  - "SourceSeeder defaults: check_frequency daily, check_time from the column default (05:30 London), timezone Europe/London, location local, year policy none; default category School for the Academy only (no obvious single category for the church or the council, so keyword rules decide); default venue only when VenueMatcher::exact finds a known venue named 'Attleborough Baptist Church' / 'Attleborough Academy', otherwise null (the dev DB has none, so all three are null)"
  - "Town Council title rules seeded exactly as planned (skip 'Private hire', skip 'Council meeting'); the notes mention research also saw 'Private Function' items so the admin can add it in the preview. No Baptist 'Sunday Service' skip rule (services are real public events)"

requirements-completed: [LEG-04]

duration: 12min
completed: 2026-10-18
---

# Phase 3 Plan 16: Public source attribution and SourceSeeder Summary

**Discovered events now say "Listed by {source}" (linked to the item's page, else the source's events page, else the feed site's home page), list "Also listed by …" in link order, and end the description with "Read more at {source}". Calendar cards say "via {short name}". Everything is eager-loaded with no N+1. A create-only SourceSeeder pre-loads the Baptist Church, Town Council and Academy ICS feeds as inactive sources, and the dev database is seeded with them.**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-10-18T11:00:59Z
- **Completed:** 2026-10-18T11:13Z
- **Tasks:** 3 (plus one Rule 2 fix commit)
- **Files modified:** 12

## Accomplishments

- Event page (LEG-04, D-12, D-14, D-16): "Listed by" plus "Also listed by" in the "Who's running it" section, "Read more at {source}" straight after the description, all escaped and with `rel="nofollow noopener"`. Hostile URLs (`javascript:`) are never linked.
- Calendar card: "via {short name}" replaces "Source: {host}" for discovered events. Non-discovered events keep the Phase 2 lines and the fallback (C10).
- No N+1: the calendar list runs the same number of queries for 10 discovered events as for 1 (tested with the query log).
- Privacy (D-17, T-03-43): a feed with `ORGANIZER;CN=Jo Bloggs`, an email and a UK phone number in the description leaves none of them on the event page or the card.
- SourceSeeder (D-05, SRC-01): three inactive, healthy ICS sources with the real feed URLs, events pages, legal basis and notes from `docs/research/event-sources.md` and the fixture manifests. Each one passes the Sources admin form's own validation unchanged (saved through `EditSource`), re-seeding is idempotent and keeps admin edits, a deleted seeded source comes back, and an activated one stays live.
- Dev database: ran the pending 03-09 migration (`add_cancelled_dates_to_events`), then `php artisan db:seed --class=SourceSeeder --no-interaction` against `attleborough`. **Count confirmed: 3 sources**, all inactive: attleborough-baptist-church, attleborough-town-council and attleborough-academy (default category School). They are now visible in /admin → Sources, waiting for the admin's test preview.

## Task Commits

1. **Task 1: Tracer, attribution on the event page and the card** - `de295d5` (feat). Tracer gate: verify re-run end-to-end, passed; Public suite 239/239.
2. **Task 2: Attribution rules (order, duplicate-only, takeover, empty, javascript:, privacy, C10 expectations)** - `40df6de` (test)
3. **Task 3: SourceSeeder, three inactive ICS sources, create-only** - `171051a` (feat)
4. **Rule 2 fix: feed-site home-page fallback so every discovered event links its source** - `bf112d4` (fix)

**Concurrent commit, not part of this plan:** `81d8b25 feat(sources): '?' helpers on every source form field` landed on main from another session between Task 3 and the fix. It is inside the measured `plan_head_before..plan_head_after` range, so `commits: 5` = 4 plan commits + 1 foreign. The seeder form round-trip test and the Sources form tests were re-run after it and pass (49/49).

## Files Created/Modified

- `app/Support/EventPageData.php`: `attribution`, `alsoListedBy`, `readMore`; source URL line suppressed when attributed
- `app/Support/OccurrenceCardData.php`: `via` from `event.primarySource.source`
- `resources/views/events/show.blade.php`: Listed by / Also listed by / Read more at; fallback only without attribution
- `resources/views/calendar/partials/occurrence-card.blade.php`: "via …" line
- `app/Calendar/PublicOccurrenceQuery.php`, `app/Http/Controllers/EventController.php`: eager loading
- `database/seeders/SourceSeeder.php`, `database/seeders/DatabaseSeeder.php`: D-05 seeder
- `tests/Feature/Public/SourceAttributionTest.php` (10 tests), `tests/Feature/Review/SourceSeederTest.php` (7 tests), C10 cases added to `OccurrenceCardTest.php` and `EventPageContentTest.php`

## Decisions Made

See `key-decisions` in the frontmatter. The main judgement calls were the organiser-site flags on the seeded sources, the short names, the feed-site home-page fallback and keeping the card "via" line as plain text.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Every discovered event must link its source (LEG-04)**
- **Found during:** Requirement check after Task 3
- **Issue:** The plan's chain (item URL → events page) leaves a source with no events page and an item with no URL as plain text, so LEG-04 ("with a link") would not hold for every discovered event (the form allows an empty events page).
- **Fix:** The chain now ends with `https://{feed host}/` (the feed file itself is never linked). The `javascript:` test now asserts the home-page link instead of plain text.
- **Files modified:** app/Support/EventPageData.php, tests/Feature/Public/SourceAttributionTest.php
- **Commit:** bf112d4

**2. [Rule 3 - Blocking] Dev DB had a pending migration**
- **Found during:** Owner-requested dev seeding
- **Issue:** `2026_10_18_100700_add_cancelled_dates_to_events` (03-09) had not run on the dev database `attleborough`.
- **Fix:** `php artisan migrate --no-interaction` before seeding. No code change.

### TDD note

Task 2's new tests passed on first run because Task 1's implementation already covered those rules. The RED step was not observed for Task 2, and the tests were checked for teeth: the ordering test reorders `linked_at` against id order, and the privacy test also asserts the cleaned excerpt is present. Task 1 and Task 3 went RED first (3 of 4 and 7 of 7 failing).

## Issues Encountered

- One full-suite run failed in `NoThirdPartyAssetsTest` with `relation "venues" does not exist`. This is the known flake from the shared `attleborough_testing` database. The re-run was green.

## Verification

- `pest --testsuite=Unit,Feature`: 1500/1500 passed at HEAD bf112d4 (final run; an earlier run hit the shared-DB flake and was re-run)
- `phpstan analyse --memory-limit=1G`: 0 errors
- `pint --dirty`: clean
- `npm run build`: done (Blade changed; only existing utilities used)
- Acceptance greps: `Listed by` appears 2+ times in show.blade.php; `primarySource` is in PublicOccurrenceQuery; `firstOrCreate` is in SourceSeeder; `SourceSeeder` appears once in DatabaseSeeder

## Next Phase Readiness / Notes for Phase 4

- **Phase 4 must extend `SourceSeeder` with the verified non-ICS List 1 sources** once their extractors exist: Attleborough Heritage Group (server-rendered HTML GridView), Attleborough Primary School (events embedded as a JS array, Crawl-delay 12000), the Baptist Church dated RSS fallback, the Town Council TEC REST adapter, and the P2/P3 List 1 sources (Visit Norfolk, Visit Wymondham JSON-LD, Summer Carnival, …). Keep the same create-only, inactive, form-valid rules. (Also added as a Phase 4 todo in STATE.md.)
- 03-17 browser UAT should cover the public attribution lines and opening a seeded source → Test run.

## Self-Check: PASSED

- FOUND: database/seeders/SourceSeeder.php, tests/Feature/Public/SourceAttributionTest.php, tests/Feature/Review/SourceSeederTest.php
- FOUND commits: de295d5, 40df6de, 171051a, bf112d4 (all ancestors of HEAD)
