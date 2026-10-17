# Phase 01 — UI Review

**Audited:** 2026-10-17
**Baseline:** Abstract 6-pillar standards (no UI-SPEC.md), plus the locked look decisions in 01-CONTEXT.md (D-01, D-05 to D-08) and the AGENTS.md public-site rules (WCAG 2.2 AA, 24×24px targets, visible focus, `<time datetime>`, "Cancelled" as text, escaped Blade, no third-party assets)
**Screenshots:** captured. 16/16 Playwright CLI captures against http://localhost:8000 in `.planning/ui-reviews/01-20261017-123710/` (git-ignored): `/`, `/events/uat-quiz-night`, `/events/first-demo-event` and `/?when=weekend&category=community`, each at 1440×900, 768×1024 and 375×812, plus full-page 375px captures of `/` and the quiz event in light and dark. I also measured the live DOM at 375×812 with Playwright: fold position, targets under 24px, overflow and script count.
**Interaction captures:** off (workflow.ui_interaction_capture is false). Every interaction finding below comes from reading the code or the static DOM.

Admin (Filament) was audited from the code only (`app/Filament/**`, `resources/views/filament/**`, `AdminPanelProvider`). I did not log in.

---

## Pillar Scores

| Pillar | Score | Key Finding |
|--------|-------|-------------|
| 1. Copywriting | 3/4 | D-05 voice and UK formats are applied well on `/`. The gaps: an unbranded "Not Found" page, ".ics" jargon, the same thing labelled differently on the card and the event page ("Source:" vs "More information:", "By" vs "Organised by"), and the System health dialogs still say Cancel/Confirm |
| 2. Visuals | 3/4 | Clear focal point and brand (D-06/D-07 met). The weak spots: on a phone the event page's "Google Calendar" link drops onto its own line away from its date, each date of a series repeats a full identical card, a cancelled card looks just like a live one, and the event page has no visual anchor |
| 3. Color | 3/4 | Ten semantic tokens, no hardcoded colours in views, every pair passes AA (measured). But green is used for links, tags, icon tiles, chips, headings and buttons alike (35 accent class uses), so it no longer marks what is tappable. Admin is still amber |
| 4. Typography | 3/4 | 5 sizes and 4 weights (above the 4-size / 2-weight guide). Heading sizes are back to front: day headings (h2, 16px) are smaller than the card titles (h3, 18px) under them |
| 5. Spacing | 3/4 | Every value is on Tailwind's scale and there are no arbitrary values. Problems: on a phone the dates rows wrap with 44px gaps between lines, the tag pills have no vertical padding, and card lines run from 0 to 8px apart with no clear pattern |
| 6. Experience Design | 2/4 | There are no custom error pages. By design a hidden event, an old share link or a bad category slug ends on Laravel's plain "Not Found" with no way back. Any category, Free or Family-friendly filter pushes results back below the fold (heading at 736px on an 812px screen). The admin dashboard is Filament's stock widgets |

**Overall: 17/24**

---

## Top 3 Priority Fixes

1. **No branded error pages (404 / 500 / 503)** (WARNING, the most serious finding). `resources/views/errors/` does not exist. `curl /events/no-such-thing` returns 404 with `<title>Not Found`, no wordmark, no D-05 voice and no link back. The app sends residents here on purpose: hidden events 404 (F7), unknown category slugs 404, and old WhatsApp or Facebook shares of a hidden event 404. *Impact:* a resident who taps a shared link lands on a dead end that doesn't look like the site. *Fix:* add `errors/404.blade.php` (plus `500` and `503`) extending `layouts.app`, for example "We can't find that event. It may have been taken down or the link may be wrong." with a link "See what's on" to `/`. Keep it static, with no script and no queries, so it still renders when the app is failing.

