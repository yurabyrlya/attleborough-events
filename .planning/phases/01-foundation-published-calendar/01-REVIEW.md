---
phase: 01-foundation-published-calendar
reviewed: 2026-10-17T09:40:00Z
depth: deep
files_reviewed: 46
files_reviewed_list:
  - .env.example
  - .github/workflows/ci.yml
  - AGENTS.md
  - app/Calendar/Export/OccurrenceUid.php
  - app/Calendar/ManualRecurrence.php
  - app/Filament/Resources/Events/Pages/EditEvent.php
  - app/Filament/Resources/Events/Schemas/EventForm.php
  - app/Filament/Resources/Events/Tables/EventsTable.php
  - app/Http/Controllers/EventController.php
  - app/Http/Controllers/OccurrenceIcsController.php
  - app/Models/Event.php
  - app/Models/User.php
  - app/Providers/AppServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - app/Publishing/Actions/ChangeEventStatus.php
  - app/Publishing/Actions/ChangeOccurrenceStatus.php
  - app/Publishing/Actions/PublishEvent.php
  - app/Publishing/Actions/UpdatePublishedEvent.php
  - app/Publishing/AdminGate.php
  - app/Publishing/Data/PublishableEventData.php
  - app/Support/LondonFormatter.php
  - ops/check-public-assets.sh
  - resources/css/filament/admin/theme.css
  - tests/Feature/Filament/EditEventResilienceTest.php
  - tests/Feature/Filament/EditRepeatTypeTest.php
  - tests/Feature/Filament/StatusActionsTest.php
  - tests/Feature/Ops/AdminThemeTest.php
  - tests/Feature/Public/EventCalendarAndShareTest.php
  - tests/Feature/Public/EventJsonLdTest.php
  - tests/Feature/Public/EventPageContentTest.php
  - tests/Feature/Public/EventPageTracerTest.php
  - tests/Feature/Public/EventSeoTest.php
  - tests/Feature/Public/EventSlugRedirectTest.php
  - tests/Feature/Public/HostHeaderAssetsTest.php
  - tests/Feature/Public/OccurrenceCardTest.php
  - tests/Feature/Public/SlugRedirectCachingTest.php
  - tests/Feature/Publishing/ChangeEventStatusTest.php
  - tests/Feature/Publishing/ChangeOccurrenceStatusTest.php
  - tests/Feature/Publishing/OccurrenceUidStabilityTest.php
  - tests/Feature/Publishing/PublishEventTest.php
  - tests/Feature/Publishing/UpdatePublishedEventTest.php
  - tests/Feature/Status/StatusSurvivesRegenerationTest.php
  - tests/Unit/Calendar/ManualRecurrenceTest.php
  - tests/Unit/Ops/EnvExampleTest.php
  - tests/Unit/Publishing/PublishableEventDataTest.php
  - vite.config.js
findings:
  critical: 0
  warning: 2
  info: 11
  total: 13
status: issues_found
---

# Phase 1: Code Review Report (re-review after gap closure)

**Reviewed:** 2026-10-17T09:40:00Z
**Depth:** deep
**Files Reviewed:** 46 (changed since `acf5f40`), plus the unchanged call-chain context they depend on
**Status:** issues_found

## Summary

**Scope.** I reviewed `git diff acf5f40..HEAD`, limited to app/, config/, database/, resources/, routes/, tests/, ops/, `.github/workflows/ci.yml`, vite.config.js, package.json, composer.json, .env.example and AGENTS.md. That covers:

- gap plans 01-11, 01-14, 01-12 and 01-13;
- the quick-task admin theme (15d2172, b7e8527);
- the spacing fix b52a928. Its inline style was replaced by `class="grid gap-6"` in b7e8527, so the net diff in `resources/views` is empty.

There are no changes in config/, database/, routes/, package.json or composer.json in this range.

**Context files.** To trace call chains I also read these unchanged files:

- `StatusActions`
- `PublicOccurrenceQuery` (pagination path)
- `OccurrenceGenerator`
- `OccurrenceUid`
- `resources/views/layouts/app.blade.php`
- the events migration
- the built `public/build` manifest and theme CSS
- the full `ops/check-public-assets.sh`

**Quality gates (run locally, PHP 8.4.12).** `vendor/bin/pest` reports 465 passed / 2732 assertions, with 1 harness warning that the runner does not detail. `phpstan` reports 0 errors and `pint --test` passes.

**Prior findings.** CR-01 and WR-01..WR-09 are all fixed, each with targeted regression tests. Specifically:

- URL generation no longer reads the Host header.
- Every publishing write goes through one `AdminGate`.
- The DTO enforces the invariants the earlier review listed.
- The one-off↔series switch and unsupported rules are refused on the locked row.
- Slug redirects revalidate.
- CI runs an 8.4 + 8.5 matrix.

**Admin Vite theme.** It does not leak onto public pages:

- `@vite` in the public layout requests only `resources/css/app.css` (plus `share.js` on event pages).
- The built manifest gives the `app.css` entry no imports.
- The theme CSS contains only `data:` `url()`s and no deny-listed hosts, so the CI asset gates still pass.

**New findings: two warnings.**

- **WR-10.** The WR-09 "can this rule be edited?" test (`UpdatePublishedEvent::canEdit`) only checks the RRULE's shape, not that it round-trips. A stored rule whose BYDAY disagrees with the start date opens as editable, and any save silently rewrites the schedule to a different weekday. I confirmed this by running the code.
- **WR-11.** The WR-07 mass-assignment fix was applied to `Event` but not to its sibling `EventOccurrence`, which still lists `status` as fillable.

**Info.** IN-01..IN-08 carry forward unchanged; IN-02 gains one more hard-coded instance. Three new info items are added (IN-09..IN-11).

### Prior finding status

| ID | Status | Evidence |
|----|--------|----------|
| CR-01 | **Fixed** | `app/Providers/AppServiceProvider.php:42-56`: `URL::forceRootUrl(config('app.url'))`, `forceScheme('https')` only for https app URLs, and `Vite::createAssetPathsUsing` returns root-relative `/build/...`. Pagination was already root-relative (`PublicOccurrenceQuery.php:62` `withPath(route(..., absolute: false))`). Canonical, og and share URLs come from config. Tests: `HostHeaderAssetsTest` ("keeps the calendar page free of a spoofed Host…", "keeps the event page free of a spoofed Host…", "builds generic redirects such as the admin login from app.url…"). These run with `withVite()` against the fixture manifest and a genuinely spoofed absolute URI. There is also a live probe at `ops/check-public-assets.sh:102-106`. `trustHosts()` is deliberately not used, which is acceptable now that no URL comes from Host; revisit in Phase 5. |
| WR-01 | **Fixed** | `app/Publishing/AdminGate.php` (single rule). `PublishEvent.php:45` authorizes before the transaction, as do `UpdatePublishedEvent.php:59`, `ChangeEventStatus.php:36` and `ChangeOccurrenceStatus.php:37`. `User::canAccessPanel` delegates to `AdminGate::allows`. No other `events`/`event_occurrences` writer exists outside `App\Publishing` apart from the system `OccurrenceGenerator::sync` (grep-verified). Tests: `PublishEventTest` "refuses an approver who is not the admin and writes nothing", "matches the admin email case-insensitively when publishing", "refuses everyone… when ADMIN_EMAIL is empty"; `EditEventResilienceTest` "keeps the events list closed to anyone but the admin". |
| WR-02 | **Fixed** (residual gaps → IN-09) | `PublishableEventData.php:54, 67-130`: non-blank title/description; round-trip-checked start, end and last dates; `HH:MM[:SS]` times; start time required unless all-day; end ≥ start; last date ≥ start; source URL or contact required. Test: `PublishableEventDataTest` "rejects input the publish form would refuse, naming the problem" (dataset) plus the accept cases. |
| WR-03 | **Fixed** | `ManualRecurrence.php:60` (constructor), `:202` (`untilValue`), `:221` (`untilToLocalDate`) and `:235-252` (`parseExact` round-trip). Tests: `ManualRecurrenceTest` "refuses an impossible until date instead of overflowing it", "keeps a real until date such as 28 Feb 2026 as London end of day", "rejects a stored UNTIL that is not a real date". |
| WR-04 | **Fixed** | `EventController.php:36,46` and `OccurrenceIcsController.php:31` send `Cache-Control: no-cache, private` on the 301. Tests: `SlugRedirectCachingTest` (old slug, uppercase slug, old-slug .ics, rename-back with no loop). |
| WR-05 | **Fixed** | `LondonFormatter.php:105-107` treats an end exactly at London midnight as exclusive for timed events too. This also fixes timed multi-day events with no end time, which previously showed one day too many. Tests: `OccurrenceCardTest` "shows a timed occurrence that ends exactly at midnight as a single day" and "still shows an overnight occurrence that ends after midnight as running into the next day". |
| WR-06 | **Fixed** (via the refusal option) | `UpdatePublishedEvent.php:70-71` refuses one-off↔series on the locked row. The form mirrors this (`EventForm.php` `repeats` select is disabled for one-off events, and "Does not repeat" is removed for series). The contract is documented in `OccurrenceUid.php` and AGENTS.md. Tests: `OccurrenceUidStabilityTest` (5 cases, real .ics UID lines); `EditRepeatTypeTest` (3); `UpdatePublishedEventTest` "refuses to make a series "Does not repeat"…". See WR-10 for a related path that still rewrites a series' days. |
| WR-07 | **Fixed for `Event`** (sibling gap → WR-11) | `Event.php:55-60` drops `status`, `origin`, `approved_by` and `approved_at` from `#[Fillable]`. `PublishEvent.php:48-54` writes them with `forceFill`. Test: `PublishEventTest` "does not let publishing state or approval data be mass-assigned on Event". |
| WR-08 | **Fixed** | `.github/workflows/ci.yml:16-21,47`: matrix `['8.4','8.5']`, `fail-fast: false`, job name includes the PHP version. |
| WR-09 | **Fixed** (round-trip gap → WR-10) | `EditEvent.php:40-94,112-121`: a `#[Locked]` `$recurrenceEditable` flag, a read-only form, no Save action, a persistent warning, and a `Halt` in both `beforeValidate` and `handleRecordUpdate`. `UpdatePublishedEvent.php:65` re-checks on the locked row. `PublishableEventData::fromEvent` (`:183`) uses `tryFromRrule`. `EventsTable.php:56` adds the status actions as record actions. Tests: `EditEventResilienceTest` (read-only page, forced save refused, hide from header, action-level refusal, table hide/cancel, Show on calendar visibility). |

