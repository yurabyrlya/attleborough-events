---
phase: 01-foundation-published-calendar
plan: 08
subsystem: event-page
status: complete
tags: [event-page, slugs, slug-history, 301, ics, sabre-vobject, open-graph, seo, grapheme, evt-01, evt-02, evt-06]
requires:
  - phase: 01-foundation-published-calendar
    provides: "01-01 events/venues/event_occurrences schema, PublishEvent, PublishableEventData slug candidate, config site.domain/app.url, sabre/vobject 5; 01-04 UpdatePublishedEvent, OccurrenceGenerator upsert keeping recurrence_key; 01-05 EventStatus/OccurrenceStatus publiclyListed(), ChangeEventStatus/ChangeOccurrenceStatus; 01-06 OccurrenceCardData, LondonFormatter::cardTime, head stack; 01-07 og stack, og-default.png, tokens/tap-target, x-category-icon, assertLink()"
provides:
  - "Migration 2026_10_17_000100_make_event_slugs_unique: deterministic backfill in id order, UNIQUE(events.slug), CHECK events_slug_format"
  - "Migration 2026_10_17_000200_create_event_slugs_table: event_slugs (event_id FK cascade, slug varchar(100) UNIQUE + CHECK event_slugs_slug_format, created_at)"
  - "App\\Models\\EventSlug; Event::slugHistory()"
  - "App\\Publishing\\EventSlugger::assign(Event): the only slug writer (unique, <=80 + suffix, reserved words -> '-event', keeps current slug while still a candidate, history + rename-back), called by PublishEvent and UpdatePublishedEvent inside their transactions"
  - "Public URL contract: GET /events/{slug} (events.show), GET /events/{slug}/{Y-m-d}.ics (events.ics); old/uppercase slug -> one 301 to the current slug; hidden -> 404"
  - "UID contract: App\\Calendar\\Export\\OccurrenceUid::for() = {event_id}-{recurrence_key}@site.domain (series) / {event_id}@site.domain (one-off)"
  - "App\\Calendar\\Export\\OccurrenceIcs::for() (sabre VCalendar, UTC or VALUE=DATE, SEQUENCE from last-modified, STATUS) and ::isCancelled()"
  - "PublicOccurrenceQuery::upcomingForEvent() / findListedOccurrence(); App\\Calendar\\UpcomingDates; config calendar.event_page_dates = 10"
  - "App\\Support\\PublicUrl, SafeLinks (http/mailto/tel/contactHref), TextLimit::graphemes(), EventPageData; OccurrenceCardData::$eventPath (+ public priceLabel()); LondonFormatter::shortDate()"
  - "Views events/show (head: canonical + og stack; body: facts, dates, Where, About, Who's running it) and events/partials/dates; card title links to the event page"
  - "Layout: <meta name=description> now yields an optional 'description' section (default config('site.tagline'))"
  - "tests/Pest.php cardTitle() helper (card title text, badge excluded, link-tolerant)"
affects: [01-10 (JSON-LD, Google Calendar link, share block on events/show; extends EventPageData date rows and the dates partial), 05-02 (production event-page check), phase-3 (dedupe merges can move slugs via event_slugs), phase-5 feeds (must reuse OccurrenceUid), phase-5 EVT-05 expired policy]
tech-stack:
  added: []
  patterns:
    - "Single slug writer with history table; 'taken' = another event's current slug OR another event's history"
    - "Resolver checks isPubliclyListed() before any redirect; redirect target built from the DB slug + config('app.url'), query string dropped"
    - "All absolute public URLs via PublicUrl (config('app.url')); in-site links root-relative"
    - "ICS built only through sabre createComponent/add; never hand-built lines"
    - "Grapheme-cluster text limits (intl) for meta/OG and ICS"
