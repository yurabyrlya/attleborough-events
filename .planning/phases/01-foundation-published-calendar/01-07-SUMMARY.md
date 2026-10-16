---
phase: 01-foundation-published-calendar
plan: 07
subsystem: ui
status: complete
tags: [tailwind-4, vite-8, fontsource, wcag-2.2, dark-mode, open-graph, brand, ci, privacy, cal-01, cal-06, cal-07]

requires:
  - phase: 01-foundation-published-calendar
    provides: "01-01 layout (lang en-GB, x-release meta, title suffix, head stack), config/site.php, CategorySeeder slugs, CI job test, tests/Pest.php withoutVite(); 01-06 chips/card/empty-state partials, OccurrenceCardData, CalendarFilters; 01-05 status visibility tests"
provides:
  - "npm toolchain pinned to owner-approved versions with a committed package-lock.json, installed with --ignore-scripts (no package in the tree has lifecycle scripts)"
  - "vite.config.js: single CSS input resources/css/app.css, no build-time font provider (offline build)"
  - "resources/css/app.css: Tailwind 4 (source(none) + views-only @source), self-hosted Atkinson Hyperlegible Next Variable, @theme static with the default palette removed and ten semantic colour tokens, dark overrides via @variant dark (prefers-color-scheme), global 2px :focus-visible ring, @utility tap-target (2.75rem)"
  - "Layout contract: @vite('resources/css/app.css') (CSS only), color-scheme + two theme-color metas, favicon.svg / favicon.ico / apple-touch-icon links, default OG/Twitter block replaced by any page that pushes the 'og' stack (og:image from config('app.url')), header x-brand.logo, skip link to main#main, 42rem single column, footer with contact mailto"
  - "Blade components: x-brand.calendar-pin, x-brand.logo, x-category-icon slug=… (fixed allow-list, fallback other), x-icons.category.{10 slugs} (Heroicons v2 outline, MIT licence file)"
  - "calendar.partials.chip: one chip anchor (tap-target, aria-[current=true]: keyed fill, check icon when active)"
  - "App\\Support\\OccurrenceCardData::$categorySlug"
  - "Public brand files: public/favicon.svg, public/favicon.ico (32x32 PNG-in-ICO), public/apple-touch-icon.png (180x180), public/images/og-default.png (1200x630); master icon resources/svg/calendar-pin.svg"
  - "ops/render-brand-assets.sh (dev-only headless-Chrome + PHP renderer, outputs committed), ops/brand/og-default.html, ops/check-public-assets.sh [BASE_URL] (prints 'PUBLIC ASSETS OK: css=… fonts=N third-party=0')"
  - "CI job test: setup-node@v7 (node 24, npm cache), npm ci --ignore-scripts, npm run build, 'Built asset gates' (manifest, deny-list, url() only /build/ or data:, no *.map, app CSS <= 60000 bytes)"
  - "Public in-site links are root-relative (route(..., absolute: false); paginator withPath) so the Host header never reaches the HTML"
  - "tests/Pest.php assertLink(response, href|null, text, current|null) helper for markup-tolerant link checks"
affects: [01-08 (pushes event og:* into the 'og' stack, uses the layout and og-default.png), 01-10 (adds its JS entry as a second Vite input; JSON-LD image fallback og-default.png), 05-02 (deploy:assets, CI artifact, production run of ops/check-public-assets.sh with immutable cache check), CAL-09 audit (Phase 5)]

actuals:
  tokens: 34208    # chars/4 over the 52 changed text files (incl. the two RED-evidence JSON files that embed JUnit reports; excludes PNG/ICO, *.xml and package-lock.json)
  tasks: 3         # Tasks 2-4 in this continuation; Task 1 (checkpoint) was completed by the owner's approval
  commits: 5
plan_head_before: 755428a344f8522008ab2bd93e67f09485100797
plan_head_after: 24d56d6f39efe3c938730a3e6f5906a7eb0d4a1f

