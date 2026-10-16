# Phase 1: Foundation & Published Calendar - Research

**Researched:** 2026-10-16
**Domain:** Laravel 13 + Filament 5 scaffold, manual recurring events (sabre/vobject 5), public calendar (Blade), event SEO/ICS/share, CI on PostgreSQL 18, Deployer 8 → Hetzner VPS (Caddy, systemd, cron)
**Confidence:** MEDIUM-HIGH. Library behaviour that matters most (recurrence expansion across DST, date windows, ICS escaping, JSON-LD escaping, Filament timezone handling, Deployer/Boost internals) was **executed or read from source this session**. The GSD `classify-confidence` seam rates every source here **LOW** (provider `webfetch`; Context7/WebSearch unavailable), so tags below say what was actually done.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

#### Brand, domain & look
- **D-01:** The public site name is **"What's On Attleborough"**. Use it in the header, `<title>` suffix, Open Graph `og:site_name` and share text. The Laravel app/repo name stays `attleborough-events`.
- **D-02:** The production domain is **`whatsonattleborough.co.uk`**, canonical with no `www` (`www` 301-redirects to the apex). — **Reversibility:** costly — canonical URLs, event slugs shared on social media, OG metadata and (from Phase 3) the crawler User-Agent contact URL all embed the domain; changing it later needs 301s and loses accumulated link equity.
- **D-03:** DNS is hosted on **Cloudflare (free plan)**. Cloudflare is already in the stack for Turnstile (Phase 2) and R2 backups (Phase 5), and provides free email routing. The decision about the orange-cloud proxy is left to the planner; Caddy handles TLS on the origin either way.
- **D-04:** The public contact email is **`hello@whatsonattleborough.co.uk`**, forwarded to the owner via Cloudflare Email Routing. Configure it as `MAIL_FROM_ADDRESS` / contact address in config. It will be reused for the crawler User-Agent (Phase 3) and the privacy notice (Phase 5).
- **D-05:** The voice is a **friendly community noticeboard**: warm, plain English, a little personality (e.g. empty state "Nothing on today. Why not check the weekend?"), and never jokey on factual fields (dates, prices, cancellations). British English spelling and UK date formats (e.g. "Sat 14 Nov, 7:30pm").
- **D-06:** The visual style is **clean and local**: white background, **one warm accent colour (Norfolk green)**, clear readable type, and **simple category icons** used as image placeholders (no third-party images). The accent must pass WCAG 2.2 AA contrast for text and focus states in both themes.
- **D-07:** The logo is a **text wordmark plus a small calendar-pin icon**, delivered as inline SVG in the repo. Favicon and apple-touch-icon are derived from the icon. A default OG image is generated from wordmark + accent (static asset is fine).
- **D-08:** **Dark mode follows the device setting** (`prefers-color-scheme`, no toggle). Tailwind 4 dark variant; both themes meet AA contrast.