key-files:
  created:
    - database/migrations/2026_10_17_000100_make_event_slugs_unique.php
    - database/migrations/2026_10_17_000200_create_event_slugs_table.php
    - app/Models/EventSlug.php
    - app/Publishing/EventSlugger.php
    - app/Calendar/UpcomingDates.php
    - app/Calendar/Export/OccurrenceUid.php
    - app/Calendar/Export/OccurrenceIcs.php
    - app/Http/Controllers/EventController.php
    - app/Http/Controllers/OccurrenceIcsController.php
    - app/Support/PublicUrl.php
    - app/Support/SafeLinks.php
    - app/Support/TextLimit.php
    - app/Support/EventPageData.php
    - resources/views/events/show.blade.php
    - resources/views/events/partials/dates.blade.php
    - tests/Feature/Public/EventPageTracerTest.php
    - tests/Feature/Public/EventPageContentTest.php
    - tests/Feature/Public/EventSeoTest.php
    - tests/Feature/Public/EventSlugRedirectTest.php
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-08-task2-red{.json,-junit.xml}
  modified:
    - app/Models/Event.php
    - app/Publishing/Actions/PublishEvent.php
    - app/Publishing/Actions/UpdatePublishedEvent.php
    - app/Calendar/PublicOccurrenceQuery.php
    - app/Support/OccurrenceCardData.php
    - app/Support/LondonFormatter.php
    - config/calendar.php
    - routes/web.php
    - resources/views/calendar/partials/occurrence-card.blade.php
    - resources/views/layouts/app.blade.php
    - database/factories/EventFactory.php
    - tests/Pest.php
    - tests/Feature/Public/AttributeFiltersTest.php
    - tests/Feature/Public/DateFilterTest.php
    - tests/Feature/Public/StatusVisibilityTest.php
key-decisions:
  - "EventSlugger keeps an event's current slug while it is still one of its title's candidates (base or base-N) and free, so an unrelated edit never moves a URL (e.g. quiz-night-2 stays quiz-night-2 after the other quiz is renamed)"
  - "The layout's meta description became @yield('description', config('site.tagline')) instead of pushing a second <meta name=description> into the head stack (two description tags would be an SEO defect)"
  - "The .ics DESCRIPTION keeps line breaks: TextLimit::graphemes(..., collapse: false) cuts at 1000 grapheme clusters without collapsing whitespace"
  - "Cards keep their mailto-only contact rule through SafeLinks::mailto(); only the event page adds tel: links"
  - "Old-slug .ics URLs 301 to the current slug's .ics URL for the same date (same resolver as the page)"
patterns-established:
  - "cardTitle(): card h3 title text with the Cancelled badge removed, tolerant of the title link"
  - "Event-page tests publish through PublishEvent so slugs come from EventSlugger; factory events get a random slug suffix"
requirements-completed: [EVT-01, EVT-02, EVT-06]
coverage:
  - id: D1
    description: "Tracer: card on / links to /events/{slug}; page lists dates; .ics 200 text/calendar with UID/UTC/STATUS, parses and validates; series UIDs keyed by date across the clock change; UID stable through edit + re-sync with SEQUENCE not lower; -2 suffix; emoji title -> event; hidden event/date 404; cancelled date STATUS:CANCELLED with no add link; 404s; migration backfill + unique violation"
    requirement: EVT-06
    verification:
      - kind: integration
        ref: "tests/Feature/Public/EventPageTracerTest.php (11 cases)"
        status: pass
  - id: D2
    description: "EVT-01 content: venue/address, organiser, mailto/tel/plain contact, attribution or 'Listed by', no empty elements, escaped description with pre-line + wrap-anywhere, unlinked URLs, 10 dates + 'n more date(s) after these.', 'When' for one-off, 'No upcoming dates.' at 200, cancelled event sentence"
    requirement: EVT-01
    verification:
      - kind: integration
        ref: "tests/Feature/Public/EventPageContentTest.php (13 cases)"
        status: pass
  - id: D3
    description: "EVT-02 head: title with next date and suffix, Cancelled: prefix, meta description, tagline fallback, canonical ignoring tracking params with no noindex, OG override with one og:image, Host header never echoed (page + .ics), escape-once, 160-grapheme cut, ASCII transliteration"
    requirement: EVT-02
    verification:
      - kind: integration
        ref: "tests/Feature/Public/EventSeoTest.php (11 cases)"
        status: pass
  - id: D4
    description: "EVT-02 slugs: rename 301, one hop after several renames, rename back restores and clears history, no squatting on history, uppercase 301, query string dropped and Host ignored in Location, percent-encoded/over-long 404, old-slug .ics 301, hidden old slug 404"
    requirement: EVT-02
    verification:
      - kind: integration
        ref: "tests/Feature/Public/EventSlugRedirectTest.php (9 cases)"
        status: pass
  - id: D5
    description: "Local app: / still script-free and same-origin, unknown slug 404, card link 200, .ics headers, uppercase 301"
    requirement: EVT-02
    verification:
      - kind: other
        ref: "php -S 127.0.0.1:8010 (BOOST_BROWSER_LOGS_WATCHER=false): ops/check-public-assets.sh -> PUBLIC ASSETS OK: css=/build/assets/app-BCZjkOgj.css fonts=2 third-party=0; /events/no-such-event 404; /events/first-demo-event 200; .ics 200 text/calendar; /events/FIRST-DEMO-EVENT 301"
        status: pass
      - kind: manual_procedural
        ref: "GitHub Actions CI run on the pushed commit (orchestrator pushes and confirms)"
        status: unknown
