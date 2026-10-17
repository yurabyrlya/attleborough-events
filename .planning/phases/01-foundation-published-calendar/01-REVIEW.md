---
phase: 01-foundation-published-calendar
reviewed: 2026-10-17T07:35:00Z
depth: deep
files_reviewed: 178
files_reviewed_list:
  - .github/workflows/ci.yml
  - app/Calendar/CalendarFilters.php
  - app/Calendar/DateWindow.php
  - app/Calendar/EventTiming.php
  - app/Calendar/Export/GoogleCalendarLink.php
  - app/Calendar/Export/OccurrenceIcs.php
  - app/Calendar/Export/OccurrenceUid.php
  - app/Calendar/ManualRecurrence.php
  - app/Calendar/OccurrenceGenerator.php
  - app/Calendar/OccurrenceTiming.php
  - app/Calendar/PublicOccurrenceQuery.php
  - app/Calendar/RecurrenceExpander.php
  - app/Calendar/UpcomingDates.php
  - app/Console/Commands/OpsDrill.php
  - app/Console/Commands/OpsHealth.php
  - app/Console/Commands/SyncOccurrences.php
  - app/Enums/DateFilter.php
  - app/Enums/EventOrigin.php
  - app/Enums/EventStatus.php
  - app/Enums/HealthState.php
  - app/Enums/OccurrenceStatus.php
  - app/Enums/PriceType.php
  - app/Enums/RepeatEnds.php
  - app/Enums/RepeatPattern.php
  - app/Filament/Pages/SystemHealth.php
  - app/Filament/Resources/Events/Actions/StatusActions.php
  - app/Filament/Resources/Events/EventResource.php
  - app/Filament/Resources/Events/Pages/CreateEvent.php
  - app/Filament/Resources/Events/Pages/EditEvent.php
  - app/Filament/Resources/Events/Pages/ListEvents.php
  - app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php
  - app/Filament/Resources/Events/Schemas/EventForm.php
  - app/Filament/Resources/Events/Tables/EventsTable.php
  - app/Http/Controllers/CalendarController.php
  - app/Http/Controllers/Controller.php
  - app/Http/Controllers/EventController.php
  - app/Http/Controllers/OccurrenceIcsController.php
  - app/Jobs/OpsDrillJob.php
  - app/Jobs/QueueHeartbeat.php
  - app/Models/Category.php
  - app/Models/Event.php
  - app/Models/EventOccurrence.php
  - app/Models/EventSlug.php
  - app/Models/FailedJob.php
  - app/Models/Heartbeat.php
  - app/Models/User.php
  - app/Models/Venue.php
  - app/Ops/HealthCheck.php
  - app/Ops/HealthReport.php
  - app/Providers/AppServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Publishing/Actions/ChangeEventStatus.php
  - app/Publishing/Actions/ChangeOccurrenceStatus.php
  - app/Publishing/Actions/PublishEvent.php
  - app/Publishing/Actions/UpdatePublishedEvent.php
  - app/Publishing/Data/PublishableEventData.php
  - app/Publishing/EventSlugger.php
  - app/Publishing/Exceptions/InvalidStatusTransition.php
  - app/Seo/EventJsonLd.php
  - app/Support/EventPageData.php
  - app/Support/LondonFormatter.php
  - app/Support/OccurrenceCardData.php
  - app/Support/PublicUrl.php
  - app/Support/SafeLinks.php
  - app/Support/ShareLinks.php
  - app/Support/TextLimit.php
  - composer.json
  - config/app.php
  - config/auth.php
  - config/cache.php
  - config/calendar.php
  - config/database.php
  - config/filesystems.php
  - config/logging.php
  - config/mail.php
  - config/ops.php
  - config/queue.php
  - config/services.php
  - config/session.php
  - config/site.php
  - database/factories/CategoryFactory.php
  - database/factories/EventFactory.php
  - database/factories/UserFactory.php
  - database/factories/VenueFactory.php
  - database/migrations/0001_01_01_000000_create_users_table.php
  - database/migrations/0001_01_01_000001_create_cache_table.php
  - database/migrations/0001_01_01_000002_create_jobs_table.php
  - database/migrations/2026_10_16_000100_enable_pg_trgm.php
  - database/migrations/2026_10_16_000110_create_categories_table.php
  - database/migrations/2026_10_16_000120_create_venues_table.php
  - database/migrations/2026_10_16_000130_create_events_table.php
  - database/migrations/2026_10_16_000140_create_event_occurrences_table.php
  - database/migrations/2026_10_16_000150_add_status_audit_columns.php
  - database/migrations/2026_10_16_000160_create_heartbeats_table.php
  - database/migrations/2026_10_17_000100_make_event_slugs_unique.php
  - database/migrations/2026_10_17_000200_create_event_slugs_table.php
  - database/seeders/CategorySeeder.php
  - database/seeders/DatabaseSeeder.php
  - ops/brand/og-default.html
  - ops/check-event-page.sh
  - ops/check-public-assets.sh
  - ops/render-brand-assets.sh
  - package.json
  - resources/css/app.css
  - resources/js/share.js
  - resources/svg/calendar-pin.svg
  - resources/views/calendar/index.blade.php
  - resources/views/calendar/partials/chip.blade.php
  - resources/views/calendar/partials/empty-state.blade.php
  - resources/views/calendar/partials/filter-chips.blade.php
  - resources/views/calendar/partials/occurrence-card.blade.php
  - resources/views/components/brand/calendar-pin.blade.php
  - resources/views/components/brand/logo.blade.php
  - resources/views/components/category-icon.blade.php
  - resources/views/components/icons/category/arts-culture.blade.php
  - resources/views/components/icons/category/charity.blade.php
  - resources/views/components/icons/category/community.blade.php
  - resources/views/components/icons/category/family.blade.php
  - resources/views/components/icons/category/food-drink.blade.php
  - resources/views/components/icons/category/market.blade.php
  - resources/views/components/icons/category/music.blade.php
  - resources/views/components/icons/category/other.blade.php
  - resources/views/components/icons/category/school.blade.php
  - resources/views/components/icons/category/sport.blade.php
  - resources/views/events/partials/dates.blade.php
  - resources/views/events/partials/share.blade.php
  - resources/views/events/show.blade.php
  - resources/views/filament/pages/system-health.blade.php
  - resources/views/layouts/app.blade.php
  - routes/console.php
  - routes/web.php
  - tests/Feature/Console/SyncOccurrencesCommandTest.php
  - tests/Feature/Filament/CreateEventTest.php
  - tests/Feature/Filament/CreateRecurringEventTest.php
  - tests/Feature/Filament/EditEventTest.php
  - tests/Feature/Filament/StatusActionsTest.php
  - tests/Feature/Ops/AdminFontAndLoggingTest.php
  - tests/Feature/Ops/CalendarSyncHeartbeatTest.php
  - tests/Feature/Ops/FailedJobsTest.php
  - tests/Feature/Ops/HealthReportTest.php
  - tests/Feature/Ops/OpsFixtures.php
  - tests/Feature/Ops/QueueHeartbeatTest.php
  - tests/Feature/Ops/SchedulerHeartbeatTracerTest.php
  - tests/Feature/Public/AttributeFiltersTest.php
  - tests/Feature/Public/BrandHeadTest.php
  - tests/Feature/Public/CalendarFiltersTracerTest.php
  - tests/Feature/Public/CalendarListTest.php
  - tests/Feature/Public/DateFilterTest.php
  - tests/Feature/Public/EventCalendarAndShareTest.php
  - tests/Feature/Public/EventJsonLdTest.php
  - tests/Feature/Public/EventPageContentTest.php
  - tests/Feature/Public/EventPageTracerTest.php
  - tests/Feature/Public/EventSeoTest.php
  - tests/Feature/Public/EventSlugRedirectTest.php
  - tests/Feature/Public/NoThirdPartyAssetsTest.php
  - tests/Feature/Public/OccurrenceCardTest.php
  - tests/Feature/Public/PublicLookTest.php
  - tests/Feature/Public/StatusVisibilityTest.php
  - tests/Feature/Publishing/ChangeEventStatusTest.php
  - tests/Feature/Publishing/ChangeOccurrenceStatusTest.php
  - tests/Feature/Publishing/PublishEventTest.php
  - tests/Feature/Publishing/UpdatePublishedEventTest.php
  - tests/Feature/Recurrence/RecurringOccurrencesTest.php
  - tests/Feature/Recurrence/RecurringSeriesTracerTest.php
  - tests/Feature/Status/CancelOccurrenceTracerTest.php
  - tests/Feature/Status/StatusSurvivesRegenerationTest.php
  - tests/Feature/WalkingSkeletonTest.php
  - tests/Fixtures/public/build/manifest.json
  - tests/Fixtures/views/brand-og-push.blade.php
  - tests/Pest.php
  - tests/TestCase.php
  - tests/Unit/Brand/ContrastTest.php
  - tests/Unit/Calendar/DateWindowTest.php
  - tests/Unit/Calendar/EventTimingTest.php
  - tests/Unit/Calendar/ManualRecurrenceTest.php
  - tests/Unit/Calendar/RecurrenceExpanderTest.php
  - tests/Unit/Enums/StatusTransitionsTest.php
  - vite.config.js
