# Phase 3: Ingestion Backbone, ICS & Change/Duplicate Handling - Research

**Researched:** 2026-10-17
**Domain:** Polite HTTP ingestion, ICS (RFC 5545) extraction with sabre/vobject 5, change detection against an accepted baseline, pg_trgm duplicate detection, Filament 5 review extensions, public attribution
**Confidence:** HIGH for codebase facts, library behaviour and fixtures (read or executed this session). MEDIUM for schema and state-machine design (a recommendation built on locked decisions). LOW for items in the Assumptions Log.

## Summary

Phase 3 adds a second write model to the app (`sources → source_runs → source_items → event_drafts`). It reaches the published model only through new `App\Publishing\Actions`. Phases 1–2 already supply most of the downstream pieces: `DraftWriter`, `DraftKind::{Update,Cancellation}`, `ReviewStatus::{Duplicate,Superseded}`, the `duplicate` bulk-skip key, `pg_trgm` (v1.6 installed), sabre/vobject 5.0.0, `ChangeOccurrenceStatus` and `UpdatePublishedEvent`. Nothing new needs installing. The research recommendation was `spatie/robots-txt`, but its matching logic departs from RFC 9309, and it is not installed. An in-house `RobotsPolicy` is the better choice and needs no owner approval.

Running the probes against the real fixtures and code surfaced eight things that change the plan. They are bigger than any library choice:
1. **The published model cannot represent ICS EXDATE, RDATE, moved overrides or most RRULEs.** `events` has no exdates column, and `PublishableEventData::fromDraft` refuses any rule that does not round-trip through `ManualRecurrence`.
2. **`OccurrenceGenerator::sync` deletes every future row beyond the 90-day horizon.** A confirmed cancellation more than 90 days out would be wiped the next night.
3. **All 30 Town Council items and 39 of 64 Baptist items have an empty `DESCRIPTION`.** `DraftPublishability` blocks approval with no description, so "Approve all like this" (D-03) would skip almost everything.
4. **TEC iCal UIDs embed the start epoch.** A time change looks like a brand-new item plus a missing one, unless identity is taken from the post id.
5. **Same-day, same-venue Town Council titles reach 0.789 trigram similarity** ("… Junior Youth" vs "… Senior Youth"). A title + date + venue rule needs a start-time guard.
6. **`ReviewQueue::qualify()` only rewrites `start_date|start_time|created_at|id`.** A D-09 urgency sort key that uses other columns breaks the keyset navigation.
7. **`reportBulkProcessingFailure()` only works for real bulk actions.** "Approve all like this" must build its own notification.
8. **The production worker runs `--queue=default --tries=3 --timeout=60`.** Fetch jobs that release themselves for crawl delays need `retryUntil()`.

**Primary recommendation:** Build Phase 3 as five layers with clear seams:
- `App\Ingestion\Http` (pure, unit-tested fetcher policy)
- `App\Ingestion\Ics` (pure extractor over fixtures)
- `App\Ingestion\Pipeline` (recorder, change and duplicate detection, writes drafts only through `DraftWriter`)
- new `App\Publishing\Actions` (apply, ignore, confirm cancellation, mark or not duplicate, link source)
- Filament and public rendering

Back every concurrency rule with a database constraint (partial unique indexes and row locks), never with the cache alone.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

#### Feed volume and noise
- **D-01:** **New sources start with a test run.** Adding (or re-enabling) a source shows a preview of what a run would do: how many drafts it would create, how many items it would skip and why (past, beyond 12 months, skip rule, parse error). Nothing is drafted until the admin clicks **"Create drafts"**, which turns the source live. This is the "new-source onboarding preview" from research D19.
- **D-02:** **Default check frequency: daily, early morning** (London time), per-source override allowed. Honour each source's robots `Crawl-delay` (e.g. Baptist Church 10 s) on top of the ≥5 s per-host minimum.
- **D-03:** **One draft per feed item, plus an "Approve all like this" bulk action.** Feeds that list every week as a separate item (Town Council: yoga, WI, cafés; ~60 items/month) are not grouped or guessed into series. The review queue gets an action that approves every pending draft from the same source with the same title and venue in one go. It goes through the existing per-draft `ApproveDraft` checks and reports skips like bulk approve (Phase 2 D-21 rules still apply).
- **D-04:** **Per-source skip/keep title rules.** Each source can have rules on item titles (e.g. skip "Private hire", "Council meeting", "Sunday Service"; or keep-only rules). Skipped items never become drafts and are counted in the run log as "skipped (rule)". Rules are whole-word, case-insensitive, like Phase 2 keyword rules.
- **D-05:** **Pre-load the three ready ICS sources as inactive:** Attleborough Baptist Church (ICS), Attleborough Town Council (TEC iCal export), Attleborough Academy (ICS), with URLs, legal basis and notes from `docs/research/event-sources.md`. The admin opens each, runs the test preview and activates it. Owner clarification of the "seeders seed reference data only" rule: inactive source records are reference configuration, not demo events, so seeding them is allowed. The seeder must use firstOrCreate and never overwrite admin edits.

#### Updates and cancellations
- **D-06:** **Update drafts only for facts residents rely on:** title, date/time (including all-day and end), venue, price/free, family-friendly, cancelled status. Wording-only description changes update the stored source text and the accepted baseline quietly, with no draft. (Refines research D13: the semantic `content_hash` covers these fields.)
- **D-07:** **"Apply" takes all changed fields shown in the three-column diff** (published now / source before / source now). No per-field picking. Apply and Ignore both move the accepted baseline (REV-06).
- **D-08:** **Confirming a cancellation keeps the date visible as "Cancelled"**, using the Phase 1 occurrence status (shown as text). For a series, only the affected date changes; the rest is untouched. One-off events become cancelled the same way.
- **D-09:** **Urgent changes jump the queue:** update and cancellation drafts for events starting within the next 48 hours (London time) get an "Urgent" badge and sort to the top of the Pending tab.

#### Duplicates and ownership
- **D-10:** **Marking a draft as a duplicate keeps the existing published details.** The admin's edits win; nothing new is published. The feed item is linked to the event through `event_sources` (REV-05) for attribution and future changes.
- **D-11:** **Linked sources keep watching.** After linking, later changes or cancellations of that item in the feed raise update/cancellation drafts for the linked event, compared against that link's accepted baseline.
- **D-12:** **Same event in two feeds: the organiser's own source is the main attribution** (e.g. Heritage Group over the Town Council feed). Other linked sources show as "Also listed by". Any linked source can raise update/cancellation drafts.
- **D-13:** **Duplicate warnings show the suspected match side by side** on the review page: date, time, venue, title and the reason (same canonical URL, or same date + similar venue + similar title with the similarity score), with **"Mark as duplicate"** and **"Not a duplicate"** buttons. "Not a duplicate" is remembered so the same pair isn't flagged again.

#### Public attribution and excerpts
- **D-14:** **Attribution on the event page and the calendar card.** Event page: "Listed by {source name}" with a link near the event details (plus "Also listed by …" per D-12). Calendar card: a short "via {source short name}" line. (LEG-04)
- **D-15:** **Description = auto excerpt, editable before approval.** Up to 200 characters, phone numbers and emails stripped, then "Read more at {source}". The admin can edit the excerpt on the edit page before approving. The full source text is kept internally for review only. (PIPE-09)
- **D-16:** **"Read more" links to the event's own page on the source site** when the feed gives one (ICS `URL`), otherwise the source's events page. Links go through the existing SafeLinks http/https rule.
- **D-17:** **Never publish organiser names or contacts from feeds.** Feeds name private people (the Town Council feed carries personal names, emails and phones). Discovered events show the source as attribution instead. The admin can add an organiser by hand when editing. Organiser fields from feeds may be kept internally for review but never reach public pages.

#### Roadmap
- **D-18 [informational]:** Owner removed the `**Mode:** mvp` tag from Phase 3 in ROADMAP.md (same as Phases 1 and 2).

#### Carried forward (do not re-ask)
- Architecture from research SUMMARY D13, D14, D19, D20 and Phase 3 notes: one source item per ICS series UID (master + overrides + EXDATE/RDATE in one fragment and hash); `raw_hash` to skip unchanged content plus a semantic `content_hash`; accepted baseline (`accepted_hash` + `accepted_payload`) updated on approve, apply and ignore; past and >12-month items skipped and counted; per-rung body validation (`BEGIN:VCALENDAR`), a bot wall or wrong content type is a failed `content_invalid` run, a robots disallow is a `blocked` run that doesn't degrade health; SSRF guard; never bypass bot protection.
- sabre/vobject is the only recurrence engine; floating times are Europe/London; unknown TZIDs are parse errors; store UTC (Phase 1 rules, EXT-07). `OccurrenceGenerator` preserves admin-set occurrence status.
- Missing-item detection (PIPE-08): only after 2 consecutive complete runs, only for future items inside the run's observed date window, only when the source supports missing detection (research D3).
- Health: degraded after 3 consecutive failures, unhealthy (paused) after 5; zero items after previous results flagged (SRC-08). Source failures never hide published events (SRC-09).
- All writes to events/occurrences go through `App\Publishing\Actions` (PublishEvent sole creator, arch test). New actions: apply update, ignore, confirm cancellation, mark duplicate.
- Review queue from Phase 2 (tabs, filters, bulk approve with named skips, Approve & next) is extended, not replaced. REV-09 "source" filter switches from origin to the real `sources` table.
- Honest identifying User-Agent; source inventory and legal basis per `docs/research/source-inventory.md` (D-23..D-26 of Phase 2). The Town Council blocks datacenter IPs: production may need the clerk allowlist (Phase 5 concern).

### the agent's Discretion
- Exact title-similarity threshold and duplicate scoring (start from pg_trgm ≥ 0.3 like the venue matcher, tune with fixtures).
- Raw body storage format and retention period (keep enough for debugging; prune old raw bodies).
- Wording of run-log statuses, health badges and the preview screen, within the Phase 1 brand voice.
- Early-morning schedule time and per-source jitter.

### Deferred Ideas (OUT OF SCOPE)
- Grouping per-occurrence feed items into a suggested series (considered for D-03, declined: guessing; revisit if volume makes "Approve all like this" insufficient).
- Picking individual fields when applying an update (declined for D-07; revisit if feeds change several facts at once and the admin wants partial applies).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description (REQUIREMENTS.md lines 50–113, verbatim) | Research Support |
|----|-------------|------------------|
| SRC-01 | Admin can create and edit sources: name, URL, extraction method, adapter, parser config, default category, default venue, location mode, default timezone, year policy, legal basis, missing-detection support, active flag, check frequency, notes | `sources` schema (§Schema). `SourceResource` form. Identity strategy as an enum inside `parser_config`. `title_rules` jsonb (D-04) |
| SRC-03 | Admin can trigger "Run now" for a source | `Run now` header action dispatches `FetchSourceJob` (trigger=manual). DB partial unique index refuses a second queued/running run |
| SRC-04 | The scheduler fetches each due active source automatically, never running two fetches of the same source at once | `ingest:dispatch-due` every 10 min `withoutOverlapping()`. `next_check_at` daily 05:30 London + jitter. `source_runs_one_active` partial unique index + `ShouldBeUnique` |
| SRC-05 | All fetching goes through one fetcher that checks robots.txt for every URL (disallowed means a `blocked` run, not a failure), sends a descriptive User-Agent with contact URL, waits at least 5 seconds per host, uses timeouts, size limits, conditional GET and an SSRF guard | `HttpFetcher` + `RobotsPolicy` (RFC 9309, in-house) + `HostThrottle` (`crawl_hosts` row lock) + `SsrfGuard` (`FILTER_FLAG_GLOBAL_RANGE` + extra blocks + `CURLOPT_RESOLVE` pinning) + manual redirect loop |
| SRC-06 | Fetched content is validated for the extraction method. Bot walls and wrong content types count as a failed `content_invalid` run, not a successful empty run | `BodyValidator` per method: ICS body must start (after BOM/whitespace) with `BEGIN:VCALENDAR`. HTML/challenge markers → `content_invalid`. Content-type is advisory (Academy sends `text/calendar;;charset=UTF-8`) |
| SRC-07 | Each run is logged with status, items found / new / changed, skipped (past, far future, out of area), drafts created and error message | `source_runs` counters (§Schema). Runs relation manager with `->poll()` |
| SRC-08 | A source becomes `degraded` after 3 consecutive failures and `unhealthy` (paused, flagged on dashboard) after 5. Zero items after previously returning items is flagged as a possible structure change | `SourceHealthTracker` state machine (§State machines). `HealthReport::sources()` check on System health |
| SRC-09 | Source failures never delete or hide previously discovered events | A failed, blocked or incomplete run never touches `source_items` missing counters or drafts. Only Publishing actions change events. Tested with a failed run following an approved item |
| EXT-01 | System extracts events from ICS feeds, including VTIMEZONE, RRULE / RDATE / EXDATE, RECURRENCE-ID overrides, all-day events and `STATUS:CANCELLED`, storing one source item per series UID | `IcsExtractor` (§Pattern 3) verified against sabre `EventIterator` this session. Publishing gap → Open Question 1 |
| EXT-07 | Floating or timezone-less times are interpreted in the source's default timezone (Europe/London), unknown TZIDs become parse errors, and all times are stored in UTC | `TimeZoneUtil::getTimeZone($tzid, $vcal, true)` throws `InvalidArgumentException` (verified). `getDateTime($sourceTz)` for floating (verified) |
| PIPE-01 | Past events and events more than 12 months ahead are skipped and counted, not drafted | `WindowClassifier`: next instance ≥ now and < now + 12 months London. Counters `skipped_past` / `skipped_far_future` |
| PIPE-05 | Likely duplicates (same canonical URL, or same date + similar venue + `pg_trgm` title similarity above a configured threshold, against published events and pending drafts) produce a duplicate warning. Same source + external ID is treated as the same item | `DuplicateDetector` (§Pattern 6) with start-time guard and same-source exclusion. `duplicate_decisions` |
| PIPE-06 | A changed known item creates an update draft only when its normalised fields differ from the accepted baseline. Unchanged raw content is skipped. Only one pending draft exists per source item (replaced in place) | `raw_hash` fast path (DTSTAMP-stripped). `content_hash` over D-06 facts. Partial unique index `event_drafts(source_item_id) WHERE review_status='pending_review'` |
| PIPE-07 | Items marked cancelled at the source (`STATUS:CANCELLED`, `eventStatus: EventCancelled`) create a cancellation draft | Master `STATUS:CANCELLED` → whole-event cancellation draft. Override with `STATUS:CANCELLED` → occurrence cancellation draft with `target_recurrence_key` (sabre yields cancelled overrides: verified) |
| PIPE-08 | An item missing from its source creates a cancellation draft only after 2 consecutive complete runs, and only if its last known start is in the future, inside the run's observed date window, and the source supports missing detection | `missing_runs` counter on `source_items`, incremented only on `is_complete` live runs. `observed_from/observed_until` on runs |
| PIPE-09 | Public descriptions of discovered events are excerpts of up to 200 characters with phone numbers and emails stripped. The full text is kept internally for review | `ExcerptBuilder` (strip emails/phones → `TextLimit::graphemes($t, 200)`). Full text in `event_drafts.raw_payload` / `source_items.payload`. Empty-description issue → Open Question 3 |
| REV-05 | Admin can mark a draft as a duplicate of an existing event. It is linked through `event_sources` and nothing new is published | `MarkDraftDuplicate` action. Sets `duplicate_of_event_id`, **not** `event_id` (unique index `event_drafts_one_new_per_event`) |
| REV-06 | Admin sees update drafts as a three-column diff (published now / source before / source now) and can apply or ignore. Both actions update the accepted baseline | `event_drafts.diff` jsonb + `ViewEntry` diff table. `ApplySourceUpdate` reuses `UpdatePublishedEvent`. `IgnoreSourceChange` |
| REV-07 | Admin can confirm a cancellation draft (event or occurrence becomes cancelled) or ignore it | `ConfirmSourceCancellation` → `ChangeOccurrenceStatus` / `ChangeEventStatus`. Horizon issue → Pitfall 2 |
| LEG-04 | Every discovered event shows visible source attribution with a link | `event_sources` (primary + "also listed by"). `EventPageData` / `OccurrenceCardData` gain attribution fields. Eager-load to avoid N+1 |
</phase_requirements>

