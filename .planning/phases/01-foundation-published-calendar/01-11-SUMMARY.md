---
phase: 01-foundation-published-calendar
plan: 11
subsystem: public-surface
status: complete
gap_closure: true
tags: [gap-closure, cr-01, host-header, vite, url-generation, laravel-boost, wr-04, wr-05, wr-08, ci, redirects, cache-control, london-time]
requires:
  - phase: 01-foundation-published-calendar
    provides: "01-07 fixture manifest + withVite pattern, ops/check-public-assets.sh, NoThirdPartyAssetsTest; 01-08 EventController/OccurrenceIcsController slug redirects, PublicUrl, EventSlugger rename-back; 01-10 share module (@vite on events/show), ops/check-event-page.sh, LondonFormatter::datedTime"
provides:
  - "AppServiceProvider::pinUrlsToAppUrl(): URL::forceRootUrl(config('app.url')), forceScheme('https') only for https app.url, Vite::createAssetPathsUsing root-relative (/build/assets/...)"
  - "EventController::REDIRECT_CACHE_CONTROL = 'no-cache, private', sent on the event-page and .ics slug 301s"
  - "LondonFormatter::lastDay(): a timed end at exactly 00:00 London is exclusive (19:00-00:00 renders as one day)"
  - "ops/check-public-assets.sh spoofed-Host probe (hostprobe.invalid); OK line ends ' host-probe=clean' (prefix 'PUBLIC ASSETS OK:' unchanged)"
  - ".env.example BOOST_BROWSER_LOGS_WATCHER=false (and appended to the owner's .env)"
  - "CI job matrix php ['8.4', '8.5'], fail-fast false, job name 'test (PHP x.y)'"
affects: [01-12 (new fixtures here already pass organiserContact), 01-14 (deferred: existing Host-spoof tests are vacuous, see deferred-items.md), 05-01/05-02 (production app.url https → forceScheme; check-public-assets.sh host probe runs against prod; a proxy rejecting unknown hosts passes)]
actuals:
  tokens: 14395    # chars/4 over the realized diff 8f42354..292c157 (57,581 added chars). TDD evidence (JUnit XML embedded in red.json, twice per task) is about 44,700 chars; code/tests/ops/CI/env are 12,848 chars (about 3,212 tokens).
  tasks: 3
  commits: 5       # MEASURED: git rev-list --count plan_head_before..plan_head_after
plan_head_before: 8f4235473aed6fca0d09943acc48fb5e7931fb2a
plan_head_after: 292c157be586b254ed7cb2e6558c5b91a110c806
tech-stack:
  added: []
  patterns:
    - "Host-spoof feature tests request the absolute URI (http://evil.example/...) as well as sending the Host header: Laravel's test client rebuilds HTTP_HOST from the URI, so withHeader('Host') on a relative path never reaches the app"
    - "Vite asset URLs are root-relative via Vite::createAssetPathsUsing; every other generated URL is rooted at config('app.url') via URL::forceRootUrl"
    - "Redirects that may be reversed (slug history) use 301 + Cache-Control 'no-cache, private' (revalidate) rather than a max-age cap"
key-files:
  created:
    - tests/Feature/Public/HostHeaderAssetsTest.php
    - tests/Unit/Ops/EnvExampleTest.php
    - tests/Feature/Public/SlugRedirectCachingTest.php
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-11-task1-red.json
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-11-task1-red-junit.xml
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-11-task2-red.json
    - .planning/phases/01-foundation-published-calendar/tdd-evidence/01-11-task2-red-junit.xml
    - .planning/phases/01-foundation-published-calendar/deferred-items.md
  modified:
    - app/Providers/AppServiceProvider.php
    - .env.example
    - ops/check-public-assets.sh
    - app/Http/Controllers/EventController.php
    - app/Http/Controllers/OccurrenceIcsController.php
    - app/Support/LondonFormatter.php
    - tests/Feature/Public/OccurrenceCardTest.php
    - .github/workflows/ci.yml
key-decisions:
  - "No trustHosts() in bootstrap/app.php: Laravel's TrustHosts middleware is inactive when APP_ENV is local and under tests, so in Phase 1's local-only scope it adds no protection a test could prove. forceRootUrl plus root-relative Vite paths take the Host header out of URL generation completely. Phase 5 (Caddy) may still reject unknown hosts at the proxy."
  - "Vite tags are root-relative, not pinned to app.url: they carry no host to poison, and they still load when the owner opens the local app on a phone through the LAN IP (01-07/01-10 human checks)."
  - "WR-04 uses Cache-Control 'no-cache, private' (revalidate every time) instead of the review's 'public, max-age=3600': a max-age cap only narrows the redirect-loop window after a rename back, while revalidation closes it. The status stays 301, so search engines still see a permanent move."
  - "WR-08 uses a PHP 8.4 + 8.5 matrix instead of downgrading CI to 8.4: 01-CONTEXT carries production PHP 8.5 with a Composer floor of 8.4 (lock resolved for platform 8.4.12, local Herd 8.4.12). Testing only one leaves the other unproven."
