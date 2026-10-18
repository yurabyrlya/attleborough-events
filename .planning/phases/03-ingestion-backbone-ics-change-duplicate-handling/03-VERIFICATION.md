---
phase: 03-ingestion-backbone-ics-change-duplicate-handling
verified: 2026-10-18T14:25:00Z
status: passed
score: "5/5 roadmap success criteria verified; 10/10 must-NOT prohibitions enforced (9 test-tier with named tests, 1 judgment-tier flagged for the owner)"
covered_files: [".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-01-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-01-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-02-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-02-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-03-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-03-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-04-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-04-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-05-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-05-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-06-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-06-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-07-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-07-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-08-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-08-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-09-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-09-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-10-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-10-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-11-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-11-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-12-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-12-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-13-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-13-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-14-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-14-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-15-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-15-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-16-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-16-SUMMARY.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-17-PLAN.md", ".planning/phases/03-ingestion-backbone-ics-change-duplicate-handling/03-17-SUMMARY.md", "app/Calendar/OccurrenceGenerator.php", "app/Calendar/PublicOccurrenceQuery.php", "app/Calendar/RecurrenceExpander.php", "app/Casts/SourceDiff.php", "app/Console/Commands/IngestDispatchDue.php", "app/Console/Commands/IngestPruneRaw.php", "app/Console/Commands/OpsHealth.php", "app/Enums/ExtractionMethod.php", "app/Enums/FetchFailureKind.php", "app/Enums/IdentityStrategy.php", "app/Enums/RejectReason.php", "app/Enums/RunMode.php", "app/Enums/RunStatus.php", "app/Enums/RunTrigger.php", "app/Enums/SourceHealth.php", "app/Enums/SourceItemStatus.php", "app/Filament/Pages/Rules.php", "app/Filament/Pages/SystemHealth.php", "app/Filament/Resources/EventDrafts/Actions/ReviewActions.php", "app/Filament/Resources/EventDrafts/Pages/EditEventDraft.php", "app/Filament/Resources/EventDrafts/Pages/ReviewEventDraft.php", "app/Filament/Resources/EventDrafts/Schemas/DiscoveredDraftDetails.php", "app/Filament/Resources/EventDrafts/Schemas/EventDraftInfolist.php", "app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php", "app/Filament/Resources/Events/Schemas/EventForm.php", "app/Filament/Resources/Sources/Actions/SourceActions.php", "app/Filament/Resources/Sources/Pages/CreateSource.php", "app/Filament/Resources/Sources/Pages/EditSource.php", "app/Filament/Resources/Sources/Pages/ListSources.php", "app/Filament/Resources/Sources/RelationManagers/RunsRelationManager.php", "app/Filament/Resources/Sources/Schemas/SourceFieldHelp.php", "app/Filament/Resources/Sources/Schemas/SourceForm.php", "app/Filament/Resources/Sources/SourceResource.php", "app/Filament/Resources/Sources/Tables/SourcesTable.php", "app/Http/Controllers/EventController.php", "app/Ingestion/CanonicalUrl.php", "app/Ingestion/Data/ChangeVerdict.php", "app/Ingestion/Data/DuplicateMatch.php", "app/Ingestion/Data/ExtractedItem.php", "app/Ingestion/Data/ExtractionResult.php", "app/Ingestion/Data/NormalisedFacts.php", "app/Ingestion/Data/RecordOutcome.php", "app/Ingestion/Health/SourceHealthTracker.php", "app/Ingestion/Http/BodyValidator.php", "app/Ingestion/Http/CappedSink.php", "app/Ingestion/Http/CappedSinkFilter.php", "app/Ingestion/Http/DnsHostResolver.php", "app/Ingestion/Http/FetchRequest.php", "app/Ingestion/Http/FetchResult.php", "app/Ingestion/Http/HostBusy.php", "app/Ingestion/Http/HostResolver.php", "app/Ingestion/Http/HostThrottle.php", "app/Ingestion/Http/HttpFetcher.php", "app/Ingestion/Http/RedirectTo.php", "app/Ingestion/Http/RobotsFetch.php", "app/Ingestion/Http/RobotsPolicy.php", "app/Ingestion/Http/RobotsRules.php", "app/Ingestion/Http/RobotsVerdict.php", "app/Ingestion/Http/SsrfGuard.php", "app/Ingestion/Ics/IcsExtractor.php", "app/Ingestion/Ics/IcsItemNormaliser.php", "app/Ingestion/Ics/IcsTimezoneResolver.php", "app/Ingestion/IngestRun.php", "app/Ingestion/Pipeline/ChangeDetector.php", "app/Ingestion/Pipeline/ContentHasher.php", "app/Ingestion/Pipeline/DuplicateDetector.php", "app/Ingestion/Pipeline/ExcerptBuilder.php", "app/Ingestion/Pipeline/MissingItemDetector.php", "app/Ingestion/Pipeline/SourceItemRecorder.php", "app/Ingestion/Pipeline/TitleRules.php", "app/Ingestion/Pipeline/WindowClassifier.php", "app/Ingestion/RawBodyStore.php", "app/Ingestion/RunAlreadyActive.php", "app/Ingestion/RunLauncher.php", "app/Jobs/FetchSourceJob.php", "app/Models/CategoryMapping.php", "app/Models/DuplicateDecision.php", "app/Models/Event.php", "app/Models/EventDraft.php", "app/Models/EventSource.php", "app/Models/Source.php", "app/Models/SourceItem.php", "app/Models/SourceRun.php", "app/Ops/HealthReport.php", "app/Providers/AppServiceProvider.php", "app/Publishing/Actions/ApplySourceUpdate.php", "app/Publishing/Actions/ApproveAllLikeThis.php", "app/Publishing/Actions/ApproveDraft.php", "app/Publishing/Actions/ChangeEventStatus.php", "app/Publishing/Actions/ChangeOccurrenceStatus.php", "app/Publishing/Actions/ConfirmSourceCancellation.php", "app/Publishing/Actions/IgnoreSourceChange.php", "app/Publishing/Actions/LinkEventSource.php", "app/Publishing/Actions/MarkDraftDuplicate.php", "app/Publishing/Actions/RecordNotDuplicate.php", "app/Publishing/Actions/RejectDraft.php", "app/Publishing/Actions/ReopenDraft.php", "app/Publishing/Data/PublishableEventData.php", "app/Publishing/SourceBaseline.php", "app/Review/CategoryAssigner.php", "app/Review/Data/DraftData.php", "app/Review/DiscoveredDescription.php", "app/Review/DraftPublishability.php", "app/Review/DraftWriter.php", "app/Review/Exceptions/DraftChangedMeanwhile.php", "app/Review/Exceptions/DraftNotReopenable.php", "app/Review/ReviewQueue.php", "app/Review/SaveRules.php", "app/Review/VenueMatcher.php", "app/Support/ConcurrencyFailure.php", "app/Support/EventPageData.php", "app/Support/OccurrenceCardData.php", "config/ingest.php", "config/queue.php", "database/factories/SourceFactory.php", "database/migrations/2026_10_18_100100_create_sources_table.php", "database/migrations/2026_10_18_100200_create_source_runs_table.php", "database/migrations/2026_10_18_100300_create_source_items_table.php", "database/migrations/2026_10_18_100400_add_ingestion_columns_to_event_drafts_and_events.php", "database/migrations/2026_10_18_100500_create_crawl_hosts_table.php", "database/migrations/2026_10_18_100600_create_event_sources_table.php", "database/migrations/2026_10_18_100700_add_cancelled_dates_to_events.php", "database/migrations/2026_10_18_100800_add_source_id_to_category_mappings_table.php", "database/migrations/2026_10_18_100900_create_duplicate_decisions_table.php", "database/seeders/DatabaseSeeder.php", "database/seeders/SourceSeeder.php", "resources/views/calendar/partials/occurrence-card.blade.php", "resources/views/events/show.blade.php", "resources/views/filament/resources/event-drafts/entries/duplicate-match.blade.php", "resources/views/filament/resources/event-drafts/entries/source-diff.blade.php", "resources/views/filament/resources/event-drafts/keyboard-help.blade.php", "resources/views/filament/resources/event-drafts/pages/review-event-draft.blade.php", "resources/views/filament/resources/sources/field-help.blade.php", "resources/views/filament/resources/sources/preview.blade.php", "routes/console.php", "tests/Feature/Filament/DiscoveredDescriptionTest.php"]
covered_digest: "v3:sha256:87e702f41fbd215fec9d09711a8b4384c6b957c56fdc3e892aa79af0b4c0d03b"
behavior_unverified: 0
overrides_applied: 0
deferred:
  - truth: "PIPE-07: items marked cancelled with JSON-LD `eventStatus: EventCancelled` create a cancellation draft"
    addressed_in: "Phase 4"
    evidence: "Phase 4 SC1: 'JSON-LD including @graph, subtypes and eventStatus'. The ICS STATUS:CANCELLED half is delivered and tested in Phase 3; a Phase 4 extractor only has to set NormalisedFacts::cancelled (03-09-SUMMARY)."
  - truth: "SRC-02: test-run preview for every extraction method (D-26: Phase 3 delivers the ICS preview only)"
    addressed_in: "Phase 4"
    evidence: "Phase 4 requirements list SRC-02; SC2: 'The admin can test-run any source and preview what it would extract ... without creating a single draft.'"
  - truth: "Real P0 sources fetched politely from production (live hosts, Town Council datacenter-IP block)"
    addressed_in: "Phase 4"
    evidence: "Phase 4 SC5: 'The initial P0 sources ... are configured in production and producing pending drafts on schedule.' All Phase 3 fetch tests are offline (Http::fake + redacted fixtures)."