## Project Constraints (from AGENTS.md, .ai/guidelines/attleborough.md, copilot-instructions.md)

`.github/copilot-instructions.md` holds only GSD tooling instructions (no coding directives). `AGENTS.md` embeds `.ai/guidelines/attleborough.md` plus the Boost guidelines. Directives the planner must honour:

- **Time:** `app.timezone` and the PG session stay UTC. Instants are `timestampTz`, and `->utc()` is called before assignment. **Upsert bypasses casts, so pass explicit UTC `Y-m-d H:i:s` strings.** Do date maths on `Europe/London` `CarbonImmutable`. `recurrence_key` is the London local `Y-m-d`. Never call `FilamentTimezone::set()`. Add `->timezone('Europe/London')` on Filament columns that show UTC instants.
- **Recurrence:** the sabre/vobject `EventIterator` is the only expansion engine, always given `new DateTimeZone('Europe/London')`. No hand-rolled loops. `OccurrenceGenerator::sync()` never updates `status`.
- **Writes:** only `App\Publishing\Actions\*` create, update or change status on `events` / `event_occurrences`. `PublishEvent` is the only creator (arch test `toOnlyBeUsedIn('App\Publishing\Actions')`). No `Event::create()` or `$event->update()` in pages, controllers or jobs. Seeders seed reference data only (D-05 owner clarification: inactive sources count as reference config).
- **Tests:** Pest 5 on PostgreSQL `attleborough_testing`. `Http::preventStrayRequests()` is global. Freeze London time with `travelTo`. DST fixtures 25 Oct 2026 and 28 Mar 2027. Raw-SQL UTC assertions. CI runs under `TZ=America/New_York`. Browser UAT flows are added to `docs/uat/browser-uat.md` for UI changes. Read the `testing-best-practices` skill before writing tests. Run the narrowest tests: `php artisan test --compact <file>`.
- **Filament 5:** `Filament\Schemas\Schema` + `->components()`. Layout components live in `Filament\Schemas\Components`. Actions are `Filament\Actions\*`. Table action tests use `TestAction::make('x')->table($record)`. Enums implement `HasLabel` (`HasColor` for statuses). Resources live in `app/Filament/Resources/{Plural}/{Schemas,Tables}`.
- **Public site:** Blade + Tailwind 4, no Livewire, no third-party assets. WCAG 2.2 AA ("Cancelled" as text, 24×24 targets). British English. Escaped `{{ }}` only. Descriptions are plain text.
- **Dependencies:** no Redis (database queue, cache and session). No LLM/AI runtime packages. Hetzner VPS. Composer platform pinned to PHP 8.4.12. **Any new package needs owner approval.**
- **Code style:** curly braces always, constructor promotion, explicit return types, TitleCase enum cases, PHPDoc array shapes. Use `php artisan make:* --no-interaction`. Run `vendor/bin/pint --dirty --format agent` after PHP edits. Use `search-docs` before ecosystem-API-dependent changes.

## Critical Findings and Conflicts (read before planning)

Each finding cites code or fixtures read, or probes executed, this session.

