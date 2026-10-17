# Browser UAT runbook

Repeatable browser checks for the public site and the Filament admin. Run them at the end of every phase, before signing off `/gsd-verify-work`, and after any change to public views, the event form, status actions or ops pages.

They were first run on 2026-10-17 for Phase 1 (results: `.planning/phases/01-foundation-published-calendar/01-UAT.md`). Each flow lists the steps, the selectors used and the expected result, so a person, an agent with browser tools (Playwright), or a future automated browser test can follow it exactly.

## Conventions

- **Select by role and accessible name** (`getByRole('button', { name: 'Cancel this date' })`, `getByLabel('Title')`). If a control can't be found that way, record it as an accessibility finding, not just a test problem.
- **Test data is prefixed `UAT`** (e.g. "UAT Quiz Night") so it is easy to find and clean up. Never use real event names.
- **Check the public effect of every admin action** with `curl -s http://127.0.0.1:8000/ | grep -oE '…'` or by reloading `/`. The admin saying "Saved" is not enough.
- **Record results** in the phase's `NN-UAT.md` (`result: pass | pass with issue | issue | partial | pending`) and add new findings under `## Gaps`.
- **Compare with the known-issues baseline** at the end of this file so a known issue isn't reported as a new regression.

## F0. Setup

1. Database: `docker start ae-pg` (PostgreSQL 18, databases `attleborough` and `attleborough_testing`).
2. `.env` must contain `BOOST_BROWSER_LOGS_WATCHER=false` (otherwise Laravel Boost injects a script into every local page and the "no script on `/`" check fails).
3. Assets: `npm run build` (no `public/hot`; a running `npm run dev` also injects a script).
4. Server: `php artisan serve` → `http://localhost:8000`. Check: `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000/` returns `200`.
5. Admin account (local only): `admin@example.test`. It must equal `ADMIN_EMAIL` in `.env`. Create it once with `php artisan make:filament-user` if missing. Check: `php artisan tinker --execute 'echo App\Models\User::pluck("email");'`.

### Log in

Go to `/admin/login`. Typing field by field can leave focus in the password box, so fill both fields explicitly:

```js
await page.getByLabel('Email address').fill('admin@example.test');
await page.getByLabel('Password').first().fill('<local password>');
await page.getByRole('button', { name: 'Sign in' }).click();
```

Expected: redirect to `/admin` (Dashboard), sidebar shows **Dashboard**, **Events**, **System → System health**.

## F1. Public calendar look (desktop and phone, light and dark)

1. Desktop: open `/` at 1280–1400px wide.
2. Phone: `page.setViewportSize({ width: 375, height: 812 })`, then `page.emulateMedia({ colorScheme: 'dark' })` and again with `'light'`.
3. Measure: `page.evaluate(() => ({ overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth, scripts: document.scripts.length, bg: getComputedStyle(document.body).backgroundColor }))`.

Expected:
- "Skip to main content" is the first focusable link; wordmark with calendar-pin icon; tagline "See what's on in Attleborough in one place."
- Norfolk-green accent; the active chip is filled with a ✓ tick (not colour alone); chips are at least 24×24px.
- Date headings ("Today", "Friday 30 October") use `<time datetime>`; a cancelled date shows the word **Cancelled**.
- No horizontal overflow, **0 scripts** on `/`, dark background in dark mode, readable text in both themes.
- Only the **When** chips are open; **Category** and **Show only** sit under a closed **More filters** disclosure (a native `details`/`summary`, at least 24×24px with the visible focus ring, opens with click, Enter or Space).
- On 375×812 the first event heading (`main h2`) starts above 812px, so the first day is visible without scrolling.

## F2. Filters without JavaScript

The public calendar loads no scripts, so walking the chips in a normal browser is equivalent to JS off (the integrated browser cannot open a JS-disabled context). Confirm `document.scripts.length === 0` on each page.

Click in order: **This weekend → More filters (the summary) → Community → Free → Family-friendly → Today → This week → All upcoming**. After each click record the URL, the number of `main article h3 a` cards, `meta[name=robots]` and any empty-state text.