2. **Filtered views push the first result below the fold again** (WARNING). The 261017-gac fix was only measured on `/` (first heading at 369px). Because the `<details>` renders open whenever `hasAttributeFilters()` is true (`filter-chips.blade.php:25`), `/?category=community` puts its first day heading at **737px** and `/?free=1` at **736px** on a 375×812 screen, in both themes. The first card title is below 812px. *Impact:* after tapping a category the visitor sees the chips and no events, and that is the main reason to filter. *Fix:* keep the disclosure closed even when a filter is active. Show the active attribute filters as removable chips beside the summary line ("Showing: Community ✕") and add a count to the summary ("More filters (1)"). Or put the results summary line and a "Jump to results" link straight after the When chips. Add a check to F1/F2 that `main h2` is above 812px for `/?category=community`.

3. **Event page dates: on a phone the "Google Calendar" link sits alone on the next line, and ".ics" is jargon** (WARNING). `events/partials/dates.blade.php:17-23` uses `flex-wrap gap-x-3` with two `tap-target` links. At 375px "Google Calendar" wraps onto its own 44px line, flush left under the date, so it reads as a separate item. With 10 dates that is 20 rows. "Add to calendar (.ics)" uses a file extension most residents won't know. *Impact:* a resident can't easily tell which link belongs to which date, and that is the task this page exists for. *Fix:* put the date on its own line, then a row with two buttons, "Apple / Outlook (.ics)" and "Google Calendar" (or "Add to calendar" → "Download file" / "Google"), indented under the date. Or put each date in a bordered `<li>` with `gap-y-1`, so the visual grouping matches the screen-reader names (which already include the date).

---

## Detailed Findings

### Pillar 1: Copywriting (3/4)

What works: empty states follow D-05 ("Nothing on today. Why not check the weekend?", "You've reached the end of the list. Back to the first page", "No events match these filters. Try removing one, or see everything coming up." in `calendar/partials/empty-state.blade.php:11-27`). Times match the UK format ("Fri 30 Oct, 8pm", ":00" dropped). Headings read "Today" / "Friday 30 October". Status dialogs name the action and dismiss with "Keep as is" (`StatusActions.php:152-155, 177-180`). Share copy and "Link copied." are plain.

- **WARNING: unbranded 404.** `/events/no-such-thing` → `<title>Not Found`, with 0 mentions of "What's On" in the body. See Top fix 1.
- **WARNING: ".ics" jargon.** `events/partials/dates.blade.php:22` "Add to calendar (.ics)". The second link, "Google Calendar" (line 23), is a bare noun with no verb, so the two labels don't read as a pair. Suggested pair: "Add to Apple / Outlook" and "Add to Google Calendar".
- **WARNING: the same thing labelled differently on the card and the event page.** The card says `By {organiser}` (`occurrence-card.blade.php:36`) and `Source: {host}` (line 39). The event page says `Organised by {organiser}` (`events/show.blade.php:75`) and `More information: {host}` (line 84). Pick one wording; "Organised by" and "More information" are the friendlier pair.
- **WARNING: Admin System health dialogs still say Cancel / Confirm.** `app/Filament/Pages/SystemHealth.php:111-121`: the Retry and Delete actions set `modalDescription` but no `modalHeading`, `modalSubmitActionLabel` or `modalCancelActionLabel`. They break the "submit names the action, dismiss says Keep as is" pattern set in 261017-gac (that summary flagged it as out of scope; it is not in the browser-uat baseline table). Fix: "Retry job" / "Delete record" and `StatusActions::KEEP_LABEL`.
- **Minor: the events list has no custom empty state.** `EventsTable.php` has no `emptyStateHeading`/`emptyStateDescription`, so a new admin sees Filament's generic "No events" with no pointer to "New event".
- **Minor: the home `<title>` repeats itself.** "What's on | What's On Attleborough" (`layouts/app.blade.php:7` with the default `"What's on"`). Use the tagline-led "What's On Attleborough: events in Attleborough this week" or the site name alone on `/`.
- **Minor: the attribution is hard-coded.** `events/show.blade.php:87` "Listed by What's On Attleborough." should use `config('site.name')` like everything else (D-01 single source).
- Baseline (confirmed, not new): the "Date key" column (`OccurrencesRelationManager.php:56`), "No upcoming dates" placeholder before a last date is chosen (`EventForm.php:191`), and the demo data "5£" / "town center" (owner data).