### F1 — The published model cannot hold ICS exclusions, extra dates or moved instances (affects EXT-01, success criterion 1, REV-06/07)
- `events` has a single `recurrence_rule` and no exdates/rdates column: `$table->text('recurrence_rule')->nullable();` [VERIFIED: database/migrations/2026_10_16_000130_create_events_table.php:37]. `event_drafts` likewise has only `$table->text('recurrence_rule')->nullable();` [VERIFIED: database/migrations/2026_10_17_100200_create_event_drafts_table.php:51].
- Approval refuses any rule the form cannot round-trip: `$recurrence = ManualRecurrence::tryFromStoredRule($draft->recurrence_rule, $startLocal, $draft->all_day);` … `throw new InvalidArgumentException("This repeat rule can't be published from the form.");` [VERIFIED: app/Publishing/Data/PublishableEventData.php:227-230]. `ManualRecurrence` only accepts `'/^FREQ=(?<freq>WEEKLY|MONTHLY);BYDAY=(?<nth>-1|[1-4])?(?<day>MO|TU|WE|TH|FR|SA|SU)'.'(?:;UNTIL=(?<until>\d{8}(?:T\d{6}Z)?)|;COUNT=(?<count>\d{1,3}))?$/'` [VERIFIED: app/Calendar/ManualRecurrence.php RRULE_PATTERN].
- `RecurrenceExpander` builds a synthetic VEVENT with DTSTART + RRULE only (`$vevent->add('RRULE', $event->recurrence_rule);`) [VERIFIED: app/Calendar/RecurrenceExpander.php:110], so EXDATE/RDATE/overrides are never applied to published series.
- **Reality check:** none of the three real ICS fixtures has an event RRULE, EXDATE, RDATE, RECURRENCE-ID or STATUS line. The only two RRULEs in the Academy file sit inside its VTIMEZONE (`RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3` and `…BYMONTH=10`) [VERIFIED: grep of tests/Fixtures/sources/*/2026-10-17-ical.ics]. The Baptist manifest says "No RRULE: recurring services are separate VEVENTs". The Academy manifest says "No RRULE in events" [VERIFIED: manifest.json notes]. Recurrence features therefore need **synthetic fixtures**. The first real sources produce only one-off items.
- **Recommendation:** see Open Question 1. Extract everything EXT-01 lists (hash, diff and draft display included). Publish the representable subset: one-offs, plus series whose RRULE round-trips through `ManualRecurrence`, plus EXDATE through a new `excluded_dates` column. Everything else becomes a draft `parse_errors` entry that blocks bulk approval and tells the admin what to fix.

### F2 — Occurrence rows beyond the horizon are deleted every night (affects REV-07, D-08)
`OccurrenceGenerator::sync` deletes with no upper bound: `$event->occurrences()->where('starts_at', '>=', $windowStart->utc()->format('Y-m-d H:i:s'))->whereNotIn('recurrence_key', array_column($rows, 'recurrence_key'))->delete();` where rows only cover `$windowStart->addDays((int) config('calendar.horizon_days', 90))` [VERIFIED: app/Calendar/OccurrenceGenerator.php:33,51-54]. `'horizon_days' => 90` [VERIFIED: config/calendar.php:18]. Ingestion drafts up to 12 months ahead (PIPE-01). A cancellation for a series date more than 90 days out has no occurrence row to cancel, and any row created for it would be pruned. **Recommendation:** raise `calendar.horizon_days` to 400. Every ingestible instance then has a row, and `ChangeOccurrenceStatus` works unchanged. Alternatively, keep such cancellation drafts pending until the date enters the horizon. Owner confirmation needed (A2).

### F3 — Most discovered items have no description, and description is a hard approval blocker (affects D-03, D-15, PIPE-09)
- `tr -d '\r' | grep -c "^DESCRIPTION:$"` → Town Council **30 of 30** empty, Baptist **39 of 64** empty. The Academy file has no DESCRIPTION property at all [VERIFIED: fixture probe].
- `if (! self::filled($draft->description)) { $blockers['no_description'] = 'Add a description.'; }` [VERIFIED: app/Review/DraftPublishability.php:148-150]. Bulk approve maps any other blocker to skip key `'blocked'` [VERIFIED: same file :97-101]. Missing description also costs `'missing_field' => 50` [VERIFIED: config/review.php:19].
- Without a fallback, "Approve all like this" skips every Town Council draft and the admin must type a description for each one. See Open Question 3.

### F4 — Source identity is not always the UID (affects PIPE-05/06/08)
- Town Council (TEC iCal): UIDs are `{post id}-{start epoch}-{end epoch}@attleboroughtc.org.uk`, and each weekly instance has its **own post id** (Yoga 18 Oct → `10003892-…`, 25 Oct → `10003893-…`) [VERIFIED: fixture probe; manifest note "stable per occurrence"]. If the source moves the time, the UID changes and the item looks new + missing. **Identity must be the post id.**
- Academy: "UIDs are NOT stable: each is a row number followed by the request's Unix time (e.g. UID:2841792262071), so dedupe must key on SUMMARY + DTSTART" [VERIFIED: attleborough-academy/manifest.json notes]. A time change then produces a new item. Leave `supports_missing_detection = false` for this source, and the duplicate detector catches the re-timed copy against the published event.
- Baptist: stable `E{event id}@UK.endis.com` [VERIFIED: manifest notes].
- **Recommendation:** add an `IdentityStrategy` enum in `parser_config` (`uid` | `tec_post_id` | `summary_start`). Never accept an admin-written regex (avoids ReDoS and invalid patterns).

### F5 — Title similarity alone flags Town Council's own sessions (affects PIPE-05, D-13)
Measured on the dev DB (pg_trgm 1.6, PostgreSQL 18.6): `similarity('attleborough youth project ~ junior youth','attleborough youth project ~ senior youth') = 0.789`, `send junior/senior youth = 0.737`, `parish council meeting / town council meeting = 0.571`, `christmas fayre / christmas fair = 0.632`, `coffee morning / coffee morning at st mary s church = 0.469` [VERIFIED: tinker probe]. The fixture has Junior Youth 15:45 and Senior Youth 18:00 on the same day at "Town Hall" [VERIFIED: fixture probe]. **Recommendation:** the fuzzy rule = same London date AND start times within 60 minutes (or both all-day) AND venue match AND `similarity ≥ 0.3`. Exclude candidates that come from the same source (same source + different external id = different item, per PIPE-05).

### F6 — `ReviewQueue` keyset only qualifies four column names (affects D-09)
`return (string) preg_replace('/\b(start_date|start_time|created_at|id)\b/', "{$alias}.$1", $expression);` [VERIFIED: app/Review/ReviewQueue.php qualify()]. An urgency key that uses `kind` or a new column needs that column added to the regex. Otherwise `next()`/`previous()` compare the outer row with itself and Approve & next breaks silently. Add a keyset test that has urgent and non-urgent drafts.

### F7 — Filament `reportBulkProcessingFailure()` only counts correctly for bulk actions
`reportBulkProcessingFailure()` decrements `$this->successfulSelectedRecordsCount`, which is set from the selection (`$this->successfulSelectedRecordsCount = $this->totalSelectedRecordsCount;`) and is `0` otherwise [VERIFIED: vendor/filament/actions/src/Concerns/InteractsWithSelectedRecords.php:19,63,139-151]. "Approve all like this" is a record/page action with no selection. It must count outcomes itself and send a `Notification` built from `DraftPublishability::skipMessage()`.

### F8 — Queue worker and job lifecycle
- Phase 5 worker: `queue:work database --queue=default --sleep=3 --tries=3 --timeout=60 …` [VERIFIED: .planning/phases/05-launch-readiness/05-01-PLAN.md:334]. Queue `retry_after` = `(int) env('DB_QUEUE_RETRY_AFTER', 90)` [VERIFIED: config/queue.php:43].
- Laravel ignores attempts while `retryUntil()` is in the future: `if ($retryUntil && Carbon::now()->getTimestamp() <= $retryUntil) { return; }` [VERIFIED: vendor/laravel/framework/src/Illuminate/Queue/Worker.php:732-736]. A fetch job that `release()`s itself while waiting for a host slot needs `retryUntil()` plus `#[MaxExceptions(1)]` (attribute exists in `Illuminate/Queue/Attributes/MaxExceptions.php`). Otherwise `--tries=3` fails it after three polite waits.
- Keep jobs on the `default` queue (the Phase 5 unit listens only there), or update 05-01's ExecStart to `--queue=default,ingest`.

### F9 — Approval, duplicate and draft-writer constraints already in the schema
- `event_drafts_extraction_method_check CHECK (extraction_method IN ('submission', 'manual'))` [VERIFIED: create_event_drafts_table.php:82]. `ExtractionMethod` has only `Submission`, `Manual` [VERIFIED: app/Enums/ExtractionMethod.php:13-14]. Phase 3 adds `ics` to both.
- `CREATE UNIQUE INDEX event_drafts_one_new_per_event ON event_drafts (event_id) WHERE kind = 'new'` [VERIFIED: :95]. **MarkDuplicate must not set `event_id` on a `new` draft.** Use `duplicate_of_event_id` (already a column, `:30`). The CHECK `event_drafts_approved_has_event` only constrains `approved` rows (`:88`).
- `DraftPublishability` blocks every non-new draft: `if ($draft->kind !== DraftKind::New) { $blockers['kind'] = 'Only new-event drafts can be approved here.'; }` [VERIFIED: DraftPublishability.php:132-134]. Update and cancellation drafts need their own actions, and the Approve header action must be hidden for them.
- `DraftWriter::create` hard-codes `'kind' => DraftKind::New` and `derive()` passes `[]` as source values to `CategoryAssigner` [VERIFIED: app/Review/DraftWriter.php create(), derive()]. Phase 3 adds `createFromSource()` / `replacePendingFromSource()` that accept kind, source values (ICS `CATEGORIES`) and links.
- `ReviewStatus::PendingReview => [Approved, Rejected, Duplicate, Superseded]`, `Rejected => [PendingReview]` [VERIFIED: app/Enums/ReviewStatus.php:28-31]. `ReopenDraft` could revive an ignored update draft whose baseline has since moved, so Reopen must refuse non-`new` drafts.
- `EventOrigin` has `Discovered = 'discovered'` (not "source") [VERIFIED: app/Enums/EventOrigin.php:11]. CONTEXT's "origin `source`" means `discovered`.

### F10 — Scope notes (no conflict)
- **D-01 test-run preview delivers SRC-02**, which REQUIREMENTS maps to Phase 4 (`| SRC-02 | Phase 4 | Pending |`, REQUIREMENTS.md:220). D-01 locks it into Phase 3 for ICS. Mark SRC-02 as "partially delivered in Phase 3 (ICS)" in the plan so Phase 4 extends rather than rebuilds it.
- SUMMARY lists "excerpt ≤200 chars + PII strip" under Phase 4, but PIPE-09 and D-15 put it in Phase 3. Build `ExcerptBuilder` now.
- SRC-01 fields `location_mode` and `year_policy` matter only to Phase 4 extractors. Create the columns now (requirement is Phase 3) with safe defaults (`local`, `none`).
- The Attleborough Primary fixture is still pending (STATE.md: capture "during Phase 3 execution, before the first inge…"). It is not an ICS source. `RobotsPolicy` must still handle its `Crawl-delay: 12000` (Pitfall 7).
- STATE.md: "Crawling needs a real User-Agent contact/about URL; until Phase 5 is live use a config placeholder (e.g. CRAWLER_CONTACT_URL …)". No crawler config exists yet (`config/site.php` has no UA keys) [VERIFIED: grep].

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Scheduling due sources, run locking | API / Backend (scheduler + database queue) | Database (partial unique index, row locks) | No Redis. The DB constraint is the source of truth for "never two runs" |
| Polite fetching (robots, throttle, SSRF, limits) | API / Backend (`App\Ingestion\Http`) | Database (`crawl_hosts` robots cache + next slot) | Must be shared by all queue workers, so state lives in PG, not in process memory |
| ICS parsing, timezone resolution, recurrence | API / Backend (`App\Ingestion\Ics`, sabre) | — | Pure functions over bytes. Fixture-testable offline |
| Change detection, baseline, missing items | API / Backend (`App\Ingestion\Pipeline`) | Database (`source_items`) | Decisions compare normalised payloads persisted per item |
| Duplicate detection | Database (pg_trgm `similarity()`) | API / Backend (`DuplicateDetector` query object) | Similarity is computed in SQL with bound parameters |
| Draft creation | API / Backend (`DraftWriter`, single writer) | — | Existing choke point (Phase 2) |
| Publishing (apply, cancel, duplicate link) | API / Backend (`App\Publishing\Actions`) | Database (transactions, FOR UPDATE) | Project rule: the only writer of events/occurrences |
| Review UI (diff, duplicates, urgent sort, sources, runs, preview) | Filament admin (Livewire) | — | Admin only. Livewire is allowed in the panel |
| Attribution and excerpt display | Frontend Server (Blade SSR) | — | Public site is Blade-only, no Livewire, no JS |
| Raw body retention | Storage (local disk `storage/app/private`) | Scheduler (prune) | Bodies are debug artefacts, not DB data |

## Standard Stack

### Core (all already installed — no new packages)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| sabre/vobject | 5.0.0 | ICS parsing, VTIMEZONE resolution, `EventIterator` | Project rule: only recurrence engine. Behaviour probed this session [VERIFIED: composer show] |
| laravel/framework | 13.37.0 | `Http` client (Guzzle), queue attributes (`Tries`, `Timeout`, `UniqueFor`, `MaxExceptions`, `Backoff`), `WithoutOverlapping`, `Sleep`, scheduler | `Http::fake()` testability. `Sleep::fake()` for throttle tests [VERIFIED: vendor/laravel/framework/src/Illuminate/Queue/Attributes/, Support/Sleep.php] |
| guzzlehttp/guzzle | 8.2.0 | Transport under `Http`. `curl` option for `CURLOPT_RESOLVE` pinning, `allow_redirects`, `on_stats` | Installed transitively [VERIFIED: composer show; vendor/guzzlehttp/guzzle/src/RequestOptions.php] |
| league/uri (+components) | 7.9.0 | Canonical URL normalisation (lower-case host, drop default port/fragment, remove `utm_*`/`fbclid`/`gclid`, sort query) | Installed transitively. RFC 3986 compliant [VERIFIED: composer show] |
| PostgreSQL pg_trgm | 1.6 | `similarity()` for duplicate titles/venues | Extension already created by `2026_10_16_000100_enable_pg_trgm.php` ("pg_trgm is first used in Phase 3 (fuzzy dedupe)") [VERIFIED: migration line 9; `select extversion` → 1.6] |
| filament/filament | 5.10.2 | `SourceResource`, relation manager, `ViewEntry`, actions, `->poll()` | Existing admin. [VERIFIED: composer show; vendor/filament/tables/src/Table/Concerns/CanPollRecords.php:11] |

### Supporting (in-repo, reuse)
| Asset | Use in Phase 3 |
|-------|----------------|
| `App\Review\CategoryAssigner::titleContainsKeyword()` / `normalise()` (public static) | D-04 title rules (whole-word, NFC, case-insensitive) and title normalisation for hashes |
| `App\Review\VenueMatcher` | LOCATION → venue suggestion, and the bound-parameter pg_trgm pattern |
| `App\Support\TextLimit::graphemes(string $text, int $max, string $end = '…', bool $collapse = true)` | 200-grapheme excerpt. **The ellipsis counts inside `$max`** ("cut to $max minus the length of $end") [VERIFIED: app/Support/TextLimit.php:14-20] |
| `App\Support\SafeLinks::http(?string $url): ?array{url, host}` | "Read more" and attribution links (D-16) [VERIFIED: SafeLinks.php:15] |
| `App\Publishing\Actions\{UpdatePublishedEvent, ChangeOccurrenceStatus, ChangeEventStatus}` | Apply update / confirm cancellation. Signatures `__invoke(Event $event, PublishableEventData $data, User $editor)`, `__invoke(EventOccurrence $occurrence, OccurrenceStatus $to, User $actor)`, `__invoke(Event $event, EventStatus $to, User $actor)` [VERIFIED] |
| `PublishableEventData::fromEvent(Event): array` + `fromForm(array)` | Build "published now + applied changes" for `ApplySourceUpdate` [VERIFIED: PublishableEventData.php:161,274] |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| In-house `RobotsPolicy` | `spatie/robots-txt` 2.5.x | Not installed (needs owner approval). It deviates from RFC 9309 (details in the Audit below). Latest 2.5.5 was published 2026-10-17, the day of this research |
| DB row-lock host throttle | `Cache::lock` on the database store | Works, but loses the slot reservation if the cache is cleared. A `crawl_hosts` table also stores robots and crawl-delay, and is visible for debugging |
| Manual redirect loop | Guzzle `allow_redirects` + `on_redirect` | Laravel's fake stub handler short-circuits the stack, so redirect callbacks and curl options never run under `Http::fake()` (see Pitfall 9). A manual loop re-runs SSRF, robots and throttle on every hop and is testable |

**Installation:** none.

## Package Legitimacy Audit

This phase installs **no** external packages. One package was evaluated and rejected:

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| spatie/robots-txt | Packagist | first release years ago; latest 2.5.5 published 2026-10-17T08:57Z | 22.0M total, 1.15M/month | github.com/spatie/robots-txt | not run (gsd seam supports only npm/pypi/crates) | **Not used.** Source read at tag 2.5.4: `allows()` merges the `*` group into specific groups by default (`includeGlobalGroup`), but RFC 9309 says the `*` group applies only "If no matching group exists". Allow weighting uses `str_starts_with` (no wildcards). `pathIsExplicitAllowed($url, …)` is passed the full URL, not the path. Lines are split on `PHP_EOL`. `readFrom()` fetches with `file_get_contents` (would bypass the fetcher and SSRF guard) [VERIFIED: raw.githubusercontent.com/spatie/robots-txt/2.5.4/src/RobotsTxt.php lines 47-55, 79-135, 182-193, 283-285; RFC 9309 §2.2.1 fetched this session] |

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
 Scheduler (cron schedule:run)            Admin (Filament)
   │ every 10 min                           │ "Run now" / "Test run" / "Create drafts"
   ▼                                        ▼
 ingest:dispatch-due ──(active, not unhealthy, next_check_at<=now)──► create source_runs row (queued)
   │                                        │   ▲ partial unique index: one queued/running run per source
   └──────────────► FetchSourceJob(run_id) ◄┘   │ (insert fails → "already running")
                         │
                         ▼
               ┌──────────────────── HttpFetcher ─────────────────────┐
               │ UrlPolicy (http/https, port 80/443, no userinfo)       │
               │ SsrfGuard: resolve → all IPs global? → pin CURLOPT_RESOLVE
               │ RobotsPolicy (crawl_hosts cache ≤24h; RFC 9309)        │──disallow──► run=blocked (health untouched)
               │ HostThrottle (crawl_hosts.next_allowed_at FOR UPDATE)  │──wait too long──► job release(wait)
               │ GET (UA, timeouts, If-None-Match/If-Modified-Since)    │──304──► run=not_modified
               │ stream body ≤ max_bytes; manual redirects (≤5, re-check each hop)
               └──────────────────────────────┬────────────────────────┘
                     network/5xx/429/too large │ ok 200
                     ──► run=failed(kind) ◄────┤
                                               ▼
                                   BodyValidator (BEGIN:VCALENDAR) ──invalid──► run=failed(content_invalid)
                                               │ store gzip raw body (storage/app/private/ingest/raw)
                                               ▼
                                   IcsExtractor (sabre) → ExtractedItem[] (one per identity)
                                       │ per item: parse errors kept on the item, never fatal for the run
                                       ▼
                     ┌──────── preview mode? ── yes ──► classify only → source_runs.preview jsonb (no writes)
                     ▼ no
            SourceItemRecorder (one transaction per item)
              WindowClassifier: past / >12 months → counters, no draft
              TitleRules (D-04) skip/keep → counter
              raw_hash unchanged → touch last_seen, done
              content_hash vs accepted baseline → ChangeVerdict{new|update|cancel|quiet|none}
              DuplicateDetector (new only) → warning + candidates (minus "not duplicate" decisions)
              ExcerptBuilder → DraftWriter (create / replace pending in place)
                                               │
            after all items: MissingItemDetector (complete live runs only) → cancellation drafts
            SourceHealthTracker → sources.health / consecutive_failures / structure_alert
                                               ▼
                                  event_drafts (pending review)
                                               │ Admin decides (Filament review page)
                                               ▼
     App\Publishing\Actions: ApproveDraft(+LinkEventSource) │ ApplySourceUpdate │ IgnoreSourceChange
                             ConfirmSourceCancellation │ MarkDraftDuplicate │ RecordNotDuplicate
                                               ▼
                    events / event_occurrences / event_sources ──► public Blade pages (attribution, excerpt)
```

### Recommended Project Structure
```
app/
├── Ingestion/
│   ├── Http/            # HttpFetcher, FetchRequest, FetchResult, RobotsPolicy, RobotsRules,
│   │                    # HostThrottle, SsrfGuard, HostResolver (interface + DnsHostResolver),
│   │                    # BodyValidator, FetchFailure (enum kinds), CanonicalUrl
│   ├── Ics/             # IcsExtractor, IcsTimezoneResolver, IcsIdentity, IcsItemNormaliser
│   ├── Data/            # ExtractedItem, ExtractionResult, NormalisedFacts, ChangeVerdict, DuplicateMatch
│   ├── Pipeline/        # SourceItemRecorder, WindowClassifier, TitleRules, ChangeDetector,
│   │                    # MissingItemDetector, DuplicateDetector, ExcerptBuilder, ContentHasher
│   ├── Health/          # SourceHealthTracker
│   ├── ExtractorRegistry.php, Extractor.php (interface)
│   └── RawBodyStore.php
├── Jobs/                # FetchSourceJob (thin)
├── Console/Commands/    # IngestDispatchDue, IngestPruneRaw, IngestReprocess (optional)
├── Publishing/Actions/  # + LinkEventSource, ApplySourceUpdate, IgnoreSourceChange,
│                        #   ConfirmSourceCancellation, MarkDraftDuplicate, RecordNotDuplicate,
│                        #   ApproveAllLikeThis (loops ApproveDraft)
├── Enums/               # + SourceHealth, RunStatus, RunMode, RunTrigger, FetchFailureKind,
│                        #   IdentityStrategy, SourceItemStatus, TitleRuleMode; ExtractionMethod::Ics
├── Models/              # + Source, SourceRun, SourceItem, EventSource, DuplicateDecision, CrawlHost
└── Filament/Resources/Sources/{SourceResource, Schemas/, Tables/, Pages/, RelationManagers/RunsRelationManager}
config/ingest.php        # user_agent, contact_url, min_host_delay=5, max_inline_wait, max_bytes, timeouts,
                         # window_months=12, health thresholds 3/5, duplicate thresholds, raw retention
database/seeders/SourceSeeder.php   # D-05 firstOrCreate, inactive
tests/Fixtures/ics/      # SYNTHETIC: rrule-exdate-override, cancelled-override, floating, unknown-tzid,
                         # greenwich-standard-time, max-instances, all-day-multi, bot-wall.html, huge
```

### Schema (recommended; new tables and alterations)

All instants are `timestampTz`. Status-like strings get a PG `CHECK` constraint mirroring the enum (the existing project pattern, e.g. `event_drafts_kind_check`).

**`sources`**
| Column | Type | Notes |
|---|---|---|
| id, name (120), slug (unique), short_name (40) | | `short_name` for the card "via {short}" (D-14) |
| url (2048) | | Feed URL. Validated http/https, no userinfo, port 80/443 |
| events_page_url (2048, null) | | D-16 fallback for "Read more" |
| extraction_method (16) | CHECK `('ics')` | Phase 4 widens. `ExtractionMethod::Ics` |
| adapter (32, null), parser_config jsonb `'{}'` | | `{"identity":"uid|tec_post_id|summary_start"}` |
| default_category_id, default_venue_id (null FKs, restrictOnDelete) | | CategoryAssigner link 1. Venue default (Open Question 4) |
| location_mode (16) default `local` CHECK (`local`,`filter`) | | SRC-01. Used in Phase 4 |
| default_timezone (64) default `Europe/London` | | EXT-07 floating times |
| year_policy (16) default `none` | | SRC-01. Phase 4 |
| legal_basis text, notes text | | From docs/research/event-sources.md |
| supports_missing_detection bool default false | | PIPE-08 |
| is_active bool default false | | D-01/D-05 |
| check_frequency (16) default `daily` CHECK (`daily`,`weekly`) ; check_time time default `05:30` | | D-02 |
| next_check_at, last_run_at, last_success_at | | |
| title_rules jsonb `'[]'` | | `[{mode: skip|keep, keyword}]` (D-04) |
| health (16) default `healthy` CHECK (`healthy`,`degraded`,`unhealthy`), consecutive_failures smallint 0 | | SRC-08 |
| last_items_found int null, structure_alert_at null | | Zero-after-results flag |
| etag (255) null, last_modified (64) null | | Conditional GET |
| timestamps | | |

**`source_runs`**
| Column | Notes |
|---|---|
| id, source_id (cascade) | |
| mode (`live`,`preview`), trigger (`schedule`,`manual`,`activation`) | D-01 preview never writes items or drafts |
| status (`queued`,`running`,`succeeded`,`not_modified`,`failed`,`blocked`) | |
| failure_kind null (`network`,`timeout`,`http_status`,`rate_limited`,`too_large`,`content_invalid`,`parse_error`,`ssrf_blocked`,`abandoned`) | CHECK: `failure_kind IS NULL OR status='failed'` |
| http_status, final_url, bytes, duration_ms, raw_path, raw_sha256 | |
| items_found, items_new, items_changed, items_unchanged, items_cancelled, items_missing | SRC-07 |
| skipped_past, skipped_far_future, skipped_rule, skipped_parse_error, skipped_out_of_area (0 in Phase 3) | |
| drafts_created, drafts_replaced | |
| observed_from date, observed_until date, is_complete bool, structure_alert bool | PIPE-08, SRC-08 |
| error_message (500), preview jsonb null, queued_at, started_at, finished_at | No response bodies in `error_message` |
| **`CREATE UNIQUE INDEX source_runs_one_active ON source_runs (source_id) WHERE status IN ('queued','running')`** | SRC-04 guarantee independent of the cache |

**`source_items`**
| Column | Notes |
|---|---|
| id, source_id (cascade), external_id (255) | UNIQUE (source_id, external_id) |
| status (`active`,`cancelled`,`missing`) | `cancelled` = STATUS:CANCELLED at source |
| raw_fragment text, raw_hash char(64), content_hash char(64), payload jsonb | Payload = normalised facts + description + url + categories + organiser (internal only, D-17) |
| accepted_hash char(64) null, accepted_payload jsonb null | Baseline. Set on approve / apply / ignore / mark duplicate / quiet change |
| review_outcome null (`pending`,`approved`,`rejected`,`duplicate`) | Suppresses re-drafting rejected items (Open Question 5) |
| current_draft_id FK event_drafts **nullOnDelete** | `EventDraft` is `MassPrunable` (no model events), so the FK action must live in the DB |
| last_known_start_at, first_seen_run_id, last_seen_run_id, first_seen_at, last_seen_at, missing_runs smallint 0, parse_errors jsonb `'[]'` | |

**`event_sources`** (REV-05, D-10..D-12, LEG-04)
| Column | Notes |
|---|---|
| id, event_id (cascade), source_id (restrict), source_item_id (unique, nullOnDelete) | |
| is_primary bool, linked_via (`approved`,`duplicate`), item_url (2048, null), linked_by, linked_at | Partial UNIQUE (event_id) WHERE is_primary |

**`duplicate_decisions`** (D-13 memory)
| Column | Notes |
|---|---|
| id, source_item_id (cascade, null), draft_id (cascade, null) | Subject: ingested item (stable across re-drafts) or a non-ingested draft |
| candidate_event_id (cascade, null), candidate_source_item_id (cascade, null), candidate_draft_id (cascade, null) | Exactly one candidate (CHECK num_nonnulls(...) = 1) |
| decision (`not_duplicate`,`duplicate`), decided_by, decided_at | Unique on (subject, candidate) |

**`crawl_hosts`** (shared fetch state)
| Column | Notes |
|---|---|
| host (255, PK, lower-case, port appended when non-default) | |
| next_allowed_at timestampTz | Throttle slot |
| robots_status (`rules`,`allow_all`,`disallow_all`,`blocked`), robots_body text (≤ 512 KiB), robots_fetched_at, crawl_delay_seconds int null | RFC 9309 §2.4: ≤24h cache. §2.5: ≥500 KiB parse limit |

**`event_drafts` additions:** `source_id` (FK, nullOnDelete), `source_item_id` (FK, nullOnDelete), `target_recurrence_key` (32, null), `diff` jsonb null, `relevant_starts_at` timestampTz null (D-09), `duplicate_of_draft_id` (FK self, nullOnDelete), `duplicate_reason` (16, null: `url`,`fuzzy`), `duplicate_score` numeric(4,3) null, `excluded_dates` jsonb `'[]'` (if F1 option chosen). Widen `event_drafts_extraction_method_check` to include `'ics'`. **`CREATE UNIQUE INDEX event_drafts_one_pending_per_item ON event_drafts (source_item_id) WHERE review_status = 'pending_review'`** (PIPE-06).

**`events` addition (if F1 option chosen):** `excluded_dates` jsonb `'[]'`, honoured by `RecurrenceExpander` as EXDATE values.

**`category_mappings`:** add nullable `source_id`. Replace `category_mappings_source_value_unique ON category_mappings (lower(source_value))` [VERIFIED: create_category_mappings_table.php:30] with `UNIQUE (coalesce(source_id, 0), lower(source_value))`. `CategoryAssigner::matchMapping` prefers source-specific mappings, then global ones.

### State machines

**Run status** (`RunStatus`): `queued → running → {succeeded | not_modified | failed | blocked}`. Terminal states are final. A janitor in `ingest:dispatch-due` marks `running` runs older than 15 min (and `queued` older than 2 h) as `failed/abandoned`, so a killed worker never leaves the partial unique index permanently occupied.

**Source health** (`SourceHealth`), updated only by live runs:
| Run outcome | consecutive_failures | health |
|---|---|---|
| succeeded / not_modified | reset to 0 | `healthy` |
| failed (any kind) | +1 | ≥3 → `degraded`, ≥5 → `unhealthy` (scheduler skips it, "Run now" still allowed) |
| blocked (robots) | unchanged | unchanged. Source shows a "Blocked by robots.txt" badge (D20) |
| succeeded with 0 items after a previous run with >0 | reset | `healthy`, plus `structure_alert_at` set and `is_complete=false` (no missing counts) |
Preview runs never change health.

**Source item lifecycle** (`SourceItemStatus` + `review_outcome`):
- First sighting in window → `active`, `review_outcome=pending`, new draft.
- Seen again, `raw_hash` same → touch `last_seen_*`, reset `missing_runs`.
- `content_hash` differs: if a pending draft exists, replace it in place. If the item is linked (approved/duplicate) and the change touches D-06 facts, create an update draft. If only quiet fields changed (description/url), update `payload` and baseline quietly (D-06).
- STATUS:CANCELLED at master → `cancelled` + cancellation draft (whole event). On an override → cancellation draft scoped to `target_recurrence_key`.
- Absent from a complete live run, inside the observed window, future start, source supports missing detection → `missing_runs++`. At 2 → `missing` + cancellation draft. Reappears → `active`, `missing_runs=0`, and any pending cancellation draft from missing detection becomes `superseded`.
- Source now equals baseline again while an update draft is pending → that draft becomes `superseded`.

**Draft decisions for ingestion kinds:**
| Draft kind | Admin action | Draft review_status | Item baseline | Published effect |
|---|---|---|---|---|
| new | Approve | approved (event_id set) | = payload at approval | `PublishEvent` + `LinkEventSource(primary)` |
| new | Mark as duplicate | duplicate (`duplicate_of_event_id`) | = payload | `event_sources` link (not primary if one exists, D-12) |
| new | Reject | rejected | = payload, `review_outcome=rejected` | none |
| update | Apply | approved (event_id = target) | = source now | `UpdatePublishedEvent` with changed fields only |
| update | Ignore | rejected (`reject_reason='ignored'`, new enum case) | = source now | none |
| cancellation | Confirm | approved | = source now | `ChangeOccurrenceStatus(Cancelled)` or `ChangeEventStatus(Cancelled)` |
| cancellation | Ignore | rejected (`ignored`) | = source now (status kept as observed) | none |

### Pattern 1: HttpFetcher — one polite path for every URL
**What:** A single service the jobs call with `FetchRequest{url, conditional headers?, mode}`. It returns `FetchResult{status, body, headers, finalUrl, bytes, ms}` or a typed `FetchFailure`. It never throws for source problems. Exceptions are for bugs only (ARCH anti-pattern 2).
**Order of checks for each hop (initial URL and every redirect target, at most 5 hops):**
1. `UrlPolicy`: scheme http/https, no userinfo, port null/80/443, host present. A failure → `ssrf_blocked`.
2. `SsrfGuard`: resolve A + AAAA through an injectable `HostResolver`. **Every** address must pass, else `ssrf_blocked`. Pin the chosen IP with `CURLOPT_RESOLVE` so curl cannot re-resolve to another address (DNS rebinding).
3. `RobotsPolicy::check(url)`: `disallow` → return `blocked` (the run becomes `blocked`).
4. `HostThrottle::reserve(host, max(5, crawl_delay))`: returns the seconds to wait. If wait > `config('ingest.max_inline_wait')` (20 s), throw `HostBusy($wait)`. The job catches it and calls `$this->release($wait)`.
5. GET with `withoutRedirecting()`, `connectTimeout(10)`, `timeout(30)`, UA header, `Accept: text/calendar, text/plain;q=0.5, */*;q=0.1`, conditional headers (live runs only, after a complete previous run), `withOptions(['stream' => true, 'curl' => [CURLOPT_RESOLVE => [...]], 'decode_content' => true])`.
6. 3xx with `Location` → resolve against the current URL (league/uri) and loop. 304 → `not_modified`. 429/503 → `rate_limited`: honour `Retry-After` (seconds or HTTP date, capped at 24 h) by pushing `crawl_hosts.next_allowed_at` and `sources.next_check_at`. Other ≥400 → `http_status`.
7. Read the body stream in 64 KiB chunks. Abort at `max_bytes + 1` → `too_large`. Also reject early when `Content-Length > max_bytes`.

```php
// SsrfGuard: verified PHP 8.4 behaviour this session. FILTER_FLAG_GLOBAL_RANGE rejects
// 10/8, 127/8, 169.254/16, 100.64/10, 0/8, 192.0.0/24, 198.18/15, ::1, fe80::/10, fc00::/7,
// ::ffff:127.0.0.1, 2001:db8::/32, but ACCEPTS 224.0.0.1 (multicast) and 64:ff9b::7f00:1
// (NAT64-embedded 127.0.0.1). Block those explicitly.
public function isPublic(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
        return false;
    }

    $packed = inet_pton($ip);

    if (strlen($packed) === 4) {
        return (ord($packed[0]) & 0xF0) !== 0xE0; // 224.0.0.0/4 multicast
    }

    return ! str_starts_with(bin2hex($packed), '0064ff9b')  // 64:ff9b::/96 NAT64
        && ! str_starts_with(bin2hex($packed), 'ff');       // ff00::/8 multicast
}
```

```php
// HostThrottle: reserve a slot atomically across workers, sleep OUTSIDE the transaction.
public function reserve(string $host, int $delaySeconds): int
{
    return DB::transaction(function () use ($host, $delaySeconds): int {
        $now = CarbonImmutable::now()->utc();
        DB::table('crawl_hosts')->insertOrIgnore(['host' => $host, 'next_allowed_at' => $now->format('Y-m-d H:i:s')]);
        $row = DB::table('crawl_hosts')->where('host', $host)->lockForUpdate()->first();
        $slot = CarbonImmutable::parse($row->next_allowed_at, 'UTC')->max($now);
        DB::table('crawl_hosts')->where('host', $host)
            ->update(['next_allowed_at' => $slot->addSeconds($delaySeconds)->format('Y-m-d H:i:s')]);

        return max(0, $slot->getTimestamp() - $now->getTimestamp());
    });
}
// caller: $wait = $throttle->reserve(...); if ($wait > $max) throw new HostBusy($wait); Sleep::for($wait)->seconds();
```
`Illuminate\Support\Sleep` (`Sleep::for()`, `Sleep::fake()`, `Sleep::assertSlept()`) [VERIFIED: vendor/laravel/framework/src/Illuminate/Support/Sleep.php:95,389,405] keeps tests instant. Note that `reserve()` books the slot *before* the request, so a later `robots.txt` fetch and the feed fetch on the same host are spaced correctly.

**User-Agent:** `AttleboroughEventsBot/1.0 (+{config('ingest.contact_url')})`. The product token `AttleboroughEventsBot` uses only letters, as RFC 9309 §2.2.1 requires ("MUST contain only uppercase and lowercase letters … underscores … and hyphens") [VERIFIED: RFC 9309 text fetched this session]. `contact_url` comes from `CRAWLER_CONTACT_URL` (placeholder until the Phase 5 about page).

### Pattern 2: RobotsPolicy (in-house, RFC 9309)
- Fetch `{scheme}://{host}/robots.txt` through the same SSRF guard and throttle, follow ≤5 redirects (§2.3.1.2 "SHOULD follow at least five consecutive redirects"), and parse at most 512 KiB (§2.5 "MUST be at least 500 kibibytes").
- 2xx → parse. 4xx → `allow_all` (§2.3.1.3 "the crawler MAY access any resources"), **except 401/403/429 or a challenge marker** (`cf-mitigated` header, "Just a moment", `challenge-platform`): treat these as `blocked`. The project never bypasses bot protection, which is stricter than the RFC. 5xx / network → `disallow_all` (§2.3.1.4 "MUST assume complete disallow"). Cache ≤ 24 h (§2.4).
- Group selection: case-insensitive product-token match. Merge all matching groups. Use `*` **only if no group matches** (§2.2.1). Rule match: longest matching path wins, and allow wins ties (§2.2.2 "most specific match … most octets … allow rule SHOULD be used"). Support `*` and `$`. Percent-encoding normalisation per §2.2.2. `/robots.txt` is always allowed.
- `Crawl-delay` (non-standard, not in RFC 9309): read it from the selected group, clamp it to [0, 86 400], and store it in `crawl_hosts.crawl_delay_seconds`. The effective delay is `max(5, crawl_delay)` (D-02).
- Fixture suite: the real robots rules in each manifest (`Crawl-delay: 10`, `Allow: /Admin/Resources/Folders/Calendar.ics`, `Disallow: /Admin/`), plus RFC 9309 §5 examples.

