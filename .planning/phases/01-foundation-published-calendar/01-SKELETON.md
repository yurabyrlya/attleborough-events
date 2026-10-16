# Walking Skeleton — What's On Attleborough (`attleborough-events`)

**Phase:** 1
**Generated:** 2026-10-16

## Capability Proven End-to-End

The admin logs in at `https://whatsonattleborough.co.uk/admin` and creates a one-off event in Filament. A resident then opens `https://whatsonattleborough.co.uk/` on their phone and sees it under its London date heading. The app is served by a release that GitHub Actions deployed with zero downtime.

The skeleton is three plans:

| Plan | Slice of the skeleton | Proven by |
|------|-----------------------|-----------|
| 01-01 | Local full stack: Laravel scaffold → PostgreSQL schema → `PublishEvent` choke point → occurrence materialisation → `PublicOccurrenceQuery` → Blade list at `/` → Filament create page → CI on PostgreSQL 18 | `tests/Feature/WalkingSkeletonTest.php` (Filament create → event visible on `/`); CI green |
| 01-02 | Production box: domain + Cloudflare DNS (DNS-only) + Hetzner CX23 → `ops/provision.sh` → Caddy (HTTPS, www→apex) → PHP 8.5-FPM → PostgreSQL 18; hardened; worker/cron ready | `curl https://whatsonattleborough.co.uk/` returns the DB-backed probe JSON; `ops/check.sh` → ALL CHECKS PASSED |
| 01-03 | Deploy: first `dep deploy` (Deployer 8, `local_archive`) replaces the probe with the 01-01 app; CI deploy job on push to `main`; production admin created | Admin creates a real event in prod and sees it on a phone; curl loop during a deploy shows no non-200 |

Local full-stack run command (until 01-03 lands): see `README.md`. In order: `docker start ae-pg`, `composer install`, `php artisan migrate --seed`, `php artisan make:filament-user`, `php artisan serve`, `php artisan queue:work`, `php artisan schedule:work`.

## Architectural Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Framework | Laravel ^13.37 monolith on PHP 8.5 in prod/CI, Composer platform floor 8.4.12 | Locked stack. Several required packages need PHP ≥ 8.4. Pinning the platform to the floor stops 8.5-only lock drift (RESEARCH Pitfall 10) |
| Admin UI | Filament ^5.10 panel `admin` at `/admin`. Resources hold no business rules; pages delegate to actions (`handleRecordCreation` → `PublishEvent`) | One admin, no custom admin UI to build. Keeping rules out of Filament lets Phase 2's review queue reuse the same actions |
| Public UI | Server-rendered Blade at `/`. No Livewire or JS needed on public pages; Tailwind 4 + self-hosted font arrive in 01-07 | CAL-06 (filters work as plain links with JS disabled), crawlable HTML, no third-party assets |
| Data layer | PostgreSQL 18 everywhere (local Docker `postgres:18`, CI service, prod distro/PGDG package) via Eloquent. pgsql connection `timezone = UTC`. `timestamptz` columns. `pg_trgm` enabled by the first migration | One engine end-to-end (tests never use an in-memory file DB). UTC storage avoids DST bugs. `pg_trgm` is needed for Phase 3 duplicate matching and proves DB-owner rights early |
| Time model | Events store local wall-clock schedule (`start_date`, `start_time`, …, Europe/London). `event_occurrences` store UTC `starts_at`/`ends_at`/`expires_at`. `recurrence_key` = London `Y-m-d`. Upsert on `(event_id, recurrence_key)` never touches `status` | Survives DST (25 Oct 2026 / 28 Mar 2027 fixtures). Admin-set occurrence statuses survive regeneration. Expiry queries are one indexed predicate |
| Write path | Single choke point `App\Publishing\Actions\*` (`PublishEvent` now; `UpdatePublishedEvent` and status actions later), each in one DB transaction | "Nothing reaches the calendar without admin approval". Phase 2 approval reuses `PublishEvent` with a different `EventOrigin` |
| Read path | `App\Calendar\PublicOccurrenceQuery` (events published or cancelled, occurrences scheduled or cancelled, `expires_at > now`, ordered by `starts_at, id`) | One read model for list, filters (01-06) and event page (01-08). Cancelled items stay listed with a badge until they expire |
| Recurrence engine | `sabre/vobject` ^5 only (synthetic VEVENT + `EventIterator` in Europe/London), arriving in 01-04 | Locked single engine. The same library serialises `.ics` (01-08) and parses feeds (Phase 3) |
| Auth | Filament session login. Only the user whose email equals `config('app.admin_email')` (`ADMIN_EMAIL`) can access the panel. No registration or reset. Database sessions | Single-admin product (ADM-01) with the smallest attack surface |
| Queue / cache / session | `database` drivers. No Redis anywhere | Small VPS budget. `queue:restart` signals through the shared database cache |
| Tests & CI | Pest 5 on PostgreSQL with `Http::preventStrayRequests()`. GitHub Actions runs Pint `--test`, Larastan (level 6) and Pest on `postgres:18` with `TZ=America/New_York` | Catches system-timezone reliance and engine differences before prod |
| Hosting | Hetzner Cloud CX23. Ubuntu 26.04 (or 24.04 + ondrej PPA + PGDG, decided at the 01-02 checkpoint). Native packages, no Docker in prod. Rebuilt from the versioned `ops/provision.sh` | Low monthly cost. Reproducible from the repo. Deployer's own provision recipe is not used (it adds a PPA unconditionally and installs Redis) |
| Web server / TLS | Caddy 2 (official apt repo): automatic Let's Encrypt, HSTS, www → apex 301, `php_fastcgi … resolve_root_symlink` to the FPM pool socket `/run/php/php8.5-fpm-attleborough.sock` | Zero-config TLS. `resolve_root_symlink` means symlink-swap deploys are served without reloading PHP-FPM (opcache pitfall) |
| Process model | PHP-FPM pool, systemd queue worker (`attleborough-queue.service`, started by a `.path` unit once `current/artisan` exists) and cron `schedule:run` all run as `deployer`. `deployer` has no sudo | One file owner for web, worker and cron (no log-permission split). Deploys never need root |
| DNS / domain / email | Canonical `whatsonattleborough.co.uk` with no www (D-02). Cloudflare Free DNS, DNS-only records (D-03). `hello@` via Cloudflare Email Routing (D-04) | Origin TLS end-to-end and real client IPs for Phase 2 rate limits. Switching to proxied later is reversible |
| Deployment | Deployer 8 `recipe/laravel.php`, `update_code_strategy = local_archive`, `deploy_path = /var/www/attleborough-events`, `shared/.env`, `keep_releases = 5`. Vite assets built in CI and uploaded (from 01-07). Runs from GitHub Actions on push to `main` (01-03) | Atomic symlink releases with no GitHub access needed on the server. `--no-dev` keeps Boost and Pest off the box (D-09). Migrations must be expand → contract because they run before the swap |
| Ops visibility | Logs: Laravel `daily` 14 days (01-09), journald 200M cap, Caddy roll 10 × 50MiB / 14 days, logrotate backstop (01-02). Heartbeats + Filament "System health" (01-09) | OPS-04 on a box nobody watches daily |
| Directory layout | Domain-grouped monolith: `app/Calendar` (timing, generator, queries), `app/Publishing/{Actions,Data}` (write choke point), `app/Enums`, `app/Filament/Resources/*/{Pages,Schemas,Tables}`, `app/Support`, `config/site.php`, `config/calendar.php`. Server config lives in `ops/` | Later phases add `app/Ingestion`, `app/Review` and `app/Submissions` beside these without moving them |
| Dev tooling | Laravel Boost ^2.10, require-dev only. Project rules in `.ai/guidelines/attleborough.md`. AGENTS.md keeps the GSD block (D-09) | Agent help at dev time with no LLM in the product or on the VPS |

