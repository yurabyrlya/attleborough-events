---
status: testing
phase: 01-foundation-published-calendar
source: [01-VERIFICATION.md]
started: 2026-10-17T10:06:11Z
updated: 2026-10-17T10:06:11Z
---

## Current Test

number: 1
name: Phone / mobile look (01-07)
expected: |
  Wordmark, Atkinson font, Norfolk green, AA-readable text, thumb-sized chips with a tick on the active one, category icons, readable 'Cancelled', green calendar-pin home icon, 'Skip to main content' first Tab stop, focus rings, no sideways scroll
awaiting: user response

## Tests

### 1. Phone / mobile look (01-07): `npm run build`, `php artisan serve --host 0.0.0.0 --port 8000`, open on a phone or in DevTools emulation in light and dark themes; tap This weekend / a category / Free; add to home screen; Tab through; 320px width with a long unbroken title
expected: Wordmark, Atkinson font, Norfolk green, AA-readable text, thumb-sized chips with a tick on the active one, category icons, readable 'Cancelled', green calendar-pin home icon, 'Skip to main content' first Tab stop, focus rings, no sideways scroll
why_human: Colour, touch and reflow on a real screen
result: [pending]

### 2. Browse with JavaScript disabled: use every chip and 'More events'
expected: The same filtered lists, no dead controls
why_human: Real browser behaviour; server tests prove only the markup
result: [pending]

### 3. Filament recurring-series walkthrough (01-04, updated by 01-13): create a monthly last-Friday series, check the 'Next dates' preview, save, edit only the time. Then open a one-off event's edit page and a series' edit page and read the Repeats field. Also check the events list's Repeats column
expected: Preview in UK format, occurrences on /, time change keeps dates and statuses. One-off: Repeats disabled at 'Does not repeat' with helper text about hiding and creating a new repeating event (new web address). Series: no 'Does not repeat' option, and helper text explains how to end or hide it. The app-created series stays editable (Save present, no warning) and the list says e.g. 'Monthly on the last Friday'
why_human: Filament UI flow and wording
result: [pending]

### 4. Filament status walkthrough (01-05 + 01-13 table actions): cancel and hide single dates, then hide / show / cancel / reinstate the whole event from the edit page AND from the events table row actions, watching /
expected: Confirmations, correct badges, hidden items gone, per-date statuses kept. No delete action anywhere
why_human: UI flow
result: [pending]

### 5. Unsupported / non-round-tripping rule edit page (01-13 WR-09 + 01-15 WR-10): in tinker set one event's recurrence_rule to 'FREQ=DAILY' and another Friday-start event's to 'FREQ=WEEKLY;BYDAY=MO'. Open both edit pages and the events list
expected: Both pages open with the persistent warning "This event's repeat rule can't be edited here. You can still hide or cancel it.", no Save button, and Hide / Cancel still work. The list shows 'Repeats' (not 'Weekly on Friday') for the Monday rule. Decide whether a blank Repeats select on these pages is acceptable (see Advisory)
why_human: Visual check of a Filament page state (behaviour is covered by EditEventResilienceTest)
result: [pending]

### 6. System health and ops drill (01-09): `composer dev`, open /admin/system-health, `ops:drill --fail-for=60` twice, Retry one, Delete one. Also check the admin theme (quick task 261017-e46) lays the sections out with spacing in light and dark mode
expected: OK states with UK times, 2 failed rows and a red badge that then clear; `ops:drill --status` shows failed=0; sections spaced in a grid, readable in dark mode
why_human: Live scheduler/worker and visual look
result: [pending]

### 7. Share and add-to-calendar (01-10): laptop at localhost: Share… (cancel, then share), Copy link, open the .ics in the calendar app, open the Google link. Phone at the LAN IP: fallbacks open WhatsApp / Facebook / email, .ics imports on iOS and Android. Firefox / no-JS: fallbacks show, with no dead button
expected: As in 01-10 PLAN. Times are correct after 25 Oct. Assets and the .ics link load from the LAN IP (root-relative). Note (review IN-11): shared / canonical URLs are built from APP_URL, so they read http://localhost:8000/… unless APP_URL is set to the LAN address for the phone test. That is by design, not a defect
why_human: Native share sheets, calendar apps, Google's undocumented template URL
result: [pending]

### 8. Acknowledge insufficient_spec backstop truths: (a) edit / sync / status row-lock serialisation (01-04, 01-05); (b) heartbeat upsert under overlapping runs (01-09); (c) share.js re-entry and AbortError (01-10, covered by the share item); (d) accepted ZWJ-emoji split at the 140-character excerpt cut (01-06)
expected: Owner accepts each, or asks for a concurrency test
why_human: Concurrency or accepted limits with no deterministic test
result: [pending]

### 9. Review 4 flagged prohibitions (1 judgment-tier, 3 test-tier with no enforcement test). See the Prohibitions table
expected: Accept the verifier's manual check or add enforcement tests (e.g. a Pest arch / composer.json assertion)
why_human: Unverified prohibitions; the verifier's check is not authoritative
result: [pending]

## Summary

total: 9
passed: 0
issues: 0
pending: 9
skipped: 0
blocked: 0

## Gaps
