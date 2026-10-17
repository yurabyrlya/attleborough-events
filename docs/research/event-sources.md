# Attleborough event sources: verified inventory

- **Date of probes:** 2026-10-17 (Saturday). First pass 17:56-18:13 UTC from a developer machine on a corporate network. Follow-up after the owner's decisions D-23..D-26 at 18:34-18:58 UTC: from the owner's **home network** (residential) for the Town Council, the u3a and the probe-script test, and for the D-23/D-24 re-probes.
- **Method:** curl + manual review, one request at a time, at least 5 s between requests to one host (11 s where robots.txt sets `Crawl-delay: 10`). Every row below records what the server actually returned. No research tooling was added to `app/`. Re-checks and new sources use `ops/probe-sources.sh` (same rules, automated; see `tests/Fixtures/sources/README.md`).
- **User-Agent:** `WhatsOnAttleboroughResearch/0.1 (+mailto:hello@whatsonattleborough.co.uk)` on every request. It was never changed to get past a block.
- **Network findings:**
  - The corporate network sits behind a security gateway (Cisco Umbrella / OpenDNS). Where the gateway answered instead of the site, the result is recorded as **no observation (local gateway)**.
  - **Breckland Council, Norfolk County Council, Norfolk Museums and TicketSource** answer our User-Agent with a Cloudflare managed challenge (`cf-mitigated: challenge`, even on robots.txt) from **both** the corporate and the home network, so they challenge automated clients everywhere (D-25).
  - **Attleborough Town Council** showed a LiteSpeed reCAPTCHA to the corporate network but serves normally to the home network. Its block is IP-based (D-26), so production on a datacenter IP may be blocked too (see Risks).
- **URL discovery:** web search was not usable: DuckDuckGo is blocked by the local gateway (OpenDNS block page), and Bing and Mojeek disallow `/search` in robots.txt. URLs came from Wikipedia's Attleborough article (its external links and its Churches/Education sections), links on probed sites (Heritage Group "Useful Links", Eastern Baptist Association, Wymondham Town Council) and DNS checks of likely domain names. Two early discovery queries went to `https://en.wikipedia.org/w/api.php` and `https://www.wikidata.org/w/api.php` before we noticed that both hosts' robots.txt disallow `/w/` for `User-agent: *`; no further `/w/` requests were made.
- **Requirements:** RES-01 (verified sources with fields), RES-02 (live ladder probes), RES-03 (fixtures). Decisions D-13 (agent first pass, then owner), D-14 (fixtures), D-16 (two lists), D-23..D-26 (owner decisions after the first pass, 2026-10-17).

## Summary for Phase 3

- **List 1 (Crawlable): 10 sources.**
  - P0: 2 (Attleborough Baptist Church, Attleborough Town Council)
  - P1: 3 (Attleborough Academy, Attleborough Heritage Group, Attleborough Primary School)
  - P2: 2 (Visit Norfolk, Visit Wymondham)
  - P3: 3 (Attleborough Players, Attleborough Summer Carnival, Banham Zoo). These are legally cleared, but no deterministic method exists, so they come in by manual entry.
- **Fixtures (P0/P1):** Town Council (TEC REST pages 1-2 plus TEC iCal, redacted), Baptist Church (ICS plus dated RSS), Academy (ICS) and Heritage Group (server-rendered events page). Attleborough Primary's are still pending: `Crawl-delay: 12000`, so `ops/probe-sources.sh attleborough-primary-school` must run after the delay.
- **List 2 (Not crawlable): 9 sources.** Bot wall 4 (Breckland, Norfolk County Council, Norfolk Museums, TicketSource: they challenge automated clients on every network), Facebook 1, JS-rendered 1, robots-disallowed 1, ToS-prohibited 2.
- **Rungs needed first:**
  1. **ICS** (EXT-01): three P0/P1 sources with three different dialects.
     - Baptist Church: UTC, no VTIMEZONE, stable UIDs.
     - Town Council: TEC, VTIMEZONE Europe/London, stable UIDs.
     - Academy: VTIMEZONE, all-day plus TZID events, **unstable UIDs**.
  2. **TEC REST** (EXT-02): Town Council, the first real local install, with 72 events in five weeks over 2 pages.
  3. **Server-rendered selectors** (EXT-03): Heritage Group.
  4. **JSON-LD** (EXT-04): only on the P2 directories, Visit Norfolk and Visit Wymondham. No P0/P1 JSON-LD source exists, and no JSON-LD fixture has been captured yet.
- **Dated RSS (EXT-05):** **yes, one feed.** The Baptist Church RSS carries the event start in `<title>` (`YYYY-MM-DD HH:MM - Name`, local time) and in `<description>`. Its `pubDate` is the start in UTC, but EXT-05 must not use it.
- **Still with the owner:**
  - Local URLs for the empty categories (see "Owner URL hand-off — pending").
  - Optional courtesy email to the Town Clerk (`docs/research/town-council-feed-request.md`). It asks for an explicit yes to listing their events (their terms of use have a no-republishing clause) and for allowlisting if the production server is blocked.
  - D-25 routes for the four Cloudflare sites: official feeds or APIs, Cloudflare Verified Bots in Phase 5, allowlist requests, hand-captured browser fixtures.
- **Phase 3 risks:**
  - The Town Council, the largest local source, may block the Hetzner datacenter IP.
  - Academy UIDs change on every fetch, so dedupe must use title + start.

## How a source is classified (D-16)

The probe ladder per candidate: robots.txt → homepage/events page (platform fingerprint, server-rendered y/n, `<link rel="alternate">` feeds, Google Calendar embeds) → WordPress `/wp-json/` namespaces and The Events Calendar (TEC) REST `/wp-json/tribe/events/v1/events` and iCal `?ical=1` → feeds (do items carry an **event** date? `pubDate` never counts) → JSON-LD `Event` (including `@graph`) → terms/legal page.

- **List 1, Crawlable:** robots.txt allows every URL we need, the site doesn't block our User-Agent, and one of these holds:
  - **(a)** No ToS prohibition was found and the site itself offers a machine-readable feed (`legal_basis` `own_feed_published_for_syndication`).
  - **(b)** The ToS is silent and the owner accepted the source (D-23): `legal_basis` `factual_extract_only`. The basis is robots allows + terms silent + owner decision + polite crawling (identifying User-Agent, `Crawl-delay` honoured, low volume) + attribution and a link back on every event.
  - **(c)** The owner holds permission (D-24): `permission_granted`.
  - **(d)** The owner decided to accept it despite boilerplate terms (D-26), recorded with the exact wording.

  Within List 1, P0-P2 have a deterministic method. **P3** means legally cleared but no deterministic method, so these come in by manual entry until the site adds structure.
- **List 2, Not crawlable:** robots-disallowed, bot wall, ToS-prohibited, ToS-unclear without an owner decision, JS-rendered, login-walled, Facebook. Nothing on List 2 is configured as a source.
- One organisation reachable at two URLs gets one entry per URL with a cross-reference. Unknown volume is written `unknown`.

