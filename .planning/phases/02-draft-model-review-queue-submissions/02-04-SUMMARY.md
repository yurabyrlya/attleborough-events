---
phase: 02-draft-model-review-queue-submissions
plan: 04
subsystem: publishing
tags: [laravel, privacy, filament, public-site, publishing, contact]

requires:
  - phase: 02-01
    provides: event_drafts.organiser_website / contact_public, DraftData mapping of both form keys, PublishableEventData::fromDraft(), ApproveDraft, DraftWriter
  - phase: 02-03
    provides: DraftPublishability no_contact blocker already counting organiser_website
provides:
  - events.contact_public (boolean, default true) and events.organiser_website (string 2048, nullable)
  - Event fillable/casts/default for both, and Event::draft() (HasOne EventDraft where kind = new)
  - PublishableEventData organiserWebsite / contactPublic (constructor, fromForm, fromEvent, fromDraft, toAttributes)
  - Widened "how to check this" invariant: source URL, organiser contact or website
  - EventForm "Organiser website" (url) and "Show contact publicly" toggle (default on)
  - Contact gate in EventPageData and OccurrenceCardData; "Website: {host}" safe link on the event page
affects: [02-05, 02-14]

actuals:
  tokens: 6346
  tasks: 2
  commits: 4
plan_head_before: 03c3747bf73909ca59eb12445bb094fd42df3e31
plan_head_after: 23a8fba7f99103ef1cfd798c46c81789bf37d027

tech-stack:
  added: []
  patterns:
    - "Privacy gate lives in the read DTOs (EventPageData, OccurrenceCardData): views only render what the DTO returns"
    - "Visibility choice is event content (fillable), written only through PublishableEventData::toAttributes() by the Publishing actions"

key-files:
  created:
    - database/migrations/2026_10_17_100300_add_contact_public_and_organiser_website_to_events.php
    - tests/Feature/Public/ContactVisibilityTest.php
  modified:
    - app/Models/Event.php
    - app/Publishing/Data/PublishableEventData.php
    - app/Filament/Resources/Events/Schemas/EventForm.php
    - app/Support/EventPageData.php
    - app/Support/OccurrenceCardData.php
    - resources/views/events/show.blade.php
    - tests/Unit/Publishing/PublishableEventDataTest.php
    - tests/Feature/Filament/CreateEventTest.php

key-decisions:
  - "contact_public defaults to true at the DB, the model and the DTO, so every Phase 1 event and admin entry keep their public output; only submissions (DraftData default false) start private"
  - "The website link takes its accent/underline style from the section's existing [&_a]: variants, like the sibling Contact and More information links, rather than repeating the classes on the anchor"
  - "The form keeps a single required_without_all rule on source_url (the field that already carried the rule), now also satisfied by organiser_website"

patterns-established:
  - "Organiser website is shown on the event page only (not the card), with rel=\"nofollow ugc noopener\"; anything SafeLinks::http() refuses leaves no line at all"

requirements-completed: []
requirements-contributed: [SUB-01]

coverage:
  - id: D1
    description: "Admin turns 'Show contact publicly' off → event and draft store false → event page and / hide the contact; organiser name still shows"
    requirement: "SUB-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Public/ContactVisibilityTest.php#keeps a private contact off the event page and the calendar card"
        status: pass
    human_judgment: false
  - id: D2
    description: "Toggle left on → contact shows on both pages; Phase 1 events default to contact_public true"
    requirement: "SUB-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Public/ContactVisibilityTest.php#shows the contact on both pages when the toggle is left on; #defaults contact_public to true for events that never set it"
        status: pass
    human_judgment: false
  - id: D3
    description: "A draft's organiser_website and contact_public=false reach the approved event (fromDraft + ApproveDraft); a website-only draft publishes"
    requirement: "SUB-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Public/ContactVisibilityTest.php#carries a draft's website and private contact to the approved event; #publishes a draft whose only \"how to check this\" detail is a website"
        status: pass
    human_judgment: false
  - id: D4
    description: "Website shown as 'Website: {host}' with rel=\"nofollow ugc noopener\"; a javascript: URL never links"
    requirement: "SUB-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Public/ContactVisibilityTest.php#links the organiser website by host with nofollow ugc noopener; #never renders a non-http website as a link"
        status: pass
    human_judgment: false
  - id: D5
    description: "Website alone satisfies the form and the DTO; none of the three is refused with 'Add a source URL, an organiser contact or a website.'"
    requirement: "SUB-01"
    verification:
      - kind: unit
        ref: "tests/Unit/Publishing/PublishableEventDataTest.php (website-only, dataset 'no source URL, organiser contact or website', toAttributes, fromForm)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Public/ContactVisibilityTest.php#accepts a website alone ...; #asks for a source URL, contact or website when none is given"
        status: pass
    human_judgment: false

