# Phase 3: Ingestion Backbone, ICS & Change/Duplicate Handling - Context

**Gathered:** 2026-10-17
**Status:** Ready for planning

<domain>
## Phase Boundary

The admin registers ICS sources that are fetched politely on a daily schedule (or with "Run now"). Each run turns new, changed, cancelled and missing feed items into the right drafts in the Phase 2 review queue (new / update / cancellation kinds), with duplicate warnings and visible source health. Nothing published is ever lost to a source failure, and approved discovered events show source attribution and a short excerpt.

In scope: `sources`, `source_runs`, `source_items`, the polite `HttpFetcher` (robots on every URL, UA, ≥5 s per host, conditional GET, limits, SSRF guard, per-rung body validation), the ICS extractor, scheduling and jobs, health tracking, change detection with an accepted baseline, update/cancellation drafts and their review actions, duplicate detection and "Mark as duplicate", source attribution and excerpts on public pages (SRC-01, SRC-03..09, EXT-01, EXT-07, PIPE-01, PIPE-05..09, REV-05..07, LEG-04).

Out of scope (other phases): RSS, TEC REST, JSON-LD and selector extractors and the geofence on regional sources (Phase 4); dashboard counts, sitemap, takedown flow, Verified Bots (Phase 5).

</domain>

<decisions>
## Implementation Decisions

### Feed volume and noise
- **D-01:** **New sources start with a test run.** Adding (or re-enabling) a source shows a preview of what a run would do: how many drafts it would create, how many items it would skip and why (past, beyond 12 months, skip rule, parse error). Nothing is drafted until the admin clicks **"Create drafts"**, which turns the source live. This is the "new-source onboarding preview" from research D19.
- **D-02:** **Default check frequency: daily, early morning** (London time), per-source override allowed. Honour each source's robots `Crawl-delay` (e.g. Baptist Church 10 s) on top of the ≥5 s per-host minimum.
- **D-03:** **One draft per feed item, plus an "Approve all like this" bulk action.** Feeds that list every week as a separate item (Town Council: yoga, WI, cafés; ~60 items/month) are not grouped or guessed into series. The review queue gets an action that approves every pending draft from the same source with the same title and venue in one go. It goes through the existing per-draft `ApproveDraft` checks and reports skips like bulk approve (Phase 2 D-21 rules still apply).
- **D-04:** **Per-source skip/keep title rules.** Each source can have rules on item titles (e.g. skip "Private hire", "Council meeting", "Sunday Service"; or keep-only rules). Skipped items never become drafts and are counted in the run log as "skipped (rule)". Rules are whole-word, case-insensitive, like Phase 2 keyword rules.
- **D-05:** **Pre-load the three ready ICS sources as inactive:** Attleborough Baptist Church (ICS), Attleborough Town Council (TEC iCal export), Attleborough Academy (ICS), with URLs, legal basis and notes from `docs/research/event-sources.md`. The admin opens each, runs the test preview and activates it. Owner clarification of the "seeders seed reference data only" rule: inactive source records are reference configuration, not demo events, so seeding them is allowed. The seeder must use firstOrCreate and never overwrite admin edits.

### Updates and cancellations
- **D-06:** **Update drafts only for facts residents rely on:** title, date/time (including all-day and end), venue, price/free, family-friendly, cancelled status. Wording-only description changes update the stored source text and the accepted baseline quietly, with no draft. (Refines research D13: the semantic `content_hash` covers these fields.)
- **D-07:** **"Apply" takes all changed fields shown in the three-column diff** (published now / source before / source now). No per-field picking. Apply and Ignore both move the accepted baseline (REV-06).
- **D-08:** **Confirming a cancellation keeps the date visible as "Cancelled"**, using the Phase 1 occurrence status (shown as text). For a series, only the affected date changes; the rest is untouched. One-off events become cancelled the same way.
- **D-09:** **Urgent changes jump the queue:** update and cancellation drafts for events starting within the next 48 hours (London time) get an "Urgent" badge and sort to the top of the Pending tab.

