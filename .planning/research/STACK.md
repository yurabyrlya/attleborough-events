# Stack Research

**Domain:** Local events aggregator (deterministic ingestion of ICS/RSS/JSON-LD/HTML, admin review queue, public mobile-first calendar)
**Researched:** 2026-10-16
**Confidence:** MEDIUM overall. Version numbers are HIGH: they come straight from the Packagist / npm / GitHub / php.net APIs on 2026-10-16. Recommendations are judgement calls (MEDIUM). The GSD `classify-confidence` seam gives a tier of **LOW** to every source here, because they were fetched with `webfetch` (curl / `gh api`) and the seam does not support the Composer ecosystem. Context7 and WebSearch were not available in this runtime. Anything not checked against a primary source is marked **[UNVERIFIED]**.

> **Headline finding:** set the PHP floor to **8.4**, not 8.3, and run **PHP 8.5** in production. Laravel 13 itself accepts PHP 8.3, but several packages we want now require `php ^8.4`: Symfony 8.x DomCrawler/CssSelector, `spatie/schema-org` 5, `spatie/laravel-sitemap` 8, Pest 5. Ubuntu 26.04 LTS ships PHP 8.5 and PostgreSQL 18 as distro packages, so no PPA is needed.

## Recommended Stack

### Core Technologies