## Coverage by category

Every spec §14 category, including the ones where nothing was found.

| Spec §14 category | Candidates found (list) | Searches / probes tried |
|---|---|---|
| Town Council | Attleborough Town Council (**List 1, P0**, TEC) | Wikipedia external link `attleboroughtc.org.uk`; DNS for `attleboroughtowncouncil.gov.uk`, `attleborough-tc.gov.uk`, `attleborough.gov.uk` (none resolve); probed from both networks |
| Breckland Council | Breckland Council (List 2, bot wall) | `www.breckland.gov.uk/robots.txt` from both networks |
| Schools (and PTAs) | Attleborough Academy (**List 1, P1**, ICS, permission); Attleborough Primary School (**List 1, P1**, calendar pending, Crawl-delay 12000) | Wikipedia Education section (Academy, Rosecroft Primary, Attleborough Primary, Wymondham College); DNS for 9 Rosecroft / Attleborough Primary domain shapes (only `attleboroughprimary.org` resolves). Rosecroft Primary: none found. PTA: Attleborough Primary links a "Friends of APS" page (not probed, same host crawl-delay) |
| Churches | Attleborough Baptist Church (**List 1, P0**); Eastern Baptist Association church page (Excluded, directory page) | Wikipedia Churches section; St Mary's (parish church): none found (DNS for 5 domain shapes; A Church Near You search is JS-rendered, `achurchnearyou.com/robots.txt` allows `/`); Methodist church: domain from Wikipedia no longer resolves and the church "has stopped holding normal services" (Wikipedia). St Mary's Church Hall appears as a venue in Heritage Group events |
| Halls (e.g. Connaught Hall) | Town Hall bookings come through the Town Council TEC feed (List 1); Trinity Hall, Besthorpe (Excluded, no event listing) | DNS for 6 Connaught Hall domain shapes (none resolve); Connaught Hall: none found |
| Sports clubs | Attleborough Town FC (List 2, robots-disallowed; its home games also appear in the Town Council feed); Attleborough Athletics Academy (in the Town Council feed) | Spec note (Squarespace); DNS for rugby, cricket and bowls club domain shapes (none resolve) |
| Charities (Rotary, Lions) | none found (Royal British Legion and the Benjamin Foundation appear in the Town Council feed) | DNS for 6 Rotary/Lions domain shapes (none resolve) |
| Businesses, farms | none found | no discovery route without search; owner hand-off |
| Pubs and venues | Attleborough Players (theatre company, **List 1, P3**) | DNS for Griffin/Queens pub domain shapes (none resolve) |
| Library and heritage centre | Attleborough Heritage Group (**List 1, P1**); Norfolk County Council libraries (List 2, bot wall) | `attleboroughheritage.org.uk`; `www.norfolk.gov.uk/robots.txt` from both networks |
| Nearby attractions | Banham Zoo (**List 1, P3**); Snetterton Circuit (List 2, JS-rendered); Norfolk Museums incl. Gressenhall (List 2, bot wall) | `www.snetterton.co.uk`, `www.banhamzoo.co.uk`, `www.museums.norfolk.gov.uk` |
| Norfolk directories | Visit Norfolk (**List 1, P2**); Visit Wymondham (**List 1, P2**) | `visitnorfolk.co.uk/events`, `visitwymondham.org.uk/whats-on/` (linked from Wymondham Town Council) |
| Eventbrite | Eventbrite (List 2, ToS-prohibited) | robots.txt, `/d/united-kingdom--attleborough/all-events/`, Terms of Service |
| TicketSource | TicketSource (List 2, bot wall) | robots.txt from both networks (and in the research session) |
| Local news | Eastern Daily Press (List 2, ToS-prohibited) | `edp24.co.uk/things-to-do/local-events/`, Newsquest terms. Watton & Swaffham Times and Diss Express resolve but were not probed (Newsquest titles share the EDP terms; Diss is outside the likely geofence) |
| Facebook Pages/Groups | Facebook (List 2, Facebook) | `www.facebook.com/robots.txt`; Facebook Pages referenced by the Carnival, Players and Besthorpe sites |
| Community events (carnival, u3a, WI) | Attleborough Summer Carnival (**List 1, P3**); WI, Youth Project, Lifelong Learning, Metal Detecting Club (all in the Town Council feed); Attleborough u3a (Excluded: no working site) | `attleboroughcarnival.co.uk`; `u3asites.org.uk/attleborough/` from both networks |
| Any site with ICS/RSS/JSON-LD | ICS: Town Council, Baptist Church, Academy; dated RSS: Baptist Church; TEC REST: Town Council; JSON-LD: Visit Norfolk, Visit Wymondham | ladder on every candidate above |

## List 1: Crawlable

Ordered by priority, then name. **10 sources:** P0 2, P1 3, P2 2, P3 3. The original first-pass block for the Baptist Church is unchanged. The others moved here after the owner's decisions D-23 (ToS-unclear sources accepted), D-24 (Academy permission) and D-26 (Town Council, reachable from a residential network).

### Attleborough Baptist Church (P0)

