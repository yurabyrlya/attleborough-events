---
phase: 02-draft-model-review-queue-submissions
verified: 2026-10-17T22:25:00Z
status: passed
score: "16/16 must-haves verified (1 by owner override: Attleborough Primary fixture pending)"
covered_files: [".env.example",".planning/REQUIREMENTS.md",".planning/ROADMAP.md",".planning/phases/02-draft-model-review-queue-submissions/02-01-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-01-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-02-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-02-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-03-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-03-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-04-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-04-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-05-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-05-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-06-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-06-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-07-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-07-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-08-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-08-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-09-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-09-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-10-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-10-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-11-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-11-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-12-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-12-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-13-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-13-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-14-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-14-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-15-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-15-SUMMARY.md",".planning/phases/02-draft-model-review-queue-submissions/02-16-PLAN.md",".planning/phases/02-draft-model-review-queue-submissions/02-16-SUMMARY.md","AGENTS.md","app/Enums/DraftKind.php","app/Enums/ExtractionMethod.php","app/Enums/QualityBand.php","app/Enums/RejectReason.php","app/Enums/ReviewStatus.php","app/Filament/Pages/Rules.php","app/Filament/Resources/EventDrafts/Actions/ReviewActions.php","app/Filament/Resources/EventDrafts/EventDraftResource.php","app/Filament/Resources/EventDrafts/Pages/EditEventDraft.php","app/Filament/Resources/EventDrafts/Pages/ListEventDrafts.php","app/Filament/Resources/EventDrafts/Pages/ReviewEventDraft.php","app/Filament/Resources/EventDrafts/Schemas/EventDraftForm.php","app/Filament/Resources/EventDrafts/Schemas/EventDraftInfolist.php","app/Filament/Resources/EventDrafts/Tables/EventDraftsTable.php","app/Filament/Resources/Events/EventResource.php","app/Filament/Resources/Events/Pages/CreateEvent.php","app/Filament/Resources/Events/Schemas/EventForm.php","app/Http/Controllers/SubmissionController.php","app/Http/Requests/StoreSubmissionRequest.php","app/Models/Category.php","app/Models/CategoryKeywordRule.php","app/Models/CategoryMapping.php","app/Models/Event.php","app/Models/EventDraft.php","app/Models/GeofenceTerm.php","app/Ops/HealthReport.php","app/Providers/AppServiceProvider.php","app/Publishing/Actions/ApproveDraft.php","app/Publishing/Actions/RejectDraft.php","app/Publishing/Actions/ReopenDraft.php","app/Publishing/Data/PublishableEventData.php","app/Review/CategoryAssigner.php","app/Review/Data/CategoryDecision.php","app/Review/Data/DraftData.php","app/Review/Data/QualityResult.php","app/Review/Data/VenueSuggestion.php","app/Review/DraftPublishability.php","app/Review/DraftWriter.php","app/Review/Exceptions/DraftNotPublishable.php","app/Review/Exceptions/InvalidReviewTransition.php","app/Review/Exceptions/RulesNotSaved.php","app/Review/QualityScorer.php","app/Review/ReferenceCode.php","app/Review/ReviewQueue.php","app/Review/SaveRules.php","app/Review/VenueMatcher.php","app/Submissions/Honeypot.php","app/Submissions/TurnstileVerifier.php","app/Support/EventPageData.php","app/Support/LondonFormatter.php","app/Support/OccurrenceCardData.php","app/Support/PrivacyDatabaseSessionHandler.php","config/review.php","config/services.php","config/session.php","database/factories/EventDraftFactory.php","database/migrations/2026_10_17_100200_create_event_drafts_table.php","database/migrations/2026_10_17_100300_add_contact_public_and_organiser_website_to_events.php","database/migrations/2026_10_17_100400_create_category_keyword_rules_table.php","database/migrations/2026_10_17_100410_create_category_mappings_table.php","database/migrations/2026_10_17_100420_create_geofence_terms_table.php","database/migrations/2026_10_17_215438_clear_sessions_stored_with_ip_addresses.php","database/seeders/DatabaseSeeder.php","database/seeders/RulesSeeder.php","docs/research/event-sources.md","docs/research/source-inventory.md","docs/research/town-council-feed-request.md","docs/uat/browser-uat.md","ops/probe-sources.sh","resources/css/app.css","resources/js/submit.js","resources/views/components/submit/honeypot.blade.php","resources/views/errors/429.blade.php","resources/views/events/show.blade.php","resources/views/filament/pages/rules.blade.php","resources/views/filament/resources/event-drafts/entries/safe-link.blade.php","resources/views/filament/resources/event-drafts/keyboard-help.blade.php","resources/views/filament/resources/event-drafts/pages/review-event-draft.blade.php","resources/views/filament/resources/event-drafts/raw-content.blade.php","resources/views/layouts/app.blade.php","resources/views/privacy.blade.php","resources/views/submit/create.blade.php","resources/views/submit/thanks.blade.php","routes/console.php","routes/web.php","tests/Browser/AdminCreateEventTest.php","tests/Browser/ApproveAndNextKeyboardTest.php","tests/Browser/ReviewQueueTest.php","tests/Browser/RulesTest.php","tests/Browser/SubmitEventTest.php","tests/Browser/UatFixtures.php","tests/Feature/Filament/ApproveAndNextTest.php","tests/Feature/Filament/BulkReviewTest.php","tests/Feature/Filament/CreateEventTest.php","tests/Feature/Filament/DraftDetailTest.php","tests/Feature/Filament/EditAndApproveTracerTest.php","tests/Feature/Filament/EditEventDraftTest.php","tests/Feature/Filament/GlobalSearchTest.php","tests/Feature/Filament/ReviewQueueTableTest.php","tests/Feature/Filament/ReviewQueueTracerTest.php","tests/Feature/Filament/RulesCategoriesTest.php","tests/Feature/Filament/RulesMappingsGeofenceTest.php","tests/Feature/Ops/HealthReportTest.php","tests/Feature/Public/ContactVisibilityTest.php","tests/Feature/Public/NoThirdPartyAssetsTest.php","tests/Feature/Public/PrivacyPageTest.php","tests/Feature/Review/ApproveDraftTest.php","tests/Feature/Review/CategoryAssignerTest.php","tests/Feature/Review/DraftScoringTest.php","tests/Feature/Review/DraftWriterTest.php","tests/Feature/Review/KeywordRuleTracerTest.php","tests/Feature/Review/ManualEntryThroughDraftTracerTest.php","tests/Feature/Review/PruneRejectedDraftsTest.php","tests/Feature/Review/RejectReopenDraftTest.php","tests/Feature/Review/ReviewQueueOrderTest.php","tests/Feature/Review/RulesSeederTest.php","tests/Feature/Review/VenueMatcherTest.php","tests/Feature/Submissions/RateLimitTest.php","tests/Feature/Submissions/SessionPrivacyTest.php","tests/Feature/Submissions/SpamDefenceTest.php","tests/Feature/Submissions/SubmitEventTracerTest.php","tests/Feature/Submissions/SubmitValidationTest.php","tests/Feature/Submissions/TurnstileVerifierTest.php","tests/Fixtures/sources/.gitattributes","tests/Fixtures/sources/README.md","tests/Fixtures/sources/attleborough-academy/2026-10-17-ical.ics","tests/Fixtures/sources/attleborough-academy/manifest.json","tests/Fixtures/sources/attleborough-baptist-church/2026-10-17-feed.xml","tests/Fixtures/sources/attleborough-baptist-church/2026-10-17-ical.ics","tests/Fixtures/sources/attleborough-baptist-church/manifest.json","tests/Fixtures/sources/attleborough-heritage-group/2026-10-17-events-page.html","tests/Fixtures/sources/attleborough-heritage-group/manifest.json","tests/Fixtures/sources/attleborough-town-council/2026-10-17-ical.ics","tests/Fixtures/sources/attleborough-town-council/2026-10-17-tec-p1.json","tests/Fixtures/sources/attleborough-town-council/2026-10-17-tec-p2.json","tests/Fixtures/sources/attleborough-town-council/manifest.json","tests/Pest.php","tests/Unit/Architecture/WriteChokePointTest.php","tests/Unit/Enums/ReviewStatusTransitionsTest.php","tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php","tests/Unit/Publishing/PublishableEventDataTest.php","tests/Unit/Review/QualityScorerTest.php"]
covered_digest: "v3:sha256:4e15c5ea5f929de58dfa858e569d5af67d72dc8b9fcd8071239fc29a6e6d87dc"
behavior_unverified: 0
overrides_applied: 1
overrides:
  - must_have: "Fixture samples are saved for every P0/P1 source"
    reason: "Attleborough Primary School (P1, provisional) sets robots Crawl-delay: 12000; ops/probe-sources.sh deferred the capture at 23:19 BST (next allowed after 02:39 BST 2026-10-18). Capture before Phase 3 planning starts (STATE.md todo); 4/5 P0/P1 sources have fixtures. Guard test hardened to read List 1 headings and require an explicit fixtures-pending mark plus Pending-note entry for any uncaptured P0/P1 source."
    accepted_by: "yurabyrlya (project owner)"
    accepted_at: "2026-10-17T22:21:56Z"