findings:
  critical: 1
  warning: 9
  info: 8
  total: 18
status: issues_found
---

# Phase 1: Code Review Report

**Reviewed:** 2026-10-17T07:35:00Z
**Depth:** deep
**Files Reviewed:** 178
**Status:** issues_found

## Summary

Scope: `git diff fda1784~1..HEAD` limited to app/, config/, database/, resources/, routes/, tests/, ops/, `.github/workflows/ci.yml`, composer.json, package.json and vite.config.js. There is no tailwind config file because Tailwind 4 is configured in CSS (`resources/css/app.css`). I read every application, config, migration, view, route, script and CI file in full. I checked tests for reliability only: the shared harness in `tests/Pest.php` plus a targeted look at the Host-header, redirect and logging tests. I did not read every test file line by line.

The domain core is in good shape:

- **UTC storage.** `timestamptz` columns, with the session timezone forced to UTC.
- **DST handling.** Times are worked out on London wall-clock dates, with an explicit `->utc()` before every query binding.
- **Single write path.** Status changes go through enum-checked transitions under `SELECT … FOR UPDATE`, in a consistent lock order.
- **Upserts keep status.** Occurrence upserts never overwrite `status`.
- **Escaping.** JSON-LD is encoded with `JSON_HEX_TAG`, sabre builds the ICS lines, `SafeLinks` limits links to http/https/mailto/tel, and Blade uses escaped echo throughout.
- **Public links.** Page links are root-relative; canonical, og:url, redirect and share URLs come from `config('app.url')`.

