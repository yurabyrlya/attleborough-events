---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
plan: 03
subsystem: ingestion
tags: [robots-txt, rfc9309, throttle, crawl-delay, row-lock, raw-storage, polite-crawler, pest]
status: complete

requires:
  - phase: 03-ingestion-backbone-ics-change-duplicate-handling
    provides: "03-02 HttpFetcher (beforeHop extension point, SsrfGuard, pinned curl transport, CappedSink, fetch deadline)"
provides:
  - "crawl_hosts table: per-host slot (last_request_at, next_allowed_at), robots.txt cache (status, body, fetched_at), crawl_delay_seconds"
  - "App\\Ingestion\\Http\\RobotsRules: RFC 9309 parser/matcher (parse, allows, crawlDelay)"
  - "App\\Ingestion\\Http\\RobotsPolicy::check(url, Closure): RobotsVerdict, with 24 h cache (1 h for disallow_all)"
  - "App\\Ingestion\\Http\\HostThrottle::reserve(host, delay, maxWait) and HostThrottle::keyFor(url)"
  - "App\\Ingestion\\Http\\HostBusy (host, seconds): thrown instead of a long sleep; the job releases itself (03-05)"
  - "HttpFetcher: robots + throttle on every hop; robots.txt fetched through the same SSRF guard, pinned transport, throttle, 5 redirects and byte cap"
  - "App\\Ingestion\\RawBodyStore: put/get/pathFor/prune for storage/app/private/ingest/raw/{source_id}/{run_id}.gz"
affects: [03-05 runs (HostBusy release, raw_path), 03-06 jobs/prune command, 03-07 health/preview]

actuals:
  tokens: 18400
  tasks: 2
  commits: 2
plan_head_before: 49e09d528e50e1285fefd2e5d8e708360e9fcf63
plan_head_after: a74753947f560e36a20c5c6997e1014d8db9c2f5

tech-stack:
  added: []
  patterns:
    - "Spacing is measured from the host's last reserved request (last_request_at + delay), so a Crawl-delay learned from robots.txt applies to the very next request"
    - "Refused reservations book nothing: HostBusy leaves the host's schedule untouched, so a released job does not push itself further back on retry"
    - "Robots wildcards matched by a linear two-pointer walk, not a regex compiled from remote text"
    - "Tests that do not test robots pre-seed an allow_all crawl_hosts row and use Sleep::fake(syncWithCarbon: true)"

key-files:
  created:
    - database/migrations/2026_10_18_100500_create_crawl_hosts_table.php
    - app/Ingestion/Http/RobotsRules.php
    - app/Ingestion/Http/RobotsPolicy.php
    - app/Ingestion/Http/RobotsFetch.php
    - app/Ingestion/Http/RobotsVerdict.php
    - app/Ingestion/Http/HostThrottle.php
    - app/Ingestion/Http/HostBusy.php
    - app/Ingestion/RawBodyStore.php
    - tests/Unit/Ingestion/RobotsRulesTest.php
    - tests/Feature/Ingestion/RobotsPolicyTest.php
    - tests/Feature/Ingestion/HostThrottleTest.php
    - tests/Feature/Ingestion/RawBodyStoreTest.php
  modified:
    - app/Ingestion/Http/HttpFetcher.php
    - tests/Feature/Ingestion/HttpFetcherTest.php

