---
phase: 01-foundation-published-calendar
plan: 10
subsystem: event-page
status: complete
tags: [event-page, json-ld, schema-org, spatie-schema-org, google-calendar, web-share, share, progressive-enhancement, vite, evt-03, evt-06, evt-07]
requires:
  - phase: 01-foundation-published-calendar
    provides: "01-08 EventPageData, PublicUrl, TextLimit, OccurrenceIcs (isCancelled, LOCATION rule, 'Finish time not given.'), PublicOccurrenceQuery::upcomingForEvent (max 10), events/show head stack, events/partials/dates; 01-07 vite.config.js, fixture manifest + withVite pattern, deny-list, ops/check-public-assets.sh, og-default.png, tap-target; 01-05 ChangeEventStatus/ChangeOccurrenceStatus; 01-01 spatie/schema-org 5.0.2"
provides:
  - "App\\Seo\\EventJsonLd::scripts(Event, Collection): HtmlString, one Schema.org Event script per listed upcoming date (max 10), eventStatus EventCancelled/EventScheduled, London offsets, inclusive all-day endDate, GBP 0 Offer for free events"
  - "App\\Calendar\\Export\\GoogleCalendarLink::for(EventOccurrence): Google TEMPLATE URL (RFC 3986; UTC or all-day exclusive end; details + canonical; ICS LOCATION line)"
  - "App\\Support\\ShareLinks: share text '{Cancelled: }{title} – {dated time} at {venue}, on What's On Attleborough' and wa.me / sharer.php / mailto URLs"
  - "EventPageData gains per-date googleUrl, jsonLd (HtmlString) and share"
  - "LondonFormatter::datedTime(): cardTime() that always names the day ('Sat 7 Nov, all day')"
  - "View events/partials/share (no-JS fallbacks, link box, hidden Share…/Copy link, role=status region)"
  - "resources/js/share.js: first-party ES module (1,026 bytes built), second Vite input, loaded only by events/show"
  - "ops/check-event-page.sh [BASE_URL] -> 'EVENT PAGE OK: path=… jsonld=… uid=…' (exit 1 on a failed check, 3 when / links no event)"
affects: [05-01 (Caddy Permissions-Policy must include web-share=(self)), 05-02 (production run of ops/check-event-page.sh, real-phone share/preview UAT, Rich Results Test), phase-5 feeds (reuse OccurrenceUid; the Google link is per request only)]
actuals:
  tokens: 18364    # chars/4 over the realized diff 3860fe7..53b9d86 (73,457 chars). TDD evidence (JUnit XML in red.json) is about 16,400 chars; app/views/js/ops/tests are 57,052 chars (about 14,263 tokens).
  tasks: 2
  commits: 3       # MEASURED: git rev-list --count plan_head_before..plan_head_after
plan_head_before: 3860fe7626c3c65756e0d1f126d23b4dee2433be
plan_head_after: 53b9d8668140fdd767691de6684491363fa4e168
tech-stack:
  added: []
  patterns:
    - "Schema.org string values (enumeration URLs, Date strings) go through spatie's generic setProperty(); typed setters only for DateTimeInterface values"
    - "JSON-LD rendered as an HtmlString from the builder, printed with escaped echo (no raw-echo directive)"
    - "Progressive-enhancement buttons ship with the hidden attribute (Tailwind preflight's [hidden] display:none !important beats tap-target's inline-flex); JS only ever un-hides them"
    - "Page-specific JS via @vite() pushed into the head stack of that view only"
key-files:
  created:
    - app/Seo/EventJsonLd.php
    - app/Calendar/Export/GoogleCalendarLink.php
    - app/Support/ShareLinks.php
    - resources/views/events/partials/share.blade.php
    - resources/js/share.js
    - ops/check-event-page.sh
    - tests/Feature/Public/EventJsonLdTest.php
    - tests/Feature/Public/EventCalendarAndShareTest.php
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-10-task2-red.json
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-10-task2-red-junit.xml
  modified:
    - app/Support/EventPageData.php
    - app/Support/LondonFormatter.php
    - resources/views/events/show.blade.php
    - resources/views/events/partials/dates.blade.php
    - vite.config.js
    - tests/Fixtures/public/build/manifest.json