## Warnings

### WR-10: `canEdit()` accepts stored rules that do not round-trip, so saving an unrelated edit silently changes a series' days

**File:** `app/Publishing/Actions/UpdatePublishedEvent.php:89-93`; `app/Filament/Resources/Events/Pages/EditEvent.php:51`; `app/Calendar/ManualRecurrence.php:100-121`; display at `app/Filament/Resources/Events/Tables/EventsTable.php:71`

**Issue:** WR-09's guard treats a rule as editable when `ManualRecurrence::tryFromRrule()` can parse its shape. `fromRrule()` keeps only the pattern kind (weekly / nth / last) and discards the BYDAY weekday and ordinal. Saving then rebuilds the rule from `start_date`. So any stored rule whose BYDAY disagrees with the start date is reported as editable: the page shows no warning, Save is available, and the form displays the start date's weekday rather than the stored one. The first save of *any* field rewrites the schedule. I ran the code with a start date of Fri 23 Oct 2026:

```
FREQ=WEEKLY;BYDAY=MO                         => editable, resave: FREQ=WEEKLY;BYDAY=FR
FREQ=MONTHLY;BYDAY=2TU                       => editable, resave: FREQ=MONTHLY;BYDAY=4FR
FREQ=WEEKLY;BYDAY=FR;UNTIL=20270101T120000Z  => editable, resave: ...;UNTIL=20270101T235959Z
FREQ=WEEKLY;BYDAY=FR;UNTIL=20270101 (timed)  => editable, resave: ...;UNTIL=20270101T235959Z
FREQ=MONTHLY;BYDAY=-1FR (4th Fri start)      => editable, but the pattern does not fit -> the select
                                                holds a value outside its options; Save fails validation
```

These are exactly the sources WR-09 was about: a Phase 2/3 importer, a manual SQL fix, or an older rule. After a title-only edit to the Monday series:

- `OccurrenceGenerator::sync` deletes every future Monday row, including admin-cancelled rows with their audit data (see IN-05);
- it inserts Fridays instead;
- every Monday `.ics` UID disappears.

None of this is visible to the admin, and the WR-06 "UIDs never change" contract is broken by a path the new guard was meant to close. The events table makes the problem worse by labelling the Monday rule "Weekly on Friday" (`EventsTable.php:71`).

**Fix:** Make `canEdit()` require an exact round-trip against the stored rule (and reuse it for the table label):

```php
public static function canEdit(Event $event): bool
{
    if ($event->recurrence_rule === null) {
        return true;
    }

    $recurrence = ManualRecurrence::tryFromRrule($event->recurrence_rule, $event->all_day);
    $start = CarbonImmutable::createFromFormat('!Y-m-d', $event->start_date->format('Y-m-d'), $event->timezone);

    if ($recurrence === null || ! $start instanceof CarbonImmutable || ! $recurrence->isValidFor($start)) {
        return false;
    }

    try {
        return $recurrence->toRrule($start, $event->all_day) === $event->recurrence_rule;
    } catch (InvalidArgumentException) {
        return false;
    }
}
```

