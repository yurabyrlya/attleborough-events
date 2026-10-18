# Browser UAT runbook

Repeatable browser checks for the public site and the Filament admin. Run them at the end of every phase, before signing off `/gsd-verify-work`, and after any change to public views, the event form, status actions or ops pages.

They were first run on 2026-10-17 for Phase 1 (results: `.planning/phases/01-foundation-published-calendar/01-UAT.md`). Each flow lists the steps, the selectors used and the expected result, so a person or an agent with browser tools (Playwright) can follow it exactly. The automated version is `vendor/bin/pest --testsuite=Browser` (see [Automating these flows](#automating-these-flows)).

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

Expected: redirect to `/admin` (Dashboard), sidebar shows **Dashboard**, **Events**, **Review → Review queue** (with the pending count), **Sources** (with a warning count when a live source needs attention) and **Rules**, **System → System health**.

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
- On 375×812 the first event heading (`main h2`) starts above 812px, so the first day is visible without scrolling. The same holds with a filter active (`/?category=community`, `/?free=1`): the disclosure stays closed with **More filters (1)** and a **Filtering by** row of removable chips sits above it.

## F2. Filters without JavaScript

The public calendar loads no scripts, so walking the chips in a normal browser is equivalent to JS off (the integrated browser cannot open a JS-disabled context). Confirm `document.scripts.length === 0` on each page.

Click in order: **This weekend → More filters (the summary) → Community → More filters → Free → More filters → Family-friendly → Today → This week → All upcoming → Remove filter: Family-friendly**. The disclosure is closed again after every reload, so reopen the summary before each Category or Show only pick. After each click record the URL, the number of `main article h3 a` cards, `meta[name=robots]` and any empty-state text.

Expected:
- Each chip is a plain link; filters combine, e.g. `/?when=weekend&category=community&free=1&family=1`.
- **More filters** is a `details`/`summary` (not a link) and is always closed when a page loads, with or without filters. With Category or Show only filters active its summary reads **More filters (N)**, and each active filter appears under **Filtering by** (outside the disclosure) as a chip whose accessible name is "Remove filter: …". Opening More filters shows the active chip ticked. Still no scripts.
- Clicking a "Remove filter:" chip, or an active chip inside More filters, removes only that filter.
- On 375×812, `/?category=community` still has the first `main h2` starting above 812px.
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
- Since Phase 2, manual entry goes through a draft (D-07): a manual draft is created and approved at once. It appears under **Review queue → Approved** (Source "Manual entry", status Approved), linked to the event, and never sits in Pending.

## F4. Stored data and public listing

```bash
php artisan tinker --execute '$e=App\Models\Event::where("title","UAT Quiz Night")->first(); echo json_encode(["rule"=>$e->recurrence_rule,"occ"=>$e->occurrences()->orderBy("starts_at")->get()->map(fn($o)=>[$o->recurrence_key,$o->starts_at->utc()->format("Y-m-d H:i\\Z"),$o->status->value])]);'
curl -s http://127.0.0.1:8000/ | grep -oE 'UAT Quiz Night|Fri [0-9]+ [A-Za-z]+, [0-9:apm]+|>Cancelled<'
```

Expected: rule `FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20270331T225959Z`; one occurrence per date inside the 90-day horizon, keyed by London date, times in UTC (19:30Z after BST ends); one public card per date under its own date heading.

## F5. Single-date status (Occurrences table)

Open the event's edit page. The **Occurrences** table is a lazy relation manager: scroll to the bottom and wait about 2 seconds (reload if it doesn't appear).

1. On the 25 Dec row click **Cancel this date** → dialog "Cancel this date?" / "This date stays on the public calendar with a "Cancelled" label until it has passed." → **Cancel date**. Expected: toast "Date cancelled"; status "Cancelled", action becomes **Restore this date**; public card shows **Cancelled**.
2. On 27 Nov click **Hide this date** → "This date disappears from the public calendar. You can restore it at any time." → **Hide date**. Expected: toast "Date hidden"; "Hidden"; date gone from `/`.
3. **Restore this date** on 27 Nov → "This date goes back on the public calendar without a "Cancelled" label." → **Restore date**. Expected: toast "Date restored"; "Scheduled"; back on `/`.

Dialogs are `role=alertdialog`; confirm with `getByRole('button', { name: 'Cancel date' })` (or `'Hide date'` / `'Restore date'`). The dismiss button is **Keep as is**; no dialog has a button called "Confirm" or "Cancel".

## F6. Edit only the time; statuses survive

On the edit page change Start time 19:30 → 20:00, click **Save**.

Expected: "Saved"; preview shows "Fri 30 Oct, 8pm" (":00" dropped); in the database all occurrences move to 20:00Z and the cancelled 25 Dec stays cancelled; `/` shows 8pm. Without reloading, the Occurrences table shows 20:00.

Also check WR-06 on a series: the Repeats dropdown does **not** offer "Does not repeat", and the helper text reads "To stop it repeating, set a last date or hide it." On a one-off event the Repeats field is locked at "Does not repeat" with helper text about hiding it and creating a new repeating event.

## F7. Whole-event status from the events table

On `/admin/events`, use the row actions for the UAT event, checking `/` after each:

| Action | Dialog text | Expected admin row | Expected public site |
|---|---|---|---|
| Hide from calendar | "All of its dates disappear from the public calendar…" | Hidden · only **Show on calendar** | event gone; `/events/<slug>` returns the branded 404 (wordmark, "We can't find that page", a "See what's on" link to `/`, no scripts) |
| Show on calendar | "Its dates go back on the public calendar as they were before." | Published | dates back |
| Mark as cancelled | "The event stays on the public calendar with a "Cancelled" label until its dates have passed." | Cancelled · only **Reinstate** | every date labelled Cancelled |
| Reinstate | "…Dates you cancelled one by one stay cancelled." | Published | only the individually cancelled date still says Cancelled |

Each dialog's submit button names the action (**Hide event**, **Show event**, **Cancel event**, **Reinstate event**) and the dismiss button is **Keep as is**.

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
- Edit page: notification "This event's repeat rule can't be edited here. You can still hide or cancel it."; fields disabled (`getByRole('textbox', { name: /Title/ }).isDisabled()` is true); the disabled Repeats select reads "Custom rule (can't be edited here)" (never "Weekly on Friday"); an inline warning callout "This repeat rule can't be edited here" in the Repeats section shows "Stored rule: <the rule>."; **no Save** button; header still has **Hide from calendar** and **Mark as cancelled**.
- After restoring, occurrences and statuses are unchanged (re-run the F4 tinker query).

## F9. Event page, add to calendar and sharing

Open `/events/<slug>` (phone width, light theme).

Expected:
- "← All events", title, category, price, "Upcoming dates" (each date on its own line, with an indented row under it holding "Apple / Outlook (.ics)" and "Google Calendar" links whose accessible names include the date), "Where" with full address, "About this event", "Who's running it" (organiser, contact link, attribution such as "Listed by What's On Attleborough."), "Share this event".
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

### Branded error pages

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8000/events/no-such-thing
```

Expected: `404`. In the browser the page shows the wordmark, "We can't find that page", a "See what's on" link to `/`, and loads no scripts. `/?category=nonexistent` shows the same page without echoing the value. `php artisan down --render="errors::503"` shows the branded "We're updating the calendar" page; run `php artisan up` straight afterwards.

## F10. System health and ops drill

1. Without workers, `/admin/system-health` shows Scheduler and Queue worker **Stale** with "Last seen …" and the thresholds in words.
2. Start the workers in the background: `php artisan schedule:work` and `php artisan queue:work database --sleep=1`.
3. Within about a minute `php artisan ops:health` shows `scheduler: OK`. The queue heartbeat runs every 5 minutes.
4. `php artisan ops:drill --fail-for=60` twice. `php artisan ops:drill --status` shows `failed=2`. Reload the page: 2 rows (time, `OpsDrillJob`, queue, first error line, **Retry** / **Delete**) and a badge on "System health".
5. After the 60 seconds have passed: **Retry** row 1 (dialog "Send this job back to the queue…", notification "Sent back to the queue"), **Delete** row 2 ("This removes the record of the failure. It cannot be undone.", "Failed job deleted"). Expected: without reloading, the "System health" badge drops from 2 to 1 after Retry and disappears after Delete; "No failed jobs"; `ops:drill --status` shows `failed=0` with a fresh `last_success`.
6. `ops:health` prints **HEALTHY** within 6 minutes; the page shows all three cards OK.
7. **Stop both workers** (kill by PID) and delete any temp logs.

## F11. Submit an event (public)

Open `/submit` (the "Add your event" form) on a phone (375×812) and on a desktop. It is a plain form: no Livewire, and it posts without JavaScript.

Turnstile: locally with Cloudflare's always-pass test keys from `.env.example` (`TURNSTILE_SITE_KEY=1x00000000000000000000AA`), the widget shows a small "Success" box above **Send for review**. In the automated tests no site key is set, so the widget and `challenges.cloudflare.com` script are not rendered and the verifier is faked.

1. **JavaScript off (once, manual):** the "Somewhere else" fields (**Name of the place**, **Address**, **Postcode (optional)**) are visible, and the notice "Our spam check needs JavaScript. If you can't turn it on, email the details to …" shows above the button.
2. **Known venue:** fill **Name of the event** "UAT Lantern Evening", **Description**, **Category** → Community, **Date** (a date in the next few weeks), **Start time**, **End time (optional)**, **Venue** → an existing venue, price **Free**, **Your name or group**, **Email address**, tick the consent box ("I agree that … privacy notice (opens in a new tab)"). Wait at least 3 seconds after the page loads (the timing check), then click **Send for review**.
3. **Somewhere else:** before choosing a venue the "Somewhere else" fields are visible; pick a known venue and they hide; pick **Somewhere else** and they show again. Fill **Name of the place** "The Old Barn", **Address** and send.
4. **No date:** fill everything except **Date** and send.

Selectors: `getByLabel('Name of the event')`, `getByLabel('Category')`, `#start_date` ("Date" alone also matches "End date"), `getByLabel('Venue')`, `#price_type_free`, `#consent`, `getByRole('button', { name: 'Send for review' })`.

Expected:
- Steps 2 and 3: `/submit/thanks` with the heading "Thanks for sending your event" and "Your reference: WOA-…". Reloading the thanks page creates nothing new. Step 3's draft has no venue and keeps "The Old Barn" as the typed venue.
- Step 4: back on `/submit` with the summary "Please check these" at the top, focused, and a link to the Date field; the Date field is marked invalid; every typed value is still there; no draft is created.
- No horizontal overflow on the phone; the only third-party script on the page is Turnstile's (and only when a site key is set).

Public effect: none. Nothing appears on `/` until the admin approves the draft (F12).

## F12. Review queue, edit-and-approve, Approve & next

Start with at least two pending submissions from F11, one sent with **Somewhere else** ("The Old Barn") and "Every Tuesday for four weeks" in **Does it repeat?**, dated on a Tuesday.

1. Sidebar **Review → Review queue**: its badge shows the pending count. The list opens on the **Pending** tab (tabs: Pending, Rejected, Approved, All, each with a count), soonest event first. A typed venue reads "The Old Barn (not confirmed)"; a category with no match reads "Other (check)".
2. Click the barn draft's row → review page (`/admin/event-drafts/<id>/review`). Read:
   - **Summary**: Quality band (High / Medium / Low) and score, **What to check** with reasons such as "Venue not recognised: 'The Old Barn'" and "Organiser says it repeats ('Every Tuesday for four weeks') but no repeat rule is set".
   - **Why this category**: "Chosen by organiser" (or a keyword rule, see F14).
   - **Before you can publish** lists the blockers ("Pick or create a venue.", "The organiser says it repeats…"); **Approve & next (A)** is disabled while there are any.
   - **Raw content** opens "What the organiser sent" (escaped text); **Close**.
3. **Edit (E)** → "Edit draft: …". Next to **Venue** click **Create new venue**: the dialog is pre-filled with the organiser's name, address and postcode (town Attleborough); **Create**. Then **Repeats** → "Weekly on Tuesday", **Ends** → "After a number of times", **Number of times** 4. Click **Save & approve** (no dialog: saving and publishing happen in one step and roll back together if something blocks).
4. Expected: "Published", and the page opens the **next** pending draft's review page (or the list with "You've reached the end of the queue" after the last one).
5. Keyboard (on a review page, shortcuts on): **A** then **Enter** publishes and opens the next draft ("Publish" has the focus in the "Publish this event?" dialog); **S** or **J** skips; **K** goes back; **?** opens "Keyboard shortcuts"; **R** opens "Reject this draft?", and typing "ask" in the note triggers nothing; on a draft with blockers, **A** says "Can't approve yet" with the reason. Untick **Keyboard shortcuts (…)** at the top, reload: it stays off and S does nothing; tick it again.

Selectors: the sidebar link and tabs carry their count in the accessible name, so match a substring (`getByRole('link', { name: /Review queue/ })`); `getByRole('link', { name: 'Edit (E)' })`, `getByRole('button', { name: 'Create new venue' })`, `#form.repeats`, `getByRole('radio', { name: 'After a number of times' })`, `#form.repeat_count`, `getByRole('button', { name: 'Save & approve' })`. Dialogs are `.fi-modal-open`.

Public effect: the barn series shows on `/` as four dated cards (Tue 27 Oct, 3, 10, 17 Nov in the automated run) at "The Old Barn"; the skipped and rejected drafts show nothing.

## F13. Bulk approve and bulk reject

On **Review queue → Pending**, with at least one publishable draft, one Low-quality draft (typed venue, "Not sure" category, no end time, a repeat in words) and, where available, one with parse errors:

1. Tick the header checkbox ("Select/deselect all items for bulk actions.") → **Bulk actions** → **Approve selected** → "Approve the selected drafts?" ("Drafts with problems are skipped and listed afterwards.") → **Approve**.
   Expected: a notification "Approved 1 of 3. The rest were skipped:" with one line per reason, e.g. "1 skipped: Low quality", "1 skipped: has parse errors" (others: "possible duplicate", "no confirmed venue", "repeat rule not set", "already reviewed"). Nothing is skipped silently: the counts add up to the drafts not approved.
2. Tick two drafts → **Bulk actions** → **Reject selected** → "Reject the selected drafts?" → **Reason (optional)** Spam → **Reject drafts**. Expected: "Drafts rejected"; they leave Pending and appear under the **Rejected** tab.
3. Open one rejected draft → **Reopen** → "Reopen this draft?" → **Reopen**. Expected: "Draft reopened"; it is back on **Pending**.

Bulk menu items are `role=menuitem` (`getByRole('menuitem', { name: 'Approve selected' })`). There is no delete anywhere: rejected drafts are purged on schedule (spam after 30 days, others after 90).

Public effect: only the approved draft appears on `/`. Rejected and skipped drafts never do.

## F14. Rules screen

Sidebar **Review → Rules** (`/admin/rules`): four tabs, **Categories**, **Keyword rules**, **Category mappings**, **Geofence**, and one **Save rules** button that saves all four at once (or nothing).

1. **Keyword rules** → **Add keyword rule** → Keyword "pumpkin", Category Community, Priority 100 (the default) → **Save rules**. Expected: "Rules saved".
2. On `/submit` send "UAT Pumpkin carving" with **Category** → **Not sure** (F11). In the queue its Category reads Community (not "Other (check)"); on its review page **Why this category** reads "Keyword rule: 'pumpkin' → Community".
3. **Categories**: the **Other** row has no Delete button (every other row has one), so the fallback category can't be removed. Delete a category that is in use (e.g. Community with a published event) → **Save rules**. Expected: a persistent "Rules not saved" notification naming the problem ("'Community' is used by …"), and nothing is changed. Reload to put the row back.
4. **Geofence**: add a place name (type it and press Tab or comma) and a postcode district such as NR17 → **Save rules** → "Rules saved". A district that isn't one ("NR") is refused with "'NR' isn't a postcode district like NR17."
5. **Category mappings** → **Add mapping** → Source value "Worship", Category Community, **Source** a seeded source (e.g. Attleborough Baptist Church) → **Save rules** → "Rules saved". Add a second row "worship" for the same source → **Save rules**. Expected: a persistent "Rules not saved" naming it ("'worship' is mapped twice for Attleborough Baptist Church."). Change the second row's Source to **All sources** → **Save rules** → "Rules saved". Discovered drafts from that source with the ICS category "Worship" then read "Mapped from 'Worship' (Attleborough Baptist Church)" under **Why this category**; other sources use the All sources row.

Selectors: tabs are `role=tab` (`getByRole('tab', { name: 'Keyword rules' })`) and load lazily, so wait for them before clicking; repeater fields have ids like `form.keyword_rules.<uuid>.keyword`; `getByRole('button', { name: 'Save rules' })`.

Public effect: none directly. Rules only decide the category of the **next** drafts; published events keep theirs. A category renamed here is renamed on the public chips (its slug, used in links, never changes).

## F15. Sources: register an ICS source, Test run, Create drafts, Run now, Pause

Sidebar **Review → Sources** (`/admin/sources`). The local database has the three seeded ICS sources (Attleborough Baptist Church, Attleborough Town Council, Attleborough Academy), all inactive. Locally a test run really fetches the feed, so only run this against sites whose `legal_basis` allows it; the automated run is offline (see below).

1. **New source** → the form. Click the **?** next to **Feed URL** (`getByRole('button', { name: 'Help: Feed URL' })`): a dialog titled "Feed URL" explains why the field exists, how to find the address ("Paste it in a browser: it should download a file starting with BEGIN:VCALENDAR…") and gives real examples; **Close**. Every field has one.
2. Fill **Name** "Attleborough Baptist Church", **Short name** "Baptist Church", **Feed URL** `https://www.attleborough.church/Calendar/Calendar.ics`; leave **Item identity** at "Event UID" → **Create**. Expected: the edit page, the "Latest test run" panel reads "No test run yet…", and the header has only **Test run** (no Create drafts, Run now or Pause): a new source is saved inactive.
3. **Test run** → "Run a test? Nothing is drafted." → **Run test**. Expected: "Test run started"; the panel says "Checking the source… This page updates by itself." and, when the check finishes, "Would create N drafts" with the skipped counts ("Past 0 · Beyond 90 days 18 · Title rule 0 · Parse error 0") and one row per item: title, date and time in UK format ("Sat 31 Oct, 10am"), verdict ("Would draft", "Skipped") and reason ("Beyond 90 days"). Nothing appears in the review queue.
4. All-day items: open the seeded **Attleborough Academy** and run a test. An all-day item shows its date only ("Autumn Half-Term Holiday · Mon 19 Oct"); a timed one its date and time ("Parent Council Meeting · Thu 12 Nov, 6pm"). The page must not error (fixed in `a048cc8`).
5. Back on the Baptist source: **Create drafts** (shown only after a successful test run) → "Create drafts and check this source daily?" → **Create drafts**. Expected: "Drafts are being created"; the N drafts appear in **Review queue → Pending** with Source "Attleborough Baptist Church"; the header now shows **Run now** and **Pause**. The **Run log** at the bottom lists the "Activation" run (Live) above the "Test run", newest first, with Found / New / Drafts created and the other counters.
6. **Run now** → "Check this source now?" → **Run now**. Expected: "Check started"; a third run (Manual, Live) appears in the run log without a reload, with New 0 and Unchanged N.
7. **Pause** → "Pause this source?" → **Pause**. Expected: "Source paused"; **Run now** and **Pause** go, **Test run** stays. To turn it back on: a new test run, then Create drafts.
8. `/admin/sources`: the row shows Health **Healthy**, the last run's status, and Next check "Paused" for the paused source (London times otherwise).

Selectors: header actions and dialog buttons by role and name (`getByRole('button', { name: 'Test run' })`, then `.fi-modal-open` → `getByRole('button', { name: 'Run test' })`); form inputs by id (`#form.name`, `#form.short_name`, `#form.url`, `#form.parser_config.identity`). Toasts sit over the header actions: close them (or wait for them to fade) before the next header click. Dismiss buttons in the run dialogs are **Cancel**; review dialogs use **Keep as is**.

Public effect: none. Drafts wait in the review queue until approved (F16–F19); a test run and Pause change nothing on `/`.

## F20. Public attribution of discovered events

Start from a Baptist draft published through its review page (**Approve & next (A)** → "Publish this event?" → **Publish**), e.g. "Women's Creative Club" on Sat 31 Oct.

1. Open `/events/<slug>`. Expected under **Who's running it**: "Listed by **Attleborough Baptist Church**", linked to the item's own page on the church site (else the source's events page, else the feed site's home page; never the `.ics` file). Straight after the description: a "**Read more at Attleborough Baptist Church**" link to the same address. No "Listed by What's On Attleborough." and no "More information: {host}" line. Links carry `rel="nofollow noopener"`.
2. On `/` the card shows "**via Baptist Church**" (the source's short name, plain text) instead of "Source: {host}".
3. Also listed by: when a second source lists the same event and its draft is marked **Mark as duplicate** (F18), the event page adds "**Also listed by** {other source}" (in link order). The organiser's own site (`Organiser's own site` on the source form) is credited first.

Selectors: `.listed-by-source >> getByRole('link', { name: 'Attleborough Baptist Church' })`, `getByRole('link', { name: 'Read more at Attleborough Baptist Church' })`, `.also-listed-by`, and on `/` `article:has-text("Women's Creative Club")` containing "via Baptist Church".

Public effect: this is the public effect; personal data from the feed (an `ORGANIZER` name or email, a phone number in the description) is never shown (covered by `tests/Feature/Public/SourceAttributionTest.php`).

## Cleanup

- Hide the UAT events (`Hide from calendar`) or remove them from the local database if they would confuse later work. Never remove real data.
- Restore any rule changed in F8.
- Stop any background processes you started; leave the owner's `php artisan serve` running.
- Reset the browser viewport and colour scheme.

## Adding flows in later phases

When a phase adds or changes UI, add a flow here in the same shape (steps, selectors, expected result, public effect) and reference it in that phase's UAT file. Likely next flows:

- Phase 4: the other extraction rungs (web pages, structured data) in the Sources form and their test-run preview.
- Phase 5: contact/complaints form, "Report a problem", expired-event banner, public ICS/RSS feeds; repeat F1–F10 against the production URL.

### Automating these flows

F0–F20 are automated as Pest browser tests in [`tests/Browser`](../../tests/Browser) (`pestphp/pest-plugin-browser`, driving Playwright Chromium). Approved by the owner on 2026-10-17.

| File | Flows |
|---|---|
| `PublicCalendarTest.php` | F1 (desktop; phone light and dark), F2 |
| `AdminCreateEventTest.php` | F0 log in, F3 + F4 |
| `AdminEditEventTest.php` | F5, F6 (including WR-06), F7 (table and edit page header), F8 |
| `EventPageTest.php` | F9 (page, calendar links, share block, Copy link) |
| `SystemHealthTest.php` | F10 page: stale workers, failed-jobs badge, Retry / Delete |
| `SubmitEventTest.php` | F11: thank-you page with a WOA- reference, nothing on `/`, "Somewhere else" fields, error summary with typed values kept, noscript notice |
| `ReviewQueueTest.php` | F12 (queue, review page reasons, Raw content, edit with Create new venue and a weekly repeat, Save & approve opens the next draft, four dates on `/`) and F13 (bulk approve skip reasons, bulk reject with Spam, Reopen) |
| `ApproveAndNextKeyboardTest.php` | F12 keyboard shortcuts: A then Enter, the guards while typing or in a dialog, ?, J / K, the remembered off switch, A on a blocked draft |
| `RulesTest.php` | F14: a keyword rule decides the next submission's category; Other has no Delete; a category in use is refused with "Rules not saved" |
| `SourcesTest.php` | F15 (the "?" field help, New source saved inactive, Test run preview, Create drafts, run log, Run now, Pause, Healthy in the table, drafts in the queue; a seeded source's all-day rows show the date only) and F20 (Listed by with the item link, Also listed by after Mark as duplicate, Read more at, card "via") |

How they differ from the manual run:

- The app is served in-process on the `attleborough_testing` database with `RefreshDatabase`. No `php artisan serve` is needed and the dev database is never touched, so the cleanup steps don't apply.
- Time is frozen in Europe/London, and data is published through `PublishEvent` (`Tests\Browser\UatFixtures`). Drafts are written through the submission path (`UatFixtures::submissionDraft()` → `DraftWriter::create(DraftData::fromSubmission(...))`). The form's timing check is passed by moving the frozen clock forward 5 seconds before sending.
- Ingestion is offline too (Phase 3): `UatFixtures::fakeIngestion()` fakes every feed URL with its captured fixture from `tests/Fixtures/sources` (robots.txt allows all, other URLs answer 404), binds the fake HostResolver (no DNS) and fakes `Sleep` and the raw-body disk. `UatFixtures::ingestedSource()` takes a seeded source live through RunLauncher (test run, then Create drafts) and `UatFixtures::runSource()` runs a check; changed feeds are fixture copies served with `UatFixtures::serveFeed()`. Queued runs are worked off the real database queue (`UatFixtures::workQueue()`), so no worker runs in the background.
- Offline: no Turnstile site key is set in tests, so the widget and Cloudflare's script are never rendered, and `UatFixtures::fakeTurnstile()` binds a verifier that accepts any token. No request leaves the app. The date pickers are driven through their Year and Month controls, so the tests don't depend on the browser's real date.
- Selectors are Playwright role and label locators (`UatFixtures::role('button', 'Hide event')`), as in the conventions above.
- Still manual: the worker drill in F10 (`schedule:work`, `queue:work`, `ops:drill`), the `.ics` headers (covered by `tests/Feature/Public/EventCalendarAndShareTest.php`), the branded error pages (covered by `tests/Feature/Public/ErrorPagesTest.php`) and the phone-only checks in F9. From Phase 2: `/submit` with JavaScript really switched off (the tests check the fields are visible before any choice and the `<noscript>` text is in the source), the real Turnstile widget with Cloudflare's test keys, and the rate limit (covered by `tests/Feature/Submissions/`).
- Since quick task 261017-hp9, `PublicCalendarTest.php` F2 reopens **More filters** after each reload and removes a filter with its "Remove filter:" chip, and also checks a filtered page on 375×812 stays above the fold; `EventPageTest.php` F9 uses the "Apple / Outlook (.ics) for …" link name.

Run them:

```bash
npm ci --ignore-scripts && npx playwright install chromium   # once
npm run build                                                # tests use public/build
vendor/bin/pest --testsuite=Browser
```

CI runs them in a separate "Browser UAT" step after the Unit and Feature suites. A failing test saves a screenshot to `tests/Browser/Screenshots/` (git-ignored). When a flow changes, update its test together with this runbook.

## Known-issues baseline (2026-10-17)

Found in the first run; none blocks Phase 1. Treat them as known until fixed. Five items were fixed on 2026-10-17 in quick task 261017-gac (filter chips above the fold, Occurrences refresh after Save, honest read-only Repeats with an inline callout, explicit dialog buttons, live failed-jobs badge) and are no longer listed. Three Phase 1 UI-review items (branded error pages, filtered views above the fold, event-page date links) were fixed on 2026-10-17 in quick task 261017-hp9; none of them was a row here:

| Area | Issue |
|---|---|
| Public (phone) | Card category icon takes about a quarter of the width, so titles and venues wrap early. |
| Admin, event form | "Next dates" says "No upcoming dates" before a last date is entered; the 3-date cap isn't labelled. |
| Admin, Occurrences | "Date key" column shows the internal recurrence key. |
| Admin, events list | All-day events show "00:00"; 24-hour times in admin vs "7:30pm" on the public site. |
| Admin, look | Not branded: Filament amber instead of Norfolk green. |
| Admin, System health | Reason shows the full server file path. |
| Admin, narrow screens | Events table needs sideways scrolling; Repeats column and actions are off-screen. |
