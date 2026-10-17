---
phase: 02
review: 02-REVIEW.md
titles: json
findings:
  - id: CR-01
    severity: critical
    disposition: fixed
    title: "The privacy notice says IPs are only kept in the rate-limit count, but every session row stores the IP and user agent, and failed submissions put the organiser's details in plaintext session payloads"
  - id: CR-02
    severity: critical
    disposition: fixed
    title: "A same-day end time equal to the start time is accepted and published as a 24-hour event, and the admin form's rule is bypassed on approve and bulk approve"
  - id: WR-01
    severity: warning
    disposition: deferred
    title: "The rate limiter counts every POST, including validation failures, so a real organiser can be locked out after a few corrections, and the 429 page misstates the window"
  - id: WR-02
    severity: warning
    disposition: deferred
    title: "Behind Cloudflare/Caddy the rate limit and Turnstile `remoteip` see the proxy address, so one spammer can block every organiser"
  - id: WR-03
    severity: warning
    disposition: deferred
    title: "The Turnstile hostname check fails open in production, and the health check doesn't notice"
  - id: WR-04
    severity: warning
    disposition: deferred
    title: "Submission end dates have no upper bound, so a single \"event\" can stay on the calendar for decades"
  - id: WR-05
    severity: warning
    disposition: deferred
    title: "The Rules screen saves the whole page state, so rules or categories created after the page loaded are silently deleted"
  - id: WR-06
    severity: warning
    disposition: deferred
    title: "The suggested venue (pg_trgm \u2265 0.3) is pre-filled as if confirmed, so any save turns a guess into a confirmed venue and bulk approve then accepts it"
  - id: WR-07
    severity: warning
    disposition: deferred
    title: "Drafts whose event has already happened can be approved and bulk approved, creating events with no visible dates"
  - id: WR-08
    severity: warning
    disposition: deferred
    title: "Pending drafts, and the organiser email in `raw_payload`, are kept forever, which the privacy notice doesn't cover"
  - id: IN-01
    severity: info
    disposition: deferred
    title: "`ReviewEventDraft::$shownStatus` is a client-writable Livewire property"
  - id: IN-02
    severity: info
    disposition: deferred
    title: "The category \"Icon\" field is never shown anywhere, and the helper text is wrong"
  - id: IN-03
    severity: info
    disposition: deferred
    title: "The timing token is spent before the draft is written, so a write failure loses the organiser's form"
  - id: IN-04
    severity: info
    disposition: deferred
    title: "After a reopen, rejecting again overwrites the earlier note"
  - id: IN-05
    severity: info
    disposition: deferred
    title: "The keyboard **E** shortcut's edit URL is captured once and goes stale after Reopen"
  - id: IN-06
    severity: info
    disposition: deferred
    title: "The project rules source file has drifted from AGENTS.md"
  - id: IN-07
    severity: info
    disposition: deferred
    title: "`ops/probe-sources.sh` follows same-host https\u2192http downgrades and doesn't cap chunked bodies"
open: 0
total: 17
recorded: 2026-10-17T21:49:26.000Z
---

# Phase 02: Code Review Disposition

| Finding | Severity | Disposition | Source |
|---------|----------|-------------|--------|
| CR-01 | critical | fixed | 2355016 (gsd-code-fixer, 2026-10-17); see 02-REVIEW-FIX.md |
| CR-02 | critical | fixed | 2ff83f7 (gsd-code-fixer, 2026-10-17); see 02-REVIEW-FIX.md |
| WR-01 | warning | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| WR-02 | warning | deferred | already a Phase 5 item (trusted proxies, STATE.md); owner deferred 2026-10-17 |
| WR-03 | warning | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| WR-04 | warning | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| WR-05 | warning | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| WR-06 | warning | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| WR-07 | warning | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| WR-08 | warning | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| IN-01 | info | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| IN-02 | info | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| IN-03 | info | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| IN-04 | info | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| IN-05 | info | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| IN-06 | info | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |
| IN-07 | info | deferred | owner decision 2026-10-17: fix only the critical findings before verification; revisit before Phase 2 sign-off or in a later quick task |

Dispositions: `open` (recorded, not yet triaged), `fixed`, `skipped`, `deferred`.
Set `deferred` by hand and put the reason in the Source cell; both are preserved.