key-decisions:
  - "All-day dates name the day on the event page: the dates list, the .ics/Google accessible names and the share text use LondonFormatter::datedTime ('Sat 7 Nov, all day'), because the plain cardTime 'All day' has no date heading above it there and the plan requires each link's accessible name to include the date. Cards on / keep cardTime"
  - "eventStatus, eventAttendanceMode and all-day startDate/endDate are set with spatie's setProperty(): the generated setter docblocks accept only enum contracts and DateTimeInterface, while schema.org takes URL and Date strings"
  - "GoogleCalendarLink mirrors the OccurrenceIcs LOCATION rule in its own private method instead of making the 01-08 ICS method public, keeping the plan's 'does not touch the ICS classes' boundary"
  - "The share status live region is a <div role=status>, not a <p>, so the 01-08 'no empty element on the page' guarantee still holds while the region starts empty"
  - "check-event-page.sh requires exactly one script with src (the share module) and no redirects on the page fetch, so a stray script or a canonical/host mismatch fails loudly"
patterns-established:
  - "Global Pest helper names stay file-prefixed (ld*, shareTest*, googleLinks, queryOf, shareLink) because Pest test-file functions share one namespace"
requirements-completed: [EVT-03, EVT-06, EVT-07]
coverage:
  - id: D1
    description: "EVT-03 JSON-LD: 10 scripts for a 12-date series, +01:00 then +00:00 across 25 Oct, Place/PostalAddress GB, url/image from APP_URL, EventCancelled for a cancelled date (properties kept) and for a cancelled event, hidden date dropped, all-day Y-m-d with inclusive end, no endDate without a finish time, GBP 0 offer only when free, </script> title cannot break out, none on / or with no upcoming dates, Host header never used"
    requirement: EVT-03
    verification:
      - kind: integration
        ref: "tests/Feature/Public/EventJsonLdTest.php (9 cases)"
        status: pass
  - id: D2
    description: "EVT-06 Google link: TEMPLATE URL with RFC 3986 encoding, UTC dates, expiry + 'Finish time not given.' with no end, all-day 20261107/20261109, UTC across the clock change, no Google or .ics link for a cancelled date, accessible name includes the date (also all-day), Host header never used"
    requirement: EVT-06
    verification:
      - kind: integration
        ref: "tests/Feature/Public/EventCalendarAndShareTest.php (5 Google/Host cases)"
        status: pass
  - id: D3
    description: "EVT-07 share: WhatsApp/Facebook/email links with the D-01 text and canonical, labelled readonly link box, Cancelled: prefix, all-day dated text with a trimmed venue, no date when nothing is upcoming, hidden Share…/Copy link buttons, polite status region, no inline handlers, Host header never used, exactly one same-origin type=module share script on the event page and none on /, share.js free of innerHTML/eval/remote URLs and its status strings verbatim"
    requirement: EVT-07
    verification:
      - kind: integration
        ref: "tests/Feature/Public/EventCalendarAndShareTest.php (8 share/asset cases)"
        status: pass
  - id: D4
    description: "Local app: / still script-free and same-origin; the first event page passes canonical/og/JSON-LD/script-origin/.ics UID/Google/share/deny-list checks"
    requirement: EVT-07
    verification:
      - kind: other
        ref: "php -S 127.0.0.1:8010 (APP_URL=http://localhost:8010, BOOST_BROWSER_LOGS_WATCHER=false): ops/check-public-assets.sh -> PUBLIC ASSETS OK: css=/build/assets/app-CbcdgzcN.css fonts=2 third-party=0; ops/check-event-page.sh -> EVENT PAGE OK: path=/events/first-demo-event jsonld=1 uid=1@whatsonattleborough.co.uk"
        status: pass
      - kind: manual_procedural
        ref: "GitHub Actions CI run on the pushed commit (orchestrator pushes and confirms)"
        status: unknown
      - kind: manual_procedural
        ref: "End-of-phase local phone/browser check (Task 2 human-check, listed below)"
        status: unknown
duration: 14min
completed: 2026-10-17
---

