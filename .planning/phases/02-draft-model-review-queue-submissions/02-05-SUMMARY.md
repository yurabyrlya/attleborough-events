---
phase: 02-draft-model-review-queue-submissions
plan: 05
subsystem: public-site
tags: [laravel, blade, tailwind, form-request, submissions, privacy, accessibility, progressive-enhancement]

requires:
  - phase: 02-01
    provides: DraftWriter::create(), DraftData, EventDraft (origin/review_status/raw_payload/consented_at), ReferenceCode (unique index + regenerate loop)
  - phase: 02-03
    provides: DraftWriter drops a stale submitted category id; CategoryAssigner "Chosen by organiser" / Other fallback
  - phase: 02-04
    provides: contact_public / organiser_website carried from draft to event; contact gate on public pages
provides:
  - Public routes GET/POST /submit (submit.create / submit.store), GET /submit/thanks (submit.thanks), GET /privacy (Route::view, privacy)
  - SubmissionController (create / store / thanks, POST-redirect-GET, never writes events)
  - StoreSubmissionRequest (first FormRequest): London-today bounds, strict Y-m-d / H:i, blanks to null, friendly messages, VENUE_OTHER / CATEGORY_NOT_SURE constants
  - DraftData::fromSubmission() (wall-clock schedule as typed, raw_payload minus consent and spam fields)
  - Views submit/create (no-JS form, error summary, aria wiring), submit/thanks, privacy (interim notice)
  - resources/js/submit.js (venue reveal + error-summary focus), registered in vite.config.js, loaded from /submit only
  - Footer links "Add your event" and "Privacy" on every public page
  - validSubmission() test helper in tests/Pest.php (02-09 extends it)
affects: [02-09, 02-10, 02-14, 02-16, phase-05-legal]

actuals:
  tokens: 17558
  tasks: 3
  commits: 4
plan_head_before: ec261187dc2d330025f8e67554ecb7a8cbfcbd9b
plan_head_after: 2a0bd560aeed8b5a9d34916cc57328394d925c56

tech-stack:
  added: []
  patterns:
    - "Public forms are plain Blade POST + FormRequest + POST-redirect-GET; the confirmation page needs a flashed marker"
    - "FormRequest::prepareForValidation() trims (incl. NBSP/BOM), turns blanks into null and reads unticked checkboxes as false"
    - "'Today' bounds are computed from CarbonImmutable::now('Europe/London'), never Laravel's 'today' keyword"
    - "Conditional free-text fields use exclude_unless so hidden, half-typed values never reach validated()"
    - "Per-page first-party JS is pushed from the page's head stack; the layout loads only the CSS entry"

key-files:
  created:
    - app/Http/Controllers/SubmissionController.php
    - app/Http/Requests/StoreSubmissionRequest.php
    - resources/views/submit/create.blade.php
    - resources/views/submit/thanks.blade.php
    - resources/views/privacy.blade.php
    - resources/js/submit.js
    - tests/Feature/Submissions/SubmitEventTracerTest.php
    - tests/Feature/Submissions/SubmitValidationTest.php
    - tests/Feature/Public/PrivacyPageTest.php
  modified:
    - routes/web.php
    - app/Review/Data/DraftData.php
    - resources/views/layouts/app.blade.php
    - vite.config.js
    - tests/Pest.php

key-decisions:
  - "Typed venue fields use exclude_unless:venue_choice,other (not required_if): with a known venue picked, anything typed in the always-rendered Somewhere else fields is dropped, never stored"
  - "venue_choice / category_choice are validated by closures that accept 'other' / 'not_sure' or an existing numeric id; an unknown or non-numeric id gets the same message as a missing venue/category (SUB-03)"
  - "Public links use route(..., absolute: false), matching the existing layout and event-page convention"
  - "The consent link to /privacy opens in a new tab, labelled '(opens in a new tab)', so typed form data isn't lost"
  - "No preselected price radio: the organiser must choose Free / Paid / Not sure"

patterns-established:
  - "Error summary: role=alert, 'Please check these', one link per error to #field-id; each field has aria-invalid + aria-describedby (hint and error ids) and an sr-only 'Error:' prefix"
  - "validSubmission(array $overrides) builds a complete valid POST body relative to the frozen London date"

requirements-completed: [SUB-01, SUB-02, SUB-03]