duration: 6min
completed: 2026-10-17
status: complete
---

# Phase 2 Plan 04: Contact Visibility and Organiser Website Summary

**`events.contact_public` (default true) and `events.organiser_website` now run from the admin form or an approved draft through `PublishableEventData` to the public site. The event page and calendar cards show the organiser's contact only when `contact_public` is true. A website shows as a "Website: {host}" link with `rel="nofollow ugc noopener"`. A website alone now counts as the "how to check this" detail.**

## Performance

- **Duration:** about 6 min
- **Started:** 2026-10-17T19:06:02Z
- **Completed:** 2026-10-17T19:11:23Z
- **Tasks:** 2
- **Files modified:** 10 (2 created, 8 modified)

## Accomplishments

- **Migration (D-19):**
  - `organiser_website` is a nullable string (2048).
  - `contact_public` is a boolean, default true, so Phase 1 rows keep their public output. `down()` reverses it exactly.
- **`Event`:**
  - Both columns are fillable content, with `@property` lines.
  - `contact_public` gets a boolean cast and a `true` default attribute.
  - New `draft()` relation: HasOne `EventDraft` where `kind = new`.
- **`PublishableEventData`:**
  - New `organiserWebsite` and `contactPublic` (default true), mapped in `fromForm()`, `fromEvent()`, `fromDraft()` and `toAttributes()`.
  - The invariant now accepts any one of source URL, organiser contact or website: "Add a source URL, an organiser contact or a website."
- **`EventForm`, "Who & source" section:**
  - New fields: "Organiser website" (`url()`, max 2048) and the "Show contact publicly" toggle (default on, with helper text).
  - `source_url` is now `requiredWithoutAll(['organiser_contact', 'organiser_website'])` with the same message.
- **Read side (T-02-11, T-02-12):**
  - `EventPageData` and `OccurrenceCardData` hide the contact unless `contact_public` is true. The card still shows the source URL first.
  - `EventPageData` gains `organiserWebsiteUrl` and `organiserWebsiteHost` from `SafeLinks::http()`. `events/show.blade.php` renders the website line with escaped output only.
- **Closes 02-03's note:** a website-only draft now publishes. Before this plan it passed the `no_contact` blocker but then failed the DTO invariant.

## Task Commits

1. **Task 1: Tracer: form toggle → event column → public pages:** `a659d37` (feat)
2. **Task 2: Website link, widened rule, draft → event mapping:** `f95f513` (test, RED) and `23a8fba` (feat, GREEN)

`commits: 4` is measured with `git rev-list` from the ledger. It includes `0c68021 docs(research): rewrite source inventory without tables`, which the owner committed during execution. It is not part of this plan, and `actuals.tokens` leaves it out.

## Files Created/Modified

- `database/migrations/2026_10_17_100300_add_contact_public_and_organiser_website_to_events.php`: the two columns
- `app/Models/Event.php`: fillable, casts, default, `draft()`
- `app/Publishing/Data/PublishableEventData.php`: the two fields in every mapping, plus the widened invariant
- `app/Filament/Resources/Events/Schemas/EventForm.php`: website field, visibility toggle, `requiredWithoutAll`
- `app/Support/EventPageData.php`: contact gate and website URL/host
- `app/Support/OccurrenceCardData.php`: contact gate on the card fallback
- `resources/views/events/show.blade.php`: "Website: {host}" line
- `tests/Feature/Public/ContactVisibilityTest.php` (10 tests, new). `tests/Unit/Publishing/PublishableEventDataTest.php`: +4 tests, and the 2 dataset rows now use the new message. `tests/Feature/Filament/CreateEventTest.php`: default assertions on the happy path, plus a test that both values are stored

