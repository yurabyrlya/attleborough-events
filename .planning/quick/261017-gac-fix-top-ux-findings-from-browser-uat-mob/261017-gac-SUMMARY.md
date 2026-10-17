---
phase: quick-261017-gac
plan: 01
subsystem: ui
tags: [blade, tailwind, filament, livewire, ux, uat]
status: complete

requires:
  - phase: 01-foundation-published-calendar
    provides: public filter chips, EditEvent/EventForm, StatusActions, OccurrencesRelationManager, SystemHealth
provides:
  - No-JS "More filters" disclosure on the public calendar (When chips always visible)
  - Occurrences table re-renders after a successful Save (Livewire event)
  - Honest read-only Repeats value plus inline warning callout for unsupported stored rules
  - Explicit status dialog buttons ("Cancel date" / "Keep as is", etc.)
  - Live failed-jobs navigation badge after Retry / Delete
affects: [browser-uat, phase-1-verification]

actuals:
  tokens: 10400
  tasks: 3
  commits: 3
plan_head_before: f7a661ccf35a23c89ac53f81d02ded56f4451c32
plan_head_after: 25265bc9b0c3fc84202f5e1411aa4381b7b000c2

tech-stack:
  added: []
  patterns:
    - "Native <details>/<summary> for progressive disclosure on public pages, opened server-side from validated filter state"
    - "Livewire browser event constant on the listening component (OccurrencesRelationManager::REFRESH_EVENT) dispatched from the page's afterSave()"
    - "Filament's refresh-sidebar event to recompute navigation badges after in-page actions"

key-files:
  created: []
  modified:
    - resources/views/calendar/partials/filter-chips.blade.php
    - app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php
    - app/Filament/Resources/Events/Pages/EditEvent.php
    - app/Filament/Resources/Events/Schemas/EventForm.php
    - app/Filament/Resources/Events/Actions/StatusActions.php
    - app/Filament/Pages/SystemHealth.php
    - tests/Feature/Public/PublicLookTest.php
    - tests/Feature/Filament/EditEventTest.php
    - tests/Feature/Filament/EditEventResilienceTest.php
    - tests/Feature/Filament/StatusActionsTest.php
    - tests/Feature/Ops/FailedJobsTest.php
    - docs/uat/browser-uat.md

key-decisions:
  - "The More filters disclosure is closed by default at every width, desktop included: one markup path, When chips stay visible everywhere, and CSS can't reliably reveal closed <details> content, while doing it server-side would need user-agent sniffing"
  - "The custom Repeats value is display-only (EventForm::CUSTOM_RULE_OPTION = 'custom'), not a RepeatPattern case; UpdatePublishedEvent::canEdit() stays the single editability check"
  - "Badge refresh uses Filament's refresh-sidebar event instead of a page redirect, so the toast and table state survive"

patterns-established:
  - "Status dialogs: the submit button names the action and the dismiss button reads 'Keep as is'"

requirements-completed: [CAL-06, ADM-03, OPS-04]

duration: 14min
completed: 2026-10-17
---

# Quick 261017-gac: Fix top UX findings from browser UAT Summary

**On a phone the first event is now on the first screen, thanks to a no-JS "More filters" disclosure. Admin screens no longer show stale or misleading state: the Occurrences table refreshes after Save, unsupported repeat rules say "Custom rule" and show an inline callout, status dialogs have explicit buttons, and the failed-jobs badge updates live.**

## Performance

- **Duration:** about 14 min
- **Started:** 2026-10-17T11:51+01:00
- **Completed:** 2026-10-17T12:05+01:00
- **Tasks:** 3/3
- **Files modified:** 12

## Accomplishments

- **Public filters (CAL-06):** the When chips are always visible. Category and Show only sit in a native `<details>` ("More filters", a 44px-tall summary with the global focus ring and a chevron that rotates when open). It renders open server-side when `CalendarFilters::hasAttributeFilters()` is true. Still zero scripts and no forms.
- **Occurrences refresh (ADM-03):** `OccurrencesRelationManager::REFRESH_EVENT` has a no-op `#[On]` listener. `EditEvent::afterSave()` dispatches the event, and a refused save never reaches that point.
- **Honest read-only Repeats (ADM-03):** for a stored rule the form can't edit, the disabled select shows "Custom rule (can't be edited here)". An escaped warning `Callout` ("This repeat rule can't be edited here" / "Stored rule: …") now sits in the Repeats section. The persistent notification and every server-side refusal are unchanged.
- **Status dialogs (ADM-03):** the submit buttons read Hide event / Show event / Cancel event / Reinstate event / Cancel date / Hide date / Restore date. Every dismiss button reads "Keep as is".
- **Failed-jobs badge (OPS-04):** `SystemHealth` dispatches `refresh-sidebar` on every Retry/Delete path, including the already-handled path.
- **Runbook:** `docs/uat/browser-uat.md` F1, F2, F5, F6, F7, F8 and F10 now describe the new behaviour. Five rows were removed or trimmed in the baseline table, and every out-of-scope row is kept.