Expected:
- Each chip is a plain link; filters combine, e.g. `/?when=weekend&category=community&free=1&family=1`.
- **More filters** is a `details`/`summary` (not a link) and is closed on `/` and on When-only URLs such as `/?when=weekend`. After choosing Community, Free or Family-friendly the next page renders it already open with the active chip ticked. Still no scripts.
- Clicking an active chip removes only that filter.
- Every filtered URL has `noindex, follow`; the page title and a "Showing: Community · Free · Family-friendly" line reflect the filters.
- No matches → "No events match these filters. Try removing one, or **see everything coming up.**" (link to `/`).

## F3. Create a recurring series

`/admin/events/create`:

| Field | Value | How |
|---|---|---|
| Title | UAT Quiz Night | `getByLabel('Title')` |
| Description | Monthly pub quiz… | `getByLabel('Description')` |
| Category | Community | `getByLabel('Category').selectOption({ label: 'Community' })` |
| Start date | Fri 30 Oct 2026 | open `getByRole('combobox', { name: 'Start date*' })`, click `getByRole('gridcell', { name: 'Friday, 30 October 2026' })` |
| Start / end time | 19:30 / 22:00 | `getByRole('textbox', { name: 'Start time*' })`, `{ name: 'End time' }` |
| Repeats | Monthly on the last Friday | `getByRole('combobox', { name: 'Repeats*' })` |
| Ends | On a date → 31 Mar 2027 | radio "On a date", then the "Last date" picker: set `spinbutton` "Year", `combobox` "Month", click `gridcell` "Wednesday, 31 March 2027" |
| Venue | existing venue | click the "Venue" button, pick an `option` |
| Price | Paid, "£2 per person" | radio "Paid", `getByRole('textbox', { name: 'Price details' })` |
| Organiser / contact | The Griffin / quiz@example.test | `getByRole('textbox', { name: 'Organiser', exact: true })`, "Organiser contact" |

Date pickers are Filament panels (`#form.start_date-panel`), not native inputs; press `Escape` to close them.

Expected:
- Before a start date is picked, Repeats offers only "Does not repeat". After picking Fri 30 Oct (the 5th Friday) it offers exactly "Weekly on Friday" and "Monthly on the last Friday" (no "5th Friday", because not every month has one).
- "Next dates" preview in UK format: "Fri 30 Oct, 7:30pm / Fri 27 Nov, 7:30pm / Fri 25 Dec, 7:30pm" (same local time after the 25 Oct clock change).
- **Create** → "Created" notification, redirect to `/admin/events`, row shows Title · Category · Next occurrence · **Repeats** ("Monthly on the last Friday") · Status ("Published") · actions **Edit / Hide from calendar / Mark as cancelled**.

## F4. Stored data and public listing

```bash
php artisan tinker --execute '$e=App\Models\Event::where("title","UAT Quiz Night")->first(); echo json_encode(["rule"=>$e->recurrence_rule,"occ"=>$e->occurrences()->orderBy("starts_at")->get()->map(fn($o)=>[$o->recurrence_key,$o->starts_at->utc()->format("Y-m-d H:i\\Z"),$o->status->value])]);'
curl -s http://127.0.0.1:8000/ | grep -oE 'UAT Quiz Night|Fri [0-9]+ [A-Za-z]+, [0-9:apm]+|>Cancelled<'
```

Expected: rule `FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20270331T225959Z`; one occurrence per date inside the 90-day horizon, keyed by London date, times in UTC (19:30Z after BST ends); one public card per date under its own date heading.

## F5. Single-date status (Occurrences table)

Open the event's edit page. The **Occurrences** table is a lazy relation manager: scroll to the bottom and wait about 2 seconds (reload if it doesn't appear).

1. On the 25 Dec row click **Cancel this date** → dialog "Cancel this date?" / "This date stays on the public calendar with a "Cancelled" label until it has passed." → **Confirm**. Expected: status "Cancelled", action becomes **Restore this date**; public card shows **Cancelled**.
2. On 27 Nov click **Hide this date** → "This date disappears from the public calendar. You can restore it at any time." → Confirm. Expected: "Hidden"; date gone from `/`.
3. **Restore this date** on 27 Nov → "This date goes back on the public calendar without a "Cancelled" label." Expected: "Scheduled"; back on `/`.