### Pattern 3: IcsExtractor (sabre/vobject 5)
Probe results this session (`/tmp/ics_probe*.php` against real and synthetic ICS):
- Real fixtures parse with `Reader::read($body, Reader::OPTION_FORGIVING)`: Academy 59 VEVENT / 59 UIDs, Baptist 64/64, Town Council 30/30. Baptist `20261025T103000Z` → "Sun 25 Oct 10:30 GMT". Town Council `DTSTART;TZID=Europe/London:20261024T120000` → "Sat 24 Oct 12:00 BST" = 11:00Z, and `…20261025T100000` → 10:00 GMT [VERIFIED].
- `TimeZoneUtil::getTimeZone('Made Up/Zone', null, true)` throws **`InvalidArgumentException`** ("We were unable to determine the correct PHP timezone for tzid: Made Up/Zone"). Without the flag it silently returns `UTC`. `GMT Standard Time` → `Europe/London`. `Greenwich Standard Time` → **`Atlantic/Reykjavik`** [VERIFIED: probe + vendor/sabre/vobject/lib/TimeZoneUtil.php findTimeZone()].
- `Property\ICalendar\DateTime::getDateTimes()` resolves TZID **without** `failIfUncertain` (`TimeZoneUtil::getTimeZone((string) $tzid, $this->root);`) [VERIFIED: vendor/sabre/vobject/lib/Property/ICalendar/DateTime.php:158-160]. The extractor must therefore pre-validate every TZID in DTSTART/DTEND/EXDATE/RDATE/RECURRENCE-ID itself.
- Floating `DTSTART:20261025T103000` → `isFloating()=true`. `getDateTime($london)` = 10:30 GMT, `getDateTime()` = 10:30 **UTC** [VERIFIED].
- All-day `VALUE=DATE` 20261114 → 20261115: `hasTime()=false`. DTEND is exclusive, so the inclusive end date = DTEND − 1 day [VERIFIED].
- `EventIterator($cal, 's1', $london)` on RRULE COUNT=6 + EXDATE 21 Oct + override 28 Oct (19:00 → 19:30) + override 4 Nov `STATUS:CANCELLED` yields: 14 Oct 19:00 BST, **28 Oct 19:30 GMT** (moved), **4 Nov 19:00 with STATUS=CANCELLED** (cancelled overrides are *yielded*, not skipped), 11 Nov, 18 Nov. 21 Oct is excluded. Every yielded object carries `RECURRENCE-ID`, even master instances [VERIFIED]. To detect overrides, collect `getByUID($uid)` components that have `RECURRENCE-ID` *before* iterating, keyed by the London local date of the RECURRENCE-ID.
- `Settings::$maxRecurrences` is a global static (default `3500`) [VERIFIED: Settings.php:54]. An exhausted rule throws `Sabre\VObject\Recur\MaxInstancesExceededException` [VERIFIED: probe]. Catch it (and `NoInstancesException`, `InvalidDataException`, `InvalidArgumentException`) **per item** and turn it into an item parse error. Do **not** lower the global setting (it would also affect `RecurrenceExpander`). Instead stop iterating at the 12-month window end with your own instance cap.