actuals:
  tokens: 36739    # chars/4 over the realized diff 12147af..1a6f089 (146,955 chars). TDD evidence (JUnit XML embedded in red.json) is about 59,800 chars; app/views/migrations/tests are 87,138 chars (about 21,785 tokens).
  tasks: 2
  commits: 3       # MEASURED: git rev-list --count plan_head_before..plan_head_after
plan_head_before: 12147af7fd22b52e2309e9164d1a046a2b095f77
plan_head_after: 1a6f089ca8d6deb81f60260d80cb556f7f448912
duration: 15min
completed: 2026-10-16
---

# Phase 1 Plan 08: Event Page, Slugs and .ics Summary

**Every card on `/` now links to a readable `/events/{slug}` page. The page shows the dates, the venue with its address, the category, price and family tag, the full escaped description, the organiser contact (mailto:/tel:/plain) and attribution. Its head has the event title with the next date, a 160-grapheme meta description, a self canonical and its own Open Graph block, all from `config('app.url')`. Each date downloads as a sabre-built `.ics` whose UID (`{event_id}[-{recurrence_key}]@whatsonattleborough.co.uk`) survives edits and re-syncs. Slugs are unique, and old or uppercase slugs answer one 301 to the current slug. Hidden events and dates are 404 everywhere.**

## Performance

- **Duration:** about 15 min
- **Started:** 2026-10-16T23:03:36Z
- **Completed:** 2026-10-16T23:18Z
- **Tasks:** 2 of 2
- **Files changed:** 36 (15 app/view/migration files created, 10 modified, 4 test files created, 4 modified, 2 TDD evidence files, 1 factory)

## Accomplishments

- **Tracer (Task 1):** built the whole slice end to end:
  - UNIQUE + CHECK on `events.slug`, with a deterministic backfill that is safe when real events exist.
  - `EventSlugger` in both publishing actions.
  - Routes with slug/key constraints.
  - Controllers sharing one resolver.
  - `upcomingForEvent` / `findListedOccurrence` on the public query.
  - The view and the card title link.
  - `OccurrenceUid` + `OccurrenceIcs` on sabre/vobject.
  - Tracer gate: re-ran the automated verify (11/11, then the full suite 346/346), so I logged "⚡ Tracer verified end-to-end — expanding" and continued with no checkpoint (`human_verify_mode: end-of-phase`).
- **Full page and head (Task 2, TDD):**
  - Slug history in `event_slugs`: one-hop 301s, rename-back, and no reuse by another event.
  - `EventPageData` with every EVT-01 field, the blank→null rule, and dates with "{n} more date(s) after these."
  - `SafeLinks`, grapheme-safe `TextLimit`, and `LondonFormatter::shortDate`.
  - Event OG block replacing the 01-07 defaults.
  - Layout meta description slot.
- **Local check:** with the app served on port 8010, `/` still has no script and no third-party host, unknown slugs return 404, the demo event's card link returns 200, the `.ics` comes back with the right headers, and an uppercase slug answers 301.

## Task Commits

1. **Task 1 (tracer): card → event page → .ics with stable UID:** `7da7c51` (feat)
2. **Task 2 (TDD): full event page, head and slug history**
   - RED: `7b36ed0` (test)
   - GREEN: `1a6f089` (feat)

No refactor commit was needed.

## TDD Gate Compliance

