---
phase: 02-draft-model-review-queue-submissions
plan: 09
subsystem: submissions
tags: [laravel, spam, honeypot, turnstile, sub-04, sub-03, d-17, d-18]
status: complete

requires:
  - phase: 02-05
    provides: /submit form, StoreSubmissionRequest, SubmissionController (PRG), validSubmission() test helper, config/review.php submission.min_seconds / max_age_minutes
provides:
  - App\Submissions\TurnstileVerifier (not final) — verify(?string $token, string $ip): list<string> (empty = pass), SITEVERIFY_URL, MAX_TOKEN_LENGTH = 2048
  - App\Submissions\Honeypot (final, static) — FIELD 'fax_number', TOKEN 'form_token', mintToken(), check(array): ?string (trap|missing|too_fast|too_old|reused), markUsed(string): bool
  - <x-submit.honeypot /> component + .hp-field { display: none } in app.css base layer
  - StoreSubmissionRequest::formIsResting() (production && missing site key or secret) and isBot()
  - config services.turnstile (site_key, secret, action 'submit', hostnames list from TURNSTILE_HOSTNAMES)
  - tests/Pest.php fakeTurnstilePass() and mintTokenSecondsAgo(int); validSubmission() now carries fax_number '', a token minted 5 s ago and cf-turnstile-response 'tok'
affects: [02-10, 02-16]

actuals:
  tokens: 13357
  tasks: 3
  commits: 4  # measured 40e2a26..1eb2bbf; includes 0ca330d, a concurrent docs(research) commit not made by this plan (3 plan commits)
plan_head_before: 40e2a26e38803c5e8dbdfdf89129fc68a8e0d6e4
plan_head_after: 1eb2bbfcac7660a29aff7af4dfb542fd69e65778

tech-stack:
  added: []
  patterns:
    - "Spam checks in FormRequest::after(): resting → bot (silent) → honeypot/timing error on 'form' → (field rules already ran) → Turnstile only when the error bag is empty → Honeypot::markUsed() last"
    - "Bot path: prepareForValidation() records Honeypot::check(); rules() returns [] for a filled trap so field errors never signal the bot; controller redirects to thanks with submitted=true and no reference"
    - "Fail-closed outbound check: Http::asForm()->acceptJson()->timeout(5)->post(...)->throw(); any Throwable or non-array body → 'internal-error'"
    - "Production-env feature tests: set $this->app['env'] = 'production' and withoutMiddleware(PreventRequestForgery::class) (CSRF is only auto-skipped in testing)"

key-files:
  created:
    - app/Submissions/TurnstileVerifier.php
    - app/Submissions/Honeypot.php
    - resources/views/components/submit/honeypot.blade.php
    - tests/Feature/Submissions/SpamDefenceTest.php
    - tests/Feature/Submissions/TurnstileVerifierTest.php
  modified:
    - app/Http/Requests/StoreSubmissionRequest.php
    - app/Http/Controllers/SubmissionController.php
    - resources/views/submit/create.blade.php
    - resources/css/app.css
    - config/services.php
    - tests/Pest.php
    - tests/Feature/Submissions/SubmitEventTracerTest.php
    - tests/Feature/Submissions/SubmitValidationTest.php
    - tests/Feature/Public/NoThirdPartyAssetsTest.php

key-decisions:
  - "validSubmission() backdates its timing token by 5 s (mintTokenSecondsAgo restores the frozen clock exactly) because the inline post('/submit', validSubmission()) leaves no moment to travel between minting and posting; boundary tests mint with Honeypot::mintToken() and travel explicitly"
  - "A filled honeypot skips the field rules entirely (rules() returns []), so a bot with invalid fields still gets the plain thank-you and learns nothing"
  - "All visible spam refusals (missing/tampered token, too fast, too old, reused, Turnstile, resting) go on the 'form' error key, linked from the error summary to #form-check beside the submit button"
  - "The form always mints a fresh token on render, so after an error the person can resend; the browser re-posting an already-sent form (same token) is refused"
  - "Refusal logs are Log::info('submission refused', ['reason' => key]) only: trap, missing, too_fast, too_old, reused, turnstile, resting — no input, no IP"
  - "Resting = production with an empty site key OR secret; outside production a missing secret still fails closed through the verifier ('couldn't confirm you're not a robot')"

patterns-established:
  - "Turnstile script and cf-turnstile widget are pushed from submit/create.blade.php only, never the layout, and only when services.turnstile.site_key is set"
  - "Spam messages: 'Please reload the page and try again.' / 'That was quick! Please check the details and send again.' / 'This form has been open a while. Please send it again.' / 'This form was already sent. Reload the page to add another event.' / \"We couldn't confirm you're not a robot. Please try again, or email {contact}\" / 'The form is resting. Please email the details to {contact}'"

requirements-completed: []

duration: 8min
completed: 2026-10-17
---

# Phase 02 Plan 09: Honeypot, timing token and Turnstile Summary

**/submit now refuses bots three ways, all tested offline: a honeypot field that must be present and empty, an encrypted single-use timing token (3 s to 120 min), and Cloudflare Turnstile checked server-side by our own fail-closed client. Turnstile's script loads on /submit only, and only when a site key is set.**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-10-17T19:59:02Z
- **Completed:** 2026-10-17T20:06Z
- **Tasks:** 3/3
- **Files modified:** 14 (5 created, 9 modified)

## Accomplishments

