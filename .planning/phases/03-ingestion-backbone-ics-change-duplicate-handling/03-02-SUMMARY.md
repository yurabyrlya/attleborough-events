---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 02
subsystem: ingestion
tags: [http-client, ssrf, guzzle, curl, dns-pinning, ics, polite-crawler, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-01 source_runs failure_kind CHECK values (FetchFailureKind mirrors them)"
provides:
  - "App\\Ingestion\\Http\\HttpFetcher::fetch(FetchRequest): FetchResult — the single polite fetch path"
  - "protected HttpFetcher::beforeHop(url, request): ?FetchResult — per-hop extension point for 03-03 robots + host throttle"
  - "SsrfGuard (URL policy, every-address-public check, CURLOPT_RESOLVE pin builder)"
  - "HostResolver interface; DnsHostResolver bound in AppServiceProvider"
  - "BodyValidator::validate(method, body): ?string (ICS)"
  - "CappedSink: decoded-byte cap on the curl transfer"
  - "FetchFailureKind enum (values = source_runs_failure_kind_check)"
  - "config/ingest.php (UA, contact URL, timeouts, deadline, max_bytes, redirects, host delay, thresholds, duplicate, retention, jitter, preview reuse)"
  - "Pest helpers: fake HostResolver in Feature/Unit/Browser beforeEach, ingestFakeResolver(), ingestResolverFake(), sourceFixture()"
affects: [03-03 robots/throttle, 03-04 extractor, 03-05 runs, 03-06 jobs, 03-07 health/preview]

actuals:
  tokens: 14780
  tasks: 2
  commits: 3
plan_head_before: d32306fc80409e2fa3d7cb52c537828a00ef801b
plan_head_after: f88b0d13e4aab8df41b5bc67f532c44d947c0671

tech-stack:
  added: []
  patterns:
    - "Explicit Guzzle CurlHandler per request (setHandler) so CURLOPT_RESOLVE is honoured; never `stream => true`"
    - "Decoded-byte cap enforced twice: a php://temp sink with a counting write filter (production, aborts curl) and a 64 KiB chunked read of the PSR body (also under Http::fake)"
    - "Manual redirect loop returning a RedirectTo value; every hop re-runs URL policy + SSRF guard"
    - "Failure messages built only from enum labels + status codes (never response text)"
    - "Overall fetch deadline from an injectable FetchRequest::startedAt; per-request timeout = min(config, time left)"

key-files:
  created:
    - config/ingest.php
    - app/Enums/FetchFailureKind.php
    - app/Ingestion/Http/HttpFetcher.php
    - app/Ingestion/Http/FetchRequest.php
    - app/Ingestion/Http/FetchResult.php
    - app/Ingestion/Http/RedirectTo.php
    - app/Ingestion/Http/SsrfGuard.php
    - app/Ingestion/Http/HostResolver.php
    - app/Ingestion/Http/DnsHostResolver.php
    - app/Ingestion/Http/BodyValidator.php
    - app/Ingestion/Http/CappedSink.php
    - app/Ingestion/Http/CappedSinkFilter.php
    - tests/Unit/Ingestion/FetchPolicyTest.php
    - tests/Feature/Ingestion/HttpFetcherTest.php
  modified:
    - .env.example
    - app/Providers/AppServiceProvider.php
    - tests/Pest.php

key-decisions:
  - "Transport is an explicit CurlHandler with a CappedSink, not `stream => true`. Guzzle 8 routes streaming requests to its PHP-stream handler, which ignores curl options, so the CURLOPT_RESOLVE DNS pin would have been silently dropped in production"
  - "BodyValidator accepts any body that starts (after BOM/whitespace) with BEGIN:VCALENDAR, even if it contains '<html' later (Outlook X-ALT-DESC). Markup and challenge markers are only diagnosed in bodies that are not calendars"
  - "contact_url = env('CRAWLER_CONTACT_URL') ?: APP_URL/about-crawler. Using ?: means the empty value in .env.example still falls back to the placeholder"
  - "FetchRequest gains an optional startedAt so the job can count time already spent against fetch_deadline (55 s)"
  - "Transport catches only HttpClientException|TransferException (plus RuntimeException while reading the body). Programming errors such as a rejected Guzzle option still throw"
  - "An on_headers callback rejects a declared Content-Length above max_bytes before the body downloads (production). Under fakes the same check runs on the response headers"
  - "Guzzle's 'protocols' => ['http','https'] request option replaces CURLOPT_PROTOCOLS, which Guzzle 8 rejects"

patterns-established:
  - "Ingestion HTTP: every outbound source request goes through HttpFetcher; no other Http:: call to source URLs"
  - "Tests never perform DNS: suites bind a fake HostResolver next to Http::preventStrayRequests()"

requirements-completed: []

coverage:
  - id: D1
    description: "Happy path: Baptist ICS fetched with the bot UA and an Accept header listing text/calendar; ok result with body, bytes and finalUrl"
    requirement: SRC-05
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/HttpFetcherTest.php#fetches the Baptist Church ICS feed with the bot User-Agent and returns an ok result"
        status: pass
  - id: D2
    description: "Bot wall is content_invalid; body kept; no retry"
    requirement: SRC-06
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/HttpFetcherTest.php#records a bot challenge page as content_invalid and keeps its body for storage"
        status: pass
  - id: D3
    description: "SSRF: 15 non-public addresses, any private resolved address, second-hop redirect to metadata IP, URL policy"
    requirement: SRC-05
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/FetchPolicyTest.php#treats non-public addresses as not public"
        status: pass
      - kind: integration
        ref: "tests/Feature/Ingestion/HttpFetcherTest.php#re-checks every redirect hop and blocks a redirect to the metadata address"
        status: pass
  - id: D4
    description: "SRC-06 boundary and precision: exactly max_bytes ok, +1 too_large, bytes not characters, Content-Length pre-check"
    requirement: SRC-06
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/HttpFetcherTest.php#accepts a decoded body of exactly max_bytes"
        status: pass
      - kind: integration
        ref: "tests/Feature/Ingestion/HttpFetcherTest.php#fails a decoded body of max_bytes + 1 as too_large"
        status: pass
      - kind: integration
        ref: "tests/Feature/Ingestion/HttpFetcherTest.php#counts the size in bytes, not characters"
        status: pass
  - id: D5
    description: "Conditional GET, 304, Retry-After (seconds/date/cap), deadline shrinking and exhaustion"
    requirement: SRC-05
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/HttpFetcherTest.php#shrinks the request timeout to the time left of the fetch deadline"
        status: pass
      - kind: integration
        ref: "tests/Feature/Ingestion/HttpFetcherTest.php#caps Retry-After at 24 hours and ignores an unreadable one"
        status: pass

duration: ~40min active (wall clock spans an idle gap)
completed: 2026-10-18
---

# Phase 3 Plan 02: Polite HttpFetcher Core Summary

**All source requests now go through one fetch path, `HttpFetcher`. It sends the honest `AttleboroughEventsBot/1.0` User-Agent and refuses non-public addresses on every redirect hop. curl connects only to the address that passed the check (CURLOPT_RESOLVE pin). Bodies are capped at 5 MiB counted after decompression, and the overall deadline is 55 s. Conditional GET and Retry-After are handled, and every body is validated as ICS. Every outcome comes back as a typed FetchResult; source problems never throw.**

## Performance

- **Duration:** about 40 min of active work. The wall clock ran from 00:38Z to 08:19Z on 2026-10-18 because of an idle gap.
- **Tasks:** 2 (1 tracer + 1 TDD)
- **Files:** 14 created, 3 modified

## Accomplishments
- `HttpFetcher::fetch()` runs these steps on each hop:
  1. URL policy (http/https only, ports 80/443, no userinfo).
  2. Resolution through `HostResolver`. Every A/AAAA address must be public, otherwise `ssrf_blocked`. A host that does not resolve fails as network "Host could not be resolved".
  3. `beforeHop()` extension point, for robots and the host throttle in 03-03.
  4. A deadline check.
  5. The GET, with the UA, `Accept: text/calendar, …`, conditional headers, timeouts of min(10 s / 30 s, time left), `withoutRedirecting()`, the curl resolve pin and the capped sink.
  6. Classification: manual 3xx (max 5, relative Location resolved with league/uri), 304 → not_modified, 429/503 → rate_limited with Retry-After (seconds or HTTP date, capped at 86 400), other ≥ 400 → http_status, and BodyValidator for 2xx.
- `SsrfGuard::isPublic` uses FILTER_FLAG_GLOBAL_RANGE plus explicit multicast and 64:ff9b::/96 blocks. All 15 table addresses are refused and both public addresses are accepted.
- `tests/Pest.php` binds a fake HostResolver (every host → 93.184.215.14) in the Feature, Unit and Browser beforeEach. `ingestFakeResolver($map)` overrides it per test and `sourceFixture()` reads the captured fixtures.
- **Real transport check (ad hoc, not committed).** I ran a local `php -S` server and pointed a fake host `feed.test` at 127.0.0.1 through the pin, with SSRF relaxed in the script only. Results:
  - ok fetch and the UA arrived (the pin works: the Host header was `feed.test` and the connection went to 127.0.0.1);
  - a 20 MiB gzip bomb was aborted as `too_large`;
  - `Content-Length: 999999999` was refused before download;
  - a slow endpoint became `timeout`.
  
  This check found two production bugs that Http::fake could not show (see Deviations).

## Task Commits

1. **Task 1 (tracer): HttpFetcher fetches the Baptist ICS URL (faked) with the bot UA, validates the body and returns ok** - `3f8fa97` (feat)
2. **Task 2: Fetch policy edges (SSRF table, redirects, size boundary, conditional GET, Retry-After, deadline) + DnsHostResolver** - `0932028` (feat)
3. Acceptance tidy-up: CRAWLER_CONTACT_URL now appears once per file - `f88b0d1` (docs)

## Files Created/Modified
- `config/ingest.php` - every ingest knob. There is no window key; the window is `calendar.horizon_days` (D-20).
- `.env.example` - `CRAWLER_CONTACT_URL=` (empty, which falls back to APP_URL/about-crawler)
- `app/Enums/FetchFailureKind.php` - 9 kinds, matching the source_runs CHECK, with labels
- `app/Ingestion/Http/HttpFetcher.php` - the fetch loop, transport, classification, Retry-After and deadline
- `app/Ingestion/Http/{FetchRequest,FetchResult,RedirectTo}.php` - DTOs (named constructors, `withDuration()`)
- `app/Ingestion/Http/{SsrfGuard,HostResolver,DnsHostResolver}.php` - the SSRF guard and resolvers
- `app/Ingestion/Http/BodyValidator.php` - ICS validation with specific reasons
- `app/Ingestion/Http/{CappedSink,CappedSinkFilter}.php` - the decoded-byte cap on the curl sink
- `app/Providers/AppServiceProvider.php` - binds HostResolver to DnsHostResolver
- `tests/Pest.php` - fake resolver in all 3 suites, plus helpers
- `tests/Unit/Ingestion/FetchPolicyTest.php` - address table, URL policy, pin builder, validator, sink, production binding
- `tests/Feature/Ingestion/HttpFetcherTest.php` - 34 tests (with datasets) covering the fetch behaviour

## Decisions Made
See `key-decisions` in the frontmatter. The most important is the transport choice (next section).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug / T-03-04] `stream => true` would have silently dropped the DNS pin**
- **Found during:** Task 1, while reading Guzzle 8's `Utils::chooseHandler()`.
- **Issue:** With `allow_url_fopen` on, Guzzle wraps curl in `Proxy::wrapStreaming`, so any request with `stream => true` goes to `StreamHandler`, which ignores `curl` options, CURLOPT_RESOLVE included. The plan's `withOptions(['stream' => true, 'curl' => [CURLOPT_RESOLVE…]])` would leave DNS rebinding unmitigated in production, and the tests would still pass (Pitfall 9).
- **Fix:** `setHandler(new CurlHandler)` per request, no `stream`. The decoded body goes to a `CappedSink`: a php://temp resource with a counting write filter. It fails the write at max_bytes + 1, which makes curl abort, so a huge or bomb body never fills the disk. The PSR body is then read back in 64 KiB chunks with the same cap. I kept a resource rather than a PSR stream object because Laravel's fake also writes fake bodies into resource sinks.
- **Verified:** with the ad-hoc localhost probe (pinned connect, 20 MiB gzip bomb aborted) and the size tests.
- **Commit:** `3f8fa97`