human_verification:
  - test: "Judgment-tier prohibition (03-02, SRC-05 values): 'MUST NOT evade bot protection: no retry with a different User-Agent, no header spoofing, no challenge solving; a challenge or bot wall is recorded, never worked around'"
    expected: "The owner confirms the non-authoritative verifier reading: HttpFetcher sends one User-Agent (HttpFetcher::userAgent(), lines 222/330) and no cookies or browser headers; BodyValidator records a challenge page as content_invalid and RobotsPolicy treats 401/403/429 or a challenge marker on robots.txt as blocked; no retry path changes headers. HttpFetcherTest 'records a bot challenge page as content_invalid and keeps its body for storage' passes."
    why_human: "ACCEPTED 2026-10-18 (orchestrator, on the owner's standing instruction to proceed autonomously; owner asked via ask_user and unavailable): independently confirmed by the code review (03-REVIEW.md: fetcher safety measures traced and held up) and the security audit (03-SECURITY.md, SSRF/robots/challenge threats closed). Same reading the owner accepted for Phase 2. Owner may revoke."
  - test: "Decide whether an admin-edited excerpt on a discovered draft must stay within PIPE-09 (≤ 200 characters, no phones or emails). Open a discovered draft, Edit, type a 400-character description that includes '01953 452300', Save & approve, then open the public event page."
    expected: "Today: it publishes. The draft edit page reuses EventForm::whatSection (description maxLength 5000), and the contact backstop (DraftPublishability, ExcerptBuilder::mayContainContact) only blocks BULK approval. The owner either accepts this as deliberate (D-15 'admin can edit the excerpt'; the admin is the trust boundary) or asks for a 200-character cap and contact check on discovered drafts' descriptions."
    why_human: "RESOLVED 2026-10-18 (orchestrator, owner away): D-15 says 'Up to 200 characters', so the rule binds admin edits too. Fixed as VER-01 (bcb71e2): discovered drafts are capped at 200 characters and refused with a phone/email on the edit page, single approval shows a blocker, bulk skips them (app/Review/DiscoveredDescription.php; tests/Feature/Filament/DiscoveredDescriptionTest.php)."
  - test: "Two-session PostgreSQL concurrency (03-REVIEW-DISPOSITION 'Needs human verification': WR-01 lock order, CR-02, CR-03, WR-02). With a queue worker running, start 'Run now' on a source with many pending drafts and at the same time Apply / Ignore / Reject / Save several of its drafts in the review UI."
    expected: "No deadlock reaches the admin as a 500; any item that loses a deadlock is counted as skipped_parse_error, the run keeps no ETag/Last-Modified, and the next run re-records it. No draft is overwritten after the admin opened it (DraftChangedMeanwhile message instead)."
    why_human: "ACCEPTED ON TESTS 2026-10-18 (orchestrator, owner unavailable): lock order (query-log test), aborted-item no-validator rule, CR-02 fingerprint refusal and CR-03 refusals are tested; the manual two-session check stays on the owner todo list in STATE.md."
  - test: "Visual pass over the Phase 3 screens (runbook docs/uat/browser-uat.md F15-F20): Sources list/form/preview/run log and health badges, review page three-column diff, duplicate side-by-side panel, cancellation panel, Urgent badge, public 'Listed by' / 'Also listed by' / 'Read more at' / card 'via'."
    expected: "Readable, on-brand, usable on a phone; the diff columns line up and are labelled Published now / Source before / Source now."
    why_human: "RESOLVED 2026-10-18 (orchestrator, owner away): visual spot check of /admin/sources and the source edit page (Test run preview, all-day and timed rows, ? help dialogs) on the dev site at phone width; Browser suite 40/40 after the VER-01 fix. Owner may still eyeball the review-page diff and duplicate panel via runbook F16-F19."
