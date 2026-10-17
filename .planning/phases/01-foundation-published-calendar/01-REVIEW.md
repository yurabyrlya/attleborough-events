---
phase: 01-foundation-published-calendar
reviewed: 2026-10-17T13:31:50+01:00
depth: standard
review_kind: incremental
diff_base: f332b0a
files_reviewed: 34
files_reviewed_list:
  - app/Calendar/CalendarFilters.php
  - app/Calendar/ManualRecurrence.php
  - app/Filament/Pages/SystemHealth.php
  - app/Filament/Resources/Events/Actions/StatusActions.php
  - app/Filament/Resources/Events/Pages/EditEvent.php
  - app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php
  - app/Filament/Resources/Events/Schemas/EventForm.php
  - app/Filament/Resources/Events/Tables/EventsTable.php
  - app/Models/EventOccurrence.php
  - app/Publishing/Actions/UpdatePublishedEvent.php
  - app/Publishing/Data/PublishableEventData.php
  - resources/views/calendar/partials/filter-chips.blade.php
  - resources/views/calendar/partials/remove-chip.blade.php
  - resources/views/errors/404.blade.php
  - resources/views/errors/500.blade.php
  - resources/views/errors/503.blade.php
  - resources/views/errors/layout.blade.php
  - resources/views/events/partials/dates.blade.php
  - tests/Browser/AdminCreateEventTest.php
  - tests/Browser/AdminEditEventTest.php
  - tests/Browser/EventPageTest.php
  - tests/Browser/PublicCalendarTest.php
  - tests/Browser/SystemHealthTest.php
  - tests/Browser/UatFixtures.php
  - tests/Feature/Filament/EditEventResilienceTest.php
  - tests/Feature/Filament/EditEventTest.php
  - tests/Feature/Filament/StatusActionsTest.php
  - tests/Feature/Ops/FailedJobsTest.php
  - tests/Feature/Public/ErrorPagesTest.php
  - tests/Feature/Public/EventCalendarAndShareTest.php
  - tests/Feature/Public/PublicLookTest.php
  - tests/Feature/Publishing/ChangeOccurrenceStatusTest.php
  - tests/Pest.php
  - tests/Unit/Calendar/ManualRecurrenceTest.php
findings:
  critical: 0
  warning: 4
  info: 5
  total: 9
status: issues_found
---

# Phase 01: Code Review Report (incremental re-review)

**Reviewed:** 2026-10-17T13:31:50+01:00
**Depth:** standard
**Files Reviewed:** 34
**Status:** issues_found
**Scope:** `git diff f332b0a HEAD` for the 34 listed files. This is an **incremental** re-review. The previous full review (CR-01, WR-01..WR-11, IN-01..IN-11) is still in git at commit `f332b0a`, and its findings are tracked in `01-REVIEW-DISPOSITION.md`. New finding IDs continue from those numbers (WR-12+, IN-12+), so no ID is reused.

## Summary

The delta has four parts: the WR-10/WR-11 fixes, quick task 261017-gac (filters disclosure, Occurrences refresh, honest Repeats display, explicit dialog buttons, live failed-jobs badge), quick task 261017-hp9 (branded error pages, remove-chips, date rows), and the Pest browser flows F0–F10 with `Uat::settleFields()`.

