# Phase 2: Draft Model, Review Queue & Submissions - Context

**Gathered:** 2026-10-16
**Status:** Ready for planning

<domain>
## Phase Boundary

Organisers submit events through a public form, and each valid submission becomes a `pending_review` draft that stays off the public site. All incoming events (submissions, and admin manual entry) go through the unified `event_drafts` model and a single Filament review queue. That queue is the only path to publication. The admin can see each draft's fields, recurrence summary, quality band with reasons, deterministic category and parse errors. They can approve, edit-and-approve, reject, bulk approve/reject, work through an "Approve & next" page with keyboard shortcuts, filter and search, and manage categories and rules on one Rules screen. By the end of the phase, `docs/research/event-sources.md` is verified and fixture samples are saved for every P0/P1 source.

Requirements: SUB-01..04, REV-01..04, REV-08..11, PIPE-03, PIPE-04, ADM-04, ADM-06, RES-01..03.

Not in this phase:
- Duplicate detection and "mark duplicate" (REV-05..07). The draft schema and queue UI should leave room for a duplicate flag and link, because REV-01/REV-09 mention them, but detection arrives in Phase 3.
- Update and cancellation diffs (Phase 3). Any crawling, sources registry UI or source runs (Phases 3-4).
- Geofence enforcement (Phase 4). ADM-04's Rules screen can include the geofence districts / place names fields now, because the requirement lists them.
- Full privacy notice, contact form, report-a-problem and analytics (Phase 5).

</domain>

<decisions>
## Implementation Decisions

### Submission form & organiser experience
- **D-01:** **Venue input is "pick or type".** A plain `<select>` lists known venues, plus a "Somewhere else" option that reveals venue name, address and postcode fields. It must work with JavaScript disabled: the fields can always be present, or shown via progressive enhancement. The draft stores the picked `venue_id` or the free-text venue fields.
- **D-02:** **Confirmation is on-screen only.** After a valid submission, show a friendly "Thanks, we'll review it" page in the Phase 1 D-05 voice, with a short reference (e.g. a draft reference code) and what happens next. **No outbound email in Phase 2.** No receipt and no approve/reject notification.
- **D-03:** **Organiser contact visibility is the organiser's choice.** The organiser name is shown publicly. A website is shown publicly if given. An email is shown publicly only if the organiser ticks "Show my contact publicly" (off by default). Otherwise it stays admin-only. Store the opt-in flag on the draft and carry it to the event. — **Reversibility:** costly — the interim privacy page and the consent wording promise this behaviour to organisers, so changing it later means rewording the notice and re-handling the contacts already collected.
- **D-04:** **Interim `/privacy` page now.** The consent checkbox links to a short interim privacy page covering: what is collected from submissions, why (legitimate interests), what is shown publicly (per D-03), retention (per D-12), and the `hello@whatsonattleborough.co.uk` contact. Phase 5 (LEG-01) expands the same URL. Don't create a different one.