key-decisions:
  - "crawl_hosts gets an extra last_request_at column. Spacing is max(now, next_allowed_at, last_request_at + delay). With next_allowed_at alone, robots.txt (booked at the 5 s minimum before its Crawl-delay is known) would space the feed request by 5 s, not 10 s, and a Crawl-delay of 12000 would never trigger HostBusy"
  - "HostThrottle::reserve takes maxWait and throws HostBusy itself, without booking the slot. Booking first (the RESEARCH snippet) would push the host back by a full delay on every refused attempt"
  - "The host's stored Crawl-delay is also applied inside reserve(), so robots.txt refreshes respect it too"
  - "Inline-wait limit = min(ingest.max_inline_wait, floor(seconds left of fetch_deadline - ingest.timeout)). A zero wait is always allowed"
  - "disallow_all (5xx, network failure, too many redirects, refused redirect target, oversize body) is cached for 1 h, not 24 h, so a short outage does not stop a source for a day. rules, allow_all and blocked are cached for 24 h"
  - "A spent fetch deadline returns a timeout result before robots.txt is consulted. Otherwise it would pose as an unreachable robots.txt and cache disallow_all for the host"
  - "robots.txt uses the normal 5 MiB transfer cap and RobotsRules parses the first 512 KiB. A robots.txt over 5 MiB is treated as unreachable (disallow_all)"
  - "Matching uses path plus '?query' (the plan's '/*.ics$ blocks /x.ics but not /x.ics?y=1' case). User-agent values are cut at the first '/' or space, so 'AttleboroughEventsBot/1.0' still names the bot"
  - "The robots cache key is the host (plus a non-default port), shared by http and https, as the plan's schema specifies"

patterns-established:
  - "Every outbound request, robots.txt included, reserves a crawl_hosts slot first"
  - "HttpFetcher::fetch() can throw HostBusy; it is the only exception for an expected condition"

requirements-completed: []

coverage:
  - id: D1
    description: "Tracer: Baptist robots (Crawl-delay 10, Allow calendar, Disallow /Admin/) read first, cached, feed spaced by 10 s, disallowed URL blocked and never sent"
    requirement: SRC-05
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/RobotsPolicyTest.php#reads robots.txt first, caches it and spaces the feed request by its 10-second Crawl-delay"
        status: pass
      - kind: integration
        ref: "tests/Feature/Ingestion/RobotsPolicyTest.php#returns blocked for a disallowed URL and never requests it"
        status: pass
  - id: D2
    description: "SRC-05 adjacency: equal-length Allow beats Disallow; longest match wins"
    requirement: SRC-05
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/RobotsRulesTest.php#lets an Allow win over a Disallow of the same length"
        status: pass
  - id: D3
    description: "SRC-05 empty: empty body, empty Disallow, group without rules, and robots 404 allow every path"
    requirement: SRC-05
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/RobotsRulesTest.php#allows everything for an empty body, an empty Disallow or a group without rules"
        status: pass
      - kind: integration
        ref: "tests/Feature/Ingestion/RobotsPolicyTest.php#maps the robots.txt response to a cached status"
        status: pass
  - id: D4
    description: "SRC-05 ordering: reversed and shuffled rule order, and reordered groups, give identical answers"
    requirement: SRC-05
    verification:
      - kind: unit
        ref: "tests/Unit/Ingestion/RobotsRulesTest.php#gives the same answers whatever the order of rules and groups"
        status: pass
  - id: D5
    description: "Robots on redirect targets on another host; robots.txt redirects re-guarded"
    requirement: SRC-05
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/RobotsPolicyTest.php#checks robots.txt of the redirect target host before following a redirect"
        status: pass
  - id: D6
    description: "Throttle: 0/5/10 s spacing under FOR UPDATE, HostBusy(12000) for Primary's Crawl-delay, HostBusy near the deadline"
    requirement: SRC-05
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/HostThrottleTest.php#throws HostBusy for a 12000-second Crawl-delay instead of sleeping"
        status: pass
  - id: D7
    description: "RawBodyStore gzip round trip, integer-id paths, prune by age"
    requirement: SRC-05
    verification:
      - kind: integration
        ref: "tests/Feature/Ingestion/RawBodyStoreTest.php#prunes only bodies older than the retention period"
        status: pass

duration: ~25min
completed: 2026-10-18
---

# Phase 3 Plan 03: Robots.txt, Host Throttle and Raw Body Store Summary

**Every fetch hop now does three things before its GET. It reads the host's robots.txt with an in-house RFC 9309 matcher; the file is fetched through the same SSRF-guarded, address-pinned curl transport and cached in crawl_hosts for up to 24 h. It refuses disallowed URLs as `blocked`, redirect targets on other hosts included. It reserves a per-host slot under a row lock, spaced by max(5 s, Crawl-delay). Waits over 20 s, or waits that would run past the fetch deadline, throw `HostBusy` instead of sleeping. Raw bodies can be stored as gzip under integer-id paths.**

