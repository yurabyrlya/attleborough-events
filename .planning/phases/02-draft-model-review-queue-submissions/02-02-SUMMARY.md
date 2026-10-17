---
phase: 02-draft-model-review-queue-submissions
plan: 02
subsystem: research
tags: [source-research, fixtures, robots, ics, rss, privacy]

requires:
  - phase: 01
    provides: Pest Unit suite with global Http::preventStrayRequests, EnvExampleTest pattern
provides:
  - docs/research/event-sources.md (Summary for Phase 3, coverage by category, List 1 Crawlable, List 2 Not crawlable, excluded sources, TEC contract check, probe log, fixture index, risks, owner hand-off)
  - P0 fixtures for Attleborough Baptist Church (Hubb ICS + dated RSS, 64 events spanning the BST end) with manifest
  - tests/Fixtures/sources/README.md (layout, manifest schema, capture etiquette, redaction)
  - tests/Fixtures/sources/.gitattributes (fixtures kept byte-identical, no EOL normalisation)
  - SourceFixturesHaveNoPersonalDataTest (email/UK phone scan, manifest completeness, P0/P1 fixture presence)
affects: [phase-03 fetchers (EXT-01 ICS, EXT-02 TEC, EXT-04 JSON-LD, EXT-05 dated RSS), 02-16 phase verification]

actuals:
  tokens: 25540
  tasks: 4
  commits: 5
plan_head_before: d0b2d2c9c6e5e9f8e9b30652f5cfd0f44dd571a4
plan_head_after: 28ec6520d1ff49a9b4968564c1aa3c72e7d52864

tech-stack:
  added: []
  patterns:
    - "Source classification: List 1 only when robots allows, no ToS prohibition, and the site itself offers a machine-readable feed (legal_basis own_feed_published_for_syndication); HTML/JSON-LD with silent ToS goes to List 2 as ToS-unclear"
    - "Fixtures are raw bytes: sha256_raw of the original, nested .gitattributes -text so text=auto eol=lf never rewrites CRLF"
    - "Privacy scan strips RFC 5545 UID lines (email-shaped identifiers) before matching emails"

key-files:
  created:
    - docs/research/event-sources.md
    - tests/Fixtures/sources/README.md
    - tests/Fixtures/sources/.gitattributes
    - tests/Fixtures/sources/attleborough-baptist-church/manifest.json
    - tests/Fixtures/sources/attleborough-baptist-church/2026-10-17-feed.xml
    - tests/Fixtures/sources/attleborough-baptist-church/2026-10-17-ical.ics
    - tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php
  modified: []

key-decisions:
  - "A feed the site itself offers (rel=alternate, Export/Subscribe control, robots Allow for the calendar file) is the syndication signal that puts a robots-allowed, ToS-silent source on List 1; HTML or JSON-LD alone with silent ToS is List 2 ToS-unclear"
  - "Attleborough Baptist Church is the only List 1 source (P0, ICS rung 1, dated RSS fallback); its robots-disallowed terms page was checked by the owner in a browser on 2026-10-17 (ok)"
  - "Eight ToS-unclear/prohibited sources are 'ask permission (owner contacting)' and stay on List 2 until written permission; five bot walls stay List 2 pending the owner's home-network check, never bypassed"
  - "The privacy scan ignores ICS UID lines (and their folded continuations) because UIDs like E5936475@UK.endis.com are structure, not contact data"

patterns-established:
  - "Fixture capture: curl with the research UA, raw body outside the repo, hash, read, redact, validate parse, manifest entry in filename order"
  - "Adding a source later: re-run the probe ladder, add a probe-log row, classify, fixture only List 1 P0/P1, run the scan test"

requirements-completed: [RES-01, RES-02, RES-03]

