---
phase: 01-foundation-published-calendar
plan: 01
subsystem: foundation
status: complete
tags: [laravel-13, filament-5, postgresql-18, pest-5, larastan, pint, github-actions, laravel-boost, timezones]

requires: []
provides:
  - Laravel 13 app scaffold at repo root (GSD AGENTS.md / .gitignore / copilot-instructions preserved)
  - PostgreSQL schema: categories, venues, events (local schedule), event_occurrences (UTC, UNIQUE event_id+recurrence_key, partial public index), pg_trgm
  - App\Publishing\Actions\PublishEvent (single creator of Event rows, one transaction with occurrence sync)
  - App\Calendar\EventTiming / OccurrenceTiming / OccurrenceGenerator (upsert preserving status) / PublicOccurrenceQuery
  - Filament admin panel /admin (single admin via ADMIN_EMAIL), EventResource with List + Create only
  - Public GET / (route `calendar`) date-grouped Blade list with empty state and "More events" pagination
  - CI workflow on postgres:18 (pint --test, phpstan level 6, pest --ci, TZ=America/New_York)
  - Laravel Boost dev-only with project guidelines (.ai/guidelines/attleborough.md)
affects: [01-02 recurrence, 01-03 deploy, 01-04 edit/status actions, 01-05 filters, 01-06 event page, 01-07 frontend/Tailwind, phase-2 approval]

actuals:
  tokens: 193734   # chars/4 over every file changed (774,938 chars). Of that, composer.lock is 482,078 chars and Boost-generated skills are 132,528; hand-authored files are about 40,083 tokens.
  tasks: 3
  commits: 4
plan_head_before: e8f1c64d0e40ce50772d2bc4710b51d00391e755
plan_head_after: 8be71c04fcd2b12079de51c640a330f647561d41

tech-stack:
  added:
    - laravel/framework ^13.17 (skeleton laravel/laravel 13)
    - filament/filament ^5.10
    - sabre/vobject ^5.0
    - spatie/schema-org ^5.0.2
    - pestphp/pest ^5.3 + pest-plugin-laravel + pest-plugin-livewire (dev)
    - larastan/larastan ^3.13 (dev)
    - deployer/deployer ^8.0 (dev)
    - laravel/boost ^2.10 (dev only)
  patterns:
    - "Local wall-clock schedule on events; UTC instants on event_occurrences"
    - "Single write choke point: App\\Publishing\\Actions\\PublishEvent wraps Event::create + OccurrenceGenerator::sync in DB::transaction"
    - "Occurrence upsert on (event_id, recurrence_key) updating only starts_at/ends_at/expires_at, never status"
    - "All-day exclusive end = next local midnight after the last day; arithmetic on London-zoned CarbonImmutable then ->utc()"
    - "Public read model: published+cancelled events, scheduled+cancelled occurrences, expires_at > now strictly, order starts_at, id"

key-files:
  created:
    - app/Publishing/Actions/PublishEvent.php
    - app/Publishing/Data/PublishableEventData.php
    - app/Calendar/EventTiming.php
    - app/Calendar/OccurrenceTiming.php
    - app/Calendar/OccurrenceGenerator.php
    - app/Calendar/PublicOccurrenceQuery.php
    - app/Support/LondonFormatter.php
    - app/Http/Controllers/CalendarController.php
    - app/Enums/{EventStatus,OccurrenceStatus,EventOrigin,PriceType}.php
    - app/Models/{Category,Venue,Event,EventOccurrence}.php
    - app/Filament/Resources/Events/{EventResource.php,Pages/ListEvents.php,Pages/CreateEvent.php,Schemas/EventForm.php,Tables/EventsTable.php}
    - database/migrations/2026_10_16_0001{00,10,20,30,40}_*.php
    - database/seeders/CategorySeeder.php
    - database/factories/{Category,Venue,Event}Factory.php
    - config/site.php, config/calendar.php
    - resources/views/layouts/app.blade.php, resources/views/calendar/index.blade.php, resources/views/calendar/partials/occurrence-card.blade.php
    - tests/Feature/WalkingSkeletonTest.php, tests/Unit/Calendar/EventTimingTest.php, tests/Feature/Publishing/PublishEventTest.php, tests/Feature/Filament/CreateEventTest.php, tests/Feature/Public/CalendarListTest.php
    - .github/workflows/ci.yml, phpstan.neon, pint.json, boost.json, .ai/guidelines/attleborough.md, README.md
  modified:
    - AGENTS.md (Boost block appended after the intact GSD block)
    - .gitignore (skeleton entries merged; Filament published assets ignored)
    - app/Models/User.php (FilamentUser, canAccessPanel by ADMIN_EMAIL)
    - config/database.php (pgsql 'timezone' => 'UTC'), config/app.php (admin_email)
    - database/seeders/DatabaseSeeder.php (categories only)
    - phpunit.xml (pgsql attleborough_testing), .env.example, composer.json

