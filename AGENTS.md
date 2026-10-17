<!-- GSD Configuration — managed by gsd-core installer -->
# Instructions for GSD

- Use the gsd-core skill when the user asks for GSD or uses a `gsd-*` command.
- Treat `/gsd-...` or `gsd-...` as command invocations and load the matching file from `.github/skills/gsd-*`.
- When a command says to spawn a subagent, prefer a matching custom agent from `.github/agents`.
- Do not apply GSD workflows unless the user explicitly asks for them.
- After completing any `gsd-*` command (or any deliverable it triggers: feature, bug fix, tests, docs, etc.), ALWAYS: (1) offer the user the next step by prompting via `ask_user`; repeat this feedback loop until the user explicitly indicates they are done.
<!-- /GSD Configuration -->

===

<laravel-boost-guidelines>
=== .ai/attleborough rules ===

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
- `UpdatePublishedEvent` refuses switching an event between one-off and repeating (the `OccurrenceUid` "never change" contract depends on it; lifting it needs a UID migration plan) and refuses edits to events whose stored rule does not round-trip exactly through `ManualRecurrence::tryFromStoredRule()` for the event's start date (WR-10) (hide/cancel still work via `ChangeEventStatus`).
- Seeders seed reference data only (categories). Never seed demo events or users.

## Tests

- Pest 5 on PostgreSQL (`attleborough_testing`), never SQLite or in-memory databases.
- `Http::preventStrayRequests()` is global (see `tests/Pest.php`). No test may hit the network.
- Freeze time with `$this->travelTo(CarbonImmutable::parse('...', 'Europe/London'))` for any window or expiry test.
- Include the DST fixtures: Sun 25 Oct 2026 (BST ends, 25h day) and Sun 28 Mar 2027 (BST starts, 23h day).
- Assert stored instants with raw SQL where it matters: `DB::selectOne("select starts_at at time zone 'UTC' as s ...")`.
- CI runs with `TZ=America/New_York` to catch reliance on the system timezone.
- Boost's Pest guidance targets Pest 3/4. Pest 5 conventions win.
- Browser UAT: before signing off a phase, run the flows in `docs/uat/browser-uat.md` against the local app and record results in that phase's `NN-UAT.md`. A phase that adds or changes UI also adds or updates its flow there.

## Filament 5 (not covered by Boost)

- Schemas use `Filament\Schemas\Schema` with `->components([...])`. Layout components (`Section`, `Grid`) live in `Filament\Schemas\Components`. `Get`/`Set` live in `Filament\Schemas\Components\Utilities`.
- Actions are `Filament\Actions\*`. Table action tests use `Filament\Actions\Testing\TestAction::make('name')->table($record)`.
- Enums implement `Filament\Support\Contracts\HasLabel` (and `HasColor` for statuses).
- Resources live in `app/Filament/Resources/{Plural}/` with `Schemas/` and `Tables/` subfolders.
- Panel access: single admin. `User::canAccessPanel()` delegates to `App\Publishing\AdminGate::allows()`, the one case-insensitive ADMIN_EMAIL rule, which PublishEvent, UpdatePublishedEvent, ChangeEventStatus and ChangeOccurrenceStatus also enforce via `AdminGate::authorize()`. No registration or password reset.

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

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