**One verified invariant violation.** The absolute stylesheet URL Vite writes into every public page (including `/`) uses the request Host header. The test suite cannot catch this because Vite is switched off for every Feature test.

**Other findings:**

- `PublishEvent` has no admin check, unlike the other three write actions.
- `PublishableEventData` claims to validate input for the non-Filament Phase 2 path but only checks recurrence.
- Permanent 301 slug redirects combined with rename-back can trap browsers in a redirect loop.
- An event that ends at midnight is shown as running into the next day.
- The iCalendar UID changes when an event switches between one-off and repeating, which breaks the public UID contract.
- `Event` lets `status`, `origin` and the approval columns be mass-assigned.
- CI tests on PHP 8.5, but the project targets 8.4.

## Critical Issues

### CR-01: Vite asset URLs on every public page (incl. `/`) are built from the request Host header

**File:** `resources/views/layouts/app.blade.php:29` (also `resources/views/events/show.blade.php:22`); root cause `app/Providers/AppServiceProvider.php:20-23` / `bootstrap/app.php:14-16`; test blind spot `tests/Pest.php:26`

**Issue:** `@vite('resources/css/app.css')` (and `@vite('resources/js/share.js')` on event pages) renders absolute URLs through Laravel's `asset()` helper. That helper takes its root from the current request's scheme and Host. Nothing forces the root URL: no `URL::forceRootUrl`, no `ASSET_URL`, no `trustHosts()`. I confirmed this by rendering the Vite tag against the fixture manifest with `Host: evil.example`:

```
<link rel="preload" as="style" href="http://evil.example/build/assets/app-fixture.css" />
<link rel="stylesheet" href="http://evil.example/build/assets/app-fixture.css" ... />
```

