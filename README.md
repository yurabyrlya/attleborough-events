# What's On Attleborough

A moderated, mobile-first community events calendar for Attleborough, Norfolk. Residents open one trustworthy, up-to-date list of what's on. Nothing reaches it without admin approval.

Repository / app name: `attleborough-events`. Production domain: `whatsonattleborough.co.uk`.

## Stack

- Laravel 13 on PHP 8.5 in production (Composer platform pinned to 8.4.12 so the lock installs locally on 8.4)
- PostgreSQL 18 (with `pg_trgm`) for everything: data, queue, cache and sessions (no Redis)
- Filament 5 admin panel at `/admin` (single admin, set by `ADMIN_EMAIL`)
- Public site: server-rendered Blade (Tailwind 4 + Vite arrive in a later plan)
- `sabre/vobject` for recurrence and ICS; `spatie/schema-org` for event JSON-LD
- Tests and quality: Pest 5 on PostgreSQL, Larastan (level 6), Pint
- CI: GitHub Actions on `postgres:18` (`.github/workflows/ci.yml`)
- Deploys: Deployer 8 to a Hetzner VPS (later plan)
- Laravel Boost is a **dev-only** aid for coding agents (`require-dev`; never installed on the server)

## Local full-stack run

Prerequisites: PHP 8.4+ with `pdo_pgsql` and `intl`, Composer 2, Docker.

First time only, start PostgreSQL 18 and create the databases:

```bash
docker run -d --name ae-pg -e POSTGRES_PASSWORD=postgres -p 5432:5432 \
  -v ae-pg18:/var/lib/postgresql postgres:18
docker exec ae-pg createdb -U postgres attleborough
docker exec ae-pg createdb -U postgres attleborough_testing
cp .env.example .env && php artisan key:generate
```

Then, each time:

```bash
docker start ae-pg
composer install
php artisan migrate --seed          # seeds the 10 categories only, never demo events
php artisan make:filament-user      # use the same email as ADMIN_EMAIL in .env
php artisan serve                   # http://127.0.0.1:8000 (admin: /admin)
php artisan queue:work              # in a second terminal
php artisan schedule:work           # in a third terminal
```

Log in at http://127.0.0.1:8000/admin, create an event, and see it at http://127.0.0.1:8000/.

## Checks

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pest                     # runs against the attleborough_testing PostgreSQL database
```

## Project rules

- Every instant is stored in UTC (`timestampTz`). Schedules and display use Europe/London.
- Only `App\Publishing\Actions\*` write published events. `PublishEvent` is the single creator.
- Tests run on PostgreSQL, never SQLite, and make no real HTTP requests.

The full rules for coding agents are in `.ai/guidelines/attleborough.md` (compiled into `AGENTS.md` by `php artisan boost:update`).

## Planning

Product spec: `docs/product-specification.md`. Roadmap, requirements, phase plans and decisions live in `.planning/` (GSD workflow).