Add `EditEventResilienceTest` dataset rows for `FREQ=WEEKLY;BYDAY=MO`, `FREQ=MONTHLY;BYDAY=2TU`, `FREQ=MONTHLY;BYDAY=-1FR` and a timed rule with a non-23:59:59 UNTIL, all on a Friday-23-Oct start. Each must open read-only and refuse a forced save.

### WR-11: `EventOccurrence` still allows mass assignment of `status` (WR-07 applied to `Event` only)

**File:** `app/Models/EventOccurrence.php:30`

**Issue:** `#[Fillable(['event_id', 'recurrence_key', 'starts_at', 'ends_at', 'expires_at', 'status'])]`. WR-07's reasoning ("writes go only through `App\Publishing\Actions`"; the state machine sits in `ChangeOccurrenceStatus`) applies equally here. Any future `$occurrence->update(['status' => 'scheduled'])`, a relation-manager `EditAction`/`CreateAction` falling back to default record handling, or `->fill($request->validated())` would bypass the transition check, the `FOR UPDATE` locking order and the `status_changed_by/at` audit. Nothing needs `status` to be fillable:

- `OccurrenceGenerator::sync` writes through `EventOccurrence::upsert()`, which goes to the query builder and ignores `$fillable`.
- `ChangeOccurrenceStatus` uses `forceFill`.
- Factories are unguarded.

