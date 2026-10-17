---
phase: "01"
slug: "foundation-published-calendar"
status: verified
# threats_open = count of OPEN threats at or above workflow.security_block_on severity (the blocking gate)
threats_open: 0
asvs_level: 1
block_on: high
created: "2026-10-17"
---

# Phase 01 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.
> Register built from the `<threat_model>` blocks of plans 01-01 and 01-04 … 01-15 plus every SUMMARY `## Threat Flags` section (all "None"; no unregistered flags). Plans 01-02/01-03 and threats T-01-38..T-01-40 moved to Phase 5 (05-01/05-02) and are out of scope here. Evidence gathered at ASVS L1 (grep/read of code and enforcing tests) on commit b89e4b4 plus quick task 261017-gac.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Internet → public pages | Anonymous visitors read `/`, `/events/{slug}`, `.ics`; query strings and Host header are untrusted | Filter params, slugs, Host header (untrusted input); published event data (public) |
| Admin browser → Filament panel | Single admin (ADMIN_EMAIL) via Filament login; Livewire state is client-controlled | Credentials, event fields, status changes (trusted only after AdminGate) |
| Filament/Livewire → domain actions | `App\Publishing\Actions\*` re-check admin and transitions on locked rows | Event/occurrence writes |
| Scheduler / queue / CLI → app | `schedule:run`, queue worker, artisan commands (shell access only) | Heartbeats, occurrence sync, failed-job retry |
| App → PostgreSQL | Local DB, bound queries only | All persisted data |
| Build / supply chain → app | composer and npm installs; Vite build output | Third-party code, built assets |
| Visitor browser → third parties (link-outs) | Google Calendar, WhatsApp, Facebook, mailto only when the visitor taps; no third-party scripts | Event URL and title (public) |

---

## Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation / Evidence | Status |
|-----------|----------|-----------|----------|-------------|------------|--------|
| T-01-01 | Elevation of privilege | `User::canAccessPanel` | high | mitigate | User.php:27 canAccessPanel→AdminGate; panel has ->login() only, no registration/reset routes; WalkingSkeletonTest:62 assertForbidden | closed |
| T-01-02 | Tampering (stored XSS) | event title/description in Blade | high | mitigate | No `{!!` in resources/views; no RichEditor/MarkdownEditor in app/Filament; escape-once tests (EventPageContentTest, PublicLookTest) | closed |
| T-01-03 | Spoofing (brute force) | Filament login | medium | mitigate (+ transfer: Phase 5) | Filament ->login() built-in throttle (AdminPanelProvider:31). Strong production password transferred to Phase 5 (05-01, was 01-03) | closed |
| T-01-04 | Information disclosure | Boost MCP / debug tooling | medium | mitigate (+ transfer: Phase 5) | laravel/boost in require-dev only (composer.json); --no-dev deploy transferred to Phase 5 (05-02) | closed |
| T-01-17 | Tampering | `ManualRecurrence` / `PublishableEventData` (rule injection) | medium | mitigate | ManualRecurrence::toRrule/tryFromRrule (ManualRecurrence.php:72,132); ManualRecurrenceTest | closed |
| T-01-18 | Denial of service | `RecurrenceExpander`, `calendar:sync-occurrences` | medium | mitigate | RecurrenceExpander MAX_INSTANCES=500 (:32,:71), NoInstancesException handled; per-event try/catch in SyncOccurrences | closed |
| T-01-19 | Tampering (integrity) | `OccurrenceGenerator` / `UpdatePublishedEvent` / daily sync race | medium | mitigate | OccurrenceGenerator upsert (:46) excludes status; UpdatePublishedEvent:65 and SyncOccurrences:41 lockForUpdate in DB::transaction | closed |
| T-01-20 | Elevation of privilege | `EditEvent` / `UpdatePublishedEvent` | high | mitigate | AdminGate::authorize in UpdatePublishedEvent:62; UpdatePublishedEventTest AuthorizationException | closed |
| T-01-21 | Repudiation | Event edits | low | accept | Accepted (see log) | closed |
| T-01-22 | Information disclosure | sync command logs | low | mitigate | SyncOccurrences:52 logs event_id + message only | closed |
| T-01-23 | Tampering (stored XSS) | `OccurrenceCardData` source link, card partial | medium | mitigate | SafeLinks::http scheme allow-list (SafeLinks.php:27); javascript: test | closed |
| T-01-24 | Tampering (injection) | `CalendarFilters::fromRequest`, `PublicOccurrenceQuery` | medium | mitigate | CalendarFilters SLUG_PATTERN (:19), DateFilter::tryFrom (:34); Eloquent bindings | closed |
| T-01-25 | Denial of service / SEO (crawl trap, canonical poisoning) | filter URLs, canonical link | medium | mitigate | robots noindex on filtered list (calendar/index.blade.php:8); canonical from config(app.url) | closed |
| T-01-26 | Information disclosure | `PublicOccurrenceQuery` filter composition | medium | mitigate | PublicOccurrenceQuery base publiclyListed() constraints (:50-51); hidden-wins tests | closed |
| T-01-27 | Tampering (reflected XSS) | "Showing:" summary, page title, empty states | medium | mitigate | Only resolved labels echoed; 404 test asserts raw value absent | closed |
| T-01-28 | Denial of service | `?page=` and oversized params | low | mitigate | simplePaginate (PublicOccurrenceQuery:60) | closed |
| T-01-29 | Information disclosure | organiser contact on public cards | low | accept | Accepted (see log) | closed |
| T-01-30 | Elevation of privilege | `StatusActions`, `ChangeEventStatus`, `ChangeOccurrenceStatus` | high | mitigate | AdminGate::authorize in ChangeEventStatus:36 / ChangeOccurrenceStatus:37; ChangeEventStatusTest:78; StatusActionsTest assertActionHidden | closed |
| T-01-31 | Tampering | Crafted Livewire calls forcing an illegal transition (e.g. dropping a cancellation by "unhiding") | medium | mitigate | assertCanTransitionTo on locked row inside actions; transition matrix unit tests; DB CHECK | closed |
| T-01-32 | Tampering (integrity) | Status vs `OccurrenceGenerator` / `UpdatePublishedEvent` / daily sync | medium | mitigate | Status actions lockForUpdate event then occurrence (ChangeOccurrenceStatus:40-42) | closed |
| T-01-33 | Repudiation | Status changes | low | mitigate | status_changed_by written only by the two actions (:47/:50) + Log::info per change | closed |
| T-01-34 | Information disclosure | Hidden events leaking through the public list, filters or pagination | medium | mitigate | PublicOccurrenceQuery publiclyListed(); window/filter hidden tests | closed |
| T-01-35 | Denial of service (data loss) | Admin hard delete | medium | mitigate | No DeleteAction/DeleteBulkAction/ForceDelete in app/Filament | closed |
| T-01-36 | Tampering (integrity of public information) | Cancelled items vanishing before they expire | medium | mitigate | Cancelled publicly listed until expires_at; PublicLookTest:191 single Cancelled badge | closed |
| T-01-37 | Information disclosure (privacy) | Public pages loading fonts/scripts/images from third parties (visitor IPs to CDNs, GDPR/PECR) | medium | mitigate | NoThirdPartyAssetsTest; CI built-asset gate | closed |
| T-01-41 | Spoofing / Tampering | Host header leaking into og:image and other absolute URLs (cache or share-preview poisoning) | medium | mitigate (+ transfer: Phase 5) | URL::forceRootUrl (AppServiceProvider:46); BrandHeadTest/HostHeaderAssetsTest evil.example. Caddy apex-only block transferred to Phase 5 (05-01) | closed |
| T-01-42 | Tampering (path traversal / injection) | `x-category-icon` choosing a component from a slug | low | mitigate | Fixed icon map; PublicLookTest:184 `../../.env` slug | closed |
| T-01-43 | Information disclosure | Source maps or dev files shipped in public/build | low | mitigate | CI fails on *.map in public/build (ci.yml:74) | closed |
| T-01-44 | Tampering (integrity of public information) | Restyle hiding or altering the "Cancelled" badge, times or prices (CSS hide, clamp, case change) | medium | mitigate | PublicLookTest Cancelled badge ancestor/visibility checks | closed |
| T-01-45 | Elevation of privilege | Dev-only render script or Chrome reaching CI/server | low | accept | Accepted (see log) | closed |
| T-01-46 | Elevation of privilege | SystemHealth page, Retry/Delete actions | high | mitigate | SystemHealth::canAccess (:62-66) reuses canAccessPanel; SchedulerHeartbeatTracerTest:80 403, :83 guest redirect | closed |
| T-01-47 | Information disclosure | Failed job rendering and audit logs | medium | mitigate | Payload never rendered; FailedJobsTest payload-absent assertion | closed |
| T-01-48 | Tampering | Retry re-executes a stored payload | low | mitigate | queue:retry only after uuid existence re-check (SystemHealth:158,189); confirmed admin action | closed |
| T-01-49 | Repudiation | Who retried or deleted a failed job | low | mitigate | Log::warning retried/deleted with admin_id + uuid (SystemHealth:159,175) | closed |
| T-01-50 | Denial of service | jobs / failed_jobs / log growth on a 40 GB box | medium | mitigate (+ transfer: Phase 5) | QueueHeartbeat ShouldBeUnique; queue:prune-failed --hours=720 (routes/console.php:33); daily log rotation. Production check.sh transferred to Phase 5 (05-02) | closed |
| T-01-51 | Spoofing | Liveness evidence (false OK) | medium | mitigate | Heartbeat table; Heartbeat::beat only in console/QueueHeartbeat/OpsDrillJob/SyncOccurrences (no HTTP path) | closed |
| T-01-52 | Denial of service (undetected outage) | Nobody opens the page while cron or the worker is dead | medium | accept | Accepted (see log) | closed |
| T-01-53 | Information disclosure | Log files | low | mitigate | config/logging.php permission 0640 (:65,:76); ids-only logging | closed |
| T-01-54 | Information disclosure (privacy) | Admin font loaded from a third-party font host | low | mitigate | AdminPanelProvider LocalFontProvider system-ui (:38); AdminFontAndLoggingTest | closed |
| T-01-55 | Tampering / Denial of service | ops:drill command | low | accept | Accepted (see log) | closed |
| T-01-56 | Information disclosure | Hidden events/occurrences leaking via event page, old-slug redirect or .ics | medium | mitigate | isPubliclyListed before 301; publiclyListed occurrences; page/history/.ics tests | closed |
| T-01-57 | Tampering (stored XSS) | Event title/description in HTML, title tag, meta/OG | high | mitigate | No `{!!` in views; EventSeoTest:151 and EventPageContentTest:169 escape-once tests | closed |
| T-01-58 | Tampering (link injection) | source_url and organiser_contact rendered as hrefs | medium | mitigate | SafeLinks http/mailto/tel allow-lists; rel nofollow noopener | closed |
| T-01-59 | Spoofing (cache / share-preview poisoning) | Host header reaching canonical, og:url, og:image, .ics URL/UID, 301 Location | medium | mitigate | PublicUrl from config(app.url); no asset()/getHost in app/Seo, app/Calendar/Export, PublicUrl; EventSeoTest Host header | closed |
| T-01-60 | Tampering (ICS property injection) | CR/LF, commas, semicolons in title/description/venue reaching the .ics | medium | mitigate | sabre VCalendar builds .ics (OccurrenceIcs); parse-back tests | closed |
| T-01-61 | Spoofing (open redirect) | 301 from old/uppercase slugs | medium | mitigate | Redirect target from DB slug via PublicUrl; Location host test | closed |
| T-01-62 | Denial of service | Long or odd slugs/keys, many occurrences | low | mitigate | Route constraints [A-Za-z0-9-]{1,120} and \d{4}-\d{2}-\d{2} (routes/web.php:12-17) | closed |
| T-01-63 | Information disclosure (privacy) | Share/embed widgets tracking visitors | medium | mitigate (+ transfer: Phase 5) | No third-party scripts (EventCalendarAndShareTest allow-list, NoThirdPartyAssetsTest). Referrer-Policy header transferred to Phase 5 Caddy (05-01); also add Permissions-Policy web-share=(self) (01-10 summary note) | closed |
| T-01-64 | Tampering (integrity of public information) | Cancelled dates shown as on in the page or the .ics | medium | mitigate | Single cancelled rule feeds STATUS:CANCELLED, badge and prefix; links suppressed; tests | closed |
| T-01-65 | Spoofing (slug squatting) | A new event taking a renamed event's old slug and capturing its shared links | low | mitigate | EventSlugger treats event_slugs as taken; UNIQUE event_slugs.slug | closed |
| T-01-66 | Tampering (client script) | share.js manipulating the DOM unsafely or calling out | low | mitigate | share.js textContent only (:14,:25); no remote URLs | closed |
| T-01-67 | Tampering (stored XSS) | Event title/description in the JSON-LD script block and data-share attributes | high | mitigate | EventJsonLd spatie toScript JSON_HEX_TAG → HtmlString (EventJsonLd.php:34-45); EventJsonLdTest script break-out | closed |
| T-01-68 | Spoofing (cache / share-preview poisoning) | Host header reaching JSON-LD url/image, Google details, share links | medium | mitigate | PublicUrl only; EventJsonLdTest / EventCalendarAndShareTest Host header | closed |
| T-01-69 | Tampering (integrity of public information) | Cancelled dates shown as on in JSON-LD, Google link or share text | medium | mitigate | eventStatus EventCancelled + Cancelled: share prefix; links suppressed; tests | closed |
| T-01-70 | Tampering | @vite asset URLs via asset() (CR-01) | high | mitigate | URL::forceRootUrl/forceScheme + Vite::createAssetPathsUsing (AppServiceProvider:46-53); HostHeaderAssetsTest | closed |
| T-01-71 | Information disclosure | Laravel Boost browser-logger script on public pages (verification gap) | medium | mitigate | BOOST_BROWSER_LOGS_WATCHER=false (.env.example:73); EnvExampleTest:12 | closed |
| T-01-72 | Information disclosure | owner's .env edit | medium | mitigate | .env untracked; never staged (process control) | closed |
| T-01-73 | Denial of service | cached 301 slug redirects + rename-back (WR-04) | medium | mitigate | REDIRECT_CACHE_CONTROL no-cache, private (EventController:36; OccurrenceIcsController); SlugRedirectCachingTest | closed |
| T-01-74 | Tampering | CI runtime mismatch (WR-08) | low | mitigate | CI matrix php 8.4 + 8.5 (ci.yml:21) | closed |
| T-01-75 | Elevation of privilege | PublishEvent without admin check (WR-01) | high | mitigate | AdminGate::authorize before transaction (PublishEvent:45); PublishEventTest | closed |
| T-01-76 | Repudiation | approved_by recorded from an unverified User (WR-01) | medium | mitigate | approved_by forceFilled only after AdminGate (PublishEvent) | closed |
| T-01-77 | Tampering | mass assignment of status/origin/approved_* (WR-07) | high | mitigate | status/origin/approved_* not in #[Fillable] (Event.php:55); PublishEventTest:176 isFillable false | closed |
| T-01-78 | Tampering | corrupt dates/times/attribution via the DTO (WR-02) | medium | mitigate | PublishableEventData constructor invariants; PublishableEventDataTest | closed |
| T-01-79 | Tampering | overflowing UNTIL silently extends a series (WR-03) | medium | mitigate | ManualRecurrence UNTIL round-trip checks (:61,:65); ManualRecurrenceTest | closed |
| T-01-80 | Tampering | UID change on one-off ↔ repeating switch (WR-06): duplicates and unmatched CANCELLED updates in residents' calendars | medium | mitigate | UpdatePublishedEvent type-switch refusal (:47,:73); OccurrenceUidStabilityTest | closed |
| T-01-81 | Denial of service | edit page 500 on unsupported rules blocks hide/cancel (WR-09) | medium | mitigate | EditEvent tryFromStoredRule fallback; status actions in header and table; EditEventResilienceTest | closed |
| T-01-82 | Tampering | forced save from the display-only fallback, or any other caller, rewrites an unsupported rule (to none or to a supported pattern) | medium | mitigate | EditEvent canEdit (:58,:132) + UpdatePublishedEvent:68 refuses non-round-tripping rules server-side | closed |
| T-01-83 | Elevation of privilege | new table record actions | low | mitigate | Table actions reuse StatusActions→AdminGate; non-admin 403 on list | closed |
| T-01-84 | Tampering | test fixtures edited to accommodate stricter domain rules could silently drop coverage (rollback proof, tagline fallback, "Listed by" fallback) | medium | mitigate | Assertions kept byte-identical; rollback "save ran" assertion (01-14) | closed |
| T-01-85 | Tampering | test-only direct writes (query-builder update, factory + makeOccurrence) bypass App\Publishing\Actions | low | accept | Accepted (see log) | closed |
| T-01-86 | Tampering | UpdatePublishedEvent / EditEvent: an unrelated save silently rewrites a non-round-tripping rule. Sync then deletes future occurrences, including admin cancellations, audit data and published UIDs (WR-10) | medium | mitigate | UpdatePublishedEvent::canEdit exact round trip (:68) | closed |
| T-01-87 | Tampering | EventsTable Repeats label shows the start date's weekday for a stored rule that says otherwise, so the admin trusts a wrong schedule (WR-10 display) | low | mitigate | EventsTable editableRecurrence label (:71) | closed |
| T-01-88 | Elevation of privilege | EventOccurrence mass assignment of status bypasses ChangeOccurrenceStatus's transition rules, FOR UPDATE lock and status_changed_by/at audit (WR-11) | medium | mitigate | status not fillable on EventOccurrence (EventOccurrence.php:32); ChangeOccurrenceStatusTest:147-149 | closed |
| T-01-89 | Denial of service | the stricter check wrongly marks a rule the app wrote itself as read-only, so the admin cannot edit a legitimate series | low | mitigate | ManualRecurrenceTest 'emitted rules' dataset (:17-66) | closed |
| T-01-SC | Tampering | composer installs | high | mitigate | composer require = php, filament, laravel/framework, tinker, sabre/vobject, spatie/schema-org; CI `npm ci --ignore-scripts` (ci.yml:61). Uncommitted pest-plugin-browser change in a parallel session is NOT part of this phase and needs the legitimacy gate before commit | closed |