## Stack Touched in Phase 1

- [x] Project scaffold: Laravel 13 + Filament 5 + Pest 5 + Larastan + Pint, CI workflow (01-01)
- [x] Routing: `GET /` (`calendar`) and the Filament panel `/admin` (01-01)
- [x] Database: real write (`PublishEvent` → `events` + `event_occurrences` in one transaction) and real read (`PublicOccurrenceQuery`) on PostgreSQL 18 (01-01). Prod DB owned by the app role (01-02)
- [x] UI: Filament create-event form submits through `PublishEvent`, and the result renders on the public list (01-01)
- [x] Deployment: documented local full-stack run (01-01 README). Production HTTPS box (01-02). Zero-downtime Deployer release from GitHub Actions (01-03)

## Out of Scope (Deferred to Later Slices)

Not in the skeleton (01-01..01-03). These come later in Phase 1, without changing the decisions above:
- Recurring series, edit via `UpdatePublishedEvent`, daily `calendar:sync-occurrences` (01-04)
- Hide / cancel / reinstate for events and single occurrences (01-05)
- Today / weekend / week, category, Free and family filters, `noindex`, D-05 empty states (01-06)
- Brand, Tailwind, self-hosted font, icons, dark mode (01-07)
- Event page, meta/OG, JSON-LD, `.ics`, Google link, share (01-08)
- Heartbeats, System health page, failed-job retry, Laravel daily logs (01-09)

Not in Phase 1 at all:
- Drafts, review queue and public submissions (Phase 2)
- Any ingestion, crawling or source health (Phases 3-4)
- Sitemap, feeds, privacy/legal pages, analytics, WCAG audit, backups + restore drill (Phase 5)
- Admin MFA (open question; Phase 5 hardening at the latest)
- Cloudflare orange-cloud proxy, Redis, Docker in production, Livewire on public pages, a month-grid view, public user accounts

## Subsequent Slice Plan

Each later slice extends this skeleton without altering its architectural decisions:

- 01-04: the admin creates a weekly / nth-weekday series and sees each upcoming occurrence (sabre in Europe/London, upsert on `recurrence_key`)
- 01-05: the admin cancels or hides an event or a single occurrence; residents see a "Cancelled" badge
- 01-06: residents narrow the list with plain-link filters that work without JS
- 01-07: the site looks like "What's On Attleborough" (D-01, D-05..D-08)
- 01-08: residents open an event page, add it to their calendar and share it
- 01-09: the admin sees scheduler/queue heartbeats and failed jobs in Filament
- Phase 2: organisers submit events; every draft goes through one review queue that calls `PublishEvent`
- Phase 3: ICS sources fetched on a schedule become drafts with change and duplicate handling
- Phase 4: the deterministic extraction ladder and geofence produce drafts from live P0 sources
- Phase 5: discoverable, legally compliant, measurable and backed up, ready for public launch
