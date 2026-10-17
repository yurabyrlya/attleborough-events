---
phase: quick-261017-gac
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - resources/views/calendar/partials/filter-chips.blade.php
  - tests/Feature/Public/PublicLookTest.php
  - app/Filament/Resources/Events/Pages/EditEvent.php
  - app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php
  - app/Filament/Resources/Events/Schemas/EventForm.php
  - tests/Feature/Filament/EditEventTest.php
  - tests/Feature/Filament/EditEventResilienceTest.php
  - app/Filament/Resources/Events/Actions/StatusActions.php
  - tests/Feature/Filament/StatusActionsTest.php
  - app/Filament/Pages/SystemHealth.php
  - tests/Feature/Ops/FailedJobsTest.php
  - docs/uat/browser-uat.md
autonomous: true
requirements: [CAL-06, ADM-03, OPS-04]

estimate:
  tokens: 60000
  raw_tokens: 60000
  tasks: 3
  confidence: low

must_haves:
  truths:
    - "On a 375x812 phone the first event day heading on / starts above the fold: only the When chips are open; Category and Show only sit inside a closed native <details> whose <summary> reads 'More filters'"
    - "Any active category, Free or Family-friendly filter renders that <details> with the open attribute server-side, so the active chip (aria-current + check tick) stays visible; a When-only filter leaves it closed"
    - "/ still has zero <script> elements and no <form>; every chip is still a plain root-relative query-string link; filtered URLs keep noindex, follow; the summary is at least 24x24px with the global visible focus outline"
    - "After Save on the event edit page the Occurrences table re-renders with the new times without a page reload (EditEvent dispatches a Livewire event the relation manager listens for); a refused save dispatches nothing"
    - "On the read-only edit page for a stored rule the form cannot round-trip (WR-09/WR-10), the disabled Repeats select shows \"Custom rule (can't be edited here)\" instead of 'Weekly on Friday', and an inline warning callout in the Repeats section shows the stored rule (escaped) alongside the existing notification; Save stays absent and all server-side refusals are unchanged"
    - "Every status confirmation dialog submits with the action's own name (Cancel date / Hide date / Restore date / Hide event / Show event / Cancel event / Reinstate event) and dismisses with 'Keep as is'; each per-date action shows a success notification"
    - "After Retry or Delete on System health the failed-jobs navigation badge updates without a reload (the page dispatches Filament's refresh-sidebar event)"
    - "Full suite green (504 existing tests plus the new ones) on PostgreSQL; pint and phpstan clean; docs/uat/browser-uat.md flows and known-issues baseline describe the new behaviour"
  artifacts:
    - path: resources/views/calendar/partials/filter-chips.blade.php
      provides: "When group always visible; Category + Show only groups inside <details> (open when $filters->hasAttributeFilters())"
    - path: app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php
      provides: "REFRESH_EVENT constant and a #[On(self::REFRESH_EVENT)] public no-op listener that re-renders the table"
    - path: app/Filament/Resources/Events/Pages/EditEvent.php
      provides: "afterSave() dispatches OccurrencesRelationManager::REFRESH_EVENT; unsupported-rule fill sets repeats to EventForm::CUSTOM_RULE_OPTION"
    - path: app/Filament/Resources/Events/Schemas/EventForm.php
      provides: "CUSTOM_RULE_OPTION constant, honest Repeats option, Callout in the Repeats section for non-editable stored rules"
    - path: app/Filament/Resources/Events/Actions/StatusActions.php
      provides: "modalSubmitActionLabel per action, modalCancelActionLabel('Keep as is')"
    - path: app/Filament/Pages/SystemHealth.php
      provides: "dispatch('refresh-sidebar') after Retry / Delete"
  key_links:
    - from: app/Filament/Resources/Events/Pages/EditEvent.php
      to: app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php
      via: "Livewire browser event OccurrencesRelationManager::REFRESH_EVENT"
      pattern: "REFRESH_EVENT"
    - from: app/Filament/Pages/SystemHealth.php
      to: vendor/filament/filament/src/Livewire/Sidebar.php
      via: "#[On('refresh-sidebar')] on Filament's Sidebar component re-renders navigation, recomputing SystemHealth::getNavigationBadge()"
      pattern: "refresh-sidebar"
    - from: app/Filament/Resources/Events/Schemas/EventForm.php
      to: app/Publishing/Actions/UpdatePublishedEvent.php
      via: "UpdatePublishedEvent::canEdit($record) is the single check deciding the custom-rule option and the callout"
      pattern: "canEdit"
