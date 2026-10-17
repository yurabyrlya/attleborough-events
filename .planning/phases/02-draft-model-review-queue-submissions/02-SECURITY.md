---
phase: "02"
slug: "draft-model-review-queue-submissions"
status: verified
# threats_open = count of OPEN threats at or above workflow.security_block_on severity (the blocking gate)
threats_open: 0
asvs_level: 1
block_on: high
created: "2026-10-17"
---

# Phase 02 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.
> Register built from the `<threat_model>` blocks of plans 02-01 … 02-16 (44 threats T-02-01 … T-02-44 plus the reserved T-02-SC row) and every SUMMARY `## Threat Flags` section (no unregistered flags). Evidence gathered at ASVS L1 (grep/read of code and enforcing tests) on commit de7725f, after the code-review fixes CR-01 (sessions keep no IP, encrypted) and CR-02 (equal start/end refused). The gsd-security-auditor agent has read-only tools in this runtime, so the orchestrator gathered the evidence directly, as in Phase 1.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Internet → `/submit` | Anonymous organisers post event details; bots and floods expected | Event fields, organiser name/contact/website (untrusted, personal data) |
| Browser → Cloudflare Turnstile | Widget script on `/submit` only (D-17); token sent back with the form | Turnstile token (untrusted until siteverify) |
| App → Cloudflare siteverify | Server-side check to a fixed URL with the secret | Secret, token, client IP |
| Admin browser → Filament review queue / Rules | Single admin; Livewire state is client-controlled | Draft edits, approvals, rules |
| Filament/Livewire → domain actions | `App\Publishing\Actions\*` and `SaveRules` re-check admin, transitions and blockers on locked rows | Draft status, event creation |
| Drafts → public pages | Only approved drafts become events, via `ApproveDraft` → `PublishEvent` | Published event facts; organiser contact only when `contact_public` |
| Research probes → third-party sites | Polite probing and fixture capture (RES-01..03) | Robots.txt, public pages; fixtures redacted before commit (public repo) |

---

## Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation (evidence) | Status |
|-----------|----------|-----------|----------|-------------|------------------------|--------|
| T-02-01 | Elevation of privilege | ApproveDraft | high | mitigate | ApproveDraft.php calls AdminGate::authorize first; non-admin test writes nothing | closed |
| T-02-02 | Tampering | Mass assignment of review state | medium | mitigate | EventDraft #[Fillable] excludes review_status, reviewed_by/at, reject_reason, quality_*, event_id, origin, kind; state set via forceFill in DraftWriter/actions | closed |
| T-02-03 | Repudiation | Who published what | low | mitigate | reviewed_by/at on draft + approved_by/at on event in one transaction (ApproveDraft); Log::info ids only | closed |
| T-02-04 | Tampering | Double approval / stale page | medium | mitigate | ApproveDraft: lockForUpdate (line 50) + assertCanTransitionTo (52); partial unique index event_drafts_one_new_per_event; double-approve test | closed |
| T-02-05 | Elevation of privilege | Bypassing the draft to publish | high | mitigate | tests/Unit/Architecture/WriteChokePointTest.php: PublishEvent toOnlyBeUsedIn(App\Publishing\Actions), proven against a planted App\Jobs bypass | closed |
| T-02-06 | Tampering | Client-side approval of a blocked draft | medium | mitigate | ApproveDraft re-checks DraftPublishability blockers on the locked row | closed |
| T-02-07 | Information disclosure | Fixtures in the public repo | high | mitigate | tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php (emails/phones, manifest coverage, P0/P1 from List 1); Town Council fixtures redacted with counts; sha256_raw provenance | closed |
| T-02-08 | Repudiation | Our probing traffic | medium | mitigate | Honest UA with hello@ contact on every probe; ops/probe-sources.sh honours robots and Crawl-delay (24 h robots cache), never saves challenge pages; probes logged in event-sources.md | closed |
| T-02-09 | Tampering | Hostile fixture content (scripts in HTML) | low | accept | Accepted: fixtures are test inputs only, never served (see Accepted Risks) | closed |
| T-02-10 | Spoofing | Local gateway pages mistaken for site responses | medium | mitigate | event-sources.md records gateway observations separately; Town Council/Cloudflare sites re-probed from the home network (D-25/D-26) | closed |
| T-02-11 | Information disclosure | Organiser email on public pages | high | mitigate | EventPageData:70 and OccurrenceCardData:42 gate organiser_contact on contact_public; page and card tests | closed |
| T-02-12 | Spoofing | Phishing links via organiser website | medium | mitigate | SafeLinks::http() (http/https only) in EventPageData/OccurrenceCardData; rel="nofollow ugc noopener"; admin approval before publication | closed |
| T-02-13 | Tampering | Stored XSS via website/contact text | medium | mitigate | No {!! !!} in resources/views; javascript: URL test | closed |
| T-02-14 | Elevation of privilege | RejectDraft / ReopenDraft | high | mitigate | RejectDraft/ReopenDraft call AdminGate::authorize first; non-admin tests | closed |
| T-02-15 | Information disclosure | Rejected drafts' personal data | medium | mitigate | EventDraft MassPrunable prunable(): Spam 30 d, others 90 d (config/review.php); model:prune scheduled daily (routes/console.php); boundary tests; pending/approved never pruned | closed |
| T-02-16 | Elevation of privilege | Rules page / SaveRules | high | mitigate | Rules::canAccess() = panel gate; SaveRules calls AdminGate::authorize; non-admin tests | closed |
| T-02-17 | Denial of service | Keyword regex on titles | low | mitigate | CategoryAssigner preg_quote()s keywords; KEYWORD_MAX_LENGTH 80 in SaveRules + Rules form + varchar(80) | closed |
| T-02-18 | Tampering | Deleting 'other' or categories in use | medium | mitigate | SaveRules refusals judged against submitted state before any write, one transaction; FK restrictOnDelete backstop; Other has no Delete button | closed |
| T-02-19 | Tampering | VenueMatcher SQL with organiser text | high | mitigate | VenueMatcher: every whereRaw/selectRaw binds organiser text as parameters (lines 46-89) | closed |
| T-02-20 | Tampering | Silent venue creation | medium | mitigate | VenueMatcher is read-only; tests assert Venue::count() unchanged | closed |
| T-02-21 | Tampering | Stored XSS via submitted text | high | mitigate | Escaped Blade only (no {!! !!}); plain-text descriptions; script-tag tests | closed |
| T-02-22 | Tampering | CSRF on the public POST | medium | mitigate | @csrf in resources/views/submit/create.blade.php (PreventRequestForgery) | closed |
| T-02-23 | Spoofing | Phishing links in submitted URLs | medium | mitigate | StoreSubmissionRequest: organiser_website/source_url 'url:http,https'; admin approval before publication | closed |
| T-02-24 | Spoofing | Forged or replayed Turnstile token | high | mitigate | TurnstileVerifier: server-side siteverify to a fixed URL; success + action (+ hostname when configured); token <= 2048; fails closed on missing secret/network/odd answers; testing-key answers accepted only outside production Production half → Phase 5: set TURNSTILE_HOSTNAMES=whatsonattleborough.co.uk (hostname check is skipped when empty; review WR-03). | closed |
| T-02-25 | Tampering | Bot skipping the honeypot by omitting it | medium | mitigate | Honeypot field must be present and empty (app/Submissions/Honeypot.php); single-use encrypted timing token (3 s - 120 min) | closed |
| T-02-26 | Denial of service | Production running without Turnstile keys | medium | mitigate | StoreSubmissionRequest::formIsResting() refuses POST in production without keys ("The form is resting") | closed |
| T-02-27 | Information disclosure | Third-party script on public pages | medium | mitigate | NoThirdPartyAssetsTest: only /submit may load the Turnstile script, only with a site key; other public pages load nothing third-party | closed |
| T-02-28 | Information disclosure | Refusal logs | low | mitigate | StoreSubmissionRequest logs 'submission refused' with a reason key only | closed |
| T-02-29 | Denial of service | POST /submit flooding | high | mitigate | routes/web.php throttle:submissions; AppServiceProvider RateLimiter 'submissions' per IP (5/h, 10/day, separate keys); branded 429; RateLimitTest Production half → Phase 5: configure trusted proxies (Caddy/Cloudflare) so the limiter and Turnstile remoteip see the client IP (review WR-02, STATE Phase 5 concern). | closed |
| T-02-30 | Information disclosure | Submitter IP addresses | medium | mitigate | No IP column on drafts (RateLimitTest/SessionPrivacyTest); CR-01 fix: PrivacyDatabaseSessionHandler never writes ip_address/user_agent, session.encrypt=true, old sessions cleared; /privacy updated | closed |
| T-02-31 | Denial of service | Production form without Turnstile keys | medium | mitigate | HealthReport::spamCheck() 'Submission spam check' Failed in production without keys; ops:health exits 1 | closed |
| T-02-32 | Elevation of privilege | Queue pages, actions, global search | high | mitigate | EventDraftResource/EventResource canAccess via AdminGate (global search gated); actions delegate to Publishing actions that re-check AdminGate; non-admin tests | closed |
| T-02-33 | Tampering | Stored XSS in admin (title/description/repeats/raw payload) | high | mitigate | No ->html() in app/Filament/Resources/EventDrafts; XSS dataset tests on title/description/repeats/raw payload | closed |
| T-02-34 | Spoofing | Malicious source/website links shown to the admin | low | mitigate | safe-link.blade.php: SafeLinks::http, rel="nofollow noopener noreferrer" | closed |
| T-02-35 | Tampering | Bulk approve publishing bad drafts | medium | mitigate | Per-record bulkBlockerKeys() + ApproveDraft server re-check; every skip reported (BulkReviewTest) | closed |
| T-02-36 | Tampering | Partial failure rolling back good approvals | low | mitigate | One domain transaction per draft; race tests | closed |
| T-02-37 | Elevation of privilege | Edit page actions | high | mitigate | Edit page behind panel gate; ApproveDraft re-checks AdminGate; non-pending drafts redirect to review | closed |
| T-02-38 | Tampering | Silent loss of a stored rule on save | low | mitigate | hasUneditableRule() + fromReviewForm keep the stored rule; FREQ=DAILY test | closed |
| T-02-39 | Tampering (accidental) | Single-key shortcuts publishing by mistake | medium | mitigate | Keyboard handler ignores inputs/dialogs/modifiers, A opens a confirm, off switch remembered; tests/Browser/ApproveAndNextKeyboardTest.php types "ask" into the reject note | closed |
| T-02-40 | Tampering | Client bypassing a disabled Approve | medium | mitigate | ApproveDraft re-checks blockers on the locked row | closed |
| T-02-41 | Elevation of privilege | SaveRules mappings/geofence sections | high | mitigate | SaveRules (all four tabs) behind Rules::canAccess + AdminGate::authorize | closed |
| T-02-42 | Tampering | Re-seed overwriting admin rules | low | mitigate | RulesSeeder firstOrCreate with case-insensitive match; re-seed tests | closed |
| T-02-43 | Information disclosure | Browser tests loading third-party scripts | low | mitigate | Browser tests bind a passing verifier, no site key in tests (phpunit.xml blanks TURNSTILE_*); assertNoJavaScriptErrors; offline suite | closed |
| T-02-44 | Tampering | Test-only fakes leaking into production | low | mitigate | fakeTurnstile() exists only under tests/ (Pest.php, Browser/UatFixtures.php); nothing in app/ | closed |
| T-02-SC | Supply chain | Package installs | high | accept | No composer/npm packages added in Phase 2 (reserved row in every plan) | closed |