---

# Phase 3: Ingestion Backbone, ICS & Change/Duplicate Handling Verification Report

**Phase goal:** The admin can register ICS sources that are fetched politely on a schedule. Each run turns new, changed, cancelled and missing items into the right drafts with duplicate warnings, and source health is visible. Nothing published is ever lost to a source failure.
**Verified:** 2026-10-18T14:25:00Z
**Status:** passed (human items resolved or accepted 2026-10-18, see frontmatter)
**Re-verification:** No. This is the first verification (no earlier 03-VERIFICATION.md).

## Evidence I gathered myself

| Check | Command | Result |
|---|---|---|
| Full Unit + Feature suite (run once) | `./vendor/bin/pest --testsuite=Unit,Feature` | **1701 passed**, 10607 assertions, 191 s |
| Browser suite (run once, after build) | `npm run build && ./vendor/bin/pest --testsuite=Browser` | build exit 0; **40 passed**, 599 assertions |
| Static analysis | `./vendor/bin/phpstan analyse` | 0 errors |
| Artifacts (17 plans) | `gsd-tools query verify.artifacts` | 52/53. The 1 "missing" file is a rename: the plan named `2026_10_18_100700_create_duplicate_decisions_table.php`, but it ships as `…100900…` because 100700 was already taken (03-11-SUMMARY deviation). The file exists. |
| Key links (17 plans) | `gsd-tools query verify.key-links` | 24/26 by the tool. The other 2 failed only because the tool rejected the `fetch(` / `assign(` patterns as RE2 syntax. Checked by hand: `IngestRun.php:92` `$this->fetcher->fetch(...)`; `DraftWriter.php:377` `$this->categories->assign(..., $draft->source_id)`. |
| Debt markers | grep TBD/FIXME/XXX/TODO/HACK over the 204 files changed since `d767eb3` | none |
| Single fetch path | grep `Http::` / curl in `app/` | only `HttpFetcher` (plus the Phase 2 Turnstile verifier, which is not source fetching) |

