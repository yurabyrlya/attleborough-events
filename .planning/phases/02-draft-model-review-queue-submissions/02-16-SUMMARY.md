---
phase: 02-draft-model-review-queue-submissions
plan: 16
subsystem: testing
tags: [browser-uat, pest-browser, playwright, filament-5, submissions, review-queue, rules]
status: complete

requires:
  - phase: 02-09
    provides: Turnstile verifier (deliberately not final) and honeypot timing token
  - phase: 02-11
    provides: Rules screen (four tabs, Save rules, SaveRules refusals)
  - phase: 02-13
    provides: bulk approve/reject with named skip reasons, Rejected tab, Reopen
  - phase: 02-15
    provides: Approve & next review page and ApproveAndNextKeyboardTest (F12 keyboard)
provides:
  - "Runbook flows F11 (submit an event), F12 (review queue, edit-and-approve, Approve & next), F13 (bulk approve/reject, Reopen), F14 (Rules screen); F3 now expects an approved manual draft (D-07)"
  - tests/Browser/SubmitEventTest.php (F11), ReviewQueueTest.php (F12 + F13), RulesTest.php (F14); F3 draft assertion in AdminCreateEventTest.php
  - "UatFixtures::fakeTurnstile(), submissionDraft() (real DraftWriter::create(DraftData::fromSubmission()) path), roleContaining() (role locator for names that include a count badge)"
affects: [phase-02-verification, phase-03-ui-flows]

tech-stack:
  added: []
  patterns:
    - "Offline Turnstile in browser tests: no site key (no widget, no Cloudflare script) plus app()->instance(TurnstileVerifier::class, <passing subclass>)"
    - "Pass the honeypot timing token in a browser test by moving the frozen clock: test()->travel(5)->seconds() before clicking Send"
    - "Filament sidebar links and list tabs carry their badge in the accessible name: match with Uat::roleContaining(); list tabs are buttons, schema tabs (Rules) are role=tab and lazy-loaded via x-load, so wait for ._x_dataStack before clicking"
    - "Filament bulk actions sit in a 'Bulk actions' dropdown whose items are role=menuitem; scope dialog buttons with .fi-modal-open"
    - "Repeater rows are UUID-keyed (form.<repeater>.<uuid>.<field>): read the id in the page, then fill and settle it"

key-files:
  created:
    - tests/Browser/SubmitEventTest.php
    - tests/Browser/ReviewQueueTest.php
    - tests/Browser/RulesTest.php
    - .planning/phases/02-draft-model-review-queue-submissions/deferred-items.md
  modified:
    - docs/uat/browser-uat.md
    - tests/Browser/UatFixtures.php
    - tests/Browser/AdminCreateEventTest.php

key-decisions:
  - "F14 'Other can't be removed' is proven as the UI enforces it: the Other row has no Delete button (every other row has one). A browser can't reach the 'Rules not saved' refusal for Other, so the visible-refusal check uses a category in use ('Community' is used by …), the same SaveRules refusal path"
  - "Save & approve has no confirmation dialog (02-14 design: save and publish in one transaction); F12 clicks it directly instead of confirming"
  - "The known venue in F11 is the fixture venue (Uat::venue(), 'UAT Town Hall'), not 'Connaught Hall', so test data stays UAT-prefixed"

patterns-established:
  - "Every Phase 2 UI flow has a runbook section in the F0-F10 shape and a browser test named after it"

requirements-completed: [SUB-01, SUB-02, SUB-03, REV-01, REV-03, REV-08, REV-10, ADM-04]

duration: 14min
completed: 2026-10-17

actuals:
  tokens: 10050
  tasks: 3
  commits: 3
plan_head_before: 734c250f3b9cb194099df3db7f4985fed2bad10a
plan_head_after: dab9909c05defc80da6d6275cb232d1f9c6a9f82
---

# Phase 2 Plan 16: Browser UAT flows F11–F14 Summary

**The runbook and the Pest/Playwright browser suite now cover every Phase 2 screen, offline: submit an event, review/edit/approve with venue creation and a weekly repeat, bulk approve skip reasons, bulk reject and reopen, and the Rules screen. The full suite (F0–F14, 33 tests) passed three runs in a row.**

## Performance

- **Duration:** ~14 min
- **Started:** 2026-10-17T21:18:24Z
- **Completed:** 2026-10-17T21:32:10Z
- **Tasks:** 3
- **Files modified:** 7 (613 insertions)

## Accomplishments