### Pillar 2: Visuals (3/4)

What works: wordmark + calendar-pin icon (D-07) is the first thing in the header. "What's on" h1 → When chips → date-grouped cards give a clear reading order. Category icon tiles stand in for images (D-06). The active chip has a ✓ as well as the fill (not colour alone). The Cancelled badge is text in a bordered pill. No horizontal overflow at 375px. Dark mode works (screenshot `home-mobile-dark-full.png`).

- **WARNING: the "Google Calendar" link sits alone on its own line** (see Top fix 3; visible in `event-mobile-full.png` and `demo-mobile.png`).
- **WARNING: each date of a series repeats an identical full card.** On `/` UAT Quiz Night appears three times with the same icon, venue, category, price, the full description and the contact (`home-mobile-full.png`; each card is about 270px tall). With weekly series, one event can fill whole screens. Consider a compact card for repeat dates of the same series within a page (title + time + "Also on …"), or drop the excerpt and organiser from the card (they are on the event page).
- **WARNING: a cancelled card looks like a live one.** Only the red pill after the title marks it (`occurrence-card.blade.php:18`). The card keeps full-strength text, the green tile and the price, so it scans as an event you can go to. Mute the card when it is cancelled (`text-muted` body, `bg-surface`, maybe strike through the time) while keeping the word "Cancelled".
- **WARNING: the event page has no visual anchor.** The page is a stack of `text-lg` h2 sections in one column. The category icon only appears at 16px inside a tag (`events/show.blade.php:48`). D-06 makes category icons the image placeholder, but the page has no icon tile. Add the 48–64px icon tile next to or above the h1 (as on the card) and give "Upcoming dates" a surface panel so the main task stands out.
- **Minor: tags look like controls.** The price, Family-friendly and category pills (`bg-accent-soft text-accent rounded-full`) look like the filter chips, but they don't do anything (see Color).
- **Minor: desktop is just the phone layout centred.** At 1440px the `max-w-2xl` column leaves about 770px empty (`home-desktop.png`), and "More filters" stays closed even though there is plenty of room. That's fine for list-first, but no breakpoint changes anything.
- Baseline (confirmed): the card icon tile is about a quarter of the width on a phone (48px tile + 12px gap out of 311px content), so titles and venues wrap early.

### Pillar 3: Color (3/4)

Tokens (`resources/css/app.css`): `@theme static` with the default palette removed and 10 semantic tokens; the dark theme follows the device (D-08). Views use **no hardcoded colours** (only the two `theme-color` metas in `layouts/app.blade.php:10-11`, which have to be literal). Contrast I measured from the tokens:

| Pair | Light | Dark |
|---|---|---|
| accent / bg | 6.38 | 9.91 |
| accent / accent-soft (pills) | 5.58 | 6.78 |
| muted / bg | 7.74 | 8.55 |
| cancel / bg | 6.57 | 9.42 |
| accent-ink / accent (active chip) | 6.38 | 9.91 |
| line / bg (chip borders) | 4.52 | 4.05 |

All pass AA.

Class use: `text-accent` 22, `bg-accent-soft` 8, `border-accent` 5, `bg-accent` 1, `text-accent-ink` 1, against `text-ink` 23, `text-muted` 18 and `text-cancel` 4.

- **WARNING: green is used for too many different jobs.** The one accent is used on at least 12 kinds of element: wordmark, logo icon, card title links, inline links, footer link, back link, calendar links, share links, the active-chip fill, the day-heading bar (`calendar/index.blade.php:23`), the card icon tile, the price, family and category pills, the "More events" button, the Share / Copy buttons and the focus ring. Because the static pills (`occurrence-card.blade.php:25,28`, `events/show.blade.php:48-53`) use the same green text as links, colour no longer tells you what you can tap. Fix: show the pills in `bg-surface text-muted` (or `text-ink`) and keep green for things you can tap plus the brand mark.
- **WARNING: Admin not branded** (baseline, confirmed): `AdminPanelProvider.php:33` `'primary' => Color::Amber`. D-06 wants Norfolk green (`Color::hex('#2E6B3F')`).
- **Minor: the line token is heavy.** `--color-line #6B7A71` (4.5:1) is used for chip borders, where 3:1 is needed, but also for card borders and the footer rule. That makes every card a dark outline (`home-desktop.png`). Consider a lighter `--color-divider` for card and footer borders and keep `line` for controls.