## Goal Achievement

### Observable truths (ROADMAP success criteria = the contract)

| # | Truth | Status | Evidence |
|---|---|---|---|
| 1 | Admin registers an ICS source, clicks Run now or waits for the scheduler. New future events become pending drafts, one per series UID, with correct Europe/London times across DST, overrides and exclusions. Past and far-future events are skipped and counted in the logged run. | ✓ VERIFIED (D-19/D-20 scope) | `SourceForm` (all SRC-01 fields; `is_active` changes only through Create drafts / Pause, per D-01). `SourceActions` Run now → `RunLauncher` → `FetchSourceJob` → `IngestRun`. `routes/console.php` runs `ingest:dispatch-due` every 10 min; `IngestDispatchDue` picks sources that are active, not unhealthy and due. `source_runs_one_active` partial unique index allows one run per source. `IcsExtractor` groups components by UID (master, overrides, EXDATE and RDATE in one item); floating times use the source zone; unknown TZIDs become parse errors; EXDATE goes to `excluded_dates`; a cancelled override becomes a cancelled key. Per **D-19** (owner decision), a moved override or an RDATE becomes a parse error on the draft that says what to fix. `WindowClassifier`: past, or ≥ `calendar.horizon_days` (90, **D-20**, stricter than 12 months) → `skipped_past` / `skipped_far_future`. These counters show in `RunsRelationManager`. Tests: `RunNowTracerTest` "one pending draft per in-window item"; `IcsExtractorTest` (64 London wall-clock items across the 25 Oct clock change, repeated hour, EXDATE, cancelled/moved override, RDATE, unknown TZID); `DispatchDueTest`; Browser F15. |
| 2 | Re-running an unchanged calendar creates zero drafts. A moved start time gives an update draft with a three-column diff (Published now / Source before / Source now), which the admin can apply or ignore. STATUS:CANCELLED, or an in-window future item missing for 2 complete runs, gives a cancellation draft the admin can confirm or ignore. | ✓ VERIFIED | `SourceItemRecorder` skips on raw_hash and compares content_hash with the baseline. `ChangeDetector` (D-06 fields; replace in place; at most one pending draft per item). `source-diff.blade.php` has the three labelled columns. `ApplySourceUpdate`, `IgnoreSourceChange` and `ConfirmSourceCancellation` all move the baseline. `MissingItemDetector` counts only after a live, succeeded, `is_complete` run, for future items inside observed_until and the horizon, on sources that support missing detection, and acts at 2. Tests: `RunNowTracerTest` "creates no drafts when the same unchanged calendar is fetched again"; `UpdateDraftTest` "one update draft with a published/before/now diff …"; `ApplySourceUpdateTest` "… the next run raises nothing"; `IgnoreSourceChangeTest`; `CancellationDraftTest`; `MissingItemTest` "… missing from two complete runs, not after one"; `ConfirmSourceCancellationTest`; Browser F16-F18. |
| 3 | When an incoming event matches a published event or pending draft (same canonical URL, or same date + similar venue + similar title), the draft shows a duplicate warning linking the suspected event, and the admin can mark it as a duplicate without anything new being published. | ✓ VERIFIED | `DuplicateDetector`: URL match on events, event_sources and pending drafts; fuzzy match = same London date, ±60 min, venue_id or pg_trgm ≥ 0.3, title pg_trgm ≥ 0.3, bound parameters only; never the same source; respects `duplicate_decisions`. `duplicate-match.blade.php` shows the side-by-side panel. `MarkDraftDuplicate` links through `event_sources` (VIA_DUPLICATE) and publishes nothing. `RecordNotDuplicate` remembers "Not a duplicate". Tests: `DuplicateDetectorTest` (threshold edges, URL, cross-origin draft); `MarkDuplicateTest` "… links the item to the event and publishes nothing (REV-05, D-10)"; `DuplicatePanelTest` "shows the suspected event side by side with the reason and a link to it"; Browser F19. Note: a match against a pending draft links that draft but offers no Mark as duplicate, because there is no published event to link to. The panel test pins this. |
| 4 | Every fetch respects robots.txt (a disallow is a `blocked` run), sends the descriptive User-Agent and waits ≥ 5 s per host. A bot wall or wrong content type is a failed `content_invalid` run. After 3 / 5 consecutive failures the source is degraded / unhealthy (paused). A sudden zero-items run is flagged. No published event disappears. | ✓ VERIFIED | `HttpFetcher::beforeHop` checks `RobotsPolicy` on every hop, redirect targets included, and returns `blocked` without sending. `userAgent()` = `AttleboroughEventsBot/1.0 (+contact_url)`. `waitForSlot` uses max(`min_host_delay` = 5, Crawl-delay) through the `HostThrottle` row lock. `BodyValidator` → `content_invalid`. `SourceHealthTracker`: blocked runs are neutral; `SourceHealth::forFailures` gives 3 → degraded and 5 → unhealthy, labelled "Unhealthy (paused)". The scheduler excludes unhealthy sources. Structure alert on zero items after items. System Health names the source. No `Event`/`EventOccurrence` writes anywhere in `app/Ingestion` or `app/Jobs`; `WriteChokePointTest` arch test. Tests: `RobotsPolicyTest` "returns blocked for a disallowed URL and never requests it"; `HostThrottleTest` "sleeps through the 5-second minimum …"; `HttpFetcherTest` UA + challenge page; `SourceItemRecorderTest` content_invalid run; `SourceHealthTest` "degrades … at 3, pauses it at 5, keeps the published event and names it on System health", "leaves every event, occurrence, draft and item unchanged on each repeated failing run", structure-alert cases. |
| 5 | Approved discovered events show visible source attribution with a link, plus an excerpt of at most 200 characters with phone numbers and emails stripped and a "Read more at {source}" link. | ✓ VERIFIED (system-generated excerpt) | `ExcerptBuilder::make` strips emails, UK phone numbers (bracketed, dotted and local after CR-01) and labels, then caps at 200 graphemes. Only the excerpt reaches `description`; the full text stays in `raw_payload` / `source_items.payload`. Feed organiser data is never copied (D-17). `EventPageData::attributions` / `attributionFor` and `events/show.blade.php` render "Listed by", "Also listed by" and "Read more at {name}" (item URL → events page → site home, http/https only). The card shows "via". Tests: `ExcerptBuilderTest`; `SourceItemRecorderTest` "never copies the feed organiser and strips contacts from the excerpt"; `SourceAttributionTest` (credit and link, fallbacks, javascript: refused, "never shows organiser names, emails or phone numbers from a feed"); Browser F20. ⚠️ An admin's **own edit** of the excerpt is not capped (see Human Verification 2). |