What I checked and found sound:
- **WR-10 round-trip.** `ManualRecurrence::tryFromStoredRule()` parses the rule, checks the pattern fits the start date, rebuilds it and requires an exact string match. `PublishableEventData::editableRecurrence()` is the single check behind `UpdatePublishedEvent::canEdit()`, `fromEvent()`, `EventForm::hasUneditableRule()` and the events-table label. `UpdatePublishedEvent` re-checks it on the locked row before writing. I traced the timed and all-day UNTIL forms, zero-padded COUNT, empty-string rules and DST-day UNTIL values. Every non-emitted shape is refused, and nothing reached a write.
- **WR-11.** `status` was removed from `EventOccurrence` fillable. No app code mass-assigns it. Tests use `forceCreate` or factories, which are unguarded.
- **Escaping and injection.** The Callout description is rendered with `{{ }}` in `filament/support`'s `callout.blade.php`, and a test covers it. The new Blade only uses `{{ }}`. Chip hrefs are root-relative and built from `CalendarFilters::queryWith()`, which only passes through whitelisted keys.
- **CR-01 not regressed.** The error layout's `@vite` goes through the same `Vite::createAssetPathsUsing` root-relative resolver. `php artisan down --render` pre-renders in console with the same provider. If a 500 view throws, Laravel's `renderHttpException` catches it and falls back to the generic page, so the manifest guard does not create an unrenderable 500.
- **Refresh events.** `refresh-sidebar` is the event `Filament\Livewire\Sidebar` listens for (v5.10.2). The panel uses sidebar navigation, not `topNavigation()`. `afterSave()` only runs after a successful `handleRecordUpdate()`.

The remaining issues are about quality and test reliability, not correctness:
- The live badge depends on an undocumented Filament event, and no test shows the badge actually changes.
- The browser fill-settle helper has an off-by-one and is only used in F0.
- The read-only "Custom rule" form still shows a one-off-only field and hides the series' end.
- A few info-level gaps.

There are no blockers.

## Warnings

### WR-12: The "live" failed-jobs badge depends on an undocumented Filament event, and no test shows the badge changing

**File:** `app/Filament/Pages/SystemHealth.php:43, 162, 178, 205-211`. Tests: `tests/Feature/Ops/FailedJobsTest.php:62, 85, 105`, `tests/Browser/SystemHealthTest.php:47-60`
**Issue:** The gac deliverable "live failed-jobs badge" is implemented by dispatching the string `'refresh-sidebar'`. That event name is an internal `#[On]` listener on `Filament\Livewire\Sidebar`. It is not public API, and Filament can rename it in a minor release. There is also a sibling `refresh-topbar` for `topNavigation()` panels, which this code ignores.

The tests do not prove the feature works end to end:
- The feature tests only assert `assertDispatched('refresh-sidebar')`. That passes even if no component listens for the event, or if the re-rendered sidebar shows a cached count.
- The F10 browser flow asserts the badge shows `2` before any action. After Retry and Delete it never checks that the badge went to `1` and then disappeared.

So a Filament upgrade, or a switch to top navigation, would silently bring back the stale badge that quick task gac fixed, and CI would stay green.
**Fix:** Assert the observable result, and dispatch both events (or wrap the name in one documented helper):
```php
// SystemHealthTest.php (F10), after the Retry confirmation:
$page->waitForText('Sent back to the queue')
    ->assertSeeIn('a:has-text("System health") .fi-badge', '1');
// ...after Delete:
$page->waitForText('No failed jobs')
    ->assertNotPresent('a:has-text("System health") .fi-badge');

// SystemHealth.php
private function refreshNavigationBadge(): void
{
    // Filament internals: Sidebar listens for refresh-sidebar, Topbar for refresh-topbar.
    $this->dispatch('refresh-sidebar');
    $this->dispatch('refresh-topbar');
}
```
You could also add a feature test that mounts `Filament\Livewire\Sidebar`, deletes the failed row, dispatches `refresh-sidebar`, and asserts the badge text is gone. That pins the coupling to the Filament version.

### WR-13: `Uat::settleFields()` never checks its last re-fill, so it can throw even when the field settled

