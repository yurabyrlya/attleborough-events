---
phase: 02-draft-model-review-queue-submissions
plan: 10
subsystem: submissions
tags: [laravel, rate-limiting, sub-04, d-17, turnstile, ops-health]
status: complete

requires:
  - phase: 02-05
    provides: POST /submit (submit.store), config/review.php submission.per_hour / per_day, /privacy IP disclosure
  - phase: 02-09
    provides: services.turnstile.* config, StoreSubmissionRequest::formIsResting(), fakeTurnstilePass() / validSubmission() test helpers
provides:
  - "'submissions' named rate limiter (AppServiceProvider::configureRateLimiting): 5/hour + 10/day per IP"
  - throttle:submissions on POST /submit only
  - resources/views/errors/429.blade.php (branded, static-safe)
  - HealthReport::spamCheck() ('spam-check', on System health and ops:health)
  - D-17 exception bullet in AGENTS.md and .ai/guidelines/attleborough.md
  - Turnstile test keys in .env.example; TURNSTILE_* pinned empty in phpunit.xml
affects: [02-16, phase-05]

actuals:
  tokens: 4224
  tasks: 2
  commits: 3  # measured bead8c4..0315810; includes a177cc9, a concurrent docs(research) commit not made by this plan (2 plan commits)
plan_head_before: bead8c4178cdb6a5e0aad24fe309a90c86d6db18
plan_head_after: 03158105fbec38755afc226dd3bb15d18046492f

tech-stack:
  added: []
  patterns:
    - "Multiple Limit objects in one named limiter need distinct by() keys: ThrottleRequests keys on md5(limiterName . key), so identical keys share and double-hit one counter"
    - "Health checks that read config only (no heartbeat) use HealthCheck with null lastSeenAt/ageSeconds"
    - "phpunit.xml pins external-service env keys empty so the owner's .env can't change test behaviour"

key-files:
  created:
    - resources/views/errors/429.blade.php
    - tests/Feature/Submissions/RateLimitTest.php
  modified:
    - app/Providers/AppServiceProvider.php
    - routes/web.php
    - app/Ops/HealthReport.php
    - tests/Feature/Ops/HealthReportTest.php
    - AGENTS.md
    - .ai/guidelines/attleborough.md
    - .env.example
    - phpunit.xml

key-decisions:
  - "Limiter keys are ip:{address}:hour and ip:{address}:day: still the exact IP string, with a window suffix so the two limits keep separate counters"
  - "HealthReport::spamCheck() reuses StoreSubmissionRequest::formIsResting(), so System health and the form's resting mode can't disagree"
  - "The 429 page reads the email from config('site.contact_email') (default hello@whatsonattleborough.co.uk), like the thanks page, and uses no route()"
  - "phpunit.xml sets TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY / TURNSTILE_HOSTNAMES to empty, so tests behave the same whether or not the local .env has keys"

patterns-established:
  - "Rate-limit tests: withServerVariables(['REMOTE_ADDR' => ...]) per request; CACHE_STORE=array resets per test; travel() moves the limiter's clock"

requirements-completed: [SUB-04]

duration: 5min
completed: 2026-10-17
---

# Phase 02 Plan 10: Rate limit, D-17 rule text and spam-check health entry Summary

**POST /submit now allows 5 posts an hour and 10 a day from each IP. Laravel's atomic cache-backed limiter enforces this, and anyone over the limit sees a branded 429 page that offers the email route. The D-17 Turnstile exception is now written into both rule files, `.env.example` has Cloudflare's test keys, and System health shows Failed if production is running /submit without Turnstile keys.**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-10-17T20:12:04Z
- **Completed:** 2026-10-17T20:16:38Z
- **Tasks:** 2/2
- **Files modified:** 10 (2 created, 8 modified)

## Accomplishments

