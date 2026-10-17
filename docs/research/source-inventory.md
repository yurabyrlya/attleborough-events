# What's On Attleborough: event source inventory

Owner-facing summary of every researched source, grouped by how we can use it. Status as of **17 October 2026**. Every classification rests on a live probe or the owner's decisions D-13 to D-26 (`.planning/phases/02-draft-model-review-queue-submissions/02-CONTEXT.md`). Technical evidence (probe logs, robots.txt rules, terms quotes, endpoints, fixtures) lives in [event-sources.md](./event-sources.md).

## At a glance

| Group | Sources | How events reach the calendar |
|---|---|---|
| A. Feed or API | 3 | Automatic import (Phase 3) |
| B. Crawlable web pages | 4 | Automatic import with page parsers (Phases 3-4) |
| C. Allowed, but manual entry | 3 | Admin enters events by hand |
| D. Blocked | 9 | Manual entry until a solution below works |
| E. Excluded (no usable events) | 9 | Not used |

Every imported event still goes to the review queue first: nothing is published without admin approval.

## A. Feed or API (automatic import)

The best sources: the site itself publishes a machine-readable calendar.

| Priority | Source | Events page | Feed or API | Legal basis | Notes |
|---|---|---|---|---|---|
| P0 | Attleborough Town Council | https://attleboroughtc.org.uk/events/ (opens the Town Hall category) | **API:** `https://attleboroughtc.org.uk/wp-json/tribe/events/v1/events` (The Events Calendar REST, JSON). **ICS:** `https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1` | Robots allows; facts only (title, date, time, venue) with attribution and link back (D-26) | Highest volume, about 60 events a month (Town Hall groups, meetings, Remembrance, ATFC home games). Its terms have a generic no-republishing clause, so the courtesy email asks the clerk for an explicit yes. Feeds contain private organisers' emails and phones: never shown publicly. Production risk: the site blocked the corporate network by IP, so the Hetzner server might be blocked too (the clerk email covers this). |
| P0 | Attleborough Baptist Church | https://www.attleborough.church/Calendar/Month.aspx | **ICS:** `https://www.attleborough.church/Calendar/Calendar.ics`. **RSS (dated):** `https://www.attleborough.church/calendar/rss.xml?days=150` | The site offers the feed "for other applications"; owner checked the terms (ok) | About 13 events a month, mostly services; community events (Warm Welcome, Women's Creative Club) are the useful ones. Honour `Crawl-delay: 10`. |
| P1 | Attleborough Academy | https://www.attleboroughacademy.org/100/calendar | **ICS:** `https://www.attleboroughacademy.org/calendar/events.ics` | Owner's written permission (D-24) | About 6 a month, mostly school-internal; a few public events a term (open evenings, presentations). Event IDs change on every fetch, so duplicates are matched on title and start time. |

## B. Crawlable web pages (automatic import with page parsers)

No feed, but robots.txt allows the pages and the dates can be read reliably from structured data or the page layout.

| Priority | Source | Events page | Method | Legal basis | Notes |
|---|---|---|---|---|---|
| P1 | Attleborough Heritage Group | https://www.attleboroughheritage.org.uk/Events/FutureEvents.aspx | Page parser (date headings in a table) | Robots allows, terms silent, owner decision (D-23) | About 1 a month; the Town Council feed also lists its Town Hall sessions. |
| P1 | Attleborough Primary School | https://www.attleboroughprimary.org/key-information/school-calendar | To be decided after the first fetch | Robots allows, terms silent, D-23 | Robots asks for 3 h 20 min between requests. **Owner action:** run `ops/probe-sources.sh attleborough-primary-school` after 23:05 on 17 Oct, then redact before committing. |
| P2 | Visit Norfolk | https://visitnorfolk.co.uk/events | Schema.org `Event` data on each event page | Robots allows, terms silent, D-23 | Regional directory: keep only events inside the Attleborough area (geofence). |
| P2 | Visit Wymondham | https://visitwymondham.org.uk/whats-on/ | Schema.org `Event` data on the listing page | Robots allows, terms silent, D-23 | Regional (Wymondham area): geofence, few Attleborough events. |

## C. Allowed, but manual entry

Crawling is allowed, but the sites give no reliable machine-readable dates, so the admin enters these by hand.

| Priority | Source | Events page | Why manual | Notes |
|---|---|---|---|---|
| P3 | Attleborough Players | https://attleboroughplayers.co.uk/ | News comes from an embedded Facebook feed and a TicketSource ticket widget (both blocked, see D) | Fewer than 1 show a month. Ask the Players to submit shows through our form. |
| P3 | Attleborough Summer Carnival | https://attleboroughcarnival.co.uk/ | Dates written in prose only ("3rd-5th July") | Once a year; also posts on Facebook. |
| P3 | Banham Zoo | https://www.banhamzoo.co.uk/events | Titles only on the listing; dates in prose on detail pages | 1-2 a month, regional. |

## D. Blocked: reasons and possible solutions

We never bypass a block: no disguising the crawler as a browser, no CAPTCHA solving, no proxies. Until a solution below works, these sources' events arrive by manual entry or our submission form.

