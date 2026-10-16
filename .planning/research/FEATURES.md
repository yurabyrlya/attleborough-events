# Feature Research

**Domain:** Hyperlocal "what's on" event aggregator / community calendar (UK market town, single admin, deterministic ingestion)
**Researched:** 2026-10-16
**Confidence:** MEDIUM overall
- Claims about other products come from their own pages, which I fetched directly on 2026-10-16. The research seam rates this method (`webfetch`) **LOW**, because no second source cross-checked the claims. Read each claim as "the product says this on its own page", not as independently confirmed. Claims I couldn't fetch are marked **[UNVERIFIED]**.
- The table-stakes / differentiator / anti-feature labels are my judgement. I weighed the v1.1 spec, the PROJECT.md constraints (solo admin, under 2 h/week, no LLM) and the evidence below.

**Complexity scale:** S = under 1 day · M = 1–4 days · L = more than 4 days, or ongoing maintenance (estimated for an experienced Laravel/Filament developer).
**Spec column:** ✅ in v1.1 · ◐ partly or implied · ❌ not in v1.1 · 🚫 explicitly out of scope in v1.1.

---

## Comparable Products Surveyed

| # | Product | Type | Relevant features observed | Source |
|---|---------|------|----------------------------|--------|
| S1–S4 | **Gancio** (AGPL, v1.28.2 stable / 2.0 beta Oct 2026) | OSS "shared agenda for local communities" | Anonymous submission on by default. Optional moderation and a public "report an event" button (both off by default). RSS feeds (global, per place, per tag, per collection). Global and per-event ICS with an "Add to calendar" button. iframe/web-component embed. Sitemap endpoints for events/places/tags. ICS import from a URL. No personal timelines. | gancio.org, /usage/share, /usage/moderation, /usage/embed |
| S5–S6 | **The Events Calendar (TEC)** (WordPress, v6.18.2, 2026-10-15) | Plugin behind many UK club/council sites | Free version: month/list/day views, event search, venues and organisers, categories and tags, **Google Calendar and iCal export**, REST API, JSON-LD. Pro: filters by category/tag/venue/price/city, recurrence, map/photo/week views, Community Submissions, Event Aggregator (ICS/Google/Eventbrite/CSV import). A 2026 user review complains that calendar views and exports generate huge numbers of crawlable URLs and bot load. | wordpress.org/plugins/the-events-calendar, theeventscalendar.com |
| S7 | **Mobilizon** (Framasoft → Kaihuri 2025+) | OSS federated events/groups | Docs cover share/report/edit event, moderation, auto-generated newsletter, **RSS/Atom and ICS/WebCal feeds**, event import, WordPress embed, federation with Gancio. | docs.mobilizon.org, framagit README |
| S8 | **Calagator** (Portland tech calendar, MIT) | OSS community aggregator | Site-wide **iCalendar and Atom "Subscribe" feeds**. Per event: **`.ics` file + Google Calendar TEMPLATE link** + hCalendar. Import events, venues, tags. **Admin-only duplicate squashing tool** (`/events/duplicates`, returns HTTP 401 without login). Anyone can edit (wiki model). | calagator.org, an event page, /events/duplicates |
| S9 | **Visit Norfolk** (regional tourism) | Regional directory | List view + **map view**, keyword search, start/end date, category (Fairs & Markets, Christmas, Bonfire Night…), "Interest type" including **Family Friendly** and **Dog Friendly** (separate from category), **location dropdown including Attleborough**, accessibility and sustainability criteria filters, events submission form. | visitnorfolk.co.uk/whats-on |
| S10 | **Visit Wymondham** (Town Council / TIC; neighbouring market town) | Closest UK comparable | Category filter that includes **"Family Friendly" and "Free"** as categories. **Facebook/Twitter/Email share on each card.** "Load more". "List your Business, Organisation or Event for FREE". Newsletter signup. JSON-LD. Runs the Modern Events Calendar plugin. | visitwymondham.org.uk/whats-on |
| S11 | **Eventbrite** "Events in Attleborough" | National ticketing marketplace | The Attleborough page mostly lists **Norwich** events (radius bleed). Save/share on each card. Confirms thin local inventory. | eventbrite.co.uk/d/united-kingdom--attleborough/events |
| S15 | **Patch** (US hyperlocal) | Hyperlocal news + events | "Share and discover events near you", user "Post", email newsletter per community. | patch.com |
| S16 | **Diss Town Council** "What's On" | UK town council | Static "Key events throughout the year" page (Wix). No filters or feeds seen. Typical of council what's-on pages. | diss.gov.uk/whats-on |
| — | **Radar.squat.net** | OSS event listing | **[UNVERIFIED]**: blocked by an Anubis proof-of-work bot wall during research. | — |
| — | **Nextdoor events** | Social hyperlocal | **[UNVERIFIED]**: not fetched (login-walled). | — |

**Takeaways from the survey:**
1. **Every OSS/plugin comparable exports its calendar** (ICS, RSS, or both) and offers per-event "add to calendar" (S2, S5, S7, S8). v1.1 has **none** of this. It is the biggest cheap gap.
2. **UK local comparables treat "Free" and "Family Friendly" as first-class filters** (S9, S10), and Visit Norfolk keeps "Family Friendly" separate from the event-type category (S9). v1.1 has a "Family" category but "one primary category per event", so a family-friendly music event can't show up under both.
3. **Share buttons on each event are standard** on the nearest comparable (S10) and on Eventbrite (S11). v1.1 lists "traffic from local shares" as a success metric but has no share feature.
4. **Moderation-first is not the norm.** Gancio's moderation is off by default (S3) and Calagator lets anyone edit (S8). v1.1's "nothing public without approval" is a real **differentiator** for trust, as long as the review queue stays fast.
5. **A Visit Norfolk listing exists for Attleborough** (S9). It is both a competitor and a likely P2 geofenced source.