- **RED** (`7b36ed0`): 33 tests ran; 25 failed on assertions, 0 errored and 8 passed. The classifier verdict was `RED_EVIDENCE_OK` (`target_test_failed`). The record is at `tdd-evidence/01-08-task2-red.json` with the JUnit report beside it.
  - **Target:** `EventSlugRedirectTest#it sends an old slug to the current one with a 301 after a rename`. It failed with "Expected response status code [301] but received 404" because there was no slug history yet.
  - **Semantic assessment:** every failure was intended: missing venue address, organiser, contact and attribution block, tel: links, "Listed by", date headings and "more dates" text, "No upcoming dates.", the cancelled sentence, the event title, meta description, canonical and OG, the grapheme cut, slug history, uppercase 301 and squatting protection.
  - The 8 passing tests guard properties the tracer already provides by construction: escaping, unlinked URLs, title with no date, the tagline default, Host never echoed, transliteration, route 404s and hidden 404.
  - A first run errored on a test typo (`RepeatEnds::Count`, really `AfterCount`). I fixed it before recording the evidence.
- **GREEN** (`1a6f089`): the three new files pass (33 cases), as do the tracer (11) and the full suite (379).

## Verification

- `./vendor/bin/pest`: 379 passed (baseline 335 + 44 new), 1 PHPUnit warning with no details. I did not establish whether it predates this plan; no test failed or was skipped.
- `vendor/bin/pint --test`: passed. `vendor/bin/phpstan analyse`: 0 errors.
- `npm run build` plus the CI "Built asset gates" steps run locally: manifest present, no deny-listed host, `url()` only `/build/` or `data:`, no maps, CSS 12,752 bytes. Passed.
- `php artisan route:list --path=events` lists `events.show` and `events.ics`.
- All acceptance greps hold:
  - no raw-echo directive
  - no asset/request-host helpers in PublicUrl, EventPageData, Export or events views
  - exactly one `@push('og')` and one canonical
  - `grapheme_substr` used
  - `event_slugs` in EventSlugger
  - no case or clamp classes
  - the "Listed by" and "No upcoming dates." strings are present
  - `OccurrenceUid` code reads no slug, title or times