**File:** `tests/Browser/UatFixtures.php:97-118`
**Issue:** Each loop iteration does three things: wait, read the values, and re-fill any stale inputs. On the last iteration (`$attempt === $attempts - 1`) the helper re-fills the stale inputs and then leaves the loop and throws `RuntimeException('Livewire kept resetting: …')` without reading them again. A re-fill that worked on the 5th try is reported as a failure. With 5 attempts, only 4 re-fills are ever verified. The helper exists to remove flakiness (commit 26e3f7d), so this off-by-one adds it back in the slow-CI case it targets. Also, `$stale` is only defined inside the loop: with `$attempts <= 0` the `throw` line reads an undefined variable, and `implode()` gets `null`, which is a `TypeError`.
**Fix:**
```php
public static function settleFields(mixed $page, array $valuesById, int $attempts = 5): mixed
{
    $stale = $valuesById;

    for ($attempt = 0; $attempt <= $attempts; $attempt++) {
        $page->wait(0.2);

        $stale = array_filter($valuesById, fn (string $value, string $id): bool =>
            $page->script('() => document.getElementById('.json_encode($id).')?.value') !== $value,
            ARRAY_FILTER_USE_BOTH);

        if ($stale === []) {
            return $page;
        }

        if ($attempt < $attempts) {
            foreach ($stale as $id => $value) {
                $page->fill('[id="'.$id.'"]', $value);
            }
        }
    }

    throw new RuntimeException('Livewire kept resetting: '.implode(', ', array_keys($stale)));
}
```

### WR-14: The Livewire fill race was fixed in F0 only; F3 and F6 still fill without settling

**File:** `tests/Browser/AdminCreateEventTest.php:63-67, 72-74, 94-96`, `tests/Browser/AdminEditEventTest.php:119`
**Issue:** Commit 26e3f7d found the cause of the F0 flake: about 10ms after Playwright fills an input, a pending wire:model effect (`_x_forceModelUpdate`) can write the stale server value back. Nothing about that is specific to the login form. Any Filament form filled just after Livewire boots, or just after a round trip, has the same exposure. Two flows still fill with no settle:
- F3 fills `Title` and `Description` straight after `visit('/admin/events/create')`. It also fills start/end time right after the date-picker round trip, and `price_text`, `organiser_name` and `organiser_contact` right after the venue select's round trip.
- F6 fills `form.start_time` right after a `confirmAction` round trip.

A reset there makes the save fail validation, or save a different value. F4's `->sole()` lookup by title and the `storedOccurrences` assertions would then fail intermittently. The flake was fixed in one test, not in the pattern.
**Fix:** Route every Filament form fill through the helper, for example a `Uat::fillSettled($page, ['form.title' => 'UAT Quiz Night', 'form.description' => '…'])` that fills and then calls `settleFields()`. At least run `settleFields()` over all the F3 fields before clicking Create, and over `form.start_time` in F6 before "Save changes".

### WR-15: The read-only "Custom rule" form shows a field that only applies to one-off events and hides the series' end

**File:** `app/Filament/Resources/Events/Pages/EditEvent.php:63`, `app/Filament/Resources/Events/Schemas/EventForm.php:84-90, 156-165, 187-192, 304-313`
**Issue:** For an uneditable rule, `mutateFormDataBeforeFill()` sets `repeats` to `EventForm::CUSTOM_RULE_OPTION` (`'custom'`). That is not a `RepeatPattern`, so `EventForm::repeats()` maps it to `RepeatPattern::None` (`tryFrom(...) ?? None`). Every visibility rule in the form then treats the series as a one-off:
- **End date** shows for a repeating event, empty and with the helper "Only for events that run over several days". Its `hidden()` is `repeats !== None`.
- **Ends**, **Last date**, **Number of times** and **Next dates** are all hidden. Their `visible()` is `repeats !== None`.

The gac goal was an "honest Repeats display". Instead, the admin sees one-off fields and gets no plain-English end date or count. The only signal is the raw RRULE in the Callout (for example `UNTIL=20270101T120000Z`), which the single non-technical admin cannot read. Nothing is written, because the form is disabled and the save is refused twice. It is still a misleading view of a live series.
**Fix:** Treat the custom value as "repeating" for visibility, and hide fields that cannot be shown truthfully:
```php
private static function isCustomRule(Get $get): bool
{
    return $get('repeats') === self::CUSTOM_RULE_OPTION;
}

// end_date
->hidden(fn (Get $get): bool => self::isCustomRule($get) || self::repeats($get) !== RepeatPattern::None)
```
Ideally, also show the next stored dates in the Callout. `RecurrenceExpander::instanceDates()` already accepts the stored `Event`, and the Occurrences table on the same page has them. That way the admin can see what the rule does without decoding the RRULE.