### Duplicates and ownership
- **D-10:** **Marking a draft as a duplicate keeps the existing published details.** The admin's edits win; nothing new is published. The feed item is linked to the event through `event_sources` (REV-05) for attribution and future changes.
- **D-11:** **Linked sources keep watching.** After linking, later changes or cancellations of that item in the feed raise update/cancellation drafts for the linked event, compared against that link's accepted baseline.
- **D-12:** **Same event in two feeds: the organiser's own source is the main attribution** (e.g. Heritage Group over the Town Council feed). Other linked sources show as "Also listed by". Any linked source can raise update/cancellation drafts.
- **D-13:** **Duplicate warnings show the suspected match side by side** on the review page: date, time, venue, title and the reason (same canonical URL, or same date + similar venue + similar title with the similarity score), with **"Mark as duplicate"** and **"Not a duplicate"** buttons. "Not a duplicate" is remembered so the same pair isn't flagged again.

### Public attribution and excerpts
- **D-14:** **Attribution on the event page and the calendar card.** Event page: "Listed by {source name}" with a link near the event details (plus "Also listed by …" per D-12). Calendar card: a short "via {source short name}" line. (LEG-04)
- **D-15:** **Description = auto excerpt, editable before approval.** Up to 200 characters, phone numbers and emails stripped, then "Read more at {source}". The admin can edit the excerpt on the edit page before approving. The full source text is kept internally for review only. (PIPE-09)
- **D-16:** **"Read more" links to the event's own page on the source site** when the feed gives one (ICS `URL`), otherwise the source's events page. Links go through the existing SafeLinks http/https rule.
- **D-17:** **Never publish organiser names or contacts from feeds.** Feeds name private people (the Town Council feed carries personal names, emails and phones). Discovered events show the source as attribution instead. The admin can add an organiser by hand when editing. Organiser fields from feeds may be kept internally for review but never reach public pages.

### Roadmap
- **D-18 [informational]:** Owner removed the `**Mode:** mvp` tag from Phase 3 in ROADMAP.md (same as Phases 1 and 2).

