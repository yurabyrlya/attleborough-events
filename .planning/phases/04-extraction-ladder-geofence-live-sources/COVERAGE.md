# API Coverage — The Events Calendar REST API v1 (`/wp-json/tribe/events/v1`)

> Full coverage by default. Opt-outs are explicit, reasoned decisions.
> Context: Phase 4 reads public WordPress sites that run The Events Calendar (Town Council, Diocese of Norwich) as a polite, read-only crawler (EXT-02, D-03). Plans 04-05 and 04-07.

| capability | decision | reason |
|---|---|---|
| `GET /events` (event list) | INTEGRATE | |
| `start_date` / `end_date` window parameters | INTEGRATE | |
| `per_page` parameter (50) | INTEGRATE | |
| pagination via `next_rest_url` (+ `total` / `total_pages` cross-check) | INTEGRATE | |
| `404 rest_no_route` error body ("site does not run TEC") | INTEGRATE | |
| event fields mapped to drafts (times, venue, cost, categories) | INTEGRATE | |
| event `organizer[]` field | OPT-OUT | personal data: kept in the raw payload only, never mapped to public fields (Phase 3 D-17) |
| `page` parameter on the first request | OPT-OUT | not needed: TEC's own `next_rest_url` carries the page number and is followed verbatim |
| `strict_dates` parameter | OPT-OUT | not needed: the default whole-day window is what the 90-day import window wants |
| `status` parameter | OPT-OUT | not needed: anonymous requests only ever see published events (TEC adds status=publish itself) |
| list filters (search, categories, tags, venue, organizer, featured, geoloc) | OPT-OUT | explicitly out of scope: filtering happens locally (title rules, category mappings, geofence D-14/D-15) so the full window is read and missing-item detection stays reliable |
| `GET /events/{id}` and `/events/by-slug/{slug}` | OPT-OUT | not needed: the list returns complete event objects; extra per-event requests would only add crawl load |
| `GET /venues`, `/venues/{id}` | OPT-OUT | not needed: venue objects are embedded in each event |
| `GET /organizers`, `/organizers/{id}` | OPT-OUT | explicitly out of scope: organiser personal data is never stored publicly |
| `GET /categories`, `/tags` | OPT-OUT | not needed: category names are embedded in each event and mapped per source |
| `POST` / `PUT` / `DELETE` on events, venues, organizers, categories, tags | OPT-OUT | explicitly out of scope: read-only crawler, no credentials, never writes to other sites |
| `GET /doc` (schema) | OPT-OUT | not needed at runtime: the contract was verified once in research (RES-02) |
| conditional GET (ETag / Last-Modified) | OPT-OUT | not available: TEC sends `no-store`; unchanged content is skipped through the raw hash (D-03) |
