---
phase: 02
review: 02-REVIEW.md
titles: json
findings:
  - id: CR-01
    severity: critical
    disposition: open
    title: "The privacy notice says IPs are only kept in the rate-limit count, but every session row stores the IP and user agent, and failed submissions put the organiser's details in plaintext session payloads"
  - id: CR-02
    severity: critical
    disposition: open
    title: "A same-day end time equal to the start time is accepted and published as a 24-hour event, and the admin form's rule is bypassed on approve and bulk approve"
  - id: WR-01
    severity: warning
    disposition: open
    title: "The rate limiter counts every POST, including validation failures, so a real organiser can be locked out after a few corrections, and the 429 page misstates the window"
  - id: WR-02
    severity: warning
    disposition: open
    title: "Behind Cloudflare/Caddy the rate limit and Turnstile `remoteip` see the proxy address, so one spammer can block every organiser"
  - id: WR-03
    severity: warning
    disposition: open
    title: "The Turnstile hostname check fails open in production, and the health check doesn't notice"
  - id: WR-04
    severity: warning
    disposition: open
    title: "Submission end dates have no upper bound, so a single \"event\" can stay on the calendar for decades"
  - id: WR-05
    severity: warning
    disposition: open
    title: "The Rules screen saves the whole page state, so rules or categories created after the page loaded are silently deleted"
  - id: WR-06
    severity: warning
    disposition: open
    title: "The suggested venue (pg_trgm \u2265 0.3) is pre-filled as if confirmed, so any save turns a guess into a confirmed venue and bulk approve then accepts it"
  - id: WR-07
    severity: warning
    disposition: open
    title: "Drafts whose event has already happened can be approved and bulk approved, creating events with no visible dates"
  - id: WR-08
    severity: warning
    disposition: open
    title: "Pending drafts, and the organiser email in `raw_payload`, are kept forever, which the privacy notice doesn't cover"
  - id: IN-01
    severity: info
    disposition: open
    title: "`ReviewEventDraft::$shownStatus` is a client-writable Livewire property"
  - id: IN-02
    severity: info
    disposition: open
    title: "The category \"Icon\" field is never shown anywhere, and the helper text is wrong"
  - id: IN-03
    severity: info
    disposition: open
    title: "The timing token is spent before the draft is written, so a write failure loses the organiser's form"
  - id: IN-04
    severity: info
    disposition: open
    title: "After a reopen, rejecting again overwrites the earlier note"
  - id: IN-05
    severity: info
    disposition: open
    title: "The keyboard **E** shortcut's edit URL is captured once and goes stale after Reopen"
  - id: IN-06
    severity: info
    disposition: open
    title: "The project rules source file has drifted from AGENTS.md"
  - id: IN-07
    severity: info
    disposition: open
    title: "`ops/probe-sources.sh` follows same-host https\u2192http downgrades and doesn't cap chunked bodies"
open: 17
total: 17
recorded: 2026-10-17T21:49:26.000Z
---

# Phase 02: Code Review Disposition

| Finding | Severity | Disposition | Source |
|---------|----------|-------------|--------|
| CR-01 | critical | open | - |
| CR-02 | critical | open | - |
| WR-01 | warning | open | - |
| WR-02 | warning | open | - |
| WR-03 | warning | open | - |
| WR-04 | warning | open | - |
| WR-05 | warning | open | - |
| WR-06 | warning | open | - |
| WR-07 | warning | open | - |
| WR-08 | warning | open | - |
| IN-01 | info | open | - |
| IN-02 | info | open | - |
| IN-03 | info | open | - |
| IN-04 | info | open | - |
| IN-05 | info | open | - |
| IN-06 | info | open | - |
| IN-07 | info | open | - |

Dispositions: `open` (recorded, not yet triaged), `fixed`, `skipped`, `deferred`.
Set `deferred` by hand and put the reason in the Source cell; both are preserved.
