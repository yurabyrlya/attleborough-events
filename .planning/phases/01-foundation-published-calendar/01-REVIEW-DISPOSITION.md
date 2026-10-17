---
phase: 01
review: 01-REVIEW.md
titles: json
findings:
  - id: WR-10
    severity: warning
    disposition: fixed
    title: "`canEdit()` accepts stored rules that do not round-trip, so saving an unrelated edit silently changes a series' days"
  - id: WR-11
    severity: warning
    disposition: fixed
    title: "`EventOccurrence` still allows mass assignment of `status` (WR-07 applied to `Event` only)"
  - id: IN-01
    severity: info
    disposition: deferred
    title: "`Carbon::parse` still used, contrary to the stated \"no Carbon::parse\" rule (carried forward, unchanged)"
  - id: IN-02
    severity: info
    disposition: deferred
    title: "The calendar timezone is hard-coded beside `config('calendar.timezone')` (carried forward, slightly worsened)"
  - id: IN-03
    severity: info
    disposition: deferred
    title: "Failed-job retention is duplicated; the config key is unused (carried forward, unchanged)"
  - id: IN-04
    severity: info
    disposition: deferred
    title: "The release directory name is published on every public page (carried forward, unchanged)"
  - id: IN-05
    severity: info
    disposition: deferred
    title: "The occurrence generator hard-deletes future rows that carry admin-set status and audit data (carried forward; now reachable via WR-10)"
  - id: IN-06
    severity: info
    disposition: deferred
    title: "SEQUENCE and LAST-MODIFIED change on every daily sync even when nothing changed (carried forward, unchanged)"
  - id: IN-07
    severity: info
    disposition: deferred
    title: "The Retry action ignores the `queue:retry` exit code (carried forward, unchanged)"
  - id: IN-08
    severity: info
    disposition: deferred
    title: "Ops and CI hard-coding and supply-chain hygiene (carried forward, unchanged; line numbers moved)"
  - id: IN-09
    severity: info
    disposition: open
    title: "`PublishableEventData` says it \"mirrors the form's rules\" but skips lengths, URL format and the equal-times rule (new)"
  - id: IN-10
    severity: info
    disposition: open
    title: "Asset tests and gates cannot tell the public stylesheet from the new admin theme (new)"
  - id: IN-11
    severity: info
    disposition: open
    title: "`forceRootUrl(app.url)` makes absolute links point at `localhost:8000` when the app is opened via another host; the provider comment overstates LAN support (new)"
  - id: CR-01
    severity: critical
    disposition: fixed
    title: "Vite asset URLs on every public page (incl. `/`) are built from the request Host header"
  - id: WR-01
    severity: warning
    disposition: fixed
    title: "`PublishEvent` performs no admin authorization (the only write action without it)"
  - id: WR-02
    severity: warning
    disposition: fixed
    title: "`PublishableEventData` does not enforce the invariants it claims to own for the non-Filament path"
  - id: WR-03
    severity: warning
    disposition: fixed
    title: "`ManualRecurrence` accepts impossible UNTIL dates and silently overflows them"
  - id: WR-04
    severity: warning
    disposition: fixed
    title: "Permanent (cacheable) 301 slug redirects plus rename-back can trap browsers in a redirect loop"
  - id: WR-05
    severity: warning
    disposition: fixed
    title: "An event that ends at midnight is displayed as running into the next day"
  - id: WR-06
    severity: warning
    disposition: fixed
    title: "iCalendar UID changes when an event switches between one-off and repeating (breaks the \"never change\" public contract)"
  - id: WR-07
    severity: warning
    disposition: fixed
    title: "`Event` allows mass assignment of `status`, `origin`, `approved_by` and `approved_at`"
  - id: WR-08
    severity: warning
    disposition: fixed
    title: "CI runs PHP 8.5 while the project targets PHP 8.4"
  - id: WR-09
    severity: warning
    disposition: fixed
    title: "The edit page (the only place status actions live) crashes for any recurrence rule `ManualRecurrence` does not emit"
open: 13
total: 23
recorded: 2026-10-17T09:33:32.563Z
---

# Phase 01: Code Review Disposition

| Finding | Severity | Disposition | Source |
|---------|----------|-------------|--------|
| WR-10 | warning | fixed | gap plan 01-15; confirmed by verifier round 3 (2026-10-17) |
| WR-11 | warning | fixed | gap plan 01-15; confirmed by verifier round 3 (2026-10-17) |
| IN-01 | info | deferred | carried forward from first review; owner left info findings for a later pass (2026-10-17) |
| IN-02 | info | deferred | carried forward from first review; owner left info findings for a later pass (2026-10-17) |
| IN-03 | info | deferred | carried forward from first review; owner left info findings for a later pass (2026-10-17) |
| IN-04 | info | deferred | carried forward from first review; owner left info findings for a later pass (2026-10-17) |
| IN-05 | info | deferred | carried forward from first review; owner left info findings for a later pass (2026-10-17) |
| IN-06 | info | deferred | carried forward from first review; owner left info findings for a later pass (2026-10-17) |
| IN-07 | info | deferred | carried forward from first review; owner left info findings for a later pass (2026-10-17) |
| IN-08 | info | deferred | carried forward from first review; owner left info findings for a later pass (2026-10-17) |
| IN-09 | info | open | - |
| IN-10 | info | open | - |
| IN-11 | info | open | - |
| CR-01 | critical | fixed | gap plan 01-11; confirmed by re-review 2026-10-17 (not in the current review) |
| WR-01 | warning | fixed | gap plan 01-12; confirmed by re-review 2026-10-17 (not in the current review) |
| WR-02 | warning | fixed | gap plan 01-12; confirmed by re-review 2026-10-17 (not in the current review) |
| WR-03 | warning | fixed | gap plan 01-12; confirmed by re-review 2026-10-17 (not in the current review) |
| WR-04 | warning | fixed | gap plan 01-11; confirmed by re-review 2026-10-17 (not in the current review) |
| WR-05 | warning | fixed | gap plan 01-11; confirmed by re-review 2026-10-17 (not in the current review) |
| WR-06 | warning | fixed | gap plan 01-13; confirmed by re-review 2026-10-17 (not in the current review) |
| WR-07 | warning | fixed | gap plan 01-12; confirmed by re-review 2026-10-17 (not in the current review) |
| WR-08 | warning | fixed | gap plan 01-11; confirmed by re-review 2026-10-17 (not in the current review) |
| WR-09 | warning | fixed | gap plan 01-13; confirmed by re-review 2026-10-17 (not in the current review) |

Dispositions: `open` (recorded, not yet triaged), `fixed`, `skipped`, `deferred`.
Set `deferred` by hand and put the reason in the Source cell; both are preserved. A `|` in the reason is kept as prose and escaped on the next run.
Re-running the gate keeps every row it can. A row the current review no longer reports is kept and its Source cell flagged, so a finding does not leave this record silently. ONE exception: when a finding id is REUSED by a different finding, the earlier decision cannot keep a row — the id is taken — and it is dropped. A RECORDED decision (anything but `open`) is named on the console when that happens; a row still at `open` is replaced silently, because `open` records no decision to lose.