*Status: open · closed · open — below high threshold (non-blocking)*
*Severity: critical > high > medium > low — only open threats at or above workflow.security_block_on count toward threats_open*
*Disposition: mitigate (implementation required) · accept (documented risk) · transfer (third-party / later phase)*

### Transferred to Phase 5 (production hardening)

These local-phase threats are closed for Phase 1; their production halves are owned by 05-01 / 05-02 and must be re-verified by `/gsd-secure-phase 5`:
- T-01-03: strong admin password on the server (the local `admin@example.test` credential is dev-only).
- T-01-04: `composer install --no-dev` in Deployer, so Boost never ships.
- T-01-41: Caddy serves only the apex site block (Host-header defence in depth).
- T-01-50: `ops/check.sh` log/backlog thresholds.
- T-01-63: Caddy `Referrer-Policy: strict-origin-when-cross-origin` and `Permissions-Policy` including `web-share=(self)` (01-10 summary note; native share otherwise fails in production).

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-01-01 | T-01-21 | Single-admin product; updated_at and approved_by/at suffice; full audit log out of Phase 1 scope (source 01-04-PLAN) | owner (plan-time) | 2026-10-17 |
| AR-01-02 | T-01-29 | Spec §7.1 requires source link or organiser contact on cards; admin-entered only; contact shown only without a source URL; revisit with Phase 5 privacy notice (source 01-06-PLAN) | owner (plan-time) | 2026-10-17 |
| AR-01-03 | T-01-45 | ops/render-brand-assets.sh is run by hand on the laptop; outputs committed; never run by CI or Deployer; ops/ holds no secrets (source 01-07-PLAN) | owner (plan-time) | 2026-10-17 |
| AR-01-04 | T-01-52 | OPS-04 requires visibility, not alerting; page turns Stale, nav badge, ops:health exits 1 (wired into check.sh in Phase 5) (source 01-09-PLAN) | owner (plan-time) | 2026-10-17 |
| AR-01-05 | T-01-55 | ops:drill is CLI-only, needs shell access; fail window bounded 10-3600 s; job writes only the drill heartbeat (source 01-09-PLAN) | owner (plan-time) | 2026-10-17 |
| AR-01-06 | T-01-85 | Test-only direct writes match the existing factory/makeOccurrence idiom; AGENTS.md Writes rule governs app code (source 01-14-PLAN) | owner (plan-time) | 2026-10-17 |

*Accepted risks do not resurface in future audit runs.*

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-10-17 | 75 | 75 | 0 | orchestrator (gsd-secure-phase; auditor subagent lacked file tools, audit done directly at ASVS L1) |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-10-17