gaps:
  - truth: "Fixture samples are saved for every P0/P1 source (ROADMAP SC5, RES-03; 02-02 truth 'Every P0 and P1 source in List 1 has a fixture directory ... with at least one fixture file ... and a manifest.json entry')"
    status: overridden
    reason: "List 1 of docs/research/event-sources.md has 5 P0/P1 sources. 4 have fixtures (Baptist Church, Town Council, Academy, Heritage Group). Attleborough Primary School (P1, '### Attleborough Primary School (P1, fixtures pending)') has no tests/Fixtures/sources/attleborough-primary-school/ directory, no manifest and no capture. Its calendar page was never fetched, so its platform, rung and extraction method are 'unknown (pending)'. The automated guard passes only because the doc keeps this source out of the '## Fixture index' table ('Pending (not in the index until captured)'), and the test reads that table rather than List 1. RES-03 is ticked [x] in REQUIREMENTS.md and claimed in 02-02-SUMMARY requirements-completed, but the summary itself says 'all P0/P1 List 1 sources except Attleborough Primary'."
    artifacts:
      - path: "tests/Fixtures/sources/attleborough-primary-school/"
        issue: "Missing: no fixture file and no manifest.json for a List 1 P1 source"
      - path: "docs/research/event-sources.md"
        issue: "Primary School entry: Platform/Ladder rung/Extraction method/Example event URL/Fixture files all 'unknown (pending)'; source left out of the Fixture index, so the guard test doesn't require it"
      - path: "tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php"
        issue: "'has fixtures for every P0 and P1 source' reads the self-declared Fixture index, not the List 1 priority headings, so a P1 source omitted from the index escapes enforcement"
      - path: ".planning/REQUIREMENTS.md"
        issue: "RES-03 marked [x] Complete while one P1 source has no fixture"
    missing:
      - "Run `ops/probe-sources.sh attleborough-primary-school` (the script's Crawl-delay deferral ended 23:05 BST on 2026-10-17), read and redact the capture, write manifest.json, add the slug to the Fixture index, and fill in the Primary School entry's platform / rung / extraction method / example URL from what the page actually shows"
      - "If the calendar page turns out to have no deterministic method, re-rank it honestly (P2/P3 or List 2) with the evidence. Don't re-rank only to make the check pass"
      - "Harden the guard: derive the P0/P1 slugs from the List 1 `### Name (P0|P1...)` headings (or require every List 1 P0/P1 entry to appear in the Fixture index), so an un-indexed P1 source fails the test"
      - "Alternatively, the owner accepts the deviation explicitly with an `overrides:` entry (see the report body)"