- **Honeypot** (`fax_number`, hidden by `.hp-field`, `aria-hidden`, `tabindex="-1"`). Filled → redirect to the thanks page with no reference, nothing written, one log line with the reason only. Missing → "Please reload the page and try again."
- **Timing token** (encrypted `{t, n}` with a random nonce). Refused at 2 s, accepted at 3 s. Accepted at 120 min, refused at 121. Tampered tokens are refused. It's marked used (`Cache::add`) only after every check passes, so a re-posted form is refused ("This form was already sent…") and leaves one draft, while a validation error doesn't burn the token.
- **TurnstileVerifier.** Sends secret, response, remoteip and idempotency_key as a form post with a 5 s timeout. It checks `success === true`, `action === 'submit'` exactly, and the hostname when a hostname list is set. It refuses (fails closed) on a missing secret, an empty or over-2048-character token (no request sent), a connection failure, a 5xx or non-JSON answer, and any mismatch.
- **Check order** is honeypot/timing → field rules → Turnstile (only if nothing has failed, so a field error never costs a Cloudflare call) → mark the token used.
- **Production resting mode:** if the site key or secret is missing, /submit shows "The form is resting…" and a POST creates nothing.
- **D-18:** a `<noscript>` notice above the button gives the hello@ mailto route.
- **D-17 guard:** /, /privacy and /submit/thanks load nothing third-party, even with a site key set. On /submit the only external URL is `https://challenges.cloudflare.com/turnstile/v0/api.js`, with one `div.cf-turnstile[data-action="submit"]`. The original four guard tests are unchanged.

## Task Commits

1. **Task 1 (tracer): honeypot + timing token + Turnstile end to end:** `3b0e796` (feat). Tracer gate: the automated verify was re-run and all 72 submission tests passed before expanding.
2. **Task 2: honeypot, token and verifier cases:** `cb36a83` (test)
3. **Task 3: third-party guard allows only Turnstile on /submit:** `1eb2bbf` (test)

## Verification

- `./vendor/bin/pest --testsuite=Unit,Feature`: **851 passed** (4340 assertions, 1 warning with no details reported; no failures)
- SpamDefenceTest + TurnstileVerifierTest: 45 passed. NoThirdPartyAssetsTest: 9 passed.
- Pint passes; PHPStan reports 0 errors.
- Mutation check (because Task 2's tests went green against the Task 1 code): making `<` into `<=` in the timing check, dropping the presence check, dropping the hostname-list guard, and removing the "no Cloudflare call on errors" guard each made tests fail (1, 1, 9 and 8 failures).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] The test helper backdates the token instead of tests travelling after minting**
- **Found during:** Task 1
- **Issue:** The plan said tests should `travel(5)->seconds()` after minting, in `beforeEach`. But `validSubmission()` is called inline inside `post(...)`, so its token is minted after `beforeEach` and posted at the same instant. Every existing test would have failed with "That was quick!".
- **Fix:** `mintTokenSecondsAgo(int)` mints the token 5 s before the current (frozen) time and then restores the clock exactly. `validSubmission()` uses it. Boundary tests mint with `Honeypot::mintToken()` and travel explicitly.
- **Files modified:** tests/Pest.php
- **Commit:** 3b0e796

**2. [Rule 3 - Blocking] CSRF in the production-environment tests**
- **Found during:** Task 2
- **Issue:** Setting `app['env'] = 'production'` turns CSRF checking back on (Laravel 13's `PreventRequestForgery`), so resting-mode POSTs got 419.
- **Fix:** `withoutMiddleware(PreventRequestForgery::class)` in the resting-mode `describe` block only.
- **Files modified:** tests/Feature/Submissions/SpamDefenceTest.php
- **Commit:** cb36a83

### Notes

- The plan put the honeypot's error messages on `form` but didn't say where `form` errors appear. They now show in `#form-check` next to the button (the noscript notice and widget are there too), and the error summary links to it. This is covered by a test.
- Task 2's RED step: most cases passed straight away because the Task 1 tracer already built the behaviour. The mutation checks above show the tests can still fail.
- Commits went to `main`. `git.branching_strategy` is `none` and the orchestrator asked for sequential mode on the main working tree, as with 02-01 to 02-08.

## Issues Encountered

None blocking.

## Known Stubs

None.

## Threat Flags

None. The only new outbound call (siteverify) and the public form surface are in the plan's threat model (T-02-24 to T-02-28).

## User Setup / Follow-ups

- **02-10 owns** the `.env.example` keys (`TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` with Cloudflare's test keys, `TURNSTILE_HOSTNAMES`), the AGENTS.md / `.ai/guidelines/attleborough.md` D-17 rule text, the rate limit and the `ops:health` Turnstile check. Until then AGENTS.md still says "No third-party scripts". Turnstile on /submit is the owner-approved D-17 exception, and the guard test enforces it.
- **Local dev:** without `TURNSTILE_SECRET_KEY` in `.env`, local submissions fail closed with "couldn't confirm you're not a robot". Add Cloudflare's test keys (site `1x00000000000000000000AA`, secret `1x0000000000000000000000000000000AA`) to the local `.env` to submit on 127.0.0.1:8000.
- **SUB-04 stays Pending** in REQUIREMENTS.md until 02-10 adds per-IP rate limiting.

## Next Phase Readiness

- 02-10 can key the limiter in front of `store()`. The request's refusal reasons and `formIsResting()` are ready for `HealthReport`.
- 02-16 browser tests can bind a passing `TurnstileVerifier` subclass with `app()->instance()`. The class is not final.

## Self-Check: PASSED

- All 5 created files exist; commits 3b0e796, cb36a83, 1eb2bbf are ancestors of HEAD.