---

## Feature Mapping by Category

Every v1.1 spec feature appears below. Rows marked ❌ are candidate additions.

### 1. Public Browsing & Filters

| Feature | Label | Cx | Spec | Evidence | Notes |
|---------|-------|----|------|----------|-------|
| Chronological list of upcoming occurrences | **Table stakes** | S | ✅ 7.1 | S5 list view, S9, S10 | List-first is correct for mobile. |
| Today / This weekend / This week quick filters | **Table stakes** | S | ✅ 7.1 | S9 has date range only; S10 has none | The quick chips are slightly better than the comparables. Keep the Europe/London overlap rules as specified. |
| Multi-day events appear in every overlapping window | **Table stakes** | S | ✅ 7.1/8.5 | S5 | Overlap query, not a start-date query. |
| Category filter (10 fixed categories) | **Table stakes** | S | ✅ 7.1/7.2 | S5, S9, S10 | |
| **"Free" filter** | **Table stakes** (UK local) | **S** | ❌ (data exists: `price_type`) | S10 has a "Free" category; S5 filters by price; S12 says use `offers.price: 0` | **Cheap addition.** The data is already in the model. Only a toggle/chip is needed. |
| **"Family / kid-friendly" flag + filter** | **Differentiator** (near table stakes for this audience) | **S–M** | ◐ ("Family" is a *category*, single-valued) | S9 keeps "Family Friendly" separate from category; S10 | Add a boolean `family_friendly` set by keyword rules + source default + admin toggle, and keep "Family" as a category. Otherwise half-term and Christmas events get split between categories. |
| Date-grouped list headings ("Sat 17 Oct") | **Table stakes** (UX) | S | ❌ | S8 groups by day | Blade grouping. Big readability win on mobile. |
| Custom date range | Differentiator | S | ◐ optional | S9 start/end date | Ship a simple "pick a date" (single day) rather than a range picker. |
| Title/keyword search | Differentiator | S–M | ◐ optional | S5, S8, S9 | Low value at expected volume (likely under 100 events per 90 days, [UNVERIFIED] estimate). Postgres FTS is cheap if wanted. **Defer.** |
| Location / village filter | Differentiator | M | ❌ | S9 location dropdown | Only useful once nearby villages have real inventory. **Defer (v1.x).** |
| Venue pages / filter by venue | Differentiator | M | ❌ (`venues` table exists) | S5, S1 per-place feed | Good for SEO ("events at Connaught Hall"). **v1.x.** |
| "Recently added" / "New this week" strip | Differentiator | S | ❌ | — (reasoned) | Gives returning visitors a reason to come back. Supports the "returning visitors" metric. Sort by `approved_at`. |
| Expired events hidden by default | **Table stakes** | S | ✅ 8.1 | — | |
| "Cancelled" badge until expiry | **Table stakes** | S | ✅ 7.1/8.1 | S12 `EventCancelled` | |
| Category placeholder images (no third-party images) | **Table stakes** (legal) | S | ✅ 8.8 | — | The placeholders are *our own* images, so they can also serve as the OG/JSON-LD `image` (S12 recommends `image`). |
| Month grid calendar view | **Anti-feature** (MVP) | M | ❌ | S5 offers it; S5 review flags crawl/URL explosion | Poor on phones, and every month URL is a crawl trap. A list plus date chips does the job better. |
| Map view | **Anti-feature** (MVP) | M–L | ❌ | S9 | Needs geocoding, tiles and consent questions. Little value in an 11k-person town. |
| Mobile-first, WCAG 2.2 AA | **Table stakes** | M (ongoing) | ✅ 7.1 | S9 accessibility filters | See category 9. |

### 2. Event Detail & SEO

| Feature | Label | Cx | Spec | Evidence | Notes |
|---------|-------|----|------|----------|-------|
| Detail page: title, date/time, venue, description, category, price, organiser contact | **Table stakes** | S | ✅ 7.1 | all | |
| Source attribution + link on every discovered event | **Table stakes** (legal/trust) | S | ✅ 7.1/8.8 | S8 shows "Imported from" | |
| Readable slugs, meta + OG tags | **Table stakes** | S | ✅ 7.1 | — | OG tags are what make WhatsApp/Facebook previews work, which the share feature depends on. |
| Schema.org `Event` JSON-LD | **Table stakes** | S | ✅ 7.1 | S5, S10, S12 | S12 requires `name`, `startDate`, and `location` (Place + PostalAddress). It recommends `endDate`, `eventStatus`, `image`, `offers`, `organizer`, and `previousStartDate` for reschedules. Emit **one Event per occurrence date** for recurring events (S12: separate Event per performance). |
| `sitemap.xml` | **Table stakes** | S | ✅ 7.1 | S1 sitemap endpoints, S12 | Include only published event pages, not filter combinations. |
| **Expired event URLs keep resolving** ("This event has ended", 200 + `noindex` or kept indexable) | **Table stakes** | S | ❌ (spec only says "hidden by default but kept") | — (reasoned) | Shared links in Facebook groups live on after the event. A 404 looks broken. |
| **`noindex` / robots rules for filter and feed URLs** | **Table stakes** | S | ❌ | S5 review: crawl explosion from views/exports | Stops bots crawling every filter combination and feed URL. On a GBP 4–6 VPS that bot load matters. |
| "Rescheduled" status (`previousStartDate`) | Differentiator | S | ◐ (update drafts exist; no public "rescheduled" display) | S12 | Optional "Date changed" badge when an applied update moves the start date. |