### Pillar 4: Typography (3/4)

Self-hosted Atkinson Hyperlegible Next Variable (no CDN). Sizes: `text-sm` ×18, base, `text-lg` ×6, `text-xl` ×1 (wordmark), `text-2xl` ×2 (h1), so **5 sizes**. Weights: `font-semibold` ×21, `font-bold` ×5, `font-medium` ×3, plus normal, so **4 weights**.

- **WARNING: heading sizes are back to front on `/`.** Day headings `h2` are `font-semibold` at the base size, 16px (`calendar/index.blade.php:23`). The card titles `h3` under them are `text-lg font-semibold`, 18px (`occurrence-card.blade.php:18`). The document outline says the date groups the cards, but the eye reads them the other way round (see "Today" vs "First Demo Event" in `home-desktop.png`). Make the day headings `text-lg font-bold` and the card titles `text-base font-semibold`, or keep both at 18px with a clear weight or colour difference.
- **WARNING: too many weights.** `font-medium` (times, venue name, dates list), `font-semibold` (headings, chips, buttons) and `font-bold` (h1, wordmark, Cancelled badge) are on top of regular text. Medium vs regular is barely visible in Atkinson at 16px (`occurrence-card.blade.php:19`). Fold `medium` into regular (or into semibold for times) to end up with 2–3 weights.
- **Minor: event-page h2s are the same size as card titles** (`text-lg font-semibold`, `events/show.blade.php:60,68,73`), so the event page has only two visible heading levels (h1 24px, the rest 18px).
- **Minor:** Atkinson Hyperlegible Next uses a slashed zero by default, so dates read "Fri 3Ø Oct" in every screenshot. It helps legibility but looks technical for a community noticeboard. Check whether the variable font offers an unslashed zero (`font-feature-settings`) and use it for date text.

### Pillar 5: Spacing (3/4)

No arbitrary values (`grep \[..px|rem\]` → 0). Top utilities: `mt-2` ×12, `mt-6` ×10, `px-2` ×8, `gap-2` ×7, `mt-1` ×5, `gap-1` ×5, `px-4` ×4, `mt-3`/`gap-3` ×3, `p-4` ×2, `gap-4` ×2. Section spacing (`mt-6`) is the same everywhere. The `tap-target` utility gives 44px controls. The only interactive elements under 24px are the inline text links (contact, source and footer email, 17–21px tall), which the WCAG 2.5.8 inline exception covers.

- **WARNING: dates rows wrap badly on a phone.** Each `tap-target` link is 44px tall, so a wrapped row has a 44px gap and no indent, and `gap-2` between `<li>`s is smaller than the gap inside one row. Things inside one date look further apart than separate dates do. See Top fix 3.
- **WARNING: card lines are spaced at random.** Title → time `mt-1`, time → venue → category `0`, → pills `mt-2`, → excerpt `mt-2`, → organiser `mt-2`, → contact `0` (`occurrence-card.blade.php:18-41`). Seven to eight lines are packed together with no grouping between "when/where" and "about". Use `space-y-1` within a group and `mt-3` between the when/where, tags and about groups.
- **Minor: the pills have no vertical padding.** `rounded-full px-2 text-sm` with no `py` (`occurrence-card.blade.php:25,28`, `events/show.blade.php:48-53`, and the Cancelled badge on line 18) makes about 20px pills that crowd the text above and below. Add `py-0.5`.
- **Minor: the share block** uses `gap-x-4 gap-y-2` for the links but `gap-3` between groups, and the 44px-tall link row and input make the block look looser than the rest of the page.