| Technology | Version (pin) | Purpose | Why Recommended | Source |
|---|---|---|---|---|
| PHP | **8.5.x** in prod (8.5.11 is current). Composer floor `^8.4` | Runtime | Laravel 13 supports 8.3-8.5. Our packages need ≥8.4. Ubuntu 26.04 ships 8.5 natively. 8.5 has a longer support window than 8.4. | [php.net releases](https://www.php.net/releases/), [packages.ubuntu.com/resolute/php](https://packages.ubuntu.com/resolute/php) (`2:8.5+99ubuntu1`) |
| Laravel | **`laravel/framework ^13.37`** (skeleton `laravel/laravel` v13.11.0) | Framework, scheduler, queues, HTTP client, Eloquent | Current major. Released 2026-03-17. Bug fixes until Q3 2027, security fixes until 2028-03-17. Laravel 14 is expected around Q1 2027: upgrade after launch, not before. | [Packagist](https://packagist.org/packages/laravel/framework), [Laravel 13 release notes](https://laravel.com/docs/13.x/releases) |
| PostgreSQL | **18** (Ubuntu 26.04 package `postgresql 18+290ubuntu1`) | Primary DB, `pg_trgm` fuzzy match, full-text search | Laravel 13 supports PG 10+. Since PG13, `pg_trgm` is a *trusted* extension, so the app's DB owner can `CREATE EXTENSION` without superuser rights. | [packages.ubuntu.com/resolute/postgresql](https://packages.ubuntu.com/resolute/postgresql), [Laravel 13 database docs](https://laravel.com/docs/13.x/database) |
| Filament | **`filament/filament ^5.10`** (v5.10.2, 2026-10-13) | Admin panel: review queue, sources, manual entry, dashboard | Current major. Requires PHP 8.2+, Laravel 11.28+, Tailwind 4.1+, `ext-intl`. Gets features until 6.x, then ~1 year of bug fixes and ~2 years of security fixes. Do **not** start on 4.x: its bug fixes end 2027-01-15. | [Packagist](https://packagist.org/packages/filament/filament), [Filament install docs (5.x)](https://filamentphp.com/docs/5.x/introduction/installation), [Version support policy](https://filamentphp.com/docs/5.x/introduction/version-support-policy) |
| Livewire | **`livewire/livewire ^4.4.7`** (v4.4.7, 2026-09-28). Installed transitively by Filament; don't pin it separately | Filament runtime; optional public interactivity | `filament/support` 5.10.2 requires `livewire/livewire ^4.4.7`. Livewire 4 supports `illuminate ^10-^13`. | [Packagist](https://packagist.org/packages/livewire/livewire) |
| Tailwind CSS | **`tailwindcss ^4.3`** + **`@tailwindcss/vite ^4.3`** (4.3.3) | Public site styling; Filament custom theme | Filament 5 needs Tailwind ≥4.1. v4 is CSS-first (`@import "tailwindcss"`, no `tailwind.config.js` by default). | [npm tailwindcss](https://www.npmjs.com/package/tailwindcss), [npm @tailwindcss/vite](https://www.npmjs.com/package/@tailwindcss/vite) |
| Vite + laravel-vite-plugin | **`vite ^8.3`**, **`laravel-vite-plugin ^3.2`** | Asset build | `laravel-vite-plugin` 3.2.0 peers on `vite ^8`. `@tailwindcss/vite` supports Vite 5-8. | [npm vite](https://www.npmjs.com/package/vite), [npm laravel-vite-plugin](https://www.npmjs.com/package/laravel-vite-plugin) |
| Alpine.js | **`alpinejs ^3.17`** (3.17.4), **public layout only** | Small public UI interactions (filter drawer, menu) | Livewire bundles its own Alpine. Import `alpinejs` only in the public `app.js`, and never load that bundle inside the Filament panel. If a public page gets a Livewire component, drop the separate Alpine import there. | [npm alpinejs](https://www.npmjs.com/package/alpinejs) |
| Queue / cache / session | Laravel **`database`** drivers (built in) | Fetch/process jobs, rate-limit counters, locks | Fits the no-Redis constraint. Volume is a few hundred jobs a day at most. | Laravel docs (queues) |

### Ingestion & Parsing Libraries

| Library | Version (pin) | Purpose | When to Use | Rejected alternatives (one line each) | Source |
|---|---|---|---|---|---|
| **sabre/vobject** | `^5.0` (5.0.0, 2026-07-07; repo pushed 2026-10-05) | Parse ICS **and** expand recurrence (`RRULE`, `RDATE`, `EXDATE`, `RECURRENCE-ID` overrides, `STATUS:CANCELLED`, VTIMEZONE) | `IcsParser`, plus **the only recurrence expansion engine** for both ICS-sourced and manual series. Store each event's recurrence as RFC 5545 lines (RRULE/RDATE/EXDATE) and re-expand with vobject's `EventIterator` over the 90-day horizon. | **johngrogg/ics-parser** v3.6.0: loose array-based parser with its own recurrence maths; not strict enough for "reject, not guess". **php-rrule as the expander**: has no VTIMEZONE or `RECURRENCE-ID` override handling, and running two expanders risks divergence. | [Packagist](https://packagist.org/packages/sabre/vobject), [5.0.0 release](https://github.com/sabre-io/vobject/releases/tag/5.0.0) |
| **rlanvin/php-rrule** | `^3.0` (v3.0.0, 2026-07-29) | `RRule::humanReadable()` ("every Friday until 12 Dec") for the draft card's recurrence summary; RRULE validation in the manual-entry form | Display and validation only. Needs `ext-intl`, which Filament already requires. | Hand-rolled summariser: reinvents i18n and edge cases. **⚠ Upstream CI tests PHP 7.3-8.4 only, not 8.5.** Our own CI on 8.5 must cover the summariser. | [Packagist](https://packagist.org/packages/rlanvin/php-rrule), [v3.0.0 release](https://github.com/rlanvin/php-rrule/releases/tag/v3.0.0) |
| **laminas/laminas-feed** | `^2.26.3` (2026-10-03; explicit support for PHP 8.2-8.5) | RSS 0.9x/1.0/2.0 and Atom parsing via `Reader::importString()` | `FeedParser`. Always feed it bytes from our `HttpFetcher` so robots, ETag and throttling stay centralised. Never use `Reader::import($uri)`. | **simplepie/simplepie** 1.9.0 (last release 2025-09): bundles its own fetching/caching and is more lenient (it guesses). **debril/feed-io** v6.0.3: last release 2023-10, stale. | [Packagist](https://packagist.org/packages/laminas/laminas-feed) |
| **In-house `JsonLdEventParser`** on DomCrawler + `json_decode(..., flags: JSON_THROW_ON_ERROR)` | n/a (about 150-250 LOC) | Extract `<script type="application/ld+json">`, flatten `@graph` and arrays, select `Event` and subtypes (`MusicEvent`, `SportsEvent`, …), map `eventStatus`, `location`, `offers` | Rung 3 (Eventbrite, SEO sites) and Squarespace pages that embed JSON-LD. Deterministic and fully fixture-tested. Rejects invalid JSON rather than repairing it. | **brick/structured-data** 0.2.0 (2025-06; pre-1.0, 26 stars; CI still maintained): weakly typed `Item` bag, an extra pre-1.0 dependency, and Microdata/RDFa support we don't need. Revisit only if a P0 source turns out to be Microdata-only. | [Packagist brick/structured-data](https://packagist.org/packages/brick/structured-data) (rejected) |
| **symfony/dom-crawler** + **symfony/css-selector** | `^8.1` (8.1.5 / 8.1.6, 2026-08; require PHP ≥8.4.1) | Per-source CSS-selector extraction (rung 4); JSON-LD script discovery; platform adapters' HTML fallbacks | `SelectorParser`. Selector config lives in `sources.parser_config` JSON. Laravel 13 accepts Symfony `^7.4 \|\| ^8.0`, and Composer picks 8.x on PHP 8.5. | **PHP 8.4 native `Dom\HTMLDocument::querySelectorAll()`**: viable and dependency-free, but DomCrawler gives filter/each/attr ergonomics and is what the spec names. **paquettg/php-html-parser**, **simple_html_dom**: unmaintained or non-standard. | [Packagist dom-crawler](https://packagist.org/packages/symfony/dom-crawler), [Packagist css-selector](https://packagist.org/packages/symfony/css-selector) |
| **Laravel HTTP client** (`Http` facade over Guzzle) | Built in; Guzzle `^7.8.2 \|\| ^8.0` resolved by Laravel (guzzle 8.2.0 is latest) | `HttpFetcher`: UA with contact URL, timeouts, `If-None-Match` / `If-Modified-Since`, 304 handling, retries, size cap | Always. Size cap: `withOptions(['stream' => true])` and stop reading after N bytes, or Guzzle `on_headers` to reject large `Content-Length`. `Http::fake()` and `Http::preventStrayRequests()` drive the tests. | **symfony/http-client** 8.1: fine, but there is no reason to run two clients, and Laravel's fakes are better integrated. **spatie/crawler** 9.4: link-following crawler; we only fetch registered URLs. | [Packagist guzzle](https://packagist.org/packages/guzzlehttp/guzzle) |
| **spatie/robots-txt** | `^2.5.4` (2026-02-25; repo pushed 2026-06) | `robots.txt` allow/deny per UA, `Crawl-delay` | Use the **`RobotsTxt` class with content we fetched ourselves** (cache about 24h per host in DB/cache). Don't use `Robots::create()`, which fetches on its own. The source supports `*` wildcards and longest-match weighting. `$` end-anchor support is **[UNVERIFIED]**: cover it with a fixture test (e.g. Eventbrite's `/rss/`, `/atom/` disallows). | In-house parser: easy to get wrong (group precedence, wildcards). **webignition/robots-txt-file**: older and less maintained **[UNVERIFIED]**. | [Packagist](https://packagist.org/packages/spatie/robots-txt) |

### Public Site, SEO & Spam Libraries

| Library | Version (pin) | Purpose | Notes | Rejected alternatives | Source |
|---|---|---|---|---|---|
| **spatie/schema-org** | `^5.0.2` (2026-09-30; PHP ^8.4) | Typed builder for `Event` JSON-LD on detail pages (`eventStatus`, `location`, `offers`, `organizer`) | Output only. It does not parse (confirmed in PROJECT.md). | Hand-written arrays: lose type safety and are easy to typo. | [Packagist](https://packagist.org/packages/spatie/schema-org) |
| **spatie/laravel-sitemap** | `^8.2` (8.2.0, 2026-06-24; PHP ^8.4, illuminate ^12\|^13) | `sitemap.xml` generated from published events + static pages | Build it from models (`Sitemap::create()->add(...)`) in a scheduled job that writes `public/sitemap.xml`. Don't use its crawler mode, even though `spatie/crawler` is pulled in as a dependency. | Hand-rolled XML view: fine, but the package costs us nothing and handles sitemap indexes. | [Packagist](https://packagist.org/packages/spatie/laravel-sitemap) |
| **spatie/laravel-honeypot** | `^4.7.3` (2026-09-11; illuminate ^11-^13) | Honeypot + timed field on the organiser form | Has a Livewire trait too, but we use a plain form. | Hand-rolled hidden field: misses the timing check. | [Packagist](https://packagist.org/packages/spatie/laravel-honeypot) |
| **ryangjchandler/laravel-cloudflare-turnstile** | `^3.0.4` (2026-09-14; illuminate ^11-^13) | `<x-turnstile />` component + `Turnstile` validation rule | Turnstile itself is free. Keys go in `config/services.php`. Use Cloudflare's test keys in tests. | **coderflex/laravel-turnstile** v2.2.1: requires `guzzlehttp/guzzle ^7.7`, which can block Guzzle 8 resolution; it also declares PHP up to 8.4 only. | [Packagist](https://packagist.org/packages/ryangjchandler/laravel-cloudflare-turnstile) |
| Laravel `RateLimiter` / `throttle` middleware | Built in | Per-IP limit on the submission POST; per-host fetch throttling (min 5s) | Uses the database cache store, so no Redis is needed. | Third-party rate-limit packages: unnecessary. | Laravel docs |
| **Umami Cloud, Hobby plan** | SaaS (umami v3.4.0 upstream) | Cookieless analytics: no banner, pageviews, visits, views/visit, custom events | **$0/month**: 100K events/month, 1 website, 6-month retention. Pro is $20/month if outgrown. Hosting region and DPA terms for UK GDPR are **[UNVERIFIED]**: check before launch and note it in the privacy notice. | **Plausible** Starter $9/month (10k pageviews): good and EU-hosted, but it costs about 2x the VPS. **Fathom** $15/month. **Self-hosted Umami** on the VPS: extra Node process and RAM on a 4 GB box, more ops. **Cloudflare Web Analytics** (free): viable fallback, but weaker custom-event reporting **[UNVERIFIED]**. | [umami.is/pricing](https://umami.is/pricing), [plausible.io](https://plausible.io/), [usefathom.com/pricing](https://usefathom.com/pricing) |

**Not packaged on purpose (use the framework):** slugs (`Str::slug()` with `/events/{id}-{slug}` routes avoids uniqueness logic, so no `spatie/laravel-sluggable`); review audit fields (`reviewed_by` / `reviewed_at` columns are already in the spec, so no `spatie/laravel-activitylog`); description cleaning (`strip_tags` + `html_entity_decode` + truncate to 500 characters; `symfony/html-sanitizer` 8.1 is already present via Filament if rich text is ever needed).

### pg_trgm in Laravel (no package)

Use raw SQL in migrations. Laravel's schema builder has no trigram operator-class helper, and one extra package for two statements isn't worth it.

```php
// migration
DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
DB::statement('CREATE INDEX events_title_trgm_idx ON events USING gin (title gin_trgm_ops)');
DB::statement('CREATE INDEX venues_name_trgm_idx ON venues USING gin (name gin_trgm_ops)');

// DuplicateDetector query (date window narrows candidates first, then similarity)
Event::query()
    ->whereHas('occurrences', fn ($q) => $q->whereBetween('starts_at', [$from, $to]))
    ->whereRaw('similarity(title, ?) >= ?', [$title, 0.45])   // threshold in config, fixture-tuned
    ->orderByRaw('similarity(title, ?) DESC', [$title])
    ->limit(5)->get();
```

Rejected: **tpetry/laravel-postgresql-enhanced** 3.10.0. It's well maintained and adds `createExtensionIfNotExists` and index operator classes, but we'd only use about two features. Adopt it later if we need many PG-specific migrations. Full-text search for optional title search uses Laravel's built-in `whereFullText()` (to_tsvector on PostgreSQL).

### Development Tools

| Tool | Version (pin) | Purpose | Notes | Source |
|---|---|---|---|---|
| **Pest** | `pestphp/pest ^5.3` (v5.3.2, 2026-10-12; PHP ^8.4) | Test runner | Pest 5 is current. | [Packagist](https://packagist.org/packages/pestphp/pest) |
| **pest-plugin-laravel** | `^5.0.1` (requires `laravel/framework ^13.23`) | Laravel helpers | | [Packagist](https://packagist.org/packages/pestphp/pest-plugin-laravel) |
| **pest-plugin-livewire** | `^5.0` (requires `livewire ^4.3.3`) | Filament resource/page tests (`livewire(ListEventDrafts::class)->callTableAction(...)`) | Filament tests are Livewire tests. | [Packagist](https://packagist.org/packages/pestphp/pest-plugin-livewire) |
| **spatie/pest-plugin-snapshots** | `^2.4` (2026-07-29; PHP ^8.4) | Snapshot each parser's normalised output per fixture (`expect($items)->toMatchJsonSnapshot()`) | This is the core determinism guarantee: same fixture, same snapshot. Review snapshot diffs in PRs. | [Packagist](https://packagist.org/packages/spatie/pest-plugin-snapshots) |
| **Larastan** | `larastan/larastan ^3.13` | Static analysis (start at level 6, raise later) | | [Packagist](https://packagist.org/packages/larastan/larastan) |
| **Pint** | `laravel/pint ^1.32` | Code style | Ships with the skeleton. | [Packagist](https://packagist.org/packages/laravel/pint) |
| **Laravel Pail** | `laravel/pail ^1.2.7` | Tail logs in dev | Ships with the skeleton. | [Packagist](https://packagist.org/packages/laravel/pail) |
| Local Postgres | Docker `postgres:18`, or Herd/DBngin | Dev + test DB | **Tests must run on PostgreSQL, not SQLite**, because `pg_trgm`, `similarity()`, tsvector and timestamptz semantics matter. | — |
| CI | GitHub Actions with a `postgres:18` service container, PHP 8.5 (`shivammathur/setup-php`) | Run Pest + Larastan + Pint on push | Add a PHP 8.4 job only if production ever moves off 8.5. | **[UNVERIFIED]** action versions; pin them when the workflow is written |

**No LLM tooling.** When scaffolding, decline any Laravel Boost / AI SDK / MCP prompts. Do not add `laravel/boost`, `laravel/ai`, `prism-php/prism` or similar. Laravel 13's release notes advertise first-party AI primitives, and they are out of scope here.

### Testing Strategy: Fixtures & HTTP Fakes

1. **Global safety net:** call `Http::preventStrayRequests()` in `tests/Pest.php` (`beforeEach`). No test ever touches the network.
2. **Fixture layout:** `tests/Fixtures/Sources/{source-slug}/{YYYY-MM-DD}-{variant}.{ics|xml|html|json}`, plus a sidecar `{...}.meta.json` holding the URL, fetch date, response headers (ETag, Last-Modified) and robots.txt snapshot. Fixtures are real samples saved during source research (spec §14), trimmed of personal data.
3. **Parser unit tests** take bytes in and give normalised DTOs out. No HTTP involved. Each fixture gets a snapshot. Also write hand-crafted edge-case fixtures: no year in the date (expect a parse error), `RRULE` + `EXDATE` + `RECURRENCE-ID` override, `STATUS:CANCELLED`, an all-day multi-day event, a floating time, a TZID-less UTC `Z` time, a malformed JSON-LD block, an empty feed, and a 0-results page after earlier results.
4. **Fetcher/pipeline tests:** `Http::fake([...] )` with `Http::sequence()` to simulate 200, then 304 (not_modified), then 500 (failure count), and robots disallow. Use `Queue::fake()` only for dispatch assertions. Otherwise run jobs synchronously with `QUEUE_CONNECTION=sync` in `phpunit.xml`.
5. **Time:** freeze the clock with `$this->travelTo(...)` in every date-window, expiry or horizon test, in `Europe/London`. Include DST-boundary fixtures for the last Sunday in March and October. (DST ends on **2026-10-25**, so this is a live risk right now.)
6. **Filament:** use `livewire()` tests for approve, reject, mark duplicate, apply update and bulk actions, asserting DB state and that `reviewed_by` / `reviewed_at` are set.

## Hosting & Deployment (cheapest simple path)

**Recommendation:** one **Hetzner Cloud CX23** (2 vCPU / 4 GB RAM / 40 GB NVMe, EU region) running **Ubuntu 26.04 LTS** with **native packages, no Docker**, deployed by **Deployer 8 from GitHub Actions**. Backups go to **Cloudflare R2 (free tier)** plus Hetzner's automated server backups.

| Component | Choice / Version | Notes | Source |
|---|---|---|---|
| VPS | Hetzner Cloud **CX23** (x86; the ARM **CAX11** has the same RAM and disk and is an equal choice) | Price is rendered client-side and could not be read: **[UNVERIFIED] about €3.5-5/month plus primary IPv4 surcharge**, within the GBP 4-6 target. Stock is shown as "not available" in some locations: pick Falkenstein, Nuremberg or Helsinki, whichever has stock. Hetzner has no UK region; latency to UK users is fine for a mostly cacheable site. | [hetzner.com/cloud/cost-optimized](https://www.hetzner.com/cloud/cost-optimized) |
| OS | Ubuntu 26.04 LTS | Ships PHP 8.5 and PostgreSQL 18 as distro packages, so no PPAs. | packages.ubuntu.com |
| Web server | **Caddy 2.11** (v2.11.7) → PHP-FPM 8.5 | Automatic Let's Encrypt HTTPS with no certbot cron. The Caddyfile is about 10 lines. | [caddy releases](https://github.com/caddyserver/caddy/releases) |
| Queue worker | systemd unit: `php artisan queue:work database --sleep=3 --tries=3 --max-time=3600 --memory=256` | `Restart=always`. Deployer runs `queue:restart` after each release. | Laravel docs |
| Scheduler | cron: `* * * * * cd /var/www/app/current && php artisan schedule:run >> /dev/null 2>&1` | Use `withoutOverlapping()` on fetch dispatch, and `onOneServer()` isn't needed. | Laravel docs |
| Deploys | **`deployer/deployer ^8.0`** (v8.0.5, 2026-05-18; PHP ^8.3) with the Laravel recipe, triggered by GitHub Actions over SSH | Zero-downtime symlinked releases, shared `.env`/`storage`, and `migrate --force`. Free. Build assets in CI (`npm ci && npm run build`) and upload them, so no Node is needed on the server. | [Packagist](https://packagist.org/packages/deployer/deployer) |
| Provisioning | Versioned `ops/provision.sh` (or cloud-init) in the repo: users, ufw (22/80/443), unattended-upgrades, PHP-FPM + extensions (`pgsql`, `intl`, `mbstring`, `xml`, `dom`, `curl`, `zip`, `bcmath`), Postgres, Caddy, systemd units, cron | One-time job, but keep it scripted so the box can be rebuilt from backups. | — |
| App backups | **`spatie/laravel-backup ^10.3.3`** (PHP ^8.3, illuminate ^12.40\|^13) + **`league/flysystem-aws-s3-v3 ^3.35`** → **Cloudflare R2** | Daily DB-only dump at 03:00, `backup:clean` retention, `backup:monitor` mail alert. R2's free tier covers 10 GB-month storage, 1M Class A and 10M Class B ops, and free egress, which is ample for a DB dump of a few MB. **The `pg_dump` client major version must match the server (18).** | [Packagist laravel-backup](https://packagist.org/packages/spatie/laravel-backup), [R2 pricing](https://developers.cloudflare.com/r2/pricing/) |
| Server backups | Hetzner automated backups (7 rolling) | **[UNVERIFIED] +20% of server price** (about €1/month). Covers OS and config drift, while R2 covers point-in-time data restores. Run one restore drill before launch. | Hetzner docs **[UNVERIFIED]** |
| Mail (admin alerts, backup failures) | Any free transactional SMTP tier | Not on the critical path. Use the `log` mailer until launch. Provider choice and pricing are **[UNVERIFIED]**. | — |
| Uptime / heartbeat | Free external uptime monitor + a scheduled heartbeat ping | Detects a dead scheduler or worker, which would otherwise fail silently. Provider **[UNVERIFIED]**. | — |

**Expected monthly cost:** VPS about €4-5 plus backups about €1 (**[UNVERIFIED]** Hetzner prices). Ploi, Forge and Coolify licences cost €0, as do analytics (Umami Hobby), Turnstile and R2. **Total about GBP 4-6/month.**

### Deployment alternatives (rejected for MVP)

| Option | Verified price | Why not |
|---|---|---|
| **Ploi Free** | €0: 1 server, 1 site, **5 deployments/month** | 5 deploys a month is too few during active development. It's the best fallback if the owner wants a GUI for provisioning: provision with Ploi, then deploy with Deployer. |
| Ploi Basic | €8 / $10 per month | Costs more than the VPS; breaks the budget. |
| Laravel Forge Hobby | $12/month | Breaks the budget. |
| Coolify (self-hosted) | Free (v4.4.6) | Control plane + Docker on the same 4 GB box. Postgres backups, scheduler and worker become container concerns. More moving parts than native. |
| Kamal 2 | Free (v2.12.0) | Image builds, a registry, kamal-proxy and Ruby tooling: overkill for one box and one app. |
| Laravel Cloud / Railway / Fly.io | Usage-based **[UNVERIFIED]** | Managed Postgres plus an always-on worker plus the scheduler would likely exceed GBP 6/month. Revisit if ops time becomes the bottleneck. |

## Installation

```bash
# Scaffold (no starter kit: admin auth is Filament's panel login; public site has no visitor accounts)
composer create-project laravel/laravel:^13.0 attleborough-events
cd attleborough-events
# Set DB_CONNECTION=pgsql, QUEUE_CONNECTION=database, CACHE_STORE=database, SESSION_DRIVER=database in .env

# Lock dependency resolution to the production PHP version
composer config platform.php 8.5.11

# Admin
composer require filament/filament:"^5.10"
php artisan filament:install --panels

# Ingestion
composer require sabre/vobject:"^5.0" rlanvin/php-rrule:"^3.0" \
  laminas/laminas-feed:"^2.26.3" \
  symfony/dom-crawler:"^8.1" symfony/css-selector:"^8.1" \
  spatie/robots-txt:"^2.5.4"

# Public site / SEO / spam
composer require spatie/schema-org:"^5.0.2" spatie/laravel-sitemap:"^8.2" \
  spatie/laravel-honeypot:"^4.7.3" ryangjchandler/laravel-cloudflare-turnstile:"^3.0.4"

# Backups
composer require spatie/laravel-backup:"^10.3.3" league/flysystem-aws-s3-v3:"^3.35"

# Dev
composer require --dev pestphp/pest:"^5.3" pestphp/pest-plugin-laravel:"^5.0.1" \
  pestphp/pest-plugin-livewire:"^5.0" spatie/pest-plugin-snapshots:"^2.4" \
  larastan/larastan:"^3.13" deployer/deployer:"^8.0"
# (laravel/pint, laravel/pail, mockery, collision ship with the skeleton)

# Frontend
npm install -D tailwindcss@^4.3 @tailwindcss/vite@^4.3 vite@^8.3 laravel-vite-plugin@^3.2
npm install alpinejs@^3.17
# Optional: npm install -D @tailwindcss/forms@^0.5.11 @tailwindcss/typography@^0.5.20
```

Note: if `composer create-project` brings in PHPUnit by default, swap it for Pest (`composer remove --dev phpunit/phpunit` before requiring Pest, then `./vendor/bin/pest --init`). Whether the Laravel 13 skeleton defaults to Pest or PHPUnit is **[UNVERIFIED]**.

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|---|---|---|
| PHP 8.5 | PHP 8.4 | If a dependency (most likely `rlanvin/php-rrule`, whose upstream CI stops at 8.4) misbehaves on 8.5 and the fix can't be contained. 8.4 still satisfies every package listed. |
| sabre/vobject as the single expander | php-rrule `RSet` expander | Never as a second engine. php-rrule is for display and validation only. |
| In-house JSON-LD parser | brick/structured-data 0.2.x | If Microdata/RDFa-only sources become P0. |
| laminas-feed | SimplePie 1.9 | If many real-world feeds are malformed and the owner chooses leniency. This conflicts with "reject, not guess". |
| Raw-SQL pg_trgm | tpetry/laravel-postgresql-enhanced 3.10 | If PG-specific DDL grows (partial indexes, generated tsvector columns, many extensions). |
| Native VPS + Deployer | Ploi Free | If the owner prefers GUI provisioning; accept the 5 deploys/month limit. |
| Umami Cloud Hobby | Plausible Starter ($9/month) | If Umami's data region or DPA fails the UK GDPR check. |
| Plain Blade form for organiser submission | Livewire form | If live validation UX becomes important. Honeypot supports Livewire, but the Turnstile widget needs `wire:ignore` handling. |

## What NOT to Use

| Avoid | Why | Use Instead |
|---|---|---|
| Filament 4.x / Livewire 3.x | Previous majors. Filament 4 bug fixes end 2027-01-15. | Filament ^5.10 / Livewire ^4.4.7 |
| Tailwind 3 / `tailwind.config.js`-first setup | Filament 5 requires Tailwind ≥4.1 | Tailwind ^4.3 via `@tailwindcss/vite` |
| Loading `alpinejs` alongside Livewire on the same page | Two Alpine instances break `x-data` / `wire:` interplay | Alpine only in the non-Livewire public bundle; Livewire's bundled Alpine elsewhere |
| SQLite for tests | No `pg_trgm`, different date/time semantics | Postgres 18 in tests and CI |
| Redis / Horizon | Breaks the no-Redis constraint. Horizon needs Redis. | Database queue + `queue:work` under systemd |
| `Reader::import($url)` (laminas) / `Robots::create()` (spatie) / SimplePie's built-in fetch | Bypasses our robots, ETag, throttle and UA policy in `HttpFetcher` | Fetch with `HttpFetcher`, then parse strings |
| johngrogg/ics-parser | Lenient array parser with its own recurrence maths | sabre/vobject ^5.0 |
| debril/feed-io | No release since 2023-10 | laminas/laminas-feed |
| Headless browsers (Browsershot / Puppeteer / Panther) | Out of scope (JS-rendered sources deferred); RAM-heavy on a 4 GB VPS | Skip the source, or use manual entry |
| Any LLM SDK, Laravel Boost, Laravel AI SDK, Prism | Project constraint: no LLM | Deterministic parsers |
| Docker / Coolify on the 4 GB box | Extra RAM and ops for a single-app box | Native Ubuntu packages |

## Stack Patterns by Variant

**If a source is Squarespace:**
- Try `?format=ical` (vobject) first, then `?format=json` (in-house adapter using `json_decode`), then JSON-LD on event pages.
- Because these are structured endpoints. Live verification is still pending (spec §14) **[UNVERIFIED]**.

**If a source is WordPress + The Events Calendar:**
- Prefer the bundled REST API (`/wp-json/tribe/events/v1/events`) with the Laravel HTTP client and JSON. Fall back to its ICS export via vobject.
- Because REST gives the venue, cost and categories as fields. Exact endpoints and pagination need checking on 2-3 live installs **[UNVERIFIED]**.

**If the VPS RAM gets tight (it shouldn't at this volume):**
- Set the PHP-FPM `pm = ondemand` with `pm.max_children = 5`, Postgres `shared_buffers = 256MB`, and one queue worker.
- Because the workload is a few fetches an hour plus low public traffic.

## Version Compatibility

| Package A | Compatible With | Notes |
|---|---|---|
| laravel/framework ^13.37 | PHP 8.3-8.5; symfony ^7.4 \|\| ^8.0; guzzle ^7.8.2 \|\| ^8.0 | Verified in the Packagist require block |
| filament/filament ^5.10 | Laravel ≥11.28 (incl. 13), PHP ≥8.2, livewire ^4.4.7, Tailwind ≥4.1, `ext-intl`, symfony/console ^7\|^8 | Verified (Packagist + Filament docs) |
| livewire/livewire ^4.4.7 | illuminate/support ^10-^13 | Verified |
| symfony/dom-crawler, css-selector ^8.1 | **PHP ≥8.4.1** | Main reason the PHP floor is 8.4 |
| spatie/schema-org ^5.0.2, spatie/laravel-sitemap ^8.2, pestphp/pest ^5.3, spatie/pest-plugin-snapshots ^2.4, pest-plugin-livewire ^5.0 | **PHP ^8.4** | Verified |
| pestphp/pest-plugin-laravel ^5.0.1 | laravel/framework ^13.23 | Verified |
| laminas/laminas-feed ^2.26.3 | PHP 8.2-8.5 (explicit) | Verified |
| rlanvin/php-rrule ^3.0 | PHP ≥7.3 declared; **upstream CI tests up to 8.4 only** | Our CI on 8.5 must cover it |
| sabre/vobject ^5.0 | PHP ^8.2; sabre/xml ^3\|^4. v5 adds strict parameter/return types | Watch for `TypeError`s if you pass loosely typed values |
| ryangjchandler/laravel-cloudflare-turnstile ^3.0.4 | illuminate ^11-^13, PHP ^8.2 | Verified |
| coderflex/laravel-turnstile 2.2.1 (rejected) | guzzle **^7.7** only | Would conflict with Guzzle 8 |
| laravel-vite-plugin ^3.2 | vite ^8 | Verified (npm peerDependencies) |
| @tailwindcss/vite ^4.3 | vite ^5.2-^8 | Verified |
| spatie/laravel-backup ^10.3.3 | Laravel ^12.40 \|\| ^13, PHP ^8.3 | Verified; needs `pg_dump` 18 on the server |
| Laravel 14 (expected ~Q1 2027) | Unknown | Upgrade after launch, once Filament 5 / Livewire 4 / spatie packages declare support |

## Sources

All fetched on 2026-10-16. Seam tier for all of them: **LOW** (provider `webfetch`). They are primary sources (official registry/API/vendor pages), so version and price numbers are factual as of that date.

- Packagist p2 metadata API (`repo.packagist.org/p2/{vendor}/{pkg}.json`): every Composer version, release date and require constraint above
- npm registry (`registry.npmjs.org/{pkg}/latest`): tailwindcss, @tailwindcss/vite, vite, laravel-vite-plugin, alpinejs, @tailwindcss/forms, @tailwindcss/typography
- GitHub API: repo activity for sabre-io/vobject (pushed 2026-10-05), rlanvin/php-rrule (CI matrix 7.3-8.4), u01jmg3/ics-parser, laminas/laminas-feed, simplepie/simplepie, brick/structured-data (commits through 2026-10-08, last tag 0.2.0), spatie/* repos, filamentphp/filament, livewire/livewire; release notes for sabre/vobject 5.0.0, php-rrule 3.0.0; latest releases for Coolify v4.4.6, Kamal v2.12.0, Caddy v2.11.7, Umami v3.4.0
- Laravel docs (13.x, via GitHub API): `releases.md` (support policy table, PHP 8.3-8.5) and `database.md` (PostgreSQL 10.0+)
- Filament docs (5.x, via GitHub API): `02-installation.md` (PHP 8.2+, Laravel 11.28+, Tailwind 4.1+) and `06-version-support-policy.md`
- spatie/robots-txt README and `src/RobotsTxt.php` (wildcard handling); laravel-honeypot and laravel-cloudflare-turnstile READMEs
- php.net releases JSON (PHP 8.5.11, 8.4.26 current); packages.ubuntu.com (resolute: php 8.5, postgresql 18; noble: php 8.3)
- Vendor pricing pages: ploi.io/pricing, forge.laravel.com/pricing, umami.is/pricing, plausible.io, usefathom.com/pricing, developers.cloudflare.com/r2/pricing. Hetzner pricing was not machine-readable **[UNVERIFIED]**.

---
*Stack research for: local events aggregator (Attleborough Events)*
*Researched: 2026-10-16*