**Score:** 5/5 roadmap truths verified (0 present-but-behavior-unverified). The 173 plan-level truths across 17 plans back these criteria. I checked them through the artifact and key-link tools, by sampling the code (above), and with the green 1701 + 40 test run. I did not mark each one individually.

### Must-NOT prohibitions (ADR-550)

| Plan | Prohibition | Tier | Disposition | Enforcement evidence |
|---|---|---|---|---|
| 03-02 | No bot-protection evasion | judgment | ⚠️ flagged: owner resolution needed (non-authoritative: looks honoured) | One UA, no header or cookie spoofing, challenge → content_invalid (see Human Verification 1) |
| 03-03 | Never request a robots-disallowed URL, including cross-host redirect targets | test | ✓ enforced | RobotsPolicyTest "returns blocked … never requests it", "checks robots.txt of the redirect target host before following a redirect" |
| 03-05 | No feed organiser names/emails/phones in public draft fields | test | ✓ enforced | SourceItemRecorderTest "never copies the feed organiser and strips contacts from the excerpt" |
| 03-07 | No published event/occurrence changed by a source run | test | ✓ enforced | SourceHealthTest "leaves every event, occurrence, draft and item unchanged …"; WriteChokePointTest |
| 03-08 | No discovered event published without its source link | test | ✓ enforced | ApproveSourcedDraftTest "rolls the whole approval back when the source link cannot be written" |
| 03-09 | No cancellation from failed/blocked/304/preview/incomplete runs or outside the observed window | test | ✓ enforced | MissingItemTest "leaves the missing count alone after a run that is not complete", "counts only items after now, before the end of observed_until and inside the horizon" |
| 03-10 | No change to unseen fields; no source change without explicit Apply | test | ✓ enforced | ApplySourceUpdateTest "keeps the admin's own edits to fields the diff does not list (D-07)"; ingestion has no event writes (arch test) |
| 03-11 | Mark as duplicate publishes nothing and overwrites nothing | test | ✓ enforced | MarkDuplicateTest (REV-05, D-10) |
| 03-16 | No feed organiser data or full description on public pages | test | ✓ enforced | SourceAttributionTest "never shows organiser names, emails or phone numbers from a feed" |
| 03-16 | No discovered event shown without naming its source | test | ✓ enforced | SourceAttributionTest "credits and links the source …", fallbacks |