| Field | Value |
|---|---|
| Name | Attleborough Baptist Church |
| Verified URL | https://www.attleborough.church/ (found via the Eastern Baptist Association church page https://www.easternbaptist.org.uk/church-homepage/attleborough-baptist-church/, itself linked from Wikipedia's Attleborough article) |
| Coverage | Attleborough (church building, Leys Lane, NR17 2HX) |
| Platform | Hubb (hubb.digital / hubb.church church-website platform by Endis Ltd, ASP.NET). RSS `<generator>Hubb, www.hubb.digital</generator>`; ICS `PRODID:-//Endis Ltd/Insight//NONSGML v1.0//EN` |
| Ladder rung | ICS (rung 1); dated RSS (rung 2) as a fallback with the same ids |
| Extraction method | **ICS:** `DTSTART`/`DTEND` in UTC (`Z`), no VTIMEZONE, `SUMMARY`, `DESCRIPTION`, `LOCATION`, `URL`, stable `UID:E{id}@UK.endis.com`; no RRULE (each service is its own VEVENT). **RSS:** start in `<title>` as local wall-clock `YYYY-MM-DD HH:MM - Name` and in `<description>` as `Time: Sunday, 18 October 2026 at 10:30`; `<pubDate>` equals the start in UTC but is not used as the event date (EXT-05). |
| Server-rendered | yes (the month view lists events in raw HTML) |
| Frequency | weekly (Sunday Service 10:30, Evening Bible School Sun 18:00, Warm Welcome Tue 10:00, Bible Study & Prayer Thu 14:00) plus a monthly Women's Creative Club (last Saturday) |
| Est. events/month | ~13 (64 events between 18 Oct 2026 and 14 Mar 2027) |
| Access method / endpoint | `GET https://www.attleborough.church/Calendar/Calendar.ics` (the URL the month view's "Export → ICAL" control shows with "Use the URL below to access your calendar from other applications"). Fallback `GET https://www.attleborough.church/calendar/rss.xml?days=150` (advertised with `<link rel="alternate" type="application/rss+xml">` on `/Calendar/Month.aspx`). |
| Robots status | allowed, fetched 2026-10-17T18:04:35Z. `User-agent: *` rules: `Crawl-delay: 10`, `Allow: /Admin/Resources/Folders/Calendar.ics`, `Disallow: /Admin/`, `Disallow: /User/`, `Disallow: /Hub/`, `Disallow: /Modules/`, … No rule matches `/Calendar/` or `/calendar/rss.xml`. Phase 3 must honour `Crawl-delay: 10`. |
| ToS finding | No prohibition found. The site's terms page `/User/TermsAndConditions.aspx` sits under `Disallow: /User/`, so it was **not fetched** (robots is honoured even for legal pages). The platform's legal page (https://hubb.church/Groups/420336/Legal.aspx) only offers downloadable T&Cs for Hubb customers. The site explicitly offers both feeds for use "from other applications", and the platform's robots.txt explicitly allows calendar ICS files. **ToS checked by owner 2026-10-17:** the owner opened https://www.attleborough.church/User/TermsAndConditions.aspx in a browser and found no prohibition of automated use or republishing ("ok"). We did not fetch the robots-disallowed page ourselves. |
| legal_basis | `own_feed_published_for_syndication` (evidence: feed explicitly offered by the site + ToS checked by owner 2026-10-17) |
| Feasibility | high: one request per run, valid ICS (sabre parses all 64 events), stable ids, DST handled (18 Oct 10:30 BST → `20261018T093000Z`, 25 Oct 10:30 GMT → `20261025T103000Z`) |
| Legal risk | low |
| Priority | P0 (trusted local organisation, regular events, rung 1) |
| Example event URL | https://www.attleborough.church/Calendar/Month.aspx?show_event=5936475 (Service, Sun 18 Oct 2026 10:30) |
| Fixture files | `tests/Fixtures/sources/attleborough-baptist-church/2026-10-17-feed.xml`, `tests/Fixtures/sources/attleborough-baptist-church/2026-10-17-ical.ics` (64 events each, spans BST end on 25 Oct 2026; no personal data, unmodified) |
| Rung / config notes | Use the ICS. Geofence: inside Attleborough. Most items are worship services; the admin may want a per-source rule that skips routine services and keeps community events (Warm Welcome, Women's Creative Club). Same organisation also listed on the Eastern Baptist Association site (Excluded). |

### Attleborough Town Council (P0)

| Field | Value |
|---|---|
| Name | Attleborough Town Council |
| Verified URL | https://attleboroughtc.org.uk/ (Wikipedia external link) |
| Coverage | Attleborough: Town Hall programme (community groups, WI, Lifelong Learning, Youth Project, Heritage Group, yoga, cafes, police drop-ins), council meetings, Remembrance services, ATFC home games, private hires |
| Platform | WordPress + The Events Calendar 6.18 (`PRODID:-//Attleborough Town Council - ECPv6.18.0//`), LiteSpeed server. Recurring series are expanded into per-occurrence URLs (`/event/{slug}/{YYYY-MM-DD}`), so it is probably TEC Pro. |
| Ladder rung | TEC REST (rung 1, platform adapter EXT-02); TEC iCal (rung 1) as a cross-check |
| Extraction method | **REST:** `/wp-json/tribe/events/v1/events?start_date=…&end_date=…&per_page=50&page=N`, following `next_rest_url` until it is absent. Each event has `id`, `global_id`, `title` (HTML entities such as `&#038;`), `description`, `start_date`/`end_date` (local), `utc_start_date`/`utc_end_date`, `timezone` (Europe/London), `all_day`, `url`, `venue{venue,address,…}`, `organizer[]` (names, emails, phones: personal data, never stored publicly), `categories`, `cost`, `modified`. **iCal:** VTIMEZONE Europe/London, `DTSTART;TZID=Europe/London`, `UID:{post id}-{start epoch}-{end epoch}@attleboroughtc.org.uk`, no RRULE (each occurrence is its own VEVENT), 30 events per export by default. |
| Server-rendered | yes (from a residential network) |
| Frequency | daily, several events a day |
| Est. events/month | ~60 (72 events between 19 Oct and 22 Nov 2026; 112 between 19 Oct and 31 Dec) |
| Access method / endpoint | `GET https://attleboroughtc.org.uk/wp-json/tribe/events/v1/events?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD&per_page=50&page=1`, then `next_rest_url`. iCal: `https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1`. Every page also advertises `<link rel="alternate" type="text/calendar" href="https://attleboroughtc.org.uk/events/?ical=1">`. |
| Robots status | allowed, fetched 2026-10-17T18:40:54Z (home network): `User-agent: *`, `Disallow: /wp-admin/`, `Allow: /wp-admin/admin-ajax.php`, `Sitemap: https://attleboroughtc.org.uk/wp-sitemap.xml`. No crawl delay. **Network:** from the corporate gateway every page, including `/wp-json/` and the sitemap, returned a LiteSpeed reCAPTCHA "Bot Verification" page (17:56 UTC). The block is IP-based (D-26). |
| ToS finding | Terms of use (https://attleboroughtc.org.uk/terms-of-use, fetched 18:43 UTC) do not mention automated access, but contain standard copyright wording: "The Materials on the Site may not be copied, reproduced, republished, uploaded, posted, transmitted, or distributed in any way" and "use of the Materials on any web site … for any purpose other than personal, non-commercial use is … expressly prohibited". The privacy policy (https://attleboroughtc.org.uk/privacy-policy) covers personal data only. The site itself advertises the iCal feed. |
| legal_basis | `factual_extract_only`: owner decision D-26. Robots allows, the iCal feed is advertised by the site, and we take facts only (title, date, time, venue) with attribution and a link back, never descriptions, images or organiser contacts. The terms' no-republishing wording is recorded, and the courtesy email (`docs/research/town-council-feed-request.md`) asks the clerk for an explicit yes. |
| Feasibility | high: 1-3 REST requests per run, JSON with UTC and local times; DST handled (25 Oct 10:00 → `utc_start_date` 10:00, 21 Oct 18:00 → 17:00 UTC) |
| Legal risk | medium until the clerk confirms (boilerplate no-republishing clause); low for the facts themselves |
| Priority | P0 (trusted local organisation, highest local volume, rung 1) |
| Example event URL | https://attleboroughtc.org.uk/event/rbl-remembrance-parade (RBL Remembrance Parade, Sun 8 Nov 2026 11:00) |
| Fixture files | `tests/Fixtures/sources/attleborough-town-council/2026-10-17-tec-p1.json`, `2026-10-17-tec-p2.json` (both pages of 19 Oct – 22 Nov, 72 events), `2026-10-17-ical.ics` (30 events, 17-27 Oct). All spans BST end on 25 Oct. **Redacted:** two private individuals' names (in event titles, slugs, URLs and organiser records) plus all organiser emails and phones. JSON re-encoded, ICS re-folded, `modified: true`. |
| Rung / config notes | The first real local TEC install, which is the Phase 3 TEC adapter target. Exclude "Private Function" / "Private Hire" items, and probably council committee meetings (or route them to a category). Organiser contact fields must never reach public pages. **Production risk:** the Hetzner VPS is a datacenter IP and may get the same LiteSpeed block as the corporate gateway. If so, ask the clerk to allowlist our User-Agent/IP. Never bypass. |

### Attleborough Academy (P1)

| Field | Value |
|---|---|
| Name | Attleborough Academy |
| Verified URL | https://www.attleboroughacademy.org/100/calendar |
| Coverage | Attleborough (secondary school and sixth form, Sapientia Education Trust) |
| Platform | Cleverbox reactCMS |
| Ladder rung | ICS (rung 1) |
| Extraction method | ICS with VTIMEZONE Europe/London. Timed events use `DTSTART;TZID=Europe/London`; all-day and multi-day events use `VALUE=DATE` with an exclusive `DTEND`. No RRULE. **UIDs are not stable:** each is a row number followed by the request's Unix time (`UID:2841792262071`), so dedupe on SUMMARY + DTSTART. The `content-type` header is `text/calendar;;charset=UTF-8` (doubled `;;`). |
| Server-rendered | yes (calendar list in raw HTML) |
| Frequency | several a month during term |
| Est. events/month | ~6 (59 events from 7 Oct 2026 to 22 Jul 2027). Most are school-internal (term dates, mocks, parents' evenings); a few a term are public (open evenings, presentation evenings, charity days). |
| Access method / endpoint | `GET https://www.attleboroughacademy.org/calendar/events.ics` (advertised on the calendar page as `webcal://…/calendar/events.ics`) |
| Robots status | allowed, fetched 2026-10-17T18:36:17Z: `User-agent: *`, `Disallow: /cms/`, `/reactcms/`, `/account/`, `/uploads/document/`, `/download?file=*`, `Crawl-delay: 3`. No rule matches `/calendar/`. |
| ToS finding | Terms of use (https://www.attleboroughacademy.org/3/terms-of-use) forbid "compiling an internal database" and republishing the content. |
| legal_basis | `permission_granted`: written permission held by the owner (D-24, 2026-10-17). The permission itself isn't committed because the repo is public. |
| Feasibility | high: one request per run, valid ICS (sabre parses all 59) |
| Legal risk | low (permission held) |
| Priority | P1 (reliable structured data, but low public volume; admin filtering needed) |
| Example event URL | https://www.attleboroughacademy.org/calendar/288/ (Autumn Half-Term Holiday, 19-30 Oct 2026) |
| Fixture files | `tests/Fixtures/sources/attleborough-academy/2026-10-17-ical.ics` (59 events; spans BST end 25 Oct 2026 and BST start 28 Mar 2027; no personal data, unmodified) |
| Rung / config notes | Per-source rule: skip internal items (INSET, mocks, "Parents' Evening", term dates), keep open evenings, presentation evenings and productions. Honour `Crawl-delay: 3`. |

### Attleborough Heritage Group (P1)

| Field | Value |
|---|---|
| Name | Attleborough Heritage Group |
| Verified URL | https://www.attleboroughheritage.org.uk/Events/FutureEvents.aspx |
| Coverage | Attleborough (talks and Coffee and Chat at St Mary's Church Hall / Town Hall) |
| Platform | ASP.NET WebForms on IIS 8.5 (built by Airedale Solutions) |
| Ladder rung | server-rendered selectors (rung 4) |
| Extraction method | Two GridViews, `#ctl00_maincontent_GridViewList` (desktop) and `#ctl00_maincontent_GridViewListMobile`. Each row has a date heading (`Saturday, 21 November 2026`), a title and a free-text description with the time and place (`St Mary's Church Hall 10.30-11.30`). Times are prose: an unparseable time is a parse error, not a guess. |
| Server-rendered | yes |
| Frequency | monthly |
| Est. events/month | ~1 (one listed on 2026-10-17; the Town Council feed also lists the group's Town Hall sessions) |
| Access method / endpoint | `GET https://www.attleboroughheritage.org.uk/Events/FutureEvents.aspx` |
| Robots status | no robots.txt (`404`, fetched 2026-10-17T18:36:39Z): no rules |
| ToS finding | No terms page; the privacy page covers Google Analytics only. Silent on automated access. |
| legal_basis | `factual_extract_only`: robots allows, terms silent, owner decision D-23, polite crawling, attribution and link back |
| Feasibility | medium (selectors on ASP.NET control ids are stable until a redesign; prose times) |
| Legal risk | low |
| Priority | P1 (local source needing selectors) |
| Example event URL | https://www.attleboroughheritage.org.uk/Events/FutureEvents.aspx (Coffee and Chat, Sat 21 Nov 2026; no per-event URLs) |
| Fixture files | `tests/Fixtures/sources/attleborough-heritage-group/2026-10-17-events-page.html` (1 event; no personal data, unmodified) |
| Rung / config notes | One request a day is plenty. Overlaps with the Town Council feed, where the group's Tuesday sessions appear, so dedupe across sources. |

### Attleborough Primary School (P1, fixtures pending)

| Field | Value |
|---|---|
| Name | Attleborough Primary School |
| Verified URL | https://www.attleboroughprimary.org/key-information/school-calendar (linked from the homepage; page not yet fetched) |
| Coverage | Attleborough (primary school, PTA "Friends of APS") |
| Platform | school website CMS (not identified) |
| Ladder rung | unknown until the calendar page is fetched |
| Extraction method | unknown (pending) |
| Server-rendered | unknown |
| Frequency | unknown |
| Est. events/month | unknown |
| Access method / endpoint | `GET /key-information/school-calendar`, at most one request per 3 h 20 min |
| Robots status | allowed, fetched 2026-10-17T18:10Z: `User-agent: *`, `Crawl-delay: 12000`, `Disallow: /page-not-found`, `/server-error`, `/admin` |
| ToS finding | No terms link on the homepage |
| legal_basis | `factual_extract_only`: robots allows, terms silent, owner decision D-23, `Crawl-delay: 12000` honoured |
| Feasibility | low-medium (one request per run at most; method unknown) |
| Legal risk | low |
| Priority | P1, provisional; re-rank once the calendar is seen |
| Example event URL | unknown (pending) |
| Fixture files | **pending:** run `ops/probe-sources.sh attleborough-primary-school` after the crawl delay. On 2026-10-17 the script deferred it until 23:05 BST. |
| Rung / config notes | Phase 3 must schedule this source at most every 3 h 20 min (daily is fine). |

### Visit Norfolk (P2)

| Field | Value |
|---|---|
| Name | Visit Norfolk (Visit East of England) |
| Verified URL | https://visitnorfolk.co.uk/events |
| Coverage | Norfolk-wide directory; Attleborough is one location filter (`value="46837"`) |
| Platform | Craft CMS + Sprig (htmx) |
| Ladder rung | JSON-LD (rung 3) on event pages |
| Extraction method | Event pages carry `Event` JSON-LD inside `@graph` (e.g. `/events/parkrun-23`). The listing filter goes through `/actions/sprig-core/components/render`, so Attleborough events have to come from listing pages plus a geofence. |
| Server-rendered | yes |
| Frequency | continuous (regional) |
| Est. events/month | unknown for Attleborough (regional volume high) |
| Access method / endpoint | `GET https://visitnorfolk.co.uk/events` (listing) then `GET /events/{slug}` |
| Robots status | allowed, fetched 2026-10-17 18:02 UTC: `User-agent: *`, `Disallow: /cpresources/`, `/vendor/`, `/.env`, `/cache/` |
| ToS finding | Only a privacy policy is linked; no website terms. Silent on automated access. |
| legal_basis | `factual_extract_only`: robots allows, terms silent, owner decision D-23, polite crawling, attribution and link back |
| Feasibility | medium (many pages for few local events; geofence needed) |
| Legal risk | low |
| Priority | P2 (regional directory, geofenced) |
| Example event URL | https://visitnorfolk.co.uk/events/parkrun-23 |
| Fixture files | none (P2) |
| Rung / config notes | Geofence to the agreed radius. A partner data feed from Visit East of England would be better: worth asking. |

### Visit Wymondham (P2)

| Field | Value |
|---|---|
| Name | Visit Wymondham |
| Verified URL | https://visitwymondham.org.uk/whats-on/ |
| Coverage | Wymondham and villages (~6 miles from Attleborough) |
| Platform | WordPress + Modern Events Calendar Lite (not TEC) |
| Ladder rung | JSON-LD (rung 3) |
| Extraction method | The listing page carries one `Event` JSON-LD block per upcoming event, with `startDate` offsets (`2026-10-25T13:30:00+00:00` after BST ends), `location.name` and `url`. Some `url` values are odd (`/events/__trashed-3/`), so fall back to the listing URL. |
| Server-rendered | yes |
| Frequency | weekly |
| Est. events/month | ~40 regional; Attleborough-relevant: unknown (few) |
| Access method / endpoint | `GET https://visitwymondham.org.uk/whats-on/` |
| Robots status | allowed, fetched 2026-10-17 18:10 UTC: `User-agent: *` / `Disallow:` (empty) |
| ToS finding | Only a privacy policy; no terms. Silent on automated access. |
| legal_basis | `factual_extract_only`: robots allows, terms silent, owner decision D-23, polite crawling, attribution and link back |
| Feasibility | high (one page, JSON-LD) |
| Legal risk | low |
| Priority | P2 (regional, geofenced) |
| Example event URL | https://visitwymondham.org.uk/whats-on/ (A Taste of Wymondham, Thu 22 Oct 2026 12:30, Market Place) |
| Fixture files | none (P2). It is the best JSON-LD fixture candidate if Phase 3 wants one before TicketSource or another source clears. |
| Rung / config notes | Geofence. |

### Attleborough Players (P3)

| Field | Value |
|---|---|
| Name | Attleborough Players |
| Verified URL | https://attleboroughplayers.co.uk/ |
| Coverage | Attleborough (amateur theatre, 2-3 productions a year) |
| Platform | WordPress + WPBakery, no TEC |
| Ladder rung | none on its own site |
| Extraction method | none deterministic. Show news comes from an embedded Facebook feed (custom-facebook-feed-pro) and a TicketSource ticket-shop widget; `/feed/` has 0 items; `/gigs/` is password-protected. |
| Server-rendered | Facebook post text only |
| Frequency | 2-3 productions a year |
| Est. events/month | <1 |
| Access method / endpoint | none (TicketSource is List 2, bot wall) |
| Robots status | allowed, fetched 2026-10-17: `User-agent: *`, `Disallow: /wp-admin/` |
| ToS finding | No terms page |
| legal_basis | `factual_extract_only` (owner decision D-23), but used through manual entry |
| Feasibility | low (no structure) |
| Legal risk | low |
| Priority | P3 (manual entry) |
| Example event URL | https://attleboroughplayers.co.uk/ (Charlie and the Chocolate Factory, from Thu 15 Oct 2026, announced via the embedded Facebook feed) |
| Fixture files | none (P3) |
| Rung / config notes | Enter productions manually. Cross-reference TicketSource (List 2) and Facebook (List 2). |

### Attleborough Summer Carnival (P3)

| Field | Value |
|---|---|
| Name | Attleborough Summer Carnival |
| Verified URL | https://attleboroughcarnival.co.uk/ |
| Coverage | Attleborough (Party on the Park, Carnival Day, Santa's Sleigh) |
| Platform | One.com Web Editor |
| Ladder rung | none deterministic (dates in prose) |
| Extraction method | server-rendered prose ("Attleborough Summer Carnival 2026 3rd-5th July") |
| Server-rendered | yes |
| Frequency | 3-4 events a year |
| Est. events/month | <1 |
| Access method / endpoint | `GET https://attleboroughcarnival.co.uk/` |
| Robots status | no robots.txt (`404`): no rules |
| ToS finding | "Copyright @ All rights reserved"; no terms |
| legal_basis | `factual_extract_only` (owner decision D-23), used through manual entry |
| Feasibility | low (prose dates) |
| Legal risk | low |
| Priority | P3 (manual entry) |
| Example event URL | https://attleboroughcarnival.co.uk/ |
| Fixture files | none (P3) |
| Rung / config notes | Enter the yearly dates manually. Cross-reference: Facebook Page (List 2). |

### Banham Zoo (P3)

| Field | Value |
|---|---|
| Name | Banham Zoo (Zoological Society of East Anglia) |
| Verified URL | https://www.banhamzoo.co.uk/events |
| Coverage | Banham (~7 miles): seasonal family events |
| Platform | Webflow + HubSpot |
| Ladder rung | none deterministic |
| Extraction method | the events page lists titles only; detail pages (e.g. `/october-half-term`) give dates in prose only ("book by Sunday 25th October"); no JSON-LD |
| Server-rendered | yes |
| Frequency | seasonal |
| Est. events/month | ~1-2 |
| Access method / endpoint | `GET https://www.banhamzoo.co.uk/events` |
| Robots status | robots.txt `200`, empty (no rules), fetched 2026-10-17 |
| ToS finding | `/terms` covers tickets and visits only, silent on website use |
| legal_basis | `factual_extract_only` (owner decision D-23), used through manual entry |
| Feasibility | low |
| Legal risk | low |
| Priority | P3 (manual entry, regional) |
| Example event URL | https://www.banhamzoo.co.uk/october-half-term |
| Fixture files | none (P3) |
| Rung / config notes | Manual entry for headline seasonal events; geofence decides whether Banham is in. |

## List 2: Not crawlable, for owner review

Ordered by reason, then name. All probes 2026-10-17 (UTC times in the evidence).

| Name | URL | Reason | Evidence | Suggested route |
|---|---|---|---|---|
| Breckland Council | https://www.breckland.gov.uk/ | bot wall | Challenges automated clients on every network. robots.txt `HTTP/2 403`, `cf-mitigated: challenge`, `server: cloudflare`, `<title>Just a moment...</title>`: research session, 18:01:51 (corporate network), again at 18:45 (home network, `ops/probe-sources.sh` test run). The owner saw from a third-party fetch that robots.txt disallows only `/bin/`, `/files/`, `/assets/`, `/apiserver/`. | D-25 routes: (1) an official feed or open data if Breckland publishes one; (2) Cloudflare Verified Bots once the production crawler is public (Phase 5); (3) ask Breckland to allowlist our User-Agent/IP; (4) hand-captured browser fixtures (`capture: hand`) for parser work. Manual entry meanwhile. |
| Norfolk County Council (libraries, incl. Attleborough Library) | https://www.norfolk.gov.uk/ | bot wall | Challenges automated clients on every network. robots.txt `HTTP/2 403`, `cf-mitigated: challenge`, `server: cloudflare`: 18:01:52 (corporate network); the owner saw the same from home. Per a third-party fetch, robots.txt disallows `/*?eventid` and office files and lists a sitemap. | D-25 routes (as Breckland; ask about a libraries events feed). Manual entry meanwhile. |
| Norfolk Museums (incl. Gressenhall Farm and Workhouse) | https://www.museums.norfolk.gov.uk/ | bot wall | Challenges automated clients on every network. robots.txt and `/` `HTTP/2 403`, `cf-mitigated: challenge`, `server: cloudflare`: 18:00:01 (corporate network); the owner saw the same from home. Per a third-party fetch, robots.txt allows everything. | D-25 routes. Regional (P2 at best). Manual entry meanwhile. |
| TicketSource | https://www.ticketsource.co.uk/ | bot wall | Challenges automated clients on every network. robots.txt `HTTP/2 403`, `cf-mitigated: challenge`, `server: cloudflare`: research session, 18:01:52 (corporate network), again at 18:45 (home network, `ops/probe-sources.sh` test run). Per a third-party fetch, robots.txt disallows `/feed/`, `/booking/`, `?q=`, `?category=` and more, and lists `sitemap.xml`. Attleborough Players sell tickets here. | D-25 routes, best first: (1) the **TicketSource organiser API** (ask Attleborough Players or other local organisers to share their event data that way); (2) Cloudflare Verified Bots (Phase 5); (3) allowlist request; (4) hand-captured event pages (`capture: hand`) for a JSON-LD fixture. Manual entry meanwhile. |
| Facebook Pages and Groups | https://www.facebook.com/ | Facebook | 18:12:16: robots.txt `User-agent: *` / `Disallow: /`, and "Collection of data on Facebook through automated means is prohibited unless you have express written permission from Facebook". Spec §8.7: no Facebook scraping. Referenced Pages: https://www.facebook.com/attleboroughsummercarnival (Carnival), Attleborough Players' Page (embedded feed), Besthorpe residents' group. | manual entry |
| Snetterton Circuit | https://www.snetterton.co.uk/calendar/full | JS-rendered | 18:03:36. robots.txt has only Cloudflare content-signal comments, no `Disallow` for `*`. `/calendar/full` returns `200` but no events in the raw HTML (page text is navigation only); no JSON-LD. Matches spec §14. | manual entry for headline race weekends (P3) |
| Attleborough Town FC | https://www.attleboroughtownfc.co.uk/ | robots-disallowed | 18:00:29. Squarespace (`static1.squarespace.com`). robots.txt `User-agent: *`: `Disallow:/*?format=ical`, `Disallow:/*?format=json`, `Disallow:/*&format=ical`. No events collection on the site (pages: about, history, teams, sponsors). Home games appear in the Town Council TEC feed ("ATFC ~ Home Game"). | covered by the Town Council feed; manual entry for anything else |
| Eastern Daily Press | https://www.edp24.co.uk/local-events/ | ToS-prohibited | 18:04. Robots allows `/local-events/`. Calendar is an evvnt JS widget (`EvvntModule`), no events in raw HTML. Newsquest terms (https://www.newsquest.co.uk/legal/terms-conditions/): "No part of the Site can be reproduced on, transmitted to or stored on any other web site or other form of electronic retrieval system". | drop (or ask evvnt about a licensed feed) |
| Eventbrite | https://www.eventbrite.co.uk/d/united-kingdom--attleborough/all-events/ | ToS-prohibited | 18:02. robots.txt `User-agent: *`: `Disallow: /rss/`, `Disallow: /atom/`, `Disallow: /events/rss/`, `Disallow: /events/atom/`; `/d/` and `/e/` allowed; listing has `ItemList` JSON-LD of `Event`s, all 20 in Norwich/Costessey/Redgrave, none in Attleborough. Terms of Service (https://www.eventbrite.co.uk/help/en-gb/articles/251210/eventbrite-terms-of-service/): "You have no right to, and you agree not to, scrape, crawl, or employ any automated means to extract data from the Sites". | drop for now; the Eventbrite API (separate API terms) could be evaluated later if Attleborough volume appears |

## Excluded sources

| Name | URL | Why excluded |
|---|---|---|
| Eastern Baptist Association church page | https://www.easternbaptist.org.uk/church-homepage/attleborough-baptist-church/ | Directory page, no events; points to the church's own site (List 1). |
| Attleborough Methodist Church | https://www.attleboroughmethodistchurch.org.uk/ | Domain no longer resolves (DNS, 2026-10-17); Wikipedia says the church stopped holding normal services. |
| attleborough.org | https://www.attleborough.org/ | Parked domain (`window.location.href="/lander"`). |
| attleborough.org.uk | https://attleborough.org.uk/ | Resolves but refuses connections on port 443. |
| Trinity Hall, Besthorpe | https://besthorpe.org.uk/events/ | WordPress.com site; the "events" pages are village information (broadband, defibrillator), not dated listings; the terms page is the hall's conditions of hire. |
| Great Ellingham one-place study | https://greatellingham.org/ | Local history site, no event listings (WordPress, no TEC). |
| Wymondham Town Council | https://wymondhamtowncouncil.gov.uk/ | No events of its own (no robots.txt; WordPress, no TEC); links to Visit Wymondham (List 1, P2). |
| The Events Calendar demo | https://demo.theeventscalendar.com/ | Not a local source; used only to verify the TEC contract (below). |
| Attleborough u3a | http://u3asites.org.uk/attleborough/ | No working site: HTTPS fails with TLS `SSL_ERROR_SYSCALL` from both the corporate and the home network (so it isn't our gateway), and plain HTTP serves an empty holding page titled "u3asites.org.uk" (18:43-18:45 UTC). `attleborough.u3asite.uk` doesn't resolve. Owner may know the current u3a site. |

## TEC contract check (RES-02)

**First local install: Attleborough Town Council** (2026-10-17, home network, robots allows `/wp-json/`):

- `GET https://attleboroughtc.org.uk/wp-json/tribe/events/v1/events?start_date=2026-10-19&end_date=2026-12-31&per_page=50&page=1` → `200 application/json`, `x-tec-total: 112`, `x-tec-totalpages: 3`, with `next_rest_url` set.
- Captured window: `start_date=2026-10-19&end_date=2026-11-22` → `x-tec-total: 72`, `x-tec-totalpages: 2`. Page 1 has 50 events and a `next_rest_url` of `…/events/?per_page=50&start_date=2026-10-19+00%3A00%3A00&end_date=2026-11-22+23%3A59%3A59&status=publish&page=2`. Page 2 has 22 events, no `next_rest_url`, and a `previous_rest_url`.
- `GET https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1` → `200 text/calendar`, `PRODID:-//Attleborough Town Council - ECPv6.18.0//NONSGML v1.0//EN`, VTIMEZONE Europe/London, 30 VEVENTs (TEC's default export size), `etag` present, `cache-control: no-store`.
- Headers: `cache-control: no-cache, must-revalidate, max-age=0, no-store, private` on REST, so no conditional GET is possible.

**Vendor demo** (first pass, corporate network): https://demo.theeventscalendar.com/ (robots `User-agent: *` / `Disallow:`).

- REST with `per_page=50` → `x-tec-total: 19`, `x-tec-totalpages: 1`.
- REST with `per_page=5` → `x-tec-totalpages: 4` with `next_rest_url`.
- `/events/?ical=1` → `text/calendar`, `PRODID:-//The Events Calendar - ECPv6.18.2//`, 25 VEVENTs.
- Event objects on both installs carry the same fields: `id`, `title`, `start_date` (local), `utc_start_date`, `timezone`, `all_day`, `url`, `venue`, `organizer`, `categories`, `modified`.

**Not TEC:** a WordPress site without TEC answers `/wp-json/tribe/events/v1/events` with `404` `{"code":"rest_no_route",…}` (checked on https://theeventscalendar.com/, 18:11:57). Phase 3 can use this to detect "not TEC".

That makes two live installs, one of them local. The spec asked for 2-3.

## Probe log (RES-02)

One row per candidate; "-" means the rung did not apply (not WordPress, no feed advertised, page never reached).

| Candidate | robots.txt | Platform | TEC REST | TEC iCal | Feed with event dates | JSON-LD Event | Events in raw HTML | ToS |
|---|---|---|---|---|---|---|---|---|
| Attleborough Baptist Church | 200, allows, Crawl-delay 10 | Hubb (ASP.NET) | - | - | yes: RSS title/description dates; also ICS export | none | yes | owner read it: ok (page is robots-disallowed for us) |
| Attleborough Town Council | 200, allows (both networks) | WordPress + TEC 6.18 | **200**, 72 events / 2 pages (home network); LiteSpeed reCAPTCHA (corporate) | **200** `text/calendar`, 30 events | iCal advertised via `rel=alternate` | not checked | yes (home network) | no-republishing boilerplate; silent on automation |
| Attleborough Academy | 200, allows, Crawl-delay 3 | Cleverbox reactCMS | - | - | ICS (`webcal://…/calendar/events.ics`), 59 events | Organization/WebPage only | yes | prohibits compiling a database (owner holds permission) |
| Attleborough Heritage Group | 404 (no rules) | ASP.NET | - | - | none | none | yes | none (privacy page only) |
| Attleborough Primary School | 200, Crawl-delay 12000 | school CMS | - | - | not probed | none on homepage | not probed (deferred) | none linked |
| Visit Norfolk | 200, allows | Craft CMS + Sprig | - | - | none | yes (`@graph`) | yes | privacy only |
| Visit Wymondham | 200, allows all | WordPress + MEC Lite | no TEC plugin in page assets (`/wp-json/` not fetched) | - | only the site post feed is advertised (not fetched) | yes (with offsets) | yes | privacy only |
| Attleborough Players | 200, allows | WordPress | no `tribe` namespace in `/wp-json/` | - | `/feed/` 0 items | none | Facebook embed; `/gigs/` password-protected | none |
| Attleborough Summer Carnival | 404 (no rules) | One.com | - | - | none | none | yes (dates in prose) | none |
| Banham Zoo | 200, empty | Webflow | - | - | none | none | titles only; detail pages prose dates | visitor terms only |
| Breckland Council | 403 cf challenge (both networks) | Cloudflare | - | - | - | - | - | - |
| Norfolk County Council | 403 cf challenge (both networks) | Cloudflare | - | - | - | - | - | - |
| Norfolk Museums | 403 cf challenge (both networks) | Cloudflare | - | - | - | - | - | - |
| TicketSource | 403 cf challenge (both networks) | Cloudflare | - | - | - | - | - | - |
| Facebook | 200, `Disallow: /` | - | - | - | - | - | - | prohibits automated collection |
| Snetterton Circuit | 200, comments only | custom + Cloudflare | - | - | none advertised | none | no (JS) | not checked (JS-rendered already decides) |
| Attleborough Town FC | 200, format=ical/json disallowed | Squarespace | - | - | disallowed | Organization/LocalBusiness only | no events page | - |
| Eastern Daily Press | 200, allows | Newsquest + evvnt widget | - | - | none | none on listing | no (JS widget) | prohibits storage in other retrieval systems |
| Eventbrite | 200, RSS/Atom disallowed | Eventbrite | - | - | disallowed | ItemList of Event | yes | prohibits scraping |
| Attleborough u3a | http: 200 empty; https: TLS error (both networks) | Caddy holding page | - | - | - | - | no content | - |

## Fixture index

| slug | priority | files |
|---|---|---|
| attleborough-baptist-church | P0 | 2026-10-17-feed.xml, 2026-10-17-ical.ics |
| attleborough-town-council | P0 | 2026-10-17-ical.ics, 2026-10-17-tec-p1.json, 2026-10-17-tec-p2.json |
| attleborough-academy | P1 | 2026-10-17-ical.ics |
| attleborough-heritage-group | P1 | 2026-10-17-events-page.html |

**Pending (not in the index until captured):** `attleborough-primary-school` (P1, provisional). Its `Crawl-delay: 12000` means `ops/probe-sources.sh attleborough-primary-school` has to run after 23:05 BST on 2026-10-17 or later. Then add it to the table above, which makes the privacy test require its manifest.

## Risks (technical and legal)

- **Production may be blocked by the Town Council.** Its LiteSpeed bot check is IP-based: it challenged the corporate network but not a residential one. A Hetzner datacenter IP may be challenged too. Contingency: the clerk email (`docs/research/town-council-feed-request.md`) asking for allowlisting. Never bypass.
- **Four regional sites challenge automated clients everywhere** (Breckland, Norfolk County Council, Norfolk Museums, TicketSource). No fixtures or crawling until a D-25 route works: official feed/API, Cloudflare Verified Bots in Phase 5, allowlisting, or hand-captured fixtures for parser work.
- **Town Council terms** carry a standard no-republishing clause. We store facts only, with attribution and a link back, and the owner accepted the source (D-26). An explicit yes from the clerk would remove the residual risk.
- **Unstable UIDs:** the Academy ICS regenerates UIDs on every request (row number + request time). Dedupe on SUMMARY + DTSTART for that source; the Baptist Church and Town Council UIDs are stable.
- **Personal data in feeds:** the Town Council REST/ICS includes organisers' private names, Gmail addresses and mobile numbers. Phase 3 must never copy organiser contact fields to public pages, and fixtures are redacted.
- **Terms pages we can't read:** the Baptist Church's terms page is under a robots `Disallow`. The owner read it in a browser on 2026-10-17 and found no prohibition. If its wording changes, nothing alerts us, so re-check it once a year.
- **Most remaining local activity is on Facebook** (Carnival, Players, residents' groups). Spec §16 risk confirmed: manual entry and the submission form carry these.
- **Crawl delays:** Baptist Church `Crawl-delay: 10`, Academy `Crawl-delay: 3`, Attleborough Primary `Crawl-delay: 12000` (at most one request per 3 h 20 min).
- **Recurring items without RRULE:** all three ICS sources list each occurrence separately. Phase 3 dedupe keys on UID where stable, and the admin will want skip rules for routine items (services, private hires, internal school events).
- **DST:** fixtures from the Baptist Church, the Town Council and the Academy span Sun 25 Oct 2026 (the Heritage page lists only 21 Nov); the Academy ICS also spans Sun 28 Mar 2027 (all-day Easter holiday).
- **Geofence:** Visit Norfolk, Visit Wymondham and Banham Zoo are regional and need a geofence.

## Owner hand-off

Owner supplied no additional URLs on 2026-10-17 (the owner will add local URLs later; follow-up pending below).

Owner answers at the D-13 checkpoint, 2026-10-17 (recorded without personal data):

1. **Local URLs:** none yet. See "Owner URL hand-off — pending".
2. **Attleborough Baptist Church terms** (https://www.attleborough.church/User/TermsAndConditions.aspx): the owner read the page in a browser and answered "ok", meaning it doesn't prohibit automated use or republishing. The church stays on List 1 (P0) and is no longer provisional.
3. **Permission asks** (superseded the same evening by D-23/D-24, see below): the owner is contacting Attleborough Heritage Group, Visit Norfolk, Visit Wymondham, Attleborough Academy, Attleborough Primary School, Attleborough Summer Carnival, Attleborough Players and Banham Zoo. Each stays on List 2 with route "ask permission (owner contacting)" until permission is granted in writing; none is configured or captured before then. If permission arrives, record it here with the date, switch `legal_basis` to `permission_granted`, move the source to List 1 and capture fixtures. Eastern Daily Press and Eventbrite were not on the owner's list and keep their routes.
4. **Bot walls** (answered the same evening by D-25/D-26, see below): the owner will check from a home network whether https://attleboroughtc.org.uk/, https://www.breckland.gov.uk/, https://www.norfolk.gov.uk/, https://www.museums.norfolk.gov.uk/ and https://www.ticketsource.co.uk/ open normally. Status: **pending owner home-network check**. They stay on List 2 (bot wall) whatever the answer, until a probe with our own User-Agent gets through without a challenge. Never bypass a challenge.

### Owner URL hand-off — pending

Categories where the first pass found nothing, so the owner's local knowledge is needed:

- St Mary's parish church (its own site or its A Church Near You page)
- Connaught Hall
- Rosecroft Primary School (and any PTA pages)
- Rotary and Lions
- Businesses and farms
- Pubs and venues
- WI
- Local sites running The Events Calendar (look for a "Subscribe to calendar" or "Export Events" button)
- Facebook-only groups and Pages (manual entry only)

How to add a URL later, without re-running this whole plan:

1. Run the same probe ladder on it (see "How a source is classified"): robots.txt first, then the homepage or events page, `/wp-json/` and TEC REST/iCal if WordPress, advertised feeds, JSON-LD, terms page. Use our User-Agent, one request at a time, at least 5 s per host (more if robots sets `Crawl-delay`). Never bypass a bot wall, login or disallow.
2. Add a row to the Probe log, then classify it into List 1 or List 2 by the rules above. Add a row in this section with the URL and the result.
3. Capture fixtures **only** for a List 1 source at P0 or P1, following `tests/Fixtures/sources/README.md` (read the capture, redact, write the manifest), and add it to the Fixture index.
4. Run `./vendor/bin/pest --testsuite=Unit tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php`.

### Post-completion owner decisions (2026-10-17 evening, D-23..D-26)

These replace answers 3 and 4 above.

- **D-23:** ToS-unclear sources are accepted as crawlable when robots allows. Heritage Group (P1), Attleborough Primary (P1, fixtures pending), Visit Norfolk (P2), Visit Wymondham (P2), Players (P3), Summer Carnival (P3) and Banham Zoo (P3) moved to List 1 with `legal_basis` `factual_extract_only`. The basis is robots allows + terms silent + owner decision + polite crawling + attribution and a link back. The permission asks for these are no longer needed.
- **D-24:** Attleborough Academy is crawlable under written permission held by the owner (not committed). It moved to List 1 as P1 with `permission_granted`, and its ICS fixture is captured.
- **D-25 (revised):** Breckland Council, Norfolk County Council, Norfolk Museums and TicketSource challenge our honest User-Agent from the home network as well. They stay on List 2 (bot wall), with legitimate routes only: official feeds/APIs (e.g. the TicketSource organiser API), Cloudflare Verified Bots in Phase 5, allowlist requests, and hand-captured browser fixtures (`capture: hand`). The home-network check asked for in answer 4 is done: still blocked.
- **D-26 (revised):** the Town Council serves normally to a residential network. It moved to List 1 as P0 (TEC REST + iCal) and its fixtures are captured, redacted. The clerk email is kept as a courtesy note and a contingency in case the production server is blocked: `docs/research/town-council-feed-request.md`.
- **Tools for the owner:** `ops/probe-sources.sh`, a polite re-check and capture script (see `tests/Fixtures/sources/README.md`, "How to run at home"). Its first real job is Attleborough Primary's calendar after the crawl delay.