coverage:
  - id: D1
    description: "event-sources.md has a header, both lists, coverage by category (18 rows), fixture index and owner hand-off"
    requirement: "RES-01"
    verification:
      - kind: automated
        ref: "grep '^## List 1: Crawlable' / '^## List 2: Not crawlable, for owner review' / '^## Summary for Phase 3' docs/research/event-sources.md"
        status: pass
    human_judgment: false
  - id: D2
    description: "Each candidate has live ladder results (robots, platform, TEC REST/iCal, dated feeds, JSON-LD, raw HTML, ToS); TEC contract verified on a live install"
    requirement: "RES-02"
    verification:
      - kind: manual
        ref: "docs/research/event-sources.md#probe-log-res-02 and #tec-contract-check-res-02"
        status: pass
    human_judgment: true
  - id: D3
    description: "Every P0/P1 source has fixtures and a manifest; fixtures contain no emails/phones other than placeholders; every file listed in its manifest"
    requirement: "RES-03"
    verification:
      - kind: unit
        ref: "tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php (18 tests)"
        status: pass
    human_judgment: false
  - id: D4
    description: "Owner hand-off recorded (no URLs yet, terms check, permission asks, bot-wall check pending)"
    requirement: "RES-01"
    verification:
      - kind: automated
        ref: "grep -A3 '^## Owner hand-off' docs/research/event-sources.md | grep 'supplied no additional URLs'"
        status: pass
    human_judgment: false

duration: 40min
completed: 2026-10-17
status: complete
---

# Phase 2 Plan 02: Source Research (RES-01..03) Summary

**I probed every spec §14 category live. The verified two-list inventory has one cleared source: Attleborough Baptist Church, P0, with a Hubb ICS feed and a dated RSS feed. I saved two byte-identical fixtures that span the BST end, and a new Unit test blocks personal emails and phone numbers in committed fixtures.**

## Performance

- **Duration:** 40 min wall clock, including about 13 min waiting at the owner checkpoint
- **Started:** 2026-10-17T17:49:27Z
- **Completed:** 2026-10-17T18:29:33Z
- **Tasks:** 4 (tracer, sweep, owner checkpoint, close)
- **Files created:** 7

## Accomplishments

- **`docs/research/event-sources.md`**, with these sections:
  - Header: method, User-Agent, gateway caveat, how URLs were found
  - Summary for Phase 3
  - D-16 classification rule
  - Coverage by category (18 rows, including the categories with nothing found)
  - List 1: 1 source with every RES-01 field
  - List 2: 18 sources, each with reason, pasted evidence and route
  - Excluded sources (8)
  - TEC contract check
  - Probe log (one row per candidate)
  - Fixture index
  - Risks
  - Owner hand-off
- **Fixtures for List 1 (P0):**
  - `tests/Fixtures/sources/attleborough-baptist-church/2026-10-17-ical.ics`: 64 VEVENTs in UTC with stable UIDs. Sabre parses all of them.
  - `tests/Fixtures/sources/attleborough-baptist-church/2026-10-17-feed.xml`: RSS with the event start in the title and description.
  - `tests/Fixtures/sources/attleborough-baptist-church/manifest.json`
  - Both files are unmodified (no personal data) and span Sun 25 Oct 2026.
- **TEC REST and iCal contract** verified live on the vendor demo: `x-tec-total`, `x-tec-totalpages`, `next_rest_url` pagination, `?ical=1` returns `text/calendar`. A WordPress site without TEC answers with `rest_no_route` 404, which gives Phase 3 a way to detect "not TEC".
- **Dated RSS (EXT-05):** one feed exists (Baptist Church). EXT-05 can be built and tested against it.
- **Owner hand-off recorded:**
  - No URLs yet. A pending follow-up lists the empty categories and how to add a URL later.
  - Baptist Church terms checked by the owner (ok).
  - 8 sources set to "ask permission (owner contacting)".
  - 5 bot walls pending the owner's home-network check.

## Task Commits