```php
// Per identity group (master + overrides). $tz = new DateTimeZone($source->default_timezone)
foreach ([$master->DTSTART, $master->DTEND, ...$master->select('EXDATE'), ...$master->select('RDATE')] as $prop) {
    if ($prop !== null && isset($prop['TZID'])) {
        TimeZoneUtil::getTimeZone((string) $prop['TZID'], $calendar, true); // throws → parse error
    }
}
$iterator = new EventIterator($calendar->getByUID($uid), null, $tz);  // array input = this group only
$iterator->fastForward($windowStartLondon->subSecond());               // same trick as RecurrenceExpander
while ($iterator->valid() && $iterator->getDtStart() < $windowEndLondon && ++$n <= self::MAX_INSTANCES) {
    $obj = $iterator->getEventObject();
    $key = $iterator->getDtStart()->setTimezone($tz)->format('Y-m-d');   // recurrence_key semantics
    $cancelled = strtoupper((string) ($obj->STATUS ?? '')) === 'CANCELLED';
    // collect instance {key, startUtc, endUtc|null, allDay, cancelled, overridden}
    $iterator->next();
}
```
Note: `EventIterator::__construct($input, ?string $uid = null, ?\DateTimeZone $timeZone = null)` accepts an array of VEVENTs [VERIFIED: vendor/sabre/vobject/lib/Recur/EventIterator.php:92-113]. Passing the identity group avoids `getByUID` collisions under the `summary_start` and `tec_post_id` strategies.

**Item facts written to `payload` (and the draft):** title (SUMMARY, unescaped, HTML-stripped, entities decoded, NFC, whitespace collapsed), all_day, start_date/start_time/end_date/end_time in **London local** (draft and event schema), timezone `Europe/London`, venue_name = LOCATION (first line; split the address after the first comma into `venue_address`; extract the postcode with a UK regex), url = URL, categories = CATEGORIES (→ `CategoryAssigner` source values), description (full, internal), organiser (internal only, D-17), status, rrule/excluded_dates/overrides (series), next_instance_start (UTC). A timed event without DTEND has **zero duration** in RFC 5545, so leave end null and let the existing `no_end_expiry_hours` rule apply.

### Pattern 4: Two hashes and the accepted baseline (D-06, PIPE-06)
- `raw_hash = sha256(unfolded fragment with DTSTAMP, LAST-MODIFIED, CREATED, SEQUENCE lines removed, plus the UID line when identity ≠ uid)`. The Academy fixture's `DTSTAMP:20261017T183431Z` is the fetch time and its UID embeds the request time [VERIFIED: fixture], so without stripping, every run would look changed.
- `content_hash = sha256(json_encode(FactSet, JSON_UNESCAPED_UNICODE))` over the D-06 facts only, in a fixed key order: `title_norm, all_day, start_date, start_time, end_date, end_time, venue_norm, price_type, family_friendly, cancelled`. For series add `rrule, excluded_dates (sorted), overrides (sorted by key: {start,end,cancelled,title_norm})`. **Never** include description, URL, categories, organiser, DTSTAMP or the rolling instance list.
- `ChangeDetector::detect(item, newFacts)`:
  - No baseline + no pending draft → `new`
  - Pending draft → `replace` (in place)
  - `newHash === accepted_hash` → `none` (if only quiet fields changed, update payload + `accepted_payload` quietly)
  - Cancelled newly true → `cancel`
  - Else → `update` with `diff = {field: {published, before: accepted_payload[field], now: new[field]}}` for each changed D-06 field
- Price and family-friendly are not ICS properties, so in Phase 3 they stay `unknown`/`false`. They remain in the hash so Phase 4 extractors share it.

### Pattern 5: Missing-item detection (PIPE-08, research D3)
Run after all items of a **live, succeeded, complete** run. `is_complete` = body valid AND `items_found > 0` AND `items_found >= 50% of previous complete run`. `observed_from` = the run's London date. `observed_until` = the max London start date among items in this run (TEC iCal's "next 30 events" export spans only 2026-10-17 → 2026-10-27 [VERIFIED: manifest window]).
```sql
-- candidates: active items of this source not seen in this run, future, inside the observed window
UPDATE source_items SET missing_runs = missing_runs + 1
WHERE source_id = ? AND status = 'active' AND last_seen_run_id <> ?
  AND last_known_start_at > now() AND last_known_start_at < ((?::date + 1)::timestamp AT TIME ZONE 'Europe/London')
```
At `missing_runs = 2` → `status = 'missing'` + a cancellation draft (only if the item is linked to an event, or has a pending new draft, which is then `superseded` instead). Not-modified, failed, blocked, preview and incomplete runs never touch the counters (SRC-09).

