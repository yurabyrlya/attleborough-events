---
phase: 01
review: 01-REVIEW.md
titles: json
findings:
  - id: CR-01
    severity: critical
    disposition: open
    title: "Vite asset URLs on every public page (incl. `/`) are built from the request Host header"
  - id: WR-01
    severity: warning
    disposition: open
    title: "`PublishEvent` performs no admin authorization (the only write action without it)"
  - id: WR-02
    severity: warning
    disposition: open
    title: "`PublishableEventData` does not enforce the invariants it claims to own for the non-Filament path"
  - id: WR-03
    severity: warning
    disposition: open
    title: "`ManualRecurrence` accepts impossible UNTIL dates and silently overflows them"
  - id: WR-04
    severity: warning
    disposition: open
    title: "Permanent (cacheable) 301 slug redirects plus rename-back can trap browsers in a redirect loop"
  - id: WR-05
    severity: warning
    disposition: open
    title: "An event that ends at midnight is displayed as running into the next day"
  - id: WR-06
    severity: warning
    disposition: open
    title: "iCalendar UID changes when an event switches between one-off and repeating (breaks the \"never change\" public contract)"
  - id: WR-07
    severity: warning
    disposition: open
    title: "`Event` allows mass assignment of `status`, `origin`, `approved_by` and `approved_at`"
  - id: WR-08
    severity: warning
    disposition: open
    title: "CI runs PHP 8.5 while the project targets PHP 8.4"
  - id: WR-09
    severity: warning
    disposition: open
    title: "The edit page (the only place status actions live) crashes for any recurrence rule `ManualRecurrence` does not emit"
  - id: IN-01
    severity: info
    disposition: open
    title: "`Carbon::parse` still used, contrary to the stated \"no Carbon::parse\" rule"
  - id: IN-02
    severity: info
    disposition: open
    title: "The calendar timezone is hard-coded in several places beside `config('calendar.timezone')`"
  - id: IN-03
    severity: info
    disposition: open
    title: "Failed-job retention is duplicated; the config key is unused"
  - id: IN-04
    severity: info
    disposition: open
    title: "The release directory name is published on every public page"
  - id: IN-05
    severity: info
    disposition: open
    title: "The occurrence generator hard-deletes future rows that carry admin-set status and audit data"
  - id: IN-06
    severity: info
    disposition: open
    title: "SEQUENCE and LAST-MODIFIED change on every daily sync even when nothing changed"
  - id: IN-07
    severity: info
    disposition: open
    title: "The Retry action ignores the `queue:retry` exit code"
  - id: IN-08
    severity: info
    disposition: open
    title: "Ops and CI hard-coding and supply-chain hygiene"
open: 18
total: 18
recorded: 2026-10-17T07:45:10.633Z
---

# Phase 01: Code Review Disposition

| Finding | Severity | Disposition | Source |
|---------|----------|-------------|--------|
| CR-01 | critical | open | - |
| WR-01 | warning | open | - |
| WR-02 | warning | open | - |
| WR-03 | warning | open | - |
| WR-04 | warning | open | - |
| WR-05 | warning | open | - |
| WR-06 | warning | open | - |
| WR-07 | warning | open | - |
| WR-08 | warning | open | - |
| WR-09 | warning | open | - |
| IN-01 | info | open | - |
| IN-02 | info | open | - |
| IN-03 | info | open | - |
| IN-04 | info | open | - |
| IN-05 | info | open | - |
| IN-06 | info | open | - |
| IN-07 | info | open | - |
| IN-08 | info | open | - |

Dispositions: `open` (recorded, not yet triaged), `fixed`, `skipped`, `deferred`.
Set `deferred` by hand and put the reason in the Source cell; both are preserved. A `|` in the reason is kept as prose and escaped on the next run.
Re-running the gate keeps every row it can. A row the current review no longer reports is kept and its Source cell flagged, so a finding does not leave this record silently. ONE exception: when a finding id is REUSED by a different finding, the earlier decision cannot keep a row — the id is taken — and it is dropped. A RECORDED decision (anything but `open`) is named on the console when that happens; a row still at `open` is replaced silently, because `open` records no decision to lose.
