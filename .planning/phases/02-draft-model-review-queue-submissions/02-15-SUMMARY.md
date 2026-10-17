---
phase: 02-draft-model-review-queue-submissions
plan: 15
subsystem: ui
tags: [filament-5, review-queue, approve-and-next, keyboard-shortcuts, alpine, wcag-2.1.4]
status: complete

requires:
  - phase: 02-12
    provides: ReviewEventDraft page, ReviewActions (approve/reject/reopen/rawContent), ReviewQueue::next()/previous()
  - phase: 02-14
    provides: EditEventDraft at /admin/event-drafts/{record}/edit, EditEventDraft::END_OF_QUEUE
provides:
  - "Approve & next review page (REV-10): Approve, Reject and Skip open the next pending draft in D-09 order; after the last one the list opens with \"You've reached the end of the queue\""
  - ReviewActions::edit(), skip(), previous() (hidden on the first draft), help(); every review-page button shows its key and carries aria-keyshortcuts
  - ReviewEventDraft::approveFromKeyboard() (blocked draft → "Can't approve yet" warning listing the blockers; otherwise the approve confirmation opens) and editUrl()
  - One guarded Alpine keydown handler plus a "Keyboard shortcuts" toggle remembered in localStorage `woa-shortcuts`
  - keyboard-help.blade.php (the help dialog's content)
affects: [02-16]

tech-stack:
  added: []
  patterns:
    - "Single-key shortcuts: one x-on:keydown.window handler in the page view, never Filament's keyBindings() (Mousetrap global bindings fire inside inputs). Guards: toggle off, Ctrl/Cmd/Alt, key repeat, IME composition, target inside input/textarea/select/contenteditable/combobox/textbox, any mounted action or open .fi-modal-open"
    - "A keyboard shortcut that needs a server-side check calls a public Livewire method (approveFromKeyboard) that either warns or mountAction()s; other keys mountAction() the header action directly, so hidden actions do nothing"
    - "Confirmation modals list 'Keep as is' first; to make Enter confirm, autofocus the submit action via modalSubmitAction(fn (Action $a) => $a->extraAttributes(['autofocus' => true]))"

key-files:
  created:
    - resources/views/filament/resources/event-drafts/keyboard-help.blade.php
    - tests/Feature/Filament/ApproveAndNextTest.php
    - tests/Browser/ApproveAndNextKeyboardTest.php
  modified:
    - app/Filament/Resources/EventDrafts/Actions/ReviewActions.php
    - app/Filament/Resources/EventDrafts/Pages/ReviewEventDraft.php
    - resources/views/filament/resources/event-drafts/pages/review-event-draft.blade.php
    - tests/Feature/Filament/ReviewQueueTracerTest.php

key-decisions:
  - "Publish is autofocused in the approve dialog, so A then Enter publishes (D-10). Filament puts 'Keep as is' first in confirmation modals, so without this Enter backed out. Mouse users get the same focus, and Escape or 'Keep as is' still back out. Publishing can be undone by hiding the event"
  - "The typing guard covers every form control, including the shortcuts checkbox itself. Right after ticking the box, focus is on it, so keys do nothing until the admin moves focus away. That is safer than special-casing checkboxes"
  - "Skip and Edit are only shown while the draft the page shows is pending. Previous is shown whenever ReviewQueue::previous() finds a draft, and hidden on the first. Reject keeps the label 'Reject' in the queue table and is 'Reject (R)' only on the review page"
  - "Skip's aria-keyshortcuts is 'S J' (two alternatives); help's is '?'"
  - "The end-of-queue redirect reuses EditEventDraft::END_OF_QUEUE. EditEventDraft's own next-draft redirect was left alone because it was outside this plan's files"
  - "Commits went to main. Sequential mode with branching_strategy none (02-01..02-14 also committed to main). The executor's protected-branch check reported main as protected"

patterns-established:
  - "Keyboard shortcut browser tests press keys on 'html > body' (a bare 'body' is treated as text by pest-plugin-browser's locator guess) and on ':focus' when a dialog has focus"

requirements-completed: [REV-10]

duration: 10min
completed: 2026-10-17

actuals:
  tokens: 7229
  tasks: 2
  commits: 4
plan_head_before: 96a08cf750205afa3a2eb6712194b42409ed538c
plan_head_after: e389101a01f78cd7716e74ff1de9c937bef7a480
---

# Phase 2 Plan 15: Approve & next with guarded keyboard shortcuts Summary

**The review page is now "Approve & next". Approve, Reject and Skip open the next pending draft in D-09 order, and the A/E/R/S/J/K/? shortcuts run through a single guarded Alpine handler with an on/off toggle the browser remembers. A real-browser test checks that a stray "a" typed into the reject note publishes nothing.**

## Performance

- **Duration:** ~10 min
- **Started:** 2026-10-17T21:06:34Z
- **Completed:** 2026-10-17T21:15:20Z
- **Tasks:** 2
- **Files modified:** 7 (3 created, 4 modified)

## Accomplishments

- Review page header: **Approve & next (A)**, **Edit (E)**, **Reject (R)**, **Skip (S)**, **Previous (K)**, Reopen, Raw content, **Keyboard shortcuts (?)**. Each button has an `aria-keyshortcuts` attribute.
- After an approve, reject or skip, `ReviewQueue::next()` decides where to go: the next draft's review page, or the queue list with "You've reached the end of the queue" after the last draft. Previous uses `ReviewQueue::previous()` and is hidden on the first draft.
- `approveFromKeyboard()`: on a blocked draft, a warning titled "Can't approve yet" lists the blockers and nothing is approved. Otherwise the "Publish this event?" dialog opens with Publish focused, and Enter publishes. `ApproveDraft` still re-checks on the server (T-02-40).
- Keyboard handler guards (T-02-39): shortcuts are ignored when the toggle is off, with Ctrl/Cmd/Alt held, on key repeat or IME composition, while focus is in any form field, combobox or contenteditable element, and while any action is mounted or a modal is open. `preventDefault()` runs only for keys the page handles.
- Visible toggle: "Keyboard shortcuts (A approve · E edit · R reject · S/J next · K previous · ? help)", saved in localStorage as `woa-shortcuts` (WCAG 2.1.4). The help dialog lists every key and explains when shortcuts are off.

## Task Commits

1. **Task 1 (tracer): Approve & next actions, keyboard handler, Livewire test**
   - `be6bfa6` test(02-15): add failing Approve & next review page test (RED)
   - `5611031` feat(02-15): Approve & next review page with guarded keyboard shortcuts (REV-10) (GREEN)
   - Tracer gate: `<verify>` re-run passed (37/37). ⚡ Tracer verified end-to-end, expanding
2. **Task 2: Real-browser proof**
   - `1db1e14` test(02-15): real-browser proof of review shortcuts, guards and off switch (RED: Enter backed out)
   - `e389101` fix(02-15): focus Publish in the approve dialog so A then Enter publishes (GREEN)

## Files Created/Modified

- `app/Filament/Resources/EventDrafts/Actions/ReviewActions.php`: key labels and aria-keyshortcuts; new edit/skip/previous/help actions; `openNext()` redirect after approve and reject on the review page; Publish autofocused.
- `app/Filament/Resources/EventDrafts/Pages/ReviewEventDraft.php`: `approveFromKeyboard()`, `editUrl()`.
- `resources/views/filament/resources/event-drafts/pages/review-event-draft.blade.php`: guarded Alpine handler and toggle.
- `resources/views/filament/resources/event-drafts/keyboard-help.blade.php`: help dialog content (static).
- `tests/Feature/Filament/ApproveAndNextTest.php`: 12 Livewire cases (next after approve, reject and skip; previous and its hiding; end of queue; blocked A; help; labels and aria; decided draft).
- `tests/Browser/ApproveAndNextKeyboardTest.php`: 6 Playwright cases.
- `tests/Feature/Filament/ReviewQueueTracerTest.php`: docblock wording only.

## Decisions Made

See `key-decisions` in the frontmatter. The main one: Publish is autofocused, so the flow is two keystrokes (A, then Enter) with a confirmation in between.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Enter in the approve dialog backed out instead of publishing**
- **Found during:** Task 2 (browser test)
- **Issue:** Filament confirmation modals put the cancel action ("Keep as is") first, and the focus trap focuses it, so A then Enter closed the dialog without publishing. That breaks D-10's "A opens the confirm dialog, Enter publishes".
- **Fix:** `modalSubmitAction(fn (Action $action) => $action->extraAttributes(['autofocus' => true]))` on approve. The browser test now checks that Publish has focus before pressing Enter.
- **Files modified:** app/Filament/Resources/EventDrafts/Actions/ReviewActions.php
- **Commit:** e389101

**2. [Rule 3 - Blocking] Shortcuts toggle needed a stable id for the browser test**
- Added `id="woa-shortcuts-toggle"` to the checkbox (commit e389101).

**3. [Process] Commits on `main`**
- The pre-commit protected-branch check reports `main` as protected. The orchestrator asked for sequential mode on the main working tree with `branching_strategy: none`, matching 02-01..02-14.

Additions beyond the plan's wording (not deviations in behaviour): Skip has `aria-keyshortcuts="S J"`; the handler also ignores key repeat and IME composition; Skip at the end of the queue opens the list with the end-of-queue notice; a decided draft offers no Approve/Edit/Reject/Skip, and `approveFromKeyboard()` does nothing on it.

## Verification

- `./vendor/bin/pest --testsuite=Unit,Feature tests/Feature/Filament/ApproveAndNextTest.php tests/Feature/Filament/DraftDetailTest.php`: 37 passed.
- `npm run build` then `./vendor/bin/pest --testsuite=Browser tests/Browser/ApproveAndNextKeyboardTest.php`: 6/6 passed, 3 consecutive runs. Covered: A then Enter publishes and opens B; "a", "s", "k" typed into the reject note (the textarea holds "ask"), the panel search and the toggle trigger nothing and B stays pending; ? opens help; K and J navigate; toggle off survives a reload, S does nothing, toggle on, S opens C; A on a draft with no venue shows "Can't approve yet" and "Pick or create a venue."; assertNoJavaScriptErrors throughout.
- Pint passed; PHPStan 0 errors (`--memory-limit=1G`).
- Full Unit+Feature suite: **990 passed** (5127 assertions). The `warnings: 1` existed before this plan.

## Known Stubs

None.

## Threat Flags

None. The shortcuts only call existing review actions on the admin-only page; nothing reaches public pages.

## Issues Encountered

- pest-plugin-browser treats a bare `body` selector as text to find, so presses timed out. The tests use `html > body`.

## User Setup Required

None.

## Next Phase Readiness

- REV-10 is done. 02-16 can build on the review page, its actions and the keyboard handler.

## Self-Check: PASSED
