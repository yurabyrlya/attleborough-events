# Attleborough Events: project rules

These rules are specific to "What's On Attleborough" (repo `attleborough-events`). Where they conflict with generic Laravel/Pest/Tailwind guidance above, these win. Planning documents live in `.planning/` (GSD); decisions are recorded there, not in `.ai/rules`.

## Time

- `config('app.timezone')` and the PostgreSQL session timezone (`config/database.php` pgsql `'timezone' => 'UTC'`) are both UTC. Never change either.
- Every instant is a `timestampTz` column stored in UTC. Call `->utc()` before assigning or upserting (upsert bypasses casts: pass explicit UTC `Y-m-d H:i:s` strings).
- Events keep the admin's LOCAL wall-clock schedule (`start_date`, `start_time`, `end_date`, `end_time`, `all_day`, `timezone = Europe/London`). Occurrences hold the UTC instants (`starts_at`, `ends_at`, `expires_at`).
- Do date arithmetic on `Europe/London`-zoned `CarbonImmutable` values (`addDay()`, `startOfDay()`, `next()`), never on UTC values. Convert with `->utc()` at the end.
- Display and window maths happen in Europe/London via `App\Support\LondonFormatter` (and `App\Calendar\DateWindow` once it exists).
- Never call `FilamentTimezone::set()`: TimePicker would then shift wall-clock times by an hour in summer. Add `->timezone('Europe/London')` to Filament columns/entries that show UTC instants.
- Expiry (REC-02): `expires_at` = end, or start + 3h (`calendar.no_end_expiry_hours`) when there is no end. An all-day occurrence (single or multi-day) is ONE row ending at the next local midnight after its last day (exclusive end). An end time earlier than the start means overnight (next day). Public visibility is strictly `expires_at > now`.
- `recurrence_key` is the London local date `Y-m-d` of the instance, never a datetime.

## Recurrence

- `sabre/vobject` `EventIterator` is the only recurrence expansion engine. Always pass `new DateTimeZone('Europe/London')`. Do not hand-roll weekly/monthly loops.
- `App\Calendar\OccurrenceGenerator::sync()` upserts on `(event_id, recurrence_key)` and updates only the instants, never `status`, so admin cancellations and hides survive. It deletes future rows the rule no longer produces and never touches past rows.

## Writes

- Only `App\Publishing\Actions\*` create, update or change status on `events` / `event_occurrences`. `PublishEvent` is the only creator of Event rows and wraps the event write and occurrence sync in one `DB::transaction`.
- Filament pages call actions (`handleRecordCreation` / `handleRecordUpdate` / action closures). No `Event::create()` or `$event->update()` in Filament pages, controllers or jobs.
- Seeders seed reference data only (categories). Never seed demo events or users.

## Tests

- Pest 5 on PostgreSQL (`attleborough_testing`), never SQLite or in-memory databases.
- `Http::preventStrayRequests()` is global (see `tests/Pest.php`). No test may hit the network.
- Freeze time with `$this->travelTo(CarbonImmutable::parse('...', 'Europe/London'))` for any window or expiry test.
- Include the DST fixtures: Sun 25 Oct 2026 (BST ends, 25h day) and Sun 28 Mar 2027 (BST starts, 23h day).
- Assert stored instants with raw SQL where it matters: `DB::selectOne("select starts_at at time zone 'UTC' as s ...")`.
- CI runs with `TZ=America/New_York` to catch reliance on the system timezone.
- Boost's Pest guidance targets Pest 3/4. Pest 5 conventions win.

## Filament 5 (not covered by Boost)

- Schemas use `Filament\Schemas\Schema` with `->components([...])`. Layout components (`Section`, `Grid`) live in `Filament\Schemas\Components`. `Get`/`Set` live in `Filament\Schemas\Components\Utilities`.
- Actions are `Filament\Actions\*`. Table action tests use `Filament\Actions\Testing\TestAction::make('name')->table($record)`.
- Enums implement `Filament\Support\Contracts\HasLabel` (and `HasColor` for statuses).
- Resources live in `app/Filament/Resources/{Plural}/` with `Schemas/` and `Tables/` subfolders.
- Panel access: single admin, `User::canAccessPanel()` checks `config('app.admin_email')`. No registration or password reset.

## Public site

- Blade + Tailwind 4. No Livewire on public pages. Filters are plain query-string links that work without JavaScript.
- No third-party scripts, fonts, images or CDNs. Fonts are self-hosted npm packages.
- WCAG 2.2 AA: 24x24px minimum targets, visible focus, `<time datetime>` on dates, "Cancelled" shown as text.
- British English and UK formats: "Sat 14 Nov, 7:30pm" (drop `:00`), headings "Today" / "Tomorrow" / "Saturday 31 October".
- Escaped Blade output (`{{ }}`) only. Descriptions are plain text.

## Dependencies

- Deployment: a Hetzner VPS (Caddy, PHP-FPM, PostgreSQL 18, systemd queue workers, cron `schedule:run`) via Deployer 8 from GitHub Actions. Not Laravel Cloud, Forge or Vapor; ignore generic advice to use them.
- No LLM SDKs or AI runtime packages in composer `require`. Laravel Boost is `require-dev` only and never runs on the server (`composer install --no-dev`).
- No Redis: queue, cache and session use the database.
- Composer `config.platform.php` is pinned to 8.4.12 so the lock installs on local PHP 8.4 and production PHP 8.5.