### Pillar 6: Experience Design (2/4)

What works: no JavaScript on `/` (`document.scripts.length === 0` in both themes). Every filter is a link, and they combine and toggle. Filtered URLs are noindex. The skip link is the first focus stop. The `:focus-visible` 2px ring is global (`app.css`). `<details>` disclosure works with no JS. "More events" plus an end-of-list empty state. The share buttons ship `hidden` and only appear with JS, so there are never dead buttons. The live region says "Link copied.". Admin has confirmation dialogs on every status change, no delete, success toasts, and a live failed-jobs badge (261017-gac). There is no loading state to design, because everything is server-rendered.

- **WARNING (top priority): no error pages.** `resources/views/errors/` is missing, so 404, 500, 503 and 419 fall back to Laravel's plain pages, which have no brand, no voice and no link home. The product sends people to 404 on purpose (hidden event, unknown category slug, `/events/{bad}`), and the Phase 5 zero-downtime deploys will show 503 if maintenance mode is ever used. See Top fix 1.
- **WARNING: the above-the-fold fix only works on `/`.** It stops working as soon as an attribute filter is active (measured: 736–737px on `/?category=community` and `/?free=1`, light and dark). See Top fix 2.
- **WARNING: the "More events" pagination only goes forward.** `calendar/index.blade.php:36-40` has a next link and nothing else. On page 2+ there is no "Previous" or "Back to the first page" except the browser back button, and no "Showing x–y" position. Add `previousPageUrl()` beside it when `currentPage() > 1`.
- **WARNING: the admin dashboard is Filament's stock page.** `AdminPanelProvider.php` registers `AccountWidget` and `FilamentInfoWidget` (Filament docs and GitHub links). The single admin's landing page gives no task entry points: no "New event", no upcoming dates this week, no health status. Replace `FilamentInfoWidget` with a small "This week" / System health summary widget, or redirect `/admin` to the events list.
- **Minor: the admin events list sorts by `start_date desc`** (`EventsTable.php:22`). A long-running weekly series that started months ago sinks below one-offs, although the useful order for the admin is "Next occurrence" ascending.
- **Minor: the card's only link is the title text** (about 21–25px tall). The whole card looks like one unit but only the title is a link. Consider a block-level title link (`after:absolute after:inset-0` on the anchor, keeping the inline contact/source links above it) so the whole card is easy to tap.
- Baseline (confirmed, not new): the admin events table needs sideways scrolling on narrow screens, all-day shows "00:00", the System health Reason shows the full path, and the "Next dates" 3-date cap isn't labelled.

Registry audit: skipped (`components.json` absent; no shadcn).

---

## Files Audited

- `resources/css/app.css`
- `resources/views/layouts/app.blade.php`
- `resources/views/calendar/index.blade.php`
- `resources/views/calendar/partials/{chip,filter-chips,occurrence-card,empty-state}.blade.php`
- `resources/views/components/brand/{logo,calendar-pin}.blade.php`, `resources/views/components/category-icon.blade.php`
- `resources/views/events/show.blade.php`, `resources/views/events/partials/{dates,share}.blade.php`
- `resources/views/filament/pages/system-health.blade.php`
- `resources/js/share.js` (status strings only)
- `app/Providers/Filament/AdminPanelProvider.php`
- `app/Filament/Pages/SystemHealth.php`
- `app/Filament/Resources/Events/{Tables/EventsTable,Schemas/EventForm,Actions/StatusActions,RelationManagers/OccurrencesRelationManager}.php`
- Live pages: `/`, `/?when=weekend&category=community`, `/?category=community`, `/?free=1`, `/?page=99`, `/events/uat-quiz-night`, `/events/first-demo-event`, `/events/no-such-thing`
- Context: 01-CONTEXT.md, 01-UAT.md, docs/uat/browser-uat.md (known-issues baseline), 261017-gac-SUMMARY.md, 01-06/07/08/10 SUMMARY.md, AGENTS.md
