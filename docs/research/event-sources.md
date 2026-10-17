# Attleborough event sources: verified inventory

- **Date of probes:** 2026-10-17 (Saturday), from a developer machine in the UK.
- **Method:** curl + manual review, one request at a time, at least 5 s between requests to one host (10-11 s where robots.txt sets `Crawl-delay: 10`). Every probe below records what was actually served. No research tooling was added to `app/`.
- **User-Agent:** `WhatsOnAttleboroughResearch/0.1 (+mailto:hello@whatsonattleborough.co.uk)` on every request.
- **Network caveat:** this machine sits behind a corporate security gateway (Cisco Umbrella / OpenDNS). When the gateway answers instead of the site (an OpenDNS block page, a redirect to `block.opendns.com`, a TLS error), the result is recorded as **no observation (local gateway)**, never as "blocked by site". Cloudflare `cf-mitigated: challenge` and LiteSpeed reCAPTCHA pages carry the site's own `server:` header and are recorded as the site's bot wall.
- **Requirements:** RES-01 (verified sources with fields), RES-02 (live ladder probes), RES-03 (fixtures). Decisions D-13 (agent first pass, then owner), D-14 (fixtures), D-16 (two lists).

## How a source is classified (D-16)

The probe ladder per candidate: robots.txt → homepage/events page (platform fingerprint, server-rendered y/n, `<link rel="alternate">` feeds, Google Calendar embeds) → WordPress `/wp-json/` namespaces and The Events Calendar (TEC) REST `/wp-json/tribe/events/v1/events` and iCal `?ical=1` → feeds (do items carry an **event** date? `pubDate` never counts) → JSON-LD `Event` (including `@graph`) → terms/legal page.