Dialogs are `role=alertdialog`; confirm with `getByRole('button', { name: 'Confirm' })`.

## F6. Edit only the time; statuses survive

On the edit page change Start time 19:30 → 20:00, click **Save**.

Expected: "Saved"; preview shows "Fri 30 Oct, 8pm" (":00" dropped); in the database all occurrences move to 20:00Z and the cancelled 25 Dec stays cancelled; `/` shows 8pm. Reload the page and check the Occurrences table shows 20:00.

Also check WR-06 on a series: the Repeats dropdown does **not** offer "Does not repeat", and the helper text reads "To stop it repeating, set a last date or hide it." On a one-off event the Repeats field is locked at "Does not repeat" with helper text about hiding it and creating a new repeating event.

## F7. Whole-event status from the events table

On `/admin/events`, use the row actions for the UAT event, checking `/` after each:

| Action | Dialog text | Expected admin row | Expected public site |
|---|---|---|---|
| Hide from calendar | "All of its dates disappear from the public calendar…" | Hidden · only **Show on calendar** | event gone; `/events/<slug>` returns 404 |
| Show on calendar | "Its dates go back on the public calendar as they were before." | Published | dates back |
| Mark as cancelled | "The event stays on the public calendar with a "Cancelled" label until its dates have passed." | Cancelled · only **Reinstate** | every date labelled Cancelled |
| Reinstate | "…Dates you cancelled one by one stay cancelled." | Published | only the individually cancelled date still says Cancelled |

Expected: success notifications ("Event shown on the calendar", "Event marked as cancelled", "Event reinstated"); `getByRole('button', { name: /delete/i })` count is 0 everywhere. Repeat Hide/Cancel once from the edit page header.

## F8. Repeat rules the form can't edit (WR-09, WR-10)

Temporarily set a stored rule directly (simulates imported data), check the page, then restore it:

```bash
php artisan tinker --execute 'DB::table("events")->where("title","UAT Quiz Night")->update(["recurrence_rule"=>"FREQ=WEEKLY;BYDAY=MO"]);'
# repeat with "FREQ=DAILY"
# restore afterwards:
php artisan tinker --execute 'DB::table("events")->where("title","UAT Quiz Night")->update(["recurrence_rule"=>"FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20270331T225959Z"]);'
```

Expected for each rule:
- Events list Repeats column shows the generic "Repeats".
- Edit page: notification "This event's repeat rule can't be edited here. You can still hide or cancel it."; fields disabled (`getByRole('textbox', { name: /Title/ }).isDisabled()` is true); **no Save** button; header still has **Hide from calendar** and **Mark as cancelled**.
- After restoring, occurrences and statuses are unchanged (re-run the F4 tinker query).

## F9. Event page, add to calendar and sharing

Open `/events/<slug>` (phone width, light theme).

Expected:
- "← All events", title, category, price, "Upcoming dates" (each with "Add to calendar (.ics)" and "Google Calendar" links whose accessible names include the date), "Where" with full address, "About this event", "Who's running it" (organiser, contact link, attribution such as "Listed by What's On Attleborough."), "Share this event".
- A cancelled date shows **Cancelled** and has **no** calendar links.
- Scripts: one `application/ld+json` per upcoming date (max 10) plus `/build/assets/share-*.js`; nothing third-party.
- Share block: WhatsApp, Facebook and Email are plain links; "Link to this event" read-only box; **Copy link** announces "Link copied." in a `role=status` region; **Share…** is hidden when `navigator.share` is unavailable.
- Google link `dates=` are UTC (e.g. `20261030T200000Z/20261030T220000Z`).

`.ics` check:

```bash
curl -s -D - http://127.0.0.1:8000/events/<slug>/<Y-m-d>.ics
```