1. **Task 1: Tracer (probe → classify → fixture → manifest → guard)**: `3e33854` (feat), plus `1b46b57` (fix: keep fixtures byte-identical)
2. **Task 2: First-pass sweep of every §14 category**: `bec30bd` (feat)
3. **Task 3: Owner hand-off (D-13 checkpoint)**: `ddbed75` (docs)
4. **Task 4: Close the inventory, Summary for Phase 3**: `28ec652` (docs)

## Files Created/Modified

- `docs/research/event-sources.md`: the verified inventory (RES-01, RES-02)
- `tests/Fixtures/sources/README.md`: layout, manifest schema, capture etiquette, redaction procedure (D-14)
- `tests/Fixtures/sources/.gitattributes`: `*.ics|*.json|*.html|*.xml -text`, so fixtures stay identical to the fetched bytes
- `tests/Fixtures/sources/attleborough-baptist-church/manifest.json`: two entries in filename order, with url, fetched_at, status, headers, robots rules, sha256_raw, redactions, events, window and notes
- `tests/Fixtures/sources/attleborough-baptist-church/2026-10-17-feed.xml`, `2026-10-17-ical.ics`: P0 fixtures (RES-03)
- `tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php`: email/phone scan with must-flag and must-not-flag datasets, manifest completeness, P0/P1 fixture presence

## Decisions Made

See `key-decisions` in the frontmatter. The main judgement call: a source enters List 1 only when the site itself offers the feed, and that offer stands in for a ToS that says nothing. This follows the research ladder's rule "explicit syndication/feeds offered → Crawlable", and it means HTML or JSON-LD sources whose ToS is silent go to List 2 as ToS-unclear.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Repo-wide `* text=auto eol=lf` rewrote the fixture's CRLF line endings**
- **Found during:** Task 1 commit (git warned "CRLF will be replaced by LF")
- **Issue:** The committed feed no longer matched its `sha256_raw`, so it wasn't the raw bytes D-14 requires.
- **Fix:** Added a nested `tests/Fixtures/sources/.gitattributes` marking fixture types `-text`, re-staged the file, and documented this in the README. The committed blobs now hash to `sha256_raw`.
- **Files modified:** `tests/Fixtures/sources/.gitattributes`, `tests/Fixtures/sources/README.md`, the feed fixture
- **Commit:** `1b46b57`

**2. [Rule 1 - Bug] The planned email pattern flagged ICS UIDs**
- **Found during:** Task 2 (ICS capture)
- **Issue:** `UID:E5936475@UK.endis.com` matches the email pattern. That would force a change to structural content, which the plan forbids.
- **Fix:** The scanner removes `UID` property lines, including folded continuations, before matching. I added an "ICS UID" must-not-flag case and an "email in an ICS description" must-flag case. The global email and phone patterns are exactly as planned.
- **Files modified:** `tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php`
- **Commit:** `bec30bd`

### Process deviations