tech-stack:
  added:
    - "vite 8.3.4 (dev)"
    - "tailwindcss 4.3.3 (dev)"
    - "@tailwindcss/vite 4.3.3 (dev)"
    - "laravel-vite-plugin 3.2.0 (dev)"
    - "concurrently 10.0.6 (dev, composer dev / php artisan dev only)"
    - "@laravel/multiplex 0.4.5 (optional, dev process runner only)"
    - "@fontsource-variable/atkinson-hyperlegible-next 5.3.0"
  patterns:
    - "CSS-only public bundle: no module script is emitted for /, so 'no JavaScript on public pages' holds in the served page, not only in tests"
    - "Semantic colour tokens only (default palette reset with --color-*: initial); ContrastTest parses app.css and proves WCAG ratios, so a token edit that breaks AA fails CI"
    - "Share metadata through a replaceable 'og' stack: the layout prints defaults only when yieldPushContent('og') is empty"
    - "Absolute URLs from config('app.url') only; everything else root-relative"
    - "Component name built only from an allow-listed value (x-dynamic-component with 'icons.category.'.$resolved)"
    - "Chip/state styling keyed to the ARIA attribute (aria-[current=true]:) plus a non-colour cue"
    - "Brand rasters generated once on a laptop and committed; CI and servers never need Chrome"

key-files:
  created:
    - package-lock.json
    - ops/check-public-assets.sh
    - ops/render-brand-assets.sh
    - ops/brand/og-default.html
    - resources/svg/calendar-pin.svg
    - resources/views/components/brand/calendar-pin.blade.php
    - resources/views/components/brand/logo.blade.php
    - resources/views/components/category-icon.blade.php
    - resources/views/components/icons/category/{family,community,market,charity,music,sport,food-drink,school,arts-culture,other}.blade.php
    - resources/views/components/icons/category/HEROICONS-LICENSE.txt
    - resources/views/calendar/partials/chip.blade.php
    - public/favicon.svg
    - public/apple-touch-icon.png
    - public/images/og-default.png
    - tests/Fixtures/public/build/manifest.json
    - tests/Fixtures/views/brand-og-push.blade.php
    - tests/Feature/Public/NoThirdPartyAssetsTest.php
    - tests/Feature/Public/BrandHeadTest.php
    - tests/Feature/Public/PublicLookTest.php
    - tests/Unit/Brand/ContrastTest.php
  modified:
    - package.json
    - vite.config.js
    - resources/css/app.css
    - resources/views/layouts/app.blade.php
    - resources/views/calendar/index.blade.php
    - resources/views/calendar/partials/filter-chips.blade.php
    - resources/views/calendar/partials/occurrence-card.blade.php
    - resources/views/calendar/partials/empty-state.blade.php
    - app/Support/OccurrenceCardData.php
    - app/Calendar/PublicOccurrenceQuery.php
    - public/favicon.ico
    - .github/workflows/ci.yml
    - tests/Pest.php
    - tests/Feature/Public/AttributeFiltersTest.php
    - tests/Feature/Public/CalendarFiltersTracerTest.php
    - tests/Feature/Public/DateFilterTest.php
    - tests/Feature/Public/OccurrenceCardTest.php
    - tests/Feature/Public/StatusVisibilityTest.php
  deleted:
    - resources/js/app.js

key-decisions:
  - "All npm packages pinned exactly (not ^ ranges) to the owner-approved / audited versions; lockfile installs with npm ci --ignore-scripts"
  - "Public in-site links are root-relative (route(..., absolute: false), paginator withPath) so the request Host header never appears in public HTML (T-01-41); works for a LAN phone check too"
  - "Tailwind scans only resources/views/**/*.blade.php (@import 'tailwindcss' source(none)), keeping the CSS small (12.6 kB) and free of classes from docs/vendor"
  - "01-05/01-06 exact-markup assertions moved to assertLink()/class-tolerant regexes with the same href, text and aria-current checks (classes and icons inside anchors made the literal strings impossible)"
  - "Local asset check runs PHP's built-in server directly (what artisan serve wraps) with BOOST_BROWSER_LOGS_WATCHER=false, because Laravel Boost (require-dev) injects a dev-only browser-logger script in local env and artisan serve strips that env var"

patterns-established:
  - "assertLink(): link contract checks that ignore styling"
  - "Fixture public path + withVite() to test real @vite output"
  - "ops/check-public-assets.sh BASE_URL for same-origin/asset checks (local now, production in 05-02)"

requirements-completed: [CAL-01, CAL-06, CAL-07]