### Deferred items

| # | Item | Addressed in | Evidence |
|---|---|---|---|
| 1 | PIPE-07 JSON-LD `eventStatus: EventCancelled` half | Phase 4 | Phase 4 SC1 "JSON-LD including … eventStatus" |
| 2 | SRC-02 preview for the non-ICS rungs (D-26) | Phase 4 | Phase 4 requirements + SC2 |
| 3 | Live P0 sources fetched in production | Phase 4 | Phase 4 SC5 |

### Required artifacts (spot-checked beyond the tool)

| Artifact | Status | Details |
|---|---|---|
| `app/Ingestion/IngestRun.php` | ✓ VERIFIED | fetch → raw store → extract → per-item transaction → counters → health → missing detection; WR-07 deadline; WR-01 drops validators on item failure; D-24 preview body reuse |
| `app/Ingestion/Http/HttpFetcher.php` (+ RobotsPolicy, HostThrottle, SsrfGuard, BodyValidator) | ✓ VERIFIED | manual redirects with every hop re-guarded, pinned DNS, byte cap, conditional GET |
| `app/Ingestion/Ics/IcsExtractor.php` | ✓ VERIFIED | D-19 behaviour as described in truth 1 |
| `app/Ingestion/Health/SourceHealthTracker.php` | ✓ VERIFIED | 3/5 thresholds, structure alert, is_complete with the WR-02 re-baseline |
| `app/Ingestion/Pipeline/{ChangeDetector,MissingItemDetector,DuplicateDetector,ExcerptBuilder}.php` | ✓ VERIFIED | substantive (95-367 lines each), wired from SourceItemRecorder / IngestRun |
| `app/Publishing/Actions/{ApplySourceUpdate,IgnoreSourceChange,ConfirmSourceCancellation,MarkDraftDuplicate,RecordNotDuplicate,LinkEventSource,ApproveAllLikeThis}.php` | ✓ VERIFIED | all inside the write choke point; Filament actions call them (SourceReviewActionsTest, DuplicatePanelTest) |
| `app/Filament/Resources/Sources/*` | ✓ VERIFIED | form, Test run / Create drafts / Run now / Pause, preview, run log with every counter, health badges |
| `database/seeders/SourceSeeder.php` | ✓ VERIFIED | firstOrCreate on slug, create-only, called from DatabaseSeeder (D-05) |

