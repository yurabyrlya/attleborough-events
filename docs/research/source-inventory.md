# What's On Attleborough: event source inventory

Owner-facing summary of every researched source, grouped by how we can use it. Status as of **17 October 2026** (gap categories researched the same evening). Every classification rests on a live probe or the owner's decisions D-13 to D-26 (`.planning/phases/02-draft-model-review-queue-submissions/02-CONTEXT.md`). The technical evidence (probe logs, robots.txt rules, quotes from terms, endpoints, fixtures) lives in [event-sources.md](./event-sources.md).

Every imported event goes to the review queue first: nothing is published without admin approval.

## At a glance

- **A. Feed or API (automatic import):** 4 sources (Connaught Hall is covered by the Town Council feed)
- **B. Crawlable web pages (automatic import with page parsers):** 4 sources
- **C. Allowed, but manual entry:** 5 sources
- **D. Blocked:** 9 sources, manual entry until one of the listed solutions works
- **E. Excluded (no usable events):** 11 sites

---

## A. Feed or API

The best sources: the site itself publishes a machine-readable calendar.

### Attleborough Town Council (P0)

- **Events page:** https://attleboroughtc.org.uk/events/ (opens the Town Hall category)
- **API:** https://attleboroughtc.org.uk/wp-json/tribe/events/v1/events (The Events Calendar REST API, JSON)
- **Calendar file (ICS):** https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1
- **Volume:** about 60 events a month: Town Hall groups, council meetings, Remembrance, Attleborough Town FC home games.
- **Venues it covers:** Town Hall, **Connaught Hall** (https://attleboroughtc.org.uk/venue/connaught-hall), Queens Square (market), Recreation Ground, Gamyers Meadow, The Arch, Scout Hall, Attleborough Town Football & Social Club. So Connaught Hall's events come through this feed.
- **Legal basis:** robots.txt allows it. We take facts only (title, date, time, venue) with attribution and a link back (D-26).
- **Watch out for:**
  - The terms have a generic "no republishing" clause, so the courtesy email asks the clerk for an explicit yes.
  - The feeds include private organisers' emails and phone numbers. These are never shown publicly.
  - The site blocked the corporate network by IP address, so the Hetzner server might be blocked too. The clerk email covers this.

### Attleborough Baptist Church (P0)

- **Events page:** https://www.attleborough.church/Calendar/Month.aspx
- **Calendar file (ICS):** https://www.attleborough.church/Calendar/Calendar.ics
- **RSS feed with dates:** https://www.attleborough.church/calendar/rss.xml?days=150
- **Volume:** about 13 events a month, mostly services. The community events (Warm Welcome, Women's Creative Club) are the useful ones.
- **Legal basis:** the site offers the feed "for other applications", and the owner checked the terms (no prohibition).
- **Watch out for:** robots.txt asks for 10 seconds between requests.

### Attleborough Academy (P1)

- **Events page:** https://www.attleboroughacademy.org/100/calendar
- **Calendar file (ICS):** https://www.attleboroughacademy.org/calendar/events.ics
- **Volume:** about 6 events a month, mostly internal to the school. A few public ones a term: open evenings, presentation evenings, charity days.
- **Legal basis:** the owner's written permission (D-24).
- **Watch out for:** event IDs change on every fetch, so duplicates are matched on title and start time.

### Diocese of Norwich (P2)

- **Events page:** https://www.dioceseofnorwich.org/events/
- **API:** https://www.dioceseofnorwich.org/wp-json/tribe/events/v1/events (The Events Calendar REST API, JSON)
- **Calendar file (ICS):** https://www.dioceseofnorwich.org/events/?ical=1
- **Volume:** diocese-wide (all of Norfolk); few Attleborough events. It has venue records for St Mary's Church, Attleborough, with past events (a craft market, a summer fete), but none upcoming on 17 October.
- **Legal basis:** robots.txt allows everything; to be confirmed against its terms before import. Facts only, with attribution and a link back.
- **Watch out for:** robots.txt asks for 10 seconds between requests. Regional, so keep only Attleborough-area venues (geofence or venue filter).

---

## B. Crawlable web pages

No feed, but robots.txt allows the pages and the dates can be read reliably from structured data or the page layout.

### Attleborough Heritage Group (P1)

- **Events page:** https://www.attleboroughheritage.org.uk/Events/FutureEvents.aspx
- **Method:** page parser (dated rows in a list).
- **Volume:** about 1 event a month. Its Town Hall sessions also appear in the Town Council feed.
- **Legal basis:** robots.txt allows it, the terms say nothing about automated use, and the owner decided to include it (D-23).

### Attleborough Primary School (P1)

- **Events page:** https://www.attleboroughprimary.org/key-information/school-calendar
- **Method:** decided after the first fetch.
- **Legal basis:** robots.txt allows it, the terms are silent, owner decision (D-23).
- **Watch out for:** robots.txt asks for 3 hours 20 minutes between requests.
- **Owner action:** run `ops/probe-sources.sh attleborough-primary-school` after 23:05 on 17 October, then redact personal data before committing.

### Visit Norfolk (P2)

- **Events page:** https://visitnorfolk.co.uk/events
- **Method:** Schema.org event data on each event page.
- **Legal basis:** robots.txt allows it, the terms are silent, owner decision (D-23).
- **Watch out for:** it's a regional directory, so we keep only events inside the Attleborough area (geofence).

### Visit Wymondham (P2)

- **Events page:** https://visitwymondham.org.uk/whats-on/
- **Method:** Schema.org event data on the listing page.
- **Legal basis:** robots.txt allows it, the terms are silent, owner decision (D-23).
- **Watch out for:** it covers the Wymondham area, so geofence; few Attleborough events.

---

## C. Allowed, but manual entry

Crawling is allowed, but these sites give no dates that a parser can read reliably, so the admin enters their events by hand.

### Attleborough Players (P3)

- **Events page:** https://attleboroughplayers.co.uk/
- **Why manual:** news comes from an embedded Facebook feed and a TicketSource ticket widget, and both of those are blocked (see D).
- **Volume:** fewer than one show a month.
- **Next step:** ask the Players to submit their shows through our form.

### Attleborough Summer Carnival (P3)

- **Events page:** https://attleboroughcarnival.co.uk/
- **Why manual:** dates are written only in prose ("3rd-5th July").
- **Volume:** once a year. It also posts on Facebook.

### Banham Zoo (P3)

- **Events page:** https://www.banhamzoo.co.uk/events
- **Why manual:** the listing has titles only, and detail pages give dates in prose.
- **Volume:** 1-2 events a month, regional.

### St Mary's Church, Attleborough with All Saints, Besthorpe (P3)

- **Website:** https://www.attleboroughchurch.org.uk/
- **Why manual:** its what's-on page (https://www.attleboroughchurch.org.uk/whats-on.html) is an embedded Facebook feed (https://www.facebook.com/AttleboroughWithBesthorpe), which we can't read. The concerts (https://www.attleboroughchurch.org.uk/concerts.html) and artisan market (https://www.attleboroughchurch.org.uk/artisan-market.html) pages are prose and posters.
- **Legal basis:** robots.txt allows the pages.
- **Next step:** manual entry. Ask the parish to tag its events in the Diocese of Norwich calendar (group A); they'd then arrive automatically.

### Rosecroft Primary School (P3)

- **Website:** https://www.rosecroftprimary.org
- **Calendar page:** https://www.rosecroftprimary.org/parents/school-calendar
- **Why manual:** the calendar only shows with JavaScript ("Sorry - Javascript is required to view the calendar"). Term dates are on https://www.rosecroftprimary.org/parents/term-dates.
- **Watch out for:** robots.txt asks for 3 hours 20 minutes between requests (same school website provider as Attleborough Primary).
- **Next step:** manual entry for public events; check later whether the calendar has a feed behind it.

---

## D. Blocked

We never get around a block: no disguising the crawler as a browser, no CAPTCHA solving, no proxies. Until one of the solutions works, these sources' events arrive by manual entry or our submission form.

### Breckland Council

- **Events page:** https://www.breckland.gov.uk/whatson
- **Why blocked:** a Cloudflare bot wall challenges automated visitors on every network. Our honest crawler gets "403 challenge", even on robots.txt. There's no official feed or API: the What's On page is put together by hand from a submission form.
- **Possible solutions, best first:**
  1. Subscribe a dedicated mailbox to Breckland's official email alerts and import from the emails: https://public.govdelivery.com/accounts/UKBRECKLAND/subscriber/new
  2. Submit our main events through their form (https://www.breckland.gov.uk/whatsonform) and ask their comms team to list our calendar.
  3. Join Cloudflare's Verified Bots programme once our production crawler is public (Phase 5).
  4. Ask them to allowlist our crawler.

### Norfolk County Council: Libraries (including Attleborough Library)

- **Events page:** https://www.norfolk.gov.uk/libraryonline
- **Why blocked:** the same Cloudflare bot wall as Breckland. No calendar file, RSS feed or API was found. The library catalogue runs on Spydus.
- **Possible solutions, best first:**
  1. Email Norfolk Libraries asking for a calendar feed or a monthly list for Attleborough Library.
  2. Cloudflare Verified Bots (Phase 5).
  3. Ask them to allowlist our crawler.

### Norfolk Museums (including Gressenhall Farm and Workhouse)

- **Events page:** https://www.museums.norfolk.gov.uk/events
- **Why blocked:** a Cloudflare bot wall for our crawler. There's no ticketing platform or feed. robots.txt allows everything.
- **Possible solutions, best first:**
  1. Ask Norfolk Museums' marketing team to send us their events regularly.
  2. Cloudflare Verified Bots (Phase 5), then a polite scheduled fetch of the events page.
  3. Ask them to allowlist our crawler.

### TicketSource

- **Events page:** https://www.ticketsource.co.uk/whats-on
- **Why blocked:** a Cloudflare bot wall. There's no public or partner API: organiser tools only cover an organiser's own events. robots.txt also disallows its feed.
- **Possible solutions, best first:**
  1. Ask local organisers who sell through TicketSource, such as Attleborough Players, to send their events or use our form.
  2. Cloudflare Verified Bots (Phase 5), for public event pages only, honouring robots.txt.

### Facebook and Instagram (Meta)

- **Example pages:**
  - Attleborough Summer Carnival: https://www.facebook.com/attleboroughsummercarnival
  - St Mary's Church: https://www.facebook.com/AttleboroughWithBesthorpe
  - Daisy's Milk Shed (Attleborough Hall Farm): https://www.instagram.com/daisysmilkshed/
- **Why blocked:** both robots.txt files say `Disallow: /` for all crawlers, and Meta's terms forbid automated collection without written permission ("Collection of data on Instagram through automated means is prohibited unless you have express written permission"). The project rule is no Facebook or Instagram scraping.
- **Possible solutions, best first:**
  1. Ask the organiser to send events through our submission form.
  2. The admin enters events by hand from their posts.
  3. **Facebook Page events via the official API, with the Page admin's consent:** the organiser connects our Meta app with a Page role, giving a Page access token (`pages_read_engagement`) for their own Page's events. Needs a Meta developer app, App Review and Business Verification. We can't read events of Pages we don't manage.
  4. **Instagram captions via the official API (Business Discovery):** our own Instagram business account can read the public captions of other business or creator accounts, such as `daisysmilkshed`, without their login. Instagram has no events, so captions are free text and would only pre-fill a draft for review. Needs our own Instagram business account linked to a Facebook Page, a Meta app with `instagram_basic`, `instagram_manage_insights` and `pages_read_engagement`, App Review and Business Verification (typically 2-6 weeks). Check Meta's Platform Terms on reusing caption text first.
- **Not possible:** Facebook Groups. Meta shut down the Groups API on 22 April 2024 (Graph API v19.0), so group events and posts can't be read at all.
- **Decision:** not in scope for the current phases. Revisit after launch if several organisers post only on Facebook or Instagram.

### Snetterton Circuit

- **Events page:** https://www.snetterton.co.uk/calendar/full
- **Why blocked:** the events only appear after JavaScript runs. robots.txt allows the page, but there's no event data in the page itself.
- **Possible solutions:**
  1. Manual entry for the headline race weekends (P3).
  2. Later: check whether the calendar loads from a public data file that robots.txt allows, or use a headless browser if robots.txt and the terms allow it.

### Attleborough Town FC

- **Website:** https://www.attleboroughtownfc.co.uk/
- **Why blocked:** robots.txt disallows its calendar and data exports, and the site has no events section.
- **Possible solutions:** already covered, because home games appear in the Town Council feed. Manual entry for anything else.

### Eastern Daily Press (local events)

- **Events page:** https://www.edp24.co.uk/local-events/
- **Why blocked:** the terms prohibit reuse ("No part of the Site can be reproduced … or stored on any other web site"). The listings come from a JavaScript widget run by evvnt.
- **Possible solutions:** drop it, or ask evvnt about a licensed feed.

### Eventbrite

- **Events page:** https://www.eventbrite.co.uk/d/united-kingdom--attleborough/all-events/
- **Why blocked:** the terms prohibit scraping ("you agree not to scrape, crawl, or employ any automated means"). None of the 20 listed events was in Attleborough on 17 October.
- **Possible solutions:** drop it for now. If Attleborough events appear, look at the official Eventbrite API. It has separate terms and fetches events by organiser or venue, not by area.

---

## E. Excluded (no usable events)

- **Eastern Baptist Association church page** (https://www.easternbaptist.org.uk/church-homepage/attleborough-baptist-church/): a directory page. The church's own site is in group A.
- **Attleborough Methodist Church** (https://www.attleboroughmethodistchurch.org.uk/): the domain no longer resolves, and normal services have stopped.
- **attleborough.org** (https://www.attleborough.org/): a parked domain.
- **attleborough.org.uk** (https://attleborough.org.uk/): refuses secure connections.
- **Trinity Hall, Besthorpe** (https://besthorpe.org.uk/events/): village information, not dated events.
- **Great Ellingham one-place study** (https://greatellingham.org/): a local history site with no events.
- **Wymondham Town Council** (https://wymondhamtowncouncil.gov.uk/): no events of its own. It links to Visit Wymondham (group B).
- **Attleborough u3a** (http://u3asites.org.uk/attleborough/): no working site. The secure connection fails and plain HTTP shows an empty holding page. The owner may know the current site.
- **The Events Calendar demo** (https://demo.theeventscalendar.com/): not local; used only to check the feed format.
- **The Bear, Attleborough** (https://thebearattleborough.co.uk/): a brochure site with no events page (about, contact, gallery only).
- **Attleborough Hall Farm** (https://www.attleboroughhallfarm.co.uk/): raw-milk dairy on Wix (it posts as Daisy's Milk Shed on Instagram, see D). robots.txt allows everything and there's no challenge, but there's no events page or events app; the pages (about, services, milk shed, contact) and two blog posts mention no open days or events. If it announces an open day, enter it by hand; recheck its blog feed occasionally.

---

## Gaps: nothing found yet

Searched on 17 October (web search, domain checks, Wikipedia, national directories). These still need the owner's local knowledge, such as Facebook group names or noticeboard posters:

- **Rotary and Lions:** no website found (about ten likely domains don't exist; Rotary's club finder needs JavaScript). Probably Facebook only.
- **Pubs:** The Griffin, The Cock, The London Tavern and The Mulberry Tree have no website found; The Bear's site has no events (see E). Quiz and music nights are probably on Facebook.
- **Businesses and farms:** Attleborough Hall Farm checked (no events, see E). Nothing else found. The Thursday market on Queens Square is already in the Town Council feed.
- **Women's Institute:** the national WI directory needs JavaScript, and no Norfolk Federation site was found. Some WI sessions appear in the Town Council feed.
- **Village halls and parish councils** (Besthorpe, Great Ellingham, Deopham, Morley, Rockland St Peter, Larling, Snetterton): no sites found; Old Buckenham's new site is an empty placeholder.
- **Attleborough Sports Hall:** run by Everyone Active; its listings would be class timetables, not community events.
- **Attleborough u3a:** still no working site.
- **Facebook- or Instagram-only groups:** manual entry or our submission form.

---

## Owner actions

1. **Tonight after 23:05:** run `ops/probe-sources.sh attleborough-primary-school` (steps in `tests/Fixtures/sources/README.md`), and redact personal data before committing.
2. **Send the Town Clerk email** in [town-council-feed-request.md](./town-council-feed-request.md). It asks for an explicit yes to republishing event facts and, if needed, allowlisting for our server.
3. **Ask for event data directly:**
   - Breckland (email alerts and the submission form)
   - Norfolk Libraries (a calendar feed or monthly list)
   - Norfolk Museums (send us their events)
   - local TicketSource organisers such as Attleborough Players
4. **Add local URLs** for the gaps above (Facebook page names are fine; they become manual-entry sources).
5. **Ask St Mary's** to tag its events in the Diocese of Norwich calendar.
6. **Phase 5:** apply for Cloudflare Verified Bots once the production crawler is public. We need a page about the crawler, a distinct crawler name with a contact URL, a published IP list or reverse DNS, and robots.txt compliance. This could unlock the public pages of Breckland, Norfolk Libraries, Norfolk Museums and TicketSource.