coverage:
  - id: D1
    description: "Organiser opens /submit and sees every SUB-01 field, known venues plus 'Somewhere else', 'Not sure' category, repeats field, 'Show my contact publicly', consent linking to /privacy and 'Send for review'"
    requirement: SUB-01
    verification:
      - kind: integration
        ref: "tests/Feature/Submissions/SubmitEventTracerTest.php#shows the form with every field, the venue choice, Not sure and the consent link"
        status: pass
    human_judgment: false
  - id: D2
    description: "A valid submission creates exactly one pending_review submission draft (consented_at, raw_payload) and redirects to /submit/thanks with the D-02 sentence and reference; no event/occurrence and nothing on /"
    requirement: SUB-02
    verification:
      - kind: integration
        ref: "tests/Feature/Submissions/SubmitEventTracerTest.php#turns a valid submission into one pending draft and keeps it off the calendar"
        status: pass
      - kind: integration
        ref: "tests/Feature/Submissions/SubmitEventTracerTest.php#shows the will-be-reviewed message and the reference after the redirect"
        status: pass
      - kind: integration
        ref: "tests/Feature/Submissions/SubmitValidationTest.php#creates nothing when the thanks page is refreshed"
        status: pass
    human_judgment: false
  - id: D3
    description: "Missing date, venue or any required field re-shows the form with an error summary, aria wiring and kept values, and creates no draft; SUB-01 boundary/empty/encoding/precision/concurrency edges"
    requirement: SUB-03
    verification:
      - kind: integration
        ref: "tests/Feature/Submissions/SubmitValidationTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "Interim /privacy notice: collection, legitimate interests, public display, Turnstile, 24-hour IP rate-limit cache, retention 30/90 days, rights and hello@ contact"
    requirement: SUB-01
    verification:
      - kind: integration
        ref: "tests/Feature/Public/PrivacyPageTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "Footer of every public page links to Add your event and Privacy; submit.js is loaded from /submit only and / still has no script"
    verification:
      - kind: integration
        ref: "tests/Feature/Submissions/SubmitEventTracerTest.php#links every public page footer to the form and the privacy notice"
        status: pass
      - kind: integration
        ref: "tests/Feature/Public/NoThirdPartyAssetsTest.php"
        status: pass
    human_judgment: false
  - id: D6
    description: "With JavaScript on, the Somewhere else fields hide while a known venue is picked and show for Somewhere else; the error summary takes focus; the form reads well on a phone in light and dark mode"
    verification: []
    human_judgment: true
    rationale: "Client-side behaviour and visual layout are not covered by the feature suite (it runs withoutVite); the browser UAT flow for submissions (F11) lands in a later plan of this phase"

duration: 10min
completed: 2026-10-17
status: complete
---

# Phase 2 Plan 05: Public Submission Form Summary

**Plain-Blade /submit form (no Livewire, works without JS) that validates against London "today" and writes one pending_review submission draft through DraftWriter, with a PRG thanks page showing the WOA- reference, an interim /privacy notice and footer links on every public page.**

## Performance

- **Duration:** 10 min
- **Started:** 2026-10-17T19:15:07Z
- **Completed:** 2026-10-17T19:25:16Z
- **Tasks:** 3
- **Files modified:** 14

## Accomplishments
- Organisers can send an event at `/submit`. A valid post becomes exactly one `pending_review` draft (origin and extraction method `submission`, `consented_at`, `raw_payload` without the consent tick) and nothing touches `events`, `event_occurrences` or `/` (SUB-02 prohibition, asserted).
- `StoreSubmissionRequest` covers every SUB-01 edge: limits counted in characters (120/5000), London "today" (including 00:30 BST on 1 Jul 2026), today + 12 months, strict `Y-m-d` / `H:i`, whitespace-only title treated as missing, blank optional fields stored as null, email-or-website rule, unknown venue/category ids refused.
- The form is accessible without JavaScript: error summary linking to each field, `aria-invalid` / `aria-describedby`, an sr-only "Error:" prefix, 44px targets, visible labels, one filled primary button, escaped `old()` values.
- Interim `/privacy` notice at its permanent URL (D-04, D-12, D-17). The footer on every public page links to "Add your event" and "Privacy".
- `submit.js` hides the typed-venue fields while a known venue is picked. It loads from `/submit` only, so the layout still loads only `app.css` and `/` has no script.

## Task Commits

1. **Task 1: Tracer: /submit → StoreSubmissionRequest → DraftWriter → /submit/thanks** - `05179c8` (feat)
2. **Task 2: Validation and SUB-01 edges, PRG, privacy page** - `29e9830` (test)
3. **Task 3: Somewhere else progressive reveal and footer links** - `026ac02` (test, RED) → `2a0bd56` (feat, GREEN)

**Plan metadata:** recorded in the docs commit that follows this SUMMARY