This breaks two Phase 1 rules: "absolute URLs only from config('app.url'), never Host header" and "no third-party requests or scripts on public `/`". Behind any shared cache (the Phase 5 Caddy setup, a CDN, or a corporate proxy), one poisoned response makes every visitor load CSS from an attacker's host, or load the share module JS on event pages.

None of the Host-header tests can catch it:

- `EventSeoTest:139`, `BrandHeadTest:72`, `EventCalendarAndShareTest:160/288` and `EventJsonLdTest:204` all run with `$this->withoutVite()` from `tests/Pest.php:26`.
- `NoThirdPartyAssetsTest` turns Vite on but sends the default Host.
- `ops/check-public-assets.sh` sends `Host` = BASE_URL's host, so it cannot see the problem either.

**Fix:** Pin the URL generator's root to config and only accept the configured host:

```php
// app/Providers/AppServiceProvider.php
public function boot(): void
{
    URL::forceRootUrl(rtrim((string) config('app.url'), '/'));
    if (str_starts_with((string) config('app.url'), 'https://')) {
        URL::forceScheme('https');
    }
}

// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustHosts(at: fn () => [parse_url((string) config('app.url'), PHP_URL_HOST)]);
})
```

Then add a regression test that calls `$this->withVite()`, sends `Host: evil.example` to both `/` and `/events/{slug}`, and asserts the body does not contain `evil.example`.

## Warnings

### WR-01: `PublishEvent` performs no admin authorization (the only write action without it)

**File:** `app/Publishing/Actions/PublishEvent.php:31-49`

**Issue:** `ChangeEventStatus`, `ChangeOccurrenceStatus` and `UpdatePublishedEvent` all re-check `config('app.admin_email')` against the actor as "defence in depth beside the panel gate". `PublishEvent`, the only creator of public rows, does not check at all. It trusts whatever `User $approver` it is given and records that user as `approved_by`. Its docblock says Phase 2's approval path will call it outside Filament (see the `PublishableEventData` constructor comment). From then on, any caller with any `User` can publish straight to the public calendar. The admin-email check is also duplicated in four places (`User::canAccessPanel` plus three actions), which makes this kind of gap easy to miss.

**Fix:** Extract one helper and call it from all four actions:

```php
// app/Publishing/AdminGate.php
final class AdminGate
{
    public static function authorize(User $user, string $message): void
    {
        $admin = config('app.admin_email');
        if (! is_string($admin) || $admin === '' || strcasecmp($user->email, $admin) !== 0) {
            throw new AuthorizationException($message);
        }
    }
}
// PublishEvent::__invoke
AdminGate::authorize($approver, 'Only the admin can publish events.');
```

Add a `PublishEventTest` case showing that a non-admin approver throws `AuthorizationException` and that no row is written.

### WR-02: `PublishableEventData` does not enforce the invariants it claims to own for the non-Filament path

**File:** `app/Publishing/Data/PublishableEventData.php:42-54, 178-188`

**Issue:** The constructor comment says "Validated here, not only in the form: Phase 2's approval path does not go through Filament (T-01-17)". It only validates recurrence. I confirmed the following are accepted:

- `title: ''` and `description: ''`. The slug silently becomes `event`.
- `allDay: false, startTime: null`. Nothing fails until `EventTiming::forDate` throws a generic `InvalidArgumentException` inside the transaction.
- `endDate: '2026-11-01'` with `startDate: '2026-11-05'`, which is stored as-is. `EventTiming::endDate()` hides this with `max(0, …)`.
- `startDate`/`endDate`/`repeatUntil` pass through `self::date()`, which is just `substr(trim($v), 0, 10)`. Any 10-character string gets through.
- `startTime`/`endTime` formats are never checked.
- A `repeatUntil` before `startDate` gives `untilPrecedes() === true`. The event is published with zero occurrences and no error.
- Neither `sourceUrl` nor `organiserContact` is required, although the form requires one or the other.

**Fix:** Check these in the constructor and throw `InvalidArgumentException` with a clear message:

- Parse every date with `CarbonImmutable::createFromFormat('!Y-m-d', …)` and require a round-trip match (`->format('Y-m-d') === $input`).
- Require times to match `/^\d{2}:\d{2}(:\d{2})?$/`.
- Require `trim($title) !== ''` and `trim($description) !== ''`.
- Require `allDay || $startTime !== null`, `endDate === null || endDate >= startDate`, and `repeatUntil >= startDate`.
- Require `sourceUrl !== null || organiserContact !== null`.