## Decisions Made

- Admin entry defaults to a public contact (the plan's flagged assumption). Submissions stay private by default through `DraftData` (02-01).
- The website link gets its style from the section's existing `[&_a]:text-accent [&_a]:underline [&_a]:underline-offset-2`, the same as its sibling links (see Deviations).
- The form rule stays on `source_url`, the one field that already carried it, so validation errors still appear in the same place.

## Deviations from Plan

### Auto-fixed Issues

None needed.

### Minor adjustments

**1. Link style comes from the parent section's variants**
- **Found during:** Task 2
- **Issue:** The plan asked for the `text-accent underline underline-offset-2` link style. The "Who's running it" section already applies it to every `<a>` through `[&_a]:` variants, and the sibling Contact and More information links carry no class.
- **Fix:** The website anchor follows the siblings: same visible style, no repeated classes. The test checks the exact anchor markup.
- **Files modified:** resources/views/events/show.blade.php, tests/Feature/Public/ContactVisibilityTest.php
- **Commit:** 23a8fba

**2. Existing unit dataset rows reworded**
- Two rows in `PublishableEventDataTest` matched the old message, "source URL or an organiser contact". They now set `organiserWebsite` to null or blank too, and expect the new message. This is a deliberate contract change from the plan, not a regression.

**Total deviations:** none. There are 2 minor adjustments, and the scope did not change.

**SUB-01 left unticked in REQUIREMENTS.md.** The plan's frontmatter lists SUB-01, and `requirements.mark-complete SUB-01` ticked it. SUB-01, though, is the public submission form, which 02-05 builds, and this plan's edge-coverage note says SUB-01's rows are lifted by 02-05 and 02-09. This plan only delivers how the contact and website fields are shown, so I reverted the tick and the traceability row. SUB-01 should be marked complete when 02-05 lands.

## Notes for Later Plans

- **02-05 (submission form):** `DraftData` already defaults `contactPublic` to false. The submit form only needs to send `organiser_website` and `contact_public`, and approval will carry both to the event.
- **02-14 (review edit page):** `DraftData::fromManualForm()` still reads `organiser_website` from the raw form state rather than from `$publishable->organiserWebsite`. The result is the same, because both trim to null. 02-01 owns that file, so I left it alone.
- **Browser UAT:** the event page has a new "Website:" line. A flow in `docs/uat/browser-uat.md` should cover it at phase sign-off: submit with "Show contact publicly" off, approve, then check the event page and the card.

## Issues Encountered

- One RED test (the website-only draft) showed up under Pest's `errors` rather than `failures`, because it threw from `ApproveDraft`. I confirmed it with a filtered run before committing RED.

## Known Stubs

None.

## Threat Flags

None. The only new public output is the website link, which is covered by T-02-12/T-02-13.

## Verification

- Task 1 verify: migrate, then ContactVisibility, CreateEvent, EventPageContent and OccurrenceCard: 52 passed. EventPageContentTest and OccurrenceCardTest pass unchanged.
- Tracer gate: verify re-run end-to-end, pass. Filament, Publishing, Review and Public folders: 400 passed.
- Task 2 RED: 7 failing (6 failures, 1 error). GREEN: 34 passed.
- Full Unit+Feature suite: 642 passed. Pint passed. PHPStan (`--memory-limit=1G`): 0 errors.
- Acceptance greps:
  - `contact_public`: EventPageData 1, OccurrenceCardData 2
  - `contactPublic` in PublishableEventData: 4
  - New message in PublishableEventDataTest: 2
  - `nofollow ugc noopener` in ContactVisibilityTest: 2
  - `javascript:` in ContactVisibilityTest: 2

## Self-Check: PASSED

- Files found: the migration, ContactVisibilityTest.php and 02-04-SUMMARY.md
- Commits found in HEAD: a659d37, f95f513, 23a8fba
