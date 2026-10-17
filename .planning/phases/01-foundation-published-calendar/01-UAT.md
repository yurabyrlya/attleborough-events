---
status: complete
phase: 01-foundation-published-calendar
source: [01-VERIFICATION.md]
started: 2026-10-17T10:06:11Z
updated: 2026-10-17T11:23:19.893Z
---

> Browser flows used for this UAT are documented in `docs/uat/browser-uat.md` (F0–F10). Re-run them for future phases and regressions.

## Current Test

[testing complete]

## Tests

### 1. Phone / mobile look (01-07): `npm run build`, `php artisan serve --host 0.0.0.0 --port 8000`, open on a phone or in DevTools emulation in light and dark themes; tap This weekend / a category / Free; add to home screen; Tab through; 320px width with a long unbroken title
expected: Wordmark, Atkinson font, Norfolk green, AA-readable text, thumb-sized chips with a tick on the active one, category icons, readable 'Cancelled', green calendar-pin home icon, 'Skip to main content' first Tab stop, focus rings, no sideways scroll
why_human: Colour, touch and reflow on a real screen
result: pass (emulated: 375px + dark/light in the integrated browser; no horizontal overflow, 0 scripts, AA-looking contrast, tick on active chip, skip link first). Real-phone check still recommended. UX: on a phone the filter chips fill more than the first screen — first event heading starts at 828px on an 812px viewport.

### 2. Browse with JavaScript disabled: use every chip and 'More events'
expected: The same filtered lists, no dead controls
why_human: Real browser behaviour; server tests prove only the markup
result: pass (public pages load 0 scripts, so behaviour is identical with JS off): all chips are links, filters combine, active chip toggles off, 'Showing: …' summary, noindex on filter URLs, friendly empty state with way back.

### 3. Filament recurring-series walkthrough (01-04, updated by 01-13): create a monthly last-Friday series, check the 'Next dates' preview, save, edit only the time. Then open a one-off event's edit page and a series' edit page and read the Repeats field. Also check the events list's Repeats column
expected: Preview in UK format, occurrences on /, time change keeps dates and statuses. One-off: Repeats disabled at 'Does not repeat' with helper text about hiding and creating a new repeating event (new web address). Series: no 'Does not repeat' option, and helper text explains how to end or hide it. The app-created series stays editable (Save present, no warning) and the list says e.g. 'Monthly on the last Friday'
why_human: Filament UI flow and wording
result: pass with issue: create 'Monthly on the last Friday' until 31 Mar 2027 → preview 'Fri 30 Oct, 7:30pm / 27 Nov / 25 Dec' (UK format, correct across 25 Oct DST); 3 occurrences stored at 19:30Z; time edit to 20:00 kept dates and the cancelled 25 Dec. Series Repeats omits 'Does not repeat' with helper text. ISSUE (minor): Occurrences table on the edit page does not refresh after Save (shows old 19:30 until reload). UX: preview says 'No upcoming dates' while Last date is still empty; preview shows only 3 dates without saying it is a sample.

### 4. Filament status walkthrough (01-05 + 01-13 table actions): cancel and hide single dates, then hide / show / cancel / reinstate the whole event from the edit page AND from the events table row actions, watching /
expected: Confirmations, correct badges, hidden items gone, per-date statuses kept. No delete action anywhere
why_human: UI flow
result: pass: per-date Cancel/Hide/Restore and event Hide (page 404s)/Show/Cancel (all dates labelled)/Reinstate (per-date cancellation kept) from edit page and table row actions; public site updates each time; no Delete anywhere. UX: confirmation dialogs use 'Cancel' / 'Confirm' — in 'Cancel this date?' the 'Cancel' button means keep the date (confusing; default focus is on it). No success toast for per-date actions.

### 5. Unsupported / non-round-tripping rule edit page (01-13 WR-09 + 01-15 WR-10): in tinker set one event's recurrence_rule to 'FREQ=DAILY' and another Friday-start event's to 'FREQ=WEEKLY;BYDAY=MO'. Open both edit pages and the events list
expected: Both pages open with the persistent warning "This event's repeat rule can't be edited here. You can still hide or cancel it.", no Save button, and Hide / Cancel still work. The list shows 'Repeats' (not 'Weekly on Friday') for the Monday rule. Decide whether a blank Repeats select on these pages is acceptable (see Advisory)
why_human: Visual check of a Filament page state (behaviour is covered by EditEventResilienceTest)
result: pass with issues: FREQ=DAILY and FREQ=WEEKLY;BYDAY=MO (Friday start) both open read-only, warning shown, no Save, Hide/Cancel work, list shows 'Repeats'; occurrences untouched. ISSUES (minor): read-only Repeats dropdown shows 'Weekly on Friday' (wrong — misreports the stored rule); the warning is a dismissible notification, not an inline banner.

