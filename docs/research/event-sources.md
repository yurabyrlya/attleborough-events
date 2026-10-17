# Attleborough event sources: verified inventory

- **Date of probes:** 2026-10-17 (Saturday), 17:56-18:13 UTC, from a developer machine in the UK.
- **Method:** curl + manual review, one request at a time, at least 5 s between requests to one host (11 s where robots.txt sets `Crawl-delay: 10`). Every row below records what the server actually returned. No research tooling was added to `app/`.
- **User-Agent:** `WhatsOnAttleboroughResearch/0.1 (+mailto:hello@whatsonattleborough.co.uk)` on every request.
- **Network caveat:** this machine sits behind a corporate security gateway (Cisco Umbrella / OpenDNS). When the gateway answers instead of the site (an OpenDNS block page, a redirect to `block.opendns.com`, a TLS error), the result is recorded as **no observation (local gateway)**, never as "blocked by site". Cloudflare `cf-mitigated: challenge` and LiteSpeed reCAPTCHA pages carry the site's own `server:` header and are recorded as the site's **bot wall**; Cloudflare may be reacting to the corporate egress IP's reputation, so those four hosts should be re-probed once from a home network before anyone gives up on them (see Risks). No other network was available during this pass.
- **URL discovery:** web search was not usable: DuckDuckGo is blocked by the local gateway (OpenDNS block page), and Bing and Mojeek disallow `/search` in robots.txt. URLs came from Wikipedia's Attleborough article (its external links and its Churches/Education sections), links on probed sites (Heritage Group "Useful Links", Eastern Baptist Association, Wymondham Town Council) and DNS checks of likely domain names. Two early discovery queries went to `https://en.wikipedia.org/w/api.php` and `https://www.wikidata.org/w/api.php` before we noticed that both hosts' robots.txt disallow `/w/` for `User-agent: *`; no further `/w/` requests were made.
- **Requirements:** RES-01 (verified sources with fields), RES-02 (live ladder probes), RES-03 (fixtures). Decisions D-13 (agent first pass, then owner), D-14 (fixtures), D-16 (two lists).

## How a source is classified (D-16)

The probe ladder per candidate: robots.txt → homepage/events page (platform fingerprint, server-rendered y/n, `<link rel="alternate">` feeds, Google Calendar embeds) → WordPress `/wp-json/` namespaces and The Events Calendar (TEC) REST `/wp-json/tribe/events/v1/events` and iCal `?ical=1` → feeds (do items carry an **event** date? `pubDate` never counts) → JSON-LD `Event` (including `@graph`) → terms/legal page.