key-decisions:
  - "A timed event whose end_date is after its start date but has no end_time runs to the end of its last day (exclusive next local midnight), not start + 3h"
  - "Filament's published assets (public/js|css|fonts/filament) are gitignored; filament:upgrade republishes them on every composer install"
  - "DatabaseSeeder seeds categories only. The skeleton test user (password 'password') was removed so seeding production can never create a login"
  - "Boost's Laravel Cloud integration and the deploying-to-cloud skill are disabled; the project guideline says we deploy to Hetzner via Deployer"
  - "Panel access compares email case-insensitively with config('app.admin_email') and is denied when ADMIN_EMAIL is empty"
  - "Card labels: timed 'Sat 24 Oct, 7:30pm' (':00' dropped); all-day 'All day'; multi-day '... until Sun 1 Nov'"

patterns-established:
  - "Tests: travelTo(CarbonImmutable::parse(..., 'Europe/London')) and raw `at time zone 'UTC'` reads for stored instants"
  - "Filament create pages delegate to publishing actions via handleRecordCreation; no edit/delete paths until UpdatePublishedEvent exists"

requirements-completed: [OPS-01, ADM-01, ADM-02, CAL-01, REC-02]

coverage:
  - id: D1
    description: "Admin creates a one-off event in Filament and it appears on / under its London date heading"
    requirement: CAL-01
    verification:
      - kind: integration
        ref: "tests/Feature/WalkingSkeletonTest.php#it lets the admin create a one-off event in Filament that then appears on the public list"
        status: pass
  - id: D2
    description: "Single admin: any other user gets 403 at /admin"
    requirement: ADM-01
    verification:
      - kind: integration
        ref: "tests/Feature/WalkingSkeletonTest.php#it forbids any user other than the configured admin from the panel"
        status: pass
  - id: D3
    description: "Every event is created by PublishEvent with origin, approver and approved_at; rollback on generation failure; no implicit dedupe"
    requirement: ADM-02
    verification:
      - kind: integration
        ref: "tests/Feature/Publishing/PublishEventTest.php"
        status: pass
  - id: D4
    description: "Expiry rules and UTC storage incl. DST fixtures 25 Oct 2026 and 28 Mar 2027, overnight, all-day and multi-day"
    requirement: REC-02
    verification:
      - kind: unit
        ref: "tests/Unit/Calendar/EventTimingTest.php"
        status: pass
      - kind: integration
        ref: "tests/Feature/Public/CalendarListTest.php"
        status: pass
  - id: D5
    description: "CI on PostgreSQL 18 running Pint, Larastan and Pest with TZ=America/New_York; Boost is require-dev only"
    requirement: OPS-01
    verification:
      - kind: other
        ref: "TZ=America/New_York vendor/bin/pest --ci && vendor/bin/pint --test && vendor/bin/phpstan analyse (local); .github/workflows/ci.yml"
        status: pass
      - kind: manual_procedural
        ref: "First GitHub Actions run of .github/workflows/ci.yml after push (not pushed in this plan)"
        status: unknown

duration: 23min
completed: 2026-10-16
---

# Phase 1 Plan 01: Walking Skeleton Summary

**A Laravel 13 / Filament 5 / PostgreSQL 18 app. The admin creates a one-off event in Filament, `PublishEvent` stores it with UTC occurrences in one transaction, and the event appears on a date-grouped public Blade list at `/`. CI runs Pint, Larastan and Pest on postgres:18, and Laravel Boost is installed as a dev-only aid.**

## Performance