requirements-completed: [CAL-01, CAL-06, CAL-07, EVT-01, EVT-02, EVT-06, OPS-01]
metrics:
  duration: "~11 min (08:39Z-08:50Z)"
  completed: 2026-10-17
---

# Phase 1 Plan 11: Gap closure: script-free local `/`, Host-proof URLs, revalidated slug redirects, midnight display, CI PHP matrix

This plan closes the 01-08 truth 16 verification gap on the server it names: with the default local config, `/` on `php artisan serve` :8000 now has no script element, because Laravel Boost's browser logger is off in `.env.example` and in the owner's `.env`. It also takes the request Host header out of URL generation. The URL root is pinned to `config('app.url')` and Vite tags are root-relative. A Vite-enabled spoofed-Host regression test covers this, and a live `hostprobe.invalid` probe checks it on the running server. Slug 301s now carry `no-cache, private`, so a rename back can't loop a browser. A timed event ending exactly at midnight now reads as one day. CI runs on PHP 8.4 and 8.5.

## Live checks (php artisan serve, http://localhost:8000; the owner's own server, not restarted)

```
PUBLIC ASSETS OK: css=/build/assets/app-CbcdgzcN.css fonts=2 third-party=0 host-probe=clean
EVENT PAGE OK: path=/events/first-demo-event jsonld=1 uid=1@whatsonattleborough.co.uk
```

These replace the earlier :8010 workaround runs noted in 01-VERIFICATION. Laravel's `serve` command picked up the `.env` change by itself, so no checkpoint was needed. Extra live checks: `curl -H 'Host: hostprobe.invalid' /admin` → `302 http://localhost:8000/admin/login` (pinned), `/` stylesheet `href="/build/assets/app-CbcdgzcN.css"`, `/` has 0 `<script`, and an uppercase slug 301 now sends `Cache-Control: no-cache, private`.

## Finding → fix → test

| Finding | Fix | Regression test / check |
|---------|-----|-------------------------|
| Verification gap (01-08 truth 16): Boost browser logger injects `<script>` on `/` locally | `BOOST_BROWSER_LOGS_WATCHER=false` in `.env.example` (under the Boost block, with a reason comment) and appended to the owner's `.env` (key was absent); `php artisan config:clear` | `tests/Unit/Ops/EnvExampleTest.php`; live `ops/check-public-assets.sh` on :8000 → PUBLIC ASSETS OK |
| CR-01: `@vite` URLs (and every generated URL) built from the request Host | `AppServiceProvider::pinUrlsToAppUrl()`: `URL::forceRootUrl`, conditional `forceScheme('https')`, `Vite::createAssetPathsUsing` root-relative | `tests/Feature/Public/HostHeaderAssetsTest.php` (3 cases: `/`, `/events/quiz-night`, guest `/admin`); live `host-probe=clean` |
| CR-01 live blind spot: ops script only sent BASE_URL's host | `ops/check-public-assets.sh` extra GET / with `Host: hostprobe.invalid`; fails on echo, refusal/non-200 passes | live run above |
| WR-04: cacheable 301 + rename-back = redirect loop | `->header('Cache-Control', EventController::REDIRECT_CACHE_CONTROL)` on both 301 branches (`'no-cache, private'`) | `tests/Feature/Public/SlugRedirectCachingTest.php` (4 cases: old slug, uppercase slug, old-slug .ics, rename back) |
| WR-05: 19:00–00:00 shown as "until Sun 1 Nov" | `LondonFormatter::lastDay()`: exclusive end when all-day OR local end == its start of day | `OccurrenceCardTest`: midnight → `>Sat 31 Oct, 7pm</time>` with no "until"; 22:00–00:30 still "until Sun 1 Nov" |
| WR-08: CI on 8.5 only | `strategy.matrix.php: ['8.4', '8.5']`, `fail-fast: false`, `name: test (PHP ${{ matrix.php }})` | Symfony YAML parse check (quoted strings, fail-fast false) → exit 0 |

## Tasks

| # | Task | Commits |
|---|------|---------|
| 1 | Tracer: script-free, Host-proof `/` end to end | `240e90e` test (RED), `e351925` fix (GREEN) |
| 2 | WR-04 revalidated slug redirects + WR-05 midnight display | `49ee3eb` test (RED), `a212ade` fix (GREEN) |
| 3 | WR-08 CI PHP matrix + whole-plan gates | `292c157` ci |