*Status: open · closed · open — below high threshold (non-blocking)*
*Severity: critical > high > medium > low — only open threats at or above workflow.security_block_on count toward threats_open*
*Disposition: mitigate (implementation required) · accept (documented risk) · transfer (third-party)*

### Transferred to Phase 5 (production configuration)

- **T-02-29:** configure trusted proxies (Caddy / Cloudflare) so the per-IP limiter and Turnstile `remoteip` see the real client IP. Until then a proxy would make all visitors share one bucket (review WR-02).
- **T-02-24:** set `TURNSTILE_HOSTNAMES=whatsonattleborough.co.uk` in production; the hostname check is skipped when the list is empty, and System health doesn't yet flag that (review WR-03).

### Related deferred review findings (not blocking, tracked in 02-REVIEW-DISPOSITION.md)

- **WR-06:** a fuzzy venue suggestion becomes the confirmed venue on any Save, after which bulk approve accepts it (data-integrity, D-21).
- **WR-08:** pending drafts, and the organiser email inside `raw_payload`, are never purged and the privacy notice doesn't say so (privacy).
- **WR-01:** the rate limiter counts failed POSTs; **WR-04:** no upper bound on submission end dates; **WR-05:** Rules save can delete rows created after the page loaded; **WR-07:** past-dated drafts can be approved.

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-02-01 | T-02-09 | Fixtures are third-party bytes used only as parser test inputs; never served or rendered; Phase 3 parsers treat them as untrusted text | Plan 02-02 (planned accept) | 2026-10-17 |
| AR-02-02 | T-02-SC | No new composer or npm packages in Phase 2; the supply-chain row is reserved per plan | Plans 02-01 … 02-16 (planned accept) | 2026-10-17 |

*Accepted risks do not resurface in future audit runs.*

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-10-17 | 45 | 45 | 0 | orchestrator (ASVS L1 grep/read evidence; auditor agent read-only) |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] Production-only halves transferred to Phase 5 (T-02-24, T-02-29)