coverage:
  - id: D1
    description: "/ is styled by one same-origin Vite stylesheet with self-hosted woff2 fonts, no script, no third-party resource"
    requirement: CAL-01
    verification:
      - kind: integration
        ref: "tests/Feature/Public/NoThirdPartyAssetsTest.php"
        status: pass
      - kind: other
        ref: "ops/check-public-assets.sh http://localhost:8010 -> PUBLIC ASSETS OK: css=/build/assets/app-D91_jxSs.css fonts=2 third-party=0"
        status: pass
  - id: D2
    description: "CI builds assets with npm ci --ignore-scripts and fails on missing manifest, deny-list host, foreign url(), *.map, or CSS > 60000 bytes"
    requirement: CAL-01
    verification:
      - kind: other
        ref: "Built asset gates step run locally (pass) + negative runs with an injected .map, unpkg url and foreign url (each exit 1); actionlint clean"
        status: pass
      - kind: other
        ref: "GitHub Actions run on the pushed commit (orchestrator confirms after push)"
        status: unknown
  - id: D3
    description: "Ten semantic tokens, AA in light and dark, dark follows the device only"
    verification:
      - kind: unit
        ref: "tests/Unit/Brand/ContrastTest.php"
        status: pass
  - id: D4
    description: "Head: color-scheme, theme-color per scheme, favicon/ico/apple-touch links, default OG once from config('app.url'), Host header never echoed, og stack replaces defaults, SVG wordmark, brand asset sizes"
    verification:
      - kind: integration
        ref: "tests/Feature/Public/BrandHeadTest.php"
        status: pass
  - id: D5
    description: "Restyled chips/cards/empty states: tap targets, skip link, aria-current keyed chips with check icon, allow-listed category icons, visible Cancelled badge, title-before-time, escape-once, wrap-anywhere, no empty wrappers, D-05 strings, no script/form/wire"
    requirement: CAL-07
    verification:
      - kind: integration
        ref: "tests/Feature/Public/PublicLookTest.php (13 cases)"
        status: pass
  - id: D6
    description: "Filters still work as plain links after the restyle"
    requirement: CAL-06
    verification:
      - kind: integration
        ref: "tests/Feature/Public/AttributeFiltersTest.php, CalendarFiltersTracerTest.php, DateFilterTest.php"
        status: pass
  - id: D7
    description: "Owner phone check against the local dev server (light/dark, chips, cards, home-screen icon, Tab focus, 320px)"
    verification:
      - kind: manual_procedural
        ref: "Task 4 <human-check>, collected end-of-phase"
        status: unknown

duration: 22min
completed: 2026-10-16
---

# Phase 1 Plan 07: Public Look (Tailwind 4, Norfolk Green, Brand Assets) Summary

**Self-hosted Tailwind 4 + Atkinson Hyperlegible Next CSS bundle (no JS, no third-party hosts) with Norfolk-green AA tokens that follow the device theme, a calendar-pin SVG wordmark, favicon/apple-touch/OG assets, and a restyled calendar with 44px tap targets, visible focus, category icon tiles and a visible "Cancelled" badge, gated in CI.**

## Performance

- **Duration:** about 22 min (this continuation, 22:34-22:56 UTC)
- **Started:** 2026-10-16T22:34:49Z
- **Completed:** 2026-10-16T22:56:35Z
- **Tasks:** 3 executed here (Tasks 2-4); Task 1 was the owner's package checkpoint
- **Files modified:** 52 (plus 1 deleted)

## Package approvals (Task 1 checkpoint, owner decision)

Owner reply, verbatim: `approved vite@8.3.4, approved concurrently@10.0.6, approved @laravel/multiplex@0.4.5`.

Before installing I re-checked each named version on the registry (`npm view <pkg>@<ver> version repository.url scripts.preinstall scripts.install scripts.postinstall dist.integrity`):