### Owner decisions after research (2026-10-17, 03-RESEARCH.md open questions)
- **D-19:** **ICS recurrence scope = option B.** Publish one-off events and repeat rules the admin form can represent (ManualRecurrence round-trip, Phase 1 WR-10). Add an `excluded_dates` store so EXDATE exclusions survive. A RECURRENCE-ID override that moves a single date, or any rule the form can't represent, becomes a parse error on the draft that tells the admin what to fix. RDATE-only extras are parse errors too. — **Reversibility:** costly — `excluded_dates` is a schema addition that occurrence sync and published series depend on.
- **D-20:** **Keep `calendar.horizon_days` at 90.** The import window is therefore 90 days ahead (not 12 months): items further ahead are skipped and counted as `skipped_far_future` (this still satisfies PIPE-01's ">12 months skipped and counted"). Missing-item detection only considers items inside this window. A confirmed cancellation therefore never falls outside the occurrence horizon.
- **D-21:** **Feed items with no description use a neutral fallback** such as "Details from {source name}." followed by "Read more at {source}", and can be approved (individually, in bulk and via "Approve all like this"). The admin can still edit the excerpt (D-15).
- **D-22:** **A venue set as the source's default venue counts as confirmed** (`venue_id`), so those drafts aren't skipped by bulk approve for an unresolved venue (Phase 2 D-21).
- **D-23:** **Rejected source items stay rejected**: later runs don't re-draft an item the admin rejected unless its facts change or the admin reopens it.
- **D-24:** **"Create drafts" after a test run reuses the preview's fetched body if it's under 60 minutes old**, to avoid a second request to the source; otherwise it fetches again politely.
- **D-25:** **Primary attribution (D-12) is a per-source flag "Organiser's own site"** set on the source. When two linked sources cover one event, a source with that flag is the main attribution; otherwise the first approved source is.
- **D-26 [informational]:** D-01's test-run preview delivers part of SRC-02 (mapped to Phase 4); plans should note it as partly delivered here.

### Carried forward (do not re-ask)
- Architecture from research SUMMARY D13, D14, D19, D20 and Phase 3 notes: one source item per ICS series UID (master + overrides + EXDATE/RDATE in one fragment and hash); `raw_hash` to skip unchanged content plus a semantic `content_hash`; accepted baseline (`accepted_hash` + `accepted_payload`) updated on approve, apply and ignore; past and >12-month items skipped and counted; per-rung body validation (`BEGIN:VCALENDAR`), a bot wall or wrong content type is a failed `content_invalid` run, a robots disallow is a `blocked` run that doesn't degrade health; SSRF guard; never bypass bot protection.
- sabre/vobject is the only recurrence engine; floating times are Europe/London; unknown TZIDs are parse errors; store UTC (Phase 1 rules, EXT-07). `OccurrenceGenerator` preserves admin-set occurrence status.
- Missing-item detection (PIPE-08): only after 2 consecutive complete runs, only for future items inside the run's observed date window, only when the source supports missing detection (research D3).
- Health: degraded after 3 consecutive failures, unhealthy (paused) after 5; zero items after previous results flagged (SRC-08). Source failures never hide published events (SRC-09).
- All writes to events/occurrences go through `App\Publishing\Actions` (PublishEvent sole creator, arch test). New actions: apply update, ignore, confirm cancellation, mark duplicate.
- Review queue from Phase 2 (tabs, filters, bulk approve with named skips, Approve & next) is extended, not replaced. REV-09 "source" filter switches from origin to the real `sources` table.
- Honest identifying User-Agent; source inventory and legal basis per `docs/research/source-inventory.md` (D-23..D-26 of Phase 2). The Town Council blocks datacenter IPs: production may need the clerk allowlist (Phase 5 concern).

### Agent's Discretion
- Exact title-similarity threshold and duplicate scoring (start from pg_trgm ≥ 0.3 like the venue matcher, tune with fixtures).
- Raw body storage format and retention period (keep enough for debugging; prune old raw bodies).
- Wording of run-log statuses, health badges and the preview screen, within the Phase 1 brand voice.
- Early-morning schedule time and per-source jitter.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Product scope and requirements
- `.planning/ROADMAP.md` — Phase 3 goal and success criteria 1–5
- `.planning/REQUIREMENTS.md` — SRC-01, SRC-03..09, EXT-01, EXT-07, PIPE-01, PIPE-05..09, REV-05..07, LEG-04
- `docs/product-specification.md` — v1.1 baseline (§6 extraction ladder, §8.4 updates/cancellations, §8.8 attribution/excerpts)

### Research
- `.planning/research/SUMMARY.md` — deltas D3, D6, D13, D14, D19, D20 and the Phase 3 delivery notes
- `.planning/research/ARCHITECTURE.md` — sources / runs / items model, HttpFetcher, change detection (P3–P6)
- `.planning/research/PITFALLS.md` — P1, P3, P5–P9, M5 (recurrence, robots, hashing, missing items, bot walls)
- `.planning/research/STACK.md` — sabre/vobject 5, spatie/robots-txt, Laravel Http, pg_trgm

### Sources and fixtures
- `docs/research/source-inventory.md` — owner-facing inventory: which sources are feed/API, crawlable, manual, blocked
- `docs/research/event-sources.md` — per-source evidence: endpoints, robots rules, crawl delays, legal basis, fixture index
- `tests/Fixtures/sources/` — redacted ICS/JSON fixtures (Baptist Church ICS + RSS, Town Council iCal + TEC REST, Academy ICS) spanning the 25 Oct 2026 clock change
- `ops/probe-sources.sh` — polite probe script (robots, crawl delay, never saves challenge pages)

### Prior phase decisions
- `.planning/phases/01-foundation-published-calendar/01-CONTEXT.md` — time rules, occurrence status, brand voice
- `.planning/phases/02-draft-model-review-queue-submissions/02-CONTEXT.md` — draft model, review queue, D-19 contact_public, D-21 bulk rules, D-23..D-26 source decisions
- `AGENTS.md` and `.ai/guidelines/attleborough.md` — project rules (time, recurrence, writes, Filament 5, public site, tests)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Review/DraftWriter.php` + `app/Review/Data/DraftData.php`: write discovered items as drafts (origin `source`), scoring and category on every write.
- `app/Enums/DraftKind.php`: `New`, `Update`, `Cancellation` already exist for update/cancellation drafts.
- `app/Publishing/Actions/ApproveDraft.php`, `UpdatePublishedEvent.php`, `ChangeOccurrenceStatus.php`, `ChangeEventStatus.php`: the write choke point to extend with apply-update / confirm-cancellation / mark-duplicate actions.
- `app/Review/DraftPublishability.php`: blockers and bulk skip keys (`duplicate` key reserved for Phase 3 warnings).
- `app/Review/VenueMatcher.php`: pg_trgm similarity pattern with bound parameters, reusable for title/venue duplicate checks.
- `app/Review/CategoryAssigner.php`: category mappings are link 2 of the chain; Phase 3 adds a nullable `source_id` to `category_mappings` (migration comment).
- `app/Calendar/RecurrenceExpander.php`, `OccurrenceGenerator.php`, `ManualRecurrence.php`: sabre/vobject expansion in Europe/London; preserve admin occurrence status.
- `app/Ops/HealthReport.php` + `app/Filament/Pages/SystemHealth.php`: add source health checks next to heartbeats.
- `app/Support/SafeLinks.php`, `EventPageData.php`, `OccurrenceCardData.php`: attribution and "Read more" link rendering.
- `app/Filament/Resources/EventDrafts/*`: review page, edit-and-approve, bulk actions, keyboard shortcuts to extend with diff and duplicate views.

### Established Patterns
- Domain writes in `App\Publishing\Actions` with AdminGate first, locked rows, transition checks, one transaction.
- Tests: Pest 5 on PostgreSQL, offline (`Http::preventStrayRequests`, `Http::fake`), frozen London time, DST fixtures, raw-SQL UTC assertions; browser UAT flows in `tests/Browser` with `Uat::settleFields`.
- Rules screen pattern (`app/Filament/Pages/Rules.php` + `SaveRules`) for per-source skip/keep rules UI.

### Integration Points
- New `sources` / `source_runs` / `source_items` / `event_sources` tables; `event_drafts` gains source/item links.
- Scheduler (`routes/console.php`) for daily dispatch; database queue workers for fetch/process jobs.
- Review queue filters (REV-09 source filter), Approve & next, bulk actions.
- Public event page and calendar card (attribution, excerpt).

</code_context>

<specifics>
## Specific Ideas

- The Town Council feed expands recurring sessions into one item per date (no RRULE) and includes private hires and council meetings: the main motivator for D-03 and D-04.
- The Town Council iCal export returns about 30 events by default; its observed date window matters for missing-item detection.
- Academy ICS UIDs change on every fetch: dedupe on title + start time within the source.
- Baptist Church robots `Crawl-delay: 10`; Attleborough Primary `Crawl-delay: 12000` (fixture still pending, owner to capture before planning).

</specifics>

<deferred>
## Deferred Ideas

- Grouping per-occurrence feed items into a suggested series (considered for D-03, declined: guessing; revisit if volume makes "Approve all like this" insufficient).
- Picking individual fields when applying an update (declined for D-07; revisit if feeds change several facts at once and the admin wants partial applies).

</deferred>

---

*Phase: 03-ingestion-backbone-ics-change-duplicate-handling*
*Context gathered: 2026-10-17*