- **Duration:** about 23 min
- **Started:** 2026-10-16T20:19:19Z
- **Completed:** 2026-10-16T20:42Z
- **Tasks:** 3 of 3
- **Files changed:** 135 (including 35 Boost skill files and composer.lock)

## Accomplishments

- Scaffolded Laravel 13 in a temp dir and copied it in selectively. AGENTS.md, `.github/copilot-instructions.md` and `.gitignore` all kept their GSD content.
- Pointed every environment at PostgreSQL (`phpunit.xml` uses `attleborough_testing`) and set the pgsql session time zone to UTC. Pinned Composer `platform.php` to 8.4.12 and raised the PHP floor to ^8.4.
- Added the schema: `pg_trgm`, categories, venues, events (local wall-clock schedule plus CHECK constraints), and `event_occurrences` (timestamptz, UNIQUE(event_id, recurrence_key), partial index WHERE status <> 'hidden').
- Built the write path: `PublishEvent` → `OccurrenceGenerator::sync` → `EventTiming`. It handles timed, no-end (+3h), overnight, all-day and multi-day events correctly across DST.
- Built the read path: `PublicOccurrenceQuery` (strict `expires_at > now`, stable ordering) → `CalendarController` grouping by the London date of max(start, today) → Blade list with Today/Tomorrow headings, `<time datetime>` and the empty state "Nothing on yet. Check back soon."
- Added the Filament panel with single-admin access, and EventResource (List + Create only). The full form has all-day, end date/time, price, family-friendly, organiser and source note, with validation.
- Added the CI workflow, Larastan level 6, Pint, Boost guidelines and skills, and a README with the local run sequence.

## Task Commits

1. **Task 1 (tracer): walking skeleton:** `fda1784` (feat)
2. **Task 2 (TDD): complete single-event entry**
   - RED: `f3fec08` (test)
   - GREEN: `d0f9f56` (feat)
3. **Task 3: CI + Larastan/Pint + Boost dev-only + README:** `8be71c0` (chore)

## TDD Gate Compliance

- **RED** (`f3fec08`): 26 tests ran; 9 failed and 17 passed. The classifier verdict was `RED_EVIDENCE_OK` (`target_test_failed`). The record is at `tdd-evidence/01-01-task2-red.json` and the JUnit report next to it.
  - **Target:** `CreateEventTest#it rejects an end time equal to the start time on the same day`. It failed on `assertHasFormErrors(['end_time'])` because the form had no end_time field or rule yet.
  - **Semantic assessment:** every failure was intended:
    - 5 all-day timing/publish/list tests threw "has no start time" because the all-day path didn't exist yet.
    - The end_date-before-start test failed because there was no rule.
    - The all-day form create failed because there was no all_day field.
  - No load, syntax or fixture faults. The 17 passing tests cover behaviour already delivered in Task 1.
- **GREEN** (`d0f9f56`): 26 of 26 pass; the full suite is 29 of 29.
- **REFACTOR:** not needed.

## Files Created/Modified

See the `key-files` frontmatter. The core files are `app/Publishing/Actions/PublishEvent.php`, `app/Calendar/*`, `app/Filament/Resources/Events/*`, `app/Http/Controllers/CalendarController.php`, the five migrations, the five test files and `.github/workflows/ci.yml`.

## Decisions Made

See `key-decisions` in the frontmatter. The most consequential one: a timed multi-day event with no end time ends at the next local midnight after its last day. The plan didn't specify this case, and the default 3h expiry would have dropped a two-day festival from the list after three hours.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Security] Removed the skeleton test user from DatabaseSeeder**
- **Found during:** Task 1
- **Issue:** The skeleton `DatabaseSeeder` creates `test@example.com` with the factory password `password`. `migrate --seed` in production would have created that login.
- **Fix:** DatabaseSeeder now only calls CategorySeeder.
- **Files modified:** database/seeders/DatabaseSeeder.php
- **Commit:** fda1784

**2. [Rule 3 - Blocking] Anchored the rsync excludes**
- **Found during:** Task 1
- **Issue:** The RESEARCH command `--exclude .gitignore` would also skip `storage/**/.gitignore` and `bootstrap/cache/.gitignore`, which Laravel needs.
- **Fix:** Used `/.gitignore`, `/AGENTS.md` and so on, so only root files are excluded.
- **Commit:** fda1784