## Info

### IN-12: Only 404, 500 and 503 are branded; other errors still show the framework's unbranded pages

**File:** `resources/views/errors/` (only `404`, `500`, `503`, `layout`)
**Issue:** Laravel looks up `errors::{code}` first, then `errors::{4xx|5xx}`, then its own views. These cases still get unbranded pages with no "See what's on" link:
- `403`: a signed-in non-admin hitting `/admin`.
- `401`, `419`, `429`: the framework's `errors::minimal` pages.
- `405`: for example `POST /`, since there is no `4xx` fallback. This gets Symfony's generic "Whoops" page.

Also, `resources/views/errors/layout.blade.php` now shadows the framework's `errors::layout`. That is harmless today because every framework error view extends `errors::minimal`, but a name like `errors/branded.blade.php` would avoid the collision.
**Fix:** Add `resources/views/errors/4xx.blade.php` and `5xx.blade.php` that extend `errors.layout` with generic copy, and extend `ErrorPagesTest` to cover `403` and `405`.

### IN-13: The round-trip check is repeated per render, and the events table re-derives the start date

**File:** `app/Filament/Resources/Events/Tables/EventsTable.php:70-76`, `app/Filament/Resources/Events/Schemas/EventForm.php:119-134, 296-302`
**Issue:**
- `repeatsLabel()` calls `PublishableEventData::editableRecurrence()`, which builds the London start date, and then builds the same `CarbonImmutable` again for `label()`.
- `hasUneditableRule()` runs the full `canEdit()` (regex parse, options and rebuild) separately in the Callout `visible()`, the Select `options()` and the Select `helperText()`. It also repeats the `recurrence_rule !== null` test that `canEdit()` already does.

The single-check intent is good. This is just duplicated work and duplicated date logic that could drift.
**Fix:** Add a `PublishableEventData::repeatsLabel(Event): ?string`, or have `editableRecurrence()` return the start date too. Have `hasUneditableRule()` read the page's locked `$recurrenceEditable` where it is available.

### IN-14: The F3 submit click targets an auto-generated Filament id

**File:** `tests/Browser/AdminCreateEventTest.php:97`
**Issue:** `->click('[id="key-bindings-1"]')` relies on the order in which Filament generates key-binding ids. Adding any action with a keyboard shortcut, or upgrading Filament, can point it at another button or break it. Every other click in the suite uses the accessible-name selectors in `Uat::role()`.
**Fix:** `->click(Uat::role('button', 'Create'))`.

### IN-15: The "first result above the fold" check measures the day heading, not the first event

**File:** `tests/Browser/PublicCalendarTest.php:151-159`
**Issue:** The test title promises the first result is above the fold, but it checks that the top of the first `main h2` (the day heading) is below 812px. A heading at y=800 passes even when the whole first card is off-screen. That card is what the UI-review fix 2 was meant to keep visible.
**Fix:** Measure the first card instead:
`document.querySelector('main article h3').getBoundingClientRect().bottom < 812`.

### IN-16: The branded 500 page is only tested as a view render, not through the exception handler

**File:** `tests/Feature/Public/ErrorPagesTest.php:65-80`
**Issue:** The 500 and 503 tests call `view('errors.500', ['exception' => …])->render()` directly. In production, `Handler::prepareResponse()` wraps the original exception in `HttpException(500, $e->getMessage(), $e)` and passes that to the view. Nothing tests that real route (debug off, an uncaught exception, the branded page, no message), so a later edit that echoes `$exception->getMessage()` would only be caught by the direct-render assertion's fake exception.
**Fix:** Add a test-only route that throws `new RuntimeException('SQLSTATE secret')`. Call it with `config(['app.debug' => false])` and assert the 500 status, the branded heading, and that `SQLSTATE` is missing from the HTML.

---

_Reviewed: 2026-10-17T13:31:50+01:00_
_Reviewer: the agent (gsd-code-reviewer)_
_Depth: standard (incremental, diff base f332b0a)_