Tracer feedback gate: the Task 1 `<verify>` checks are automated (Pest plus the live scripts), so I re-ran them end to end and they passed before expanding (end-of-phase mode, no checkpoint).

## TDD Gate Compliance

- **Task 1 RED** (`tdd-evidence/01-11-task1-red.json`, classifier `RED_EVIDENCE_OK`, target `it keeps the calendar page free of a spoofed Host and links the stylesheet root-relative`): 4 tests, 4 assertion failures, 0 errors. Both page bodies contained `evil.example`, guest `/admin` redirected to `http://evil.example/admin/login`, and `.env.example` lacked the key. Semantic assessment: every failure was the missing behaviour, with no load, DB or fixture faults. A first RED attempt with the repo's `withHeader('Host')` idiom failed only on the root-relative assertion, because the spoof never reached the app (see Deviations). The recorded RED is the corrected run.
- **Task 1 GREEN** `e351925`: the 5-file verify set passed 27/27 (123 assertions).
- **Task 2 RED** (`tdd-evidence/01-11-task2-red.json`, `RED_EVIDENCE_OK`, target `it sends an old slug to the current one with a 301 that browsers revalidate`): 32 tests, 5 assertion failures. All 4 caching cases failed on an empty Cache-Control (status and Location already right), and the midnight case rendered `Sat 31 Oct, 7pm until Sun 1 Nov`. The overnight guard case passed as intended.
- **Task 2 GREEN** `a212ade`: the 4-file verify set passed 54/54 (444 assertions), and the whole Public suite passed 205/205.
- No REFACTOR commits were needed.

## Verification (local)

- `./vendor/bin/pest`: **411 passed, 2485 assertions** (baseline 401 + 10 new), 0 failures or skips. It shows the same single detail-less PHPUnit warning recorded in 01-08 and 01-10.
- `vendor/bin/pint --test`: clean.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: 0 errors. Without `--memory-limit`, local Herd PHP's 128M `memory_limit` crashes a parallel worker. CI already passes `--memory-limit=1G`, so this is an environment limit, not a code finding.
- `npm run build` plus the CI "Built asset gates" script run locally: passed. The CSS is 12.98 kB and there are no source maps or deny-list hosts.
- `.github/workflows/ci.yml` matrix YAML check: exit 0 (`["8.4","8.5"]` as strings, `fail-fast: false`).
- `.env`: `grep -c '^BOOST_BROWSER_LOGS_WATCHER=false$' .env` → 1. `git status --porcelain .env` → empty (ignored, never staged). No `.env` contents were printed.
- I didn't push. The orchestrator pushes and confirms both CI legs.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Repo Host-spoof test idiom does not spoof the Host**
- **Found during:** Task 1 RED.
- **Issue:** `$this->withHeader('Host', 'evil.example')->get('/path')` (the idiom the plan prescribes, from EventSeoTest:139) never reaches the app. Laravel's test client prefixes relative paths with `config('app.url')`, and Symfony `Request::create()` then sets `HTTP_HOST` from the URI host, overriding the header. CR-01 was invisible to it: the first RED rendered `http://localhost:8000/build/...`.
- **Fix:** `HostHeaderAssetsTest::spoofedHostGet()` sends the header and requests the absolute `http://evil.example{path}` URI. That is what the app sees when a real client sends `Host: evil.example`. Re-run RED reproduced CR-01 exactly as 01-REVIEW describes.
- **Files modified:** tests/Feature/Public/HostHeaderAssetsTest.php
- **Commit:** 240e90e
- **Out of scope, logged:** the existing Host tests in EventSeoTest, BrandHeadTest, EventCalendarAndShareTest and EventJsonLdTest use the same vacuous idiom. 01-14 owns those files, so the item is in `deferred-items.md`. The code under them is now Host-proof.

**2. [Rule 3 - Blocking] PHPStan memory limit locally**
- **Found during:** Task 2 gates.
- **Issue:** `vendor/bin/phpstan analyse --no-progress` (the plan's verify line) crashes at Herd's 128M memory_limit.
- **Fix:** I ran it with `--memory-limit=1G`, the same flag CI uses. No file changed.

Other small adjustments: the `.ics` redirect uses the shared `EventController::REDIRECT_CACHE_CONTROL` constant rather than a second string literal. The literal `no-cache, private` still appears twice in each controller (docblock and code, or the existing success header), so the acceptance greps hold.

## Known Stubs

None.

## Threat Flags

None. No new endpoints, auth paths or schema. T-01-70..74 are mitigated as planned, and no package was added (T-01-SC).

## Self-Check: PASSED