# Phase 1 Plan 10: Event JSON-LD, Google Calendar Link and Sharing Summary

**The event page now gives search engines one spatie-built Schema.org Event script per listed upcoming date (at most 10). Each script has the right London offset across the 25 Oct clock change, and `EventCancelled` for cancelled dates. Every date that is not cancelled offers a prefilled, RFC 3986-encoded Google Calendar link beside the `.ics`. A share block works with no JavaScript: WhatsApp, Facebook and email links plus a link box. A 1 KB first-party module adds a native "Share…" button and a "Copy link" button. It is the only script on the page and is never loaded on `/`.**

## Performance

- **Duration:** about 14 min
- **Started:** 2026-10-17T07:16Z
- **Completed:** 2026-10-17T07:31Z
- **Tasks:** 2 of 2
- **Files changed:** 16 (8 created app/view/js/ops/test files, 6 modified, 2 TDD evidence files)

## Accomplishments

- **Tracer (Task 1):**
  - `EventJsonLd` builds the JSON-LD, with addresses only when set, the organiser only when named, and the description cut at 300 graphemes.
  - `GoogleCalendarLink` builds the Google link.
  - `EventPageData` carries `jsonLd` and a `googleUrl` per date.
  - The head stack and the dates partial render both.
  - **Tracer gate:** I re-ran the automated verify: 14/14, then the full suite at 393, with pint and phpstan clean. I logged "⚡ Tracer verified end-to-end — expanding" and continued with no checkpoint (`human_verify_mode: end-of-phase`, auto mode off).
- **Sharing (Task 2, TDD):**
  - `ShareLinks` and the share partial.
  - `share.js`:
    - Web Share runs only inside the click handler. An in-flight flag ignores repeat taps, and `AbortError` (the visitor cancelled) is silent.
    - Clipboard copy is offered only when `isSecureContext` is true.
    - Status text is set with `textContent` only.
    - The fallback links are never touched.
  - The module is the second Vite input, and the fixture manifest has a `share.js` entry.
  - `ops/check-event-page.sh` checks a live event page.