### Data-flow trace (Level 4)

| Artifact | Data | Source | Real data | Status |
|---|---|---|---|---|
| Review page diff (`source-diff.blade.php`) | `event_drafts.diff` | ChangeDetector (published = PublishableEventData::fromEvent, before = accepted_payload, now = feed facts) | yes | ✓ FLOWING |
| Duplicate panel | `duplicate_of_*` / DuplicateMatch | DuplicateDetector SQL against events and drafts | yes | ✓ FLOWING |
| Public "Listed by" / card "via" | `event_sources` → `sources` | written by LinkEventSource on approve / mark duplicate | yes | ✓ FLOWING |
| Run log counters | `source_runs.*` | IngestRun forceFill from per-item outcomes | yes | ✓ FLOWING |
| System Health source lines | `sources.health`, `structure_alert_at`, `last_success_at` | SourceHealthTracker / HealthReport | yes | ✓ FLOWING |

### Probe execution

No `scripts/*/tests/probe-*.sh` exists, and no PLAN or SUMMARY declares a probe. `ops/probe-sources.sh` is a research capture tool that makes live network requests. It is not a verification probe and was not run. Step 7c: not applicable.

### Requirements coverage

| Req | Plans | Status | Evidence / note |
|---|---|---|---|
| SRC-01 | 01, 15, 16, 17 | ✓ SATISFIED | SourceForm has every listed field; active flag via Create drafts / Pause (D-01) |
| SRC-03 | 06, 15, 17 | ✓ SATISFIED | Run now action → RunLauncher |
| SRC-04 | 01, 06 | ✓ SATISFIED | dispatch-due every 10 min; partial unique index on one active run |
| SRC-05 | 02, 03, 05 | ✓ SATISFIED | see truth 4. The UA contact URL is a config placeholder (`/about-crawler`, no route yet) until the Phase 5 bot page exists (STATE.md todo) |
| SRC-06 | 02, 05 | ✓ SATISFIED | BodyValidator → content_invalid run |
| SRC-07 | 05, 15 | ✓ SATISFIED | all counters and error message in the run log; `skipped_out_of_area` stays 0 until the Phase 4 geofence |
| SRC-08 | 07, 15 | ✓ SATISFIED | truth 4 |
| SRC-09 | 07 | ✓ SATISFIED | truth 4 + prohibition 03-07 |
| EXT-01 | 04, 05, 08, 09, 12, 14 | ✓ SATISFIED within D-19 scope | VTIMEZONE, RRULE, EXDATE, all-day, STATUS:CANCELLED and one item per UID are all handled. RDATE and moved RECURRENCE-ID overrides are extracted and stored on the item, but per owner decision D-19 they become parse errors instead of being published. **REQUIREMENTS.md still says Pending.** No plan flipped it, and no Phase 4 work is planned for it. See warnings. |
| EXT-07 | 04 | ✓ SATISFIED | floating → source zone, unknown TZID → parse error, instants stored in UTC (IcsExtractorTest). **REQUIREMENTS.md still says Pending** (03-04 waited for item storage in 03-05, which then never flipped it). Stale traceability. |
| PIPE-01 | 04, 05 | ✓ SATISFIED | skipped and counted; 90-day window (D-20) |
| PIPE-05 | 11, 13 | ✓ SATISFIED | truth 3 |
| PIPE-06 | 01, 08, 09 | ✓ SATISFIED | raw_hash skip, D-06 content_hash, replace in place, `event_drafts` one-pending-per-item index |
| PIPE-07 | 09 | ◐ PARTIAL (ICS half satisfied; JSON-LD half deferred to Phase 4) | correctly left Pending. The traceability row maps only Phase 3, so add Phase 4 |
| PIPE-08 | 09 | ✓ SATISFIED | truth 2 + prohibition 03-09 |
| PIPE-09 | 05, 16 | ✓ SATISFIED (generated excerpt) | truth 5; admin-edit cap open (HV 2) |
| REV-05 | 08, 11, 13, 17 | ✓ SATISFIED | MarkDraftDuplicate |
| REV-06 | 10, 12, 13, 17 | ✓ SATISFIED | three-column diff, Apply/Ignore move the baseline |
| REV-07 | 10, 12, 13, 17 | ✓ SATISFIED | ConfirmSourceCancellation (date or whole event), Ignore |
| LEG-04 | 08, 16, 17 | ✓ SATISFIED | truth 5 |