### 6. System health and ops drill (01-09): `composer dev`, open /admin/system-health, `ops:drill --fail-for=60` twice, Retry one, Delete one. Also check the admin theme (quick task 261017-e46) lays the sections out with spacing in light and dark mode
expected: OK states with UK times, 2 failed rows and a red badge that then clear; `ops:drill --status` shows failed=0; sections spaced in a grid, readable in dark mode
why_human: Live scheduler/worker and visual look
result: pass: with schedule:work + queue:work, scheduler OK in ~1 min, HEALTHY within 6 min; 2 drill failures listed with Retry/Delete and a red badge; Retry → job DONE (failed=0, fresh last_success), Delete removed the record. Minor: badge stays '2' until reload; Reason shows full absolute file path.

### 7. Share and add-to-calendar (01-10): laptop at localhost: Share… (cancel, then share), Copy link, open the .ics in the calendar app, open the Google link. Phone at the LAN IP: fallbacks open WhatsApp / Facebook / email, .ics imports on iOS and Android. Firefox / no-JS: fallbacks show, with no dead button
expected: As in 01-10 PLAN. Times are correct after 25 Oct. Assets and the .ics link load from the LAN IP (root-relative). Note (review IN-11): shared / canonical URLs are built from APP_URL, so they read http://localhost:8000/… unless APP_URL is set to the LAN address for the phone test. That is by design, not a defect
why_human: Native share sheets, calendar apps, Google's undocumented template URL
result: pass — laptop parts: event page shows dates with per-date .ics + Google links (none on the cancelled date), .ics valid (stable UID 2-2026-10-30@whatsonattleborough.co.uk, UTC DTSTART, STATUS:CANCELLED for the cancelled date), Google dates 20261030T200000Z/…T220000Z, WhatsApp/Facebook/Email links, Copy link announces 'Link copied.', Share… correctly hidden where navigator.share is absent. Phone (owner, 2026-10-17, LAN http://192.168.1.20:8001 with APP_URL set to the LAN address): .ics imports at the right time, Google link opens a prefilled event, WhatsApp/Facebook/Email fallbacks work. Native share / clipboard need a secure context (HTTPS), so over LAN http the Share… button is correctly hidden; re-check on HTTPS in Phase 5.

### 8. Acknowledge insufficient_spec backstop truths: (a) edit / sync / status row-lock serialisation (01-04, 01-05); (b) heartbeat upsert under overlapping runs (01-09); (c) share.js re-entry and AbortError (01-10, covered by the share item); (d) accepted ZWJ-emoji split at the 140-character excerpt cut (01-06)
expected: Owner accepts each, or asks for a concurrency test
why_human: Concurrency or accepted limits with no deterministic test
result: skipped
reason: "Owner skipped on 2026-10-17 (backstops neither accepted nor given a test yet)"

### 9. Review 4 flagged prohibitions (1 judgment-tier, 3 test-tier with no enforcement test). See the Prohibitions table
expected: Accept the verifier's manual check or add enforcement tests (e.g. a Pest arch / composer.json assertion)
why_human: Unverified prohibitions; the verifier's check is not authoritative
result: skipped
reason: "Deferred follow-up: skip as I do it at the end once we finish all phases"

## Summary

total: 9
passed: 7
issues: 0
pending: 0
skipped: 2
blocked: 0

## Gaps

None blocking. Minor UX findings from the browser walkthrough (2026-10-17), candidates for a follow-up quick task:
- Mobile: filter chips push the first event below the fold (collapse categories into a <details> disclosure or a horizontal scroller; works without JS) — FIXED by quick 261017-gac
- Admin: Occurrences table not refreshed after Save on the edit page — FIXED by quick 261017-gac
- Admin: read-only Repeats dropdown misreports unsupported rules (shows 'Weekly on Friday'); warning is dismissible rather than inline — FIXED by quick 261017-gac
- Admin: confirmation dialogs 'Cancel' vs 'Confirm' wording in 'Cancel this date?' (use 'Keep date' / 'Cancel date') — FIXED by quick 261017-gac
- Admin: 'Next dates' preview says 'No upcoming dates' before a last date is entered; 3-date cap not labelled
- Admin: System health badge stays stale until reload; failed-job Reason shows absolute path — badge FIXED by quick 261017-gac (absolute path still open; Retry/Delete dialogs still Cancel/Confirm)
- Admin: not branded (Filament amber, not Norfolk green); 'Date key' column is internal jargon; all-day shown as '00:00' in the events list; 24h times in admin vs 7:30pm public
- Demo data (owner): First Demo Event has '5£', 'town center' and a Source URL pointing at /admin/events/create

## Deferred Follow-Ups

```yaml
- test: 9
  idea: "skip as I do it at the end once we finish all phases"
  deferred_at: 2026-10-17
```