- **List 1, Crawlable:** robots.txt allows every URL we need, no ToS prohibition was found, **and** a deterministic machine-readable method works that the site itself offers (an advertised ICS/RSS/TEC feed: `<link rel="alternate">`, a visible "Export"/"Subscribe" control or a robots.txt `Allow` for the calendar file). Offering the feed is the syndication signal, so `legal_basis` is `own_feed_published_for_syndication`.
- **List 2, Not crawlable, for owner review:** robots-disallowed, bot wall, ToS-prohibited, ToS-unclear (robots allows but the ToS is silent or vague and the only route is scraping HTML the site doesn't offer for reuse), JS-rendered, login-walled, Facebook. Nothing on List 2 is configured as a source until the owner clears it.
- One organisation reachable at two URLs gets one entry per URL with a cross-reference. Unknown volume is written `unknown`.

## Coverage by category

(Filled in by the sweep, Task 2.)

## List 1: Crawlable

Ordered by priority, then name.

### Attleborough Baptist Church (P0)

| Field | Value |
|---|---|
| Name | Attleborough Baptist Church |
| Verified URL | https://www.attleborough.church/ (found via the Eastern Baptist Association church page https://www.easternbaptist.org.uk/church-homepage/attleborough-baptist-church/, itself linked from Wikipedia's Attleborough article) |
| Coverage | Attleborough (church building, NR17 2HX) |
| Platform | Hubb (hubb.digital / hubb.church church-website platform, ASP.NET). RSS `<generator>Hubb, www.hubb.digital</generator>` |
| Ladder rung | RSS with event dates (rung 2) |
| Extraction method | RSS 2.0. Event start is in `<title>` as local wall-clock `YYYY-MM-DD HH:MM - Name` and in `<description>` as `Time: Sunday, 18 October 2026 at 10:30`; location and postcode in `<description>`; event URL in `<link>` (`/Calendar/Month.aspx?show_event={id}`, a stable id). `<pubDate>` equals the event start in UTC and handles DST correctly (25 Oct 10:30 → `10:30:00 GMT`, 18 Oct 10:30 → `09:30:00 GMT`), but it is not used as the event date (EXT-05). |
| Server-rendered | yes (calendar month view lists events in raw HTML) |
| Frequency | weekly (Sunday services, Evening Bible School, Warm Welcome on Tuesdays, Bible Study on Thursdays) plus a monthly Women's Creative Club |
| Est. events/month | ~13 (64 items in the 150-day feed) |
| Access method / endpoint | `GET https://www.attleborough.church/calendar/rss.xml?days=150` (advertised on `/Calendar/Month.aspx` with `<link rel="alternate" type="application/rss+xml" href="/calendar/rss.xml?days=150">`; the homepage also advertises `/publisher/RSS.xml`). The month view offers an "Export: XML / ICAL / PDF / CSV" control (ICS URL not yet resolved; it is built by JS). |
| Robots status | allowed, fetched 2026-10-17T18:04:35Z. Rules for `User-agent: *`: `Crawl-delay: 10`, `Allow: /Admin/Resources/Folders/Calendar.ics`, `Disallow: /Admin/`, `Disallow: /User/`, `Disallow: /Hub/`, … No rule matches `/calendar/rss.xml`. Phase 3 must honour `Crawl-delay: 10`. |
| ToS finding | The site's terms page `/User/TermsAndConditions.aspx` sits under `Disallow: /User/`, so it was **not fetched** (we honour robots even for legal pages). The platform's own legal page (https://hubb.church/Groups/420336/Legal.aspx) only links downloadable T&Cs for Hubb customers. No prohibition found; the feed is explicitly offered ("Latest events from Attleborough Baptist Church, Norfolk") and the platform's robots.txt explicitly allows calendar ICS files. **Owner to read the terms page once in a browser** to confirm (checkpoint). |
| legal_basis | `own_feed_published_for_syndication` |
| Feasibility | high: one request per run, stable XML, dated items, ids |
| Legal risk | low (pending the owner's read of the terms page) |
| Priority | P0 (trusted local organisation, regular events, rung 2) |
| Example event URL | https://www.attleborough.church/Calendar/Month.aspx?show_event=5936475 (Service, Sun 18 Oct 2026 10:30) |
| Fixture files | `tests/Fixtures/sources/attleborough-baptist-church/2026-10-17-feed.xml` (64 events, 18 Oct 2026 – 14 Mar 2027, spans BST end on 25 Oct) |
| Rung / config notes | Parse the title prefix as Europe/London local time; ignore `pubDate`. Recurring services arrive as separate items (no RRULE). Geofence: inside Attleborough. Many items are worship services; the admin may want a per-source title rule to skip routine services and keep community events (Warm Welcome, Creative Club). |

## List 2: Not crawlable, for owner review

Ordered by reason, then name.

| Name | URL | Reason | Evidence (probe date 2026-10-17) | Suggested route |
|---|---|---|---|---|
| Attleborough Town Council | https://attleboroughtc.org.uk/ | bot wall | robots.txt `200` (`User-agent: *` / `Disallow: /wp-admin/`), but `/`, `/wp-json/` and `/wp-sitemap.xml` all answer `200` with `<title>Bot Verification</title>` and a LiteSpeed reCAPTCHA form (`action="/.lsrecap/recaptcha?"`, `server: LiteSpeed`). WordPress (`x-redirect-by: WordPress`). | ask permission (ask the clerk to allow-list our UA, or whether the council publishes a feed); manual entry meanwhile |
| Attleborough Academy | https://www.attleboroughacademy.org/100/calendar | ToS-prohibited | Robots allows (`User-agent: *`, `Crawl-delay: 3`, no rule for `/calendar/`). Calendar offers `webcal://www.attleboroughacademy.org/calendar/events.ics`. Terms of use (https://www.attleboroughacademy.org/3/terms-of-use): "You agree that You will not: (i) distribute the Content for any purpose including without limitation compiling an internal database, redistributing or reproduction of the Content…". Most items are school-internal (term dates, mocks, INSET). | ask permission (for public events only: presentation evening, productions); else drop |

## Fixture index

| slug | priority | files |
|---|---|---|
| attleborough-baptist-church | P0 | 2026-10-17-feed.xml |

## Excluded sources

(Filled in by the sweep, Task 2.)

## Risks (technical and legal)

(Filled in by the sweep, Task 2.)

## Owner hand-off

Pending: the owner's local URLs are collected at the D-13 checkpoint (Task 3).