human_verification:
  - test: "Judgment-tier prohibition (02-02, RES-02 legal): 'MUST NOT bypass a bot wall, CAPTCHA, login, robots.txt disallow or ToS prohibition while probing or capturing fixtures'"
    expected: "The owner confirms that no probe used a spoofed UA, a challenge solver or copied cookies. Evidence seen: the doc records the honest UA on every request; the 4 Cloudflare sites stay on List 2; ops/probe-sources.sh refuses to save challenge pages. One self-reported slip: a robots.txt fetch to attleboroughprimary.org fell inside its Crawl-delay: 12000 (02-02-SUMMARY), and the script was fixed with a 24 h robots cache"
    why_human: "Non-authoritative LLM judgment over research narrative. The probes ran outside this repo's test harness, so nothing deterministic can prove them. OWNER-ACCEPTED 2026-10-17T22:21:56Z (yurabyrlya): honest probing confirmed"
  - test: "Judgment-tier prohibition (02-02, RES-01 values): 'MUST NOT classify a source as Crawlable on assumption: a source enters List 1 only with recorded live evidence (robots rule lines, ToS finding and a working ladder method)'"
    expected: "The owner decides whether List 1 entries with no working ladder method are acceptable: Attleborough Primary School (P1, method unknown, calendar never fetched) and the three P3 entries (Players, Summer Carnival, Banham Zoo: 'no deterministic method exists, so they come in by manual entry'). D-23 covers their legal classification, not the 'working ladder method' clause. LLM judgment: the Primary School entry doesn't meet the clause as written. The P3 entries are a presentation choice the owner made in D-23"
    why_human: "Judgment-tier prohibition. Interactive verify needs explicit human resolution per item. OWNER-ACCEPTED 2026-10-17T22:22:04Z (yurabyrlya): manual-entry P3 sources stay in List 1 under D-23; Attleborough Primary stays P1 with fixtures pending under the override"
---

# Phase 2: Draft Model, Review Queue & Submissions Verification Report

**Phase Goal:** Organisers can submit events, and the admin can triage every incoming draft quickly through one review queue that is the only path to publication. By the end, the local source inventory is verified and fixtures are saved.
**Verified:** 2026-10-17T22:25:00Z
**Status:** gaps_found
**Re-verification:** No (initial verification)

The submissions, review queue and single publication path are fully delivered and well tested. The phase falls short on one source-research deliverable: one P1 source (Attleborough Primary School) has no fixture. The automated guard doesn't catch this because the source was left out of the table the guard reads.

## Goal Achievement