### Pattern 6: DuplicateDetector (PIPE-05, D-13)
Runs for `new` verdicts only. Compare at **occurrence level** against published events and against pending drafts from *other* sources or other origins:
1. **Canonical URL:** `CanonicalUrl::of($item->url)` equals the canonical `events.source_url` / `event_sources.item_url` / `event_drafts.source_url` → reason `url`, score 1.0. Canonicalise with league/uri: lower-case scheme/host, strip `www.`, drop fragment and default port, remove `utm_*`, `fbclid`, `gclid`, `mc_*`, sort the query, strip a trailing slash.
2. **Fuzzy** (bound parameters only):
```sql
SELECT e.id, o.recurrence_key, similarity(lower(e.title), lower(:title)) AS title_score
FROM event_occurrences o JOIN events e ON e.id = o.event_id
LEFT JOIN venues v ON v.id = e.venue_id
WHERE o.recurrence_key = :london_date                       -- same London date
  AND o.status <> 'hidden' AND e.status <> 'hidden'
  AND (:all_day OR abs(extract(epoch FROM (o.starts_at - :starts_at_utc))) <= 3600)
  AND (e.venue_id = :venue_id OR similarity(lower(v.name), lower(:venue_name)) >= :venue_threshold)
  AND similarity(lower(e.title), lower(:title)) >= :title_threshold
  AND NOT EXISTS (SELECT 1 FROM duplicate_decisions d WHERE d.source_item_id = :item_id
                  AND d.candidate_event_id = e.id AND d.decision = 'not_duplicate')
ORDER BY title_score DESC, e.id LIMIT 3
```
The same shape runs against pending `event_drafts` (`start_date = :london_date`, `source_id IS DISTINCT FROM :source_id`). Store the best match in `duplicate_of_event_id` / `duplicate_of_draft_id` + reason + score, so the existing bulk skip `'duplicate'` keeps working (extend `bulkBlockerKeys` to `duplicate_of_draft_id`). Defaults: `title_threshold 0.3`, `venue_threshold 0.3` (= `config('review.venue_similarity_threshold')`). Keep them in `config/ingest.php` and tune them with the fixture test in Pitfall 6.
Volumes are tiny (hundreds of events), so no trigram index is needed. If one is added later, use `CREATE INDEX … USING gin (lower(title) gin_trgm_ops)`. Do not rely on the `%` operator: it uses the session `pg_trgm.similarity_threshold` (ARCH anti-pattern 9).

### Pattern 7: Jobs and scheduling (SRC-03, SRC-04, D-02)
```php
// routes/console.php (London times avoid the 01:00-02:00 DST hour, like the existing entries)
Schedule::command('ingest:dispatch-due')->everyTenMinutes()->withoutOverlapping();
Schedule::command('ingest:prune-raw')->dailyAt('03:50')->timezone('Europe/London')->withoutOverlapping();
```
- `ingest:dispatch-due`: run the janitor (abandoned runs), then for each `is_active AND health <> 'unhealthy' AND next_check_at <= now()` source, insert a `queued` run (catch the unique violation → skip), set `next_check_at` to the next `check_time` London date + deterministic jitter (`source_id * 7 % 20` minutes), and dispatch `FetchSourceJob($run->id)`. `next_check_at` is computed on London `CarbonImmutable` then `->utc()`.
- `FetchSourceJob` (thin): `implements ShouldQueue, ShouldBeUnique`, `uniqueId()` = source id, `#[UniqueFor(900)]`, `#[Timeout(75)]` (< `retry_after` 90), `#[MaxExceptions(1)]`, `retryUntil()` = now + 6 h (throttle releases). Constructor takes the **run id** (not models: no stale serialised state). `handle()` marks the run `running` (guarded `UPDATE … WHERE status='queued'`, so 0 rows → return), calls `IngestRun::execute($run)`, catches `HostBusy` → `release($wait)` (status back to `queued`). Any other `Throwable` → run `failed/abandoned` + rethrow (counts as a real failure).
- **"Run now"** (Filament header action on the source page) does the same insert + dispatch with `trigger=manual`. If the insert hits the unique index, show the notification "A run is already queued or running." Never fetch inside the HTTP request: crawl delays (10 s) plus timeouts exceed PHP-FPM limits.
- The concurrency guarantee comes from the partial unique index. `ShouldBeUnique` and `withoutOverlapping()` only reduce noise. Both use the database cache store's `cache_locks` table [VERIFIED: config/cache.php:18 default `database`; 0001_01_01_000001_create_cache_table.php:20].

### Pattern 8: D-01 test-run preview without writes
- "Test run" creates a run with `mode=preview` and dispatches the same job. `IngestRun` executes fetch → validate → extract → classify (window, title rules, change verdict against existing items, duplicate check, parse errors), but **skips** `SourceItemRecorder` writes, `DraftWriter`, missing detection and health. It stores `preview` jsonb: `{would_create: n, skipped: {past, far_future, rule, parse_error}, rows: [{title, starts, verdict, reason, duplicate?}] (cap 200)}` plus the raw body.
- The source page shows the latest preview run in a section that `->poll('3s')`s while the run is queued/running.
- **"Create drafts"** sets `is_active=true` and queues a live run with `trigger=activation`. Re-processing the preview's stored raw body when it is < 1 hour old avoids a second fetch (Open Question 6). Either way, the run goes through the full pipeline.
- Test that a preview run leaves `source_items`, `event_drafts` and `sources.health` unchanged (assert counts before and after).

### Pattern 9: Applying an update (REV-06, D-07)
```php
// App\Publishing\Actions\ApplySourceUpdate (sketch)
AdminGate::authorize($actor, 'Only the admin can apply updates.');
DB::transaction(function () use ($draft, $actor) {
    $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
    $locked->review_status->assertCanTransitionTo(ReviewStatus::Approved);   // kind must be Update
    $event = Event::query()->whereKey($locked->event_id)->firstOrFail();
    $form = PublishableEventData::fromEvent($event);           // "published now" as form state
    foreach ($locked->diff as $field => $change) { $form = FieldMap::apply($form, $field, $change['now']); }
    ($this->update)($event, PublishableEventData::fromForm($form), $actor);   // re-syncs occurrences
    $item = SourceItem::query()->whereKey($locked->source_item_id)->lockForUpdate()->firstOrFail();
    $item->forceFill(['accepted_hash' => $item->content_hash, 'accepted_payload' => $item->payload])->save();
    $locked->forceFill(['review_status' => ReviewStatus::Approved, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->utc()])->save();
});
```
- `UpdatePublishedEvent` refuses one-off ↔ repeating switches (`TYPE_SWITCH_REFUSED`) and unsupported rules (`UNSUPPORTED_RULE_REFUSED`) [VERIFIED: app/Publishing/Actions/UpdatePublishedEvent.php:45-47,67-75]. Surface these as a blocker on the update draft ("This change can't be applied automatically: …"). Ignore must stay available.
- A **venue** change arrives as text. Apply needs a `venue_id`: use `VenueMatcher::exact()`, else block Apply until the admin picks a venue on the update draft's edit page.
- **Lock order:** `UpdatePublishedEvent` locks `events` first, and so do `ChangeOccurrenceStatus` (parent first) and calendar sync [VERIFIED: ChangeOccurrenceStatus.php:20-22 docblock]. Lock the draft row, then the event (via the inner action), then the source item last, every time, to avoid deadlocks.

### Pattern 10: "Approve all like this" (D-03)
A record action on the review page and table row for `kind = new` pending drafts with a `source_id`. Scope: `review_status = pending_review AND kind = 'new' AND source_id = ? AND lower(title_norm) = ? AND venue_id IS NOT DISTINCT FROM ?`, in `ReviewQueue::applyOrder()` order, capped at e.g. 200. For each draft: `bulkBlockerKeys()` → count the first key, else `ApproveDraft`. Catch `InvalidReviewTransition|ModelNotFoundException` → `already_reviewed` and `DraftNotPublishable` → `blocked` (same as `approveSelected()` [VERIFIED: EventDraftsTable.php:172-205]). Build a `Notification` listing "Approved {n} of {total}" plus `DraftPublishability::skipMessage($key, $count)` lines (F7). The confirmation modal shows the count before approving.

### Pattern 11: D-09 urgent-first queue order
Set `relevant_starts_at` (UTC) on update and cancellation drafts at write time: the target occurrence's `starts_at` (cancellation), or the earlier of the published next occurrence and the new start (update). Prepend to `ReviewQueue::SORT_KEY`:
```php
"(not (kind <> 'new' and relevant_starts_at is not null and relevant_starts_at < now() + interval '48 hours'))"
    => "(not (kind <> 'new' and relevant_starts_at is not null and relevant_starts_at < now() + interval '48 hours'))",
```
and extend `qualify()`'s regex to `start_date|start_time|created_at|id|kind|relevant_starts_at` (F6). `now()` is the transaction timestamp in PostgreSQL, so the outer and inner (keyset) expressions agree within one statement. Show the "Urgent" badge with the same predicate (single source of truth: a static method returning the SQL plus a PHP mirror for the badge, with a unit test that the two agree).

### Pattern 12: Excerpt and attribution (PIPE-09, LEG-04, D-14..D-17)
```php
// ExcerptBuilder::make(?string $full): string
$text = html_entity_decode(strip_tags((string) $full), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '', $text);              // emails
$text = preg_replace('/(?:\+44\s?\(0\)\s?|\+44\s?|\b0)(?:\d[\s\-]?){9,10}\d?\b/u', '', $text); // UK phones
return TextLimit::graphemes((string) $text, 200);   // ellipsis counted inside 200
```
- The draft's `description` holds the excerpt (editable on the edit page, D-15). The full text goes to `raw_payload['description']` and `source_items.payload`. "Read more at {source}" is **rendered**, not stored: `EventPageData` gains `attribution{name, url}` (primary `event_sources` + `SafeLinks::http(item_url ?? events_page_url)`) and `alsoListedBy[]`. `OccurrenceCardData` gains `via` (short name). Eager-load `primarySource.source` in `PublicOccurrenceQuery` to avoid N+1.
- `DraftData` for discovered items: `organiserName/organiserContact/organiserWebsite = null`, `contactPublic = false`, `sourceUrl = item URL` (this satisfies the `no_contact` blocker), `sourceNote = null`.
- Re-run `ExcerptBuilder` on save in the draft edit page? **No.** The admin's edit wins. Only strip again at ingestion.

The two regexes above were run this session on sample strings. "Call 01953 452123 today" → "Call  today". "Tel +44 (0)1953 452 123" → "Tel ". "Phone 01953-452-123" → "Phone ". "Starts 2026 at 10:30" was unchanged. "Email jo.bloggs@example.co.uk please" → "Email  please" [VERIFIED: php -r probe]. Leftover labels such as "Tel"/"Email:" should also be stripped (`/\b(?:tel|phone|mob(?:ile)?|e-?mail)\s*[:.]?\s*(?=\s|$)/iu`) before whitespace collapsing.

### Anti-Patterns to Avoid
- **Fetching in a web request** (Run now / Test run): crawl delays + timeouts exceed PHP-FPM. Always queue.
- **Cache-only run locks:** a cleared cache or an expired `UniqueFor` lets a second run start. The partial unique index is the guarantee.
- **Letting `Http` follow redirects:** the SSRF and robots checks would only cover the first URL.
- **Calling `getDateTime()` without a zone, or `VCalendar::expand()` without its third argument:** both default to UTC [VERIFIED: VCalendar.php:276-283; probe].
- **Hashing raw fragments to decide updates:** DTSTAMP/UID churn floods the queue (Academy).
- **Writing `event_id` on a duplicate `new` draft:** violates `event_drafts_one_new_per_event`.
- **Mutating `Sabre\VObject\Settings::$maxRecurrences` globally.**
- **Missing detection on preview, failed, blocked, 304 or incomplete runs** (violates SRC-09/PIPE-08).
- **Publishing feed organiser fields** (D-17): never map ORGANIZER into `DraftData` organiser fields.
- **`->html()` / unescaped Blade for feed text** in Filament diff/duplicate views or public pages: feed text is untrusted.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| ICS parsing, line unfolding, TEXT unescaping, VTIMEZONE | Regex/line parser | `Sabre\VObject\Reader::read(…, OPTION_FORGIVING)` | Folding, escaping, Windows TZ names, VTIMEZONE guessing |
| Recurrence expansion with EXDATE/RDATE/overrides | Weekly/monthly loops | `Sabre\VObject\Recur\EventIterator` with an explicit zone | Project rule. DST-correct (probe: 19:00 BST → 19:00 GMT) |
| Timezone resolution | String maps | `TimeZoneUtil::getTimeZone($tzid, $vcal, true)` | Finders and guessers plus a strict failure mode |
| URL parsing/resolution/normalisation | `parse_url` + string concat | `league/uri` (installed) | Relative redirects, IDN, query sorting, RFC 3986 |
| Fuzzy string similarity | Levenshtein in PHP | pg_trgm `similarity()` | In-SQL, indexable, matches VenueMatcher |
| Grapheme-safe truncation | `substr`/`mb_substr` | `App\Support\TextLimit::graphemes` | Emoji and combining marks (EVT-02) |
| Whole-word keyword match | New regex | `CategoryAssigner::titleContainsKeyword` | Same semantics as Phase 2 rules (D-04 "like Phase 2") |
| Sleeping in tests | `sleep()` | `Illuminate\Support\Sleep` + `Sleep::fake()` | Instant, assertable |
| IP range classification | CIDR tables | `filter_var(…, FILTER_FLAG_GLOBAL_RANGE)` + 3 explicit extra blocks | Verified behaviour; small, auditable |
| Status transitions | Ad-hoc ifs | Enum `transitions()` / `assertCanTransitionTo()` (existing pattern) | Same as `ReviewStatus`, `OccurrenceStatus` |

**Key insight:** what this domain needs to get right is **policy**: identity, windows, baselines, completeness and politeness. The parsing itself is a solved problem. Spend the custom code on the policy layer, keep each piece pure, and drive it from the real fixtures plus small synthetic ones.

## Common Pitfalls