| Package | Version | Repository | Install scripts | dist.integrity |
|---|---|---|---|---|
| vite | 8.3.4 (published 2026-10-08; SLSA provenance, OIDC trusted publishing; maintainers yyx990803/vitebot; includes fixes for GHSA-rq7h-c2jc-7f22 / GHSA-vfpm-58rq-9qcg / GHSA-9jrq-w75r-8gcw) | git+https://github.com/vitejs/vite.git | none | sha512-IftLE8phJhdNRIGNEV4JCCvfNScoZQVfcXaN8TQropWGJtOHcu+iAB9pHcuGvmEb/kddw0LBe/jS8ztdC6xdiQ== |
| concurrently | 10.0.6 (SLSA provenance, dev-only) | git+https://github.com/open-cli-tools/concurrently.git | none | sha512-qgICXXFp2/3nAebRLmxcINKEXM1mLK3ij/y31P02VTs/KIkddIeC05n73DxOXsXMB9LFSqNU1/na3lDlOhcbIw== |
| @laravel/multiplex | 0.4.5 (SLSA provenance, optional, dev-only) | git+https://github.com/laravel/multiplex.git | none | sha512-NhDhj4b1/+6j1xYnonzx3vZa39CAUUM7iCAN0xZ5jjcYw3GKORtiXXb0XwXW74wyeVJ25vAvXzfIhy4MXmmzCw== |
| tailwindcss | 4.3.3 (audited group a) | https://github.com/tailwindlabs/tailwindcss.git | none | sha512-gOhV3P7ufE62QDGg1zVaTgCR+EtPv92k2nIhVcVKcLmxT1sUBsQGhnZj175j+MqRt4zLF7ic+sCYjfhxMxj7YQ== |
| @tailwindcss/vite | 4.3.3 (audited; peer vite ^5.2 \|\| ^6 \|\| ^7 \|\| ^8) | https://github.com/tailwindlabs/tailwindcss.git | none | sha512-yYU8cogLeSh/ms2jh8Fj7jaba/EWa7Ja6GoUqYZaraEuCI5YS6ms6ObZgjjedm+jm6XZjdNRWBpPP6Z86oOxcw== |
| laravel-vite-plugin | 3.2.0 (audited; peer vite ^8.0.0, optional peer fontaine not installed) | git+https://github.com/laravel/vite-plugin.git | none | sha512-xSxY9Gzeb/eancd8WeK09piAFP+a6i5QIBqNCKNv9L0Eq6wziwzSem7F1GvMSrtjMh5F/QVKFxn8t9naGOA66A== |
| @fontsource-variable/atkinson-hyperlegible-next | 5.3.0 (audited) | git+https://github.com/fontsource/font-files.git | none | sha512-UnLzNUy1RWoLDfydAatP4NaRm99X/WbQEph7q/PymJnCFIOOwKOx3pBmp5ys8trO0dM+CKGuJgeZP9fAR4mQpA== |

- Group (b): none. The skeleton's `resources/js/app.js` was an empty stub (`//`) and there was no `bootstrap.js` or axios dependency, so nothing had to be dropped.
- After `npm install --ignore-scripts`, running `npm query ':attr(scripts, [postinstall]), :attr(scripts, [install]), :attr(scripts, [preinstall])'` over the whole installed tree (98 packages) returned nothing, so no package anywhere has lifecycle scripts.
- The lockfile holds exactly `node_modules/vite` 8.3.4. No Alpine and no HTTP client.

## Accomplishments

- **Tracer (Task 2):** the CSS-only Vite bundle is wired end to end. The font url()s rebase to `/build/assets/*.woff2` (2 woff2 files). The layout emits one stylesheet and no script. The CI job builds the bundle and runs the built-asset gates. `ops/check-public-assets.sh` passes against a local server.
- **Brand (Task 3):** the ten tokens pass AA in both themes (proved by ContrastTest). Dark mode follows `prefers-color-scheme` only. The calendar-pin master SVG and its inline component match, and the wordmark sits in the header. The favicon (SVG + ICO), the opaque 180px apple-touch icon and the 1200x630 OG image were rendered offline with the bundled font and committed. The default OG block is built from `config('app.url')`, and a page can replace it through the `og` stack.
- **Restyle (Task 4):** chips are thumb-sized and show their state without relying on colour. Cards have category icon tiles, `wrap-anywhere` titles and a visible "Cancelled" pill inside the h3, which still comes before `<time>`. Date headings have an accent rule, "More events" is full width, empty states are surface panels with the D-05 strings unchanged, and the page has a skip link and a footer contact. A headless check at a true 320px viewport (fixed-width iframe) showed no sideways scroll.

## Task Commits

1. **Task 2 (tracer): CSS bundle, CI build + gates, asset check:** `a0b82d7` (feat)
2. **Task 3: brand tokens, wordmark, icons, OG:** `b312b7a` (test, RED), `32f948e` (feat, GREEN)
3. **Task 4: public restyle:** `dd16006` (test, RED), `24d56d6` (feat, GREEN)

## TDD Gate Compliance