### Observable Truths (ROADMAP success criteria, split into checkable truths; PLAN must-haves merged in)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | SC1: An organiser completes the public form with every SUB-01 field, including the "repeats" field and a required consent checkbox linking to /privacy | ✓ VERIFIED | `resources/views/submit/create.blade.php` has `repeats_text` (l.154-157), `consent` required with a `route('privacy')` link (l.263-266) and `data-venue-other` no-JS venue fields. Routes `submit.create/store/thanks` and `/privacy` are in `routes/web.php`. Tests: SubmitValidationTest, SubmitEventTracerTest. Browser: SubmitEventTest (F11) |
| 2 | SC1: A valid submission shows a "will be reviewed" confirmation, writes one pending draft and stays off the public site | ✓ VERIFIED | `SubmissionController::store` → `DraftWriter::create(DraftData::fromSubmission(...))` → PRG to /submit/thanks. Never calls PublishEvent or ApproveDraft. SubmitEventTracerTest l.78-81 asserts `Event::count()==0`, `EventOccurrence::count()==0` and `/` doesn't show the title |
| 3 | SC1: Missing date or venue shows validation errors and creates no draft | ✓ VERIFIED | StoreSubmissionRequest (Europe/London bounds). SubmitValidationTest covers the error summary, aria-invalid, kept values, the 'other' venue with no name and a non-existent venue id. Browser F11 checks the error summary with typed values kept |
| 4 | SC1: Bots are stopped by the honeypot, per-IP rate limiting and Turnstile | ✓ VERIFIED | `app/Submissions/Honeypot.php` (trap field plus encrypted single-use timing token). `TurnstileVerifier` fails closed and checks success, action and hostname. `throttle:submissions` is on POST /submit, with separate hour and day keys in AppServiceProvider. Tests: SpamDefenceTest, TurnstileVerifierTest (fail-closed, 2048/2049 boundary, action case), RateLimitTest (5th passes, 6th gets 429, other IP unaffected, daily 10, no IP stored) |
| 5 | SC2: The review page shows each draft's fields, recurrence summary, source, quality band with reasons, category (or flagged Other) and parse errors | ✓ VERIFIED | `EventDraftInfolist.php`: 'Nothing to fix' (l.80), 'Check category' (l.107), repeats callout (l.127), extraction_method, source_url safe link, Raw content, parse_errors, 'No duplicate found' (l.227), 'One-off' (l.291), 'Suggested: …' (l.333). DraftDetailTest. Browser F12 |
| 6 | SC2: Filters for kind, source, band, duplicate flag and date | ✓ VERIFIED | `EventDraftsTable.php` l.93-130: SelectFilter kind / origin (labelled Source, per D-20) / quality_band, TernaryFilter duplicate, and an inclusive event_date range. ReviewQueueTableTest |
| 7 | SC2: Search events and drafts | ✓ VERIFIED | `EventDraftResource::getGloballySearchableAttributes`, with canAccess on both resources. GlobalSearchTest (case-insensitive, lower-case reference) |
| 8 | SC2: Categories, mappings and keyword rules on one Rules screen | ✓ VERIFIED | `app/Filament/Pages/Rules.php`: Categories, Keyword rules, Category mappings and Geofence tabs, saved in one transaction by `SaveRules` (AdminGate). CategoryAssigner chain is preferred → 'Mapped from' → 'Keyword rule:' → 'No match → Other'. RulesCategoriesTest, RulesMappingsGeofenceTest, KeywordRuleTracerTest, CategoryAssignerTest (determinism). Browser F14 |
| 9 | SC3: Approve, edit-and-approve, and reject with an optional reason | ✓ VERIFIED | `ReviewActions` → ApproveDraft / RejectDraft / ReopenDraft. `EditEventDraft` Save & approve does DraftWriter::update then ApproveDraft in one transaction. Tests: ApproveDraftTest, RejectReopenDraftTest (null reason and note, no Mail/Notification sent), EditEventDraftTest, EditAndApproveTracerTest. Browser F12/F13 |
| 10 | SC3: A recurring series is approved once and its occurrences appear automatically | ✓ VERIFIED | ApproveDraftTest l.131-157 asserts the raw-SQL UTC instants across the end of BST (`2026-10-29 19:30:00`) and the start of BST (`2027-03-28 09:00:00`). Browser F12 checks the weekly series appears on / |
| 11 | SC3: Every published event records approver and time; manual entry publishes through the same review path; the queue is the only path to publication | ✓ VERIFIED | `ApproveDraft` does lock, transition assert, blockers, `fromDraft`, PublishEvent and stamps reviewed_by/at in one `DB::transaction`. `CreateEvent::handleRecordCreation` uses DraftWriter then ApproveDraft. Arch test `PublishEvent toOnlyBeUsedIn App\Publishing\Actions` (mutation-checked per 02-03). Grep found no other `Event::create` / insert in app/ (EventForm's `new Event` is an unsaved preview). Rollback test: ManualEntryThroughDraftTracerTest l.96. Idempotency: ApproveDraftTest 'creates one event when the same draft is approved twice' |
| 12 | SC4: "Approve & next" page with guarded keyboard shortcuts | ✓ VERIFIED | `review-event-draft.blade.php`: guard on input, textarea, select, contenteditable, combobox and textbox; on Ctrl/Meta/Alt, repeat and composing; open modals; localStorage toggle. aria-keyshortcuts on each action. Browser ApproveAndNextKeyboardTest: A then Enter, keys typed into the reject note ignored, ? help, J/K, toggle remembered, A blocked with reasons |
| 13 | SC4: Bulk approve and bulk reject; bulk approve visibly skips parse errors, duplicates and Low quality | ✓ VERIFIED | `EventDraftsTable::approveSelected` runs `bulkBlockerKeys()[0]` per record, one ApproveDraft transaction each, and the failure notification shows `skipMessage` per key. BulkReviewTest: named skips, counts add up, concurrency 'already reviewed', bulk reject of Low and parse-error drafts. Browser F13 |
| 14 | SC5: event-sources.md lists verified local sources with priority P0-P3, platform, extraction method, robots status and legal basis | ✓ VERIFIED (caveat) | Two lists (List 1: 10 sources, List 2: 9), coverage-by-category table, a field table per entry, legal_basis values, an excluded-sources section. Caveat: the Primary School entry's platform and method are 'unknown (pending)'. See gap and judgment item 2 |
| 15 | SC5: TEC endpoints, dated RSS feeds and JSON-LD presence verified live | ✓ VERIFIED | 'TEC contract check (RES-02)': Town Council REST 200 with `x-tec-total`/pages and iCal 200. Dated RSS: Baptist Church (event start in `<title>`, pubDate explicitly not used). JSON-LD `Event` (incl. `@graph`) on Visit Norfolk and Visit Wymondham. Per-candidate probe log table (l.391) |
| 16 | SC5: Fixture samples saved for every P0/P1 source | ✗ FAILED | 4 of 5 List 1 P0/P1 sources have fixtures. **Attleborough Primary School (P1) has none.** Manifests are valid: unmodified files match `sha256_raw`, redacted files are marked `modified: true` with redaction counts. See Gaps |

**Score:** 15/16 truths verified (0 present but behavior-unverified)

The plan-level must-have truths of all 16 plans (edge, boundary, concurrency, idempotency and ordering truths) map to named tests in the suites above. Unit+Feature ran **1005/1005 passing** and Browser ran **33/33 passing** in this verification. Every behavior-dependent truth (transaction rollback, approve idempotency, bulk concurrency, keyboard guards, DST instants, retention boundaries) has a passing behavioral test, so none is left PRESENT_BEHAVIOR_UNVERIFIED.

### Required Artifacts

`gsd-tools verify.artifacts` across all 16 plans: **50/50 passed**. All exist, are substantive and contain their expected patterns.

| Artifact (key ones) | Status | Details |
|---|---|---|
| `database/migrations/2026_10_17_100200_create_event_drafts_table.php` | ✓ VERIFIED | enum CHECKs, partial unique `event_drafts_one_new_per_event` |
| `app/Publishing/Actions/ApproveDraft.php` | ✓ VERIFIED | read in full; single transaction, lockForUpdate, server-side blockers re-check |
| `app/Review/DraftPublishability.php` | ✓ VERIFIED | blockers(), bulkBlockerKeys() (adds `end_time_same_as_start` from the CR-02 fix), skipMessage() |
| `app/Review/{DraftWriter,CategoryAssigner,QualityScorer,VenueMatcher,ReviewQueue,SaveRules}.php` | ✓ VERIFIED | wired through DraftWriter::derive() and the Filament pages |
| `app/Filament/Resources/EventDrafts/**` | ✓ VERIFIED | resource, 3 pages, infolist, form, table, actions |
| `app/Submissions/{Honeypot,TurnstileVerifier}.php` | ✓ VERIFIED | |
| `docs/research/event-sources.md` | ✓ VERIFIED (content gap) | structure complete; Primary School entry pending |
| `tests/Fixtures/sources/*` | ⚠️ PARTIAL | 4 slug dirs; `attleborough-primary-school` missing |
| `tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php` | ⚠️ WEAK | passes, but the P0/P1 check reads the self-declared Fixture index (see Test Quality) |
| `docs/uat/browser-uat.md`, `tests/Browser/{SubmitEvent,ReviewQueue,Rules,ApproveAndNextKeyboard}Test.php` | ✓ VERIFIED | F11-F14 present and green |

### Key Link Verification

`gsd-tools verify.key-links` across all 16 plans: **30/30 verified**. Manual confirmation of the critical links:

| From | To | Via | Status |
|---|---|---|---|
| CreateEvent.php | ApproveDraft | `DB::transaction(fn () => ApproveDraft(DraftWriter::create(DraftData::fromManualForm($data))))` | ✓ WIRED |
| ApproveDraft | PublishEvent | `PublishableEventData::fromDraft($locked)` → `($this->publish)(...)` | ✓ WIRED |
| ApproveDraft | DraftPublishability | `blockers($locked)` inside the lock | ✓ WIRED |
| SubmissionController | DraftWriter | `fromSubmission($request->validated())` | ✓ WIRED |
| routes/web.php | rate limiter | `->middleware('throttle:submissions')` | ✓ WIRED |
| EventDraftsTable | DraftPublishability | `bulkBlockerKeys($record)` / `skipMessage` | ✓ WIRED |
| review-event-draft.blade.php | ReviewEventDraft | `$wire.approveFromKeyboard()` / `$wire.mountAction(...)` | ✓ WIRED |
| SourceFixturesHaveNoPersonalDataTest | event-sources.md | parses `## Fixture index` | ✓ WIRED (but see Test Quality: wrong source of truth) |

### Data-Flow Trace (Level 4)

| Artifact | Data | Source | Real data | Status |
|---|---|---|---|---|
| Review queue table | drafts | Eloquent `EventDraft` query + `ReviewQueue::applyOrder` | yes | ✓ FLOWING |
| Draft infolist | quality_reasons / category_reason / suggestion | DraftWriter::derive() → QualityScorer / CategoryAssigner, VenueMatcher (pg_trgm) | yes | ✓ FLOWING |
| /submit venues and categories | Venue / Category | DB queries in `SubmissionController::create` | yes | ✓ FLOWING |
| Public calendar after approve | events / occurrences | PublishEvent via ApproveDraft | yes (browser F12 sees the series on /) | ✓ FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|---|---|---|---|
| Whole Unit+Feature suite (run once) | `./vendor/bin/pest --testsuite=Unit,Feature` | 1005 passed, 5178 assertions | ✓ PASS |
| Browser suite F0-F14 | `npm run build && ./vendor/bin/pest --testsuite=Browser` | 33 passed, 442 assertions | ✓ PASS |
| Static analysis | `./vendor/bin/phpstan analyse --memory-limit=1G` | 0 errors | ✓ PASS |
| Fixture integrity | sha256 of each fixture vs manifest `sha256_raw` | 4 unmodified files match; 3 redacted Town Council files differ and are marked `modified: true` with counts | ✓ PASS |
| Skipped or disabled tests | grep `->skip(` / `->todo(` / `->only(` / `markTestSkipped` | none | ✓ PASS |

### Probe Execution

Step 7c: `ops/probe-sources.sh` is a live-network capture tool, not a pass/fail probe. Running it would make outbound requests to third-party sites and write fixtures (a state mutation outside verification scope), so it was **not run**. Its absence of output for `attleborough-primary-school` is the gap itself.

### Requirements Coverage

| Requirement | Source Plan | Description (short) | Status | Evidence |
|---|---|---|---|---|
| SUB-01 | 02-04, 02-05 | Submission form fields + consent | ✓ SATISFIED | Truth 1 |
| SUB-02 | 02-05 | Pending draft, confirmation, not public | ✓ SATISFIED | Truth 2 |
| SUB-03 | 02-05, 02-09 | Validation errors, no draft | ✓ SATISFIED | Truth 3 |
| SUB-04 | 02-09, 02-10, 02-16 | Honeypot, rate limit, Turnstile | ✓ SATISFIED | Truth 4 |
| REV-01 | 02-08, 02-12, 02-13 | Draft detail incl. band, reasons, parse errors, duplicate link | ✓ SATISFIED | Truth 5 |
| REV-02 | 02-01, 02-03 | One-transaction approve, approver/time, single write path | ✓ SATISFIED | Truth 11 |
| REV-03 | 02-14 | Edit and approve | ✓ SATISFIED | Truth 9 |
| REV-04 | 02-06, 02-12 | Reject with optional reason | ✓ SATISFIED | Truth 9 |
| REV-08 | 02-03, 02-13 | Bulk approve/reject with skips | ✓ SATISFIED | Truth 13 |
| REV-09 | 02-13 | Filters | ✓ SATISFIED | Truth 6 |
| REV-10 | 02-15 | Approve & next + shortcuts | ✓ SATISFIED | Truth 12 |
| REV-11 | 02-03 | Series approved once | ✓ SATISFIED | Truth 10 |
| PIPE-03 | 02-03, 02-07, 02-11 | Deterministic category chain | ✓ SATISFIED | Truth 8 |
| PIPE-04 | 02-08 | 0-100 score, band, reasons | ✓ SATISFIED | QualityScorerTest, DraftScoringTest (80/79/50/49 boundaries, clamp, ordering) |
| ADM-04 | 02-07, 02-11 | Categories + one Rules screen | ✓ SATISFIED | Truth 8 |
| ADM-06 | 02-12 | Search events and drafts | ✓ SATISFIED | Truth 7 |
| RES-01 | 02-02 | Verified source inventory with fields and priority | ✓ SATISFIED (caveat) | Truth 14; Primary entry incomplete |
| RES-02 | 02-02 | TEC / dated RSS / JSON-LD verified live | ✓ SATISFIED | Truth 15 |
| RES-03 | 02-02 | Fixtures for each P0/P1 source | ✗ BLOCKED | Truth 16: Attleborough Primary School (P1) has no fixture. REQUIREMENTS.md wrongly marks it [x] |

All 19 phase requirement IDs appear in plan frontmatter. REQUIREMENTS.md maps no extra IDs to Phase 2, so there are **no orphaned requirements**. REV-05/06/07 and RES-04 map to Phases 3 and 4.

### Prohibitions (must-NOT checks)

| Plan | Req | Statement (short) | Tier | Disposition |
|---|---|---|---|---|
| 02-02 | RES-02 | No bypassing a bot wall, CAPTCHA, robots disallow or ToS | judgment | ⚑ FLAGGED: needs human resolution (non-authoritative LLM verdict: no bypass evidenced; one self-reported Crawl-delay slip on a robots.txt fetch) |
| 02-02 | RES-03 | No personal data in fixtures | test | ✓ enforced: email/phone scan passes on all 7 fixture files |
| 02-02 | RES-01 | No List 1 entry without live evidence and a working method | judgment | ⚑ FLAGGED: needs human resolution (LLM verdict: the Primary School entry doesn't meet the "working ladder method" clause) |
| 02-03 | REV-02 | No publish path other than ApproveDraft → PublishEvent | test | ✓ enforced: arch test `toOnlyBeUsedIn` |
| 02-04 | SUB-01 | Private contact never shown publicly | test | ✓ ContactVisibilityTest |
| 02-05 | SUB-02 | No public display before approval | test | ✓ SubmitEventTracerTest |
| 02-06 | REV-04 | No organiser messages | test | ✓ RejectReopenDraftTest `Mail/Notification::assertNothingSent` |
| 02-06 | REV-04 | Retention 30/90 days; never prune pending/approved | test | ✓ PruneRejectedDraftsTest (29/31, 89/91, pending, approved) |
| 02-07 | PIPE-03 | No AI/LLM/network classification | test | ✓ CategoryAssignerTest determinism + global `Http::preventStrayRequests()`; no LLM package in composer.json |
| 02-08 | REV-01 | Matching never creates a venue or sets venue_id | test | ✓ VenueMatcherTest `Venue::count()` unchanged |
| 02-09 | SUB-04 | No third-party script except Turnstile on /submit | test | ✓ NoThirdPartyAssetsTest |
| 02-09 | SUB-03 | No silent discard of a human's submission | test | ✓ SpamDefenceTest |
| 02-10 | SUB-04 | IP never stored with the draft | test | ✓ RateLimitTest 'stores no IP address'; SessionPrivacyTest (CR-01 fix) |
| 02-12 | REV-01 | Suggested venue never treated as confirmed | test | ✓ bulkBlockerKeys requires venue_id; ApproveDraftTest 'skips a High draft with no confirmed venue ... even with an exact-match venue' (see WR-06 warning) |
| 02-13 | REV-08 | Bulk approve never publishes blocked drafts; no silent skip | test | ✓ BulkReviewTest |
| 02-14 | REV-03 | No venue created without 'Create new venue' | test | ✓ EditEventDraftTest `Venue::count()` before and after |
| 02-15 | REV-10 | Keys in text fields or dialogs never act | test | ✓ Browser 'ignores keys typed into the reject note and while a dialog is open' |
| 02-15 | REV-10 | Shortcuts can be turned off and are discoverable | test | ✓ Browser toggle, help and key hints |
| 02-16 | SUB-04 | Browser tests never load third-party hosts | test | ✓ `Http::preventStrayRequests()`, no site key in tests, `fakeTurnstile()` |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|---|---|---|---|---|
| tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php | P0/P1 test | Requirement guard reads a self-declared index, not the source list | ⚠️ Warning | Let RES-03 pass while a P1 source has no fixture |
| tests/Feature/Submissions/TurnstileVerifierTest.php | 138, 145 | `XXXX.DUMMY.TOKEN.XXXX` | ℹ️ Info | Cloudflare's documented dummy token, not a debt marker |
| app/Filament/Resources/EventDrafts/Pages/EditEventDraft.php | ~99 | WR-06: similarity suggestion pre-filled into venue_id | ⚠️ Warning (owner-deferred) | Matches D-05 "suggestion is pre-selected", but an unrelated Save confirms a 0.3-similarity guess, after which bulk approve accepts it |
| app/Review/DraftPublishability.php | — | WR-07: past-dated drafts approvable | ⚠️ Warning (owner-deferred) | Not in any SC; publishes invisible events |
| app/Review/SaveRules.php | — | WR-05: last-writer-wins deletes rows created after page load | ⚠️ Warning (owner-deferred) | Concurrency data loss on the Rules screen |
| routes / AppServiceProvider | — | WR-01/WR-02: limiter counts invalid POSTs; proxy IP behind Cloudflare | ⚠️ Warning (owner-deferred) | WR-02 already a STATE/Phase 5 concern |
| TurnstileVerifier / HealthReport | — | WR-03: hostname check open when list empty in production | ⚠️ Warning (owner-deferred) | |
| StoreSubmissionRequest | — | WR-04: no end-date upper bound | ⚠️ Warning (owner-deferred) | |
| EventDraft prunable / privacy | — | WR-08: pending drafts and raw_payload email retained indefinitely | ⚠️ Warning (owner-deferred) | Privacy-notice gap; flag for secure-phase |
| Review queue tabs / sidebar badge | — | Stale counts after bulk action (deferred-items.md) | ℹ️ Info | Cosmetic; rows update |

There are no unreferenced TBD/FIXME/XXX debt markers in phase-modified source files. CR-01 and CR-02 are fixed (2355016, 2ff83f7) and covered by SessionPrivacyTest and the BulkReviewTest / DraftPublishability `end_time_same_as_start` tests. None of the owner-deferred warnings makes a ROADMAP success criterion false, so none is a blocker. They are recorded for secure-phase and the owner.

### Test Quality Audit

| Test File | Linked Req | Active | Skipped | Circular | Assertion Level | Verdict |
|---|---|---|---|---|---|---|
| tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php | RES-03 | yes | 0 | no | Value | ⚠️ INSUFFICIENT for "every P0/P1": the P0/P1 set comes from the doc's own Fixture index, which omits the pending P1 |
| tests/Feature/Review/ApproveDraftTest.php | REV-02, REV-11, REV-08 | yes | 0 | no | Value (raw-SQL UTC instants, exact key lists) | ✓ |
| tests/Feature/Filament/BulkReviewTest.php | REV-08 | yes | 0 | no | Behavioral | ✓ |
| tests/Browser/ApproveAndNextKeyboardTest.php | REV-10 | yes | 0 | no | Behavioral (real browser) | ✓ |
| tests/Unit/Architecture/WriteChokePointTest.php | REV-02 | yes | 0 | no | Structural (mutation-checked) | ✓ |
| tests/Feature/Submissions/* | SUB-01..04 | yes | 0 | no | Value / Behavioral | ✓ |

**Disabled tests on requirements:** 0. **Circular patterns detected:** 0. **Insufficient assertions:** 1 (warning).

### Decision Coverage

All trackable CONTEXT.md decisions are honored by shipped artifacts (25/25; `check.decision-coverage-verify`, non-blocking).

### Human Verification Required

These are the two judgment-tier prohibitions from 02-02. Each needs an explicit owner decision.

#### 1. No bypass while probing (RES-02, legal)

**Test:** Confirm no probe or capture used a spoofed UA, a challenge solver, cookies copied from a browser or a proxy.
**Expected:** Confirmed. The doc and the probe script indicate honest behavior. One self-reported robots.txt fetch fell inside attleboroughprimary.org's `Crawl-delay: 12000`, and the script was fixed afterwards.
**Why human:** The research ran outside the repo's test harness, so this is LLM judgment only.

#### 2. No List 1 entry on assumption (RES-01, values)

**Test:** Decide whether Attleborough Primary School (P1, calendar never fetched, method unknown) and the three P3 manual-entry sources belong in List 1 under the "working ladder method" clause.
**Expected:** An owner ruling. Closing the RES-03 gap by capturing the Primary calendar resolves the Primary part.
**Why human:** Judgment-tier prohibition.

### Gaps Summary

**One gap: RES-03 / SC5, "fixture samples are saved for every P0/P1 source".**

Attleborough Primary School is a P1 source in List 1 with no fixture. Its calendar page has never been fetched because its robots.txt sets `Crawl-delay: 12000`. The executor honoured that delay correctly and deferred the capture to the owner (`ops/probe-sources.sh attleborough-primary-school`, eligible after 23:05 BST on 2026-10-17, so it can run now).

The guard test doesn't catch the gap because the doc keeps the source out of the Fixture index the test reads. REQUIREMENTS.md and 02-02-SUMMARY still claim RES-03 complete. ROADMAP notes that source research "must be complete before Phase 3 starts, because ICS and later rungs need real fixtures", and no later phase covers this, so it can't be deferred.

The root cause is one source, with three fixes:
1. Capture, redact and index the fixture, and fill in the entry.
2. Make the guard read List 1.
3. Correct REQUIREMENTS.md if it stays open.

Everything else in the phase goal is achieved and proven by tests: submissions, the review queue as the only publication path, approve / edit / reject / reopen, Approve & next, bulk actions, Rules, search, scoring and the rest of the source inventory.

**This looks intentional** (a robots Crawl-delay honoured, with capture handed to the owner). If the owner prefers to accept the deviation rather than capture now, add this to the frontmatter:

```yaml
overrides:
  - must_have: "Fixture samples are saved for every P0/P1 source"
    reason: "Attleborough Primary School (P1, provisional) sets robots Crawl-delay: 12000; capture deferred to the owner via ops/probe-sources.sh before Phase 3 planning starts; 4/5 P0/P1 sources have fixtures"
    accepted_by: "{owner}"
    accepted_at: "{ISO timestamp}"
```

Even with an override, harden the guard test so it reads List 1.

---

_Verified: 2026-10-17T22:25:00Z_
_Verifier: the agent (gsd-verifier)_
