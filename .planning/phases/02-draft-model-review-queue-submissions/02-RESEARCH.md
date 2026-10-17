# Phase 2: Draft Model, Review Queue & Submissions - Research

**Researched:** 2026-10-17
**Domain:** Laravel 13 + Filament 5 moderated-submission workflow (draft write model, review actions, public Blade form with spam defence) plus a live source-inventory research track
**Confidence:** HIGH for codebase integration and Filament/Laravel APIs (read from installed vendor source); MEDIUM for Turnstile/TEC facts (official docs and upstream source fetched this session); LOW for source-inventory outcomes (live work happens at execution time)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

#### Submission form & organiser experience
- **D-01:** **Venue input is "pick or type".** A plain `<select>` lists known venues, plus a "Somewhere else" option that reveals venue name, address and postcode fields. It must work with JavaScript disabled: the fields can always be present, or shown via progressive enhancement. The draft stores the picked `venue_id` or the free-text venue fields.
- **D-02:** **Confirmation is on-screen only.** After a valid submission, show a friendly "Thanks, we'll review it" page in the Phase 1 D-05 voice, with a short reference (e.g. a draft reference code) and what happens next. **No outbound email in Phase 2.** No receipt and no approve/reject notification.
- **D-03:** **Organiser contact visibility is the organiser's choice.** The organiser name is shown publicly. A website is shown publicly if given. An email is shown publicly only if the organiser ticks "Show my contact publicly" (off by default). Otherwise it stays admin-only. Store the opt-in flag on the draft and carry it to the event. — **Reversibility:** costly — the interim privacy page and the consent wording promise this behaviour to organisers, so changing it later means rewording the notice and re-handling the contacts already collected.
- **D-04:** **Interim `/privacy` page now.** The consent checkbox links to a short interim privacy page covering: what is collected from submissions, why (legitimate interests), what is shown publicly (per D-03), retention (per D-12), and the `hello@whatsonattleborough.co.uk` contact. Phase 5 (LEG-01) expands the same URL. Don't create a different one.