- **F11** (`SubmitEventTest.php`, 4 tests): a visitor fills `/submit` and sees "Thanks for sending your event" with "Your reference: WOA-…". One pending submission draft is stored and nothing shows on `/`. The "Somewhere else" fields are visible before any choice, hide for a known venue and come back for Somewhere else; the draft keeps "The Old Barn" with no venue_id. Sending without a date shows "Please check these" with a link to `#start_date`, `aria-invalid`, focus on the summary and the typed values kept, and saves nothing. The `<noscript>` notice is in the source. No `.cf-turnstile` or Cloudflare script is rendered.
- **F12** (`ReviewQueueTest.php`): Review queue (badge 2) → first row (soonest, D-09) → review page with Medium band, "Venue not recognised: 'The Old Barn'", the repeats reason, "Chosen by organiser", the blockers and Raw content. Then Edit (E) → Create new venue (pre-filled name and postcode) → Weekly on Tuesday ×4 → Save & approve. The next draft's review page opens, and `/` shows four dated cards (27 Oct to 17 Nov).
- **F13**: select all → Approve selected shows "Approved 1 of 3. The rest were skipped:", "1 skipped: Low quality" and "1 skipped: has parse errors", and only the publishable draft reaches `/`. Reject selected with Spam sends both drafts to the Rejected tab; Reopen puts one back on Pending.
- **F14** (`RulesTest.php`): add keyword rule "pumpkin" → Community and save ("Rules saved"). A "Not sure" submission titled "UAT Pumpkin carving" then reads Community, with "Keyword rule: 'pumpkin' → Community" on its review page. The Other row has no Delete button; deleting the in-use Community row and saving gives "Rules not saved" ("'Community' is used by …"), with the category count unchanged.
- **F3**: the existing flow now also asserts that the sole draft is manual, approved and linked to the created event (D-07).
- Runbook: F11–F14 in the existing shape (steps, selectors, expected result, public effect), plus the F3 and F0 sidebar updates. The "Automating these flows" table gains rows for SubmitEventTest, ReviewQueueTest, ApproveAndNextKeyboardTest and RulesTest. "Still manual" gains JS-off `/submit`, the real Turnstile widget and the rate limit. The Phase 2 "next flows" bullet is removed.

## Task Commits

1. **Task 1 (tracer): F11 in the runbook and a real browser** – `4da5dd1` (test). Tracer gate: re-ran `<verify>` green before committing and before expanding.
2. **Task 2: F12 + F13** – `23de12b` (test)
3. **Task 3: F14, F3 draft check, automation table, full suite** – `dab9909` (test)

## Verification

- `npm run build && ./vendor/bin/pest --testsuite=Browser`: 33/33 passed, 442 assertions, three consecutive runs (~32 s each).
- `./vendor/bin/pest --testsuite=Unit,Feature`: 990/990 passed.
- Acceptance greps: `^## F11`…`^## F14` once each; `fakeTurnstile|submissionDraft` in UatFixtures; `EventDraft` in AdminCreateEventTest; `RulesTest.php` in the runbook.
- Pint clean. PHPStan isn't affected (its paths cover `app/` only; no app code changed).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] F14 "remove the Other row" can't be done in a browser**
- **Found during:** Task 3
- **Issue:** The plan's step was "Categories tab → remove the Other row → Save rules → 'Rules not saved'". `Rules.php` hides the Delete action on the Other row (02-11), so the browser can't trigger that refusal.
- **Fix:** The test asserts the UI guard: Other has no Delete button and every other row has one. It covers the visible refusal with a reachable case on the same SaveRules path: deleting Community while a published event uses it shows "Rules not saved" with "'Community' is used by", and the category count is unchanged. The runbook F14 describes both.
- **Files modified:** tests/Browser/RulesTest.php, docs/uat/browser-uat.md
- **Commit:** dab9909

**2. [Rule 1 - Bug] "Save & approve; confirm" has no confirm step**
- **Found during:** Task 2
- **Issue:** EditEventDraft's Save & approve runs straight away (save and approve in one transaction, no dialog).
- **Fix:** F12 clicks Save & approve and waits for the next draft. The runbook says there is no dialog.
- **Commit:** 23de12b

**3. [Rule 3 - Blocking] Selector shapes differed from the plan's assumptions**
- Sidebar link and list tabs include the badge in their accessible name ("Review queue 2", "Rejected 0"). Added `Uat::roleContaining()`.
- Bulk action items are `role=menuitem` under "Bulk actions".
- Rules repeater ids use the `form.` prefix, and schema tabs are `role=tab`, lazy-loaded (wait for Alpine).
- "Date" / "Address" labels are ambiguous as substrings, so the test uses `#start_date` / `#venue_address`.
- **Commits:** 4da5dd1, 23de12b, dab9909

### Other notes

- The plan named "Connaught Hall" as the known venue; the tests use the fixture venue "UAT Town Hall" to keep the UAT prefix convention.
- Commits are on `main` per the orchestrator's sequential mode (`git.branching_strategy: none`), as with 02-01..02-15.

## Deferred Issues

Logged in `deferred-items.md` (out of scope, not caused by this plan):
- After a bulk action, the Review queue's tab badges and the sidebar badge keep the old counts until the page is reloaded (the rows update). This is the same pattern Phase 1 fixed for the System health badge.

## Known Stubs

None. Only tests and docs changed.

## Threat Flags

None. No new app surface. `fakeTurnstile()` lives only in `tests/Browser/UatFixtures.php` and binds per test (T-02-44). Tests render no Turnstile widget or script, and `Http::preventStrayRequests()` stays on (T-02-43, SUB-04 prohibition).

## Self-Check: PASSED

- FOUND: tests/Browser/SubmitEventTest.php, tests/Browser/ReviewQueueTest.php, tests/Browser/RulesTest.php, deferred-items.md
- FOUND commits: 4da5dd1, 23de12b, dab9909 (ancestors of HEAD)
