---
phase: 03
review: 03-REVIEW.md
titles: json
findings:
  - id: CR-01
    severity: critical
    disposition: fixed
    commit: 62cdb67
    title: "The excerpt leaves common UK phone formats in, and bulk approval publishes them (D-17, PIPE-09)"
  - id: CR-02
    severity: critical
    disposition: fixed
    commit: 2fa8f8c
    title: "An edit-page save overwrites a draft that ingestion replaced meanwhile, and approval then sets the baseline to facts that were never published"
  - id: CR-03
    severity: critical
    disposition: fixed
    commit: f9829c2
    title: "Reopening an old rejected draft whose item has since been approved turns a linked item back into a pending new one"
  - id: WR-01
    severity: warning
    disposition: fixed
    commit: 58baa43
    title: "Ingestion and the review actions take locks in opposite orders; a deadlock victim on the ingestion side is silently lost behind conditional GET"
  - id: WR-02
    severity: warning
    disposition: fixed
    commit: 8099ba5
    title: "is_complete latches off for good after a lasting drop in the feed, silently disabling missing-item detection"
  - id: WR-03
    severity: warning
    disposition: fixed
    commit: 899dead
    title: "Editing a live source's URL, item identity or default time zone reuses stale validators and can flood the queue with false cancellations"
  - id: WR-04
    severity: warning
    disposition: deferred
    title: "429/503 Retry-After is parsed and then thrown away"
  - id: WR-05
    severity: warning
    disposition: fixed
    commit: 586d071
    title: "Source health cannot see a source that is permanently blocked or not checking, so System Health says all healthy"
  - id: WR-06
    severity: warning
    disposition: deferred
    title: "Personal data from feeds is kept for ever in source_items"
  - id: WR-07
    severity: warning
    disposition: fixed
    commit: 0edfa1a
    title: "The job timeout covers the fetch but not item processing"
  - id: WR-08
    severity: warning
    disposition: fixed
    commit: 85d0b6b
    title: "Documented domain and DB exceptions surface as 500s, and bulk approval stops halfway"
  - id: IN-01
    severity: info
    disposition: deferred
    title: "SsrfGuard lets the local-use NAT64 prefix and IPv4-translated addresses through"
  - id: IN-02
    severity: info
    disposition: deferred
    title: "A preview can disagree with the live run for items already linked to events"
  - id: IN-03
    severity: info
    disposition: deferred
    title: "A draft's source_url falls back to the .ics feed URL"
---

# Phase 03 — Review disposition

Owner unavailable during sign-off (asked; no answer), so the orchestrator applied the Phase 2 rule plus a pragmatic extension: fix every critical, fix the warnings that can silently produce wrong data or 500s, defer the rest with a reason. Details of each fix: [03-REVIEW-FIX.md](./03-REVIEW-FIX.md).

## Deferred

- **WR-04 Retry-After ignored.** Politeness only; sources are checked once a day and the per-host throttle and robots Crawl-delay already apply. Before launch (STATE.md).
- **WR-06 feed personal data kept for ever in `source_items`.** Needs an owner decision on the retention period (GDPR). Never shown publicly (D-17 holds). Before launch (STATE.md).
- **IN-01 NAT64 / IPv4-translated IPv6 ranges.** Harmless on a server without NAT64; revisit when hardening the Hetzner VPS (Phase 5).
- **IN-02 preview vs live run for linked items.** Preview-only display difference; the live run is correct.
- **IN-03 `source_url` fallback to the `.ics` URL.** Public attribution already falls back to the site home page (03-16), never the feed file; the draft field is admin-only.

## Needs human verification

CR-02, CR-03 (review/approval logic) and WR-01, WR-02 (lock order; completeness rule): covered by tests, but real two-session PostgreSQL deadlocks cannot run inside the test transaction.