## Performance

- **Duration:** about 25 min (08:26Z to 08:51Z on 2026-10-18)
- **Tasks:** 2 (1 tracer + 1 TDD)
- **Files:** 12 created, 2 modified

## Accomplishments
- `HttpFetcher::beforeHop()` now runs these steps on every hop:
  1. A spent deadline returns a timeout result.
  2. `RobotsPolicy::check()` reads the cache, or fetches robots.txt with `fetchRobotsTxt()`. That fetch goes through the SSRF guard, the throttle, the pinned `send()`, at most 5 redirects and the byte cap.
  3. A disallowed URL returns `FetchResult::blocked()`.
  4. `waitForSlot()` reserves the slot, then calls `Sleep::for($wait)->seconds()` or throws HostBusy.
- `send()` is now generic (URL, pin, headers) and is shared by feed and robots requests. Feed headers moved to `icsHeaders()`.
- Robots outcomes:
  - 2xx → rules
  - other 4xx (404, 410) → allow_all
  - 401, 403, 429, or a cf-mitigated header / "Just a moment" / challenge-platform marker → blocked
  - 5xx, network failure, too many redirects, a redirect to a private address, or an oversize body → disallow_all
- **Real transport check (ad hoc, not committed).** I ran a local `php -S` server, pointed `feed.test` at 127.0.0.1 through the pin, and relaxed the SSRF guard in the probe script only. Results:
  - robots.txt was requested first, with the bot UA and `Host: feed.test:8089`.
  - `Crawl-delay: 2` gave real gaps of 2.05 s and 2.02 s between requests.
  - `/Admin/x` came back blocked and never reached the server.
  - The crawl_hosts row was `rules` with delay 2.
  - When the server was down, robots failed closed to disallow_all.
  
  The probe row was deleted from the dev DB afterwards.

## Task Commits

1. **Task 1 (tracer): robots.txt and per-host throttle on every fetch hop** - `804d43f` (feat)
2. **Task 2 (tdd): RFC 9309 matcher table, robots failure modes, throttle limits, raw body store** - `a747539` (feat)

## Files Created/Modified
- `database/migrations/2026_10_18_100500_create_crawl_hosts_table.php` - host PK, next_allowed_at, last_request_at, robots_status (CHECK), robots_body, robots_fetched_at, crawl_delay_seconds (CHECK 0..86400), timestampsTz
- `app/Ingestion/Http/RobotsRules.php` - the parser and matcher
- `app/Ingestion/Http/RobotsPolicy.php` - the cache and status mapping; `productToken()`, `robotsUrlFor()`
- `app/Ingestion/Http/{RobotsFetch,RobotsVerdict}.php` - small DTOs between the fetcher and the policy
- `app/Ingestion/Http/HostThrottle.php` - `reserve()` with `lockForUpdate`, plus `keyFor()`
- `app/Ingestion/Http/HostBusy.php` - the exception, carrying host and seconds
- `app/Ingestion/Http/HttpFetcher.php` - the robots and throttle wiring, `fetchRobotsTxt()`, generic `send()`
- `app/Ingestion/RawBodyStore.php` - gzip store on the `local` (private) disk
- Tests: RobotsRulesTest (42), RobotsPolicyTest (23 including datasets), HostThrottleTest (15), RawBodyStoreTest (8). HttpFetcherTest was updated.

## Decisions Made
See `key-decisions` in the frontmatter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] A Crawl-delay learned from robots.txt would not have spaced the very next request**
- **Found during:** Task 1, designing the reservation.
- **Issue:** The RESEARCH `reserve()` keeps only `next_allowed_at = slot + delay`. robots.txt is booked before its Crawl-delay is known, at the 5 s minimum, so the feed request would follow 5 s later, not 10 s. The tracer and HostThrottleTest expectations (a 10 s wait; HostBusy(12000)) could not hold.
- **Fix:** Added a `last_request_at` column. The slot is `max(now, next_allowed_at, last_request_at + delay)`, and the host's stored Crawl-delay is never undercut.
- **Commit:** `804d43f`