### 3. Calendar Export, Feeds & Sharing ("subscriptions" without accounts)

| Feature | Label | Cx | Spec | Evidence | Notes |
|---------|-------|----|------|----------|-------|
| **Per-event "Add to calendar" (`.ics` download + Google Calendar link)** | **Table stakes** | **S** | ❌ | S2 Gancio "Add to calendar", S5 TEC free "Google Calendar and iCal exporting", S8 Calagator `.ics` + Google TEMPLATE link | **Top cheap addition.** `sabre/vobject` is already in the stack. Give each occurrence a stable UID. The Google link is a URL template (S8 shows the format). Covers iPhone/Android/Outlook without accounts. |
| **Public ICS feed of our calendar** (all events + per category) | **Differentiator** | **S–M** | ❌ (🚫 "notifications" is out of scope, but a feed is pull-based, not a notification) | S2 global ICS, S7 ICS/WebCal, S8 iCalendar subscribe | Emit expanded occurrences within the horizon (simpler than re-emitting RRULEs), with `STATUS:CANCELLED` for cancellations. Subscriber clients poll slowly: Google Calendar is commonly reported at 8–24 h **[UNVERIFIED]**. That's acceptable. Gives "notifications" with no email, PECR or accounts. |
| **RSS/Atom feed of newly published events** | **Differentiator** | **S** | ❌ | S2 RSS per place/tag, S7 RSS/Atom, S8 Atom | Lets Facebook group admins, the Town Council and RSS-to-social tools rebroadcast us. This is the cheapest "distribution" feature. Serves the "traffic from local shares" metric. |
| **Share button per event** (Web Share API + fallback links: WhatsApp, Facebook, email, copy link) | **Table stakes** (UK local) | **S** | ❌ | S10 FB/Twitter/Email per card, S11 share per card, S13 | MDN marks `navigator.share()` as **Limited availability / not Baseline**, HTTPS only, user gesture required (S13). So **always render fallback links**. Plain links, no third-party share scripts, so no cookies. |
| Embeddable widget for organisers / Town Council site | Differentiator | M | ❌ | S4 Gancio iframe/web component | A distribution channel. **v1.x**, once there is inventory. |
| Email digest / newsletter | Differentiator (deferred) | M–L | 🚫 out of scope | S7 auto-newsletter, S10, S15 | Needs a PECR consent flow, double opt-in and deliverability work. **v2.** The RSS feed plus an off-the-shelf RSS-to-email service covers this cheaply later. |
| Push notifications / visitor alerts | **Anti-feature** | L | 🚫 | — | Needs accounts or service workers and consent. Not needed to validate demand. |

### 4. Organiser Submission