### WR-03: `ManualRecurrence` accepts impossible UNTIL dates and silently overflows them

**File:** `app/Calendar/ManualRecurrence.php:57, 184-192`

**Issue:** The constructor only checks `untilDate` against `/^\d{4}-\d{2}-\d{2}$/`. `untilValue()` then calls `createFromFormat('!Y-m-d', …)`, which overflows instead of failing. I confirmed that `new ManualRecurrence(Weekly, OnDate, '2026-13-45')->toRrule(…)` returns `FREQ=WEEKLY;BYDAY=FR;UNTIL=20270214T235959Z`, so a typo becomes a series that ends four months late. The `instanceof` guard never fires because the call does not return `false`.

**Fix:**

```php
$until = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->untilDate, self::TIMEZONE);
if (! $until instanceof CarbonImmutable || $until->format('Y-m-d') !== $this->untilDate) {
    throw new InvalidArgumentException("Invalid until date [{$this->untilDate}].");
}
```

Run the same round-trip check in the constructor so the DTO rejects the date early.

### WR-04: Permanent (cacheable) 301 slug redirects plus rename-back can trap browsers in a redirect loop

**File:** `app/Http/Controllers/EventController.php:33-35`, `app/Http/Controllers/OccurrenceIcsController.php:27-29`, `app/Publishing/EventSlugger.php:67-74`

**Issue:** Old slugs answer `301` with no `Cache-Control`. Browsers cache 301s heuristically and often indefinitely. `EventSlugger` deliberately supports renaming back (`EventSlugRedirectTest:68`):

1. The event is renamed `quiz-night` → `big-quiz-night`. A visitor follows `/events/quiz-night` and the browser caches `quiz-night → big-quiz-night`.
2. The admin renames it back. The server now answers `big-quiz-night → quiz-night`.

That visitor's browser now loops (`quiz-night` from cache → `big-quiz-night` from the server → `quiz-night` from cache …) and shows ERR_TOO_MANY_REDIRECTS for the event's canonical URL. Shared links in WhatsApp in-app browsers are badly exposed to this.

**Fix:** Keep the 301 status for SEO but cap how long it is cached, on both controllers:

```php
return redirect()->away(PublicUrl::event($event), 301)
    ->header('Cache-Control', 'public, max-age=3600');
```

Alternatively, refuse to make a history slug current again (always issue a fresh slug). Add a test asserting the redirect's `Cache-Control` header.

### WR-05: An event that ends at midnight is displayed as running into the next day

**File:** `app/Support/LondonFormatter.php:94-103` (used by `cardTime`, `datedTime`, share text, meta description, event-page title)

**Issue:** `lastDay()` subtracts one second from the exclusive end only for all-day events. A timed event that ends at exactly 00:00 is very common (discos, NYE, late gigs). `EventTiming` correctly rolls it to the next local midnight, so `lastDay()` returns the next day. I confirmed that an event on Sat 31 Oct, 19:00–00:00 renders as **"Sat 31 Oct, 7pm until Sun 1 Nov"**. That wrong text appears on the card, the event page dates list, share messages and the meta description.

**Fix:** Treat an end that falls exactly on local midnight as exclusive for timed events too:

```php
$end = self::local($occurrence->ends_at);
$inclusive = ($occurrence->event->all_day || $end->eq($end->startOfDay())) ? $end->subSecond() : $end;
return $inclusive->startOfDay();
```

Add an `OccurrenceCardTest` case for a 19:00–00:00 event, expecting "Sat 31 Oct, 7pm" with no "until".

### WR-06: iCalendar UID changes when an event switches between one-off and repeating (breaks the "never change" public contract)

**File:** `app/Calendar/Export/OccurrenceUid.php:29-33`

**Issue:** The UID scheme depends on `$event->recurrence_rule === null`. A one-off event's file carries `42@domain`. If the admin later makes it weekly (`EditEvent` → `UpdatePublishedEvent` allows this), the same date's occurrence keeps its row (key = start date) but its UID becomes `42-2026-10-30@domain`. The reverse switch has the same problem. Residents who imported the original `.ics` and re-import get a duplicate entry, and a CANCELLED update never matches the original. The class docblock calls the UID a public contract that "must never change for the same occurrence".