- **Task 3 RED** (`b312b7a`): `tests/Unit/Brand/ContrastTest.php` + `tests/Feature/Public/BrandHeadTest.php`. All 13 tests failed on assertions with 0 errors. Target: `it meets AA for text pairs and 3:1 for focus and border pairs with data set "('light')"` ("Failed asserting that an array has the key [accent]"). The first record named the target without its data-set suffix and was rejected (INVALID_RED no_target_test_failure); the corrected name gave `RED_EVIDENCE_OK`. Evidence: `tdd-evidence/01-07-task3-red.json` / `-junit.xml`. Semantic assessment: every failure was missing Task 3 behaviour, with no load or fixture faults.
- **Task 4 RED** (`dd16006`): `tests/Feature/Public/PublicLookTest.php`. 7 tests failed on assertions and 0 errored. Target: `it lets a long unbroken title wrap anywhere`. `RED_EVIDENCE_OK`. The other 6 tests passed before implementation by design: they guard 01-06 behaviour the restyle must keep (single visible badge, title-before-time, escape-once, footer mailto, no script/form/wire).
- **GREEN:** `32f948e` and `24d56d6`. The full suite passes after each one.
- Task 2 is a `type="tracer"` task, not `tdd="true"`. NoThirdPartyAssetsTest was run red first (2 assertion failures) and committed with the implementation.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Security, T-01-41] Root-relative in-site links so the Host header never reaches the HTML**
- **Found during:** Task 3 (BrandHeadTest "evil.example appears nowhere")
- **Issue:** The chip, empty-state, logo and "More events" hrefs were absolute `route()`/paginator URLs built from the request host. A request for `http://evil.example/` echoed `evil.example` in every link.
- **Fix:** `route(..., absolute: false)` in the calendar partials and the logo, and `->withPath(route('calendar', absolute: false))` on the paginator in `PublicOccurrenceQuery`. I did not use `URL::forceRootUrl()` because it would break the LAN phone check: the CSS and links would point at localhost:8000.
- **Files modified:** resources/views/calendar/partials/{filter-chips,empty-state}.blade.php, resources/views/components/brand/logo.blade.php, app/Calendar/PublicOccurrenceQuery.php, plus the 01-06 tests that compared against absolute `route()` (now `absolute: false`)
- **Commit:** 32f948e

**2. [Rule 3 - Blocking] 01-05/01-06 exact-markup assertions made markup-tolerant**
- **Found during:** Task 4
- **Issue:** Some 01-06 tests asserted literal strings such as `<a href="X" aria-current="true">Free</a>`, `<a href="X">see everything coming up.</a>`, `<p class="price">…</p>`, `<p class="excerpt">` and `'<h3>'` (StatusVisibilityTest). The plan requires classes and an active-state check icon inside those exact elements, so those literal strings could no longer match.
- **Fix:** Added `assertLink()` to tests/Pest.php. It checks the same href, visible text and aria-current, and it is now stricter for inactive chips because it requires no aria-current. The price, excerpt and h3 regexes now accept extra classes. No assertion was removed or weakened in intent.
- **Files modified:** tests/Pest.php, tests/Feature/Public/{AttributeFiltersTest,CalendarFiltersTracerTest,DateFilterTest,OccurrenceCardTest,StatusVisibilityTest}.php
- **Commit:** 24d56d6

**3. [Rule 3 - Blocking] Local asset check served without Boost's dev browser-logger**
- **Found during:** Task 2 verify
- **Issue:** In `APP_ENV=local`, Laravel Boost (require-dev only, never installed on servers) injects `<script id="browser-logger-active">` into every HTML page, so the check failed with "/ contains a script element". `php artisan serve` strips non-allow-listed env vars from its child server, so `BOOST_BROWSER_LOGS_WATCHER=false` had no effect through it.
- **Fix:** No project change. The local check runs PHP's built-in server directly, which is what `artisan serve` wraps: `cd public && env APP_URL=http://localhost:8010 BOOST_BROWSER_LOGS_WATCHER=false php -S 127.0.0.1:8010 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`. I used port 8010 because the owner's own `artisan serve` was on 8000 and I left it alone. **For the owner's phone check:** the injected logger is first-party and dev-only and does not affect the visual check. To keep the local page script-free, add `BOOST_BROWSER_LOGS_WATCHER=false` to `.env` (Boost reads it from the app process).
- **Commit:** n/a (procedure only)

**4. [Rule 1 - Bug] "Built asset gates" grep count**
- **Found during:** Task 2 acceptance
- **Issue:** The success echo also contained "Built asset gates", which made the acceptance count 2.
- **Fix:** Changed the echo to "Asset gates passed".
- **Commit:** a0b82d7