**3. [Rule 2 - Correctness] Removed SQLite leftovers and skeleton noise**
- **Found during:** Task 1
- **Issue:** The `post-create-project-cmd` SQLite touch and migrate steps were still in composer.json (Pitfall 1). The welcome view and the example tests were also still there.
- **Fix:** Removed all of them. The PHP constraint is now ^8.4 to match the stack floor.
- **Commit:** fda1784

**4. [Rule 1 - Bug] Null-safe date construction in `EventTiming::endDate()`**
- **Found during:** Task 3, while probing Larastan
- **Issue:** `createFromFormat()` can return null, which would cause a fatal `addDays()` call.
- **Fix:** Reused the checked `localMidnight()` helper.
- **Commit:** 8be71c0

**5. [Rule 2 - Correctness] Disabled Boost's Laravel Cloud integration**
- **Found during:** Task 3
- **Issue:** Non-interactive `boost:install` enabled `cloud: true` and the `deploying-to-cloud` skill. Both point agents at the wrong deployment target.
- **Fix:**
  - Set `cloud: false` in boost.json and removed the skill.
  - Added a project guideline saying we deploy to Hetzner via Deployer, not Laravel Cloud, Forge or Vapor.
- **Commit:** 8be71c0

### Other adjustments

- **Rollback test without a mock:** `OccurrenceGenerator` is `final`, so Mockery can't mock it. The "generation throws → nothing persisted" test triggers a real throw instead: a timed event with no start time makes `EventTiming` throw inside the transaction. It still proves the single-transaction guarantee.
- **Boost agent selection:** I pre-seeded `boost.json` with `agents: ["copilot"]` before running `boost:install --guidelines --skills --mcp -n`. Without it, Boost would also configure every agent detected on the machine (Claude, Cursor, Codex and so on). `.github/copilot-instructions.md` was not changed by the installer, as RESEARCH predicted.
- **Filament assets:** published assets in `public/` are gitignored rather than committed (about 4.5 MB, regenerated by `filament:upgrade`).
- **Branch:** commits went to `main` as the orchestrator dispatch instructed (sequential mode, `branching_strategy: none`). The generic executor guard reports `main` as protected; I followed the explicit dispatch instruction instead of halting.
- **Extra tests beyond `<behavior>`:**
  - admin-allowed panel access
  - the 28 Mar 2027 all-day 23h fixture
  - overnight acceptance in the form
  - source_url required without organiser_contact
  - Today/Tomorrow headings and dropping ":00"
  - single all-day "All day" label

## Issues Encountered

- `laravel/pao` turns Pest, Pint and PHPStan output into JSON summaries. This is harmless, but expect JSON rather than the usual console output.
- I didn't run `npm install` (it's deferred to plan 01-07, as the plan says).

## User Setup Required

None for this plan. After the next push, the first GitHub Actions run of `CI` should go green. That hasn't been verified yet because this plan doesn't push.

## Next Phase Readiness

- Plan 01-02 (recurrence) can plug in through `OccurrenceGenerator::instances()`. That method currently throws `LogicException` for any non-null `recurrence_rule`, and the DTO always writes null for now.
- Plan 01-04 should add the Edit page through `UpdatePublishedEvent`. The resource deliberately has no edit/delete pages or actions yet.
- The `x-release` meta in the layout is ready for plan 01-03's symlink-release check.

## Self-Check: PASSED

- FOUND: app/Publishing/Actions/PublishEvent.php, app/Calendar/EventTiming.php, app/Calendar/OccurrenceGenerator.php, app/Calendar/PublicOccurrenceQuery.php, app/Filament/Resources/Events/Pages/CreateEvent.php, database/migrations/2026_10_16_000140_create_event_occurrences_table.php, .github/workflows/ci.yml, boost.json, .ai/guidelines/attleborough.md, README.md
- FOUND commits: fda1784, f3fec08, d0f9f56, 8be71c0
- Verification: pest 29/29 on PostgreSQL (also under TZ=America/New_York), `pint --test` passed, phpstan level 6 reported 0 errors. `migrate:fresh --seed` gives 10 categories and 0 events. Boost appears only in require-dev. AGENTS.md and copilot-instructions.md both still contain the GSD block.