---

<objective>
Fix the five owner-approved UX findings from the 2026-10-17 browser UAT walkthrough: (1) the public filter chips push the first event below the fold on a phone, (2) the Occurrences table on the event edit page stays stale after Save, (3) the read-only Repeats select misreports unsupported stored rules and the only warning is a dismissible toast, (4) status confirmation dialogs say "Cancel" / "Confirm" (where "Cancel" means keep the date), and (5) the System health failed-jobs badge stays stale after Retry / Delete.

Purpose: residents see events on the first screen of their phone; the admin's screens stop showing stale or misleading state.
Output: one Blade partial, five Filament classes and matching Pest tests changed; the browser UAT runbook updated so its flows and known-issues baseline stay accurate.

Out of scope (leave untouched, keep in the baseline table): branding/amber colour, the "Date key" column, 00:00 for all-day events, 24-hour admin times, the file path in Reason, narrow-table sideways scroll, the "Next dates" preview wording, the card category icon width.
</objective>

<execution_context>
@.github/gsd-core/workflows/execute-plan.md
@.github/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@AGENTS.md
@docs/uat/browser-uat.md
@.github/skills/tailwindcss-development/SKILL.md
@.github/skills/laravel-best-practices/SKILL.md
@.github/skills/testing-best-practices/SKILL.md

Interfaces the executor needs (verified in the codebase while planning; do not re-derive):

- `App\Calendar\CalendarFilters::hasAttributeFilters(): bool` returns `category !== null || free || family`. Use it as the single "open the disclosure" condition.
- `resources/views/calendar/partials/filter-chips.blade.php`: `<nav aria-label="Filter events" class="filter-chips ...">` with three `role="group"` divs labelled `chips-when`, `chips-category`, `chips-only`; each chip is `@include('calendar.partials.chip', [...])` (unchanged). `tests/Feature/Public/PublicLookTest.php` queries `nav[aria-label="Filter events"] a` and expects >10 chips and exactly 2 active on `/?category=music`, so chips must stay inside that `<nav>`. Helpers `lookDocument()`, `lookClasses()`, `lookText()` live in that test file.
- Global CSS (`resources/css/app.css`): `:focus-visible` outline for everything; `@utility tap-target` = min 2.75rem height/width, inline-flex.
- Filament 5.10.2 / Livewire 4.4.7. `Filament\Schemas\Components\Callout::make($heading)->description(...)->warning()` exists and escapes the description with `e()`. `Filament\Livewire\Sidebar` has `#[On('refresh-sidebar')] public function refresh(): void {}`. Livewire throws `EventHandlerDoesNotExist` when a test `->dispatch()`es an event the component does not listen for, so a dispatch-based test fails without the listener. `Livewire\Features\SupportEvents\SupportEvents::getListenerEventNames($component)` is public static.
- `PublishableEventData::fromEvent()` hydrates a non-round-tripping rule as `repeats = none`; `EventForm::repeatOptions()` drops "Does not repeat" for a series, so the disabled select falls back to the first option ("Weekly on Friday"). That is the bug in finding 3.
- `EditEvent`: `$recurrenceEditable` (Locked), `mutateFormDataBeforeFill()` sends the persistent warning, `beforeValidate()` and `handleRecordUpdate()` both call `refuseUnsupportedRule()` (Halt). `UpdatePublishedEvent::canEdit(Event)` is the single editability check; `UpdatePublishedEvent::UNSUPPORTED_RULE_REFUSED` is the warning text.
- `StatusActions::eventAction()` / `occurrenceAction()` build every status action (`requiresConfirmation()`, `modalHeading`, `modalDescription`, success notification via `run()`). Per-date success titles already exist ("Date cancelled", "Date hidden", "Date restored") and are asserted for hide/restore in `tests/Feature/Filament/StatusActionsTest.php`; the UAT did not see a toast in the browser, so this plan adds the missing cancel assertion and a browser check rather than new server code unless the browser check shows no toast.
- `SystemHealth::retry()` / `forget()` are private and end with a success notification; `notifyAlreadyHandled()` covers stale rows. `SystemHealth::getNavigationBadge()` is static and reads the live count.
- Test fixtures: `StatusActionsTest` has `$this->editPage`, `$this->occurrencesTable` (`livewire(OccurrencesRelationManager::class, ['ownerRecord' => $this->series, 'pageClass' => EditEvent::class])`); `EditEventResilienceTest` has dataset `unsupported rules` and const `UNSUPPORTED_RULE_WARNING`; `EditEventTest` saves via `livewire(EditEvent::class, ['record' => ...])->fillForm(['start_time' => '20:00'])`; `FailedJobsTest` calls `->callAction(TestAction::make('retry'|'delete')->table($failed))`.
</context>