Expected: `Content-Type: text/calendar`, `Content-Disposition: attachment`, `UID:<event_id>-<Y-m-d>@whatsonattleborough.co.uk` (or `<event_id>@…` for a one-off), UTC `DTSTART`/`DTEND`, escaped and folded `DESCRIPTION`/`LOCATION`; a cancelled date has `STATUS:CANCELLED`.

**Phone-only (manual):** native share sheet, importing the `.ics` into iOS and Android calendars, opening the Google link. Shared and canonical links use `APP_URL`, so set it to the laptop's LAN address before testing from a phone.

## F10. System health and ops drill

1. Without workers, `/admin/system-health` shows Scheduler and Queue worker **Stale** with "Last seen …" and the thresholds in words.
2. Start the workers in the background: `php artisan schedule:work` and `php artisan queue:work database --sleep=1`.
3. Within about a minute `php artisan ops:health` shows `scheduler: OK`. The queue heartbeat runs every 5 minutes.
4. `php artisan ops:drill --fail-for=60` twice. `php artisan ops:drill --status` shows `failed=2`. Reload the page: 2 rows (time, `OpsDrillJob`, queue, first error line, **Retry** / **Delete**) and a badge on "System health".
5. After the 60 seconds have passed: **Retry** row 1 (dialog "Send this job back to the queue…", notification "Sent back to the queue"), **Delete** row 2 ("This removes the record of the failure. It cannot be undone.", "Failed job deleted"). Expected: "No failed jobs"; `ops:drill --status` shows `failed=0` with a fresh `last_success`.
6. `ops:health` prints **HEALTHY** within 6 minutes; the page shows all three cards OK.
7. **Stop both workers** (kill by PID) and delete any temp logs.

## Cleanup

- Hide the UAT events (`Hide from calendar`) or remove them from the local database if they would confuse later work. Never remove real data.
- Restore any rule changed in F8.
- Stop any background processes you started; leave the owner's `php artisan serve` running.
- Reset the browser viewport and colour scheme.

## Adding flows in later phases

When a phase adds or changes UI, add a flow here in the same shape (steps, selectors, expected result, public effect) and reference it in that phase's UAT file. Likely next flows:

- Phase 2: organiser submission form (validation, honeypot, rate limit, confirmation page), review queue (approve, edit-and-approve, reject, bulk actions, "Approve & next" keyboard shortcuts).
- Phase 3: source registry ("Run now"), update drafts with the three-column diff, cancellation drafts, duplicate warnings.
- Phase 4: test-run preview of a source without creating drafts.
- Phase 5: contact/complaints form, "Report a problem", expired-event banner, public ICS/RSS feeds; repeat F1–F10 against the production URL.

### Automating these flows

The selectors above are the ones a browser test would use. Turning them into automated tests needs a browser-test dependency (e.g. `pestphp/pest-plugin-browser` or `@playwright/test`), which needs the owner's approval first (project rule: no dependency changes without approval).

## Known-issues baseline (2026-10-17)

Found in the first run; none blocks Phase 1. Treat them as known until fixed:

| Area | Issue |
|---|---|
| Public (phone) | Card category icon takes about a quarter of the width, so titles and venues wrap early. |
| Admin, event form | Occurrences table doesn't refresh after **Save** (shows old times until reload). |
| Admin, event form | Read-only Repeats dropdown shows "Weekly on Friday" for unsupported rules (misreports the stored rule). |
| Admin, event form | The unsupported-rule warning is a dismissible notification, not an inline banner. |
| Admin, event form | "Next dates" says "No upcoming dates" before a last date is entered; the 3-date cap isn't labelled. |
| Admin, dialogs | "Cancel this date?" uses **Cancel** / **Confirm**, where Cancel means keep the date (and has default focus). |
| Admin, Occurrences | "Date key" column shows the internal recurrence key; no success notification for per-date actions. |
| Admin, events list | All-day events show "00:00"; 24-hour times in admin vs "7:30pm" on the public site. |
| Admin, look | Not branded: Filament amber instead of Norfolk green. |
| Admin, System health | Failed-jobs badge stays stale until reload; Reason shows the full server file path. |
| Admin, narrow screens | Events table needs sideways scrolling; Repeats column and actions are off-screen. |