### Draft → event conversion
- **D-05:** **Venue resolution is suggest-and-confirm.** On review, the system suggests an existing venue: exact name / `aliases` / postcode match first, then `pg_trgm` similarity. The suggestion is pre-selected. The admin confirms it, picks another, or clicks "Create new venue" (pre-filled from the draft's free-text fields). **No venue is ever created silently.** Approval is blocked until a `venue_id` is resolved, because `events.venue_id` is a required FK.
- **D-06:** **The admin translates the "repeats" field.** The draft view shows the organiser's free-text "repeats" value prominently, next to the same recurrence controls manual entry uses (weekly / nth-weekday-of-month, with an end date or count). The admin sets the rule during edit-and-approve. There is no parsing of the free text. If "repeats" is non-empty but no rule has been set, add a quality reason, and the draft is excluded from bulk approve.
- **D-07:** **Manual entry is a draft that is approved immediately.** The admin's "Save & publish" creates an `event_drafts` row (origin `manual`) and approves it at once through the same approval action (e.g. `ApproveDraft`) in one DB transaction. This satisfies success criterion 3 ("manual entry publishes through the same review path"). The audit trail (draft ↔ event link, approver, time) is the same as for a reviewed draft. The current direct call `CreateEvent` → `PublishEvent` must be routed through the approval action. `PublishEvent` stays the only creator of `Event` rows. — **Reversibility:** costly — every published event from Phase 2 onward links to a draft. Reverting means migrating that linkage and changing the single write path.
- **D-08:** **The submitter's category choice comes first.** The public form offers the categories plus "Not sure". For `submission` drafts, a real category picked by the organiser is the top-priority input in the PIPE-03 chain (it plays the "source default" role). "Not sure" or blank falls through to title keyword rules, then `Other` (flagged). The draft shows the assigned category and the reason it was assigned (e.g. "Chosen by organiser", "Keyword rule: 'quiz' → Community", "No match → Other"). The admin can always change it.

### Review queue workflow
- **D-09:** **The default order is soonest event first.** The queue and "Approve & next" walk pending drafts by earliest start date/time first, with ties broken by oldest created. Other orders are available as table sorts.
- **D-10:** **"Approve & next" uses guarded single-letter shortcuts:** `A` approve, `E` edit, `R` reject (opens the reason dialog), `S` or `J` skip to next, `K` previous, `?` help overlay. Shortcuts are disabled while focus is in a text input. `A` is blocked, with a visible message, when the draft is not publishable: no resolved venue, missing required fields, or "repeats" text with no rule set (if the admin hasn't acknowledged it). Shortcuts must be discoverable (help overlay plus visible hints) and must not break screen reader use.
- **D-11:** **Reject reasons are an optional preset plus an optional note.** Presets: Spam, Out of area, Not an event, Past/expired, Duplicate of existing, Incomplete/unverifiable, Other. Store the preset as an enum value, so Phase 5's dashboard can count rejections by reason, plus the free-text `review_notes`.
- **D-12:** **Rejected drafts are kept for a while, then purged.** Rejected drafts stay visible under a "Rejected" filter with an Undo/Reopen action that returns them to `pending_review`. A scheduled job hard-deletes rejected drafts after **90 days**, or **30 days** when the reason is Spam. The interim `/privacy` page (D-04) states this retention. — **Reversibility:** one-way — purged personal data can't be recovered. The policy is also published on the privacy page.

### Source research process (RES-01..03)
- **D-13:** **The agent does the first pass and the owner fills the gaps.** The research plan's agent builds a candidate list from spec §14 categories (Town Council, Breckland Council, schools, churches, halls such as Connaught Hall, sports clubs, Rotary/Lions, pubs and venues, library/heritage centre, nearby attractions, Norfolk directories, Eventbrite, TicketSource, local news, Facebook Pages/Groups for manual entry only). It probes each candidate live (robots.txt, platform fingerprint, TEC REST/ICS endpoints, RSS with event dates, JSON-LD presence, server-rendered or not). Then a `checkpoint:human` task asks the owner to add local URLs the agent couldn't find, especially Facebook-only groups and word-of-mouth sources. The agent then verifies those too.
- **D-14:** **Fixture storage:** `tests/Fixtures/sources/{source-slug}/{YYYY-MM-DD}-{type}.{ics|json|html|xml}`, holding the raw bytes as fetched, plus a small manifest per source (URL, fetch date, HTTP status and relevant headers, robots status at fetch time). Committed to the repo. **Personal data (phone numbers, personal emails, private names in descriptions) is redacted before commit, because the repo is public.** Redaction must keep the document structure valid for parsers.
- **D-15:** **Research timing:** a separate research plan in the **first wave**, running in parallel with the draft/queue code plans. The owner URL hand-off is a `checkpoint:human` inside it. Phase 2 can't be verified complete until RES-01..03 are done, because research gates Phase 3.
- **D-16:** **`event-sources.md` has two lists** (owner's own framing):
  1. **Crawlable:** every source where robots.txt allows the needed URLs, the ToS doesn't prohibit automated access, and a deterministic ladder method works (ICS / RSS with dates / TEC / JSON-LD / server-rendered selectors). Prioritised P0-P2 per spec §14, with all the per-source fields from spec §14 / RES-01.
  2. **Not crawlable, for owner review:** robots-disallowed, ToS-prohibited, **ToS-unclear** (robots allows but the ToS is silent or vague), JS-rendered, login-walled and Facebook sources. Each one gets the specific reason and a suggested route (manual entry, ask permission, or drop). The owner decides on these after the phase. Nothing on this list is configured as a source until the owner clears it.
  Only sources on the Crawlable list get P0/P1 fixtures (RES-03).

### Owner decisions at planning (2026-10-17, from 02-RESEARCH.md open questions)
- **D-17:** **Turnstile's script is allowed on `/submit` only.** This is the single exception to the "no third-party scripts" public-site rule. Add a one-line exception to the AGENTS.md project rules and extend `NoThirdPartyAssetsTest` so every other public page stays free of third-party scripts. Server-side verification uses an in-house verifier on Laravel's `Http` client (checks `success`, `action`, `hostname`; fails closed) so `Http::fake()` keeps tests offline. The privacy page mentions Turnstile.
- **D-18:** **No-JS visitors get a `<noscript>` "email us instead" notice on `/submit`** (`hello@`), because Turnstile can't run without JavaScript.
- **D-19:** **Add `events.contact_public` (default true) and `events.organiser_website`.** Event pages and calendar cards show organiser contact only when `contact_public` is true. Phase 1 events keep their current output. — **Reversibility:** costly (published privacy promise, D-03).
- **D-20:** **REV-09's "source" filter means origin** (submission / manual entry) until Phase 3 adds a `sources` table.
- **D-21:** **Bulk approve treats a venue as resolved only when the draft has a confirmed `venue_id`.** A suggested match alone makes bulk approve skip the draft (with that reason).
- **D-22 [informational]:** **No UI-SPEC for Phase 2.** Plan against Phase 1's brand decisions (Phase 1 D-05..D-08), the public-site rules in AGENTS.md, and the Phase 1 UI review findings (`01-UI-REVIEW.md`).
- **D-23:** **Owner accepts ToS-unclear sources as crawlable (2026-10-17).** Attleborough Heritage Group, Visit Norfolk, Visit Wymondham, Attleborough Primary School, Attleborough Summer Carnival, Attleborough Players and Banham Zoo move to the Crawlable list when robots.txt allows the needed URLs. Legal basis recorded as: robots allows, terms silent on automated access, owner's decision, polite crawling (honour Crawl-delay, low volume, identifying User-Agent), attribution and a link back to the source on every event. Attleborough Primary's `Crawl-delay: 12000` is honoured. — **Reversibility:** reversible (drop a source).
- **D-24:** **Attleborough Academy is crawlable under the owner's written permission (2026-10-17)**, despite its terms forbidding database compilation. Record "written permission held by owner" as the legal basis; the permission itself stays with the owner (not committed — the repo is public).
- **D-25 (revised 2026-10-17 evening):** **Breckland Council, Norfolk County Council, Norfolk Museums and TicketSource challenge our honest crawler User-Agent from every network** (`cf-mitigated: challenge` on robots.txt from both the corporate gateway and the owner's home network; their robots.txt allows event pages, seen via a third-party fetch service). Never bypass: no browser-UA spoofing, headless challenge solving, CAPTCHA services or proxies. Legitimate routes, in order: (1) official feeds/APIs where they exist (e.g. TicketSource organiser API, council open-data/ICS); (2) apply for Cloudflare Verified Bots once the production crawler is public (Phase 5: published bot info page, robots compliance, fixed Hetzner IP + reverse DNS); (3) ask each site to allowlist our User-Agent/IP; (4) for parser development now, the owner saves event pages from a browser as hand-captured fixtures (manifest marks `capture: hand`). They stay on the Not-crawlable list (reason: bot wall — challenges automated clients) until one route succeeds.
- **D-26 (revised 2026-10-17 evening):** **Attleborough Town Council is crawlable — P0.** Its block was IP-based (403 from the corporate gateway and from datacenter fetchers); from a residential network it serves normally. robots.txt allows everything except /wp-admin/. It runs The Events Calendar: REST `/wp-json/tribe/events/v1/events` (JSON) and ICS `/?post_type=tribe_events&ical=1` both return 200 — the first local TEC site (Phase 3 TEC adapter target). Production crawling from the Hetzner VPS (a datacenter IP) may be blocked like the corporate gateway: if so, ask the clerk to allowlist our User-Agent/IP (draft email kept in docs/research/) — never bypass.

### Carried forward (do not re-ask)
- Brand "What's On Attleborough", domain `whatsonattleborough.co.uk`, `hello@` contact, and the friendly-noticeboard voice with British English (Phase 1 D-01, D-02, D-04, D-05).
- Cloudflare is already in the stack, so use **Cloudflare Turnstile** for SUB-04, alongside a honeypot and Laravel per-IP rate limiting.
- **Single write choke point:** `App\Publishing\Actions\PublishEvent` is the only creator of `Event` rows. Approval wraps it.
- Quality: show the **band and reasons first**, with the 0-100 number second. Use **one Rules resource** for categories, mappings, keyword rules and geofence (research SUMMARY D16).
- **Bulk approve skips** drafts with parse errors, duplicate warnings, Low quality or unresolved venues (research D19 + REV-08). Show the skipped count and reasons.
- Stack: Laravel 13, Filament 5, Livewire 4, Tailwind 4, Postgres 18 with `pg_trgm`, Pest on PostgreSQL with `Http::preventStrayRequests()`. Public pages are Blade + Alpine and work without JS. Build to WCAG 2.2 AA.
- Recurrence: sabre/vobject only, RRULE stored, 90-day horizon, Europe/London → UTC. A series is approved once (REV-11) via the existing occurrence generator.

### Agent's Discretion
- Quality score weights and reason wording (spec §8.2 factors: extraction rung, required fields, end time, venue recognised, category mapped, no parse warnings). Submissions should get a sensible score. Missing end time, an unrecognised venue and an unset "repeats" rule should lower it.
- Seeded keyword rules and category mappings (sensible defaults for the 10 spec categories).
- `event_drafts` schema details, beyond spec §9 plus the fields implied above: `repeats_text`, `contact_public` flag, `venue_id` nullable alongside free-text venue fields, reject reason enum, reference code.
- Public form URL (e.g. `/submit`) and layout, rate-limit thresholds, the Turnstile failure UX, and the honeypot field technique.
- Filament page structure for the queue, the "Approve & next" page and the Rules screen. Search implementation for ADM-06 (Postgres full-text or `pg_trgm`).
- Format of the reference code shown to organisers.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Product scope & requirements
- `docs/product-specification.md` — v1.1 baseline. §7.2 categories and assignment chain, §7.4 review queue, §7.5 submission form, §7.6 administration, §8.1 publication rules, §8.2 review and quality score, §8.8 content/privacy/legal, §9 data model (`event_drafts`, `category_mappings`, `category_keyword_rules`, `venues`), §14 source research phase (fields to record, priorities, live verifications)
- `.planning/REQUIREMENTS.md` — exact wording of SUB-01..04, REV-01..04, REV-08..11, PIPE-03, PIPE-04, ADM-04, ADM-06, RES-01..03
- `.planning/ROADMAP.md` — Phase 2 goal and success criteria. Planning notes on the source research track gating Phase 3, and EXT-05 depending on research finding an RSS feed with dates
- `.planning/PROJECT.md` — constraints (single admin, determinism, legal, budget) and key decisions

### Prior phase decisions
- `.planning/phases/01-foundation-published-calendar/01-CONTEXT.md` — brand, voice, domain, contact, stack, single choke point, recurrence rules

### Research
- `.planning/research/SUMMARY.md` — spec deltas, especially D16 (one Rules screen, band before number), D17 (manual recurrence plus the "repeats" free-text field), D19 (bulk approve exclusions), D20 (content validation, relevant to research probing)
- `.planning/research/ARCHITECTURE.md` — two write models plus a read model, draft and review state enums, folder layout
- `.planning/research/FEATURES.md` — moderated-submission and review UX patterns from comparable products
- `.planning/research/PITFALLS.md` — spam, personal data and legal pitfalls
- `.planning/research/STACK.md` — pinned versions and testing approach

</canonical_refs>

<code_context>
## Existing Code Insights

Phase 1 was mid-execution during this discussion: STATE.md showed 1/10 plans, and git history showed commits up to plan 01-06. Planners must re-scan `app/` before planning, because Phase 1 plans will have added and changed files listed below.

### Reusable Assets
- `app/Publishing/Actions/PublishEvent.php`: the single creator of `Event` rows. Persists the event as published, with approver and time, and syncs occurrences in one transaction. Phase 2's approval action must wrap or call it, never bypass it.
- `app/Publishing/Data/PublishableEventData.php`: typed DTO with `fromForm()` / `toAttributes()`, recurrence fields (`RepeatPattern`, `RepeatEnds`, until/count) and `recurrence()`. Natural target for mapping a draft to publishable data (e.g. a `fromDraft()`).
- `app/Filament/Resources/Events/Schemas/EventForm.php`: sections What / When / Repeats / Where / Price / Who & source, with recurrence validation. Reuse the Repeats and Where sections in the draft edit-and-approve form so manual entry and review share one UI (D-06, D-07).
- `app/Calendar/OccurrenceGenerator.php`: materialises occurrences. REV-11 (approve a series once) is satisfied by going through `PublishEvent`.
- `app/Enums/EventOrigin.php`: `manual` / `submission` / `discovered` already defined. Reuse it for `event_drafts.origin`.
- `venues` table: `name`, `address`, `postcode`, `town`, `lat/lng`, `aliases` (jsonb). Supports D-05 alias matching. `pg_trgm` is already enabled (migration `2026_10_16_000100_enable_pg_trgm.php`).
- `categories` table: `name`, `slug`, `icon`, `sort`.

### Established Patterns
- Backed string enums implementing Filament `HasLabel`, with DB `CHECK` constraints mirroring enum values (see the `events` migration). Follow this for draft `kind`, `review_status` and reject reason.
- Thin Filament pages call domain actions (`CreateEvent` → `PublishEvent`). Domain code is grouped by area (`app/Calendar`, `app/Publishing`).
- Events store the local wall-clock schedule (`start_date`/`start_time`/... + `timezone`). UTC instants live on `event_occurrences`. Draft date fields should use the same local wall-clock representation, so `PublishableEventData` maps cleanly.

### Integration Points
- `app/Filament/Resources/Events/Pages/CreateEvent.php`: currently calls `PublishEvent` directly with `EventOrigin::Manual`. Reroute it through draft creation plus immediate approval (D-07).
- `app/Providers/Filament/AdminPanelProvider.php`: register the review queue, the "Approve & next" page and the Rules resource.
- `routes/web.php` + `resources/views/layouts/app.blade.php`: add the public submission form, its confirmation page and the interim `/privacy` page in the Phase 1 layout and voice.
- `routes/console.php`: schedule the rejected-draft purge job (D-12).

</code_context>

<specifics>
## Specific Ideas

- The confirmation page tells the organiser what happens next in the friendly-noticeboard voice, e.g. "Thanks! We'll check the details and add it to the calendar, usually within a few days."
- Category reasons and quality reasons should read like plain-English explanations to a busy reviewer, not codes.
- The owner's framing of source research: "list everything that you can crawl, and list separately what you cannot crawl, for review after."

</specifics>

<deferred>
## Deferred Ideas

- Notifying organisers by email when their event is approved or rejected: a new capability (needs mail delivery and PECR care). Backlog, post-validation.
- Deterministic parsing of common "repeats" phrases into a suggested RRULE: considered and declined for Phase 2 (admin translates). Revisit if submission volume makes manual translation slow.

</deferred>

---

*Phase: 02-draft-model-review-queue-submissions*
*Context gathered: 2026-10-16*