<tasks>

<task type="tracer" tdd="true">
  <name>Task 1: Public "More filters" disclosure: When chips visible, Category and Show only collapsed (no JS)</name>
  <files>resources/views/calendar/partials/filter-chips.blade.php, tests/Feature/Public/PublicLookTest.php, docs/uat/browser-uat.md</files>
  <precondition>PostgreSQL container ae-pg is running (docker start ae-pg) so the attleborough_testing database is reachable.</precondition>
  <behavior>
    - GET / : inside nav[aria-label="Filter events"] there is exactly one details element, it has no open attribute, its summary text contains "More filters", the chips-when group is NOT a descendant of the details, and the chips-category and chips-only groups ARE descendants of it.
    - GET /?when=weekend : the details stays closed (a When filter alone never opens it).
    - GET /?category=music, /?free=1 and /?family=1 (dataset): the details has the open attribute and the matching chip has aria-current="true".
    - The summary carries the tap-target utility (24x24px minimum) and is not an anchor, so the existing chip count / active-chip test ("keys chip styles to aria-current...") still passes unchanged.
    - Existing CalendarFiltersTracerTest "renders no form and no script" and AttributeFiltersTest href tests pass unchanged.
  </behavior>
  <action>
RED first: add the behaviours above to tests/Feature/Public/PublicLookTest.php using its existing lookDocument()/lookText() helpers (one test for the closed default plus the When-only case, one dataset-driven test for the three open cases). Run them and see them fail.

GREEN: in resources/views/calendar/partials/filter-chips.blade.php keep the nav and the chips-when group exactly as they are. Wrap the chips-category and chips-only groups in a native details element (class "group", plus the open attribute rendered only when $filters->hasAttributeFilters() is true, via an inline @if ... @endif inside the tag, matching how chip.blade.php renders aria-current) with a summary as its first child. Summary content: the text "More filters" followed by a decorative chevron svg (aria-hidden="true", focusable="false", same stroke style as the chip tick) that rotates with group-open:rotate-180. Summary classes: tap-target, cursor-pointer, list-none, the Safari marker hidden via [&::-webkit-details-marker]:hidden, rounded-full border border-line px-4 text-ink hover:bg-accent-soft, so it reads as a sibling control of the chips and keeps the global :focus-visible outline (never remove the outline). Put the two groups in an inner div with mt-3 flex flex-col gap-4 so spacing matches the When group. Update the partial's docblock: chips stay plain anchors; the disclosure is native HTML so the page still loads no script; it is open server-side whenever a category, Free or Family-friendly filter is active so the active chip is never hidden.