## Task Commits

1. **Task 1 (tracer): no-JS More filters disclosure:** `a9b3508` (feat)
2. **Task 2: Occurrences refresh and honest Repeats:** `2b2d3c7` (fix)
3. **Task 3: dialog buttons, live badge, runbook:** `25265bc` (fix)

Each task followed RED → GREEN, with the failing tests written and run before the implementation. Each task is one commit: test and implementation together, as the plan specified.

## Browser checks (headless Chrome via CDP against the local app on :8000)

- **F1 (tracer gate):** at 375×812 in both light and dark the first `main h2` ("Today") starts at **369px** (it was 828px). The summary is 135×44px, Enter opens it, the focus outline is solid 2px, there is no horizontal overflow and `document.scripts.length` is 0. `/?free=1` renders open; `/` and `/?when=weekend` render closed. On desktop (1280) the heading is at 317px.
- **F5:** the "Cancel this date?" buttons are ["Keep as is", "Cancel date"] and the **"Date cancelled" toast appears**. Restore shows ["Keep as is", "Restore date"] and the "Date restored" toast. No page reload.
- **Per-date toast finding:** the server already sent these toasts, and they render from the lazy relation manager. The UAT probably missed them (they are short-lived), so no server change was needed. I added the cancel-date assertion.
- **F6:** after Save at 20:30 (and again back at 20:00) the Occurrences cells showed the new time without a reload.
- **F8:** with `FREQ=DAILY` the Repeats select read "Custom rule (can't be edited here)" and was disabled, the callout showed "Stored rule: FREQ=DAILY.", the page never showed "Weekly on Friday", and there was no Save button. The rule was restored afterwards.
- **F10:** two drill failures gave badge "2", then "1" after the first Delete, then no badge after the second. "No failed jobs", no reload.
- **Cleanup:** UAT Quiz Night is back to its original state (rule, 20:00, 30 Oct scheduled, 25 Dec still cancelled) and `failed=0`. Logging in used a temporary database session minted through tinker, deleted afterwards; the admin password was not touched. Nothing I started is still running, and the owner's `php artisan serve` was left as is.

## Verification

- `php artisan test --compact --testsuite=Unit,Feature`: **514 passed** (504 baseline plus 10 new) on PostgreSQL.
- `vendor/bin/pint --dirty --format agent`: passed. `vendor/bin/phpstan analyse --memory-limit=1G`: 0 errors.
- `npm run build` succeeded. `curl -s http://127.0.0.1:8000/ | grep -c '<script'` → 0.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Security] Added an escape test for the stored-rule callout**
- **Found during:** Task 2
- **Issue:** T-gac-02 (stored XSS via `recurrence_rule` in the callout) needed a test that actually proves escaping, not just that the rule text appears.
- **Fix:** a new test stores a rule containing `<script>…</script>` and asserts that the escaped HTML is rendered and the raw tag is not.
- **Files modified:** tests/Feature/Filament/EditEventResilienceTest.php
- **Commit:** 2b2d3c7

No other deviations. The full suite ran with `--testsuite=Unit,Feature` because a concurrent session has an uncommitted `Browser` testsuite in `phpunit.xml` (see Notes). The committed `phpunit.xml` defines only Unit and Feature, so this matches `php artisan test` on HEAD.

## Notes / Out-of-scope observations

- **Concurrent session:** while I worked, another session left uncommitted changes in the working tree: `composer.json`/`composer.lock`, `package.json`/`package-lock.json`, `phpunit.xml`, `tests/Pest.php` and untracked `tests/Browser/`, all for `pest-plugin-browser`. I did not stage or modify any of them. Two full-suite runs hit transient "relation categories does not exist" errors in tests that changed between runs (the shared `attleborough_testing` DB). A re-run after a wait was fully green.
- The System health Retry/Delete dialogs still use Filament's default "Cancel" / "Confirm". This is outside this plan's scope (finding 4 covered status dialogs only); it may be worth a follow-up so the copy is consistent.

## Known Stubs

None.

## Threat Flags

None. No new endpoints or trust-boundary surface. The new Livewire listener is a no-op behind the panel gate (T-gac-03, accepted).

## Self-Check: PASSED

- FOUND: all 12 modified files exist
- FOUND: a9b3508, 2b2d3c7, 25265bc are ancestors of HEAD