**Fix:** Pick one scheme that does not depend on the rule. Either always use `{event_id}-{recurrence_key}@domain`, accepting that a moved one-off date then gets a new UID, or store the UID on the `event_occurrences` row when it is first inserted and never rewrite it. If you keep the current split, block switching between one-off and repeating in `UpdatePublishedEvent` and the form. Add a test that switches the type and asserts the UID of an existing occurrence is unchanged.

### WR-07: `Event` allows mass assignment of `status`, `origin`, `approved_by` and `approved_at`

**File:** `app/Models/Event.php:53-58`

**Issue:** The model says "Writes go only through App\Publishing\Actions". Yet the publishing-state columns are in `#[Fillable]` only so that `PublishEvent` can call `new Event($attrs + [...])`. Any future `Event::create($request->validated())`, `->fill($data)`, a Filament page that falls back to the default `handleRecordCreation/Update`, or a relation-manager `CreateAction` can then set `status = published` and `approved_by` freely, bypassing the single write path and the state machine. Today `UpdatePublishedEvent` only stays safe because `toAttributes()` happens to leave these keys out.

**Fix:** Remove `status`, `origin`, `approved_by` and `approved_at` from `#[Fillable]`. In `PublishEvent`, write them explicitly:

```php
$event = new Event($data->toAttributes());
$event->forceFill([
    'status' => EventStatus::Published,
    'origin' => $origin,
    'approved_by' => $approver->id,
    'approved_at' => now()->utc(),
]);
```

Add an architecture test asserting these attributes are not fillable.

### WR-08: CI runs PHP 8.5 while the project targets PHP 8.4

**File:** `.github/workflows/ci.yml:39` vs `composer.json:14, 95-97`

**Issue:** `composer.json` pins `config.platform.php` to `8.4.12` (dependencies resolve for 8.4), and dev uses Herd on 8.4. CI installs `php-version: '8.5'`. A green CI run therefore does not prove the code works on the runtime it ships on. 8.5 deprecations, behaviour changes and new functions accidentally used in code will not match production.

**Fix:** Set `php-version: '8.4'`, or run a matrix of `['8.4', '8.5']` if you want early warning while 8.4 stays the gate.

### WR-09: The edit page (the only place status actions live) crashes for any recurrence rule `ManualRecurrence` does not emit

**File:** `app/Filament/Resources/Events/Pages/EditEvent.php:30-33` → `app/Publishing/Data/PublishableEventData.php:96-105` → `app/Calendar/ManualRecurrence.php:97-99`

**Issue:** `mutateFormDataBeforeFill()` calls `ManualRecurrence::fromRrule()`, which throws `InvalidArgumentException` for any rule outside the narrow regex. Examples: a rule written by a Phase 2/3 importer, a manual SQL fix, or `COUNT` above 999. The edit page then returns a 500. Hide/Cancel/Reinstate exist only as header actions on that page (`StatusActions::forEvent()`), so the admin cannot take a broken or wrong event off the public calendar through the UI. That undermines the "hiding is the only way to take an event off" design. `EventsTable::repeatsLabel()` already catches this; the edit page does not.

**Fix:** In `fromEvent()`, catch the exception and fall back to `RepeatPattern::None` form state with a persistent warning notification ("This event's repeat rule can't be edited here"). Also add Hide/Cancel as record actions in `EventsTable`, so status changes do not depend on the form rendering.

## Info

### IN-01: `Carbon::parse` still used, contrary to the stated "no Carbon::parse" rule

**File:** `app/Calendar/Export/OccurrenceIcs.php:54, 96`; `app/Jobs/OpsDrillJob.php:29`
**Issue:** All three inputs are self-generated, not user input, so this is not exploitable. It does break the letter of the project invariant, and `parse()` accepts relative phrases if the input ever changes.
**Fix:** Use `CarbonImmutable::createFromFormat(DATE_ATOM, …)` / `createFromFormat('Y-m-d H:i:s', …, 'UTC')`. In `lastModified()`, simply return `max($stamps)` from the Carbon instances, with no format/parse round trip.

### IN-02: The calendar timezone is hard-coded in several places beside `config('calendar.timezone')`