#### Carried forward from project level (do not re-ask)
- Stack: Laravel ^13.37, PHP 8.5 (Composer floor ^8.4), PostgreSQL 18 (+ `pg_trgm`), Filament ^5.10, Livewire ^4.4, Tailwind ^4.3 + Vite 8, Alpine only on non-Livewire public pages, Pest 5, Larastan, Pint. No Redis (database queue, cache and session). Tests run on PostgreSQL, never SQLite, with `Http::preventStrayRequests()`.
- Hosting: Hetzner CX23, Ubuntu 26.04, Caddy, PHP-FPM 8.5, PG 18, systemd queue workers, cron `schedule:run`, Deployer 8 via GitHub Actions. Deploy to the VPS in this phase.
- Recurrence: sabre/vobject ^5 is the only expansion engine. Manual series are stored as RRULE (synthetic VEVENT) and expanded over a rolling 90-day horizon. Occurrences are upserted on a recurrence key, preserving admin-set status. Always expand in Europe/London and store UTC. Include DST fixtures (25 Oct 2026, 28 Mar 2027).
- Expiry: end time, or start + 3h if no end, or end of day for all-day events. A denormalised `expires_at` is used for public window queries.
- Filters: Today / This weekend / This week (Europe/London overlap semantics per REQUIREMENTS CAL-02), category, Free, family-friendly flag. These are plain query-string links that work without JS, and filter URLs are `noindex`.
- Mobile list-first, no month grid; date-grouped headings; `<time datetime>`; 24x24px minimum targets; "Cancelled" shown as text.
- Only review actions and manual entry write published events (single choke point; Phase 1 implements manual entry through the same domain action that Phase 2's approval will reuse).

#### Owner prerequisites (human actions before or while executing)
- Register `whatsonattleborough.co.uk` (confirm availability at the registrar first; the `.com` is also unregistered if wanted defensively).
- Create or confirm accounts: Cloudflare (DNS + Email Routing), Hetzner Cloud (CX23), GitHub Actions secrets for deploy.
- The planner should turn these into `checkpoint:human` tasks at the right points.

#### Owner decision received during research (2026-10-16) — overrides STACK/SUMMARY "decline Laravel Boost"
- **USE Laravel Boost as a DEV-ONLY tool** (`composer require laravel/boost --dev`, `php artisan boost:install`, GitHub Copilot agent). MCP server + guidelines + skills for coding agents. Must never be a production dependency; the MCP server must never run on the VPS (`composer install --no-dev` on deploy). This does not violate "no LLM in the product". See [Laravel Boost (dev-only)](#laravel-boost-dev-only).

### the agent's Discretion
The owner chose not to discuss these areas; the planner/researcher decides, guided by the spec, research and decisions above:
- **Home calendar layout:** default view (all upcoming, grouped by day), filter chip presentation, card density, how far ahead the list goes (pagination or "load more" via plain links), and empty states (in the D-05 voice).
- **Manual entry & venues:** free-text venue vs reusable `venues` table (the spec data model includes `venues`), the recurrence form UX (weekly / nth weekday of month, with optional end date or count, and single-occurrence cancel/hide), and price and family-friendly inputs.
- **Event page & sharing details:** how upcoming dates of a series are listed, attribution text for manual events (source note/URL), share copy, and per-event OG (default OG image acceptable in Phase 1).
- Exact Norfolk-green hex value, font choice (self-hosted, no third-party font CDNs) and icon set (must be self-hosted SVG).

### Deferred Ideas (OUT OF SCOPE)
None. The discussion stayed within phase scope.

*(Also out of this phase per CONTEXT Phase Boundary: drafts/review queue/submissions (Phase 2); ingestion/crawling (3-4); sitemap, expired-page policy, report-a-problem, feeds, privacy/legal pages, analytics, WCAG audit, backup restore drill (Phase 5).)*
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| OPS-01 | Laravel 13, PHP 8.5 (floor 8.4), PG 18, Filament 5, Pest/Larastan/Pint, GH Actions CI on PostgreSQL | [Scaffold order](#scaffold-order-exact), [CI workflow](#github-actions-ci--deploy); Pest 5 needs PHPUnit 13 (skeleton ships 12) |
| OPS-02 | VPS with HTTPS, supervised workers, cron, zero-downtime deploys from GH Actions | [Deployment](#deployment-hetzner--caddy--systemd--deployer-8): Caddyfile with `resolve_root_symlink`, systemd unit, cron, `deploy.php` (`local_archive`), deployphp/action |
| OPS-04 | Admin sees scheduler heartbeat and failed jobs; logs rotated | [Pattern 8](#pattern-8-ops-visibility-heartbeat--failed-jobs--logs): `heartbeats` table + Filament "System health" page; `LOG_STACK=daily`; Caddy `roll_*` |
| CAL-01 | Published, non-expired occurrences, ordered, date headings, mobile list | [Pattern 5](#pattern-5-datewindow--publicoccurrencequery-read-side); grouping key = London date of `max(starts_at, today)` |
| CAL-02 | Today / This weekend / This week (London, overlap) | [Pattern 5](#pattern-5-datewindow--publicoccurrencequery-read-side) — computation executed across both DST weekends |
| CAL-03/04/05 | Category, Free, family-friendly filters | Same query; `price_type = 'free'`; `events.family_friendly` boolean |
| CAL-06 | Plain query-string links without JS; filter URLs `noindex` | Blade controller (no Livewire on public pages); `<meta name="robots" content="noindex, follow">` when any filter param present |
| CAL-07 | Card fields + text "Cancelled" badge | Event/occurrence status enums; event-level `cancelled` must still be listed (see Pitfall 6) |
| CAL-08 | One entry per occurrence over rolling 90 days | [Pattern 2/3](#pattern-2-manual-recurrence--synthetic-vevent--sabre-eventiterator) + daily `calendar:sync-occurrences` |
| EVT-01 | Event page: date/time, venue/address, organiser contact, attribution, full description | `events` columns + `venues`; plain-text description rendered escaped |
| EVT-02 | Slug URLs, title/meta/canonical/OG | `/events/{event}/{slug}` + 301 on slug mismatch |
| EVT-03 | Event JSON-LD per upcoming occurrence (cap ~10) with `eventStatus` | [Pattern 6](#pattern-6-event-page-json-ld-ics-google-link-share) — spatie/schema-org 5.0.2 executed |
| EVT-06 | `.ics` (stable UID) or Google Calendar link | UID `{event_id}-{recurrence_key}@whatsonattleborough.co.uk`; UTC times; sabre serialiser executed |
| EVT-07 | Web Share + WhatsApp/Facebook/email/copy fallbacks | Server-rendered fallback links; JS enhances (`navigator.share` requires secure context + transient activation) |
| REC-01 | Scheduled materialisation, upsert by recurrence key, preserve admin status | `EventOccurrence::upsert(..., update: [...])` excluding `status` |
| REC-02 | Multi-day = one occurrence; all-day expires end of day; no end → +3h | `expires_at` derivation table in Pattern 3 |
| ADM-01 | Filament login, single admin | `FilamentUser::canAccessPanel()` + `make:filament-user --name --email --password` |
| ADM-02 | Manual event with weekly / nth-weekday recurrence + source note; origin `manual`, admin as approver | Filament `CreateRecord::handleRecordCreation()` → `PublishEvent` action |
| ADM-03 | Edit, hide, unhide, cancel events and single occurrences; status survives regeneration | `ChangeEventStatus`, `ChangeOccurrenceStatus` actions; occurrences relation manager |
</phase_requirements>

## Project Constraints (from copilot-instructions.md)

`./.github/copilot-instructions.md` (and `AGENTS.md`) contain only the GSD block. Actionable directives:
- Use GSD skills only when the user asks for GSD / `gsd-*` commands; load matching files from `.github/skills/gsd-*`; prefer custom agents from `.github/agents`.
- "Do not apply GSD workflows unless the user explicitly asks for them."
- After a `gsd-*` deliverable, offer the next step via `ask_user`.

No coding conventions, tool bans or test rules live there. App-level rules come from CONTEXT.md (above) and will be added as Boost custom guidelines (see Boost section). **Both files are GSD-managed blocks; app work must not delete them.**

## Summary

Project research already fixed the stack, hosting and architecture. This phase research pins down **how to actually build Phase 1**, and several of its findings change details of earlier research:

1. **The Laravel 13 skeleton (v13.11.0) is not ready as-is for this project.** It ships PHPUnit 12 (Pest 5 needs PHPUnit ^13.4.2), a SQLite `phpunit.xml`, an `AGENTS.md` + `CLAUDE.md` Boost bootstrap prompt that would overwrite the repo's GSD `AGENTS.md`, and a `vite.config.js` that downloads **Instrument Sans from fonts.bunny.net at build time**. Scaffold into a temp dir and copy in selectively. Then swap PHPUnit for Pest, point tests at PostgreSQL, and replace the bunny font with a self-hosted `@fontsource-variable` package [VERIFIED: laravel/laravel v13.11.0 tree + composer.json; packagist pestphp/pest v5.3.2 require].
2. **The recurrence engine works as needed. It was executed this session.** A synthetic VEVENT (`DTSTART;TZID=Europe/London`, `RRULE` with `BYDAY=FR`, `BYDAY=2TU`, `BYDAY=-1FR`, `COUNT`, `UNTIL=…Z`) expanded with `new EventIterator($vevent, null, new DateTimeZone('Europe/London'))` keeps 10:00 local across 25 Oct 2026 (09:00Z → 10:00Z). It handles the 28 Mar 2027 gap the RFC way: 01:30 local becomes 01:30Z, i.e. 02:30 BST. All-day instances on the 25-hour day end at the next local midnight [VERIFIED: executed sabre/vobject 5.0.0 on PHP 8.4.12].
3. **Use a date-only `recurrence_key` (`Y-m-d`, London) for manual series.** Manual patterns produce at most one instance per day. A date key lets admin-set statuses survive a change of start *time*, which a datetime key would not. The DST-gap shift (01:30 → 02:30) also cannot change the key.
4. **Store the event's schedule as local wall-clock values (`start_date`, `start_time`, …) and occurrences as UTC `timestamptz`.** Filament's `TimePicker` silently converts time-only values through `FilamentTimezone` (`getTimezone()` returns `FilamentTimezone::get()` whenever the field has a time). So do **not** call `FilamentTimezone::set()` globally. Set `->timezone('Europe/London')` on display columns instead [VERIFIED: filament 5.x `DateTimePicker.php:897-900`]. Laravel also sends timestamps to PostgreSQL **without an offset** (`Y-m-d H:i:s`) and formats a Carbon in its own zone. Set the pgsql connection `'timezone' => 'UTC'` and always `->utc()` before persisting [VERIFIED: framework v13.37.0 `Grammar.php:282-285`, `HasAttributes.php:1639-1644`, `PostgresConnector.php:180-187`].
5. **Deployment is mostly off-the-shelf, with two traps.** (a) PHP-FPM opcache serves the old release after the symlink swap unless Caddy uses `php_fastcgi … { resolve_root_symlink }`. (b) **Don't run `dep provision`.** Deployer 8's provision recipe adds `ppa:ondrej/php` and installs `redis`, both against project constraints, and its Postgres step only does `GRANT ALL PRIVILEGES ON DATABASE`. Write a small `ops/provision.sh` on distro packages and create the DB with `OWNER` [VERIFIED: deployer v8.0.5 `recipe/provision.php`, `recipe/provision/databases.php`, `docs/avoid-php-fpm-reloading.md`; caddy `fastcgi.go:289-294`].

**Primary recommendation:** Build the walking skeleton first: scaffold → `events`/`event_occurrences`/`categories`/`venues` migrations → Filament create a single event through `PublishEvent` → the public Blade list shows it → deployed at `https://whatsonattleborough.co.uk` by GitHub Actions + Deployer. Then layer on recurrence, filters, the event page, ICS/share, JSON-LD and ops visibility. Start the domain/DNS/Hetzner human checkpoints on day 1, because nameserver propagation and server provisioning are on the critical path.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Public calendar list + filters | Frontend Server (Blade SSR) | Database | Must work without JS (CAL-06), so it is server-rendered GET with query params; no Livewire on public pages |
| Date-window computation (London → UTC) | API/Backend (`App\Calendar\DateWindow`) | — | One pure class, used by the list and by tests; never in Blade or SQL |
| Occurrence materialisation | API/Backend (`OccurrenceGenerator`) | Database (upsert, unique index) | Domain logic; runs in the publish transaction and daily from the scheduler |
| Publish / edit / status changes | API/Backend (`App\Publishing\Actions\*`) | Admin UI (Filament calls actions) | The single choke point that Phase 2 approval reuses; Filament holds no rules |
| Admin auth | Frontend Server (Filament panel, session) | Database (sessions table) | Filament login + `canAccessPanel()`; no public accounts |
| Event page SEO / JSON-LD / OG | Frontend Server (Blade) | — | Must be in initial HTML for crawlers |
| `.ics` download | API/Backend (controller → sabre serializer) | — | Generated per request from the occurrence; no files stored |
| Google Calendar link / share links | Frontend Server (Blade renders hrefs) | Browser (Web Share / clipboard enhancement) | Fallbacks work without JS; JS only adds the native share sheet and copy |
| Heartbeat + failed jobs visibility | API/Backend (scheduler writes, queue job writes) | Admin UI (Filament page) | Proves cron and worker are alive; Filament only reads |
| TLS, www→apex redirect, static assets | CDN/Static (Caddy) | Cloudflare DNS | Caddy gets Let's Encrypt certs; `/build/*` gets immutable cache headers |
| Persistence | Database (PostgreSQL 18) | — | `timestamptz` UTC; partial indexes for the public window query |

## Standard Stack

Phase 1 subset only. Versions were checked against Packagist/npm on 2026-10-16. Everything not listed (sabre is listed; php-rrule, laminas-feed, dom-crawler, robots-txt, sitemap, honeypot, turnstile, backup) is **deferred to the phase that needs it**.

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | ^13.37 (v13.37.0, 2026-10-16) via skeleton laravel/laravel v13.11.0 | Framework, scheduler, DB queue/cache/session | Locked [VERIFIED: packagist] |
| filament/filament | ^5.10 (v5.10.2, 2026-10-13) | Admin panel (+ Livewire ^4.4.7, blade-heroicons ^2.5 transitively) | Locked [VERIFIED: packagist] |
| sabre/vobject | ^5.0 (5.0.0) | Expand manual series (synthetic VEVENT) and serialise per-occurrence `.ics` | Locked single engine; behaviour executed this session [VERIFIED: composer install + probe] |
| spatie/schema-org | ^5.0.2 (PHP ^8.4) | Event JSON-LD builder; `toScript()` escapes `</script>` | [VERIFIED: executed probe] |
| tailwindcss + @tailwindcss/vite | ^4.3 | Public styling (skeleton ships `^4.0.0`, so bump it) | Locked [VERIFIED: npm] |
| vite + laravel-vite-plugin | ^8 / ^3.2 (3.2.0) | Asset build | Skeleton default [VERIFIED: skeleton package.json, npm] |
| @fontsource-variable/atkinson-hyperlegible-next | ^5.3.0 | Self-hosted, legibility-focused variable font (discretion item) | npm, OK verdict, no postinstall [VERIFIED: npm + legitimacy seam]. Font *choice* is a recommendation |

### Supporting / Dev
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| pestphp/pest | ^5.3 (v5.3.2; requires `phpunit/phpunit ^13.4.2`, PHP ^8.4) | Tests | Replace the skeleton's PHPUnit 12 [VERIFIED: packagist require] |
| pestphp/pest-plugin-laravel | ^5.0.1 | Laravel helpers | Always |
| pestphp/pest-plugin-livewire | ^5.0 | `livewire()` for Filament tests | Filament resource/action tests |
| larastan/larastan | ^3.13 (v3.13.0) | PHPStan level 5→6 | CI |
| laravel/pint | ^1.32 (skeleton ships ^1.27) | Style | CI `pint --test` |
| deployer/deployer | ^8.0 (v8.0.5, PHP ^8.3) | Zero-downtime deploys (dev dep, run from CI via `vendor/bin/dep`) | Deploy job |
| laravel/boost | ^2.10 (v2.10.3, 2026-10-07) **dev only** | Agent guidelines/skills/MCP (owner decision) | Local dev only |
| laravel/pao | ^1.0.6 (skeleton dev dep) | "Agent-optimized output for PHP testing tools" | Keep (harmless dev dep) [VERIFIED: packagist description] |

**Not in Phase 1:** Alpine.js (not needed: share/copy is ~30 lines of vanilla JS, and filters are plain links), `rlanvin/php-rrule` (Phase 1 recurrence summaries come from the two manual patterns, e.g. "Every Friday" / "Second Tuesday of the month", which read better in the D-05 voice. It works on PHP 8.4 if wanted later: `humanReadable(['locale' => 'en_GB'])` returned "weekly on Friday, until 18/12/2026"; `'en'` gives US dates) [VERIFIED: executed].

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Blade controller for the public list | Livewire component with `#[Url]` (ARCHITECTURE.md) | Livewire adds nothing when filters must work without JS. Blade is simpler to cache and test |
| `venues` table | Free-text venue on `events` | Spec §9 has `venues`, and Phase 3 dedup uses venue identity. Filament `Select::relationship()->createOptionForm()` keeps entry fast |
| Own `ops/provision.sh` | `dep provision` | Recipe installs redis + ondrej PPA and grants DB privileges insufficient for PG15+ (see Pitfall 9) |
| Date+time columns on events | One `DateTimePicker` → `timestamptz` | Local columns map 1:1 to the synthetic VEVENT and avoid all picker timezone conversion |

**Installation:** see [Scaffold order](#scaffold-order-exact).

## Package Legitimacy Audit

The seam supports only npm/pypi/crates (`Error: Usage: gsd-tools package-legitimacy check --ecosystem <npm|pypi|crates>` for composer). Composer packages were verified against Packagist metadata and official GitHub repos/docs instead.

| Package | Registry | Age / Release | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|---------------|-----------|-------------|---------|-------------|
| tailwindcss | npm | mature | high | github.com/tailwindlabs/tailwindcss | OK | Approved |
| @tailwindcss/vite | npm | mature | high | tailwindlabs | OK | Approved |
| vite | npm | mature package; latest release very recent | very high | github.com/vitejs/vite | **SUS (`too-new`)** | Flagged. The signal is the *release* recency, not the package. It's the official skeleton default (`"vite": "^8.0.0"`). Planner: one `checkpoint:human-verify` before `npm install`, or accept the skeleton lockfile |
| laravel-vite-plugin | npm | mature | high | laravel/vite-plugin | OK | Approved |
| @fontsource-variable/atkinson-hyperlegible-next | npm | 2026-07-19 | ~91k/wk | github.com/fontsource/font-files | OK | Approved |
| laravel/framework, laravel/laravel, laravel/boost, laravel/pint, laravel/pao | Packagist | official Laravel org | — | github.com/laravel/* | n/a (composer) | Approved (official org) |
| filament/filament | Packagist | v5.10.2 | — | github.com/filamentphp/filament | n/a | Approved (docs read) |
| sabre/vobject, spatie/schema-org | Packagist | 5.0.0 / 5.0.2 | — | sabre-io / spatie | n/a | Approved (installed + executed) |
| pestphp/*, larastan/larastan, deployer/deployer | Packagist | current | — | official repos | n/a | Approved |

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** `vite` (release-recency signal only; see disposition)
**No postinstall scripts** on the npm packages above (`npm view <pkg> scripts.postinstall` empty) [VERIFIED].

## Architecture Patterns

### System Architecture Diagram

```
                         ┌──────────────── Cloudflare DNS (apex + www A/AAAA → VPS) ───────────────┐
Visitor (phone) ──HTTPS──► Caddy :443 ── www.* ──► 301 https://whatsonattleborough.co.uk{uri}
                            │  /build/* ──► static (immutable cache)
                            └─ php_fastcgi (resolve_root_symlink) ──► PHP-FPM 8.5 ──► Laravel
                                                                                          │
   GET /?when=weekend&category=music&free=1&family=1                                      │
      └─► CalendarController ─► DateFilter (validated) ─► DateWindow(London→UTC [start,end))
              └─► PublicOccurrenceQuery (events ⋈ occurrences, expires_at > max(now,start), starts_at < end)
                     └─► group by London date ─► Blade list (+ noindex if filtered)
   GET /events/{id}/{slug}  ─► EventController ─► slug mismatch? ─yes─► 301 canonical
                                   └─► Blade page + meta/OG + JSON-LD (≤10 upcoming occurrences)
   GET /events/{id}/occurrences/{Y-m-d}/calendar.ics ─► IcsController ─► sabre VCalendar (UTC) ─► text/calendar
                                                                                          │
Admin ──HTTPS──► /admin (Filament login, canAccessPanel)                                  │
   Create/Edit Event form ─► handleRecordCreation/Update ─► PublishEvent / UpdatePublishedEvent
                                   └─► DB::transaction { Event row ─► OccurrenceGenerator::sync }
                                                              └─► ManualRecurrence → RRULE → synthetic VEVENT
                                                                   → sabre EventIterator(Europe/London) → UTC rows
                                                                   → upsert(event_id, recurrence_key) [status untouched]
   Occurrences relation manager ─► ChangeOccurrenceStatus (cancel / hide / restore)
   System health page ◄── heartbeats table, failed_jobs table

cron (every minute) ─► schedule:run ─┬─► heartbeat:scheduler (+ optional external ping)
                                     ├─► QueueHeartbeat job (every 5 min) ─► jobs table ─► systemd queue:work ─► heartbeat:queue
                                     ├─► calendar:sync-occurrences (daily 03:10 London) ─► OccurrenceGenerator for all series
                                     └─► queue:prune-failed (daily)

GitHub push main ─► Actions: pint --test, phpstan, pest (postgres:18 service), npm run build
                     └─► deploy job ─► vendor/bin/dep deploy (local_archive + upload public/build)
                            └─► VPS: releases/N, shared .env+storage, composer --no-dev, optimize, migrate --force,
                                     symlink current → releases/N, artisan reload (queue:restart + schedule:interrupt)
```

### Recommended Project Structure

```
app/
├── Enums/                    # EventStatus, OccurrenceStatus, EventOrigin, PriceType, RepeatPattern, DateFilter
├── Models/                   # User (FilamentUser), Event, EventOccurrence, Venue, Category, Heartbeat, FailedJob
├── Publishing/               # WRITE side: the only code that writes events/occurrences
│   ├── Data/PublishableEventData.php
│   └── Actions/              # PublishEvent, UpdatePublishedEvent, ChangeEventStatus, ChangeOccurrenceStatus
├── Calendar/                 # Time + read side (not app/Events, which means Laravel events)
│   ├── ManualRecurrence.php  # form fields ⇄ RRULE
│   ├── OccurrenceGenerator.php
│   ├── EventTiming.php       # local schedule → UTC start/end/expires per instance
│   ├── DateWindow.php
│   └── PublicOccurrenceQuery.php
├── Http/Controllers/         # CalendarController, EventController, OccurrenceIcsController
├── Support/                  # Heartbeat helper, LondonFormatter (UK date strings), ShareLinks, GoogleCalendarLink
├── Console/Commands/         # SyncOccurrences
├── Jobs/QueueHeartbeat.php
└── Filament/
    ├── Resources/Events/     # EventResource + Pages + RelationManagers/OccurrencesRelationManager
    ├── Resources/Venues/     # simple resource (optional; createOptionForm covers most needs)
    └── Pages/SystemHealth.php
resources/views/{layouts,calendar,events,components}/
resources/svg/                # wordmark, calendar-pin icon (inline SVG)
ops/                          # provision.sh, Caddyfile, attleborough-queue.service, cron.d file, journald.conf.d
deploy.php
.github/workflows/ci.yml
.ai/guidelines/               # Boost custom guidelines (project rules)
tests/{Unit/Calendar, Feature/{Public,Filament,Publishing,Ops}}
```

### Scaffold order (exact)

The repo root already has `.github/` (GSD), `.planning/`, `docs/`, `AGENTS.md` and `.gitignore`. `composer create-project` needs an empty dir, so scaffold in a temp dir:

```bash
# 1. Skeleton into temp, copy everything EXCEPT skeleton AGENTS.md / CLAUDE.md / README.md / .gitignore
composer create-project laravel/laravel:^13.0 /tmp/ae-skel
rsync -a --exclude AGENTS.md --exclude CLAUDE.md --exclude README.md --exclude .gitignore \
      --exclude database/database.sqlite --exclude .env /tmp/ae-skel/ ./
cp .env.example .env && php artisan key:generate
#    merge skeleton .gitignore entries into the repo .gitignore by hand (repo already covers most)

# 2. Lock resolution to a PHP version that runs both locally (8.4.12) and in prod (8.5)
composer config platform.php 8.4.12        # see note below

# 3. PostgreSQL locally (PG18 image: VOLUME moved to /var/lib/postgresql)
docker run -d --name ae-pg -e POSTGRES_PASSWORD=postgres -p 5432:5432 \
  -v ae-pg18:/var/lib/postgresql postgres:18
#    .env: DB_CONNECTION=pgsql DB_DATABASE=attleborough ...; create attleborough + attleborough_testing

# 4. Pest instead of PHPUnit 12 (Pest 5 requires phpunit ^13.4.2)
composer remove --dev phpunit/phpunit
composer require --dev pestphp/pest:^5.3 pestphp/pest-plugin-laravel:^5.0.1 \
  pestphp/pest-plugin-livewire:^5.0 larastan/larastan:^3.13 deployer/deployer:^8.0 -W
./vendor/bin/pest --init

# 5. Filament panel (adds "@php artisan filament:upgrade" to post-autoload-dump)
composer require filament/filament:^5.10
php artisan filament:install --panels      # creates app/Providers/Filament/AdminPanelProvider.php

# 6. App libraries for this phase
composer require sabre/vobject:^5.0 spatie/schema-org:^5.0.2

# 7. Frontend: bump Tailwind, replace bunny font with self-hosted fontsource
npm install -D tailwindcss@^4.3 @tailwindcss/vite@^4.3
npm install @fontsource-variable/atkinson-hyperlegible-next
#    remove `fonts: [bunny(...)]` from vite.config.js (or switch to the plugin's fontsource()/local() provider)

# 8. Laravel Boost LAST (it detects installed packages to choose guidelines)
composer require laravel/boost:^2.10 --dev
php artisan boost:install                  # pick GitHub Copilot; then add .ai/guidelines/* and run boost:update
```

Notes:
- `platform.php`: STACK.md said `8.5.11`. That resolves for 8.5 while the dev machine runs **8.4.12** (`php --version`), and Composer's `platform_check.php` would fail locally the moment any locked package needs ^8.5. Pinning the **floor** (8.4.x) guarantees the lock installs on both. CI and prod still *run* 8.5 [VERIFIED: local `php --version`; ASSUMED: no future package forces ^8.5].
- `vite.config.js` (skeleton) imports `bunny` from `laravel-vite-plugin/fonts`. The plugin fetches `https://fonts.bunny.net/css2` **at build time**, caches it under `node_modules/.cache/laravel-vite-plugin/fonts` and emits the files as build assets. Visitors would get self-hosted files, but CI builds would depend on a third-party CDN. Use npm-packaged fonts so the build is offline-deterministic [VERIFIED: laravel-vite-plugin 3.2.0 `dist/index.js` lines 19, 1021-1068, 1146-1157].
- `.gitattributes` from the skeleton contains `/.github export-ignore`. With Deployer's `local_archive` strategy (`git archive`), the GSD tooling is never shipped to the server. Add `/.planning export-ignore`, `/docs export-ignore` and `/.ai export-ignore` too [VERIFIED: skeleton `.gitattributes`; deployer `update_code.php`].

### Data model (Phase 1 subset, designed for Phase 2 reuse)

Proposed. These are new tables, not existing in-repo values.

| Table | Columns (Phase 1) | Notes |
|---|---|---|
| `categories` | id, name, slug (unique), icon (heroicon name), sort | Seed the 10 from spec §7.2: Family, Community, Market, Charity, Music, Sport, Food & Drink, School, Arts & Culture, Other |
| `venues` | id, name, address, postcode, town, lat/lng (nullable), aliases (jsonb, default `[]`) | Phase 3 dedup reuses it |
| `events` | id, title, slug, description (text), category_id, venue_id, family_friendly (bool), price_type (`free`/`paid`/`unknown`), price_text, organiser_name, organiser_contact, source_url, source_note, **timezone** (default `Europe/London`), **all_day**, **start_date** (date), **start_time** (time, null if all-day), **end_date** (date, null), **end_time** (time, null), **recurrence_rule** (text, null), status (`published`/`hidden`/`cancelled`), origin (`manual`/`submission`/`discovered`), approved_by (FK users), approved_at (timestamptz), timestamps | Local wall-clock schedule = exactly what the admin typed. `CHECK` constraints on enum values. Phase 2 adds draft linkage; `PublishEvent` stays the writer |
| `event_occurrences` | id, event_id (FK cascade), recurrence_key (varchar 32), starts_at, ends_at (null), expires_at (timestamptz, not null), status (`scheduled`/`cancelled`/`hidden`), timestamps | `UNIQUE(event_id, recurrence_key)` (required by Laravel `upsert` on PG) |
| `heartbeats` | name (pk), last_seen_at (timestamptz), meta (jsonb null) | Scheduler / queue liveness. A table, not the cache: `cache:clear` must not erase evidence |
| `users`, `sessions`, `cache`, `jobs`, `job_batches`, `failed_jobs` | skeleton migrations | `failed_jobs`: id, uuid (unique), connection, queue, payload, exception, failed_at [VERIFIED: skeleton `0001_01_01_000002_create_jobs_table.php:37-46`] |

Indexes:
```sql
CREATE UNIQUE INDEX event_occurrences_event_key_uq ON event_occurrences (event_id, recurrence_key);
CREATE INDEX event_occurrences_public_idx ON event_occurrences (starts_at, expires_at) WHERE status <> 'hidden';
CREATE INDEX events_status_idx ON events (status);
CREATE EXTENSION IF NOT EXISTS pg_trgm;   -- not used until Phase 3; run now to prove the DB owner can create it
```

### Pattern 1: Local schedule on the event, UTC instants on occurrences

**What:** `events` stores `start_date/start_time/end_date/end_time/all_day/timezone`. `EventTiming` turns one *instance date* into UTC `starts_at/ends_at/expires_at`:

| Case | starts_at | ends_at | expires_at |
|---|---|---|---|
| Timed with end | local(start_date+start_time) → UTC | local(end_date ?? start_date + end_time) → UTC (if ≤ start, +1 day = overnight) | ends_at |
| Timed, no end | as above | null | starts_at + 3h |
| All-day (one or many days) | local(start_date 00:00) → UTC | local((end_date ?? start_date) + 1 day 00:00) → UTC (**exclusive**) | ends_at |

Multi-day = one occurrence (REC-02). For recurring events, disallow multi-day spans in the form (validation: `end_date` hidden when repeating; only `end_time`, with overnight allowed).

**Why:** the form fields map directly onto `DTSTART;TZID=Europe/London`, there are no picker conversions, and a series stays at 10:00 local across DST.

**Filament form pickers:** `DatePicker` (date-only) uses the app timezone (UTC). It doesn't convert plain dates. `TimePicker` **does** convert from the component's timezone, which defaults to `FilamentTimezone::get()`:
```php
// filament 5.x packages/forms/src/Components/DateTimePicker.php:897-900 (verbatim)
public function getTimezone(): string
{
    return $this->evaluate($this->timezone) ?? ($this->hasTime() ? FilamentTimezone::get() : config('app.timezone'));
}
```
So: leave `FilamentTimezone` at its default (app tz = UTC, i.e. no conversion), store wall-clock `time` columns, and add `->timezone('Europe/London')` to every `TextColumn`/`TextEntry` that shows an occurrence's UTC `starts_at` [VERIFIED: source read]. Add a Pest test that saves "10:00" under a frozen BST date and asserts `start_time = '10:00:00'`.

### Pattern 2: Manual recurrence → synthetic VEVENT → sabre EventIterator

**Form UX (recommended):** Google-Calendar-style "Repeats" select whose options are **computed from the chosen start date** (Filament `->live()` + `->options(fn (Get $get) => ...)`):
- "Does not repeat"
- "Weekly on {Friday}"
- "Monthly on the {second} {Tuesday}" (offered when nth ≤ 4)
- "Monthly on the last {Tuesday}" (offered when start_date + 7 days falls in the next month)

Then "Ends": Never (rolling 90-day horizon) / On date (`UNTIL`) / After N times (`COUNT`).

```php
// App\Calendar\ManualRecurrence: values here are proposals, not existing code
enum RepeatPattern: string { case None = 'none'; case Weekly = 'weekly'; case MonthlyNth = 'monthly_nth'; case MonthlyLast = 'monthly_last'; }

public function toRrule(CarbonImmutable $startLocal): ?string   // $startLocal is in Europe/London
{
    $day = strtoupper(substr($startLocal->englishDayOfWeek, 0, 2));       // MO..SU
    $rule = match ($this->pattern) {
        RepeatPattern::None        => null,
        RepeatPattern::Weekly      => "FREQ=WEEKLY;BYDAY={$day}",
        RepeatPattern::MonthlyNth  => 'FREQ=MONTHLY;BYDAY='.(intdiv($startLocal->day - 1, 7) + 1).$day,
        RepeatPattern::MonthlyLast => "FREQ=MONTHLY;BYDAY=-1{$day}",
    };
    if ($rule === null) return null;
    if ($this->untilDate) {   // RFC 5545: UNTIL must be UTC when DTSTART has a TZID; UNTIL is inclusive
        $rule .= ';UNTIL='.CarbonImmutable::createFromFormat('!Y-m-d', $this->untilDate, 'Europe/London')
            ->endOfDay()->utc()->format('Ymd\THis\Z');
    } elseif ($this->count) {
        $rule .= ";COUNT={$this->count}";     // COUNT includes DTSTART
    }
    return $rule;
}
```

**Building + expanding (executed this session, sabre/vobject 5.0.0):**
```php
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Recur\EventIterator;

$tz = new DateTimeZone($event->timezone);                 // 'Europe/London'
$cal = new VCalendar();
$vevent = $cal->add('VEVENT', [
    'UID'     => "event-{$event->id}@whatsonattleborough.co.uk",
    'DTSTART' => $firstStartLocal,                         // DateTimeImmutable in $tz → serialises DTSTART;TZID=Europe/London
    'RRULE'   => $event->recurrence_rule,
]);
if ($durationMinutes !== null) { $vevent->add('DTEND', $firstStartLocal->modify("+{$durationMinutes} minutes")); }
// all-day: $vevent->add('DTSTART', $date, ['VALUE' => 'DATE']);  → DTSTART;VALUE=DATE:20261025

$it = new EventIterator($cal->VEVENT, null, $tz);          // ALWAYS pass the tz (default is UTC)
$it->fastForward($windowStartLocal);                       // skips instances whose END <= window start
while ($it->valid() && $it->getDtStart() < $windowEndLocal) {
    $startLocal = $it->getDtStart();                       // DateTimeImmutable in $tz
    $key = $startLocal->format('Y-m-d');                   // recurrence_key
    // EventTiming computes starts_at/ends_at/expires_at (UTC) from $key + event schedule
    $it->next();
}
```
Constructor signature (verbatim): `public function __construct($input, ?string $uid = null, ?\DateTimeZone $timeZone = null)` with `if (is_null($timeZone)) { $timeZone = new \DateTimeZone('UTC'); }`. `fastForward` is `while ($this->valid() && $this->getDtEnd() <= $dateTime) { $this->next(); }` [VERIFIED: vendor/sabre/vobject/lib/Recur/EventIterator.php:92-97, 399-404].

**Observed output (probe, window 16 Oct 2026 → +90d):**
```
FREQ=WEEKLY;BYDAY=FR from 2026-10-02 10:00
  Fri 2026-10-23 10:00 BST | utc 2026-10-23T09:00Z
  Fri 2026-10-30 10:00 GMT | utc 2026-10-30T10:00Z          ← DST end handled
FREQ=MONTHLY;BYDAY=2TU;COUNT=6 from 2026-09-08 19:30  → Nov 10, Dec 8, Jan 12, Feb 9 (Sep/Oct before window; COUNT includes DTSTART)
FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20270331T235959Z     → Oct 30, Nov 27, Dec 25, Jan 29, Feb 26, Mar 26
FREQ=WEEKLY;BYDAY=SU 01:30 across 28 Mar 2027       → Sun 2027-03-28 02:30 BST (utc 01:30Z)   ← nonexistent time shifted (RFC behaviour)
FREQ=WEEKLY;BYDAY=SA 23:30 +180min across 25 Oct    → Sat 24 Oct 23:30 BST → 02:30 GMT (3h absolute)
All-day weekly SU from 2026-10-25                   → 2026-10-25T00:00+01:00 → 2026-10-26T00:00+00:00 (25h day correct)
```
[VERIFIED: executed]. Use these exact cases as the DST fixture set (Pest `travelTo`).

### Pattern 3: OccurrenceGenerator upsert that preserves admin status

```php
public function sync(Event $event, ?CarbonImmutable $now = null): void
{
    $tz = $event->timezone;
    $windowStart = ($now ?? CarbonImmutable::now())->setTimezone($tz)->startOfDay();
    $windowEnd = $windowStart->addDays(config('calendar.horizon_days', 90));

    $rows = collect($this->instances($event, $windowStart, $windowEnd))   // single event → exactly one instance
        ->map(fn (OccurrenceTiming $t) => [
            'event_id'       => $event->id,
            'recurrence_key' => $t->key,                                     // 'Y-m-d' (London)
            'starts_at'      => $t->startsAt->utc(),
            'ends_at'        => $t->endsAt?->utc(),
            'expires_at'     => $t->expiresAt->utc(),
            'status'         => OccurrenceStatus::Scheduled->value,          // only used on INSERT
        ])->all();

    DB::transaction(function () use ($event, $rows, $windowStart) {
        EventOccurrence::upsert($rows, uniqueBy: ['event_id', 'recurrence_key'],
            update: ['starts_at', 'ends_at', 'expires_at']);                 // NOT status → admin status survives
        $event->occurrences()                                                // rule no longer produces these
            ->where('starts_at', '>=', $windowStart->utc())                  // never touch the past
            ->whereNotIn('recurrence_key', array_column($rows, 'recurrence_key'))
            ->delete();
    });
}
```
- Laravel: "All databases except SQL Server require the columns in the second argument of the `upsert` method to have a "primary" or "unique" index." Upsert sets timestamps automatically [CITED: laravel/docs 13.x eloquent.md#upserts].
- Orphan rule: if the admin changes Friday → Saturday, future Friday rows (including any they cancelled) are deleted. That's intended, because they're no longer part of the series. Changing only the *time* keeps keys, so statuses survive.
- Called inside `PublishEvent`/`UpdatePublishedEvent` (same transaction) and by `calendar:sync-occurrences` daily at 03:10 Europe/London (avoid 01:00-02:00 local, which skips or repeats at DST) with `withoutOverlapping()`. The daily job iterates **all** events with a `recurrence_rule` (including hidden ones, so unhiding is instant).
- `upsert` takes raw values and bypasses Eloquent casts. Pass `->utc()` Carbon instances or pre-formatted UTC strings (see Pitfall 2).

### Pattern 4: Single write choke point (Phase 2 ready)

```php
// App\Publishing\Actions\PublishEvent: the ONLY creator of Event rows
final class PublishEvent
{
    public function __construct(private OccurrenceGenerator $occurrences) {}

    public function __invoke(PublishableEventData $data, User $approver, EventOrigin $origin): Event
    {
        return DB::transaction(function () use ($data, $approver, $origin) {
            $event = Event::create($data->toAttributes() + [
                'status' => EventStatus::Published, 'origin' => $origin,
                'approved_by' => $approver->id, 'approved_at' => now(),
            ]);
            $this->occurrences->sync($event);
            return $event;
        });
    }
}

// Filament CreateEvent page (hook names verified in Filament 5 docs)
protected function handleRecordCreation(array $data): Model
{
    return app(PublishEvent::class)(PublishableEventData::fromForm($data), auth()->user(), EventOrigin::Manual);
}
// EditEvent::handleRecordUpdate(Model $record, array $data): Model → UpdatePublishedEvent
```
Phase 2's `ApproveDraft` builds `PublishableEventData::fromDraft($draft)` and calls the same `PublishEvent`. Manual entry can then be rewired through an auto-approved draft without touching publish logic. Status changes use `ChangeEventStatus(Event, EventStatus)` / `ChangeOccurrenceStatus(EventOccurrence, OccurrenceStatus)` with enum `canTransitionTo()` (ARCHITECTURE Pattern 3) [CITED: filament 5.x docs 03-resources/03-creating-records.md, 04-editing-records.md].

### Pattern 5: DateWindow + PublicOccurrenceQuery (read side)

```php
// App\Calendar\DateWindow: compute in London, compare in UTC, half-open [start, end)
public static function for(DateFilter $filter, CarbonImmutable $now): ?self
{
    $local = $now->setTimezone('Europe/London');
    $today = $local->startOfDay();
    return match ($filter) {
        DateFilter::All     => null,
        DateFilter::Today   => new self($today, $today->addDay()),
        DateFilter::Weekend => (function () use ($local, $today) {
            $sat = $local->isSaturday() ? $today : ($local->isSunday() ? $today->subDay() : $today->next(CarbonImmutable::SATURDAY));
            return new self($sat, $sat->addDays(2));                 // Sat 00:00 → Mon 00:00 (= "Sun 23:59")
        })(),
        DateFilter::Week    => new self($today, $today->next(CarbonImmutable::MONDAY)),   // today → Sun 23:59
    };
}
```
Executed outputs (`now` London → windows, UTC):
```
Fri 23 Oct 15:00 → today [22T23:00Z, 23T23:00Z) | weekend Sat 24 BST → Mon 26 GMT (49h) [23T23:00Z, 26T00:00Z) | week 73h
Sun 25 Oct 12:00 → today 25h [24T23:00Z, 26T00:00Z) | weekend = current (Sat 24 → Mon 26) | week = today only (25h)
Mon 26 Oct 08:00 → weekend Sat 31 Oct → Mon 2 Nov (48h) | week Mon 26 → Mon 2 Nov
Sat 27 Mar 2027  → weekend Sat 27 GMT → Mon 29 BST (47h) [27T00:00Z, 28T23:00Z)
Sun 28 Mar 22:00 → today 23h [28T00:00Z, 28T23:00Z)
```
[VERIFIED: executed with Carbon 3.14.2]. **Do the `addDay()`/`next()` arithmetic on the London-zoned instance, never on UTC.**

```php
// PublicOccurrenceQuery: overlap = starts_at < window_end AND expires_at > max(now, window_start)
EventOccurrence::query()
    ->select('event_occurrences.*')
    ->join('events', 'events.id', '=', 'event_occurrences.event_id')
    ->whereIn('events.status', [EventStatus::Published->value, EventStatus::Cancelled->value])   // cancelled stays visible with badge
    ->whereIn('event_occurrences.status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::Cancelled->value])
    ->where('event_occurrences.expires_at', '>', $window ? $window->start->max($now) : $now)
    ->when($window, fn ($q) => $q->where('event_occurrences.starts_at', '<', $window->end))
    ->when($category, fn ($q) => $q->where('events.category_id', $category->id))
    ->when($free, fn ($q) => $q->where('events.price_type', PriceType::Free->value))
    ->when($family, fn ($q) => $q->where('events.family_friendly', true))
    ->orderBy('event_occurrences.starts_at')->orderBy('event_occurrences.id')
    ->with(['event.venue', 'event.category'])
    ->simplePaginate(30)->withQueryString();       // "More events" = plain ?page=2 link
```
- **Query params (validated, unknown values → ignored or 404):** `when=today|weekend|week`, `category={slug}` (must exist), `free=1`, `family=1`, `page`. Render chips as `<a href>` links that toggle one param while keeping the others. If any filter param is present: `<meta name="robots" content="noindex, follow">` and `<link rel="canonical" href="https://whatsonattleborough.co.uk/">`.
- **Grouping heading:** London date of `max(starts_at, today 00:00)`, so an ongoing multi-day event shows under "Today" rather than yesterday. Headings like "Today", "Tomorrow", "Saturday 31 October". Card time "Sat 14 Nov, 7:30pm" (`D j M, g:ia` with `:00` dropped). All-day: "All day". Multi-day: "until Sun 1 Nov".
- **Edge:** an event at Fri 23:00 with no end has `expires_at` Sat 02:00, so it shows in "This weekend" on Friday evening. That's acceptable and consistent with expiry. Document it in the test.
- **Caching:** none in Phase 1 (the query is sub-millisecond at this volume). Add ARCHITECTURE's versioned cache only if needed later.

### Pattern 6: Event page, JSON-LD, ICS, Google link, share

**Routes:**
```php
Route::get('/', CalendarController::class)->name('calendar');
Route::get('/events/{event}/{slug?}', EventController::class)->whereNumber('event')->name('events.show');
Route::get('/events/{event}/occurrences/{key}/calendar.ics', OccurrenceIcsController::class)
    ->whereNumber('event')->where('key', '\d{4}-\d{2}-\d{2}')->name('events.ics');
```
`EventController`: 404 if `status = hidden` (410 policy is Phase 5). 301 to the canonical slug URL when `{slug}` is missing or stale, which also makes title edits safe without a `slug_history` table. Slug = `Str::slug($title)` stored on save.

**Head:** `<title>{Title} – {Sat 14 Nov} | What's On Attleborough</title>`, meta description = first ~155 chars of the plain-text description, canonical, `og:site_name`, `og:type=website`, `og:title`, `og:description`, `og:url`, `og:image` (default static OG image, absolute URL), `twitter:card=summary_large_image`.

**JSON-LD (one `<script>` per upcoming occurrence, max 10):**
```php
use Spatie\SchemaOrg\{Schema, EventStatusType, EventAttendanceModeEnumeration};

Schema::event()
    ->name($event->title)
    ->startDate($occ->starts_at->setTimezone('Europe/London'))      // DateTimeInterface → ATOM with +01:00/+00:00
    ->endDate($occ->ends_at?->setTimezone('Europe/London'))
    ->eventStatus($occ->isCancelled() ? EventStatusType::EventCancelled : EventStatusType::EventScheduled)
    ->eventAttendanceMode(EventAttendanceModeEnumeration::OfflineEventAttendanceMode)
    ->location(Schema::place()->name($venue->name)->address(
        Schema::postalAddress()->streetAddress($venue->address)->addressLocality($venue->town)
            ->postalCode($venue->postcode)->addressCountry('GB')))
    ->organizer(Schema::organization()->name($event->organiser_name)->url($event->organiserUrl()))
    ->description(Str::limit($event->description, 300))
    ->url(route('events.show', [$event, $event->slug]))
    ->image(asset('images/og-default.png'))
    ->toScript();
// free events: ->isAccessibleForFree(true)->offers(Schema::offer()->price(0)->priceCurrency('GBP')->url(...))
// all-day: pass 'Y-m-d' strings for startDate/endDate (setter is untyped: `public function startDate($startDate)`)
```
Constants verified verbatim in `vendor/spatie/schema-org/src/EventStatusType.php`: `EventCancelled = 'https://schema.org/EventCancelled'`, `EventMovedOnline`, `EventPostponed`, `EventRescheduled`, `EventScheduled = 'https://schema.org/EventScheduled'`. Output observed: `"startDate":"2026-10-16T19:30:00+01:00"`, and description `</script>` became `\u003C/script\u003E` (safe to inline) [VERIFIED: executed]. Don't pass `null` to `endDate`: guard with `when`/`if`.

**Per-occurrence `.ics` (UTC, no VTIMEZONE needed):**
```php
$ics = new VCalendar(['PRODID' => "-//What's On Attleborough//EN", 'METHOD' => 'PUBLISH']);
$ics->add('VEVENT', [
    'UID'         => "{$event->id}-{$occ->recurrence_key}@whatsonattleborough.co.uk",   // stable across regeneration
    'DTSTAMP'     => $occ->updated_at->utc(),
    'DTSTART'     => $occ->starts_at->utc(),          // → DTSTART:20261030T100000Z
    'DTEND'       => ($occ->ends_at ?? $occ->expires_at)->utc(),
    'SUMMARY'     => $event->title,
    'DESCRIPTION' => Str::limit($event->description, 1000)."\n\n".route('events.show', [$event, $event->slug]),
    'LOCATION'    => $venue->singleLine(),
    'URL'         => route('events.show', [$event, $event->slug]),
    'STATUS'      => $occ->isCancelled() ? 'CANCELLED' : 'CONFIRMED',
]);
// all-day: add DTSTART/DTEND with ['VALUE' => 'DATE'] and the exclusive end date
return response($ics->serialize(), 200, [
    'Content-Type' => 'text/calendar; charset=utf-8',
    'Content-Disposition' => 'attachment; filename="'.Str::slug($event->title).'-'.$occ->recurrence_key.'.ics"',
]);
```
Observed serialisation escapes `,` `;` and newlines (`SUMMARY:Coffee\, cake\; & chat`, `DESCRIPTION:Line one\nLine two\, with comma`), and `$ics->validate()` returned `[]` [VERIFIED: executed].

**Google Calendar link:** `https://calendar.google.com/calendar/render?action=TEMPLATE&text={title}&dates={YYYYMMDDTHHMMSSZ}/{YYYYMMDDTHHMMSSZ}&details={text+url}&location={venue}`. For all-day use `dates=YYYYMMDD/YYYYMMDD` (exclusive end). Build it with `http_build_query(..., PHP_QUERY_RFC3986)` [ASSUMED: Google does not formally document this URL; widely used. Verify manually on a phone in UAT].

**Share (progressive enhancement, no third-party scripts):**
- Server-rendered `<a>` fallbacks (work with JS off): WhatsApp `https://wa.me/?text={urlencoded "Title – Sat 14 Nov https://…"}`, Facebook `https://www.facebook.com/sharer/sharer.php?u={url}`, Email `mailto:?subject={title}&body={text+url}`, plus a read-only `<input>` holding the URL [ASSUMED: wa.me / sharer.php URL formats, which are long-standing but not checked against vendor docs this session].
- JS (`resources/js/share.js`, ~30 lines): if `navigator.share` exists, unhide a "Share" `<button>` that calls `navigator.share({title, text, url})` inside the click handler (needs **transient activation**; `AbortError` = user cancelled, so ignore it). A "Copy link" button uses `navigator.clipboard.writeText` (secure context only) with an `aria-live` confirmation [CITED: MDN `Navigator.share()`: secure context, `NotAllowedError` without transient activation or when blocked by the `web-share` Permissions Policy; MDN `Clipboard.writeText()`: secure context].
- If Caddy sends a `Permissions-Policy` header, it **must** include `web-share=(self)`.

### Pattern 7: Filament EventResource

- `php artisan make:filament-resource Event` (List/Create/Edit) + `php artisan make:filament-relation-manager EventResource occurrences recurrence_key`, registered in `getRelations()` [CITED: filament 5.x docs 03-resources/01-overview.md, 07-managing-relationships.md].
- Form sections: **What** (title, description `Textarea`, plain text, category `Select`, family_friendly `Toggle`) · **When** (all_day `Toggle->live()`, start_date `DatePicker`, start_time `TimePicker->seconds(false)` hidden when all-day, end_date (hidden when repeating), end_time, Repeats `Select->live()` with computed options, Ends `Radio` + until `DatePicker` / count `TextInput->integer()->minValue(1)->maxValue(200)`) · **Where** (venue `Select::make('venue_id')->relationship('venue', 'name')->searchable()->preload()->createOptionForm([...])`) · **Price** (price_type `Radio`, price_text) · **Who & source** (organiser_name, organiser_contact, source_url `->url()`, source_note). Validation: venue required; `source_url` required without `organiser_contact` (spec §8.1).
- A read-only "Next dates" preview `Placeholder` (calls `ManualRecurrence` + `EventTiming` for the next 5) helps the admin catch wrong patterns before saving.
- Edit page header actions: Hide / Unhide / Cancel / Reinstate → `ChangeEventStatus`, with `->visible(fn ($record) => $record->status->canTransitionTo(...))` and `->requiresConfirmation()`.
- Occurrences relation manager: read-only form, table columns `starts_at` (`->dateTime('D j M Y, H:i')->timezone('Europe/London')`), status badge (enum `HasLabel`/`HasColor`); record actions Cancel / Hide / Restore → `ChangeOccurrenceStatus`. No create/delete in the relation manager (occurrences are generated).
- Enums implement `Filament\Support\Contracts\HasLabel` (+ `HasColor`) so selects, badges and filters render labels automatically [CITED: filament 5.x docs 09-advanced/03-enums.md].
- Admin user: `App\Models\User implements FilamentUser` with `canAccessPanel(Panel $panel): bool { return $this->email === config('app.admin_email'); }`. Create in prod over SSH: `php artisan make:filament-user --name=… --email=… --password=…` (options exist) [VERIFIED: filament 5.x docs 07-users/01-overview.md; `MakeUserCommand.php` InputOptions `name`, `email`, `password`]. Login is rate-limited (`$this->rateLimit(5)` in `Login.php`) [VERIFIED: source grep]. Registration/password reset stay disabled (panel defaults).

**Filament tests (syntax from 5.x docs):**
```php
livewire(CreateEvent::class)->fillForm([...])->call('create')->assertHasNoFormErrors();
livewire(OccurrencesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
    ->callAction(TestAction::make('cancel')->table($occurrence));
livewire(EditEvent::class, ['record' => $event->id])->callAction('hide');
```
[CITED: filament 5.x docs 10-testing/02-testing-resources.md, 05-testing-actions.md]

### Pattern 8: Ops visibility (heartbeat, failed jobs, logs)

```php
// routes/console.php
Schedule::call(fn () => Heartbeat::beat('scheduler'))->everyMinute()->name('heartbeat:scheduler')
    ->thenPingIf(filled(config('services.heartbeat.scheduler_url')), (string) config('services.heartbeat.scheduler_url'));
Schedule::job(new QueueHeartbeat)->everyFiveMinutes();              // handled by the worker → Heartbeat::beat('queue')
Schedule::command('calendar:sync-occurrences')->dailyAt('03:10')->timezone('Europe/London')->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
```
- `pingBefore/thenPing/pingOnSuccess/pingOnFailure` and the `…If` variants exist in 13.x [CITED: laravel/docs 13.x scheduling.md lines 623-651]. An external free monitor (e.g. a healthchecks-style service) alerts when pings stop, which covers "cron died" when nobody looks at the admin [ASSUMED: provider choice; optional in Phase 1].
- Filament `SystemHealth` page (`make:filament-page`): stats for scheduler and queue last-seen (green < 3 min / < 10 min, else red), `jobs` backlog count, and a table of `failed_jobs` through a read-only `FailedJob` model (`$table = 'failed_jobs'`, `$timestamps = false`) with Retry (`Artisan::call('queue:retry', ['id' => [$uuid]])`) and Delete (`queue:forget`) actions.
- **Logs:** production `.env` `LOG_CHANNEL=stack`, `LOG_STACK=daily`, `LOG_DAILY_DAYS=14`, `LOG_LEVEL=warning`. The skeleton `daily` channel has `'max_files' => env('LOG_DAILY_DAYS', 14)` [VERIFIED: skeleton config/logging.php:68-74]. Caddy access log: `roll_size` (default 100MiB, always on) + `roll_keep` [CITED: caddy docs log directive]. Worker stdout goes to journald: cap with `/etc/systemd/journald.conf.d/size.conf` `SystemMaxUse=200M`. PHP-FPM and PostgreSQL logs use Ubuntu's packaged logrotate configs [ASSUMED].

### Deployment: Hetzner + Caddy + systemd + Deployer 8

**Deployer 8 Laravel recipe (verbatim, v8.0.5 `recipe/laravel.php`):**
```php
set('shared_dirs', ['storage']);
set('shared_files', ['.env']);
set('writable_dirs', ['bootstrap/cache', 'storage']);
task('deploy', ['deploy:prepare', 'deploy:vendors', 'artisan:storage:link', 'artisan:optimize',
                'artisan:migrate', 'deploy:publish', 'artisan:reload']);
```
- `deploy:vendors` uses `composer_options` = `'--verbose --prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader'`, so **Boost and Pest are never installed on the server** [VERIFIED: `recipe/deploy/vendors.php:6`].
- `artisan:optimize` also runs `filament:optimize`, because Filament registers `$this->optimizes(optimize: 'filament:optimize', …)` [VERIFIED: filament `SupportServiceProvider.php:484-488`].
- `artisan:reload` (Laravel ≥12.41) runs `'queue' => 'queue:restart', 'schedule' => 'schedule:interrupt'` [VERIFIED: framework v13.37.0 `ReloadCommand.php`]. `queue:restart` signals through the **cache**, so `CACHE_STORE=database` must be shared by web and worker (it is).
- `artisan:migrate` runs **before** the symlink swap, while the old release is still serving. Migrations must be backward-compatible (expand → deploy → contract).

```php
<?php // deploy.php
namespace Deployer;
require 'recipe/laravel.php';

set('application', 'attleborough-events');
set('repository', 'https://github.com/yurabyrlya/attleborough-events.git');
set('update_code_strategy', 'local_archive');   // git archive of the CI checkout → upload; server needs no GitHub access
set('keep_releases', 5);

host('production')
    ->set('hostname', getenv('DEPLOY_HOST') ?: 'whatsonattleborough.co.uk')
    ->set('remote_user', 'deployer')
    ->set('deploy_path', '/var/www/attleborough-events');

task('deploy:assets', fn () => upload('public/build/', '{{release_path}}/public/build/'));  // built in CI
after('deploy:update_code', 'deploy:assets');
after('deploy:failed', 'deploy:unlock');
```
`local_archive` runs `git archive` locally, uploads, then untars into `{{release_path}}` [VERIFIED: `recipe/deploy/update_code.php:82-90`]. `upload()` is a Deployer function (`src/functions.php:740`) that uses rsync, so install `rsync` on the server [ASSUMED: rsync dependency].

**Caddyfile (`/etc/caddy/Caddyfile`):**
```caddy
whatsonattleborough.co.uk {
	root * /var/www/attleborough-events/current/public
	encode zstd gzip
	header {
		Strict-Transport-Security "max-age=31536000"
		X-Content-Type-Options "nosniff"
		Referrer-Policy "strict-origin-when-cross-origin"
		Permissions-Policy "camera=(), microphone=(), geolocation=(), web-share=(self)"
		-Server
	}
	@build path /build/*
	header @build Cache-Control "public, max-age=31536000, immutable"
	php_fastcgi unix//run/php/php8.5-fpm.sock {
		resolve_root_symlink
	}
	file_server
	log {
		output file /var/log/caddy/attleborough-access.log {
			roll_size 50MiB
			roll_keep 10
		}
	}
}

www.whatsonattleborough.co.uk {
	redir https://whatsonattleborough.co.uk{uri} permanent
}
```
- `resolve_root_symlink` runs `filepath.EvalSymlinks(root)`, so `SCRIPT_FILENAME` points at `releases/N/…` and opcache picks up new code without reloading PHP-FPM [VERIFIED: caddy `modules/caddyhttp/reverseproxy/fastcgi/fastcgi.go:289-294`; deployer `docs/avoid-php-fpm-reloading.md` ("Do **not** reload php-fpm… Use `resolve_root_symlink`")]. The socket path follows Deployer's own provision Caddyfile (`unix//run/php/php{{php_version}}-fpm.sock`) [CITED].
- Smoke check after the first deploy: a temporary route printing `$_SERVER['SCRIPT_FILENAME']` must show `/releases/`, not `/current/`. Remove it afterwards.
- **Cloudflare proxy (D-03, planner's call): recommend DNS-only (grey cloud) in Phase 1.** Caddy's HTTP-01/TLS-ALPN issuance works directly, `request()->ip()` is the real client (Phase 2 rate limiting), and no trusted-proxy config is needed. Switch to proxied later with SSL mode "Full (strict)" plus `trustProxies` limited to Cloudflare ranges [ASSUMED: tradeoff reasoning].

**systemd worker (`/etc/systemd/system/attleborough-queue.service`):**
```ini
[Unit]
Description=attleborough-events queue worker
After=network.target postgresql.service

[Service]
User=deployer
Group=www-data
WorkingDirectory=/var/www/attleborough-events/current
ExecStart=/usr/bin/php artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=60 --max-time=3600 --memory=192
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=90

[Install]
WantedBy=multi-user.target
```
`--timeout` 60 < `retry_after` 90 (skeleton default `'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90)`) [VERIFIED: skeleton config/queue.php:38-45; CITED: laravel docs 13.x queues.md "`--timeout` value should always be at least several seconds shorter than your `retry_after`"]. After `queue:restart` or `--max-time`, the worker exits and systemd restarts it, re-resolving the `current` symlink. The `deployer` user needs no sudo for deploys.

**cron (`/etc/cron.d/attleborough-events`):**
```
* * * * * deployer cd /var/www/attleborough-events/current && php artisan schedule:run >> /dev/null 2>&1
```

**`ops/provision.sh` (idempotent, run once as root):** apt update/upgrade; `php8.5-{fpm,cli,pgsql,intl,mbstring,xml,curl,zip,bcmath}`, `postgresql` (18), `caddy` (official Caddy apt repo or the Ubuntu package), `git`, `unzip`, `rsync`, `ufw`, `unattended-upgrades`, `fail2ban` (optional); composer; user `deployer` (SSH key only, in group `www-data`); `PasswordAuthentication no`; ufw allow 22/80/443; PHP-FPM pool `pm = ondemand`, `pm.max_children = 8`; Postgres:
```sql
CREATE ROLE attleborough LOGIN PASSWORD '…';
CREATE DATABASE attleborough OWNER attleborough;     -- OWNER: PG15+ public-schema CREATE is not granted to PUBLIC
```
Then the systemd unit, cron file, Caddyfile, journald cap, 2 GB swapfile, and `/var/www/attleborough-events/shared/.env` (written by hand at a human checkpoint) [ASSUMED: Ubuntu 26.04 package names follow the `php8.5-*` scheme and the Hetzner image is available. STACK.md verified `php 2:8.5+99ubuntu1` and `postgresql 18+290ubuntu1` exist for resolute. Package-page lookups for individual extensions timed out this session: no observation].

### GitHub Actions CI + deploy

```yaml
# .github/workflows/ci.yml (sketch; pin action majors as listed)
name: CI
on:
  push: { branches: [main] }
  pull_request:
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:18
        env: { POSTGRES_USER: postgres, POSTGRES_PASSWORD: postgres, POSTGRES_DB: testing }
        ports: ['5432:5432']
        options: >-
          --health-cmd "pg_isready -U postgres" --health-interval 5s --health-timeout 5s --health-retries 10
    env: { TZ: America/New_York }            # catches accidental reliance on system timezone (D6)
    steps:
      - uses: actions/checkout@v7
      - uses: shivammathur/setup-php@v2       # 2.40.0
        with: { php-version: '8.5', extensions: 'pgsql, pdo_pgsql, intl, mbstring', coverage: none }
      - uses: actions/setup-node@v7
        with: { node-version: '24', cache: npm }
      - run: composer install --no-interaction --prefer-dist --no-progress
      - run: npm ci && npm run build
      - run: cp .env.example .env && php artisan key:generate
      - run: vendor/bin/pint --test
      - run: vendor/bin/phpstan analyse --no-progress --memory-limit=1G
      - run: vendor/bin/pest --ci
      - uses: actions/upload-artifact@v7
        with: { name: build, path: public/build }
  deploy:
    needs: test
    if: github.event_name == 'push' && github.ref == 'refs/heads/main'
    runs-on: ubuntu-latest
    environment: production
    concurrency: { group: deploy-production, cancel-in-progress: false }
    steps:
      - uses: actions/checkout@v7
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.5' }
      - run: composer install --no-interaction --prefer-dist --no-progress   # provides vendor/bin/dep
      - uses: actions/download-artifact@v8
        with: { name: build, path: public/build }
      - uses: deployphp/action@v1             # v1.0.26
        with:
          dep: deploy production
          deployer-binary: vendor/bin/dep
          private-key: ${{ secrets.DEPLOY_SSH_KEY }}
          known-hosts: ${{ secrets.DEPLOY_KNOWN_HOSTS }}
      - run: curl -fsS https://whatsonattleborough.co.uk/up    # post-deploy smoke (skeleton health route)
```
- Latest releases checked: actions/checkout v7.0.1, shivammathur/setup-php 2.40.0, actions/setup-node v7.1.0, upload-artifact v7.0.2, download-artifact v8.0.2, deployphp/action v1.0.26 (inputs `dep`, `private-key`, `known-hosts`, `deployer-binary` verified in its `action.yaml`) [VERIFIED: GitHub releases API].
- `phpunit.xml`: replace `DB_CONNECTION=sqlite`/`DB_DATABASE=:memory:` with `pgsql`, `127.0.0.1`, `testing`, `postgres`/`postgres` (locally the `attleborough_testing` DB). Keep `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`. `tests/Pest.php`: `uses(RefreshDatabase::class)->in('Feature')`, `beforeEach(fn () => Http::preventStrayRequests())`, and `$this->withoutVite()` for feature tests (no manifest needed locally) [VERIFIED: skeleton phpunit.xml contents].
- `config/database.php` pgsql: add `'timezone' => 'UTC'` (see Pitfall 2).
- `phpstan.neon`: `includes: [vendor/larastan/larastan/extension.neon, vendor/nesbot/carbon/extension.neon]`, `paths: [app/]`, `level: 6` [CITED: larastan README (example uses level 5)].
- Secrets: `DEPLOY_SSH_KEY` (private key for `deployer`), `DEPLOY_KNOWN_HOSTS` (`ssh-keyscan` output), optional `DEPLOY_HOST`. Production `.env` lives only on the server (`shared/.env`), never in GitHub.

### Laravel Boost (dev-only)

Facts read from laravel/boost **v2.10.3** source (2026-10-07; requires `illuminate/* ^11.45.3|^12.41.1|^13.0`, `laravel/mcp`, `laravel/roster`):
- **Copilot agent writes to:** guidelines → `config('boost.agents.copilot.guidelines_path', 'AGENTS.md')`; skills → `'.github/skills'`; MCP → `'.vscode/mcp.json'` under key `servers`. Project detection: `.vscode` dir or `.github/copilot-instructions.md` [VERIFIED: `src/Install/Agents/Copilot.php`]. **It writes to `AGENTS.md`, not `.github/copilot-instructions.md`.**
- **Guideline writing is non-destructive:** it replaces only `/<laravel-boost-guidelines>.*?<\/laravel-boost-guidelines>/s`. Otherwise it appends to the existing file after `"\n\n===\n\n"` [VERIFIED: `src/Install/GuidelineWriter.php`]. The repo's GSD block in `AGENTS.md` survives.
- **Custom guidelines:** `.ai/guidelines/*` (`protected string $userGuidelineDir = '.ai/guidelines'`), composed into the block on `boost:install` / `boost:update` [VERIFIED: `src/Install/GuidelineComposer.php:23`]. Project rules: `.ai/rules/` (`'rules' => ['enabled' => env('BOOST_RULES_ENABLED', true), ...]`) [VERIFIED: `config/boost.php`].
- **Runtime gating:** the service provider returns early when `! config('boost.enabled', true)`, when `app()->runningUnitTests()`, or when not `local` and `app.debug !== true`. It registers `POST /_boost/browser-logs` only when active [VERIFIED: `src/BoostServiceProvider.php:106, 201-210`]. Production has it neither installed (`--no-dev`) nor enabled (`APP_DEBUG=false`).
- **Shipped guideline sets (v2.10.3):** `laravel` (core + 11/12 dirs + best-practice skills), `livewire/4`, `tailwindcss/4`, `pest` (core only), `pint`, `php/8.4`, `php/8.5`. **No Filament set.** [VERIFIED: repo tree].
- MCP command is `php artisan boost:mcp` with **relative** `artisan` path unless an absolute path is forced (`getArtisanPath()`) [VERIFIED: `src/Install/Agents/Agent.php:44-46`, `McpWriter.php:45-46`].

**Placement in scaffold order:** last (step 8 above), after Filament, Pest and Tailwind are installed, so its package detection picks the right guideline sets. Re-run `php artisan boost:update` after adding packages or editing `.ai/guidelines/*`. Optional: add `"@php artisan boost:update --ansi"` to `post-update-cmd` (it only runs on `composer update`, never in CI or deploy).

**Commit vs ignore:**
| Path | Action | Why |
|---|---|---|
| `boost.json` | **commit** | Records agents/skills/mcp/packages selection so `boost:install`/`boost:update` are reproducible |
| `AGENTS.md` (GSD block + Boost block) | **commit** | Agents in CI/cloud need the guidance; the GSD block must stay intact |
| `.ai/guidelines/*.md` | **commit** | Project rules (source of truth for the generated block) |
| `.github/skills/<boost skill dirs>` (e.g. `livewire-development`, `tailwindcss-development`, `laravel-best-practices`) | **commit** | No name clash with `gsd-*`. Note: GSD's installer manifest doesn't know them, so re-run `boost:update` after a GSD update if anything disappears [ASSUMED] |
| `.vscode/mcp.json` | **leave ignored** (repo `.gitignore` already has `/.vscode/`) | Per-machine; regenerated by `boost:install` |
| skeleton `AGENTS.md` / `CLAUDE.md` (Boost bootstrap prompt) | **do not copy** | Would overwrite the GSD `AGENTS.md` |

**`.ai/rules`:** set `BOOST_RULES_ENABLED=false` in local `.env`. GSD already keeps decisions in `.planning/` (PROJECT/STATE/CONTEXT), and a second agent-written decision log would drift [ASSUMED: recommendation].

**Recommended custom guidelines (`.ai/guidelines/attleborough.md`):**
- Time: app timezone and DB session timezone are UTC. All instants are `timestampTz` stored UTC (`->utc()` before persisting or upserting). Display and window maths happen in `Europe/London` via `App\Calendar\DateWindow` / `LondonFormatter`. Do date arithmetic on London-zoned Carbon. Never call `FilamentTimezone::set()`. Use `->timezone('Europe/London')` on Filament display columns.
- Recurrence: `sabre/vobject` `EventIterator` is the only expansion engine. Always pass `new DateTimeZone('Europe/London')`. `recurrence_key` = London `Y-m-d`. Occurrence upserts never update `status`.
- Writes: only `App\Publishing\Actions\*` create, update or change status on `events`/`event_occurrences`. Filament pages call actions (`handleRecordCreation`/`handleRecordUpdate`/action closures); there are no `Event::create` calls in Filament or controllers.
- Ingestion (Phase 3+): no `strtotime`, `Carbon::parse`, `new DateTime($text)` or `date_create` in `App\Ingestion` (enforced by a Pest `arch()` test). Reject, don't guess.
- Tests: Pest 5 on **PostgreSQL** (never SQLite). `Http::preventStrayRequests()` globally. Freeze time with `travelTo()` in London for any window/expiry test. Include the 25 Oct 2026 and 28 Mar 2027 fixtures. Boost's Pest guidance targets Pest 3/4, and Pest 5 conventions win.
- Filament 5 (not covered by Boost): schemas use `Filament\Schemas\Schema` + `->components([...])`; actions are `Filament\Actions\*`; tests use `Filament\Actions\Testing\TestAction::make('name')->table($record)`; enums implement `HasLabel`/`HasColor`; resources live in `app/Filament/Resources/{Plural}/`.
- Public site: Blade + Tailwind 4; no Livewire on public pages; no third-party scripts, fonts or images; filters are plain links; WCAG 2.2 AA (24×24px targets, visible focus, `<time datetime>`).
- No LLM SDKs or AI runtime packages in `require` (Boost is `require-dev` only). No Redis.

**Conflict check with the existing `.github/copilot-instructions.md` GSD block:** none. Boost doesn't write that file by default. Its `AGENTS.md` block is appended after the GSD block. The GSD block's "do not apply GSD workflows unless asked" doesn't contradict Boost guidance. One caveat: `.planning/config.json` sets `claude_md_path: ./.github/copilot-instructions.md`. GSD reads project constraints from there, not from `AGENTS.md`, so project-critical rules must live in `.ai/guidelines` (for agents) **and** stay reflected in CONTEXT/PROJECT docs (for GSD).

### Walking skeleton (thinnest end-to-end slice) and human checkpoints

1. **Skeleton slice:** scaffold (steps 1-6) → migrations (`categories`, `venues`, `events`, `event_occurrences`, `heartbeats`) + category seeder → `PublishEvent` + `OccurrenceGenerator` (single, non-recurring path only) → Filament `EventResource` create → `/` Blade list renders the occurrence → CI green on PostgreSQL → deployed at `https://whatsonattleborough.co.uk` → admin creates a real event in prod and sees it on a phone.
2. Then: recurrence (ManualRecurrence + sabre + DST fixtures + daily sync) → filters + date windows → event page + meta/OG + JSON-LD → ICS/Google/share → status actions + occurrences relation manager → System health page + heartbeat + log config → Boost guidelines → design polish (D-05..D-08).

**`checkpoint:human` placement:**
| When | Action | Blocks |
|---|---|---|
| Day 1, before any deploy work | Register `whatsonattleborough.co.uk`; add zone to Cloudflare; switch nameservers (propagation can take hours) | DNS, TLS |
| Day 1 | Create Hetzner Cloud project + CX23 (Ubuntu 26.04), add SSH key; give the IPv4/IPv6 to the executor | Provisioning |
| After provisioning | Create A/AAAA records (apex + www, DNS-only); confirm `dig` resolves | Caddy cert issuance |
| After provisioning | Write `/var/www/attleborough-events/shared/.env` (APP_KEY, DB password, `APP_URL`, `APP_ENV=production`, `APP_DEBUG=false`, mail/log settings, `ADMIN_EMAIL`) | First deploy |
| Before first deploy | Add GitHub secrets `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`; create `production` environment | Deploy job |
| After first deploy | SSH: `php artisan make:filament-user --name=… --email=… --password=…` (owner types the password) | ADM-01 in prod |
| Any time | Cloudflare Email Routing: `hello@` → owner inbox; verify destination | D-04 |
| End of phase | Phone UAT: browse, filters with JS off, add to calendar (iOS + Android), share sheet, Google link | SC1, SC4 |

### Anti-Patterns to Avoid
- **Livewire for the public list:** breaks the no-JS requirement for interaction and adds hydration for nothing. Use plain GET links.
- **`FilamentTimezone::set('Europe/London')` with `TimePicker`s on wall-clock columns:** silently shifts times by an hour in summer (Pattern 1).
- **Assigning London Carbon instances to Eloquent datetime attributes:** Laravel formats them as `Y-m-d H:i:s` *in their own zone* with no offset, so they're stored an hour off in BST (Pitfall 2).
- **Datetime `recurrence_key`:** changing a series' start time orphans every admin cancellation.
- **Deleting and re-inserting occurrences:** loses admin status and changes ids (ARCHITECTURE Anti-Pattern 5).
- **`dep provision`:** installs redis and the ondrej PPA, and its Postgres grants are insufficient on PG15+.
- **Reloading PHP-FPM on deploy to clear opcache:** drops requests. Use `resolve_root_symlink`.
- **Hand-writing JSON-LD with `json_encode` in Blade:** easy to forget `JSON_HEX_TAG`. spatie escapes `</script>`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| RRULE expansion | Own weekly/monthly loop | sabre/vobject `EventIterator` | DST gaps, UNTIL/COUNT semantics, and Phase 3 ICS uses the same engine |
| ICS text output | String concatenation | sabre `VCalendar::serialize()` | Escaping (`\,` `\;` `\n`), line folding, `VALUE=DATE` |
| JSON-LD | Arrays + `json_encode` | spatie/schema-org `toScript()` | Typed setters, enum constants, safe escaping |
| Zero-downtime releases | rsync + symlink bash | Deployer 8 `recipe/laravel.php` | Locking, shared dirs, rollback, cleanup |
| TLS certs | certbot + cron | Caddy automatic HTTPS | Renewal built in |
| Admin auth / rate-limited login | Custom guard | Filament panel login + `FilamentUser` | Rate limiting, CSRF, session handling included |
| Log rotation | logrotate for Laravel logs | `daily` channel `max_files` | Built in |
| Failed-job retry | Custom tables | `failed_jobs` + `queue:retry`/`queue:forget` | Built in |
| Health endpoint | Custom route | skeleton `health: '/up'` | Already wired in `bootstrap/app.php` [VERIFIED] |

**Key insight:** the custom code in this phase should be small and pure: `ManualRecurrence`, `EventTiming`, `DateWindow`, `OccurrenceGenerator`, `PublicOccurrenceQuery`. Each is unit-testable with frozen time. Everything around them is configuration.

## Common Pitfalls

### Pitfall 1: Skeleton defaults that violate project rules
**What goes wrong:** SQLite tests; PHPUnit 12 blocks Pest 5; the skeleton `AGENTS.md`/`CLAUDE.md` replaces the GSD file; the bunny.net font fetch makes CI depend on a CDN; `post-create-project-cmd` creates `database/database.sqlite`.
**How to avoid:** scaffold in a temp dir and copy selectively (scaffold order). Edit `phpunit.xml` and `.env.example` to pgsql. Delete `database/database.sqlite`.
**Warning signs:** `pest` install conflict on `phpunit/phpunit`; tests pass locally but `pg_*` functions fail in CI; GSD commands stop working.

### Pitfall 2: Timestamps written in the wrong zone
**What goes wrong:** Laravel's base grammar `getDateFormat()` returns `'Y-m-d H:i:s'` (Postgres grammar doesn't override it). `fromDateTime()` does `$this->asDateTime($value)->format($this->getDateFormat())` without converting zones. The stock pgsql config has no `timezone` key, so `configureTimezone()` never runs `set time zone`. A London Carbon or a non-UTC server session therefore stores BST times an hour off.
**How to avoid:** add `'timezone' => 'UTC'` to the pgsql connection; always `->utc()` before assigning or upserting; keep `config/app.php` `'timezone' => 'UTC'` (skeleton default). Add a test that reads the raw column with `DB::selectOne("select starts_at at time zone 'UTC' ...")` for a BST occurrence.
**Warning signs:** occurrences show 1h late in summer only.
[VERIFIED: framework v13.37.0 `Grammar.php:282-285`, `HasAttributes.php:1639-1644`, `PostgresConnector.php:180-187`; skeleton `config/database.php` pgsql block has no timezone; `config/app.php:68 'timezone' => 'UTC'`]

### Pitfall 3: Filament TimePicker timezone conversion
See Pattern 1. **Warning sign:** saving 19:30 shows 18:30 after reload in summer.

### Pitfall 4: Opcache serving the previous release
**What goes wrong:** after the symlink swap, PHP-FPM keeps executing cached `current/...` paths.
**How to avoid:** `resolve_root_symlink` in Caddy; smoke-check `SCRIPT_FILENAME` once.
**Warning signs:** a deploy "succeeds" but the page is unchanged until FPM restarts.

### Pitfall 5: DST edges in windows and recurrences
**What goes wrong:** windows computed in UTC; `addHours(24)` used for "tomorrow"; expanding without a timezone (sabre defaults to UTC).
**How to avoid:** Pattern 2/5 code; fixtures pinned to Sat 24 Oct 23:30, Sun 25 Oct 12:00, Mon 26 Oct 08:00 (2026) and Sat 27 / Sun 28 Mar 2027; an all-day event on Sun 25 Oct (25 h); a 01:30 weekly event across 28 Mar 2027 (expect 02:30 BST); CI `TZ=America/New_York`.

### Pitfall 6: Cancelled events disappearing instead of showing a badge
**What goes wrong:** ARCHITECTURE Pattern 11's query filters `e.status = 'published'`, which hides event-level `cancelled`. Spec §8.1 says "Cancelled events show a badge until they expire."
**How to avoid:** `events.status IN ('published','cancelled')` and `occurrences.status IN ('scheduled','cancelled')`. The badge shows if either is cancelled.

### Pitfall 7: Orphaned or duplicated occurrences on pattern edits
**What goes wrong:** editing Friday → Saturday leaves old Friday rows, or a crash mid-sync leaves half a series.
**How to avoid:** upsert + delete-unproduced in **one transaction**, future rows only (Pattern 3). Test with an edited pattern and an admin-cancelled row.

### Pitfall 8: Queue/scheduler silently dead
**What goes wrong:** cron missing or worker crash-looping, and nothing notices.
**How to avoid:** heartbeats (Pattern 8), a System health page, optional external ping; `Restart=always`; `queue:restart` via `artisan:reload` on deploy; `--timeout` < `retry_after`.

### Pitfall 9: Postgres permissions on PG15+
**What goes wrong:** `GRANT ALL PRIVILEGES ON DATABASE` (what Deployer's provision recipe does) doesn't grant `CREATE` on schema `public` in PG15+, so `php artisan migrate` fails with "permission denied for schema public".
**How to avoid:** `CREATE DATABASE attleborough OWNER attleborough`. The Phase 1 `pg_trgm` migration proves the privileges early.
[VERIFIED: deployer `recipe/provision/databases.php:64-66` grant statements; ASSUMED: PG15 public-schema change from training knowledge (the release-notes fetch returned no match)]

### Pitfall 10: Local PHP 8.4 vs prod 8.5 lock drift
**What goes wrong:** `platform.php 8.5.11` lets Composer lock an 8.5-only release, and the local `platform_check.php` then fatals.
**How to avoid:** `platform.php` = the floor (8.4.12); CI runs 8.5.

### Pitfall 11: Share/copy silently failing
**What goes wrong:** `navigator.share` called outside a click (no transient activation), blocked by a strict `Permissions-Policy`, or used over HTTP locally.
**How to avoid:** call it in the click handler; `web-share=(self)`; fallback links always rendered.

## Code Examples

The main verified examples are inline above: synthetic VEVENT + `EventIterator` (Pattern 2), upsert sync (Pattern 3), `PublishEvent` + Filament hooks (Pattern 4), `DateWindow` + query (Pattern 5), schema-org / ICS / share (Pattern 6), Filament tests (Pattern 7), scheduler entries (Pattern 8), `deploy.php`, Caddyfile, systemd unit and CI workflow (Deployment). Two more:

```php
// Pest DST fixture style (tests/Unit/Calendar/DateWindowTest.php)
it('spans 49 hours on the October DST weekend', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 15:00', 'Europe/London'));
    $w = DateWindow::for(DateFilter::Weekend, CarbonImmutable::now());
    expect($w->start->utc()->toIso8601ZuluString())->toBe('2026-10-23T23:00:00Z')
        ->and($w->end->utc()->toIso8601ZuluString())->toBe('2026-10-26T00:00:00Z');
});

it('keeps a weekly 10:00 event at 10:00 local across 25 Oct 2026', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00', 'Europe/London'));
    $event = publishManual(start: '2026-10-02', time: '10:00', repeats: RepeatPattern::Weekly);
    expect($event->occurrences()->where('recurrence_key', '2026-10-23')->value('starts_at'))->toEqual(CarbonImmutable::parse('2026-10-23 09:00', 'UTC'))
        ->and($event->occurrences()->where('recurrence_key', '2026-10-30')->value('starts_at'))->toEqual(CarbonImmutable::parse('2026-10-30 10:00', 'UTC'));
});
```
(`publishManual` is a proposed test helper that calls `PublishEvent`. `Carbon::parse` is fine in tests and calendar code, and banned only in `App\Ingestion`.)

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Skeleton PHPUnit-only, manual Pest install | Skeleton still PHPUnit (12.5); Pest 5 needs PHPUnit 13 | Pest 5 (2026) | Remove PHPUnit before requiring Pest (`-W`) |
| Filament 3 `Form $form` / `Tables\Actions\*` | Filament 5 `Schema $schema` + `->components()`, unified `Filament\Actions\*`, `TestAction` | v4/v5 | Older tutorials won't compile |
| Google/Bunny font CDN links | laravel-vite-plugin 3.x `fonts:` providers (download at build) / fontsource npm | lvp 3.x | Self-host by default; prefer npm for offline CI |
| `PGDATA=/var/lib/postgresql/data` | PG18 image: `PGDATA=/var/lib/postgresql/18/docker`, `VOLUME /var/lib/postgresql` | postgres:18 image | Mount the volume at `/var/lib/postgresql` [VERIFIED: docker-library/postgres 18/trixie/Dockerfile:189-192] |
| `queue:restart` in deploy scripts | `php artisan reload` (queue:restart + schedule:interrupt + package hooks) | Laravel 12.41 | Deployer's `artisan:reload` uses it |
| Reload PHP-FPM after symlink deploy | `resolve_root_symlink` (Caddy) / `$realpath_root` (nginx) | — | No dropped requests |

**Deprecated/outdated:** STACK.md's "decline Laravel Boost" (overridden by the owner); STACK.md's `composer config platform.php 8.5.11` (use the 8.4 floor, see Pitfall 10); ARCHITECTURE's Livewire public calendar (use Blade for CAL-06).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Google Calendar `render?action=TEMPLATE&dates=…Z/…Z` link format works on iOS/Android | Pattern 6 | Broken "Google Calendar" button; caught in phone UAT |
| A2 | `wa.me/?text=` and `facebook.com/sharer/sharer.php?u=` share URL formats | Pattern 6 | Broken fallback links; caught in UAT |
| A3 | PG15+ removed PUBLIC CREATE on schema `public` (so DB `OWNER` is needed) | Pitfall 9 | Low: `OWNER` is correct either way |
| A4 | Ubuntu 26.04 package names `php8.5-*`, `caddy`, `rsync`, logrotate configs for FPM/PG; Hetzner offers a 26.04 image | Deployment | Provision script edits; fallback Ubuntu 24.04 + ondrej PPA (would conflict with "no PPA" preference) |
| A5 | Deployer `upload()` needs rsync on both ends | Deployment | First deploy fails until rsync is installed |
| A6 | Cloudflare DNS-only is the better Phase 1 choice | Deployment | Low; reversible in minutes |
| A7 | No locked package will require PHP ^8.5 soon (platform 8.4.12) | Scaffold | Composer would refuse such versions, which is intended |
| A8 | External heartbeat provider (healthchecks-style) free tier | Pattern 8 | Optional; the admin page still works |
| A9 | Boost skill dirs in `.github/skills` coexist with GSD updates | Boost | Re-run `boost:update` |
| A10 | Disabling Boost `.ai/rules` is preferable to a second decision log | Boost | Low; owner preference |
| A11 | Atkinson Hyperlegible Next as the font (discretion item) | Stack | Cosmetic |

## Open Questions

1. **Ubuntu 26.04 on Hetzner Cloud and exact package availability**
   - What we know: STACK verified `php 8.5` and `postgresql 18` packages for resolute.
   - What's unclear: whether a Hetzner image exists today (packages.ubuntu.com lookups timed out this session: no observation).
   - Recommendation: the human checkpoint creates the server; the provision script runs `apt-cache policy php8.5-fpm postgresql-18 caddy` first and stops if anything is missing.
2. **Recurring events with multi-day spans** (e.g. a monthly two-day market)
   - Recommendation: out of scope in Phase 1 (form validation blocks it). Revisit if the owner needs it.
3. **How many upcoming dates to show on a series' event page**
   - Recommendation: next 10 (matches the JSON-LD cap), each with its own "Add to calendar" link, and "More dates" text if the series continues beyond.
4. **Admin MFA**
   - Filament 5 ships app/email MFA. Recommendation: enable app (TOTP) MFA for the single admin if it's a ≤1-task addition; otherwise defer to Phase 5 hardening.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP (local) | dev, tests | ✓ | 8.4.12 (intl, pgsql, pdo_pgsql, mbstring, bcmath, curl, dom, xml, zip, gd, sodium) | Prod/CI run 8.5 |
| Composer | install | ✓ | 2.10.2 | — |
| Node / npm | Vite build | ✓ | 24.21.0 / 11.19.0 | — |
| Docker | local PostgreSQL 18 | ✓ | 29.3.1 (daemon running) | Herd/DBngin |
| psql / pg_isready | DB admin | ✗ | — | `docker exec ae-pg psql` |
| Deployer `dep` | deploy | ✗ (global) | — | `vendor/bin/dep` (dev dependency) |
| Caddy | prod only | n/a locally | 2.11.7 latest | `php artisan serve` locally |
| gh CLI | repo/secrets | ✓ | 2.83.1 | GitHub web UI |
| Hetzner VPS, domain, Cloudflare zone | OPS-02 | ✗ (owner actions) | — | none: human checkpoints |

**Missing dependencies with no fallback:** domain registration, Cloudflare zone, Hetzner server, GitHub deploy secrets (all owner actions; they block the deploy plans only, not local development).

**Missing dependencies with fallback:** psql (docker exec), dep (vendor/bin).

## Success Criteria Verification Map

(`workflow.nyquist_validation` is `false`, so this is a concise map rather than a full Validation Architecture.)

| SC | Behaviour | Automated | Manual |
|----|-----------|-----------|--------|
| 1 | List grouped by date; filters via links; noindex on filters | Pest feature: GET `/`, `/?when=weekend&free=1` asserts headings, items, `noindex` meta, links contain query strings; `DateWindow` unit tests across DST (Pattern 5 cases) | Phone at prod URL with JS disabled |
| 2 | Weekly / nth-weekday series over 90 days; multi-day overlap; expiry rules; DST | Unit: `ManualRecurrence::toRrule`, `EventTiming` table cases, `OccurrenceGenerator` with travelTo on 16 Oct 2026 + 21 Mar 2027 fixtures; Filament `CreateEvent` test produces N occurrences | Create a series in prod and inspect the list |
| 3 | Hide/unhide/cancel event + single occurrence; status survives regeneration | Filament action tests (`callAction('hide')`, `TestAction::make('cancel')->table($occ)`); `artisan calendar:sync-occurrences` then assert status unchanged; badge text "Cancelled" in HTML | — |
| 4 | Event page SEO, JSON-LD, ICS, Google link, share | Feature: slug 301; `<link rel="canonical">`, `og:*`; JSON-LD count ≤ 10 and `eventStatus`; `.ics` response content-type + `UID:{id}-{key}@…` stable across a re-sync; share hrefs present | iOS + Android: add to calendar, share sheet, Google link |
| 5 | CI on PG, zero-downtime deploy, heartbeat, failed jobs, log rotation | CI itself (pint/phpstan/pest on postgres:18); `/up` curl in the deploy job; Pest: heartbeat closure writes row; SystemHealth page renders failed job | `SCRIPT_FILENAME` shows `/releases/`; deploy during a curl loop shows no 5xx; `ls storage/logs` shows dated files |

## Security Domain

`security_enforcement: true`, ASVS level 1.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Filament login (rate-limited, 5), bcrypt; single admin created by CLI; no registration/reset; optional TOTP MFA |
| V3 Session Management | yes | Database sessions; `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=false` OK, HttpOnly default; HTTPS only |
| V4 Access Control | yes | `FilamentUser::canAccessPanel()` checks `ADMIN_EMAIL`; public routes read-only; hidden events 404 |
| V5 Input Validation | yes | Filament form rules; public query params validated against an enum/whitelist (category slug must exist); Blade `{{ }}` escaping; description stored and rendered as plain text (`nl2br(e(...))`) |
| V6 Cryptography | minimal | `APP_KEY` only; never hand-roll |
| V7 Errors & Logging | yes | `APP_DEBUG=false` in prod; daily logs, 14 days; no secrets in logs |
| V9 Communications | yes | Caddy HTTPS + HSTS (no preload yet); www→apex 301 |
| V14 Configuration | yes | `.env` only on server (`shared/.env`, 0640); GitHub secrets for SSH key; ufw 22/80/443; SSH key-only; unattended-upgrades; Boost dev-only (`--no-dev`) |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Stored XSS via event title/description | Tampering | Blade escaping; plain-text description; JSON-LD via spatie (`</script>` escaped, verified) |
| ICS injection (CRLF/property smuggling) | Tampering | sabre serializer escapes `,` `;` and newlines (verified) |
| Header injection via download filename | Tampering | `Str::slug()` filename + validated `Y-m-d` key |
| Admin credential brute force | Spoofing | Filament login rate limit; strong password; optional MFA; fail2ban for SSH |
| Debug/Boost endpoints exposed in prod | Information disclosure | `--no-dev`; `APP_DEBUG=false` (Boost also self-disables) |
| Leaked deploy key | Elevation | Dedicated `deployer` user without sudo; key only in a GitHub environment secret |
| Crawl traps via filter combinations | DoS/SEO | `noindex, follow` + canonical on filtered URLs; params whitelisted |

## Sources

### Primary (read or executed this session; seam tier LOW because the provider is `webfetch`/local execution)
- **Executed:** sabre/vobject 5.0.0, spatie/schema-org 5.0.2, rlanvin/php-rrule 3.0.0, nesbot/carbon 3.14.2 installed in `/tmp/vo` on PHP 8.4.12. Probes: RRULE expansion across DST, all-day 25h day, ICS serialisation/validation, JSON-LD output, DateWindow outputs.
- laravel/laravel v13.11.0 (GitHub API): composer.json, package.json, phpunit.xml, .env.example, vite.config.js, AGENTS.md, .gitattributes, bootstrap/app.php, config/{app,database,logging,queue}.php, jobs migration.
- laravel/framework v13.37.0 source: `Grammar.php`, `PostgresConnector.php`, `HasAttributes.php`, `ReloadCommand.php`.
- laravel/docs 13.x: scheduling.md (ping methods), queues.md (retry_after vs --timeout), eloquent.md (upserts), logging.md.
- filamentphp/filament 5.x: docs (installation, users, resources, relation managers, testing resources/actions, enums, creating/editing records, date-time picker); source `DateTimePicker.php`, `MakeUserCommand.php`, `SupportServiceProvider.php`, `InstallCommand.php`.
- deployphp/deployer v8.0.5: `recipe/laravel.php`, `recipe/common.php`, `recipe/deploy/{update_code,vendors}.php`, `recipe/provision.php`, `recipe/provision/{php,databases}.php`, `recipe/provision/Caddyfile`, `contrib/php-fpm.php`, `docs/avoid-php-fpm-reloading.md`; deployphp/action `action.yaml`.
- caddyserver/website docs (php_fastcgi, log, redir) and caddy source `fastcgi.go`.
- laravel/boost v2.10.3: `Copilot.php`, `Agent.php`, `McpWriter.php`, `GuidelineWriter.php`, `GuidelineComposer.php`, `BoostServiceProvider.php`, `config/boost.php`, `Support/Config.php`, `.ai/` tree.
- laravel-vite-plugin 3.2.0 (npm tarball): `dist/index.js`, `dist/fonts/*`.
- docker-library/postgres `18/trixie/Dockerfile`; Docker Hub tags (`18`, `18.6`, 2026-10-07).
- MDN content: Navigator.share(), Clipboard.writeText().
- Packagist p2 metadata and npm registry (versions, requires); GitHub releases API (action versions).

### Secondary
- Larastan README (phpstan.neon example); Pest docs installation.md (remove phpunit, `--init`).

### Tertiary (LOW, needs confirmation)
- Google Calendar template URL; WhatsApp/Facebook share URL formats; PG15 public-schema change; Ubuntu 26.04 package specifics on Hetzner.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH. Versions from registries; key libraries installed and executed.
- Recurrence/time logic: HIGH. Executed across both DST transitions; Laravel/Filament timezone traps confirmed in source.
- Architecture/data model: MEDIUM-HIGH. Design judgement built on verified mechanics.
- Deployment: MEDIUM-HIGH. Recipe/Caddy behaviour read from source; Ubuntu 26.04/Hetzner specifics assumed.
- Boost integration: HIGH for file paths and gating (source); MEDIUM for commit/ignore policy (judgement).
- Pitfalls: HIGH for items backed by source reads; MEDIUM for share-URL and Google-link formats.

**Research date:** 2026-10-16
**Valid until:** 2026-11-15 (fast-moving majors: Filament 5.x, Boost 2.x, Laravel 13.x minors)