| Source | Events page | Why blocked | Possible solutions (best first) |
|---|---|---|---|
| Breckland Council | https://www.breckland.gov.uk/whatson | **Bot wall.** Cloudflare challenges automated clients on every network (our honest crawler gets `403 cf-mitigated: challenge`, even on robots.txt). No official feed or API: What's On is curated by hand from a submission form. | 1. Subscribe a dedicated mailbox to Breckland's official email alerts (https://public.govdelivery.com/accounts/UKBRECKLAND/subscriber/new) and import from the emails. 2. Submit our flagship events through https://www.breckland.gov.uk/whatsonform and ask their comms team to list our calendar. 3. Cloudflare Verified Bots once our production crawler is public (Phase 5). 4. Ask them to allowlist our crawler. |
| Norfolk County Council: Libraries (incl. Attleborough Library) | https://www.norfolk.gov.uk/libraryonline | **Bot wall** (Cloudflare, as Breckland). No ICS, RSS or API found; the library catalogue runs on Spydus. | 1. Email Norfolk Libraries asking for an ICS/RSS export or a monthly list for Attleborough Library. 2. Cloudflare Verified Bots (Phase 5). 3. Allowlist request. |
| Norfolk Museums (incl. Gressenhall Farm and Workhouse) | https://www.museums.norfolk.gov.uk/events | **Bot wall** for our crawler (Cloudflare). No ticketing platform or feed. Robots allows everything. | 1. Ask Norfolk Museums' marketing team to send us their events regularly. 2. Cloudflare Verified Bots (Phase 5), then a polite scheduled fetch of the events page. 3. Allowlist request. |
| TicketSource | https://www.ticketsource.co.uk/whats-on | **Bot wall** (Cloudflare). **No public or partner API:** organiser tools only cover an organiser's own events. Robots disallows its `/feed/`. | 1. Ask local organisers who sell through TicketSource (e.g. Attleborough Players) to send their events or use our form. 2. Cloudflare Verified Bots (Phase 5) for public event pages, honouring robots. |
| Facebook Pages and Groups (e.g. https://www.facebook.com/attleboroughsummercarnival) | — | **Robots and terms.** robots.txt disallows everything, and Facebook's terms prohibit automated collection without written permission. Project rule: no Facebook scraping. | Manual entry by the admin; ask group organisers to use our submission form. |
| Snetterton Circuit | https://www.snetterton.co.uk/calendar/full | **JavaScript-rendered.** Robots allows, but the events only appear after JavaScript runs; there's no event data in the page or structured data. | 1. Manual entry for headline race weekends (P3). 2. Later: check whether the calendar loads from a public JSON endpoint that robots allows, or use a headless browser if robots and terms allow it. |
| Attleborough Town FC | https://www.attleboroughtownfc.co.uk/ | **Robots.** robots.txt disallows its calendar and JSON exports, and the site has no events section. | Already covered: home games appear in the Town Council feed. Manual entry for anything else. |
| Eastern Daily Press (local events) | https://www.edp24.co.uk/local-events/ | **Terms prohibit reuse** ("No part of the Site can be reproduced … or stored on any other web site"); listings are a JavaScript widget from evvnt. | Drop, or ask evvnt about a licensed feed. |
| Eventbrite | https://www.eventbrite.co.uk/d/united-kingdom--attleborough/all-events/ | **Terms prohibit scraping** ("you agree not to scrape, crawl, or employ any automated means"). None of the 20 listed events was in Attleborough on 17 Oct. | Drop for now. If Attleborough events appear, evaluate the official Eventbrite API (separate API terms; it fetches by organiser or venue, not by area). |

## E. Excluded (no usable events)

| Source | Link | Why |
|---|---|---|
| Eastern Baptist Association church page | https://www.easternbaptist.org.uk/church-homepage/attleborough-baptist-church/ | Directory page; the church's own site is in group A. |
| Attleborough Methodist Church | https://www.attleboroughmethodistchurch.org.uk/ | Domain no longer resolves; normal services have stopped. |
| attleborough.org | https://www.attleborough.org/ | Parked domain. |
| attleborough.org.uk | https://attleborough.org.uk/ | Refuses secure connections. |
| Trinity Hall, Besthorpe | https://besthorpe.org.uk/events/ | Village information, not dated events. |
| Great Ellingham one-place study | https://greatellingham.org/ | Local history site, no events. |
| Wymondham Town Council | https://wymondhamtowncouncil.gov.uk/ | No events of its own; links to Visit Wymondham (group B). |
| Attleborough u3a | http://u3asites.org.uk/attleborough/ | No working site (secure connection fails; plain HTTP shows an empty holding page). The owner may know the current site. |
| The Events Calendar demo | https://demo.theeventscalendar.com/ | Not local; used only to check the feed format. |

## Gaps: nothing found yet

These categories from the spec have no source yet. The owner will add local URLs; each one gets the same checks.

- St Mary's parish church (Church Near You search needs JavaScript)
- Connaught Hall
- Rosecroft Primary School
- Rotary and Lions
- Pubs and venues
- Businesses and farms
- Women's Institute (some sessions appear in the Town Council feed)
- Any other local site using The Events Calendar
- Facebook-only groups (manual entry only)

## Owner actions

1. **Tonight after 23:05:** run `ops/probe-sources.sh attleborough-primary-school` (steps in `tests/Fixtures/sources/README.md`) and redact before committing.
2. **Send the Town Clerk email:** [town-council-feed-request.md](./town-council-feed-request.md). It asks for an explicit yes to republish event facts and, if needed, allowlisting for our server.
3. **Ask for data directly:** Breckland (email alerts + submission form), Norfolk Libraries (export or monthly list), Norfolk Museums (send us events), local TicketSource organisers such as Attleborough Players.
4. **Add local URLs** for the gaps above.
5. **Phase 5:** apply for Cloudflare Verified Bots once the production crawler is public (bot info page, distinct crawler name with contact URL, published IP list or reverse DNS, robots compliance). This could unlock Breckland, Norfolk Libraries, Norfolk Museums and TicketSource public pages.