Desktop decision (planner's call, record it in the SUMMARY): use the same closed-by-default disclosure at every width. Reason: one markup path, the When chips (the most-used filter) stay visible everywhere, and a CSS-only "always open on desktop" cannot reliably reveal closed details content, while doing it server-side would need user-agent sniffing.

Do not add any script, form, Livewire or JS; do not change chip.blade.php, CalendarFilters, routes or the noindex logic in calendar/index.blade.php. British English copy.

Docs: in docs/uat/browser-uat.md update F1 (expected: on 375x812 the first main h2 starts above 812px; Category and Show only sit under a closed "More filters" disclosure) and F2 (click the "More filters" summary before Community; after choosing Community, Free or Family-friendly the disclosure is rendered open with the active chip ticked; summary is a details/summary, not a link, and still no scripts). Remove the "Filter chips fill more than the first screen" row from the known-issues baseline; keep the card category icon row.

After GREEN run npm run build so the new Tailwind classes exist for the browser check.
  </action>
  <verify>
    <automated>php artisan test --compact tests/Feature/Public</automated>
    <fails_when>The details disclosure is missing, wraps the When group, is open on / or /?when=weekend, is closed when a category/free/family filter is active, chips leave the Filter events nav, or a script/form appears on / (the new PublicLookTest cases or the existing CalendarFiltersTracerTest/AttributeFiltersTest cases fail).</fails_when>
    <human-check>php artisan serve, open http://localhost:8000/ at 375x812 (light and dark): the first day heading is visible without scrolling; "More filters" opens/closes by click and keyboard (Enter/Space) with a visible focus ring; /?free=1 shows it open with Free ticked; document.scripts.length is 0.</human-check>
  </verify>
  <done>New PublicLookTest cases pass, all of tests/Feature/Public stays green, the partial renders a no-JS disclosure open exactly when an attribute filter is active, npm run build succeeds, F1/F2 and the baseline row in docs/uat/browser-uat.md are updated, committed as feat(quick-261017-gac): collapse category and show-only filters into a no-JS disclosure.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Event edit page: Occurrences table refreshes after Save; honest Repeats value and inline callout for unsupported rules</name>
  <files>app/Filament/Resources/Events/RelationManagers/OccurrencesRelationManager.php, app/Filament/Resources/Events/Pages/EditEvent.php, app/Filament/Resources/Events/Schemas/EventForm.php, tests/Feature/Filament/EditEventTest.php, tests/Feature/Filament/EditEventResilienceTest.php</files>
  <precondition>PostgreSQL container ae-pg is running (docker start ae-pg).</precondition>
  <behavior>
    - EditEventTest: saving a time change (fillForm start_time 20:00, call save) has no form errors and assertDispatched(OccurrencesRelationManager::REFRESH_EVENT).
    - EditEventTest: the Occurrences relation manager mounted for a series shows the old local time; after the event's time is changed through UpdatePublishedEvent (never a direct model write), ->dispatch(OccurrencesRelationManager::REFRESH_EVENT) succeeds and the component now shows the new time (Livewire throws EventHandlerDoesNotExist without the listener, so this fails before the fix).
    - EditEventResilienceTest (extend the existing dataset-driven "opens the edit page read-only" test, both unsupported rules): assertFormSet repeats = EventForm::CUSTOM_RULE_OPTION, assertSee "Custom rule (can't be edited here)", assertDontSee "Weekly on Friday", assertSee the callout heading and the raw stored rule string; Save still absent; repeats still disabled; the existing persistent notification is still sent.
    - EditEventResilienceTest forced-save test: additionally assertNotDispatched(OccurrencesRelationManager::REFRESH_EVENT) and the stored rule is unchanged (refusal path never reaches afterSave).
    - EditEventResilienceTest supported weekly series test: repeats still hydrates to weekly and the callout heading is NOT on the page.
  </behavior>
  <action>
RED first: write the behaviours above into the two test files and watch them fail.

Finding 2 (refresh): in OccurrencesRelationManager add a public string constant REFRESH_EVENT (e.g. 'event-occurrences-saved') and a public method refreshOccurrences(): void with an empty body and the Livewire\Attributes\On attribute keyed to self::REFRESH_EVENT; its only job is to make Livewire re-render the (lazy) table, which re-queries the rows (table records are cached per request only). Docblock: why it exists, and that it writes nothing. In EditEvent add protected function afterSave(): void that dispatches OccurrencesRelationManager::REFRESH_EVENT via $this->dispatch(). Filament only calls afterSave after a successful handleRecordUpdate, so the Halt refusal path never dispatches. Do not change the relation manager's read-only contract, columns or actions.

Finding 3 (honest read-only Repeats): in EventForm add public const CUSTOM_RULE_OPTION = 'custom' (a form-only display value; do NOT add a case to RepeatPattern and do not touch PublishableEventData). Add a private static helper hasUneditableRule(string $operation, mixed $record): bool that is true only on the edit operation for an Event with a non-null recurrence_rule where UpdatePublishedEvent::canEdit($record) is false (single editability check, so the form, EditEvent and the events table cannot disagree). In the repeats Select: when that helper is true the options closure returns only [CUSTOM_RULE_OPTION => "Custom rule (can't be edited here)"], and the helperText returns null (the callout carries the explanation). All other branches (create, one-off, editable series, WR-06 helper texts) stay exactly as they are. The existing rules() closure already ignores values RepeatPattern::tryFrom cannot parse; repeats() falls back to None so Ends/Last date/Next dates stay hidden for the custom value — keep it that way.

Add Filament\Schemas\Components\Callout as the first component of the Repeats section, visible only when hasUneditableRule() is true, columnSpanFull, warning(), heading "This repeat rule can't be edited here", description built as a plain string: "Stored rule: {recurrence_rule}. You can still hide or cancel the event with the buttons at the top of the page." Pass the rule as plain text only — never wrap it in HtmlString, Markdown or ->html(); Callout escapes the description (T-gac-02).

In EditEvent::mutateFormDataBeforeFill(), when $this->recurrenceEditable is false, set the returned data's repeats key to EventForm::CUSTOM_RULE_OPTION after PublishableEventData::fromEvent() so the disabled select shows the honest option. Keep the persistent notification, the form-wide disabled(), getFormActions() and both refuseUnsupportedRule() calls untouched (do not weaken any server-side refusal; UpdatePublishedEvent is not modified). Update EditEvent's class docblock (WR-09 paragraph) to mention the inline callout and the custom Repeats value.
  </action>
  <verify>
    <automated>php artisan test --compact tests/Feature/Filament/EditEventTest.php tests/Feature/Filament/EditEventResilienceTest.php tests/Feature/Filament/EditRepeatTypeTest.php tests/Feature/Filament/StatusActionsTest.php</automated>
    <fails_when>EditEvent does not dispatch REFRESH_EVENT after a successful save, dispatches it on a refused save, the relation manager has no listener (dispatch throws EventHandlerDoesNotExist) or does not show the new time, the read-only Repeats still shows "Weekly on Friday", the callout or raw rule is missing on the unsupported-rule page or appears on an editable series, or WR-06 one-off/series behaviour regresses.</fails_when>
  </verify>
  <done>All four Filament test files pass; Save on the edit page refreshes the Occurrences table without a reload; unsupported stored rules show "Custom rule (can't be edited here)" plus an inline warning callout with the escaped stored rule; server-side refusals unchanged. Committed as fix(quick-261017-gac): refresh occurrences after save and show unsupported repeat rules honestly.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Explicit dialog buttons and per-date notifications; live failed-jobs badge; runbook update</name>
  <files>app/Filament/Resources/Events/Actions/StatusActions.php, tests/Feature/Filament/StatusActionsTest.php, app/Filament/Pages/SystemHealth.php, tests/Feature/Ops/FailedJobsTest.php, docs/uat/browser-uat.md</files>
  <precondition>PostgreSQL container ae-pg is running (docker start ae-pg).</precondition>
  <behavior>
    - StatusActionsTest: mapping action name to getModalSubmitActionLabel() is exactly hide=>"Hide event", unhide=>"Show event", cancel=>"Cancel event", reinstate=>"Reinstate event" for StatusActions::forEvent(), and cancel=>"Cancel date", hide=>"Hide date", restore=>"Restore date" for forOccurrence(); every one has getModalCancelActionLabel() === "Keep as is"; confirmation is still required for all.
    - StatusActionsTest: calling the cancel record action on a scheduled row in the Occurrences table notifies "Date cancelled" and the row becomes Cancelled (hide/restore notifications are already asserted; keep them).
    - FailedJobsTest: Retry and Delete each assertDispatched('refresh-sidebar'); after deleting the only failed job SystemHealth::getNavigationBadge() returns null; the already-handled (stale row) path also dispatches refresh-sidebar.
  </behavior>
  <action>
RED first: add the behaviours above to the two test files and watch them fail.

Finding 4: in StatusActions add a required string parameter submitLabel to eventAction() and occurrenceAction() and chain ->modalSubmitActionLabel($submitLabel) and ->modalCancelActionLabel(self::KEEP_LABEL), with private const KEEP_LABEL = 'Keep as is'. Submit labels: event hide "Hide event", unhide "Show event", cancel "Cancel event", reinstate "Reinstate event"; occurrence cancel "Cancel date", hide "Hide date", restore "Restore date". Do not change action names, trigger labels, headings, descriptions, colours, visibility rules, success titles or the run() delegation. Update the class docblock: dialog buttons name the action and the dismiss button reads "Keep as is", so "Cancel" never means "keep" (D-05 plain copy). Per-date success notifications already exist ("Date cancelled" / "Date hidden" / "Date restored"); the UAT did not observe a toast, so during the human check below confirm the toast appears from the lazy Occurrences relation manager. Only if it does not appear, find and fix the root cause (record it in the SUMMARY); do not add duplicate notifications.

Finding 5: in SystemHealth add private const REFRESH_NAVIGATION_EVENT = 'refresh-sidebar' (Filament's Sidebar listener) and dispatch it via $this->dispatch() at the end of retry() and forget() on every path — success and already-handled — because the count may have changed either way. Keep the uuid re-read (T-01-48), logging and notifications exactly as they are. Chosen over a full-page redirect: it keeps the toast and table state, re-renders only the sidebar, and recomputes getNavigationBadge() from the database. Docblock line explaining this.

Runbook (docs/uat/browser-uat.md):
- F5: confirm with getByRole('button', { name: 'Cancel date' }) / 'Hide date' / 'Restore date'; the dismiss button is "Keep as is"; expect toasts "Date cancelled", "Date hidden", "Date restored"; replace the line that says to confirm with the Confirm button.
- F6: after Save the Occurrences table shows 20:00 without a reload (drop "Reload the page and check").
- F7: add dialog submit labels (Hide event / Show event / Cancel event / Reinstate event) and "Keep as is".
- F8: expect the disabled Repeats select to read "Custom rule (can't be edited here)" and an inline warning callout "This repeat rule can't be edited here" showing the stored rule, in addition to the notification.
- F10: after Retry/Delete the "System health" badge drops/disappears without a reload.
- Baseline table: remove the rows for the Occurrences refresh, the Repeats misreport, the dismissible-only warning and the Cancel/Confirm dialog; trim the Occurrences row to the "Date key" column only and the System health row to "Reason shows the full server file path" only. Keep every out-of-scope row. Add one sentence under the table heading noting the five items fixed on 2026-10-17 in quick task 261017-gac.

Finally run vendor/bin/pint --dirty --format agent, vendor/bin/phpstan analyse --memory-limit=1G and the full suite.
  </action>
  <verify>
    <automated>php artisan test --compact tests/Feature/Filament/StatusActionsTest.php tests/Feature/Ops/FailedJobsTest.php && vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G --no-progress && php artisan test --compact</automated>
    <fails_when>Any status dialog keeps the default Confirm/Cancel labels or a wrong action name, the cancel-date action does not notify "Date cancelled", Retry/Delete (or the already-handled path) does not dispatch refresh-sidebar, the badge does not clear after deleting the last failed job, pint or phpstan reports a problem, or any of the 504 existing tests regress.</fails_when>
    <human-check>In the admin: "Cancel this date?" shows buttons "Cancel date" and "Keep as is" and a "Date cancelled" toast appears after submitting; with 2 failed jobs (php artisan ops:drill --fail-for=60 twice) Delete one and the System health badge drops from 2 to 1 without reloading; on a series edit page change the start time, Save, and the Occurrences table shows the new time without reloading; set a UAT event's rule to FREQ=DAILY (F8) and see the custom Repeats value and inline callout, then restore the rule.</human-check>
  </verify>
  <done>Dialog labels explicit and tested, per-date toasts confirmed, badge refreshes live and tested, runbook flows and baseline updated, pint/phpstan clean, full suite green. Committed as fix(quick-261017-gac): explicit status dialog buttons and live failed-jobs badge.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser -> public GET / | Untrusted query string decides which chips are active and whether the disclosure renders open |
| admin browser -> Livewire (EditEvent, OccurrencesRelationManager, SystemHealth) | Livewire payloads and dispatched events can be forged even when buttons are hidden |
| stored data -> admin HTML | recurrence_rule may come from future imports (Phase 3) and is now rendered in the edit form |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-gac-01 | Tampering | EditEvent read-only path (custom Repeats value) | high | mitigate | beforeValidate() and handleRecordUpdate() refusals and UpdatePublishedEvent::canEdit() are untouched; the custom value is display-only and never reaches PublishableEventData::fromForm(); the forced-save test asserts refusal, unchanged rule and no REFRESH_EVENT dispatch |
| T-gac-02 | Tampering (stored XSS) | EventForm Repeats Callout showing recurrence_rule | medium | mitigate | Rule passed as a plain string to Callout::description(), which escapes with e(); never HtmlString/Markdown/->html(); test asserts the raw rule text renders as text |
| T-gac-03 | Elevation of Privilege | OccurrencesRelationManager::refreshOccurrences listener | low | accept | Empty body, writes nothing, only re-renders rows of the owner record; the component sits behind the panel auth gate (AdminGate) like the rest of the page |
| T-gac-04 | Tampering | filter-chips disclosure open state | low | mitigate | Open state derives only from CalendarFilters::hasAttributeFilters() on already-validated values; no raw query value is echoed; hrefs stay root-relative (T-01-41) |
| T-gac-05 | Denial of Service | SystemHealth refresh-sidebar after each action | low | accept | One extra count query per admin action on a single-admin panel |
| T-gac-06 | Repudiation | Status dialogs | low | accept | Explicit submit labels reduce mistaken status changes; status audit (status_changed_at) unchanged |
| T-gac-SC | Tampering | npm/composer installs | low | accept | No package installs or version changes in this plan; existing lockfiles untouched |
</threat_model>

<verification>
- php artisan test --compact (full suite, PostgreSQL) green: 504 existing tests plus the new cases.
- vendor/bin/pint --dirty --format agent leaves no changes to commit (project rule: never pint --test) and vendor/bin/phpstan analyse --memory-limit=1G is clean.
- npm run build succeeds; curl -s http://127.0.0.1:8000/ | grep -c '<script' prints 0 with the server running.
- Browser human-checks from Tasks 1 and 3 done locally (F1, F2, F5, F6, F8, F10 of docs/uat/browser-uat.md); results noted in the SUMMARY.
- No deploy; local only.
</verification>

<success_criteria>
- First event day heading on / is above the fold at 375x812 with no JavaScript; active attribute filters stay visible via a server-rendered open disclosure.
- Occurrences table, Repeats display, dialog buttons, per-date toasts and failed-jobs badge all reflect the true current state without a page reload or misleading labels.
- Server-side refusals for unsupported rules are unchanged and still tested.
- docs/uat/browser-uat.md flows and baseline match the new behaviour; out-of-scope baseline rows untouched.
</success_criteria>

<output>
Create `.planning/quick/261017-gac-fix-top-ux-findings-from-browser-uat-mob/261017-gac-SUMMARY.md` when done (include the desktop disclosure decision and the per-date toast browser finding).
</output>