## Files Created/Modified
- `routes/web.php` - `/submit`, `/submit/thanks` and `/privacy` routes, each marked "Public URL contract".
- `app/Http/Controllers/SubmissionController.php` - `create` / `store` / `thanks`. PRG with `submitted` and `reference` flashed; never writes events.
- `app/Http/Requests/StoreSubmissionRequest.php` - field rules, London date bounds, input normalisation, friendly messages.
- `app/Review/Data/DraftData.php` - `fromSubmission()`, `NOT_IN_RAW_PAYLOAD`, `wallClockTime()`.
- `resources/views/submit/create.blade.php` - the form (What / When / Where / Price / Who / Anything else fieldsets).
- `resources/views/submit/thanks.blade.php` - the D-02 confirmation with the reference.
- `resources/views/privacy.blade.php` - the interim notice (7 headings, "Last updated 17 October 2026").
- `resources/js/submit.js` - venue reveal and error-summary focus.
- `vite.config.js` - the `resources/js/submit.js` entry.
- `resources/views/layouts/app.blade.php` - footer links.
- `tests/Pest.php` - `validSubmission()` helper.
- `tests/Feature/Submissions/SubmitEventTracerTest.php`, `SubmitValidationTest.php`, `tests/Feature/Public/PrivacyPageTest.php` - 70+ cases.

## Decisions Made
See `key-decisions` in the frontmatter. The main one: the typed-venue fields use `exclude_unless:venue_choice,other`. They are always rendered, and with JS on they are only hidden, so a value typed before a known venue was picked would otherwise reach the draft.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Accessibility] submit.js moves focus to the error summary**
- **Found during:** Task 3
- **Issue:** The plan's submit.js only handled the venue reveal. After a failed POST, keyboard and screen-reader users started at the top of the page, not at the errors.
- **Fix:** If `[data-error-summary]` is present (it has `tabindex="-1"`), submit.js focuses it. `role="alert"` already announces it when JS is off.
- **Files modified:** resources/js/submit.js
- **Committed in:** 2a0bd56

**2. [Convention] Relative route URLs in the layout footer and the consent link**
- **Found during:** Task 3
- **Issue:** One acceptance grep expects the literal `route('privacy')`. The layout and the event pages build public links with `route(..., absolute: false)`.
- **Fix:** Kept the existing convention: `route('privacy', absolute: false)`, which renders `href="/privacy"`. `grep -c "route('privacy'"` returns 1. The intent (footer links to the privacy route) is met and tested.
- **Committed in:** 2a0bd56

**3. [Process] Task 2 tests passed on their first run (TDD unexpected GREEN)**
- **Found during:** Task 2
- **Issue:** Task 1's action already specified the full rule set, the messages and the aria wiring. Task 2's new tests passed straight away, so there is no failing-test commit before Task 2's code. Task 2 changed no production code, so its only commit is `test(...)`.
- **Check:** A temporary mutation (UTC "today", `max:121` on the title) made the 00:30 BST test and the 121-character title tests fail. The mutation was reverted with `git checkout -- <file>`. The tests do catch real regressions.
- **Committed in:** 29e9830

**4. [Process] Commits on `main`**
- `gsd-tools git.base-branch --is-protected main` returns true. The orchestrator dispatched this plan in sequential mode on the main working tree (`branching_strategy: none`, `use_worktrees: false`), and 02-01 to 02-04 committed the same way, so the per-commit protected-branch assertion was not enforced.

---

**Total deviations:** 1 auto-fix (Rule 2), 1 convention alignment, 2 process notes.
**Impact on plan:** No scope creep. All plan truths, artefacts and key links are in place.

## Issues Encountered
- `assertSee()` escapes its needle, so copy containing apostrophes ("We'll", "what's") needed `assertSee(..., false)`.

## Known Stubs
None. The `placeholder="https://"` attributes on the URL inputs are input hints, not stubs.

## User Setup Required
None. No external service configuration is needed. Turnstile keys arrive with 02-09.

## Next Phase Readiness
- 02-09 (spam defence) can go in front of `SubmissionController::store()`. `DraftData::NOT_IN_RAW_PAYLOAD` already removes `fax_number`, `form_token` and `cf-turnstile-response`, and `validSubmission()` is ready to extend.
- 02-10 (rate limit) adds `->middleware('throttle:submissions')` to the `submit.store` route.
- `/privacy` already describes Turnstile and the 24-hour IP cache, ahead of 02-09 and 02-10 shipping them (flagged assumption in the plan).
- The browser UAT flow for submissions (F11) is still to be added in `docs/uat/browser-uat.md` by a later plan; D6 needs a human or browser check.

---
*Phase: 02-draft-model-review-queue-submissions*
*Completed: 2026-10-17*

## Self-Check: PASSED

All 9 created files present; commits 05179c8, 29e9830, 026ac02, 2a0bd56 are ancestors of HEAD; full Unit+Feature suite 714/714, Pint and PHPStan clean, `npm run build` succeeds.