#### Draft → event conversion
- **D-05:** **Venue resolution is suggest-and-confirm.** On review, the system suggests an existing venue: exact name / `aliases` / postcode match first, then `pg_trgm` similarity. The suggestion is pre-selected. The admin confirms it, picks another, or clicks "Create new venue" (pre-filled from the draft's free-text fields). **No venue is ever created silently.** Approval is blocked until a `venue_id` is resolved, because `events.venue_id` is a required FK.
- **D-06:** **The admin translates the "repeats" field.** The draft view shows the organiser's free-text "repeats" value prominently, next to the same recurrence controls manual entry uses (weekly / nth-weekday-of-month, with an end date or count). The admin sets the rule during edit-and-approve. There is no parsing of the free text. If "repeats" is non-empty but no rule has been set, add a quality reason, and the draft is excluded from bulk approve.
- **D-07:** **Manual entry is a draft that is approved immediately.** The admin's "Save & publish" creates an `event_drafts` row (origin `manual`) and approves it at once through the same approval action (e.g. `ApproveDraft`) in one DB transaction. This satisfies success criterion 3 ("manual entry publishes through the same review path"). The audit trail (draft ↔ event link, approver, time) is the same as for a reviewed draft. The current direct call `CreateEvent` → `PublishEvent` must be routed through the approval action. `PublishEvent` stays the only creator of `Event` rows. — **Reversibility:** costly — every published event from Phase 2 onward links to a draft. Reverting means migrating that linkage and changing the single write path.
- **D-08:** **The submitter's category choice comes first.** The public form offers the categories plus "Not sure". For `submission` drafts, a real category picked by the organiser is the top-priority input in the PIPE-03 chain (it plays the "source default" role). "Not sure" or blank falls through to title keyword rules, then `Other` (flagged). The draft shows the assigned category and the reason it was assigned (e.g. "Chosen by organiser", "Keyword rule: 'quiz' → Community", "No match → Other"). The admin can always change it.

#### Review queue workflow
- **D-09:** **The default order is soonest event first.** The queue and "Approve & next" walk pending drafts by earliest start date/time first, with ties broken by oldest created. Other orders are available as table sorts.
- **D-10:** **"Approve & next" uses guarded single-letter shortcuts:** `A` approve, `E` edit, `R` reject (opens the reason dialog), `S` or `J` skip to next, `K` previous, `?` help overlay. Shortcuts are disabled while focus is in a text input. `A` is blocked, with a visible message, when the draft is not publishable: no resolved venue, missing required fields, or "repeats" text with no rule set (if the admin hasn't acknowledged it). Shortcuts must be discoverable (help overlay plus visible hints) and must not break screen reader use.
- **D-11:** **Reject reasons are an optional preset plus an optional note.** Presets: Spam, Out of area, Not an event, Past/expired, Duplicate of existing, Incomplete/unverifiable, Other. Store the preset as an enum value, so Phase 5's dashboard can count rejections by reason, plus the free-text `review_notes`.
- **D-12:** **Rejected drafts are kept for a while, then purged.** Rejected drafts stay visible under a "Rejected" filter with an Undo/Reopen action that returns them to `pending_review`. A scheduled job hard-deletes rejected drafts after **90 days**, or **30 days** when the reason is Spam. The interim `/privacy` page (D-04) states this retention. — **Reversibility:** one-way — purged personal data can't be recovered. The policy is also published on the privacy page.

#### Source research process (RES-01..03)
- **D-13:** **The agent does the first pass and the owner fills the gaps.** The research plan's agent builds a candidate list from spec §14 categories (Town Council, Breckland Council, schools, churches, halls such as Connaught Hall, sports clubs, Rotary/Lions, pubs and venues, library/heritage centre, nearby attractions, Norfolk directories, Eventbrite, TicketSource, local news, Facebook Pages/Groups for manual entry only). It probes each candidate live (robots.txt, platform fingerprint, TEC REST/ICS endpoints, RSS with event dates, JSON-LD presence, server-rendered or not). Then a `checkpoint:human` task asks the owner to add local URLs the agent couldn't find, especially Facebook-only groups and word-of-mouth sources. The agent then verifies those too.
- **D-14:** **Fixture storage:** `tests/Fixtures/sources/{source-slug}/{YYYY-MM-DD}-{type}.{ics|json|html|xml}`, holding the raw bytes as fetched, plus a small manifest per source (URL, fetch date, HTTP status and relevant headers, robots status at fetch time). Committed to the repo. **Personal data (phone numbers, personal emails, private names in descriptions) is redacted before commit, because the repo is public.** Redaction must keep the document structure valid for parsers.
- **D-15:** **Research timing:** a separate research plan in the **first wave**, running in parallel with the draft/queue code plans. The owner URL hand-off is a `checkpoint:human` inside it. Phase 2 can't be verified complete until RES-01..03 are done, because research gates Phase 3.
- **D-16:** **`event-sources.md` has two lists** (owner's own framing):
  1. **Crawlable:** every source where robots.txt allows the needed URLs, the ToS doesn't prohibit automated access, and a deterministic ladder method works (ICS / RSS with dates / TEC / JSON-LD / server-rendered selectors). Prioritised P0-P2 per spec §14, with all the per-source fields from spec §14 / RES-01.
  2. **Not crawlable, for owner review:** robots-disallowed, ToS-prohibited, **ToS-unclear** (robots allows but the ToS is silent or vague), JS-rendered, login-walled and Facebook sources. Each one gets the specific reason and a suggested route (manual entry, ask permission, or drop). The owner decides on these after the phase. Nothing on this list is configured as a source until the owner clears it.
  Only sources on the Crawlable list get P0/P1 fixtures (RES-03).

#### Carried forward (do not re-ask)
- Brand "What's On Attleborough", domain `whatsonattleborough.co.uk`, `hello@` contact, and the friendly-noticeboard voice with British English (Phase 1 D-01, D-02, D-04, D-05).
- Cloudflare is already in the stack, so use **Cloudflare Turnstile** for SUB-04, alongside a honeypot and Laravel per-IP rate limiting.
- **Single write choke point:** `App\Publishing\Actions\PublishEvent` is the only creator of `Event` rows. Approval wraps it.
- Quality: show the **band and reasons first**, with the 0-100 number second. Use **one Rules resource** for categories, mappings, keyword rules and geofence (research SUMMARY D16).
- **Bulk approve skips** drafts with parse errors, duplicate warnings, Low quality or unresolved venues (research D19 + REV-08). Show the skipped count and reasons.
- Stack: Laravel 13, Filament 5, Livewire 4, Tailwind 4, Postgres 18 with `pg_trgm`, Pest on PostgreSQL with `Http::preventStrayRequests()`. Public pages are Blade + Alpine and work without JS. Build to WCAG 2.2 AA.
- Recurrence: sabre/vobject only, RRULE stored, 90-day horizon, Europe/London → UTC. A series is approved once (REV-11) via the existing occurrence generator.

### the agent's Discretion
- Quality score weights and reason wording (spec §8.2 factors: extraction rung, required fields, end time, venue recognised, category mapped, no parse warnings). Submissions should get a sensible score. Missing end time, an unrecognised venue and an unset "repeats" rule should lower it.
- Seeded keyword rules and category mappings (sensible defaults for the 10 spec categories).
- `event_drafts` schema details, beyond spec §9 plus the fields implied above: `repeats_text`, `contact_public` flag, `venue_id` nullable alongside free-text venue fields, reject reason enum, reference code.
- Public form URL (e.g. `/submit`) and layout, rate-limit thresholds, the Turnstile failure UX, and the honeypot field technique.
- Filament page structure for the queue, the "Approve & next" page and the Rules screen. Search implementation for ADM-06 (Postgres full-text or `pg_trgm`).
- Format of the reference code shown to organisers.

### Deferred Ideas (OUT OF SCOPE)
- Notifying organisers by email when their event is approved or rejected: a new capability (needs mail delivery and PECR care). Backlog, post-validation.
- Deterministic parsing of common "repeats" phrases into a suggested RRULE: considered and declined for Phase 2 (admin translates). Revisit if submission volume makes manual translation slow.

Also out of this phase (CONTEXT `<domain>`): duplicate detection and "mark duplicate" (REV-05..07, Phase 3; leave schema/UI room only), update/cancellation diffs, crawling, sources registry UI, source runs (Phases 3-4), geofence enforcement (Phase 4; the Rules screen may hold the districts/place names now), full privacy notice, contact form, report-a-problem and analytics (Phase 5).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SUB-01 | Public form with all listed fields, "repeats" free text and consent linking to the privacy notice | Pattern 6 (form + `StoreSubmissionRequest`), field list and validation rules; D-01 no-JS venue picker; D-03 contact fields; `/privacy` route |
| SUB-02 | Valid submission creates a `pending_review` draft (origin `submission`), not public; "will be reviewed" confirmation | `DraftWriter::create()` (Pattern 2); POST-redirect-GET to `/submit/thanks` with flashed reference code; drafts never touch `events` |
| SUB-03 | Missing date/venue/required field → validation errors, no draft | FormRequest validation runs before any write; Turnstile verified last; London-dated `after_or_equal` (Pitfall 4) |
| SUB-04 | Honeypot, per-IP rate limiting, Turnstile | Pattern 7 (in-house honeypot + timing token), `RateLimiter::for('submissions')` on POST only, in-house `TurnstileVerifier` checking `success` + `hostname` + `action` (Pattern 8); offline tests via `Http::fake` |
| REV-01 | Draft shows fields, recurrence summary, source name/URL, extraction method, raw content link, quality band + reasons, parse errors, duplicate warning + link | `event_drafts` schema (Pattern 1) with `extraction_method`, `quality_*`, `parse_errors`, `duplicate_of_event_id`; Review page infolist; `ManualRecurrence::label()` for the summary |
| REV-02 | Approve publishes event + occurrences in one transaction, linked to the draft, approver/time recorded; only review actions + manual entry write published events | `ApproveDraft` (Pattern 3) wraps `PublishEvent` inside one `DB::transaction` with `lockForUpdate`; `event_drafts.event_id` link |
| REV-03 | Edit a draft's fields and approve | `EditEventDraft` page reusing extracted `EventForm` sections; "Save & approve" = `DraftWriter::update()` + `ApproveDraft` in one transaction |
| REV-04 | Reject with optional reason | `RejectDraft` with `RejectReason` enum (D-11) + `review_notes`; `ReopenDraft` (D-12) |
| REV-08 | Bulk approve/reject; bulk approve skips parse errors, duplicate warnings, Low quality | `DraftPublishability::blockers()` single source of truth; `BulkAction` + `reportBulkProcessingFailure()` grouped messages (verified in vendor docs); do NOT use table-level `checkIfRecordIsSelectableUsing` (Pitfall 6) |
| REV-09 | Filter by kind, source, quality band, duplicate flag, date | Table filters on `kind`, `origin` (Phase 2 "source"), `quality_band`, `duplicate_of_event_id` ternary, `start_date` range; Open Question 3 on "source" |
| REV-10 | "Approve & next" page with keyboard shortcuts | Custom resource record page `ReviewEventDraft` (`InteractsWithRecord`); `ReviewQueue::next()/previous()` keyset query; hand-written Alpine key handler (Pattern 5), NOT `Action::keyBindings()` (Pitfall 1) |
| REV-11 | Approve a recurring series once; occurrences generated automatically | Draft stores `recurrence_rule`; `PublishableEventData::fromDraft()` → `PublishEvent` → `OccurrenceGenerator::sync()` (existing) |
| PIPE-03 | Deterministic category: source default → mapping → keyword rules → `Other` (flagged) | `CategoryAssigner` (Pattern 4) with organiser choice in the source-default slot (D-08); ordered whole-word keyword match; `CategoryDecision{categoryId, reason, flagged}` |
| PIPE-04 | 0-100 quality score, band (High 80+, Medium 50-79, Low <50) and reasons | `QualityScorer` (Pattern 4) deduction model with weights in `config/review.php`; recomputed on every draft write |
| ADM-04 | Manage categories and one Rules screen (mappings, keyword rules, geofence districts/place names) | Filament custom page `Rules` with Tabs + table Repeaters + TagsInput, saved by one `SaveRules` action (Pattern 9) |
| ADM-06 | Search events and drafts | Filament global search (EventResource already has `$recordTitleAttribute = 'title'`); add `EventDraftResource` searchable attributes + table `->searchable()` |
| RES-01 | `docs/research/event-sources.md` with verified sources, P0-P3, platform, method, robots, legal basis, volume | Research method section (probe ladder, two lists per D-16, field template, `legal_basis` enum) |
| RES-02 | TEC endpoints, RSS feeds with event dates, JSON-LD verified live | TEC REST/iCal contract read from upstream source; probe commands; live network needed at execution |
| RES-03 | Fixtures saved for each P0/P1 source | D-14 layout + manifest schema + redaction procedure + automated "no personal data in fixtures" test |
</phase_requirements>

## Project Constraints (from AGENTS.md / copilot-instructions.md)

`./copilot-instructions.md` does not exist; `.github/copilot-instructions.md` holds only the GSD block. `.ai/rules/` does **not** exist (only `.ai/guidelines/attleborough.md`, which is rendered into AGENTS.md). The binding project rules are the "Attleborough Events: project rules" in `AGENTS.md`. [VERIFIED: AGENTS.md read this session]

| Area | Directive the planner must honour |
|------|-----------------------------------|
| Time | App and PG session timezone stay UTC. Instants are `timestampTz` in UTC (`->utc()` before assigning/upserting). Events/drafts keep the LOCAL wall-clock schedule (`start_date`, `start_time`, `end_date`, `end_time`, `all_day`, `timezone = Europe/London`). Date arithmetic on Europe/London `CarbonImmutable`. Never call `FilamentTimezone::set()`; add `->timezone('Europe/London')` to columns showing UTC instants. |
| Recurrence | `sabre/vobject` `EventIterator` is the only expansion engine (with `DateTimeZone('Europe/London')`). No hand-rolled loops. |
| Writes | Only `App\Publishing\Actions\*` create/update/change status on `events`/`event_occurrences`. `PublishEvent` is the only creator and wraps event write + occurrence sync in one `DB::transaction`. Filament pages call actions; no `Event::create()` / `$event->update()` in pages, controllers or jobs. `UpdatePublishedEvent` refuses one-off↔repeating switches (WR-06) and non-round-tripping rules (WR-10). Seeders seed reference data only. |
| Tests | Pest 5 on PostgreSQL (`attleborough_testing`), never SQLite. `Http::preventStrayRequests()` is global; no test may hit the network. Freeze time with `travelTo(CarbonImmutable::parse(..., 'Europe/London'))`. Include DST fixtures (Sun 25 Oct 2026, Sun 28 Mar 2027). Assert stored instants with raw SQL. CI runs with `TZ=America/New_York`. Browser UAT flows in `docs/uat/browser-uat.md` must be run before sign-off and **new UI adds flows**. |
| Filament 5 | `Filament\Schemas\Schema` with `->components([...])`; layout in `Filament\Schemas\Components`; `Get`/`Set` in `...\Components\Utilities`; actions are `Filament\Actions\*`; table action tests use `TestAction::make('name')->table($record)`; enums implement `HasLabel` (+ `HasColor`); resources in `app/Filament/Resources/{Plural}/` with `Schemas/` and `Tables/`. Panel access via `AdminGate::allows()`. |
| Public site | Blade + Tailwind 4. **No Livewire on public pages.** Filters work without JS. **No third-party scripts, fonts, images or CDNs.** WCAG 2.2 AA (24x24 targets, visible focus, `<time datetime>`). British English, UK formats. Escaped Blade only; descriptions plain text. |
| Dependencies | No LLM/AI runtime packages; no Redis (database queue/cache/session); Hetzner VPS deploy (Phase 5). Composer platform pinned to PHP 8.4.12. **"Do not change the application's dependencies without approval."** |
| Conventions | `php artisan make:*` with `--no-interaction`; curly braces always; constructor promotion; explicit return types; TitleCase enum keys; PHPDoc array shapes; run `vendor/bin/pint --dirty --format agent`; don't create new **base** folders without approval (app/ sub-domains like `app/Calendar`, `app/Publishing`, `app/Ops` are the established pattern). |
| Skills | `laravel-best-practices`: prefer framework features and existing abstractions over new dependencies; avoid speculative abstractions. `testing-best-practices`: feature test first, test decisions/failure modes, keep distinct-defect tests. Note: the skill text says the browser plugin isn't installed, but `composer.json` has `pestphp/pest-plugin-browser ^5.1` and `tests/Browser/*` exists — project reality wins. `tailwindcss-development`: v4 CSS-first, `gap` over margins, dark-mode variants (the public layout supports `prefers-color-scheme`). |

### Conflicts surfaced for the planner (need owner sign-off or an explicit plan decision)

1. **Turnstile vs "No third-party scripts" (AGENTS.md Public site).** SUB-04 (and CONTEXT "Carried forward") explicitly require Cloudflare Turnstile, which needs `https://challenges.cloudflare.com/turnstile/v0/api.js` plus an iframe from the same origin [CITED: developers.cloudflare.com/turnstile/reference/content-security-policy]. **Resolution to plan:** a scoped exception on `GET /submit` only — push the script via the layout's existing `@stack('head')` (layout line 30), never in the layout itself; extend `tests/Feature/Public/NoThirdPartyAssetsTest.php` so every other public page (`/`, `/events/{slug}`, `/privacy`, `/submit/thanks`) still loads nothing third-party, and `/submit` loads exactly one allowed third-party origin. Add a one-line exception to the AGENTS.md "Public site" rule (owner approval: it edits project rules). The existing test only checks `/` today [VERIFIED: tests/Feature/Public/NoThirdPartyAssetsTest.php:36-77].
2. **Turnstile vs "works without JavaScript".** CONTEXT says public pages work without JS and D-01 requires the venue picker to work with JS off. Turnstile cannot produce a token without JS, so a JS-disabled visitor can fill the form but cannot pass verification. **Resolution to plan:** keep the whole form usable without JS (D-01 satisfied), add a `<noscript>` notice above the submit button ("Our spam check needs JavaScript. If you can't turn it on, email the details to hello@whatsonattleborough.co.uk") and show the same text as the Turnstile validation error. Owner confirmation needed (Open Question 1).
3. **ARCHITECTURE.md puts review actions in `app/Review/Actions`**, but the AGENTS.md write rule names `App\Publishing\Actions\*` as the only writers of events. **Resolution:** put `ApproveDraft`, `RejectDraft`, `ReopenDraft` (and the manual-entry orchestration) in `App\Publishing\Actions`; draft-side services go in a new `app/Review/` sub-domain.
4. **D-03 needs an `events` schema change.** `events` has one `organiser_contact` column that both `EventPageData::make()` and `OccurrenceCardData::fromOccurrence()` render publicly [VERIFIED: app/Support/EventPageData.php:67, app/Support/OccurrenceCardData.php:40]. Carrying a private email to the event without leaking it requires a visibility flag on `events` and the two read-side DTOs honouring it (Pattern 1, "events additions").

## Summary

Phase 1 already built the hard part of the publish path: `PublishEvent` is the single creator of `Event` rows, it re-checks `AdminGate` before its transaction, and `PublishableEventData` re-validates every invariant in its constructor because "Phase 2's approval path does not go through Filament" [VERIFIED: app/Publishing/Data/PublishableEventData.php:47-54, app/Publishing/Actions/PublishEvent.php:16-64]. Phase 2 therefore adds a **second write model** (`event_drafts`) in front of that choke point, plus one new action, `ApproveDraft`, which locks the draft row, maps it to `PublishableEventData::fromDraft()`, calls `PublishEvent`, and stamps the draft as approved, all inside one transaction. Manual entry (D-07) becomes "write a `manual` draft, then `ApproveDraft`" in the same transaction, so every event from now on has a draft behind it.

The review UI is a standard Filament 5 resource (`EventDraftResource`) with tabs, filters, global search and bulk actions, plus one custom record page for "Approve & next". Two vendor-verified Filament facts drive the design: `Action::keyBindings()` always renders `x-mousetrap.global`, which fires inside text inputs and so breaks D-10 [VERIFIED: vendor/filament/support/src/View/Concerns/CanGenerateButtonHtml.php:198]; and `checkIfRecordIsSelectableUsing()` is table-level, so using it to stop bulk approve would also stop bulk reject [VERIFIED: vendor/filament/tables/src/Table/Concerns/HasBulkActions.php:66]. Use a hand-written Alpine key handler with an off switch (WCAG 2.1.4) and per-record `reportBulkProcessingFailure()` messages.

The public form is a plain Blade + Tailwind page with POST-redirect-GET, an in-house honeypot plus timing token, a `throttle:submissions` limiter on the POST route, and an in-house `TurnstileVerifier` that calls `siteverify` via Laravel's `Http` client (so `Http::fake()` keeps every test offline) and checks `success`, `hostname` and `action`. The only maintained Laravel Turnstile package checks `success` alone [VERIFIED: GitHub ryangjchandler/laravel-cloudflare-turnstile v3.0.4 src/Client.php], and adding it needs dependency approval anyway. The source-research track is execution work: a probe ladder (robots → fingerprint → TEC REST/iCal → feeds → JSON-LD → server-rendered check → ToS), two lists per D-16, and fixtures with a manifest and redaction. Live probing from this machine already showed **Breckland Council and TicketSource serve a Cloudflare managed challenge even on `robots.txt`** (`cf-mitigated: challenge`), so bot walls must be a first-class "Not crawlable" reason.

**Primary recommendation:** Build `event_drafts` + `App\Review\{DraftWriter, CategoryAssigner, QualityScorer, VenueMatcher, DraftPublishability, ReviewQueue}` + `App\Publishing\Actions\{ApproveDraft, RejectDraft, ReopenDraft}` first (wave 1, together with the research plan), then the Filament queue/review/rules UI and the public form in parallel, and reroute `CreateEvent` through `ApproveDraft` last. Add **no new Composer packages**.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Submission form rendering (no-JS, accessible) | Frontend Server (Blade SSR) | Browser (progressive enhancement for "Somewhere else"; Turnstile widget) | Public pages are Blade, no Livewire; JS is optional except for Turnstile |
| Spam defence (honeypot, timing, rate limit, Turnstile verify) | API / Backend (middleware + FormRequest) | External service (Cloudflare siteverify) | The browser can be forged; only server checks count |
| Draft creation, category assignment, quality scoring | API / Backend (`App\Review`) | Database (CHECK constraints, partial unique index) | Deterministic domain logic reused by Phase 3 ingestion |
| Venue suggestion (exact/alias/postcode, then trigram) | Database (`pg_trgm similarity`) | API / Backend (`VenueMatcher`) | Fuzzy matching belongs in Postgres; ranking/threshold in PHP |
| Approval / rejection / publish transaction | API / Backend (`App\Publishing\Actions`) | Database (row lock, transaction) | Single write choke point (REV-02) |
| Review queue, Approve & next, Rules screen | Admin UI (Filament/Livewire, server-driven) | Browser (Alpine keyboard handler) | Admin is Filament; shortcuts are client-side sugar over server actions |
| Retention purge (D-12) | API / Backend (scheduler + `MassPrunable`) | Database | Scheduled, idempotent, no UI |
| Search (ADM-06) | Admin UI (Filament global/table search) | Database (ILIKE; optional trigram index) | Volume is tiny; framework search is enough |
| Source research + fixtures | Developer tooling at execution time (curl, manual review) | Repo (`docs/research`, `tests/Fixtures/sources`) | Not app runtime; Phase 3 builds the real fetcher |

## Standard Stack

### Core (all already installed — no changes)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | 13.37.0 | Routing, FormRequest validation, `RateLimiter`, `Http` client, `Crypt`, `MassPrunable` + `model:prune`, scheduler | [VERIFIED: `composer show`] Framework features cover every SUB-04 control |
| filament/filament | 5.10.2 | Review queue resource, custom record page, Rules page, bulk actions, global search | [VERIFIED: `composer show`] Already the admin |
| livewire/livewire | 4.4.7 | Underpins Filament; **admin only** | [VERIFIED: `composer show`] |
| sabre/vobject | 5.0.0 | Occurrence expansion via existing `OccurrenceGenerator` | [VERIFIED: `composer show`] Only recurrence engine (AGENTS.md) |
| PostgreSQL + pg_trgm | 18.6 / 1.6 | Draft storage, CHECK constraints, partial indexes, `similarity()` for venue suggestion | [VERIFIED: `select version()`, `pg_extension` this session] Extension enabled by `2026_10_16_000100_enable_pg_trgm.php` |
| pestphp/pest (+ plugin-laravel, plugin-livewire, plugin-browser) | 5.3.2 | Feature, Livewire and browser UAT tests | [VERIFIED: `composer show`] |

### Supporting (in-house, small)
| Component | Purpose | When to Use |
|-----------|---------|-------------|
| `App\Submissions\TurnstileVerifier` (~50 lines, Laravel `Http`) | Server-side siteverify with `hostname` + `action` checks, fail closed | Every `POST /submit` after field validation passes |
| `App\Submissions\Honeypot` + `<x-submit.honeypot />` Blade component (~40 lines) | Hidden trap field + encrypted start timestamp (min seconds, max age) | Rendered in the form; checked in the FormRequest |
| `config/review.php` | Quality weights, band thresholds, retention days, rate limits, Turnstile expected action/hostnames | Single tuning point (CONTEXT discretion) |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| In-house `TurnstileVerifier` | `ryangjchandler/laravel-cloudflare-turnstile` ^3.0.4 (listed in `.planning/research/STACK.md`) | Package uses `Http` (fakeable) and ships a `FakeClient`, but checks only `success` (no `hostname`/`action`, no `remoteip`), and its `<x-turnstile.scripts />` is a bare `<script src=...>`. It needs dependency approval. Use only if the owner prefers a package; then wrap it to add the hostname/action checks. |
| In-house honeypot | `spatie/laravel-honeypot` ^4.7.3 | Mature, but by default it **skips the check when the honeypot fields are missing** (`honeypot_fields_required_for_all_forms => false`, so a bot that strips the fields passes) and answers spam with a blank 200 page [VERIFIED: GitHub spatie/laravel-honeypot 4.7.3 config/honeypot.php + src/SpamProtection.php]. If adopted: set that flag `true`, apply `ProtectAgainstSpam` only to `POST /submit`, and set `amount_of_seconds` ≥ 3. |
| Filament global search (ILIKE) | Postgres full-text (`tsvector`) | Overkill at hundreds of rows; full-text loses substring/partial matching admins expect |

**Installation:** none. If the owner overrides and chooses the packages: `composer require spatie/laravel-honeypot:^4.7.3 ryangjchandler/laravel-cloudflare-turnstile:^3.0.4` (behind a `checkpoint:human-verify`).

**Version verification:** installed versions read with `composer show` this session (above). Packagist checked for the two alternatives this session: spatie/laravel-honeypot 4.7.3 (2026-09-11, requires illuminate ^11|^12|^13, php ^8.2); ryangjchandler/laravel-cloudflare-turnstile v3.0.4 (2026-09-14, requires illuminate/contracts ^11|^12|^13, php ^8.2).

## Package Legitimacy Audit

No new packages are recommended. The `gsd-tools package-legitimacy check` seam supports only `npm|pypi|crates`, so the two Packagist alternatives were checked manually against the Packagist API and their GitHub source:

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| spatie/laravel-honeypot | Packagist | many years (4.x line; 4.7.3 released 2026-09-11) | ~7.98M total, ~411k/month | github.com/spatie/laravel-honeypot | OK (manual) | Not recommended (in-house instead); approved fallback |
| ryangjchandler/laravel-cloudflare-turnstile | Packagist | v3.x line (v3.0.4 released 2026-09-14) | ~1.70M total, ~163k/month | github.com/ryangjchandler/laravel-cloudflare-turnstile | OK (manual) | Not recommended (missing hostname/action checks); fallback only if wrapped |

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none
Both names came from the project's own `.planning/research/STACK.md`, not from this session's web search; source was read directly on GitHub. Any install still needs owner approval (AGENTS.md dependency rule).

## Architecture Patterns

### System Architecture Diagram

```
 Organiser (browser)                                   Admin (Filament panel, AdminGate)
        │ GET /submit                                          │
        ▼                                                      ▼
 [Blade form] ──(Turnstile widget, /submit only)──┐     [EventDraftResource list]──tabs/filters/search
        │ POST /submit                            │            │            │ bulk approve/reject
        ▼                                         │            │            ▼
 throttle:submissions (per IP)                    │            │     DraftPublishability::blockers()
        ▼                                         │            │       ├─ blocked → reportBulkProcessingFailure(reason)
 StoreSubmissionRequest                           │            │       └─ ok → ApproveDraft (own transaction per record)
   ├─ honeypot / timing token ── fail → generic   │            ▼
   ├─ field rules (London "today") ── fail → back with errors, NO draft
   └─ TurnstileVerifier ──POST──► Cloudflare siteverify (success+hostname+action)
        ▼ ok                                                [ReviewEventDraft "Approve & next"]
 DraftWriter::create(DraftData)                               │ A/E/R/S/J/K/? (Alpine, guarded)
   ├─ VenueMatcher (venue_id or suggestion)                   ├─ A → ApproveDraft ─► next pending (ReviewQueue)
   ├─ CategoryAssigner (organiser → mapping → keyword → Other)├─ E → EditEventDraft ("Save & approve")
   └─ QualityScorer (score, band, reasons)                    └─ R → RejectDraft(reason?, note?)
        ▼                                                      
 event_drafts (pending_review)  ◄──────────── DraftWriter::update() (admin edits; rescore)
        │                                      ▲
        │                         Manual entry: CreateEvent page
        │                         → DraftWriter::create(origin manual) + ApproveDraft (one transaction)
        ▼
 ApproveDraft: AdminGate → BEGIN → lock draft → assert pending/new → blockers empty
   → PublishableEventData::fromDraft → PublishEvent (event + OccurrenceGenerator::sync)
   → draft: approved, event_id, reviewed_by/at → COMMIT
        ▼
 events + event_occurrences (public read model, unchanged)

 Scheduler (daily): model:prune --model=App\Models\EventDraft  (rejected: 30d spam / 90d other)
```

### Recommended Project Structure

```
app/
├── Enums/                  # + DraftKind, ReviewStatus, RejectReason, QualityBand, ExtractionMethod
├── Models/                 # + EventDraft, CategoryMapping, CategoryKeywordRule, GeofenceTerm
├── Review/                 # NEW draft-side domain (reused by Phase 3 pipeline)
│   ├── Data/               # DraftData, CategoryDecision, QualityResult, VenueSuggestion
│   ├── DraftWriter.php     # the only writer of event_drafts field data
│   ├── CategoryAssigner.php
│   ├── QualityScorer.php
│   ├── VenueMatcher.php
│   ├── DraftPublishability.php   # blockers(): list<string>; one rule for A key, button, bulk
│   ├── ReviewQueue.php           # ordered pending query + next()/previous()
│   └── ReferenceCode.php
├── Publishing/Actions/     # + ApproveDraft, RejectDraft, ReopenDraft, SaveRules (rules are not events,
│                           #   so SaveRules may live in App\Review instead; planner's call)
├── Submissions/            # NEW: TurnstileVerifier, Honeypot
├── Http/Controllers/SubmissionController.php   # create / store / thanks
├── Http/Controllers/PrivacyController.php      # or Route::view('/privacy', ...)
├── Http/Requests/StoreSubmissionRequest.php
└── Filament/
    ├── Resources/EventDrafts/{EventDraftResource.php, Pages/{ListEventDrafts,EditEventDraft,ReviewEventDraft}.php,
    │                          Schemas/{EventDraftForm,EventDraftInfolist}.php, Tables/EventDraftsTable.php}
    ├── Resources/Events/Schemas/EventForm.php   # extract section builders, reuse in EventDraftForm
    └── Pages/Rules.php
resources/views/
├── submit/{create,thanks}.blade.php, components/submit/honeypot.blade.php
├── privacy.blade.php
├── errors/429.blade.php    # branded "too many submissions" page (404/500/503 exist)
└── filament/resources/event-drafts/pages/review-event-draft.blade.php
config/review.php
database/migrations/        # event_drafts, rules tables, events additions
database/seeders/RulesSeeder.php   # firstOrCreate defaults; called from DatabaseSeeder
tests/Feature/{Submissions,Review,Rules}/..., tests/Browser/{SubmitEventTest,ReviewQueueTest}.php
tests/Fixtures/sources/{slug}/...  # RES-03
docs/research/event-sources.md      # RES-01/02
```

### Pattern 1: `event_drafts` schema (enum + CHECK backstop, local wall-clock schedule)

**What:** Mirror the `events` conventions exactly: backed string enums implementing `HasLabel`, a DB `CHECK` per enum, local wall-clock schedule columns, `timestampTz` audit columns. Existing values the draft reuses, quoted verbatim:

- `EventOrigin`: `case Manual = 'manual'; case Submission = 'submission'; case Discovered = 'discovered';` [VERIFIED: app/Enums/EventOrigin.php:9-11]
- `PriceType`: `case Free = 'free'; case Paid = 'paid'; case Unknown = 'unknown';` [VERIFIED: app/Enums/PriceType.php:9-11]
- `RepeatPattern` (form state only, never stored): `case None = 'none'; case Weekly = 'weekly'; case MonthlyNth = 'monthly_nth'; case MonthlyLast = 'monthly_last';` [VERIFIED: app/Enums/RepeatPattern.php:13-16]
- `RepeatEnds`: `case Never = 'never'; case OnDate = 'until'; case AfterCount = 'count';` [VERIFIED: app/Enums/RepeatEnds.php:10-12]
- events CHECKs: `CHECK (price_type IN ('free', 'paid', 'unknown'))`, `CHECK (status IN ('published', 'hidden', 'cancelled'))`, `CHECK (origin IN ('manual', 'submission', 'discovered'))` [VERIFIED: database/migrations/2026_10_16_000130_create_events_table.php:47-49]
- venues NOT NULL columns: `name`, `address`, `postcode`, `town`; `aliases` jsonb default `'[]'` [VERIFIED: database/migrations/2026_10_16_000120_create_venues_table.php:14-20]

New enums (values are proposals → `[ASSUMED]` until the plan locks them; spec §9 and ARCHITECTURE.md name the first two):
- `DraftKind`: `new`, `update`, `cancellation` (only `new` produced in Phase 2)
- `ReviewStatus`: `pending_review`, `approved`, `rejected`, `duplicate`, `superseded`; transitions `pending_review → approved|rejected|duplicate|superseded`, `rejected → pending_review` (D-12 reopen), others terminal
- `RejectReason` (D-11 presets): `spam`, `out_of_area`, `not_an_event`, `past`, `duplicate`, `incomplete`, `other`
- `QualityBand`: `high`, `medium`, `low` with `fromScore(int)`: ≥80 high, 50-79 medium, <50 low (PIPE-04)
- `ExtractionMethod`: `submission`, `manual` now; Phase 3/4 add `ics`, `feed`, `tec`, `json_ld`, `selector` (CHECK altered then)

Columns (proposal):
```
id
reference           string(12) unique            -- e.g. WOA-7K3P9Q (D-02)
origin              string(16)  CHECK EventOrigin
kind                string(16)  default 'new'  CHECK DraftKind
review_status       string(16)  default 'pending_review' CHECK ReviewStatus
extraction_method   string(16)  CHECK ExtractionMethod
event_id            FK events nullable restrictOnDelete          -- REV-02 link
duplicate_of_event_id FK events nullable nullOnDelete            -- Phase 3 fills; filter/UI exist now
title               string(255)
description         text nullable          -- nullable: Phase 3 drafts may be incomplete
category_id         FK categories nullable restrictOnDelete    -- assigned (PIPE-03)
category_reason     string(255)            -- "Chosen by organiser" / "Keyword rule: 'quiz' → Community"
category_flagged    boolean default false  -- Other fallback
submitted_category_id FK categories nullable nullOnDelete      -- organiser's pick (null = Not sure)
venue_id            FK venues nullable restrictOnDelete        -- confirmed/picked only
venue_name, venue_address, venue_postcode, venue_town  string nullable (free text, D-01)
timezone            string(64) default 'Europe/London'
all_day             boolean default false
start_date date nullable, start_time time nullable, end_date date nullable, end_time time nullable
recurrence_rule     text nullable          -- RRULE as built by ManualRecurrence::toRrule()
repeats_text        string(255) nullable   -- organiser free text (D-06)
repeats_acknowledged boolean default false -- admin ticked "no rule needed" (D-10)
family_friendly boolean default false, price_type string(16) default 'unknown' CHECK, price_text string nullable
organiser_name string nullable, organiser_email string nullable, organiser_website string(2048) nullable
contact_public      boolean default false  -- D-03 opt-in
source_url          string(2048) nullable  -- organiser's "original link"
source_note         text nullable
consented_at        timestampTz nullable   -- consent checkbox time (accountability)
quality_score smallint, quality_band string(8) CHECK, quality_reasons jsonb default '[]'
parse_errors        jsonb default '[]'
reject_reason       string(16) nullable CHECK RejectReason
review_notes        text nullable
reviewed_by FK users nullable nullOnDelete, reviewed_at timestampTz nullable
timestampsTz
INDEX (review_status, start_date, start_time, created_at, id)                 -- D-09 queue order
UNIQUE INDEX event_drafts_one_new_per_event ON (event_id) WHERE kind = 'new'  -- one origin draft per event
```
Do **not** store the submitter's IP: the rate limiter keeps it in the cache store only, which keeps the privacy notice simple.

**events additions (D-03):** `organiser_website string(2048) nullable` and `contact_public boolean not null default true`. The default `true` keeps every Phase 1 / manual event rendering exactly as today. `EventPageData` and `OccurrenceCardData` show `organiser_contact` only when `contact_public`, and always show `organiser_website` as a link (`SafeLinks::http`, `rel="nofollow ugc noopener"`). `PublishableEventData` gains `organiserWebsite` + `contactPublic` (default `true`), and its "source URL or organiser contact" invariant [VERIFIED: PublishableEventData.php:111-113] widens to "source URL, organiser contact or organiser website". Add both to `Event`'s `#[Fillable]` list (they are content, not publishing state). Draft → event mapping: `organiser_email → organiser_contact`, `contact_public → contact_public`, `organiser_website → organiser_website`, `source_url → source_url`.

**Link direction:** link from the draft side only (`event_drafts.event_id`). Phase 1 events have no draft, so an `events.draft_id NOT NULL` column would break existing rows.

### Pattern 2: `DraftWriter` is the only writer of draft field data

```php
// app/Review/DraftWriter.php (sketch)
final class DraftWriter
{
    public function __construct(
        private VenueMatcher $venues,
        private CategoryAssigner $categories,
        private QualityScorer $quality,
        private ReferenceCode $references,
    ) {}

    public function create(DraftData $data): EventDraft
    {
        return DB::transaction(function () use ($data): EventDraft {
            $draft = new EventDraft($data->toAttributes());
            $draft->forceFill([
                'reference' => $this->references->unique(),
                'origin' => $data->origin,
                'kind' => DraftKind::New,
                'review_status' => ReviewStatus::PendingReview,
            ]);
            $this->derive($draft, $data->categoryInput());   // category + quality
            $draft->save();

            return $draft;
        });
    }

    public function update(EventDraft $draft, DraftData $data): EventDraft { /* fill, re-derive, save */ }
}
```
Category/quality/review state is not mass-assignable on `EventDraft` (same WR-07 pattern as `Event`); `forceFill` happens only in `DraftWriter` and the review actions.

### Pattern 3: `ApproveDraft` wraps `PublishEvent` in one transaction

```php
// app/Publishing/Actions/ApproveDraft.php (sketch)
final class ApproveDraft
{
    public function __construct(private PublishEvent $publish, private DraftPublishability $publishability) {}

    /** @throws AuthorizationException|DraftNotPublishable|InvalidReviewTransition */
    public function __invoke(EventDraft $draft, User $reviewer): Event
    {
        AdminGate::authorize($reviewer, 'Only the admin can approve drafts.');

        return DB::transaction(function () use ($draft, $reviewer): Event {
            $locked = EventDraft::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail(); // double-click safe
            $locked->review_status->assertCanTransitionTo(ReviewStatus::Approved);

            if (($blockers = $this->publishability->blockers($locked, forBulk: false)) !== []) {
                throw new DraftNotPublishable($blockers);
            }

            $event = ($this->publish)(PublishableEventData::fromDraft($locked), $reviewer, $locked->origin); // nested = savepoint

            $locked->forceFill([
                'review_status' => ReviewStatus::Approved,
                'event_id' => $event->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now()->utc(),
            ])->save();

            return $event;
        });
    }
}
```
- `PublishEvent` opens its own `DB::transaction`; inside an outer transaction Laravel uses a savepoint, so a failure anywhere rolls everything back. Its `AdminGate` check runs again (harmless, defence in depth).
- `PublishableEventData::fromDraft()` must convert the stored `recurrence_rule` back to the form enums with `ManualRecurrence::tryFromStoredRule($rule, $startLocal, $allDay)` [VERIFIED: app/Calendar/ManualRecurrence.php:151-170]. A `null` return (a rule the form cannot represent) is a blocker in Phase 2. Phase 3 ICS drafts with arbitrary RRULEs need a different publish input: note it for Phase 3, don't solve it now.
- Recurring drafts: `PublishableEventData` forbids multi-day repeating events and validates the pattern against the start date [VERIFIED: PublishableEventData.php:56-64]. Surface those `InvalidArgumentException` messages as blockers, not 500s.
- `RejectDraft(draft, reviewer, ?RejectReason, ?string note)` and `ReopenDraft(draft, reviewer)` follow the same AdminGate → lock → assert → forceFill shape. Reopen clears `reject_reason` and keeps `review_notes`.

**Edit & approve (REV-03):** `EditEventDraft` "Save & approve" runs `DB::transaction(fn () => $approve($writer->update($draft, DraftData::fromForm($data)), $admin))`.

**Manual entry (D-07):** `CreateEvent::handleRecordCreation()` becomes `DB::transaction(fn () => $approve($writer->create(DraftData::fromManualForm($data)), $admin))`. It returns the `Event`, so the existing redirect and tests keep working. Category reason for manual drafts: "Chosen by admin".

### Pattern 4: Deterministic `CategoryAssigner` and `QualityScorer` (pure services)

**CategoryAssigner** input: `?int $preferredCategoryId` (organiser pick for submissions, the source default in Phase 3), `list<string> $sourceValues` (Phase 3 mappings; empty now), `string $title`. Chain:
1. preferred category → reason "Chosen by organiser" (or "Source default" in Phase 3)
2. first `category_mappings` row whose normalised `source_value` equals a normalised source value → "Mapped from '{value}'"
3. keyword rules ordered by `(priority asc, id asc)`; first whole-word, case-insensitive match on the normalised title (`mb_strtolower`, collapse whitespace, `/(?<![\p{L}\p{N}])` . `preg_quote($kw, '/')` . `(?![\p{L}\p{N}])/u`) → "Keyword rule: '{kw}' → {Category}"
4. else the category with slug `other` (seeded by `CategorySeeder`: the `['Other', 'heroicon-o-sparkles']` row, slug from `Str::slug`) [VERIFIED: database/seeders/CategorySeeder.php:18-39] → `flagged = true`, reason "No match → Other"

Look up `Other` by **slug**, and stop the Rules screen from deleting it or changing its slug.

**QualityScorer** (deduction model, weights in `config/review.php`; values are discretion → `[ASSUMED]`): start at 100, subtract, clamp to 0..100, and return `reasons` as plain English in display order:

| Factor (spec §8.2) | Deduction | Reason text |
|---|---|---|
| Each missing required field (title, start date, start time unless all-day, venue (id or free text), description, a contact or source link) | −50 (forces Low) | "Missing start time" |
| Any parse error | −40 | "Couldn't read: {error}" |
| Venue not recognised (no `venue_id` and no exact match) | −20 | "Venue not recognised: '{name}'" |
| Category fell back to Other | −15 | "No category matched, set to Other" |
| No end time (timed event) | −10 | "No end time" |
| "Repeats" text with no rule and not acknowledged | −25 | "Organiser says it repeats ('{text}') but no repeat rule is set" |
| Extraction rung | submission/manual 0 (Phase 3: ICS 0, TEC −5, JSON-LD −10, selectors −15) | "Entered by organiser" |

A clean submission at a known venue with an end time and a category scores 100 (High). Unknown venue + no end time = 70 (Medium). Unknown venue + Other + no end + unset repeats = 30 (Low). Re-score on **every** `DraftWriter` write, so resolving the venue during review lifts the band.

**VenueMatcher**: exact match first (case-insensitive name; alias via `exists (select 1 from jsonb_array_elements_text(aliases) a where lower(a) = lower(?))`; postcode via `replace(upper(postcode),' ','') = ?`), then `select id, name, similarity(name, ?) as score from venues where name % ? order by score desc limit 5`. The default `pg_trgm.similarity_threshold` is 0.3 `[ASSUMED]`; set the threshold explicitly in config and filter by `score >= config` rather than relying on `%`'s session setting. Store `venue_id` only when the organiser picked from the list or the admin confirmed. The suggestion is computed when the page renders and pre-selected as the Select's default (D-05).

### Pattern 5: "Approve & next" custom record page with a guarded Alpine key handler

- `ReviewEventDraft extends Filament\Resources\Pages\Page` using `Filament\Resources\Pages\Concerns\InteractsWithRecord` [VERIFIED: vendor/filament/filament/src/Resources/Pages/Concerns/InteractsWithRecord.php], registered in `EventDraftResource::getPages()` as `'review' => ReviewEventDraft::route('/{record}/review')`. Filament 5 page views are **non-static**: `protected string $view = '...';` [VERIFIED: vendor/filament/filament/src/Pages/Page.php:84; app/Filament/Pages/SystemHealth.php].
- Header actions `approve`, `edit` (URL), `reject` (modal: `Select` reason with "No reason" + `Textarea` note), `skip`, `previous`, `help` (modal listing shortcuts). Each action calls a domain action, then redirects to `ReviewQueue::next($record)` or the list when the queue is empty. `approve` is `->disabled(fn () => $blockers !== [])` and shows the blockers in a visible Callout above the fields. `ApproveDraft` re-checks on the server anyway.
- **Do not use `->keyBindings()` for the letter keys.** Filament renders it as `x-mousetrap.global.{keys}` [VERIFIED: vendor/filament/support/src/View/Concerns/CanGenerateButtonHtml.php:198], and Mousetrap's `bindGlobal` fires inside inputs `[ASSUMED: Mousetrap semantics]`, which breaks D-10. Put one handler in the page view instead:

```blade
{{-- resources/views/filament/resources/event-drafts/pages/review-event-draft.blade.php (sketch) --}}
<x-filament-panels::page>
  <div
    x-data="{ enabled: localStorage.getItem('woa-shortcuts') !== 'off' }"
    x-on:keydown.window="
      if (! enabled || $event.ctrlKey || $event.metaKey || $event.altKey) return;
      if ($event.target.closest('input, textarea, select, [contenteditable], [role=combobox]')) return;
      if (($wire.mountedActions ?? []).length) return;   // a modal is open
      const map = { a: 'approve', r: 'reject', s: 'skip', j: 'skip', k: 'previous', '?': 'help' };
      if ($event.key === 'e') { $event.preventDefault(); window.location = @js($editUrl); return; }
      if (map[$event.key]) { $event.preventDefault(); $wire.mountAction(map[$event.key]); }
    "
  >
    <label class="inline-flex items-center gap-2 text-sm">
      <input type="checkbox" x-model="enabled" x-on:change="localStorage.setItem('woa-shortcuts', enabled ? 'on' : 'off')">
      Keyboard shortcuts (A approve · E edit · R reject · S/J next · K previous · ? help)
    </label>
    {{ $this->infolist }}   {{-- or the page's schema content --}}
  </div>
</x-filament-panels::page>
```
`$wire.mountedActions` and `mountAction(name)` are the real Filament 5 names [VERIFIED: vendor/filament/actions/src/Concerns/InteractsWithActions.php:40; vendor/filament/actions/src/Contracts/HasActions.php:22]. The on/off toggle satisfies **WCAG 2.1.4 Character Key Shortcuts** ("A mechanism is available to turn the shortcut off") [CITED: w3.org/WAI/WCAG22/Understanding/character-key-shortcuts.html]. Show visible hints on the buttons, e.g. "Approve (A)". Blocked `A`: `mountAction('approve')` on a disabled action does nothing, so the handler should also announce the blockers through an `aria-live="polite"` region.

**ReviewQueue ordering (D-09):** `orderByRaw('start_date asc nulls last, start_time asc nulls first, created_at asc, id asc')`. `next()` uses a row-value keyset `where (coalesce(start_date,'9999-12-31'), coalesce(start_time,'00:00'), created_at, id) > (?, ?, ?, ?)` over `pending_review` only. Use the same query object for the list's default sort, so the list and "Approve & next" never disagree.

### Pattern 6: Public submission form (Blade, no Livewire, POST-redirect-GET)

- Routes (`routes/web.php`): `GET /submit` → `submit.create`; `POST /submit` → `submit.store` with `->middleware('throttle:submissions')`; `GET /submit/thanks` → `submit.thanks` (reads the flashed `reference`, redirects to `/submit` when it is absent); `GET /privacy` → `privacy`. Add footer links to "Add your event" and "Privacy" in `resources/views/layouts/app.blade.php`.
- Fields and rules (`StoreSubmissionRequest`):
  - `title` required|string|max:120
  - `start_date` required|date_format:Y-m-d|after_or_equal:{London today}|before_or_equal:{London today + 12 months} (PIPE-01 consistency)
  - `all_day` boolean; `start_time` required_unless:all_day,1|date_format:H:i
  - `end_date` nullable|date_format:Y-m-d|after_or_equal:start_date; `end_time` nullable|date_format:H:i
  - `venue_choice` required (a venue id or `other`); when `other`: `venue_name` required|max:120, `venue_address` required|max:255, `venue_postcode` nullable|max:10|UK postcode regex
  - `description` required|max:5000
  - `category_choice` required (category id or `not_sure`)
  - `price_type` required|in:free,paid,unknown; `price_text` nullable|max:60
  - `organiser_name` required|max:120
  - `organiser_email` nullable|email:rfc|required_without:organiser_website; `organiser_website` nullable|url:http,https|max:2048 (`url:http,https` → `Str::isUrl($value, ['http','https'])` [VERIFIED: vendor/laravel/framework/src/Illuminate/Validation/Concerns/ValidatesAttributes.php:2770-2773])
  - `contact_public` boolean; `source_url` nullable|url:http,https|max:2048
  - `repeats_text` nullable|max:200; `family_friendly` boolean (optional extra)
  - `consent` accepted
  - then honeypot/timing, then Turnstile **last** (the `bail` order means a field error never spends a Cloudflare call)
- Native `<input type="date">`/`<input type="time">`, a `<fieldset>` for the venue with the "Somewhere else" fields **always rendered** (D-01). Alpine/CSS hides them only when JS is on and a known venue is picked. An error summary at the top links to each field; each input gets `aria-invalid` and `aria-describedby`; labels are visible; targets are ≥24px. Repopulate with `old()` (escaped).
- Confirmation: `redirect()->route('submit.thanks')->with('reference', $draft->reference)`. Copy per CONTEXT specifics ("Thanks! We'll check the details and add it to the calendar, usually within a few days.").

### Pattern 7: Honeypot + timing token (in-house)

- `<x-submit.honeypot />` renders `<div class="hp-field" aria-hidden="true">` (CSS `display:none` in `app.css`; no inline style needed) with `<label for="hp_fax">Leave this empty</label><input id="hp_fax" name="fax_number" tabindex="-1" autocomplete="off">` and `<input type="hidden" name="form_token" value="{{ Crypt::encryptString((string) now()->getTimestamp()) }}">`.
- Check in `StoreSubmissionRequest::prepareForValidation()`/`after()`: the field must be **present and empty**. If filled: create nothing, redirect to `submit.thanks` with no reference ("Thanks!"), so a bot gets no signal, and log at info with no PII. The token must decrypt (`DecryptException` → generic error), be at least `config('review.submission.min_seconds', 3)` old and at most `max_age_minutes` (120) old. Too fast or too old → validation error "That was quick! Please check the details and send again." Humans can trip the timing check (autofill), so it must stay visible to them.
- The field name must not collide with real fields (`organiser_website` exists, so don't call the trap `website`).

### Pattern 8: Turnstile verification that stays offline in tests

```php
// app/Submissions/TurnstileVerifier.php (sketch)
final class TurnstileVerifier
{
    public const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @return list<string> error codes; empty = passed */
    public function verify(?string $token, string $ip): array
    {
        $secret = config('services.turnstile.secret');
        if (! is_string($secret) || $secret === '') {
            return ['missing-input-secret'];                      // fail closed
        }
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return ['missing-input-response'];
        }
        try {
            $body = Http::asForm()->acceptJson()->timeout(5)->post(self::SITEVERIFY_URL, [
                'secret' => $secret, 'response' => $token, 'remoteip' => $ip,
                'idempotency_key' => (string) Str::uuid(),
            ])->throw()->json();
        } catch (Throwable) {
            return ['internal-error'];                            // fail closed
        }
        if (($body['success'] ?? false) !== true) {
            return array_values((array) ($body['error-codes'] ?? ['invalid-input-response']));
        }
        if (($body['action'] ?? null) !== config('services.turnstile.action', 'submit')) {
            return ['action-mismatch'];
        }
        $hosts = (array) config('services.turnstile.hostnames', []);
        if ($hosts !== [] && ! in_array($body['hostname'] ?? null, $hosts, true)) {
            return ['hostname-mismatch'];
        }
        return [];
    }
}
```
- Siteverify facts: `POST https://challenges.cloudflare.com/turnstile/v0/siteverify`, form or JSON, params `secret`, `response`, optional `remoteip`, `idempotency_key`. Response has `success`, `challenge_ts`, `hostname`, `error-codes`, `action`, `cdata`. Tokens are ≤2048 chars, valid 300 s, single use (`timeout-or-duplicate`) [CITED: developers.cloudflare.com/turnstile/get-started/server-side-validation]. Widget: `<div class="cf-turnstile" data-sitekey="…" data-action="submit">` plus `<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer>`, which adds a hidden `cf-turnstile-response` input to the form. `action` is ≤32 chars of `[A-Za-z0-9_-]` [CITED: developers.cloudflare.com/turnstile/get-started/client-side-rendering and …/widget-configurations].
- Test keys: sitekey `1x00000000000000000000AA` always passes and `2x00000000000000000000AB` always fails (visible); secret `1x0000000000000000000000000000000AA` passes, `2x0000000000000000000000000000000AA` fails, `3x0000000000000000000000000000000AA` returns "token already spent". Test keys work on `localhost` [CITED: developers.cloudflare.com/turnstile/troubleshooting/testing]. Use them in `.env.example` for local dev. The hostname the test secret returns is `[ASSUMED]`, so leave `hostnames` empty locally (check skipped) and set it to `whatsonattleborough.co.uk` in production.
- **Feature tests (offline):** `Http::fake([TurnstileVerifier::SITEVERIFY_URL => Http::response(['success' => true, 'action' => 'submit', 'hostname' => 'whatsonattleborough.co.uk', 'error-codes' => []])])`. `preventStrayRequests()` stays on, and faked URLs are allowed. Cover `success:false`, action mismatch, hostname mismatch, `Http::failedConnection()` [VERIFIED: vendor/laravel/framework/src/Illuminate/Http/Client/Factory.php:304] and a missing secret. Assert `Http::assertSent(...)` carries `secret`, `response`, `remoteip`.
- **Browser tests:** a real browser would fetch `challenges.cloudflare.com` (network!). Render the widget and script only when `services.turnstile.site_key` is filled; leave it empty in `phpunit.xml`; in browser tests bind a passing fake: `app()->instance(TurnstileVerifier::class, ...)` (the browser plugin serves the app in-process, per `tests/Pest.php:37-46`). Production guard: with keys missing in `production`, `GET /submit` shows "The form is resting; email hello@…" and `POST` refuses. Add a check to `ops:health`.
- `.env.example`: add `TURNSTILE_SITE_KEY=1x00000000000000000000AA`, `TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA`; `config/services.php` gets a `turnstile` block (`site_key`, `secret`, `action`, `hostnames`).

### Pattern 9: One Rules screen

A Filament custom page `App\Filament\Pages\Rules` (navigation group "Review") with one form schema: `Tabs` → **Categories** (table `Repeater` with name, slug read-only for `other`, icon, sort), **Keyword rules** (`Repeater->table([TableColumn::make('Keyword'), TableColumn::make('Category'), TableColumn::make('Priority')])`) [VERIFIED: vendor/filament/forms/docs (Repeater "Table repeaters"), vendor/filament/forms/src/Components/Repeater/TableColumn.php], **Category mappings** (source value → category; unused until Phase 3 but manageable now per ADM-04), **Geofence** (`TagsInput` postcode districts, default `NR17, NR16, NR18, IP24` from PIPE-02; `TagsInput` place names). One "Save rules" action calls one domain action that syncs every table in a single transaction. That action validates uniqueness (normalised keyword, normalised source value), refuses to delete a category that events or drafts use (FKs are `restrictOnDelete` [VERIFIED: create_events_table.php:22]) with a friendly message, and never touches `other`'s slug.
- Avoid embedding separate Livewire table components (`Filament\Schemas\Components\Livewire`): Filament's docs warn they "do not inherit the parent component's model scoping or authorization" [VERIFIED: vendor/filament/schemas/docs/09-custom-components.md:132-134], so each would need its own AdminGate checks.
- Tables: `category_keyword_rules(id, keyword string(80), category_id FK restrict, priority smallint default 100, timestampsTz, unique(lower(keyword)))`; `category_mappings(id, source_value string(120), category_id FK, timestampsTz, unique(lower(source_value)))` (Phase 3 adds a nullable `source_id` and widens the unique key); `geofence_terms(id, type string CHECK ('postcode_district','place_name'), value string, unique(type, lower(value)))`.
- Seeding: `RulesSeeder` with `firstOrCreate` keyed by keyword/value, **never `updateOrCreate`** (that would overwrite admin edits on re-seed), called from `DatabaseSeeder` next to `CategorySeeder`. Sensible starter keywords (discretion `[ASSUMED]`): quiz/bingo/coffee morning → Community; market/car boot/craft fair → Market; fundraiser/charity → Charity; concert/gig/choir/band/live music → Music; football/cricket/parkrun/bowls → Sport; beer festival/food festival/afternoon tea → Food & Drink; school/PTA/fete (school) → School; theatre/exhibition/art/film → Arts & Culture; family/kids/children/toddler → Family.

### Anti-Patterns to Avoid
- **Calling `PublishEvent` from the submission controller or a Filament bulk closure directly.** Everything goes through `ApproveDraft`, so the draft link and the audit trail always exist.
- **`Event::create()` / `EventDraft::update([...review_status...])` in Filament pages.** Pages call actions (AGENTS.md).
- **Storing recurrence as `RepeatPattern` on the draft.** Store the RRULE (spec §9) and derive the form state with `ManualRecurrence::tryFromStoredRule()`.
- **Silently creating venues** from free text (D-05).
- **Rendering the Turnstile script from the layout** (it would land on every public page).
- **Trusting the client:** the `A` key, the disabled button and bulk skips are UX. `ApproveDraft` must re-check blockers on the server.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Per-IP throttling | Counter table / custom middleware | `RateLimiter::for('submissions', fn (Request $r) => [Limit::perHour(5)->by('ip:'.$r->ip()), Limit::perDay(10)->by('ip:'.$r->ip())])` + `throttle:submissions` [VERIFIED: vendor/laravel/framework/src/Illuminate/Cache/RateLimiting/Limit.php:75-132] | Atomic, uses the database cache store, testable with `CACHE_STORE=array` |
| Retention purge | Hand-written delete command | `MassPrunable` on `EventDraft` with `prunable()` + `Schedule::command('model:prune', ['--model' => [EventDraft::class]])->daily()` [VERIFIED: vendor/laravel/framework/src/Illuminate/Database/Eloquent/MassPrunable.php, Database/Console/PruneCommand.php:25-30] | Framework-supported, `--pretend` for a dry run |
| Fuzzy venue match | Levenshtein in PHP | `pg_trgm similarity()` | Already enabled; index-friendly later |
| Recurrence expansion / "next dates" preview | Loops | Existing `RecurrenceExpander` / `OccurrenceGenerator` (sabre) | AGENTS.md: sabre is the only engine |
| Bulk "skipped N because…" notification | Custom notification builder | `BulkAction` + `$action->reportBulkProcessingFailure('low_quality', message: fn (int $failureCount) => …)` + `failureNotificationTitle()` [VERIFIED: vendor/filament/tables/docs/04-actions.md:249-293] | Grouped counts per reason come for free |
| Admin search | Custom search page | Filament global search + `TextColumn::searchable()` | `EventResource` already has `$recordTitleAttribute = 'title'` [VERIFIED: app/Filament/Resources/Events/EventResource.php:24] |
| CSRF | Custom tokens | `@csrf` (Laravel 13 `PreventRequestForgery`) [VERIFIED: vendor/laravel/framework/src/Illuminate/Foundation/Http/Middleware/PreventRequestForgery.php exists] | Standard |
| Enum state machine | `spatie/laravel-model-states` | Backed enum `transitions()/assertCanTransitionTo()` like `EventStatus` [VERIFIED: app/Enums/EventStatus.php:23-45] | Matches Phase 1 |

**Key insight:** every Phase 2 control has a framework-native or already-built primitive. The only genuinely new code is domain logic (assignment, scoring, publishability, queue order) and two ~50-line spam helpers whose behaviour we must control exactly (Turnstile hostname/action checks; required honeypot presence).

## Source Research Method (RES-01..03): how to do it at execution time

**Live network access is required at execution time.** Nothing below can be finished from fixtures. This session's probes ran from a developer machine behind a corporate "Umbrella Cloud Security Gateway" (it answered `raw.githubusercontent.com` with its own 302 page). When the local gateway blocks or rewrites a fetch, record **"no observation (local gateway)"**, never "blocked by site".

### Probes already observed this session (2026-10-17, from this machine)
```
$ curl -s -D - -o /dev/null -A "WhatsOnAttleboroughResearch/0.1 (+mailto:hello@whatsonattleborough.co.uk)" https://www.breckland.gov.uk/robots.txt
HTTP/2 403
cf-mitigated: challenge
server: cloudflare
(body: <title>Just a moment...</title>)

$ curl ... https://www.ticketsource.co.uk/robots.txt
HTTP/2 403
cf-mitigated: challenge
server: cloudflare

$ curl ... https://www.eventbrite.co.uk/robots.txt | grep -i -E "rss|atom|^user-agent: \*"
User-agent: *
Disallow: /atom/
Disallow: /rss/
Disallow: /events/rss/
Disallow: /events/atom/
```
- Breckland Council and TicketSource: Cloudflare managed challenge even on `robots.txt` → **Not crawlable: bot wall** (never bypass, per research D20). Re-probe once from a non-corporate network before the final classification. A different result there means the gateway, not the site, was the cause. [VERIFIED: probe output above]
- Eventbrite: RSS/Atom paths disallowed (matches spec §8.7). Event pages need their own robots + ToS check. [VERIFIED: probe output above]

### Probe ladder per candidate (≥5 s between requests to one host; descriptive UA with contact)
1. **robots.txt:** status (200 / 404 = no rules / 403 or `cf-mitigated` = bot wall). Evaluate the exact paths needed (`/`, `/events/`, `/wp-json/…`, `?ical=1`, feed URLs) for `User-agent: *`.
2. **Homepage / events page GET:** status, `content-type`, platform fingerprint (`<meta name="generator">`, `wp-content/`, `static1.squarespace.com`, Wix markers), whether event titles/dates appear in the **raw HTML** (server-rendered y/n), and `<link rel="alternate">` tags (`type="text/calendar"` means TEC iCal; `application/rss+xml` / `atom+xml` means a feed). Note embedded Google Calendar iframes (`calendar.google.com/calendar/embed?src=…`): a public calendar has an ICS URL (`…/calendar/ical/{id}/public/basic.ics` `[ASSUMED]`) that must pass its own robots check.
3. **WordPress + The Events Calendar:** `GET /wp-json/` and look for the `tribe/events/v1` namespace; `GET /wp-json/tribe/events/v1/events?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD&per_page=50&page=1`. Facts from upstream source: route `/events` under namespace `tribe` + `/events/v1`; args `page` (default 1), `per_page` (max 50 via the `tribe_rest_event_max_per_page` filter), `start_date` (default "yesterday 23:59"), `end_date` (default +24 months), plus `starts_after`, `ends_before`, `categories`, `venue`…; response includes `total`, `total_pages`, `next_rest_url`; headers `X-TEC-Total`, `X-TEC-TotalPages`; a swagger `/doc` route [CITED: github.com/the-events-calendar/the-events-calendar src/Tribe/REST/V1/Endpoints/Archive_Event.php and src/Tribe/REST/V1/Main.php, main branch at research time]. iCal: add `?ical=1` to an events archive link; the feed exports 30 events by default (`tribe_ical_feed_posts_per_page`); pages advertise it with `<link rel="alternate" type="text/calendar">` [CITED: same repo, src/Tribe/iCal.php]. Verify on **2-3 live installs** (RES-02) and record whether the REST API is enabled (some sites disable it).
4. **Feeds:** fetch, then check whether items carry **event** dates (namespaced elements, xCal, or dates in a predictable field). `pubDate` never counts (EXT-05). A feed whose items link to pages with JSON-LD is a `link_follow` candidate.
5. **JSON-LD:** extract every `<script type="application/ld+json">` block and look for `@type` `Event` or a subtype, also inside `@graph` and arrays, plus `startDate` with an offset and `eventStatus`.
6. **ToS:** find terms/legal pages. Classify: prohibits automated access → Not crawlable (ToS-prohibited); silent or vague → Not crawlable (**ToS-unclear**, D-16); explicit syndication/feeds offered → Crawlable.
7. **JS-rendered / login-walled / Facebook:** Not crawlable, with a route (manual entry / ask permission / drop).

### `docs/research/event-sources.md` template
- Header: date, method, UA used, network caveat.
- **List 1 Crawlable** (P0-P2), one block per source with the spec §14 + RES-01 fields: name · verified URL · coverage · platform · ladder rung · extraction method · server-rendered y/n · frequency · est. events/month · access method/endpoint · robots status (+ date, rule lines) · ToS finding · `legal_basis` (`own_feed_published_for_syndication | factual_extract_only | permission_granted | manual_only`, from PITFALLS P13) · feasibility · legal risk · priority · example event URL · fixture files · rung/config notes.
- **List 2 Not crawlable, for owner review:** name · URL · reason (robots-disallowed / bot wall / ToS-prohibited / ToS-unclear / JS-rendered / login / Facebook) · evidence (pasted header or rule line) · suggested route.
- Plus excluded sources with reasons, technical/legal risks, and the owner hand-off results (D-13 `checkpoint:human`).

### Fixtures (RES-03, D-14)
- Layout: `tests/Fixtures/sources/{source-slug}/{YYYY-MM-DD}-{type}.{ics|json|html|xml}` plus `tests/Fixtures/sources/{source-slug}/manifest.json`. A manifest entry per file: `file`, `url`, `fetched_at` (UTC ISO 8601), `http_status`, headers (`content-type`, `etag`, `last-modified`, `cache-control`, `cf-mitigated` if present), `robots_status` + the rule lines at fetch time, `sha256_raw`, `redactions` (count by kind), `modified` (bool). Name TEC pages `…-tec-p1.json`, `…-tec-p2.json` and capture **all** pages of one date window (EXT-02 pagination).
- **DST bonus:** today is 17 Oct 2026, eight days before BST ends (Sun 25 Oct 2026). Fixtures captured this week naturally span the transition AGENTS.md requires tests to cover. Capture windows that include 25 Oct.
- **Redaction:** emails → `redacted@example.invalid`; UK phone numbers → `01632 960000` (Ofcom drama range `[ASSUMED]`); private names → `[name removed]` after manual review. ICS: unfold lines first (RFC 5545 folding can split a value across lines), redact, and keep unfolded or re-fold at 75 octets (sabre reads either). JSON: decode → redact string values → re-encode with `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT` (structure preserved, bytes change, so `modified: true`). HTML/XML: replace inside text nodes and `mailto:`/`tel:` attributes only. Record `sha256_raw` of the original so the provenance is honest without committing personal data.
- Add an automated guard `tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php`: scan `tests/Fixtures/sources/**` for email and UK-phone patterns, allowing only the placeholders, and require every fixture file to be listed in its `manifest.json`.
- Don't commit research tooling as app code: probes are curl + manual review, logged in the doc. Phase 3 builds the real `HttpFetcher`/`RobotsPolicy`.

## Common Pitfalls

### Pitfall 1: Filament `keyBindings()` fires while typing
**What goes wrong:** `A` typed into the reject note approves the draft.
**Why it happens:** Filament always emits `x-mousetrap.global.*` [VERIFIED: CanGenerateButtonHtml.php:198].
**How to avoid:** use the custom Alpine handler (Pattern 5) with the input/modal/modifier guards and the on/off toggle.
**Warning signs:** a browser test typing "a" into the reject textarea changes `review_status`.

### Pitfall 2: Two transactions for approve
**What goes wrong:** the event is published but the draft stays `pending_review` (or the reverse); a double-click creates two events.
**How to avoid:** one outer `DB::transaction` with `lockForUpdate()` and the `assertCanTransitionTo` check inside the lock, plus the partial unique index on `event_drafts(event_id) WHERE kind='new'`.
**Warning signs:** `Event::count()` 2 after calling `ApproveDraft` twice in a test.

### Pitfall 3: Private organiser email leaks publicly
**What goes wrong:** approval copies the email into `events.organiser_contact`, which cards and event pages render today.
**How to avoid:** the `contact_public` column + DTO changes (Pattern 1); feature tests on the card and the event page for both flag values.

### Pitfall 4: "Today" in UTC
**What goes wrong:** between 00:00 and 01:00 BST, Laravel's `after_or_equal:today` uses the app timezone (UTC) and accepts yesterday or rejects today.
**How to avoid:** compute the bound as `CarbonImmutable::now('Europe/London')->toDateString()` and test at `freezeLondon('2026-07-01 00:30')`.

### Pitfall 5: Honeypot that a bot can skip
**What goes wrong:** the check runs only when the trap field is present (spatie's default).
**How to avoid:** require presence; test a POST without the honeypot fields → no draft.

### Pitfall 6: Table-level selectability blocks bulk reject
**What goes wrong:** `checkIfRecordIsSelectableUsing()` stops parse-error/Low drafts from being selected at all, so they can't be bulk rejected, and REV-08 wants a **visible** skip.
**How to avoid:** let everything be selectable; bulk approve calls `DraftPublishability::blockers($draft, forBulk: true)`, which adds Low band, parse errors, duplicate flag, unresolved venue and unset repeats to the single-approve blockers, and reports each skip with `reportBulkProcessingFailure($reasonKey, …)`. Each record approves in its own `ApproveDraft` transaction, so one failure doesn't roll back the rest.

### Pitfall 7: Turnstile breaks tests or ships disabled
**What goes wrong:** a browser test loads `challenges.cloudflare.com`; or production runs with empty keys and accepts everything.
**How to avoid:** render the widget only when a site key is set; fail closed when the secret is missing; add an `ops:health` warning in production.

### Pitfall 8: Rate limit keyed on the proxy IP
**What goes wrong:** behind Cloudflare + Caddy (Phase 5) every request shares one IP, so one spammer locks everyone out, or the limit never triggers.
**How to avoid:** key on `$request->ip()` now, and add a Phase 5 todo to configure `$middleware->trustProxies(at: …)` [VERIFIED: vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:698] for Caddy, with Cloudflare's real client IP forwarded. Add a STATE.md concern.

### Pitfall 9: Re-seeding overwrites admin rules
**What goes wrong:** `updateOrCreate` in `RulesSeeder` reverts the admin's edits on deploy.
**How to avoid:** `firstOrCreate` only.

### Pitfall 10: Deleting the `Other` category or a used category from the Rules screen
**How to avoid:** guard in the save action and hide the delete action for `other`; the FK `restrictOnDelete` is the backstop, but surface a friendly message instead of a 500.

### Pitfall 11: Recurring draft that can't round-trip
**What goes wrong:** a stored rule that `ManualRecurrence::tryFromStoredRule` returns `null` for causes `fromDraft()` to throw.
**How to avoid:** add it as a blocker ("This repeat rule can't be published from the form") in Phase 2 and leave a Phase 3 note.

### Pitfall 12: Existing tests and UAT flows after rerouting manual entry
**What goes wrong:** `tests/Feature/Filament/CreateEventTest.php` and `tests/Browser/AdminCreateEventTest.php` assume only `events` rows appear.
**How to avoid:** keep `handleRecordCreation()` returning the `Event` and add assertions that a `manual` draft row exists, is `approved` and points at the event. Update `docs/uat/browser-uat.md` F3 and add flows (F11 submit, F12 review queue/approve & next, F13 bulk, F14 rules).

### Pitfall 13: Livewire on public pages
**What goes wrong:** using a Livewire form for live validation violates the public-site rule.
**How to avoid:** plain POST form; Alpine only for the optional show/hide.

## Code Examples

Bulk approve with visible skips (Filament 5 API shape from vendor docs):
```php
// Source: vendor/filament/tables/docs/04-actions.md:249-289 (shape); domain calls are this phase's
use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

BulkAction::make('approveSelected')
    ->label('Approve selected')
    ->requiresConfirmation()
    ->action(function (BulkAction $action, Collection $records): void {
        $records->each(function (EventDraft $draft) use ($action): void {
            $blockers = app(DraftPublishability::class)->bulkBlockerKeys($draft); // e.g. ['low_quality']
            if ($blockers !== []) {
                $action->reportBulkProcessingFailure(
                    $blockers[0],
                    message: fn (int $failureCount): string => DraftPublishability::skipMessage($blockers[0], $failureCount),
                );
                return;
            }
            app(ApproveDraft::class)($draft, auth()->user());
        });
    })
    ->successNotificationTitle('Approved')
    ->failureNotificationTitle(fn (int $successCount, int $totalCount): string => "Approved {$successCount} of {$totalCount}; the rest were skipped")
    ->deselectRecordsAfterCompletion();
```

Retention (D-12) on the model:
```php
// Source: Laravel MassPrunable (vendor/laravel/framework/src/Illuminate/Database/Eloquent/MassPrunable.php)
use Illuminate\Database\Eloquent\MassPrunable;

public function prunable(): Builder
{
    $now = CarbonImmutable::now()->utc();

    return static::query()
        ->where('review_status', ReviewStatus::Rejected)
        ->where(fn (Builder $q) => $q
            ->where(fn (Builder $s) => $s->where('reject_reason', RejectReason::Spam)->where('reviewed_at', '<', $now->subDays(30)))
            ->orWhere('reviewed_at', '<', $now->subDays(90)));
}
// routes/console.php
Schedule::command('model:prune', ['--model' => [EventDraft::class]])->dailyAt('03:40')->timezone('Europe/London');
```
Test with `travelTo` at 29/31 days (spam) and 89/91 days (other); assert approved and pending drafts are never pruned.

Feature test skeleton (offline Turnstile):
```php
it('creates a pending submission draft and shows the reference', function () {
    config(['services.turnstile.secret' => 'test-secret', 'services.turnstile.hostnames' => ['whatsonattleborough.co.uk']]);
    Http::fake([TurnstileVerifier::SITEVERIFY_URL => Http::response([
        'success' => true, 'action' => 'submit', 'hostname' => 'whatsonattleborough.co.uk', 'error-codes' => [],
    ])]);
    freezeLondon('2026-10-20 10:00');

    $response = $this->post(route('submit.store'), validSubmission());   // helper adds honeypot token minted >3 s ago

    $response->assertRedirect(route('submit.thanks'));
    expect(EventDraft::sole())
        ->origin->toBe(EventOrigin::Submission)
        ->review_status->toBe(ReviewStatus::PendingReview)
        ->and(Event::count())->toBe(0);
    Http::assertSent(fn ($request) => $request['response'] === 'tok' && $request['secret'] === 'test-secret');
});
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `VerifyCsrfToken` middleware | Laravel 13 `PreventRequestForgery` (origin + token) wired in the panel provider | Laravel 13 | Public form still uses `@csrf`; nothing extra |
| Filament 3 `protected static string $view` | Filament 5 `protected string $view` (non-static) | Filament 4/5 | Custom page code from older tutorials won't compile |
| reCAPTCHA (cookies, Google) | Turnstile (no puzzle; Cloudflare says WCAG 2.2 AA [CITED: developers.cloudflare.com/turnstile]) | — | Still a third-party script; scope it to `/submit` |
| spatie honeypot default "optional fields" | Required presence | — | See Pitfall 5 |

**Deprecated/outdated:** `failureNotificationMessage()` on actions is deprecated in favour of `failureNotificationTitle()` [VERIFIED: vendor/filament/actions/src/Concerns/CanNotify.php:110-121].

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | New enum values (`DraftKind`, `ReviewStatus`, `RejectReason`, `QualityBand`, `ExtractionMethod`) as listed | Pattern 1 | Low: plan locks them; CHECK constraints must match |
| A2 | Quality weights and reason wording | Pattern 4 | Low: tunable in config; may shift bands |
| A3 | Default `pg_trgm.similarity_threshold` is 0.3 | Pattern 4 | Low: we set our own threshold explicitly |
| A4 | Mousetrap `bindGlobal` fires inside inputs | Pattern 5 / Pitfall 1 | Low: the custom handler is safer either way |
| A5 | Hostname returned by Turnstile test secrets (and whether it equals `localhost`) | Pattern 8 | Low: hostname check skipped locally |
| A6 | Turnstile's cookie/storage behaviour (for the privacy wording) | Security / Open Q2 | Medium: privacy page could misstate processing; check the Turnstile Privacy Addendum before writing it |
| A7 | Ofcom drama number `01632 960000` is safe as a redaction placeholder | Fixtures | Low |
| A8 | Google Calendar public ICS URL shape `…/calendar/ical/{id}/public/basic.ics` | Research ladder | Low: verified per source at execution |
| A9 | Starter keyword rules list | Pattern 9 | Low: admin-editable |
| A10 | Rate-limit thresholds (5/hour, 10/day per IP) and timing token (3 s min, 120 min max) | Patterns 7, Don't Hand-Roll | Low: config |
| A11 | Filament `createOptionAction(fn (Action $a) => $a->fillForm(...))` to pre-fill "Create new venue" from draft text | Pattern 1/D-05 UI | Medium: confirm with Boost `search-docs` before coding |

## Open Questions (RESOLVED)

All six were resolved on 2026-10-17: Q1-Q5 by the owner's planning decisions D-17..D-21 in 02-CONTEXT.md, Q6 by the STATE.md "[Phase 5]" trusted-proxies concern. Each item below names its resolution.

1. **No-JS submitters vs Turnstile.** RESOLVED by D-18: a `<noscript>` "email us instead" notice on /submit.
   - What we know: Turnstile needs JS; CONTEXT wants public pages usable without JS.
   - What's unclear: whether the owner accepts "email us instead" as the no-JS route.
   - Recommendation: plan the `<noscript>` fallback and confirm it in the plan's first checkpoint.
2. **Third-party script exception wording + privacy text for Turnstile.** RESOLVED by D-17: Turnstile allowed on /submit only, rule exception in AGENTS.md, extended NoThirdPartyAssetsTest, in-house verifier, privacy page mentions Turnstile.
   - What we know: SUB-04 requires Turnstile; AGENTS.md bans third-party scripts; PITFALLS M10 flags Turnstile storage as unverified.
   - Recommendation: owner approves a one-line AGENTS.md exception ("Turnstile on /submit only"). The interim `/privacy` page names Cloudflare Turnstile as a spam check that processes technical data (IP, browser signals) under legitimate interests and links Cloudflare's Turnstile Privacy Addendum (`https://www.cloudflare.com/turnstile-privacy-policy/`, linked from the Turnstile docs index [CITED: developers.cloudflare.com/turnstile]). It must not claim "no cookies" until verified.
3. **What "source" means in REV-09 before a `sources` table exists.** RESOLVED by D-20: "source" means origin until Phase 3.
   - Recommendation: in Phase 2 the "Source" filter is the `origin` (Public submission / Manual entry) and the source panel shows origin + `source_url` host. Phase 3 adds `source_id` and switches the filter. Record this so the verifier doesn't fail SC2.
4. **Bulk approve and suggested (not confirmed) venues.** RESOLVED by D-21: only a confirmed `venue_id` counts; a suggestion alone makes bulk approve skip the draft with that reason.
   - What we know: D-05 pre-selects the suggestion; bulk skips "unresolved venues".
   - Recommendation: only a stored `venue_id` counts as resolved. An exact-match suggestion still needs one click on the review page. Confirm with the owner if bulk throughput matters.
5. **`organiser_website` column on `events`.** RESOLVED by D-19: add `events.contact_public` (default true) and `events.organiser_website`.
   - Recommendation: add it (Pattern 1). The alternative (folding the website into `source_url`) mixes "where we found it" with "who runs it" and blocks D-03's "website always public, email optional" rule. Costly-reversibility decision, so state it in the plan.
6. **Trusted proxies.** RESOLVED: recorded as the STATE.md "[Phase 5]" trusted-proxies concern. Not needed for local Phase 2; add a Phase 5 concern to STATE.md (Pitfall 8).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | All | ✓ | 8.4.12 | — |
| Laravel / Filament / Livewire | All | ✓ | 13.37.0 / 5.10.2 / 4.4.7 | — |
| PostgreSQL + pg_trgm | Drafts, venue matching, tests | ✓ | 18.6 / 1.6 | — |
| Node / npm (Vite build for Tailwind + Filament theme) | Public form CSS, admin view classes | ✓ | node v24.21.0 | — |
| Playwright (pest-plugin-browser) | Browser UAT flows | ✓ | 1.64.1 | Manual UAT per runbook |
| curl, jq, xmllint, python3 | Source research probes / fixture redaction | ✓ | system | — |
| Live internet to candidate sites | RES-01..03 | Partial: corporate gateway in path; Cloudflare challenges seen on Breckland/TicketSource | — | Re-probe from another network; record "no observation (local gateway)" |
| Cloudflare Turnstile account (real keys) | Production submissions | ✗ (not needed until Phase 5) | — | Cloudflare test keys locally |
| Owner input (local URLs, Facebook-only groups) | D-13 `checkpoint:human` | Pending | — | None; on the critical path for Phase 3 (STATE blocker) |

**Missing dependencies with no fallback:** owner URL hand-off (blocks RES completion, not the code plans).
**Missing dependencies with fallback:** real Turnstile keys (test keys until deploy); unrestricted network (re-probe elsewhere).

## Validation Architecture

Skipped: `workflow.nyquist_validation` is explicitly `false` in `.planning/config.json`. Test expectations are given inline (Patterns 3, 7, 8, Code Examples, Pitfalls) and in the Browser UAT flow additions (Pitfall 12).

## Security Domain

`security_enforcement: true`, ASVS level 1, block on high (`.planning/config.json`).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (admin only) | Existing Filament login + `AdminGate`; no public accounts |
| V3 Session Management | yes | Laravel database sessions; CSRF via `@csrf` on the public form |
| V4 Access Control | yes | `AdminGate::authorize()` at the start of `ApproveDraft`/`RejectDraft`/`ReopenDraft`/rules save (defence in depth, like Phase 1 actions); Filament panel gate; never embed un-authorised Livewire components (Pattern 9) |
| V5 Input Validation | yes | `StoreSubmissionRequest` (Laravel validation), `url:http,https`, length caps, enum `in:` rules, `PublishableEventData` invariants, DB CHECK constraints |
| V6 Cryptography | yes (light) | `Crypt::encryptString` for the timing token (app key); never hand-roll |
| V7 Error/Logging | yes | Log spam rejections without PII; no stack traces to the public |
| V11 Business Logic / anti-automation | yes | Honeypot + timing + rate limit + Turnstile (hostname/action checked, fail closed) |
| V12 Files | no | No uploads (no images by design) |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Form spam / bot floods | Denial of service, Tampering | Honeypot (required presence), timing token, `throttle:submissions`, Turnstile siteverify, moderation |
| Forged/replayed Turnstile token | Spoofing | Server siteverify; check `success`, `action`, `hostname`; single-use; fail closed on network error |
| Stored XSS via submitted text shown in admin/public | Tampering | Escaped Blade `{{ }}` only; Filament `Callout` escapes descriptions (EventForm comment); plain-text descriptions |
| Malicious links (phishing) under the site's brand | Spoofing | `url:http,https`; `SafeLinks::http`; `rel="nofollow ugc noopener"`; admin approval mandatory |
| Personal data exposure (organiser email; fixtures in a public repo) | Information disclosure | `contact_public` gate on events; no IP storage; retention purge; fixture redaction + automated scan test |
| Mass assignment of review state | Elevation of privilege | Review/publishing columns not fillable; `forceFill` only in `DraftWriter`/actions (WR-07 pattern) |
| Double approval race | Tampering | `lockForUpdate` + transition assert + partial unique index |
| Rate-limit bypass via proxy IP confusion | DoS | Key on `$request->ip()`; configure trusted proxies at deploy (Phase 5) |
| CSRF on public POST | Tampering | `@csrf` / `PreventRequestForgery` |
| Admin shortcut misfire | Tampering (accidental) | Input/modal guards, toggle, server-side blockers |

## Sources

### Primary (HIGH confidence: read this session from installed code or the repo)
- Repo: `AGENTS.md`; `.planning/{REQUIREMENTS,STATE,ROADMAP}.md`; `02-CONTEXT.md`; `docs/product-specification.md` §6-§14; `.planning/research/{ARCHITECTURE,PITFALLS,STACK,SUMMARY}.md` (relevant sections)
- Code: `app/Publishing/Actions/PublishEvent.php`, `app/Publishing/Data/PublishableEventData.php`, `app/Publishing/AdminGate.php`, `app/Enums/{EventOrigin,EventStatus,PriceType,RepeatPattern,RepeatEnds}.php`, `app/Calendar/ManualRecurrence.php`, `app/Filament/Resources/Events/{Pages/CreateEvent,Schemas/EventForm,EventResource}.php`, `app/Models/{Event,Venue,Category}.php`, `app/Support/{EventPageData,OccurrenceCardData}.php`, migrations (pg_trgm, categories, venues, events, audit columns), `database/seeders/CategorySeeder.php`, `routes/{web,console}.php`, `bootstrap/app.php`, `app/Providers/Filament/AdminPanelProvider.php`, `tests/Pest.php`, `tests/Feature/Public/NoThirdPartyAssetsTest.php`, `phpunit.xml`, `config/{site,calendar,services}.php`
- Vendor (Filament 5.10.2): `support/src/View/Concerns/CanGenerateButtonHtml.php`, `actions/src/Concerns/{HasKeyBindings,InteractsWithSelectedRecords,CanNotify,InteractsWithActions}.php`, `tables/src/Table/Concerns/HasBulkActions.php`, `tables/docs/04-actions.md`, `schemas/docs/09-custom-components.md`, `forms/docs` (table repeaters), `filament/src/Resources/{Pages/Concerns/InteractsWithRecord,Concerns/HasTabs,Resource/Concerns/HasGlobalSearch}.php`, `filament/resources/js/index.js` (alpine-mousetrap)
- Vendor (Laravel 13.37.0): `Cache/RateLimiting/Limit.php`, `Foundation/Configuration/Middleware.php`, `Database/Eloquent/MassPrunable.php`, `Database/Console/PruneCommand.php`, `Http/Client/Factory.php`, `Validation/Concerns/ValidatesAttributes.php`
- Live probes (pasted above): Breckland, TicketSource, Eventbrite `robots.txt`

### Secondary (MEDIUM confidence: official docs / upstream source fetched this session)
- developers.cloudflare.com/turnstile: server-side validation, testing keys, client-side rendering, widget configurations, CSP, index (privacy addendum link, WCAG claim)
- github.com/the-events-calendar/the-events-calendar (main branch): `src/Tribe/REST/V1/Endpoints/Archive_Event.php`, `src/Tribe/REST/V1/Main.php`, `src/Tribe/iCal.php`
- github.com/ryangjchandler/laravel-cloudflare-turnstile v3.0.4 source; github.com/spatie/laravel-honeypot 4.7.3 source; Packagist API metadata
- w3.org/WAI/WCAG22/Understanding/character-key-shortcuts.html (SC 2.1.4 text)

### Tertiary (LOW confidence)
- Training knowledge items in the Assumptions Log (A3-A8, A11)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH. Everything is already installed and versions were read with `composer show`; no new packages.
- Architecture: HIGH. Built directly on Phase 1 code that was read this session; Filament APIs confirmed in vendor source.
- Spam defence: MEDIUM-HIGH. Cloudflare docs fetched; package gaps confirmed in source; the no-JS/third-party conflicts need owner confirmation.
- Source research: MEDIUM for method and TEC contract; LOW for outcomes, which depend on live execution and the owner hand-off.
- Pitfalls: HIGH for vendor-verified ones (1, 6), MEDIUM for the others.

**Research date:** 2026-10-17
**Valid until:** 2026-11-16 for code/framework findings (stable, pinned versions); re-probe sources at execution time (live sites change daily)