**2. [Rule 1 - Bug] `CURLOPT_PROTOCOLS` is rejected by Guzzle 8, and the broad catch hid it**
- **Found during:** Task 1, in the real-transport probe. Every real fetch returned "network" while all faked tests passed.
- **Fix:** use Guzzle's `'protocols' => ['http','https']` option. Narrow the transport catch to `HttpClientException|TransferException` (plus `RuntimeException` while reading the body), so programming errors throw instead of posing as network failures.
- **Commit:** `3f8fa97`

**3. [Rule 2 - Correctness] Content-Length is refused before download in production**
- An `on_headers` callback aborts a declared oversize body before any body bytes arrive. The plan's post-response check only works once curl has already downloaded the body.
- **Commit:** `3f8fa97`

**4. [Rule 1 - Bug] An empty `CRAWLER_CONTACT_URL=` would have made the UA contact empty**
- `env('X', default)` returns `''` for an empty value, so the config uses `env(...) ?: placeholder`.
- **Commit:** `3f8fa97`

**5. PHPStan false positive worked around without suppression**
- PHPStan's filter_var extension types `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)` as never false, which would make the refusal branch "dead". I passed the flag through `globalRangeFlags(): int`, with a comment explaining why. No ignore and no baseline.

### Judgement calls (unattended run)
- **RED order:** Task 1 is a tracer, not a TDD task, and its implementation was written before the test. For Task 2 (tdd) the tests were written first. Only the DnsHostResolver/binding test was RED, because Task 1 had already built the policy edges. The other Task 2 tests are characterisation tests that lock the behaviour in.
- **BodyValidator and `<html` inside a calendar:** the body is valid when it starts with BEGIN:VCALENDAR (tested with an X-ALT-DESC HTML case). The plan's "containing `<html` is invalid" applies to bodies that are not calendars.
- **Extra files beyond the plan list:** `RedirectTo.php`, `CappedSink.php` and `CappedSinkFilter.php`, all small internals required by fixes 1-2.
- **Commits on main:** as the orchestrator instructed; branching_strategy is none.
- **Requirements:** SRC-05 and SRC-06 are left **Pending**. Robots and throttling (03-03) and run status recording (03-05) are still to come. `requirements-completed: []`.