**2. [Rule 1 - Bug] Booking a slot and then refusing it would push the host back on every retry**
- **Fix:** `reserve(host, delay, maxWait)` throws HostBusy before writing anything. A test proves the refused call booked nothing.
- **Commit:** `804d43f`

**3. [Rule 1 - Bug] A spent deadline would have cached disallow_all for the host**
- **Fix:** `beforeHop()` returns a timeout result before robots is consulted. Tested: no crawl_hosts row is written.
- **Commit:** `804d43f`, test in `a747539`

**4. [Rule 2 - Correctness] Regex blow-up (T-03-10) removed entirely**
- Wildcards are matched by a linear two-pointer walk, so remote text is never compiled into a regex. This is stronger than the planned preg_quote approach, and a test runs 40 `*` against a 4000-char path.

**5. [Rule 2 - Correctness] A transient robots failure blocks for 1 h, not 24 h**
- The plan says "at most 24 h". Error outcomes are re-checked after an hour.

**6. [Rule 2 - Correctness] Robots body made safe for a PostgreSQL text column**
- Invalid UTF-8 is scrubbed and NUL bytes are stripped before storing (and parsing).

### Judgement calls (unattended run)
- **HttpFetcherTest:** the plan said to fake an allow-all robots.txt in each case. Instead, a `beforeEach` seeds an allow_all crawl_hosts row for the test hosts. A 404 robots.txt leaves exactly that state, and it keeps every existing request-count assertion meaningful (feed requests only). `Sleep::fake(syncWithCarbon: true)` keeps the clock moving with the waits. The two redirect-count tests set `min_host_delay` to 0, because a 6-hop chain on one host would otherwise legitimately hit the deadline-aware HostBusy. Robots fetching itself is covered in RobotsPolicyTest.
- **beforeHop signature:** it gains a `CarbonImmutable $startedAt` argument so inline waits respect the deadline. The method is protected and nothing overrides it yet.
- **Extra files:** `RobotsFetch.php` and `RobotsVerdict.php` are small DTOs that are not in the plan's file list. `CrawlHost` model: none; the query builder is used, as the plan's file list implies.
- **RED order:** Task 1 is a tracer; its code and tests landed together. In Task 2 I wrote the tests together with the small RawBodyStore. The robots and throttle tests are characterisation tests against the Task 1 code. Quick mutations (tie → Disallow, '*' group merged into specific groups) each turned RobotsRulesTest red.
- **Requirements:** SRC-05 is left **Pending**. robots.txt and spacing are now enforced, but run status recording (`blocked` run rows, 03-05) is still to come. `requirements-completed: []`.

**Total deviations:** 6 auto-fixed, plus the judgement calls above. **Impact:** the D-02 and SRC-05 semantics hold in production (verified against real curl), not only under fakes. No scope change.

## Known Stubs
None. (`.planning/WINDOWS.md` does not exist in this project, so there was nothing to append to.)

## Threat Flags
None. The robots.txt request is the planned surface (T-03-07). It goes through the existing SSRF guard and address pin.

## Issues Encountered
- My first real-transport probe failed because the local server had not started (it was launched in parallel with the step that created its directory). Robots correctly failed closed to disallow_all. The rerun passed.
- The full suite reports one PHPUnit warning that predates this plan.

## User Setup Required
None.

## Next Phase Readiness
- 03-05: catch `HostBusy` in FetchSourceJob and `release($e->seconds)`. Map `FetchResult::isBlocked()` to a `blocked` run with the result's message. Store bodies with `RawBodyStore::put($source->id, $run->id, $body)`, then write `raw_path`/`raw_sha256`.
- 03-06: `ingest:prune-raw` calls `RawBodyStore::prune(config('ingest.raw_retention_days'))`. If a Retry-After should push a host's next request back, it can raise `crawl_hosts.next_allowed_at` (reserve() already honours it as a floor).
- Source page (Pitfall 7): `crawl_hosts.crawl_delay_seconds` is available for the "Crawl-delay exceeds the schedule" notice.
- Full Unit+Feature suite: 1194 passed. PHPStan: 0 errors. Pint: clean.

## Self-Check: PASSED
- All 8 created source files and 4 test files exist; commits `804d43f` and `a747539` are ancestors of HEAD.