**Fix:** Remove `status` (and, ideally, all columns written only by `OccurrenceGenerator`'s upsert) from the attribute:

```php
#[Fillable(['event_id', 'recurrence_key', 'starts_at', 'ends_at', 'expires_at'])]
```

Extend the `PublishEventTest` mass-assignment test (or add an architecture test) to assert `! (new EventOccurrence)->isFillable('status')`.

## Info

### IN-01: `Carbon::parse` still used, contrary to the stated "no Carbon::parse" rule (carried forward, unchanged)

**File:** `app/Calendar/Export/OccurrenceIcs.php:54, 96`; `app/Jobs/OpsDrillJob.php:29`
**Issue:** These are unchanged since the previous review. The inputs are self-generated, so this is not exploitable, but it breaks the letter of the invariant.
**Fix:** Use `createFromFormat(...)`. In `lastModified()`, return `max($stamps)` with no format/parse round trip.

### IN-02: The calendar timezone is hard-coded beside `config('calendar.timezone')` (carried forward, slightly worsened)

**File:** `app/Support/LondonFormatter.php:13`, `app/Calendar/ManualRecurrence.php:31` (now also used by the new `localDate()` at `:235-237`), `app/Models/Event.php:70`, `app/Filament/Resources/Events/Tables/EventsTable.php:32`, `app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php:39`, `routes/console.php:17`, `app/Publishing/Data/PublishableEventData.php:41`
**Issue:** The two date checks now use different zones:

- `PublishableEventData::isRealDate()` (`:120`) validates in `$this->timezone`.
- `ManualRecurrence::localDate()` validates and encodes UNTIL in the hard-coded `Europe/London`.

So a DTO built with any other zone (the constructor accepts any string) would have its UNTIL computed in a different zone from its DTSTART. Separately, an invalid zone string is caught by the blanket `catch (Throwable)` and reported as "Invalid start date", which is misleading.
**Fix:** Use a single source of zone truth (a `CalendarZone::NAME` constant or config). Validate `timezone` explicitly in the DTO, or remove it as a constructor parameter.

### IN-03: Failed-job retention is duplicated; the config key is unused (carried forward, unchanged)

**File:** `routes/console.php:33`, `config/ops.php:25`
**Issue:** `--hours=720` is hard-coded, and `ops.failed_jobs_retention_hours` is never read.
**Fix:** `Schedule::command('queue:prune-failed', ['--hours' => (int) config('ops.failed_jobs_retention_hours')])->daily();`

### IN-04: The release directory name is published on every public page (carried forward, unchanged)

**File:** `resources/views/layouts/app.blade.php:6`
**Issue:** `<meta name="x-release" content="{{ basename(base_path()) }}">`.
**Fix:** Emit it to admins only, behind an env flag, or as a header on `/up`.

### IN-05: The occurrence generator hard-deletes future rows that carry admin-set status and audit data (carried forward; now reachable via WR-10)

**File:** `app/Calendar/OccurrenceGenerator.php:52-55`
**Issue:** The code is unchanged. WR-10 adds a silent trigger for this deletion: a title-only save of a non-round-tripping rule.
**Fix:** Mark orphaned future rows as hidden/orphaned instead of deleting them, or record this as an accepted exception.

### IN-06: SEQUENCE and LAST-MODIFIED change on every daily sync even when nothing changed (carried forward, unchanged)

**File:** `app/Calendar/OccurrenceGenerator.php:46-50`, `app/Calendar/Export/OccurrenceIcs.php:88-97`
**Issue:** Eloquent `upsert()` always bumps `updated_at`.
**Fix:** Upsert via `DB::table()` and supply `created_at` explicitly, or derive SEQUENCE from a content hash.

### IN-07: The Retry action ignores the `queue:retry` exit code (carried forward, unchanged)

**File:** `app/Filament/Pages/SystemHealth.php:151`
**Issue:** `forget()` (`:161`) checks the exit code but `retry()` does not.
**Fix:** `if (Artisan::call('queue:retry', ['id' => [$uuid]]) !== 0) { $this->notifyAlreadyHandled(); return; }`

### IN-08: Ops and CI hard-coding and supply-chain hygiene (carried forward, unchanged; line numbers moved)

**File:** `ops/check-event-page.sh:20`; `.github/workflows/ci.yml:43, 45, 54`
**Issue:** `UID_DOMAIN` is still hard-coded. The actions are still pinned to mutable tags (`@v7`, `@v2`), and the new matrix runs each of them twice.
**Fix:** `UID_DOMAIN="${UID_DOMAIN:-@whatsonattleborough.co.uk}"`, and pin the actions to commit SHAs.

### IN-09: `PublishableEventData` says it "mirrors the form's rules" but skips lengths, URL format and the equal-times rule (new)

**File:** `app/Publishing/Data/PublishableEventData.php:46-53, 67-130` vs `app/Filament/Resources/Events/Schemas/EventForm.php` (title max 255, description max 5000, price_text max 60, organiser max 255, `source_url` `->url()` max 2048, source_note max 500, end time ≠ start time on the same day)
**Issue:** The Phase 2 (non-Filament) path can pass these inputs, with these results:

| Input | Result |
|-------|--------|
| Title or organiser contact over 255 chars | Rejected only by PostgreSQL as a generic `QueryException` mid-transaction, with no clear message |
| A 50 KB description | Stored and rendered |
| `source_url = 'javascript:…'` | Stored; `SafeLinks` stops it rendering as a link, so not exploitable today |
| `end_time === start_time` | Becomes a 24-hour event (`EventTiming.php:64`), which the form refuses |

**Fix:** Add `mb_strlen` caps matching the form, `filter_var($sourceUrl, FILTER_VALIDATE_URL)` with an http/https scheme check, and the same-day equal-times refusal. Alternatively, correct the docblock to say which rules are form-only.

### IN-10: Asset tests and gates cannot tell the public stylesheet from the new admin theme (new)

**File:** `tests/Fixtures/public/build/manifest.json` (no `resources/css/filament/admin/theme.css` entry, no font assets); `ops/check-public-assets.sh:118-127`; `.github/workflows/ci.yml` size gate (`app-*.css` only)
**Issue:** Today the public layout cannot pull in the 680 KB Filament theme: `@vite` requests only `app.css`, and the real manifest gives it no imports. But nothing would catch a regression:

- the fixture manifest no longer matches the real build;
- `check-public-assets.sh` only asserts "exactly one stylesheet under `/build/assets/*.css`", which `theme-*.css` would also satisfy.

The new `AdminThemeTest` checks the wiring by string matching on `vite.config.js` and does not render a Vite tag.
**Fix:** Add the theme entry to the fixture manifest. Assert in `NoThirdPartyAssetsTest`/`HostHeaderAssetsTest` that `/` does not reference `theme-`. Make the ops script require that the stylesheet path matches `/build/assets/app-*.css`.

### IN-11: `forceRootUrl(app.url)` makes absolute links point at `localhost:8000` when the app is opened via another host; the provider comment overstates LAN support (new)

**File:** `app/Providers/AppServiceProvider.php:27-56`
**Issue:** The docblock says the root-relative Vite tags "still load when the owner opens the local app from a phone via the LAN IP". That is true for assets and for root-relative public links. But every `route()`/`url()` absolute URL is now pinned to `APP_URL=http://localhost:8000`, including Filament's post-login redirect, panel navigation, canonical/og/share links and any future signed URLs (whose signatures are validated against the real request URL). Opening the admin from a phone, or via a Herd `.test` host, therefore redirects to the device's own localhost. This is intended production behaviour; it only affects local development.
**Fix:** Correct the comment, or document "set APP_URL to the LAN/Herd URL when testing on another device" in README/.env.example.

---

_Reviewed: 2026-10-17T09:40:00Z_
_Reviewer: the agent (gsd-code-reviewer)_
_Depth: deep_