**File:** `app/Support/LondonFormatter.php:13`, `app/Calendar/ManualRecurrence.php:30`, `app/Models/Event.php:68`, `app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php:39`, `app/Filament/Resources/Events/Tables/EventsTable.php:31`, `routes/console.php:17`, `app/Publishing/Data/PublishableEventData.php:37`
**Issue:** Display, UNTIL encoding and grouping use the literal `Europe/London`, while date windows and the expander read config or the event's `timezone` column. These are consistent today, but the code relies on two sources of truth.
**Fix:** Read `config('calendar.timezone')` everywhere, or define one `CalendarZone::NAME` constant used by both config and code.

### IN-03: Failed-job retention is duplicated; the config key is unused

**File:** `routes/console.php:33`, `config/ops.php:26`
**Issue:** `queue:prune-failed --hours=720` is hard-coded. `ops.failed_jobs_retention_hours` is never read, so changing the config has no effect.
**Fix:** `Schedule::command('queue:prune-failed', ['--hours' => (int) config('ops.failed_jobs_retention_hours')])->daily();`

### IN-04: The release directory name is published on every public page

**File:** `resources/views/layouts/app.blade.php:6`
**Issue:** `<meta name="x-release" content="{{ basename(base_path()) }}">` exposes the server's deployment directory name (in Phase 5 this will be the Deployer release id/timestamp). That is minor information disclosure on a privacy-focused public site.
**Fix:** Emit it only to authenticated admins, behind an env flag, or as a response header on `/up` only.

### IN-05: The occurrence generator hard-deletes future rows that carry admin-set status and audit data

**File:** `app/Calendar/OccurrenceGenerator.php:52-55`
**Issue:** When a schedule edit stops producing a date, a future row is deleted even if the admin had cancelled it. The `status_changed_by/at` audit is lost, and residents who imported that date's `.ics` get a 404 rather than a CANCELLED update. This matches the documented design, but it is at odds with the project's "no hard deletes" stance.
**Fix:** Consider marking orphaned future rows `hidden` (or adding an `orphaned_at` column) instead of deleting them. Alternatively, document this as an accepted exception in the decisions log.

### IN-06: SEQUENCE and LAST-MODIFIED change on every daily sync even when nothing changed

**File:** `app/Calendar/OccurrenceGenerator.php:46-50`, `app/Calendar/Export/OccurrenceIcs.php:88-97`
**Issue:** Eloquent's `upsert()` always adds `updated_at` to the update columns (`Builder::addUpdatedAtToUpsertColumns`). Every recurring occurrence therefore gets a new `updated_at` at 03:10 daily, so its ICS `SEQUENCE` and `LAST-MODIFIED` move forward without any real change. This may prompt calendar clients to re-sync.
**Fix:** Upsert through `DB::table('event_occurrences')->upsert(...)` (with `created_at` supplied explicitly on insert). Alternatively, base SEQUENCE on a content hash or on `events.updated_at` combined with `status_changed_at`.

### IN-07: The Retry action ignores the `queue:retry` exit code

**File:** `app/Filament/Pages/SystemHealth.php:151-154`
**Issue:** `forget()` checks `Artisan::call(...) !== 0`, but `retry()` does not, so a failed retry still shows "Sent back to the queue".
**Fix:** `if (Artisan::call('queue:retry', ['id' => [$uuid]]) !== 0) { $this->notifyAlreadyHandled(); return; }`

### IN-08: Ops and CI hard-coding and supply-chain hygiene

**File:** `ops/check-event-page.sh:20`, `.github/workflows/ci.yml:35, 37, 46`
**Issue:** `UID_DOMAIN='@whatsonattleborough.co.uk'` is hard-coded even though `site.domain` comes from `SITE_DOMAIN`, so the check gives a false FAIL on any other domain. The GitHub Actions are pinned to mutable tags (`@v7`, `@v2`) rather than commit SHAs.
**Fix:** Let the script take `UID_DOMAIN="${UID_DOMAIN:-@whatsonattleborough.co.uk}"`, and pin the actions to full commit SHAs with a version comment.

---

_Reviewed: 2026-10-17T07:35:00Z_
_Reviewer: the agent (gsd-code-reviewer)_
_Depth: deep_