- **Rate limit (SUB-04, T-02-29).** The `submissions` limiter applies `Limit::perHour(5)` and `Limit::perDay(10)`, both keyed on the exact `$request->ip()`. It's attached to POST /submit only. GET /submit was loaded 20 times without a single 429. The limit counts every POST, valid or not, and runs before validation.
- **429 page.** Title "Too many submissions", heading "Too many submissions from here today", body "Please try again later, or email the details to hello@whatsonattleborough.co.uk." It extends `errors.layout` and uses no `route()`.
- **Privacy (T-02-30).** No `event_drafts` column holds an IP, and a draft's attributes never contain the submitter's address. The address exists only in the limiter cache, for up to 24 hours, which `/privacy` already says (02-05).
- **Spam-check health entry (T-02-31).** The entry is called "Submission spam check" and its expectation is "Turnstile keys are set in production". In production with either key missing it shows **Failed**, "Turnstile keys missing: the public form is resting", and `ops:health` reports UNHEALTHY with exit code 1. In production with both keys it shows OK. Outside production it shows OK, "Not checked outside production".
- **D-17 rule text.** The same bullet now sits directly under "No third-party scripts…" in both `AGENTS.md` and `.ai/guidelines/attleborough.md`. Running `diff` on the two passages shows no differences.
- **`.env.example`** has `TURNSTILE_SITE_KEY=1x00000000000000000000AA`, `TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA` (Cloudflare's documented always-pass test keys) and an empty `TURNSTILE_HOSTNAMES`. A comment says production uses real keys and `whatsonattleborough.co.uk`.

## Task Commits

1. **Task 1 (tracer): six POSTs → 429, another IP unaffected:** `ecb458e` (feat). Tracer gate: the automated verify was run again (6/6 RateLimitTest, then 358/358 across Submissions and Public) before moving to Task 2.
2. **Task 2: D-17 rule text, test keys, spam-check entry:** `0315810` (feat)

## Verification

- `./vendor/bin/pest --testsuite=Unit,Feature`: **867 passed** (4418 assertions), no failures
- RateLimitTest: 6 passed. HealthReportTest + EnvExampleTest: 20 passed.
- RED first for both tasks: RateLimitTest had 4 of 6 failing (302, not 429) before the limiter existed. HealthReportTest had 8 failing before `spamCheck()` existed.
- Pint passes; PHPStan reports 0 errors.
- Acceptance greps: `throttle:submissions` 1, `configureRateLimiting` 2, `Exception (D-17)` 1 in each rule file, `^TURNSTILE_SITE_KEY=` 1, `spam-check` 3.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Separate limiter keys for the hourly and daily limits**
- **Found during:** Task 1
- **Issue:** The plan gave both limits `->by('ip:'.$request->ip())`. Laravel's `ThrottleRequests` keys each limit as `md5($limiterName.$limit->key)`, so the hourly and daily limits would have shared one counter, and each request would have counted twice against it. The hourly limit would then have blocked the 4th post, and the daily window would have reset at the wrong time.
- **Fix:** `->by('ip:'.$ip.':hour')` and `->by('ip:'.$ip.':day')`. Each is still the exact IP string. The docblock explains why. The test "resets the hourly limit after an hour but still applies the daily limit of ten" covers it.
- **Files modified:** app/Providers/AppServiceProvider.php
- **Commit:** ecb458e

**2. [Rule 3 - Blocking] The local `.env`'s Turnstile keys leaked into tests**
- **Found during:** Task 1 (running the Submissions suite)
- **Issue:** The owner's `.env` now has `TURNSTILE_SITE_KEY`, and phpunit.xml didn't override it. SpamDefenceTest "renders the widget only when a site key is set" failed because the widget appeared without any key being set in the test. This failure came from the `.env` change, not from this plan's code, but it stopped the full suite passing.
- **Fix:** phpunit.xml now sets `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` and `TURNSTILE_HOSTNAMES` to empty, as it already does for `ADMIN_EMAIL`. Tests that need keys set them explicitly, for example with `fakeTurnstilePass()`.
- **Files modified:** phpunit.xml (not in the plan's `files_modified`)
- **Commit:** ecb458e

### Notes

- The 429 page gets the contact email from `config('site.contact_email')`, as `submit/thanks` does, instead of hard-coding it. The default value is the hello@ address the plan specifies, and that's what the test checks.
- `spamCheck()` calls `StoreSubmissionRequest::formIsResting()` rather than repeating the key check, so the health entry and the form use one rule.
- The protected-branch check flagged `main`. The commits still went to `main` on purpose: `git.branching_strategy` is `none`, the orchestrator asked for sequential mode on the main working tree, and 02-01 to 02-09 did the same.
- `actuals.commits` is 3 because a concurrent `docs(research)` commit (`a177cc9`) landed during the run. This plan made 2 commits.

## Issues Encountered

None blocking.

## Known Stubs

None.

## Threat Flags

None. The limiter, the 429 page and the health entry are all in the plan's threat model (T-02-29 to T-02-31). No new endpoints or outbound calls.

## User Setup / Follow-ups

- **Phase 5 (trusted proxies):** behind a reverse proxy or Cloudflare, `$request->ip()` returns the proxy's address until trusted proxies are configured. Every visitor would then share one 5/hour limit. This is already a STATE.md concern.
- **Production:** set real `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` and `TURNSTILE_HOSTNAMES=whatsonattleborough.co.uk`. Until then, System health shows "Submission spam check: Failed".

## Next Phase Readiness

- 02-16 browser tests can rely on the 429 page and the limiter, which is cleared between tests by the array cache.
- SUB-04 is complete: honeypot, timing token and Turnstile (02-09), plus the rate limit (02-10).

## Self-Check: PASSED

- Created files exist: resources/views/errors/429.blade.php, tests/Feature/Submissions/RateLimitTest.php
- Commits ecb458e and 0315810 are ancestors of HEAD