- Local app (`php -S 127.0.0.1:8010`, the 01-07 recipe with `BOOST_BROWSER_LOGS_WATCHER=false`, so the owner's port 8000 was left alone):
  - `ops/check-public-assets.sh` → `PUBLIC ASSETS OK: css=/build/assets/app-BCZjkOgj.css fonts=2 third-party=0`
  - `/events/no-such-event` → 404
  - `/events/first-demo-event` → 200, with title `First Demo Event – Sat 17 Oct | What's On Attleborough`, canonical `http://localhost:8010/events/first-demo-event` and 0 script elements
  - its `.ics` → 200 `text/calendar; charset=utf-8`, attachment, noindex, `UID:1@whatsonattleborough.co.uk`, all-day `VALUE=DATE`
  - an uppercase slug → 301
  - The server was stopped afterwards and port 8010 is free.
- **CI:** not pushed, per the orchestrator. The local equivalents above are green, and the orchestrator confirms the GitHub Actions run after pushing.

## Decisions Made

- **The current slug survives unrelated edits.** `EventSlugger` keeps the stored slug while it is still `base` or `base-N` for the title and nobody else holds it. Without this, editing "Quiz Night" (slug `quiz-night-2`) after the first quiz was renamed would silently move it to `quiz-night` and create needless 301s.
- **Layout meta description slot** (see Deviations, item 3).
- **Line breaks in the .ics:** `TextLimit::graphemes(..., collapse: false)` keeps the newlines in the DESCRIPTION. Meta and OG descriptions collapse whitespace.
- **Old-slug `.ics`** URLs 301 to the current slug's `.ics` for the same date.
- **Phone contacts:** cards keep the 01-06 mailto-only rule (`SafeLinks::mailto`); only the event page adds `tel:`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Card-title test helpers could not see a linked title**
- **Found during:** Task 1, full-suite run
- **Issue:** The helpers in AttributeFiltersTest, DateFilterTest and StatusVisibilityTest read the card title with `#<h3[^>]*>\s*(.*?)\s*<#`, which stops at the first tag. The new `<a>` inside the h3 made every title `''`, so 21 tests failed. The plan expected the 01-05/01-06 card tests to stay unchanged, but the planned markup makes that impossible.
- **Fix:** Added a shared `cardTitle()` helper to `tests/Pest.php`: the h3 text with the `<strong>` badge removed, tags stripped and entities decoded. The three helpers now call it. They make the same assertions as before. This follows the 01-07 precedent of `assertLink()`.
- **Files modified:** tests/Pest.php, tests/Feature/Public/{AttributeFiltersTest,DateFilterTest,StatusVisibilityTest}.php
- **Commit:** 7da7c51

**2. [Rule 3 - Blocking] The event factory produced colliding slugs once slugs were unique**
- **Found during:** Task 1
- **Issue:** `EventFactory` set `slug = Str::slug(random 3 words)`. With the new UNIQUE index, any repeat would fail tests at random.
- **Fix:** Added a random 8-character lowercase alphanumeric suffix. The result still matches the CHECK regex.
- **Files modified:** database/factories/EventFactory.php
- **Commit:** 7da7c51

**3. [Rule 1 - Bug] One edit to the layout for the meta description**
- **Found during:** Task 2
- **Issue:** The layout hard-coded `<meta name="description" content="{{ config('site.tagline') }}">`. Pushing a second description tag into the head stack, as the plan suggested, would have produced two conflicting meta descriptions on every event page.
- **Fix:** Changed the tag to `@yield('description', config('site.tagline'))`. Every other page renders exactly what it rendered before, and the BrandHeadTest/NoThirdPartyAssetsTest suites stay green. The plan's "does NOT touch the layout" list is breached by this single line.
- **Files modified:** resources/views/layouts/app.blade.php
- **Commit:** 1a6f089

**4. [Rule 3 - Blocking] Larastan could not see `add()` on the VEVENT**
- **Found during:** Task 1
- **Issue:** `VCalendar::add('VEVENT', …)` returns `Node`, so phpstan reported 9 `method.notFound` errors.
- **Fix:** Build the VEVENT with `createComponent('VEVENT', …)`, which returns `Component`, then call `$calendar->add($vevent)`. The output is unchanged.
- **Files modified:** app/Calendar/Export/OccurrenceIcs.php
- **Commit:** 7da7c51

### Process notes

- **Local dev database migrated early.** While preparing the RED run, I ran `php artisan migrate --env=testing --force`. There is no `.env.testing`, so this migrated the local dev database (`attleborough`) with both new migrations, as batch 4. The plan's verify step runs `php artisan migrate --force` on the local database anyway, and the backfill left the single demo event's slug `first-demo-event` unchanged.
- **The plan's verify said "push to main and watch CI".** I did not push or watch CI, per the orchestrator. The local equivalents are recorded above.
- **Commits on `main`.** `gsd-tools git.base-branch --is-protected main` returns `true`. I committed on `main` anyway because the orchestrator states `branching_strategy: none` and that committing on main is intended, as the earlier Phase 1 plans did.

## Known Stubs

None. The D-06 "placeholder" word in the card partial's comment refers to the decorative category icon from 01-07; it is not a stub.

## Threat Flags

None. The new public surface (`/events/{slug}`, `/events/{slug}/{key}.ics`, the 301 redirects) is the surface the plan's threat model covers:
- T-01-56: hidden events and dates leaking
- T-01-57: stored XSS
- T-01-58: link injection
- T-01-59: Host header in absolute URLs
- T-01-60: ICS property injection
- T-01-61: open redirect
- T-01-62: long or odd slugs and keys
- T-01-64: cancelled dates shown as on
- T-01-65: slug squatting

Each mitigation is tested.

## End-of-phase human checks (non-blocking)

- Open an event from a card on a phone (local dev server) and check that the page reads well at 320px with no sideways scrolling, and that a long description wraps.
- Tap "Add to calendar (.ics)" on iOS and Android and confirm the calendar app imports the right date and time (all-day as a date). Then re-download after editing the event and confirm the entry is updated, not duplicated.
- Rename an event in `/admin` and open the old link: it should land on the new URL.
- 01-10 adds JSON-LD, the Google Calendar link and sharing, plus the local phone UAT. The production checks are 05-02.

## Next Phase Readiness

Plan 01-10 can now extend `EventPageData` (date rows, share data), the `head` stack in `events/show` (JSON-LD), and `events/partials/dates` (the Google Calendar link beside each `.ics` link). It can reuse `PublicUrl`, `TextLimit` and `OccurrenceIcs::isCancelled()`. The Phase 5 feeds must build UIDs with `OccurrenceUid::for()`.

## Self-Check: PASSED

- All 20 created files listed in key-files exist on disk.
- Commits 7da7c51, 7b36ed0 and 1a6f089 are ancestors of HEAD; `git rev-list --count 12147af..1a6f089` = 3.