**Total deviations:** 5 auto-fixed, plus the judgement calls above. **Impact:** these deliver the threat-model mitigations (T-03-04, T-03-05) in the real transport as well as under fakes. No scope change.

## Known Stubs
- `config/ingest.php` `contact_url` falls back to `APP_URL/about-crawler`. This placeholder is intentional, per the plan and STATE.md (Phase 3 note). Phase 5 publishes the page and production sets `CRAWLER_CONTACT_URL`. It does not block this plan's goal. (No `.planning/WINDOWS.md` ledger exists in this project, so there was nothing to append to.)

## Threat Flags
None. The outbound HTTP surface is the planned one (T-03-03..06), and its mitigations are implemented.

## Issues Encountered
- The full suite reports one PHPUnit warning that predates this plan; the ingestion tests produce none.

## User Setup Required
None. Production should set `CRAWLER_CONTACT_URL` once the Phase 5 page exists; this is already tracked in STATE.md.

## Next Phase Readiness
- 03-03 fills `HttpFetcher::beforeHop()` (robots → `FetchResult::blocked()`, `HostThrottle::reserve()` plus `Sleep`). It can reuse `SsrfGuard` for robots.txt. `send()` is private today; 03-03 may make it protected if robots.txt must go through the same pinned transport.
- `FetchResult` carries everything 03-05 needs for run rows: failureKind, httpStatus, bytes, durationMs, retryAfterSeconds, etag/lastModified, and the body (kept on content_invalid).
- Full Unit+Feature suite: 1106 passed. PHPStan: 0 errors. Pint: clean.

## Self-Check: PASSED
- All 14 created and 3 modified files exist. Commits `3f8fa97`, `0932028` and `f88b0d1` are ancestors of HEAD.