- **Local check:** `/` is still script-free and `EVENT PAGE OK` passes for the local demo event (port 8010; the owner's server on port 8000 was left alone).

## Task Commits

1. **Task 1 (tracer): JSON-LD + Google Calendar link:** `860b74f` (feat)
2. **Task 2 (TDD): share block, share module and event-page check**
   - RED: `afd1019` (test)
   - GREEN: `53b9d86` (feat)

No refactor commit was needed.

## TDD Gate Compliance

- **RED** (`afd1019`): 12 tests ran in `EventCalendarAndShareTest`. 7 failed (4 on assertions, 3 errored) and 5 passed. Classifier: `RED_EVIDENCE_OK` (`target_test_failed`). The record is at `tdd-evidence/01-10-task2-red.json`, with the JUnit report beside it.
  - **Target:** `it renders WhatsApp, Facebook and email share links and a link box without JavaScript`. It failed with "Expecting null not to be null" because there was no WhatsApp anchor and no share block yet.
  - **Semantic assessment:** the 5 passing tests are the Task 1 Google/Host cases, which were already green. Every failure was missing Task 2 behaviour:
    - no `[data-share]` block, so no fallback links, no hidden buttons, no status region and no share links for the Host test
    - no share module script on the page
    - two "getAttribute() on null" errors, because the share root with `data-share-text` did not exist yet
    - `resources/js/share.js` did not exist yet
    - There were no syntax, load, database or fixture faults.
- **GREEN** (`53b9d86`):
  - The two plan test files pass 22 cases together (the plan asked for at least 16).
  - The full suite passes 401.

## Verification

- `./vendor/bin/pest`: 401 passed (baseline 379 + 22 new), with the same single detail-less PHPUnit warning recorded in 01-08. No failures or skips.
- `vendor/bin/pint --test`: passed. `vendor/bin/phpstan analyse`: 0 errors.
- `node --check resources/js/share.js` passed, and so did `npm run build`:
  - The manifest has the `resources/js/share.js` entry.
  - `share-BYXUCOpo.js` is 1,026 bytes, against a 2,048-byte limit.
  - There are no `*.map` files.
  - The CI "Built asset gates" step, run locally, printed "Asset gates passed": no deny-listed host in `public/build`, no CSS `url()` outside `/build/` or `data:`, and CSS at 12,984 bytes. The built share bundle contains no `http(s)://` string.
- `shellcheck ops/check-event-page.sh ops/check-public-assets.sh` came back clean. Shellcheck is not installed on this machine, so I ran it from the `koalaman/shellcheck:stable` Docker image already used in 01-07.
- All acceptance greps hold:
  - no raw-echo directive in `resources/views/events`
  - no asset/request-host helpers in `app/Seo` or `GoogleCalendarLink`
  - `EventCancelled`, `HtmlString` and `PHP_QUERY_RFC3986` are present
  - `googleUrl` is in the dates partial
  - `share.js` has no `innerHTML`, `eval(`, `document.write` or URLs, and contains `AbortError` and `isSecureContext`
  - no third-party widget or iframe strings
  - no inline `on*=` attributes
  - `share.js` appears exactly once in `vite.config.js`, and no view other than `events/show` references it
- Local app (`cd public && env APP_URL=http://localhost:8010 BOOST_BROWSER_LOGS_WATCHER=false php -S 127.0.0.1:8010 …/server.php`, the 01-07/01-08 recipe):
  - `ops/check-public-assets.sh` → `PUBLIC ASSETS OK: css=/build/assets/app-CbcdgzcN.css fonts=2 third-party=0`
  - `ops/check-event-page.sh` → `EVENT PAGE OK: path=/events/first-demo-event jsonld=1 uid=1@whatsonattleborough.co.uk`
  - Negative check: against `http://127.0.0.1:8010` the script exits 1 with "canonical is 'http://localhost:8010/…', expected 'http://127.0.0.1:8010/…'".
  - I stopped the server afterwards, and port 8010 is free.
- **CI:** not pushed, per the orchestrator. Its local equivalents are listed above, and the orchestrator confirms the GitHub Actions run after pushing. The plan's `git pull --rebase` / push / `gh run watch` steps were left to the orchestrator.

## Decisions Made

See `key-decisions` in the frontmatter. The two that change behaviour beyond the plan's literal text are **datedTime for all-day dates** and the **`<div>` status region** (Deviations 1 and 2 below).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] All-day dates had no date in the dates list, the link names or the share text**
- **Found during:** Task 2, local live check
- **Issue:**
  - On the demo all-day event, the share text read "First Demo Event – All day at …", and both add-to-calendar links were named "… for All day".
  - `LondonFormatter::cardTime()` returns a bare "All day" because cards on `/` sit under a date heading. The event page's dates list (01-08) has no such heading, so an all-day series listed "All day" several times with no dates.
  - This breaks the plan's truth that "each link's accessible name includes the date", and a shared message with no date is useless.
- **Fix:** Added `LondonFormatter::datedTime()`. It returns "Sat 7 Nov, all day" or "Sat 7 Nov, all day until Sun 8 Nov"; timed dates are unchanged. It is used for the `EventPageData` date `timeLabel` and in `ShareLinks`. Cards keep `cardTime()`. I also trimmed the venue name in the share text, because the demo venue's trailing space produced "center , on".
- **Tests:** the all-day Google test now asserts the accessible name "Google Calendar for Sat 7 Nov, all day until Sun 8 Nov". A new case asserts "Craft Fair – Sat 7 Nov, all day at Connaught Hall, on What's On Attleborough" with a trailing-space venue.
- **Files modified:** app/Support/LondonFormatter.php, app/Support/EventPageData.php, app/Support/ShareLinks.php, tests/Feature/Public/EventCalendarAndShareTest.php
- **Commit:** 53b9d86

**2. [Rule 1 - Bug] The empty status paragraph broke 01-08's "no empty element" guarantee**
- **Found during:** Task 2, full-suite run
- **Issue:** The plan specified an empty `<p role="status">`. `EventPageContentTest` "leaves no label or empty element…" fails on any empty `<p>`.
- **Fix:** Made the live region a `<div role="status" aria-live="polite">`. It has the same semantics, and the 01-08 test is unchanged and green.
- **Files modified:** resources/views/events/partials/share.blade.php
- **Commit:** 53b9d86

**3. [Rule 3 - Blocking] Larastan rejected schema.org string values in spatie's typed setters**
- **Found during:** Task 1
- **Issue:** `eventStatus()`, `eventAttendanceMode()` and `endDate()` are documented as taking only enum contracts or `DateTimeInterface`. phpstan reported 3 `argument.type` errors for the `EventStatusType` URL constants and the all-day `Y-m-d` strings, even though RESEARCH Pattern 6 shows exactly these values.
- **Fix:** Pass those four values through spatie's public `setProperty()`. Timed dates still use the typed `startDate()`/`endDate()` with London-zoned `CarbonImmutable` values. The output is identical and there is no suppression.
- **Files modified:** app/Seo/EventJsonLd.php
- **Commit:** 860b74f

### Process notes

- **Plan says "push to main and watch CI".** I did not push or watch CI, per the orchestrator; the local equivalents are recorded above.
- **Commits on `main`.** `branching_strategy: none` and the orchestrator says committing on `main` is intended, as in the earlier Phase 1 plans.
- **shellcheck via Docker:** see Verification. I installed nothing.
- **`GoogleCalendarLink` duplicates the eight-line ICS LOCATION join** to respect the "do not touch the ICS classes" boundary. If a later plan touches `OccurrenceIcs`, it can expose that method and remove the copy.

## Known Stubs

None.

## Threat Flags

None. There is no new endpoint or trust boundary. The JSON-LD, Google link, share links and module all sit inside the plan's threat model (T-01-63, T-01-66 … T-01-69), and each mitigation is tested:
- tests for script break-out, the Host header, inline handlers and the script allow-list
- a grep gate for the module's content

**Phase 5 note (carried forward):** Caddy's `Permissions-Policy` must include `web-share=(self)`, or the native share button will fail in production (05-01).

## End-of-phase human checks (non-blocking, owner)

Run `npm run build`, then `php artisan serve --host 0.0.0.0 --port 8000`. Use local events, ideally a weekly series with a date after 25 Oct 2026. Optionally add `BOOST_BROWSER_LOGS_WATCHER=false` to `.env` so Boost's dev-only logger script is not injected.

1. **Laptop Safari or Chrome at http://localhost:8000:**
   - From `/`, open a card.
   - Tap "Share…" and cancel. The page should stay usable, and a second tap should reopen the sheet.
   - Share to a target. The title and link should be correct.
   - Tap "Copy link". "Link copied." should be announced, and the pasted URL should be `http://localhost:8000/events/{slug}`.
   - Download "Add to calendar (.ics)" for a date after 25 Oct and open it. The calendar app should show the right local time, title and venue.
   - Follow "Google Calendar". The event should be prefilled with the same title, time and location. For an all-day event, check the end day is right (exclusive end, RESEARCH A1).
2. **Phone on the same Wi-Fi at http://LAPTOP-LAN-IP:8000** (not a secure context):
   - "Share…" and "Copy link" must stay hidden.
   - The WhatsApp, Facebook and Email links should open the right apps with the "… on What's On Attleborough" text and the link.
   - The link box should be visible.
   - Add an `.ics` to the phone calendar.
3. **Desktop Firefox (no Web Share), or JavaScript turned off:** the three fallback links and the link box should show and work, with no dead button.

The production event-page check, the real-phone share/preview UAT and the Rich Results Test are 05-02 Task 4 (Phase 5).

## Next Phase Readiness

Phase 1 plan work is complete (01-01 and 01-04 … 01-10). For Phase 5:
- 05-01 must send `Permissions-Policy: … web-share=(self)`.
- 05-02 runs `ops/check-event-page.sh https://whatsonattleborough.co.uk` and the Rich Results Test on a genuine published event.

## Self-Check: PASSED

- All 10 created files listed in key-files exist on disk.
- Commits 860b74f, afd1019 and 53b9d86 are ancestors of HEAD; `git rev-list --count 3860fe7..53b9d86` = 3.
- STATE.md and ROADMAP.md are untouched. No background process of mine is running (port 8010 is free).