### Pitfall 1: Unpublishable ICS recurrence shapes
**What goes wrong:** a series draft with EXDATE, a moved override or a non-form RRULE sits in the queue, and Approve says "This repeat rule can't be published from the form."
**Why:** F1 (events store only `recurrence_rule`; `ManualRecurrence` round-trip only).
**How to avoid:** classify at extraction. Representable → normal draft. Not representable → an item-level `parse_errors` entry with an actionable message ("Repeats on Mon and Wed: publish as separate events or edit the rule"). Add `excluded_dates` if Open Question 1 option B is chosen.
**Warning signs:** drafts with `recurrence_rule` set that `DraftPublishability::blockers()` reports as `invariant`.

### Pitfall 2: Cancelling a date the generator will delete
**What goes wrong:** a confirmed cancellation 4 months out disappears, and the date reappears when it enters the horizon.
**Why:** F2: `sync()` deletes future rows not produced inside `horizon_days`.
**How to avoid:** set `horizon_days ≥ 400`, or keep such drafts pending with a "too far ahead to confirm yet" blocker. Test: confirm a cancellation 100 days out, run `calendar:sync-occurrences`, assert the row is still cancelled.

### Pitfall 3: Replace-in-place overwrites the admin's edits
**What goes wrong:** the admin picks a venue and trims the excerpt on a pending new draft. The next nightly run "replaces in place" and resets both.
**How to avoid:** `replacePendingFromSource()` copies only source-owned fields. Keep `venue_id`, `category_id` (when `category_reason = 'Chosen by admin'`), and `description` when it differs from the previous auto-excerpt (store `auto_excerpt_hash` in `raw_payload`). Then re-derive quality.
**Warning signs:** a test that edits a draft and re-runs ingestion with a changed time loses the venue.

### Pitfall 4: TEC per-occurrence identity and moved times
**What goes wrong:** a Town Council session moves from 10:00 to 11:00. With UID identity, this creates a new draft and, two runs later, a cancellation draft for the old one.
**How to avoid:** `IdentityStrategy::TecPostId` (`/^(\d+)-\d+-\d+@/` on the UID, a constant in code, never admin input). Fixture test: rewrite one UID's epoch and DTSTART in a copy of the TC fixture → one update draft, zero cancellations.

### Pitfall 5: Empty descriptions make bulk approve useless
See F3 and Open Question 3. A test should show "Approve all like this" approving the TC Yoga drafts once the chosen description rule is in place.

### Pitfall 6: Duplicate false positives within one feed
**What goes wrong:** Junior Youth / Senior Youth at the Town Hall on the same day are flagged as duplicates of each other (0.789).
**How to avoid:** apply the start-time guard and the same-source exclusion (F5). Fixture test: ingest the full TC fixture into an empty DB → 0 duplicate warnings. Ingest it again with the Yoga item also present as a published event → exactly 1 warning, linking Yoga.

### Pitfall 7: Huge Crawl-delay values block a worker
**What goes wrong:** Attleborough Primary's `Crawl-delay: 12000` (STATE.md) would make a worker sleep for 3.3 h, or fail at `--timeout=60`.
**How to avoid:** inline waits ≤ 20 s, otherwise release with a delay (`retryUntil`). If crawl_delay exceeds the check interval, show "Crawl-delay {n}s exceeds the schedule" on the source page.

### Pitfall 8: Bot walls masquerading as success
**What goes wrong:** Cloudflare or a DNS filter returns 200 HTML. The run shows "0 items, succeeded", and missing detection starts counting.
**How to avoid:** `BodyValidator` runs **before** extraction. ICS needs `BEGIN:VCALENDAR` after stripping a UTF-8 BOM and leading whitespace. Bodies starting with `<` or containing `<html` are `content_invalid`. Content-type is only a hint: Academy sends `text/calendar;;charset=UTF-8` [VERIFIED: manifest], so never parse the header strictly. Always store the invalid body (gzip) for the admin to inspect.

### Pitfall 9: `Http::fake()` skips transport options
**What goes wrong:** tests pass, production behaves differently.
**Why:** Laravel's stub handler returns the fake response without calling the next handler (`if (is_null($response)) { … return $handler($request, $options); }` only for unmatched requests) [VERIFIED: vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php:1819-1850]. So `curl` options (`CURLOPT_RESOLVE`), `on_headers` and `progress` never run under fakes.
**How to avoid:** unit-test `SsrfGuard` and the resolve-option builder directly with a fake `HostResolver`. Do size limits by reading the PSR stream (works with fakes). Redirects are a manual loop (works with `Http::fakeSequence`/URL patterns).

### Pitfall 10: Retries counting as failures
`--tries=3` fails a released job (F8). Use `retryUntil()` + `#[MaxExceptions(1)]`. Source failures are *recorded*, not thrown.

### Pitfall 11: DST and window edges
Compute the 12-month window, `observed_until` and "within 48 hours" on `Europe/London` `CarbonImmutable`, then convert with `->utc()`. Test with `travelTo` on Sat 24 Oct 2026 23:30 London (an item at 01:30 on the 25-hour day) and on 28 Mar 2027. Run the suite under `TZ=America/New_York` (CI already does).

### Pitfall 12: MassPrunable drafts and dangling links
`EventDraft` uses `MassPrunable` [VERIFIED: app/Models/EventDraft.php:17,99], so no model events fire. `source_items.current_draft_id` and `duplicate_decisions.draft_id` need DB-level `nullOnDelete` / `cascadeOnDelete`. Suppression of rejected items must live on `source_items.review_outcome`, not on the (pruned) draft.

### Pitfall 13: Filament specifics (verified in vendor this session)
- `reportBulkProcessingFailure()` is meaningless on non-bulk actions (F7).
- Use `Filament\Infolists\Components\ViewEntry` (exists, already used in `EventDraftInfolist.php:15`) for the three-column diff and the side-by-side duplicate panel. Escape everything in the Blade view.
- Use `->poll('3s')` (exists on tables: `CanPollRecords::poll()`, and on schema components: `CanPoll::poll()`) for run status. Stop polling when no run is active (`->poll(fn () => $this->hasActiveRun() ? '3s' : null)`).
- Add `->timezone('Europe/London')` to every `TextColumn`/`TextEntry` that shows `source_runs.*_at` and `next_check_at` (project rule).
- Hide `approve` (and the "A" key path) for `kind ≠ new`. Add `apply`/`ignore` and `confirmCancellation`/`ignore` header actions with the same `shownStatus` guard pattern as `ReviewActions` (`->visible(fn (EventDraft $record, Component $livewire): bool => self::shownStatus($record, $livewire) === ReviewStatus::PendingReview)`) [VERIFIED: ReviewActions.php:93].
- The REV-09 source filter currently filters `origin` ("'Source' is the draft's origin until Phase 3 adds…") [VERIFIED: EventDraftsTable.php:96-98]. Replace it with a `SelectFilter::make('source_id')->relationship('source', 'name')`.
- Table action tests: `TestAction::make('approveAllLikeThis')->table($record)`. Browser tests must keep the `forgetScopedInstances()` listener (tests/Pest.php).

## Code Examples

The patterns above carry the core snippets (SSRF guard, throttle, extractor loop, duplicate SQL, apply update, urgent key, excerpt). Here are two test shapes the planner can cite:

```php
// Feature: unchanged calendar → zero drafts (success criterion 2, day-2 check)
it('creates no drafts when the same calendar is fetched twice', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 06:00', 'Europe/London'));
    Sleep::fake();
    $source = Source::factory()->ics()->active()->create(['url' => 'https://www.attleboroughbaptist.org.uk/Calendar/Calendar.ics']);
    Http::fake([
        '*/robots.txt' => Http::response("User-agent: *\nCrawl-delay: 10\nDisallow: /Admin/\n", 200),
        '*/Calendar/Calendar.ics' => Http::response(fixture('attleborough-baptist-church/2026-10-17-ical.ics'), 200, ['Content-Type' => 'text/calendar; charset=utf-8']),
    ]);
    app(IngestRunner::class)->runNow($source);
    $first = EventDraft::count();
    app(IngestRunner::class)->runNow($source->refresh());
    expect(EventDraft::count())->toBe($first)
        ->and(SourceRun::latest('id')->first()->drafts_created)->toBe(0);
});
```
(`Http::preventStrayRequests()` is global, so every URL the fetcher touches, robots.txt included, must be faked. The DNS resolver must be bound to a fake that returns a public IP. `fixture()` is a small helper over `tests/Fixtures/sources`. The source URL here is illustrative: take real URLs from each manifest's `url`.)

```php
// Unit: unknown TZID becomes an item parse error, never UTC
it('rejects an unknown TZID', function () {
    $result = app(IcsExtractor::class)->extract(icsFixture('unknown-tzid.ics'), Source::factory()->make());
    expect($result->items[0]->parseErrors)->toContain('Unknown time zone "Made Up/Zone"')
        ->and($result->items[0]->facts)->toBeNull();
});
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Original robots.txt convention (1994) | RFC 9309 (Sept 2022): specific group overrides `*`, longest match, 4xx = allow, 5xx = disallow, 24 h cache, ≥500 KiB | 2022 | Parsers that merge `*` into specific groups (spatie default) disagree with the standard |
| `$tries`/`$timeout` properties on jobs | PHP attributes `#[Tries]`, `#[Timeout]`, `#[UniqueFor]`, `#[MaxExceptions]`, `#[Backoff]`, `#[DebounceFor]` | Laravel 12/13 | Present in 13.37 (`Illuminate/Queue/Attributes/`) [VERIFIED] |
| `FILTER_FLAG_NO_PRIV_RANGE \| NO_RES_RANGE` | `FILTER_FLAG_GLOBAL_RANGE` (PHP 8.2+) | PHP 8.2 | Also rejects 100.64/10, 192.0.0/24, 198.18/15, 2001:db8::/32 (verified). Multicast and NAT64 still need explicit blocks |
| Guzzle 7 | Guzzle 8.2 (installed) | 2026 | Use `Http` facade options only. Constants in `GuzzleHttp\RequestOptions` unchanged (`allow_redirects`, `curl`, `on_stats`, `force_ip_resolve`) [VERIFIED] |

**Deprecated/outdated:** using `VCalendar::expand()` for storage (UTC-flattening, drops VTIMEZONE). sabre's `Greenwich Standard Time` → `Atlantic/Reykjavik` mapping is correct for Windows, but wrong for a UK feed mislabelled that way. Flag it as a quality reason when the source's default zone is Europe/London.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The owner accepts "publish the representable subset" for ICS recurrence (one-offs, form-representable RRULE, plus EXDATE via a new `excluded_dates` column). Moved/added instances become parse errors | F1, Open Q1 | If the owner wants full fidelity, Phase 3 must add per-occurrence time overrides to `event_occurrences` (larger change touching Phase 1 contracts WR-06 and OccurrenceUid) |
| A2 | Raising `calendar.horizon_days` from 90 to 400 is acceptable (more rows, the public calendar shows further ahead) | F2 | If not, cancellations >90 days out must wait in the queue |
| A3 | A neutral description fallback for empty feed descriptions (e.g. "Details on {source name}'s website.") is acceptable as published text | F3, Open Q3 | Without it, D-03 bulk approval is ineffective for TC and most Baptist items |
| A4 | A source's admin-configured `default_venue_id` (and an exact name/alias match on a discovered draft) may set `venue_id`, i.e. counts as the admin's confirmation | Open Q4 | If not, every discovered draft needs a manual venue pick, and "Approve all like this" skips with "no confirmed venue" |
| A5 | Rejected items stay suppressed (no new draft on later changes) unless the admin reopens the draft | Open Q5 | Rejected events could re-enter the queue after every source edit |
| A6 | `supports_missing_detection` seeds: Baptist true, Town Council true, Academy false | D-05 seeder | Wrong defaults → false cancellations (Academy UIDs unstable) or missed cancellations |
| A7 | New `RejectReason::Ignored` (`'ignored'`) for ignored update/cancellation drafts (widen `event_drafts_reject_reason_check`) | State machines | Labelling only. Alternative: reuse `Other` with a note |
| A8 | D-08 one-off cancellations use `ChangeEventStatus(Cancelled)` (the event page prefix "Cancelled:" reads `EventStatus` [VERIFIED: EventPageData.php `$isCancelled = $event->status === EventStatus::Cancelled`]). Series dates use `ChangeOccurrenceStatus` | State machines | If the owner wants occurrence-only for one-offs, the event page header won't say "Cancelled:" |
| A9 | Default schedule 05:30 London with 0–19 min jitter; dispatcher every 10 min | Pattern 7 | Cosmetic (discretion area) |
| A10 | Raw bodies kept 30 days (gzip, `storage/app/private/ingest/raw/{source_id}/{run_id}.gz`); `source_runs` rows kept 1 year | Pattern 7 | Disk use (tiny) |
| A11 | `Http::fake()` serves a readable PSR stream when `stream => true` is set, so chunked size-limit reading works in tests | Pitfall 9 | If not, read `$response->body()` with a Content-Length pre-check in tests only. Verify in Wave 1 with one test |
| A12 | TEC iCal descriptions are plain text without HTML (only empty ones observed) | Pattern 12 | `strip_tags` handles HTML anyway: low risk |

## Open Questions (RESOLVED)

All seven questions were answered by the owner on 2026-10-17 and recorded in 03-CONTEXT.md. Where an answer differs from the recommendation below, the decision wins:
- Q1 recurrence scope → **D-19** (option B: one-offs + form-representable RRULE + `excluded_dates`; moved overrides and RDATE become parse errors).
- Q2 horizon → **D-20** (keep `calendar.horizon_days` at 90; the import window is 90 days, *not* 400 as recommended below).
- Q3 empty descriptions → **D-21** ("Details from {source name}." + "Read more at {source}").
- Q4 venue confirmation → **D-22** (the source's default venue counts as confirmed).
- Q5 rejected items → **D-23** (stay rejected unless facts change or the admin reopens).
- Q6 preview body → **D-24** (reuse when under 60 minutes old).
- Q7 primary attribution → **D-25** (per-source "Organiser's own site" flag).

1. **How much ICS recurrence should be publishable in Phase 3?** (F1)
   - What we know: the real feeds have no RRULE/EXDATE/overrides. The published model holds one `recurrence_rule` limited to `ManualRecurrence` shapes. EXT-01 requires extraction, and success criterion 1 requires correct draft times "across DST, overrides and exclusions".
   - Options: (A) extract fully, publish only one-offs + form-representable RRULE, everything else gets an actionable parse error. (B) As A, plus `excluded_dates` jsonb on drafts and events, honoured by `RecurrenceExpander` (EXDATE on the synthetic VEVENT). This covers EXDATE and "removed" instances. (C) Full fidelity with per-occurrence overrides.
   - Recommendation: **B**. It is small and contained (one column each, one EXDATE line in the expander, a form display "Except: …"), and it keeps WR-06/WR-10 intact. Treat a cancelled override as an occurrence cancellation after publish. Moved or added instances → parse error.
2. **Horizon vs 12-month window** (F2). Recommendation: `horizon_days = 400`. Confirm with the owner.
3. **Empty feed descriptions** (F3). Options: (a) fallback sentence "Details on {source short name}'s website." written as the excerpt, with the `no_description` blocker satisfied. (b) Keep the blocker and accept manual typing. (c) Relax the blocker only for `origin = discovered` when a `source_url` exists. Recommendation: (a). It keeps one rule for all origins, stays honest text, and the "Read more" link sits right beside it.
4. **Venue confirmation for discovered drafts.** Phase 2 made `VenueMatcher` "read-only: it never … sets a draft's venue_id" [VERIFIED: VenueMatcher.php docblock] and bulk requires a confirmed venue (D-21). Recommendation: the source's `default_venue_id` applies when LOCATION is empty or equals that venue's name/alias. That is admin configuration, so it counts as confirmed. Fuzzy matches stay suggestions only.
5. **Rejected items' later changes.** Recommendation: suppress (A5), with "Reopen" as the way back.
6. **"Create drafts" after a preview: re-fetch or reuse the preview body?** Recommendation: reuse when < 60 min old (politeness, identical result to what the admin saw). Otherwise re-fetch.
7. **Primary attribution choice (D-12)** when two feeds link one event: there is no "organiser's own source" flag yet. Recommendation: add `sources.is_organiser_source` bool. Primary = the first approved link unless an organiser source links later, which then takes primary. The admin can switch it on the event's sources list.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | everything | ✓ | 8.4.12 (local); prod 8.5 | — |
| PostgreSQL + pg_trgm | duplicates, tests | ✓ | 18.6, pg_trgm 1.6 (dev DB via tinker) | — |
| sabre/vobject | ICS | ✓ | 5.0.0 | — |
| ext-intl (grapheme_*) | excerpt | ✓ (TextLimit in use) | — | — |
| ext-curl (`CURLOPT_RESOLVE`) | SSRF pinning | ✓ assumed (Guzzle curl handler is default) | — | Stream handler lacks pinning: refuse to fetch if curl is missing |
| `psql` CLI | none (convenience) | ✗ | — | `php artisan tinker` / `DB::select` |
| `timeout` CLI | none | ✗ (macOS) | — | — |
| Network to sources | production only | n/a in tests | — | Tests are offline (fixtures) |

**Missing dependencies with no fallback:** none.
**Production note (carried forward):** the Town Council blocks datacenter IPs. The Hetzner VPS may get `blocked`/`failed` runs until the clerk allowlists it (Phase 5).

## Validation Architecture

Skipped: `workflow.nyquist_validation` is `false` in `.planning/config.json`. Testing guidance for the planner:
- **Framework:** Pest 5.3.2 on PostgreSQL. Quick: `php artisan test --compact tests/Feature/Ingestion`. Full: `php artisan test --compact`.
- **Unit (pure, fast):** RobotsPolicy (RFC 9309 examples + manifest rules), SsrfGuard (the probe table above), CanonicalUrl, ExcerptBuilder, ContentHasher (DTSTAMP/UID churn → same hash), IcsExtractor over the 3 real fixtures + synthetic ones (DST 25 Oct 2026 / 28 Mar 2027, floating, unknown TZID, Greenwich Standard Time, EXDATE, moved and cancelled overrides, MaxInstances, all-day multi-day exclusive DTEND, bot-wall HTML).
- **Feature:** end-to-end runs with `Http::fake` + `Sleep::fake` + a fake resolver: new → drafts; unchanged → 0; moved time → update with diff; STATUS:CANCELLED → cancellation; missing ×2 complete runs → cancellation; incomplete/failed run → no counters; robots disallow → `blocked`, health unchanged; 5 failures → unhealthy and skipped by the dispatcher; zero-after-results → structure alert; preview → no writes; partial unique index refuses a second active run; Publishing actions (apply, ignore, confirm, duplicate, not-duplicate memory, approve-all-like-this) with AdminGate refusals; arch test still green (new actions live in `App\Publishing\Actions`).
- **Public:** event page/card attribution, "Read more" SafeLinks, no organiser from feeds, excerpt ≤ 200 graphemes. `NoThirdPartyAssetsTest` still passes.
- **Browser UAT:** add flows to `docs/uat/browser-uat.md`: register source → Test run → Create drafts. Review an update diff → Apply. Confirm a cancellation. Mark as duplicate / Not a duplicate. Urgent badge order.
- **Wave 0 gaps:** `tests/Fixtures/ics/*` synthetic files; factories for Source/SourceRun/SourceItem; a fake `HostResolver` binding helper in `tests/Pest.php`; a `fixture()` helper.

## Security Domain

`security_enforcement: true`, ASVS level 1, block on high.

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (unchanged panel login) | — |
| V3 Session Management | no | — |
| V4 Access Control | yes | `AdminGate::authorize()` first in every new Publishing action. Panel gate `User::canAccessPanel()`. `SourceResource` behind the panel |
| V5 Input Validation | yes | Source URL validation (scheme/port/userinfo). Feed text treated as untrusted (escaped Blade, no `->html()`). Bound SQL parameters for similarity. Enum-only identity strategies (no admin regex). Size/line/instance caps |
| V6 Cryptography | no (sha256 for change hashes only, not security) | `hash('sha256', …)` |
| V12 Files and Resources / V13 API (SSRF) | yes | SsrfGuard + DNS pinning + per-hop checks + manual redirects + http/https only |

### Known Threat Patterns for this stack
| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| SSRF via admin-entered source URL or a redirect to internal/metadata IPs (169.254.169.254) | Information disclosure / Elevation | Resolve → all IPs `isPublic()` → `CURLOPT_RESOLVE` pin. Re-check every hop. Ports 80/443 only. No userinfo |
| DNS rebinding between check and connect | Tampering | Pin the checked IP via `CURLOPT_RESOLVE` (unit-test the option builder, Pitfall 9) |
| Decompression / huge body / billion-instance RRULE DoS | Denial of service | `max_bytes` (e.g. 5 MiB) on the decoded stream, VEVENT count cap (e.g. 5 000), own instance cap, `MaxInstancesExceededException` caught per item, job timeout 75 s |
| Stored XSS from feed SUMMARY/DESCRIPTION/LOCATION in Filament or public pages | Tampering | Escaped output only. `ViewEntry` Blade uses `{{ }}`. No `->html()`, `->markdown()` or `HtmlString` from feed text |
| Personal data republication (organiser names, emails, phones) | Information disclosure / legal | D-17: organiser never mapped. Excerpt strips emails/phones. Raw bodies private disk, 30-day prune. Logs carry ids only (existing pattern "Ids only: never titles or contact details") |
| Bot-protection evasion | Repudiation / legal | Never retry with a different UA. Challenge → `blocked`/`content_invalid`. Honour robots and Crawl-delay |
| Log injection via error messages | Tampering | `error_message` from our own enum-based messages + truncated HTTP status line. Never the response body |
| Path traversal in raw storage | Tampering | Paths built only from integer ids |
| Concurrent writes (two runs, apply vs sync) | Tampering | Partial unique index. Consistent lock order (draft → event → item). Transactions |

## Suggested Plan Breakdown (for the planner; coarse granularity)

1. **Schema & domain types:** migrations (sources, source_runs, source_items, event_sources, duplicate_decisions, crawl_hosts, event_drafts alters, category_mappings `source_id`, optional `excluded_dates`), enums, models, factories, `config/ingest.php`, `ExtractionMethod::Ics`, horizon change (if approved).
2. **HttpFetcher:** UrlPolicy, SsrfGuard + resolver, RobotsPolicy, HostThrottle, BodyValidator, redirect loop, conditional GET, RawBodyStore. Unit-heavy.
3. **IcsExtractor:** identity strategies, timezone resolver, normaliser, window classifier, content/raw hashing. Real + synthetic fixtures.
4. **Pipeline & jobs:** SourceItemRecorder, ChangeDetector, MissingItemDetector, DuplicateDetector, TitleRules, ExcerptBuilder, `DraftWriter` source methods, `FetchSourceJob`, `ingest:dispatch-due`, `ingest:prune-raw`, SourceHealthTracker, preview mode.
5. **Publishing actions:** LinkEventSource (hooked into ApproveDraft for sourced drafts), ApplySourceUpdate, IgnoreSourceChange, ConfirmSourceCancellation, MarkDraftDuplicate, RecordNotDuplicate, ApproveAllLikeThis. ReopenDraft refuses non-new drafts.
6. **Filament:** SourceResource (form incl. title rules repeater, Run now, Test run + preview panel, Create drafts, runs relation manager with poll), review page diff/duplicate panels and actions, urgent sort + badge, source filter, System health sources check.
7. **Public + seed + UAT:** attribution and excerpt on the event page and card, `SourceSeeder` (D-05, `firstOrCreate` keyed on `slug`, inactive), browser UAT flows, Attleborough Primary fixture capture task (STATE.md carry-over).

## Sources

### Primary (HIGH confidence, read or executed this session)
- Codebase: `app/Calendar/{OccurrenceGenerator,RecurrenceExpander,ManualRecurrence}.php`, `app/Publishing/Actions/{ApproveDraft,UpdatePublishedEvent,ChangeOccurrenceStatus}.php`, `app/Publishing/Data/PublishableEventData.php`, `app/Review/{DraftWriter,DraftPublishability,ReviewQueue,CategoryAssigner,VenueMatcher,QualityScorer}.php`, `app/Enums/*`, `app/Support/{TextLimit,SafeLinks,EventPageData}.php`, `app/Ops/HealthReport.php`, `app/Filament/Resources/EventDrafts/*`, migrations (events, event_occurrences, event_drafts, category_mappings, pg_trgm, cache), `config/{calendar,review,queue,cache}.php`, `routes/console.php`, `tests/Pest.php`, `tests/Unit/Architecture/WriteChokePointTest.php`
- Fixtures + manifests: `tests/Fixtures/sources/{attleborough-academy,attleborough-baptist-church,attleborough-town-council}/`
- Vendor source: sabre/vobject 5.0.0 (`TimeZoneUtil`, `Property/ICalendar/DateTime`, `DateTimeParser`, `Recur/EventIterator`, `Component/VCalendar::expand`, `Settings`); Laravel 13.37 (`Queue/Worker`, `Queue/Attributes`, `Queue/Middleware/WithoutOverlapping`, `Support/Sleep`, `Http/Client/PendingRequest`); Guzzle 8.2 `RequestOptions`, `RedirectMiddleware`; Filament 5.10.2 (`actions/src/Concerns/InteractsWithSelectedRecords`, `Action::bulk`, `tables/.../CanPollRecords`, `infolists/.../ViewEntry`)
- Probes: sabre fixture/synthetic parsing (`/tmp/ics_probe.php`, `/tmp/ics_probe2.php`), PHP IP filter flags, pg_trgm similarity on the dev DB, excerpt regexes
- RFC 9309 (https://www.rfc-editor.org/rfc/rfc9309.txt): §2.2.1, §2.2.2, §2.3.1.2–2.3.1.4, §2.4, §2.5

### Secondary (MEDIUM)
- `.planning/research/{SUMMARY,ARCHITECTURE,PITFALLS,STACK}.md` (prior research, cross-checked against code where cited)
- spatie/robots-txt 2.5.4 source on GitHub, Packagist metadata (versions, downloads)

### Tertiary (LOW)
- Training knowledge for TEC description formatting (A12) and `Http::fake` stream behaviour (A11)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH. Everything is installed and versions were checked with `composer show`. No new packages.
- Architecture: MEDIUM-HIGH. Schema and state machines follow locked decisions and the prior ARCHITECTURE research. The constraints they must respect were verified in code.
- Pitfalls: HIGH. Each critical pitfall (F1–F9) comes from code read or probes run this session.
- Open decisions: LOW until the owner answers Open Questions 1–4.

**Research date:** 2026-10-17
**Valid until:** 2026-11-16 (stable stack). Re-check spatie/robots-txt only if the owner prefers a package.