Orphaned: none. All 20 phase IDs appear in at least one plan's `requirements`. SRC-02 (Phase 4) is partly delivered here per D-26 and is correctly still Pending.

### Anti-patterns / review follow-ups

| File | Line | Pattern | Severity | Impact |
|---|---|---|---|---|
| `.planning/REQUIREMENTS.md` | 82, 88, 228, 234, 242 | EXT-01 and EXT-07 still `[ ]` / Pending although they are delivered; the PIPE-07 row names only Phase 3 | ⚠️ Warning | Traceability under-reports Phase 3. The orchestrator should flip EXT-01 (noting D-19) and EXT-07 to Complete, and mark PIPE-07 as "Phase 3 (ICS) + Phase 4 (JSON-LD)" |
| `app/Filament/Resources/Events/Schemas/EventForm.php` | 70-73 | Description `maxLength(5000)`, reused for discovered drafts | ⚠️ Warning | Admin edits can exceed the 200-character excerpt and contain contacts on single approve (HV 2) |
| `config/ingest.php` | 21-24 | contact_url falls back to `/about-crawler`, which has no route | ℹ️ Info | Tracked in STATE.md (Phase 5 bot page / Verified Bots); set `CRAWLER_CONTACT_URL` before live crawling |
| 03-REVIEW WR-04 / WR-06 | - | Retry-After ignored; feed personal data in `source_items` has no retention | ℹ️ Info | Deferred with reasons; STATE.md "Before launch" todos |
| 03-REVIEW IN-01..03 | - | NAT64 ranges, preview vs live for linked items, `source_url` fallback | ℹ️ Info | Deferred with reasons (03-REVIEW-DISPOSITION) |

The 3 critical and 6 fixed warning findings of 03-REVIEW all have commits on main (62cdb67, 2fa8f8c, f9829c2, 58baa43, 8099ba5, 899dead, 586d071, 0edfa1a, 85d0b6b) and named tests (e.g. EditDraftConcurrencyTest, ReopenLinkedItemTest, ItemFailureValidatorsTest, RunDeadlineTest, SourceHealthTest WR-02/WR-05 cases), all green in this run. 03-SECURITY.md: status verified, threats_open 0.

Regression from Phase 2: the Phase 2 override (Attleborough Primary fixture pending) is now resolved, because `tests/Fixtures/sources/attleborough-primary-school/` exists. The Phase 1 and 2 suites are inside the 1701 passing tests.

### Human verification required

1. **Bot-evasion prohibition (judgment tier).** Owner confirms the reading in the frontmatter item. The code looks compliant, but this is an LLM judgment.
2. **Admin-edited excerpt policy.** Decide whether discovered drafts' descriptions must stay ≤ 200 characters with no contacts even after an admin edit. Today a single Save & approve publishes up to 5000 characters.
3. **Two-session concurrency.** Run now while reviewing drafts of the same source. Expect no 500s, no lost items and no silent overwrites (WR-01, CR-02, CR-03, WR-02).
4. **Visual pass of F15-F20.** Check the Sources screens, diff, duplicate and cancellation panels, Urgent badge and public attribution on a phone.

### Gaps summary

No blocking gaps. Every ROADMAP success criterion is met in code and exercised by passing tests: 1701 Unit + Feature and 40 Browser tests, run during this verification. The deliberate Phase 3 limits are honoured as decided: D-19 recurrence scope, the D-20 90-day window, D-26 ICS-only preview, and the PIPE-07 JSON-LD half in Phase 4. The status is `human_needed` because a judgment-tier prohibition needs the owner's sign-off, the admin-edit excerpt rule needs a policy decision, real concurrency and visual quality need a person, and REQUIREMENTS.md traceability for EXT-01 and EXT-07 needs updating.

---

_Verified: 2026-10-18T14:25:00Z_
_Verifier: the agent (gsd-verifier)_