**3. Wikimedia robots slip (URL discovery)**
- **What happened:** I sent two discovery queries to `https://en.wikipedia.org/w/api.php` (Attleborough's external links) and `https://www.wikidata.org/w/api.php` (search) before reading that both hosts' robots.txt has `Disallow: /w/` for `User-agent: *`.
- **Action:** I stopped as soon as I noticed and used only allowed `/wiki/` pages after that. The slip is recorded in the doc's header. It touched no event source and produced no fixture.

**4. Attleborough Primary School `Crawl-delay: 12000`**
- **What happened:** I fetched the homepage 6 s after robots.txt, before reading the crawl delay.
- **Action:** No further requests. Recorded in List 2.

**5. TEC verified on one live install, not 2-3**
- **Why:** I found no Attleborough-area TEC site, so the contract was verified on the vendor demo only. Recorded in "TEC contract check", and local TEC sites are on the owner's pending list.

**6. Commits on `main`**
- **What happened:** `git.base-branch --is-protected main` returns true, but the orchestrator dispatched sequential mode on the main tree (`branching_strategy: none`), as with 02-01. I committed there and pushed nothing.

## Owner follow-ups (pending, non-blocking)

- **Owner URL hand-off.** Categories where the first pass found nothing:
  - St Mary's parish church
  - Connaught Hall
  - Rosecroft Primary School
  - Rotary and Lions
  - Businesses and farms
  - Pubs and venues
  - WI
  - Local sites running The Events Calendar
  - Facebook-only groups

  To add one later, follow "Owner URL hand-off — pending → How to add a URL later" in the doc: same probe ladder, fixtures only for List 1 P0/P1.
- **Permission asks (owner contacting):** Attleborough Heritage Group, Visit Norfolk, Visit Wymondham, Attleborough Academy, Attleborough Primary School, Attleborough Summer Carnival, Attleborough Players, Banham Zoo. They stay on List 2, with no configuration and no fixtures, until permission is granted.
- **Home-network check of the bot walls:** attleboroughtc.org.uk, breckland.gov.uk, norfolk.gov.uk, museums.norfolk.gov.uk, ticketsource.co.uk. They stay on List 2 (bot wall).

## Issues Encountered

- Web search couldn't be used. DuckDuckGo is blocked by the local gateway, and Bing and Mojeek disallow `/search`. That is why several categories came back empty.
- Four Cloudflare-protected hosts challenged even `robots.txt` from the corporate network.

## Known Stubs

None. The "pending" items in the doc are owner follow-ups, not code stubs.

## Next Phase Readiness

- Phase 3 can build the ICS fetcher (EXT-01) and the dated-RSS parser (EXT-05) against real local bytes, including a DST transition.
- TEC (EXT-02) has a live-verified contract but no cleared local source. JSON-LD (EXT-04) sources are waiting on permission.
- Risk: List 1 has a single P0 source, so discovered volume stays low until permissions arrive or the owner adds sources.

## Self-Check: PASSED

- All 7 created files exist on disk.
- All 5 commits (`3e33854`, `1b46b57`, `bec30bd`, `ddbed75`, `28ec652`) are ancestors of HEAD.
- Committed fixture blobs hash to the manifest's `sha256_raw`.
- `./vendor/bin/pest --testsuite=Unit` passed (125 tests).

## Post-completion owner decisions (2026-10-17 evening, D-23..D-26)

After the plan closed, the owner recorded D-23..D-26 in `02-CONTEXT.md`, then revised D-25 and D-26 after probing from a home network. The follow-up below applies them. RES-01..03 stay complete; the inventory, fixtures and tooling grew.

**Inventory now** (`docs/research/event-sources.md`):

- **List 1: 10 sources**
  - P0: Attleborough Baptist Church (ICS + dated RSS), Attleborough Town Council (TEC REST + iCal, the first local TEC install)
  - P1: Attleborough Academy (ICS, written permission held by the owner, D-24), Attleborough Heritage Group (server-rendered selectors, D-23), Attleborough Primary School (method unknown; fixtures pending behind `Crawl-delay: 12000`)
  - P2: Visit Norfolk and Visit Wymondham (JSON-LD, geofenced, D-23)
  - P3: Attleborough Players, Attleborough Summer Carnival, Banham Zoo (legally cleared under D-23, no deterministic method, so manual entry)
- **List 2: 9 sources**
  - Bot wall: Breckland Council, Norfolk County Council, Norfolk Museums, TicketSource. They challenge our honest User-Agent on the corporate network and the home network alike (D-25 routes only: official feeds/APIs, Cloudflare Verified Bots in Phase 5, allowlisting, hand-captured fixtures).
  - Facebook; Snetterton (JS-rendered); Attleborough Town FC (robots-disallowed; its home games come through the Town Council feed); Eastern Daily Press and Eventbrite (ToS-prohibited).
- **Excluded:** Attleborough u3a (HTTPS fails from both networks; HTTP serves an empty holding page).

**Fixtures added** (all P0/P1 List 1 sources except Attleborough Primary):

- `tests/Fixtures/sources/attleborough-town-council/2026-10-17-tec-p1.json`, `2026-10-17-tec-p2.json` (both pages of 19 Oct – 22 Nov, 72 events) and `2026-10-17-ical.ics` (30 events).
  - Captured from the home network.
  - **Redacted:** two private individuals' names (in titles, slugs, URLs and organiser records) and every organiser email and phone number. 26 emails, 17 phones and 66 name occurrences in the REST pages; 8 emails and 9 names in the ICS.
  - JSON re-encoded, ICS re-folded at 75 octets, `modified: true`, `sha256_raw` of the originals.
- `tests/Fixtures/sources/attleborough-academy/2026-10-17-ical.ics`: 59 events, spans both DST changes. No personal data found, unmodified. UIDs are unstable (row number + request time).
- `tests/Fixtures/sources/attleborough-heritage-group/2026-10-17-events-page.html`: one event, no personal data, unmodified.

**New files:**

- `ops/probe-sources.sh`: bash + curl, honest User-Agent. It checks the path against robots.txt (own group or `*`, longest match), honours `Crawl-delay` across runs, saves D-14 names with manifest entries and sha256, and records `blocked` for challenges without saving them. `bash -n` passes; shellcheck isn't installed here. A live test run gave: Town Council "exists", Primary "deferred until 23:05", Breckland and TicketSource "blocked".
- `tests/Fixtures/sources/README.md`: new "How to run at home" section, plus `capture: hand` and `network` manifest fields.
- `docs/research/town-council-feed-request.md`: a courtesy and contingency email to the Town Clerk. It asks for an explicit yes to listing public events, and for crawler allowlisting if the production datacenter IP is blocked.

**Findings worth knowing:**

- **Town Council terms of use** (https://attleboroughtc.org.uk/terms-of-use) contain standard copyright wording that materials "may not be copied, reproduced, republished … or distributed". They say nothing about automated access, and the site advertises its own iCal feed. I recorded it as `factual_extract_only` under the owner's D-26 decision, with legal risk "medium until the clerk confirms". The email asks for that confirmation.
- **Town Council production risk:** its LiteSpeed check blocked the corporate IP but not the residential one, so a Hetzner datacenter IP may be blocked. The clerk email is the contingency.
- **Organiser data:** the Town Council REST and ICS feeds expose organisers' private names, Gmail addresses and mobile numbers. Phase 3 must never copy organiser contact fields to public pages.

**Process deviation:**

- The live test run of `ops/probe-sources.sh` fetched `https://www.attleboroughprimary.org/robots.txt` about 35 minutes after my earlier homepage request. The earlier request had gone to the bare domain and redirected to `www`, and the script's per-host state didn't know about it, so the robots.txt request fell inside that host's `Crawl-delay: 12000`. No page was fetched (the script deferred the calendar request). The script now caches robots.txt for 24 h, so it won't happen again.

**Follow-up commits:**

1. `fbfff6d` test(02-02): P1 fixtures for Attleborough Academy (ICS) and Heritage Group (events page)
2. `1f253b3` test(02-02): P0 TEC fixtures for Attleborough Town Council (first local TEC install)
3. `9745ce8` feat(02-02): polite source probe script for re-checks and new sources
4. `915bbd4` docs(02-02): courtesy/contingency email draft to the Town Clerk
5. `ef33b01` docs(02-02): apply owner decisions D-23..D-26 to the source inventory

**Still with the owner:**

1. Run `ops/probe-sources.sh attleborough-primary-school` after 23:05 BST, then read and redact the capture before committing (or hand it to the agent).
2. Optionally send the courtesy email to the Town Clerk.
3. Local URLs for the empty categories (St Mary's, Connaught Hall, Rosecroft, Rotary/Lions, businesses and farms, pubs and venues, other local TEC sites, Facebook-only groups).
4. Optionally pursue a D-25 route for TicketSource, e.g. ask local organisers about the organiser API.

Post-completion checks: privacy test 18/18 green, full Unit suite 125/125 green. The committed fixture blobs match their manifests (`sha256_raw` for unmodified files, valid JSON and ICS after redaction).