| Feature | Label | Cx | Spec | Evidence | Notes |
|---------|-------|----|------|----------|-------|
| Public submission form (moderated) | **Table stakes** | M | ✅ 7.5 | S5 Community add-on, S9 events submission, S10 "List your event FREE", S1 anonymous submission | |
| Fields: title, start, optional end, venue/address, description, category, price, organiser, contact, link, consent | **Table stakes** | S | ✅ 7.5 | — | |
| Honeypot + per-IP rate limit + Cloudflare Turnstile | **Table stakes** | S | ✅ 7.5 | — | Turnstile alone would probably be enough at this volume; all three are cheap. |
| "Will be reviewed" confirmation | **Table stakes** | S | ✅ 7.5 | — | |
| **"Repeats" field on submission** (free text, or simple weekly / nth-weekday-monthly picker) | **Table stakes** (local reality) | S (text) / M (picker) | ❌ | S5 recurrence | Toddler groups, quiz nights and markets are weekly or monthly. Without this, organisers submit the same event many times. **MVP: a free-text "repeats" note**; the admin converts it to a series during review. |
| **Manual entry supports recurrence** (admin builds a simple RRULE) | **Table stakes** | M | ◐ (8.5 covers ICS recurrence; 7.6 manual entry doesn't mention it) | — | Facebook-only weekly events are the main manual-entry use case. This needs to be explicit in requirements. |
| "Suggest a source / regular listing" (organiser inclusion request) | Differentiator | S | ◐ (spec 15 measures "organiser inclusion requests" but no mechanism exists) | S10 "List your Business, Organisation…" | Add as an option on the submission form or a contact-page mailto. |
| Confirmation / "your event was approved" email to organiser | Differentiator | S–M | ❌ | — | Transactional email, so PECR doesn't bite, but it needs a mailer and bounce handling. **v1.x.** |
| Image upload on submission | **Anti-feature** (MVP) | M | 🚫 (no third-party images) | — | Copyright and moderation burden. Placeholders instead. |
| Organiser accounts / self-edit | **Anti-feature** (MVP) | L | 🚫 | S7 identities, S1 users | Account recovery, auth and abuse handling are too much for a solo admin. Corrections go through a "report a problem" link (category 5). |

### 5. Admin Review & Moderation

| Feature | Label | Cx | Spec | Evidence | Notes |
|---------|-------|----|------|----------|-------|
| Unified draft queue: new / update / cancellation | **Differentiator** (core value) | M | ✅ 7.4 | S3 Gancio moderation is opt-in; S8 none | The trust promise. Comparables mostly lack it. |
| Draft card: fields, recurrence summary, source + URL, method, raw link, quality score + reasons, parse errors, duplicate warning | **Table stakes** (for *this* product) | M | ✅ 7.4 | — | |
| Approve / edit and approve / reject / mark duplicate | **Table stakes** | M | ✅ 7.4 | — | |
| Apply/ignore update with field diff | **Differentiator** | M | ✅ 7.4/8.4 | — | |
| Confirm/ignore cancellation | **Differentiator** | S | ✅ 7.4/8.4 | — | |
| Bulk approve/reject | **Table stakes** (solo admin) | S | ✅ 7.4 | — | Filament bulk actions. |
| Queue filters: kind, source, quality band, duplicate flag, date | **Table stakes** | S | ✅ 7.4 | — | |
| Rule-based quality score 0–100, bands, visible reasons | Differentiator | M | ✅ 8.2 | — | Possible simplification: show the **reasons + band** prominently and treat the number as secondary. |
| Every decision records reviewer + time | **Table stakes** | S | ✅ 8.2 | — | |
| Edit / hide / cancel published events | **Table stakes** | S | ✅ 7.6 | — | |
| Cancel or hide a single occurrence of a series | **Table stakes** | M | ✅ 8.5 | — | |
| **"Approve & next" + keyboard shortcuts** | **Differentiator** (directly serves the under-2 h/week metric) | S | ❌ | — (reasoned) | Filament supports keybindings on actions. Cheap, high leverage. |
| **Public "Report a problem / suggest a correction" link per event** | **Table stakes** (trust + legal) | S | ◐ (spec has a site-wide takedown contact) | S3 Gancio "report an event", S7 "report an event" | A prefilled mailto or a tiny form that creates a review note. It doubles as the takedown/correction route required under UK GDPR (8.8). |
| Review-time metric on dashboard (median time from draft to decision) | Differentiator | S | ◐ (spec 15 lists it as a metric; 7.6 dashboard omits it) | — | `reviewed_at − created_at`. Measures the operational success criterion. |
| Public comments / ratings | **Anti-feature** | M–L | 🚫 | S7 comments | Moderation load, no validation value. |
| Open wiki-style public editing | **Anti-feature** | M | ❌ | S8 Calagator lets anyone edit | Conflicts with "nothing public without approval". |

### 6. Source Management & Ingestion

| Feature | Label | Cx | Spec | Evidence | Notes |
|---------|-------|----|------|----------|-------|
| Source Registry (all fields in 7.3) | **Table stakes** (for an aggregator) | M | ✅ 7.3 | S5 Event Aggregator, S1 ICS import | |
| Test run (preview, no drafts) / Run now | **Differentiator** | M | ✅ 7.3 | — | Essential for selector sources. |
| Health: failure counts, degraded (3) / unhealthy (5, paused), zero-result alert | **Table stakes** | S–M | ✅ 8.7 | — | |
| Robots-aware polite fetching (UA with contact, 5 s/host, timeouts, size limits) | **Table stakes** (legal) | M | ✅ 8.7 | — | |
| Conditional requests (ETag/Last-Modified) + content hash skip | **Table stakes** | S | ✅ 6 | — | |
| Rung 1: ICS (RRULE, EXDATE, STATUS:CANCELLED) | **Table stakes** | M–L | ✅ 6 | S1, S5, S7, S8 all import ICS | The highest-yield parser. Build it first. |
| Rung 1: RSS/Atom as an *event source* | ⚠ **Over-scoped (low yield)** | M | ✅ 6 | — (reasoned) | RSS items usually carry `pubDate`, **not the event date**. Under the reject-not-guess rule (6), most items become parse errors. **Keep only if source research finds a feed with event-date fields**; otherwise defer. |
| Rung 2: The Events Calendar adapter | ◐ **Possibly redundant** | M | ✅ 6 (DoD: "at least one verified adapter") | S5: TEC free has iCal export + REST API | TEC sites already expose ICS, which rung 1 handles. Build the adapter **only if** live installs show the ICS lacks fields (venue, cost) that the REST API has. |
| Rung 2: Squarespace adapter | ◐ **Possibly redundant** | M | ✅ 6 | spec 14 says `?format=ical` needs verifying | Same reasoning. If `?format=ical` works, it is just an ICS source. **Build one adapter at most, picked by source research.** |
| Rung 3: Schema.org JSON-LD | **Table stakes** | M | ✅ 6 | S5, S10 emit JSON-LD; S11 Eventbrite | Covers Eventbrite, TEC, Modern Events Calendar (S10) and many WordPress sites. High yield. |
| Rung 4: per-source CSS selectors | Differentiator | M (+L ongoing) | ✅ 6 | — | Brittle. Limit to P1 sources and depend on test run + zero-result alerts. |
| One page → many items | **Table stakes** | S | ✅ 6 | — | |
| Reject-not-guess on ambiguous dates | **Differentiator** (trust) | S | ✅ 6 | — | |
| Location geofence for `filter` sources | **Table stakes** for regional sources | M | ✅ 8.6 | S11 shows Norwich bleed on the "Attleborough" page | **Simplify:** postcode-district + place-name allowlist first. The 8-mile radius needs lat/lng (geocoder not chosen), so use it only when coordinates are present. |
| Deterministic categories (source default → mapping → keyword → Other) | **Table stakes** | M | ✅ 7.2 | — | |
| Admin UIs for category mappings + keyword rules + geofence settings | ◐ **Could simplify** | M | ✅ 7.6 | — | Three extra Filament resources. The owner is the developer, so seeded config/DB rows edited through **one** simple resource (or config) are enough for launch. |
| Source run logs + dashboard counts + approval rate per source | **Table stakes** (ops) | S–M | ✅ 7.6 | — | |
| Source research doc + parser fixtures | **Table stakes** (process) | M | ✅ 14 | — | Not a user feature, but every parser depends on it. |
| Facebook scraping / Graph API | **Anti-feature** | L | 🚫 | — | Policy-prohibited (PROJECT.md). Use manual entry. |
| Headless browser / JS-rendered sources, poster OCR | **Anti-feature** (MVP) | L | 🚫 | — | |
| LLM extraction / classification | **Anti-feature** (MVP) | — | 🚫 | — | Out of scope by decision. **Not recommended for MVP.** |
| Federation (ActivityPub) | **Anti-feature** | L | ❌ | S1, S7 | Interesting but irrelevant to an 11k-person town. |

### 7. Deduplication, Updates & Cancellations

| Feature | Label | Cx | Spec | Evidence | Notes |
|---------|-------|----|------|----------|-------|
| Same source + external ID → treat as the same item (update) | **Table stakes** | S | ✅ 8.3 | — | |
| Same canonical URL match | **Table stakes** | S | ✅ 8.3 | — | |
| Fuzzy title + date + venue (`pg_trgm`) → duplicate warning, admin decides | **Differentiator** | M | ✅ 8.3 | S8 Calagator has an admin-only duplicates tool | Comparable OSS tools dedupe manually after the fact. Flagging at draft time is better. |
| Venue aliases to improve matching | **Table stakes** (supports dedup) | S | ✅ 9 (`venues.aliases`) | — | |
| Update drafts with diff; pending drafts replaced, not stacked | **Differentiator** | M | ✅ 8.4 | — | |
| Cancellation drafts from `STATUS:CANCELLED` / `EventCancelled` | **Table stakes** | S | ✅ 8.4 | S12 | |
| Missing for 2 successful runs → cancellation draft | ◐ **Noisy, keep but guard** | M | ✅ 8.4 | — (reasoned) | Sites drop past events, paginate, or show only the next N items, so this produces false cancellations. Ignore items whose start is in the past or beyond the source's visible window. |
| Source failures never delete/hide events | **Table stakes** | S | ✅ 8.4 | — | |
| Traceability (`event_sources`: primary/duplicate/update) | **Table stakes** (for this product) | S | ✅ 9/10 | — | |
| Merge two published events (squash) | Differentiator | M | ❌ | S8 duplicates squash | Rare when dedup happens at draft time. Hide one and link it instead. **Defer.** |

### 8. Recurrence & Multi-Day

| Feature | Label | Cx | Spec | Evidence | Notes |
|---------|-------|----|------|----------|-------|
| ICS RRULE / EXDATE expansion | **Table stakes** | M | ✅ 8.5 | S5 recurring, S1 | |
| RDATE + RECURRENCE-ID overrides | **Table stakes** for Google Calendar feeds | **L** | ✅ 8.5 | — | The hardest parser work. Google Calendar public feeds commonly contain overrides **[UNVERIFIED]**. Needs fixture-heavy testing. |
| Series approved once; occurrences over a rolling 90-day horizon | **Differentiator** (admin time) | M | ✅ 8.5 | — | Needs a scheduled re-expansion job. |
| Single-occurrence cancel/hide | **Table stakes** | M | ✅ 8.5 | — | |
| Multi-day = one event, one long occurrence | **Table stakes** | S | ✅ 8.5 | — | |
| Recurrence in manual entry / submission | **Table stakes** | S–M | ❌ / ◐ | — | See category 4. |

### 9. Accessibility

| Feature | Label | Cx | Spec | Evidence | Notes |
|---------|-------|----|------|----------|-------|
| WCAG 2.2 AA target, mobile-first | **Table stakes** | M (ongoing) | ✅ 7.1 | — | Livewire filter updates need `aria-live` announcements and focus management. Make filter chips real buttons/links. |
| Filters work without JavaScript (query-string links) | **Table stakes** | S | ❌ | — (reasoned) | Plain `?when=weekend&category=music` links. Better for accessibility and shareable filtered views. Pair with `noindex` (category 2). |
| Readable date formats ("Sat 17 Oct, 7:30pm") + machine `<time datetime>` | **Table stakes** | S | ❌ | — | |
| Venue accessibility info per event (step-free, hearing loop) | Differentiator | S (free text) / M (structured filters) | ❌ | S9 accessibility filters | Rarely present in source data. Optional free-text field only. **v1.x.** |

### 10. Analytics, Privacy & Legal

| Feature | Label | Cx | Spec | Evidence | Notes |
|---------|-------|----|------|----------|-------|
| Cookieless analytics (Plausible / Fathom / Umami), no banner | **Table stakes** | S | ✅ 8.8 | S14 | |
| **Outbound-click + action events** (source link, add-to-calendar, share, submission) | **Table stakes** for validation | S | ❌ | S14: Plausible outbound-link and custom-event tracking; Umami `data-umami-event` | Directly measures "events viewed per session" and "local shares". Plausible counts these towards billable pageviews (S14). |
| Privacy notice + legitimate-interests assessment + takedown contact | **Table stakes** (UK GDPR) | S | ✅ 8.8 | — | |
| Description truncation (500 chars) + attribution | **Table stakes** (copyright) | S | ✅ 8.8 | — | |
| Advanced analytics / dashboards for organisers | **Anti-feature** (MVP) | M | 🚫 | — | |

---

## Feature Landscape (summary)

### Table Stakes (Users Expect These)

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Upcoming list + Today/Weekend/Week + category filter | Every comparable has date and category browsing (S5, S9, S10) | S | ✅ spec |
| Event detail with attribution, JSON-LD, OG, sitemap | SEO and link previews are how local events spread | S | ✅ spec |
| **Per-event "Add to calendar" (.ics + Google link)** | Present in TEC free, Gancio and Calagator (S2, S5, S8) | S | ❌ **add** |
| **Share button with fallback links** | Nearest UK comparable and Eventbrite have it (S10, S11) | S | ❌ **add** |
| **"Free" filter** | UK local comparables surface it (S10); data already exists | S | ❌ **add** |
| Moderated organiser submission with spam protection | S5, S9, S10 all accept submissions | M | ✅ spec |
| **Recurring events in manual entry + "repeats" on submission** | Local events are mostly weekly/monthly | S–M | ❌/◐ **add** |
| **Report-a-problem link per event** | S3, S7. Also the UK GDPR correction route | S | ◐ **add** |
| Expired-URL handling + `noindex` on filter/feed URLs | Shared links outlive events; S5 review shows crawl traps | S | ❌ **add** |
| WCAG 2.2 AA, mobile-first, no-JS filter links | Public-sector-adjacent audience; older residents | M | ◐ |
| Cookieless analytics + outbound/action events | Needed to measure the success metrics | S | ◐ **extend** |

### Differentiators (Competitive Advantage)

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Nothing public without review (unified draft queue, diffs, cancellations) | Trust. Gancio's moderation is opt-in (S3); Calagator lets anyone edit (S8) | M | ✅ core value |
| Draft-time duplicate detection across sources | One clean listing where Facebook shows five reposts | M | ✅ |
| Deterministic, reject-not-guess extraction with visible reasons | No invented dates; fast to review | M–L | ✅ |
| **Public ICS subscription feed** (all + per category) | "Notifications" with no accounts, email or PECR | S–M | ❌ **add** |
| **RSS/Atom feed of new events** | Community admins can rebroadcast; cheap distribution | S | ❌ **add** |
| **Family-friendly flag** (separate from category) | The main thing parents search for at half-term and Christmas (S9, S10) | S–M | ◐ **add** |
| "New this week" strip | Gives returning visitors a reason to come back | S | ❌ add |
| "Approve & next" + keyboard shortcuts | Keeps the admin under 2 h/week | S | ❌ add |
| Venue pages, embed widget, village filter | SEO and distribution once inventory exists | M | ❌ v1.x |

### Anti-Features (Commonly Requested, Often Problematic)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Visitor accounts / saved events | "Remember what I like" | Auth, GDPR and support load; no validation value | ICS subscription + add-to-calendar |
| Email digest / push notifications | Retention | PECR consent, deliverability, ongoing ops | RSS/ICS feeds now; RSS-to-email later |
| Month grid view | Looks like a "calendar" | Poor on mobile; crawl trap (S5 review) | List + date chips + "pick a date" |
| Map view | Visual appeal (S9) | Geocoding and tiles for a 2-mile town | Venue address + "Open in Maps" link |
| Comments / ratings | Engagement | Moderation burden | Report-a-problem link |
| Organiser accounts / self-edit | Saves the admin time (in theory) | Account recovery, abuse, auth | Submission form + correction link |
| Open public editing (wiki model) | Crowd maintenance (S8) | Breaks the trust promise | Moderated submission |
| Image hosting / uploads | Prettier cards | Copyright risk (spec 8.8) | Category placeholders (own images, also used as OG image) |
| LLM extraction / summarisation | "Parse anything" | Non-deterministic; fabricated dates | Deterministic ladder + manual entry |
| Facebook scraping | Most activity is there | Policy-prohibited | Manual entry, submission form |
| Federation / ActivityPub | OSS comparables offer it (S1, S7) | Irrelevant at this scale | RSS/ICS feeds |
| Paid "featured" listings | Revenue | Undermines trust; no revenue model in MVP | — |

---

## Feature Dependencies

```
Source Research (fixtures)
    └──requires──> every parser (ICS, JSON-LD, adapter?, selectors, RSS?)

ICS parser + recurrence expansion
    ├──requires──> event_occurrences + 90-day horizon job
    └──enables──> Admin manual-entry recurrence (same RRULE model)

Draft model ──requires──> Normalizer → LocationFilter → QualityScorer → Change/DuplicateDetector
    └──enables──> Review queue → published events/occurrences

Published events/occurrences
    ├──enables──> Public list + filters (Free, Family flag, date chips)
    ├──enables──> Detail page + JSON-LD (one Event per occurrence)
    ├──enables──> Per-event .ics / Google link  ──shares──> stable occurrence UID
    ├──enables──> Public ICS feed + RSS feed   ──shares──> stable occurrence UID
    └──enables──> Sitemap

OG tags ──enables──> Share button previews (WhatsApp/Facebook)
Share / add-to-calendar / outbound links ──measured by──> analytics custom events
price_type ──enables──> Free filter + JSON-LD offers.price = 0
Category keyword rules ──enables──> family_friendly flag (same rule engine)
Filter query-string URLs ──conflicts──> indexability → requires noindex/robots rules
```

### Dependency Notes

- **Add-to-calendar and the ICS feed share one occurrence→VEVENT serialiser and a stable UID scheme.** Build them together, after occurrences exist. Changing UIDs later creates duplicates in every subscriber's calendar.
- **Manual-entry recurrence reuses the ICS RRULE model.** Build it after the ICS parser and horizon expansion, not before.
- **Share previews depend on OG tags**, which are already in the spec. The share button is just UI on top.
- **The family-friendly flag reuses the deterministic keyword-rule engine** from 7.2. No new mechanism is needed.
- **Filter URLs conflict with SEO hygiene:** making filters linkable (good for accessibility and sharing) means they must be `noindex` and kept out of the sitemap.

---

## MVP Definition

### Launch With (v1)

Everything in the v1.1 DoD, **plus these cheap additions (each S, about 3–5 days in total):**

- [ ] Per-event "Add to calendar" (`.ics` + Google Calendar link). Table stakes in every comparable.
- [ ] Share button (Web Share API + WhatsApp/Facebook/email/copy fallbacks). Serves the "local shares" metric.
- [ ] "Free" filter chip. The data already exists.
- [ ] Report-a-problem link per event. Trust + GDPR correction route.
- [ ] Expired-event pages keep resolving; `noindex` on filter/feed URLs.
- [ ] Analytics custom events for outbound/source clicks, add-to-calendar, share and submission. Without them the success metrics can't be measured.
- [ ] Recurrence in admin manual entry + a free-text "repeats" field on the submission form.
- [ ] Date-grouped list headings.

### Add After Validation (v1.x)

- [ ] Public ICS feed (all + per category). Add once there are 20+ upcoming events, so subscribing is worth it.
- [ ] RSS/Atom feed of newly published events. Add when the first community admin or the Town Council asks to rebroadcast. (S effort, so it could move into v1 if time allows.)
- [ ] Family-friendly flag + filter. Add before the first school holiday after launch.
- [ ] "New this week" strip. Add when returning-visitor share is measurable.
- [ ] "Approve & next" + keyboard shortcuts. Add if median review time is over 1 minute per draft.
- [ ] Venue pages, village filter. Add when nearby villages have real inventory.
- [ ] Embeddable widget. Add on a request from the Town Council or a venue.
- [ ] Organiser "approved" email. Add when submission volume justifies a mailer.

### Future Consideration (v2+)

- [ ] Email digest (PECR-compliant double opt-in). Defer until traffic justifies the ops.
- [ ] Structured accessibility filters. Defer: source data rarely contains them.
- [ ] Merge/squash published events. Defer: draft-time dedup should make it rare.
- [ ] Admin-triggered LLM assistance. Explicitly post-MVP, per PROJECT.md.

### v1.1 Spec Items That Look Over-Scoped for a Solo Admin

| Spec item | Concern | Recommendation |
|-----------|---------|----------------|
| RSS/Atom as an event *source* (rung 1) | Feeds usually lack event dates, so reject-not-guess turns most items into parse errors | Gate on source research. Build only if a real local feed carries event dates. |
| Both TEC **and** Squarespace adapters | TEC (S5) and probably Squarespace already expose ICS, which rung 1 handles | Build **one** adapter, and only where ICS is missing fields. The DoD needs just one. |
| Separate Filament UIs for category mappings, keyword rules, geofence settings | Three CRUD resources for rules the developer-admin rarely changes | One "Rules" resource or seeded config at launch. |
| 8-mile radius geofence | Needs a geocoder (not chosen) and venue coordinates | Postcode district + place-name allowlist first; radius only when lat/lng is present. |
| Missing-item → cancellation drafts | Noisy on sites that drop past or paginated items | Keep, but exclude past-dated items and items beyond the source's visible window. |
| RDATE + RECURRENCE-ID overrides | L complexity | Keep (Google Calendar feeds need it), but give it its own fixture-heavy plan. |
| Quality score as a 0–100 number | Precision the reviewer doesn't need | Show band + reasons first; the number is secondary. |
| Optional title search / custom range | Low value at likely volume | Defer search; ship a single "pick a date". |

---

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| v1.1 public list + date/category filters + detail + JSON-LD | HIGH | MEDIUM | P1 |
| v1.1 review queue, drafts, dedup, updates/cancellations | HIGH | HIGH | P1 |
| ICS parser + recurrence | HIGH | HIGH | P1 |
| JSON-LD parser | HIGH | MEDIUM | P1 |
| Submission form + manual entry (with recurrence) | HIGH | MEDIUM | P1 |
| Add to calendar (.ics + Google) | HIGH | LOW | P1 |
| Share button | HIGH | LOW | P1 |
| Free filter | MEDIUM | LOW | P1 |
| Report-a-problem link | MEDIUM | LOW | P1 |
| Analytics custom events | HIGH (for validation) | LOW | P1 |
| Expired-URL handling + noindex rules | MEDIUM | LOW | P1 |
| CSS selector sources | MEDIUM | MEDIUM (+ongoing) | P1 (DoD) – limit to P1 sources |
| One platform adapter | MEDIUM | MEDIUM | P1 (DoD) – pick from research |
| Public ICS feed | MEDIUM | LOW–MEDIUM | P2 |
| RSS feed of new events | MEDIUM | LOW | P2 |
| Family-friendly flag | MEDIUM | LOW–MEDIUM | P2 |
| Approve & next / shortcuts | MEDIUM (admin) | LOW | P2 |
| RSS/Atom event *source* parser | LOW | MEDIUM | P2 (gate on research) |
| Second platform adapter | LOW | MEDIUM | P3 |
| Venue pages, village filter, embed | MEDIUM | MEDIUM | P3 |
| Email digest | MEDIUM | HIGH | P3 |
| Map / month grid | LOW | MEDIUM | Won't (MVP) |

**Priority key:** P1 = must have for launch · P2 = should have, add when possible · P3 = nice to have later.

---

## Competitor Feature Analysis

| Feature | Gancio (S1–S4) | TEC free/Pro (S5–S6) | Calagator (S8) | Visit Norfolk (S9) | Visit Wymondham (S10) | Our Approach |
|---------|----------------|----------------------|----------------|--------------------|-----------------------|--------------|
| Moderation before publish | Optional, off by default | Community add-on (moderation details [UNVERIFIED]) | None (open edit) | [UNVERIFIED] | [UNVERIFIED] | **Mandatory for all items** (differentiator) |
| Ingestion from other sources | ICS import from URL | Event Aggregator (ICS/Google/Eventbrite/CSV, paid) | Import events | [UNVERIFIED] | — | Deterministic ladder + geofence |
| Dedup | — [UNVERIFIED] | — [UNVERIFIED] | Admin squash tool | — | — | Draft-time ID/URL/fuzzy warning |
| Per-event add-to-calendar | Yes | Yes (Google + iCal) | Yes (.ics + Google) | [UNVERIFIED] | Not seen | **Add (P1)** |
| Calendar feed (ICS/RSS) | RSS + ICS, per place/tag | iCal export | iCal + Atom subscribe | Not seen | Not seen | **Add ICS + RSS (P2)** |
| Share | Share menu (feeds/embed) | [UNVERIFIED] | — | [UNVERIFIED] | FB/Twitter/Email per card | **Add (P1)** |
| Free filter | — | Price filter (Pro) | — | Not seen | "Free" category | **Add chip (P1)** |
| Family filter | Tags | Category/tag | Tags | "Family Friendly" interest type | "Family Friendly" category | **Flag (P2)** |
| Map view | — | Pro | — | Yes | — | Won't (MVP) |
| Submission | Anonymous by default | Community add-on | Open | Events submission | "List your event FREE" | Moderated form + Turnstile |
| Structured data | microdata/microformat | JSON-LD | hCalendar | [UNVERIFIED] | JSON-LD | JSON-LD per occurrence |
| Newsletter | — | Paid add-ons | Mailing list | Newsletter | Newsletter | Deferred (PECR) |

---

## Sources

All fetched directly with `curl` on 2026-10-16. Seam confidence tier is **LOW** (`webfetch` provider, no cross-check), but every one is the product's own first-party page.

- **S1** Gancio home: https://gancio.org/ (philosophy, anonymous submission default, export list)
- **S2** Gancio Share: https://gancio.org/usage/share (RSS per place/tag/collection, global/per-event ICS, "Add to calendar")
- **S3** Gancio Moderation: https://gancio.org/usage/moderation (moderation and report off by default)
- **S4** Gancio Embed + API index: https://gancio.org/usage/embed (iframe/web component; API lists sitemap endpoints and ICS import from URL)
- **S5** The Events Calendar on WordPress.org: https://wordpress.org/plugins/the-events-calendar/ (free feature list incl. "Google Calendar and iCal exporting", JSON-LD, REST API; changelog 6.18.2 dated 2026-10-15; user review on crawlable-URL explosion)
- **S6** TEC product page: https://theeventscalendar.com/products/wordpress-events-calendar/ (filters by category/tag/venue/price/city; Pro: recurrence, views, Community Submissions, Event Aggregator)
- **S7** Mobilizon docs index: https://docs.mobilizon.org/ and README https://framagit.org/kaihuri/mobilizon (share/report, moderation, auto-newsletter, RSS/Atom & ICS/WebCal feeds, import, embed)
- **S8** Calagator: https://calagator.org/, event page https://calagator.org/events/1250482303 (".ics", Google TEMPLATE link, hCalendar), https://calagator.org/events/duplicates (HTTP 401, i.e. admin-only)
- **S9** Visit Norfolk What's On: https://www.visitnorfolk.co.uk/whats-on (list/map, keyword, dates, category, Family/Dog Friendly interest type, Attleborough location, accessibility filters, events submission)
- **S10** Visit Wymondham What's On: https://visitwymondham.org.uk/whats-on/ ("Family Friendly" and "Free" categories, per-card share, "List your event for FREE", newsletter, JSON-LD)
- **S11** Eventbrite Attleborough: https://www.eventbrite.co.uk/d/united-kingdom--attleborough/events/ (mostly Norwich listings; save/share per card)
- **S12** Google Search Central, Event structured data (last updated 2026-09-08): https://developers.google.com/search/docs/appearance/structured-data/event
- **S13** MDN Web Share API / `navigator.share()`: https://developer.mozilla.org/en-US/docs/Web/API/Web_Share_API ("Limited availability", secure context, transient activation)
- **S14** Plausible outbound-link and custom-event tracking: https://plausible.io/docs/outbound-link-click-tracking, https://plausible.io/docs/custom-event-goals. Umami events: https://umami.is/docs/track-events
- **S15** Patch: https://patch.com/ ("Share and discover events near you", community newsletter)
- **S16** Diss Town Council What's On: https://www.diss.gov.uk/whats-on (static key-events page)
- **Not accessible:** Radar.squat.net (Anubis bot wall). Nextdoor (login wall). Both are [UNVERIFIED].
- **Unverified operational claims:** Google Calendar subscription refresh interval (8–24 h, commonly reported). Prevalence of RECURRENCE-ID overrides in Google public feeds. Expected event volume for Attleborough.

---
*Feature research for: hyperlocal "what's on" aggregator (Attleborough, Norfolk)*
*Researched: 2026-10-16*