- **List 1, Crawlable:** robots.txt allows every URL we need, no ToS prohibition was found, **and** a deterministic machine-readable method works that the site itself offers for reuse (an advertised ICS/RSS/TEC feed: `<link rel="alternate">`, a visible "Export"/"Subscribe" control, or a robots.txt `Allow` for the calendar file). Offering the feed is the syndication signal, so `legal_basis` is `own_feed_published_for_syndication`.
- **List 2, Not crawlable, for owner review:** robots-disallowed, bot wall, ToS-prohibited, ToS-unclear (robots allows but the ToS is silent or vague, and the only route is HTML or JSON-LD the site doesn't offer for reuse), JS-rendered, login-walled, Facebook. Nothing on List 2 is configured as a source until the owner clears it.
- One organisation reachable at two URLs gets one entry per URL with a cross-reference. Unknown volume is written `unknown`.

## Coverage by category

Every spec §14 category, including the ones where nothing was found.

| Spec §14 category | Candidates found (list) | Searches / probes tried |
|---|---|---|
| Town Council | Attleborough Town Council (List 2, bot wall) | Wikipedia external link `attleboroughtc.org.uk`; DNS for `attleboroughtowncouncil.gov.uk`, `attleborough-tc.gov.uk`, `attleborough.gov.uk` (none resolve) |
| Breckland Council | Breckland Council (List 2, bot wall) | `www.breckland.gov.uk/robots.txt` |
| Schools (and PTAs) | Attleborough Academy (List 2, ToS-prohibited); Attleborough Primary School (List 2, ToS-unclear / Crawl-delay 12000) | Wikipedia Education section (Academy, Rosecroft Primary, Attleborough Primary, Wymondham College); DNS for 9 Rosecroft / Attleborough Primary domain shapes (only `attleboroughprimary.org` resolves). Rosecroft Primary: none found. PTA: Attleborough Primary links a "Friends of APS" page (not probed, same host crawl-delay) |
| Churches | Attleborough Baptist Church (**List 1, P0**); Eastern Baptist Association church page (Excluded, directory page) | Wikipedia Churches section; St Mary's (parish church): none found (DNS for 5 domain shapes; A Church Near You search is JS-rendered, `achurchnearyou.com/robots.txt` allows `/`); Methodist church: domain from Wikipedia no longer resolves and the church "has stopped holding normal services" (Wikipedia) |
| Halls (e.g. Connaught Hall) | Trinity Hall, Besthorpe (Excluded, no event listing) | DNS for 6 Connaught Hall domain shapes (none resolve); Connaught Hall: none found |
| Sports clubs | Attleborough Town FC (List 2, robots-disallowed) | Spec note (Squarespace); DNS for rugby, cricket and bowls club domain shapes (none resolve) |
| Charities (Rotary, Lions) | none found | DNS for 6 Rotary/Lions domain shapes (none resolve) |
| Businesses, farms | none found | no discovery route without search; owner hand-off |
| Pubs and venues | Attleborough Players (theatre company, List 2, ToS-unclear) | DNS for Griffin/Queens pub domain shapes (none resolve) |
| Library and heritage centre | Attleborough Heritage Group (List 2, ToS-unclear); Norfolk County Council libraries (List 2, bot wall) | `attleboroughheritage.org.uk`; `www.norfolk.gov.uk/robots.txt` |
| Nearby attractions | Snetterton Circuit (List 2, JS-rendered); Banham Zoo (List 2, ToS-unclear); Norfolk Museums incl. Gressenhall (List 2, bot wall) | `www.snetterton.co.uk`, `www.banhamzoo.co.uk`, `www.museums.norfolk.gov.uk` |
| Norfolk directories | Visit Norfolk (List 2, ToS-unclear); Visit Wymondham (List 2, ToS-unclear) | `visitnorfolk.co.uk/events`, `visitwymondham.org.uk/whats-on/` (linked from Wymondham Town Council) |
| Eventbrite | Eventbrite (List 2, ToS-prohibited) | robots.txt, `/d/united-kingdom--attleborough/all-events/`, Terms of Service |
| TicketSource | TicketSource (List 2, bot wall) | robots.txt (twice: research session and this pass) |
| Local news | Eastern Daily Press (List 2, ToS-prohibited) | `edp24.co.uk/things-to-do/local-events/`, Newsquest terms. Watton & Swaffham Times and Diss Express resolve but were not probed (Newsquest titles share the EDP terms; Diss is outside the likely geofence) |
| Facebook Pages/Groups | Facebook (List 2, Facebook) | `www.facebook.com/robots.txt`; Facebook Pages referenced by the Carnival, Players and Besthorpe sites |
| Community events (carnival, u3a, WI) | Attleborough Summer Carnival (List 2, ToS-unclear); Attleborough u3a (no observation, local gateway) | `attleboroughcarnival.co.uk`; `u3asites.org.uk/attleborough/` (TLS `SSL_ERROR_SYSCALL`); WI: none found |
| Any site with ICS/RSS/JSON-LD | Attleborough Baptist Church ICS + dated RSS (List 1); Visit Norfolk and Visit Wymondham JSON-LD (List 2) | ladder on every candidate above; TEC contract checked on the vendor demo (see "TEC contract check") |

## List 1: Crawlable

Ordered by priority, then name. **One source.** The search found no other source that both offers a feed and has no ToS problem; see Risks.

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
| ToS finding | No prohibition found. The site's terms page `/User/TermsAndConditions.aspx` sits under `Disallow: /User/`, so it was **not fetched** (robots is honoured even for legal pages). The platform's legal page (https://hubb.church/Groups/420336/Legal.aspx) only offers downloadable T&Cs for Hubb customers. The site explicitly offers both feeds for use "from other applications", and the platform's robots.txt explicitly allows calendar ICS files. **Owner to read the terms page once in a browser** (checkpoint). |
| legal_basis | `own_feed_published_for_syndication` |
| Feasibility | high: one request per run, valid ICS (sabre parses all 64 events), stable ids, DST handled (18 Oct 10:30 BST → `20261018T093000Z`, 25 Oct 10:30 GMT → `20261025T103000Z`) |
| Legal risk | low (pending the owner's read of the terms page) |
| Priority | P0 (trusted local organisation, regular events, rung 1) |
| Example event URL | https://www.attleborough.church/Calendar/Month.aspx?show_event=5936475 (Service, Sun 18 Oct 2026 10:30) |
| Fixture files | `tests/Fixtures/sources/attleborough-baptist-church/2026-10-17-feed.xml`, `tests/Fixtures/sources/attleborough-baptist-church/2026-10-17-ical.ics` (64 events each, spans BST end on 25 Oct 2026; no personal data, unmodified) |
| Rung / config notes | Use the ICS. Geofence: inside Attleborough. Most items are worship services; the admin may want a per-source rule that skips routine services and keeps community events (Warm Welcome, Women's Creative Club). Same organisation also listed on the Eastern Baptist Association site (Excluded). |

## List 2: Not crawlable, for owner review

Ordered by reason, then name. All probes 2026-10-17 (UTC times in the evidence).

| Name | URL | Reason | Evidence | Suggested route |
|---|---|---|---|---|
| Attleborough Town Council | https://attleboroughtc.org.uk/ | bot wall | 17:56. robots.txt `200` (`User-agent: *` / `Disallow: /wp-admin/`), but `/`, `/wp-json/` and `/wp-sitemap.xml` all return `200` with `<title>Bot Verification</title>` and a LiteSpeed reCAPTCHA form (`action="/.lsrecap/recaptcha?"`, `server: LiteSpeed`). WordPress (`x-redirect-by: WordPress`). | ask permission (ask the clerk to allow-list our User-Agent, or whether the council can publish a calendar feed); manual entry meanwhile |
| Breckland Council | https://www.breckland.gov.uk/ | bot wall | Research session (earlier on 2026-10-17) and again 18:01:51: robots.txt `HTTP/2 403`, `cf-mitigated: challenge`, `server: cloudflare`, body `<title>Just a moment...</title>`. | re-probe from a home network; else manual entry |
| Norfolk County Council (libraries, incl. Attleborough Library) | https://www.norfolk.gov.uk/ | bot wall | 18:01:52: robots.txt `HTTP/2 403`, `cf-mitigated: challenge`, `server: cloudflare`. | re-probe from a home network; else manual entry |
| Norfolk Museums (incl. Gressenhall Farm and Workhouse) | https://www.museums.norfolk.gov.uk/ | bot wall | 18:00:01: robots.txt and `/` `HTTP/2 403`, `cf-mitigated: challenge`, `server: cloudflare`, `<title>Just a moment...</title>`. | re-probe from a home network; else manual entry (regional, P2 at best) |
| TicketSource | https://www.ticketsource.co.uk/ | bot wall | Research session (earlier on 2026-10-17) and again 18:01:52: robots.txt `HTTP/2 403`, `cf-mitigated: challenge`, `server: cloudflare`. Attleborough Players sell tickets through a TicketSource widget. | re-probe from a home network; else manual entry |
| Facebook Pages and Groups | https://www.facebook.com/ | Facebook | 18:12:16: robots.txt `User-agent: *` / `Disallow: /`, and "Collection of data on Facebook through automated means is prohibited unless you have express written permission from Facebook". Spec §8.7: no Facebook scraping. Referenced Pages: https://www.facebook.com/attleboroughsummercarnival (Carnival), Attleborough Players' Page (embedded feed), Besthorpe residents' group. | manual entry |
| Snetterton Circuit | https://www.snetterton.co.uk/calendar/full | JS-rendered | 18:03:36. robots.txt has only Cloudflare content-signal comments, no `Disallow` for `*`. `/calendar/full` returns `200` but no events in the raw HTML (page text is navigation only); no JSON-LD. Matches spec §14. | manual entry for headline race weekends (P3) |
| Attleborough Town FC | https://www.attleboroughtownfc.co.uk/ | robots-disallowed | 18:00:29. Squarespace (`static1.squarespace.com`). robots.txt `User-agent: *`: `Disallow:/*?format=ical`, `Disallow:/*?format=json`, `Disallow:/*&format=ical`. No events collection on the site (pages: about, history, teams, sponsors). | manual entry; ask the club whether it publishes fixtures elsewhere |
| Attleborough Academy | https://www.attleboroughacademy.org/100/calendar | ToS-prohibited | 18:01. Robots allows (`User-agent: *`, `Crawl-delay: 3`, no rule for `/calendar/`). Calendar offers `webcal://www.attleboroughacademy.org/calendar/events.ics` (Cleverbox reactCMS). Terms of use (https://www.attleboroughacademy.org/3/terms-of-use): "You agree that You will not: (i) distribute the Content for any purpose including without limitation compiling an internal database, redistributing or reproduction of the Content…". Most items are school-internal (term dates, mocks, INSET). | ask permission (public events only: presentation evening, productions); else drop |
| Eastern Daily Press | https://www.edp24.co.uk/local-events/ | ToS-prohibited | 18:04. Robots allows `/local-events/`. Calendar is an evvnt JS widget (`EvvntModule`), no events in raw HTML. Newsquest terms (https://www.newsquest.co.uk/legal/terms-conditions/): "No part of the Site can be reproduced on, transmitted to or stored on any other web site or other form of electronic retrieval system". | drop (or ask evvnt about a licensed feed) |
| Eventbrite | https://www.eventbrite.co.uk/d/united-kingdom--attleborough/all-events/ | ToS-prohibited | 18:02. robots.txt `User-agent: *`: `Disallow: /rss/`, `Disallow: /atom/`, `Disallow: /events/rss/`, `Disallow: /events/atom/`; `/d/` and `/e/` allowed; listing has `ItemList` JSON-LD of `Event`s, all 20 in Norwich/Costessey/Redgrave, none in Attleborough. Terms of Service (https://www.eventbrite.co.uk/help/en-gb/articles/251210/eventbrite-terms-of-service/): "You have no right to, and you agree not to, scrape, crawl, or employ any automated means to extract data from the Sites". | drop for now; the Eventbrite API (separate API terms) could be evaluated later if Attleborough volume appears |
| Attleborough Heritage Group | https://www.attleboroughheritage.org.uk/Events/FutureEvents.aspx | ToS-unclear | 17:58. robots.txt `404` (no rules). ASP.NET (`server: Microsoft-IIS/8.5`). Events server-rendered (one upcoming: Coffee and Chat, Sat 21 Nov 2026, St Mary's Church Hall). No feed, no JSON-LD. Only a privacy page (Google Analytics wording); no terms. | ask permission (selectors, ~1 event/month) or manual entry |
| Attleborough Players | https://attleboroughplayers.co.uk/ | ToS-unclear | 17:58. Robots allows (`Disallow: /wp-admin/`). WordPress, no TEC (`/wp-json/` namespaces: oembed, redirection, sbc, wpforms, wp/v2…). Show news comes from an embedded Facebook feed (custom-facebook-feed-pro) and a TicketSource ticket-shop widget; `/feed/` has 0 items. No terms page. 2-3 productions a year. | manual entry |
| Attleborough Primary School | https://www.attleboroughprimary.org/key-information/school-calendar | ToS-unclear | 18:10. robots.txt `User-agent: *`, `Crawl-delay: 12000` (one request per 3 h 20 min), `Disallow: /admin`. The homepage was fetched 6 s after robots.txt, before the crawl-delay was read; no further requests. Calendar page not probed; no terms link on the homepage. | owner to look at the calendar in a browser; ask permission if it lists public events (fairs, PTA "Friends of APS") |
| Attleborough Summer Carnival | https://attleboroughcarnival.co.uk/ | ToS-unclear | 17:58. robots.txt `404` (no rules). One.com Web Editor. Server-rendered text: "Attleborough Summer Carnival 2026 3rd-5th July", Party on the Park 3-4 July, Carnival Day 5 July, Santa's Sleigh. No feed, no JSON-LD, "Copyright @ All rights reserved", no terms. Cross-reference: Facebook row. | manual entry (3-4 events a year) |
| Banham Zoo | https://www.banhamzoo.co.uk/events | ToS-unclear | 18:03. robots.txt `200` but empty (no rules). Webflow + HubSpot. Events page server-rendered but titles only ("trick or treat", "the big zoo snooze"), no dates, no JSON-LD. `/terms` covers tickets and visits only, silent on website use. ~7 miles away. | manual entry for seasonal events (P2) |
| Visit Norfolk | https://visitnorfolk.co.uk/events | ToS-unclear | 18:02. robots.txt `User-agent: *`: `Disallow: /cpresources/`, `/vendor/`, `/.env`, `/cache/`. Craft CMS + Sprig (htmx). Event pages carry `Event` JSON-LD in `@graph` (e.g. `/events/parkrun-23`). Location filter has "Attleborough" (`value="46837"`) but filtering goes through `/actions/sprig-core/components/render`. Footer links only a privacy policy; no website terms. | ask permission (Visit East of England may have a partner data feed); geofenced P2 if cleared |
| Visit Wymondham | https://visitwymondham.org.uk/whats-on/ | ToS-unclear | 18:10. robots.txt `User-agent: *` / `Disallow:` (empty). WordPress + Modern Events Calendar Lite (not TEC). 13 JSON-LD blocks incl. `Event` with offsets (`2026-10-25T13:30:00+00:00`, correct after BST ends). Only a privacy policy; no terms. Wymondham is ~6 miles away. | ask permission; geofenced P2 if cleared |

## Excluded sources

| Name | URL | Why excluded |
|---|---|---|
| Eastern Baptist Association church page | https://www.easternbaptist.org.uk/church-homepage/attleborough-baptist-church/ | Directory page, no events; points to the church's own site (List 1). |
| Attleborough Methodist Church | https://www.attleboroughmethodistchurch.org.uk/ | Domain no longer resolves (DNS, 2026-10-17); Wikipedia says the church stopped holding normal services. |
| attleborough.org | https://www.attleborough.org/ | Parked domain (`window.location.href="/lander"`). |
| attleborough.org.uk | https://attleborough.org.uk/ | Resolves but refuses connections on port 443. |
| Trinity Hall, Besthorpe | https://besthorpe.org.uk/events/ | WordPress.com site; the "events" pages are village information (broadband, defibrillator), not dated listings; the terms page is the hall's conditions of hire. |
| Great Ellingham one-place study | https://greatellingham.org/ | Local history site, no event listings (WordPress, no TEC). |
| Wymondham Town Council | https://wymondhamtowncouncil.gov.uk/ | No events of its own (no robots.txt; WordPress, no TEC); links to Visit Wymondham (List 2). |
| The Events Calendar demo | https://demo.theeventscalendar.com/ | Not a local source; used only to verify the TEC contract (below). |

## TEC contract check (RES-02)

No Attleborough-area site running The Events Calendar was found in this pass (Players, Great Ellingham, Eastern Baptist Association and Wymondham Town Council expose no `tribe/events/v1` namespace in `/wp-json/`; Visit Wymondham loads Modern Events Calendar Lite, not TEC). The contract Phase 3 relies on was verified live on the vendor's demo install, robots `User-agent: *` / `Disallow:`:

- `GET https://demo.theeventscalendar.com/wp-json/tribe/events/v1/events?start_date=2026-10-19&end_date=2026-11-30&per_page=50&page=1` → `200 application/json`, headers `x-tec-total: 19`, `x-tec-totalpages: 1`, body `total: 19`, `total_pages: 1`, no `next_rest_url`.
- Same with `per_page=5` → `x-tec-totalpages: 4`, `next_rest_url: …/events/?per_page=5&start_date=2026-10-19+00%3A00%3A00&end_date=2026-11-30+23%3A59%3A59&status=publish&page=2`. Event objects carry `id`, `title`, `start_date` (local), `utc_start_date`, `timezone`, `all_day`, `url`, `venue`.
- `GET https://demo.theeventscalendar.com/events/?ical=1` → `200 text/calendar`, `PRODID:-//The Events Calendar - ECPv6.18.2//NONSGML v1.0//EN`, VTIMEZONE included, 25 VEVENTs.
- A WordPress site without TEC answers `/wp-json/tribe/events/v1/events` with `404` `{"code":"rest_no_route",…}` (checked on https://theeventscalendar.com/, 18:11:57), which is how Phase 3 can detect "not TEC".
- Only one live install was verified (the spec asks for 2-3). The owner may know local TEC sites (look for a "Subscribe to calendar" / "Export Events" button).

## Probe log (RES-02)

One row per candidate; "-" means the rung did not apply (not WordPress, no feed advertised, page never reached).

| Candidate | robots.txt | Platform | TEC REST | TEC iCal | Feed with event dates | JSON-LD Event | Events in raw HTML | ToS |
|---|---|---|---|---|---|---|---|---|
| Attleborough Baptist Church | 200, allows, Crawl-delay 10 | Hubb (ASP.NET) | - | - | yes: RSS title/description dates; also ICS export | none | yes | not fetchable (`/User/` disallowed); none found |
| Attleborough Town Council | 200, allows | WordPress (header) | bot wall | bot wall | bot wall | bot wall | bot wall | bot wall |
| Breckland Council | 403 cf challenge | Cloudflare | - | - | - | - | - | - |
| Norfolk County Council | 403 cf challenge | Cloudflare | - | - | - | - | - | - |
| Norfolk Museums | 403 cf challenge | Cloudflare | - | - | - | - | - | - |
| TicketSource | 403 cf challenge | Cloudflare | - | - | - | - | - | - |
| Facebook | 200, `Disallow: /` | - | - | - | - | - | - | prohibits automated collection |
| Snetterton Circuit | 200, comments only | custom + Cloudflare | - | - | none advertised | none | no (JS) | not checked (JS-rendered already decides) |
| Attleborough Town FC | 200, format=ical/json disallowed | Squarespace | - | - | disallowed | Organization/LocalBusiness only | no events page | - |
| Attleborough Academy | 200, allows, Crawl-delay 3 | Cleverbox reactCMS | - | - | webcal ICS offered | Organization/WebPage only | yes | prohibits compiling a database |
| Eastern Daily Press | 200, allows | Newsquest + evvnt widget | - | - | none | none on listing | no (JS widget) | prohibits storage in other retrieval systems |
| Eventbrite | 200, RSS/Atom disallowed | Eventbrite | - | - | disallowed | ItemList of Event | yes | prohibits scraping |
| Attleborough Heritage Group | 404 (no rules) | ASP.NET | - | - | none | none | yes | none (privacy page only) |
| Attleborough Players | 200, allows | WordPress | no `tribe` namespace in `/wp-json/` | - | `/feed/` 0 items, no event dates | none | Facebook embed | none |
| Attleborough Primary School | 200, Crawl-delay 12000 | school CMS | - | - | not probed | none on homepage | not probed | none linked |
| Attleborough Summer Carnival | 404 (no rules) | One.com | - | - | none | none | yes (dates in text) | none |
| Banham Zoo | 200, empty | Webflow | - | - | none | none | titles only, no dates | visitor terms only |
| Visit Norfolk | 200, allows | Craft CMS + Sprig | - | - | none | yes (`@graph`) | yes | privacy only |
| Visit Wymondham | 200, allows all | WordPress + MEC Lite | no TEC plugin in page assets (MEC Lite instead; `/wp-json/` not fetched) | - | only the site post feed is advertised (not fetched) | yes (with offsets) | yes | privacy only |
| Attleborough u3a | no observation (local gateway: TLS `SSL_ERROR_SYSCALL`) | - | - | - | - | - | - | - |

## Fixture index

| slug | priority | files |
|---|---|---|
| attleborough-baptist-church | P0 | 2026-10-17-feed.xml, 2026-10-17-ical.ics |

## Risks (technical and legal)

- **List 1 has one source.** Phase 3's ICS/RSS rungs have a real local fixture; TEC, JSON-LD and selector rungs do not have a cleared local source yet. The calendar will depend on manual entry and submissions until List 2 sources are cleared (permission, home-network re-probe) or the owner adds sources.
- **Bot walls may be our network, not the sites.** Breckland, Norfolk County Council, Norfolk Museums and TicketSource all answered with a Cloudflare managed challenge from this corporate network, even on robots.txt. A re-probe from a home connection may give a different result; until then they stay "bot wall".
- **Terms pages we can't read.** The Baptist Church's terms page is under a robots `Disallow`; its List 1 placement rests on the explicit feed offer and needs the owner's one-time browser read.
- **Most local activity is on Facebook** (Carnival, Players, residents' groups). Spec §16 risk confirmed: manual entry and the submission form carry these.
- **Crawl delays:** Baptist Church `Crawl-delay: 10` (fine for one request per run); Attleborough Primary `Crawl-delay: 12000` (at most one request per 3 h 20 min).
- **Recurring items without RRULE:** the Hubb ICS lists each weekly service separately; Phase 3 dedupe must key on `UID`, and the admin will want a skip rule for routine services.
- **DST:** both Baptist Church fixtures span Sun 25 Oct 2026; UTC conversion in the source is correct, which makes them good DST regression fixtures. No captured window reaches Sun 28 Mar 2027.
- **Geofence:** Visit Norfolk, Visit Wymondham, Eventbrite and Banham Zoo are regional; any of them, if cleared, needs a geofence.
- **Personal data:** none of the committed fixtures contained emails, phone numbers or private names. Public pages probed did contain personal addresses (e.g. a parish chair's Gmail on the Besthorpe site), which is why only List 1 sources are captured and every capture is read before commit.

## Owner hand-off

Pending: the owner's local URLs are collected at the D-13 checkpoint (Task 3).