### Other notes

- Tailwind scanning is limited with `@import 'tailwindcss' source(none)` plus `@source '../views/**/*.blade.php'`, as the plan's "views only" intent requires. Auto-detection would otherwise scan the whole repo, including `.planning/`. The built CSS is 12.6 kB, well under the 60000-byte gate.
- I used exact version pins for tailwindcss, @tailwindcss/vite and fontsource where the plan said `^4.3` / `^5.3.0`, following the orchestrator's "install with exact pins" instruction.
- The skeleton's `resources/js/bootstrap.js` and `resources/views/welcome.blade.php` did not exist (01-01 had already removed them), so only `resources/js/app.js` was deleted. That deletion is intended. `routes/web.php` has no reference to the welcome view.
- One acceptance grep is a plan defect: `grep -c "Nothing on today. Why not check the weekend?" empty-state.blade.php` returns 0. That sentence has always been split by the `<a>` tag in the source (it also returns 0 at baseline 755428a). The rendered text is verified verbatim by PublicLookTest and DateFilterTest.
- The heroicons LICENSE in blade-heroicons is Dries Vints' licence for the Blade package. HEROICONS-LICENSE.txt records the upstream Heroicons MIT licence (Tailwind Labs) and credits the Blade package. The chip check-mark is Heroicons `check`, covered by the same file.
- Pest reports `warnings: 1` with empty `warning_details` on every full run, both before and after this plan's changes. It is a run-level PHPUnit notice, not a test-level one. Out of scope.

## Verification (local equivalents of CI)

- `rm -rf node_modules public/build && npm ci --ignore-scripts && npm run build`: 98 packages, built in ~0.2 s
- Built asset gates (extracted from ci.yml and run locally): pass. Negative runs with an injected `.map`, an `unpkg.com` url() and a foreign url() each exit 1
- `actionlint` (docker rhysd/actionlint): clean. `shellcheck ops/render-brand-assets.sh ops/check-public-assets.sh` (docker koalaman/shellcheck): clean
- Task 4 grep gates (no default-palette classes, no outline-none, no case-change/clamp/ellipsis, no order/reverse): pass. No `{!!`, no `wire:/<script/<form` in public views
- `./vendor/bin/pest`: **335 passed** (baseline 305, +30). `vendor/bin/pint --test` passed. `vendor/bin/phpstan analyse` 0 errors
- `ops/check-public-assets.sh http://localhost:8010`: `PUBLIC ASSETS OK: css=/build/assets/app-D91_jxSs.css fonts=2 third-party=0` (also checks favicon.svg, favicon.ico, apple-touch-icon.png and og:image)
- `ops/render-brand-assets.sh`: `BRAND ASSETS OK` (images checked by eye: legible wordmark in Atkinson Hyperlegible Next, white glyph on #2E6B3F)
- **CI on GitHub:** not pushed by me (sequential mode). The orchestrator confirms the green run after pushing.

## Pending human check (end-of-phase, not blocking)

Task 4 `<human-check>`: run `npm run build`, then `php artisan serve --host 0.0.0.0 --port 8000`, and open `http://<laptop-LAN-IP>:8000/` on a phone, or use Chrome DevTools device emulation. Check light and dark mode, chips (This weekend / a category / Free), a cancelled card, the home-screen icon, Tab focus (the first stop is "Skip to main content") and a 320px width with a long unbroken title. Root-relative links mean chips and CSS work over the LAN IP. The WhatsApp preview needs a public URL and is checked in 05-02 Task 4.

## Known Stubs

None. Every card field comes from `OccurrenceCardData`, and the icon tile is wired to the real category slug.

## Threat Flags

None. No new endpoints, auth paths or schema. The plan's mitigations T-01-37, T-01-41, T-01-42, T-01-43, T-01-44 and T-01-SC are implemented and tested as listed above.

## Next Phase Readiness

- 01-08 can `@push('og', …)` event-specific og:title/description/url/image. The defaults disappear automatically, and canonical/robots go in `@stack('head')`.
- 01-10 adds its event-page JS entry as a second Vite input, loaded only on that page. The CI asset gates and the no-script check on `/` stay valid.
- 05-02 runs `ops/check-public-assets.sh https://whatsonattleborough.co.uk`. The immutable Cache-Control and strict `font/woff2` checks switch on automatically for https bases.

## Self-Check: PASSED
