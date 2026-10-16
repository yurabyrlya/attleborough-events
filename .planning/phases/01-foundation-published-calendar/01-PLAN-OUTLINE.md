# Phase 1: Foundation & Published Calendar - Plan Outline (chunked)

| Plan ID | Objective | Wave | Depends On | Requirements |
|---------|-----------|------|------------|--------------|
| 01-01 | Walking skeleton: scaffold + schema + PublishEvent; admin creates one-off event in Filament, it appears on `/`; single-event timing/expiry with 25 Oct 2026 DST fixture; CI on PG18 (Pint/Larastan/Pest); Laravel Boost dev-only (D-09) | 1 | - | OPS-01, ADM-01, ADM-02, CAL-01, REC-02 |
| 01-02 | Owner infra checkpoints (domain, Cloudflare DNS + email routing D-02..D-04, Hetzner server + OS-image decision), then tracer: provisioned VPS serves a PHP probe over HTTPS at the apex with www redirect, journald cap, Caddy log roll | 1 | - | OPS-02, OPS-04 |
| 01-03 | Zero-downtime deploy: tracer first `dep deploy` from laptop; owner GitHub secrets + production admin; CI deploy job; curl non-200 probe during deploy | 2 | 01-01, 01-02 | OPS-02, ADM-01 |
| 01-04 | Recurring series: weekly + nth/last weekday via sabre with DST fixtures, until/count, edit via UpdatePublishedEvent, daily `calendar:sync-occurrences` | 2 | 01-01 | ADM-02, ADM-03 (edit), CAL-08, REC-01, REC-02 |
| 01-05 | Status control: per-occurrence cancel/hide, event hide/unhide/cancel/reinstate; status survives sync; Cancelled badge | 3 | 01-04, 01-06 | ADM-03, CAL-07 |
| 01-06 | Public filters: Today/This weekend/This week (London overlap, DST), category, Free, family-friendly as plain links, `noindex`, full card fields, D-05 empty states | 2 | 01-01 | CAL-01, CAL-02, CAL-03, CAL-04, CAL-05, CAL-06, CAL-07 |
| 01-07 | Brand & look: vite legitimacy checkpoint then Tailwind 4, self-hosted font, wordmark/icons, favicon + default OG, dark mode (D-01, D-05..D-08); CI builds assets, deploy uploads | 3 | 01-03, 01-06 | CAL-01, CAL-06, CAL-07 |
| 01-08 | Event page: slug URL + 301, meta/OG/canonical, full description + attribution, Event JSON-LD (<=10, eventStatus), per-occurrence `.ics` stable UID, Google link, Web Share + WhatsApp/Facebook/email/copy fallbacks; phone UAT | 4 | 01-06, 01-07 | EVT-01, EVT-02, EVT-03, EVT-06, EVT-07 |
| 01-09 | Ops visibility: heartbeats table, scheduler + queue heartbeats, Filament "System health" page with failed jobs (retry/delete), daily logs 14d; verified in prod | 3 | 01-03, 01-04 | OPS-04 |

Notes carried from the outline planner:
- Edge probe: all 40 items placed; 31 -> must_haves.truths in the owning plan (EVT-07 as backstop); 9 unclassified rows (OPS-01, OPS-02, OPS-04, CAL-03, CAL-04, CAL-05, CAL-08, EVT-03, ADM-01) -> flagged assumptions.
- Prohibitions (descriptor-less): no event public without admin publish / no demo data in prod (01-01); no LLM runtime in prod, Boost dev-only (01-01); no jokey copy on dates/prices/cancellations (01-07); cancelled items stay listed until they expire (01-05/06).
- One-way decisions: none (domain, URL scheme, ICS UID format flagged costly).
- Threat IDs: 01-01 uses T-01-01..04 + T-01-SC; later plans continue from T-01-05 (no reuse).
- 01-SKELETON.md still to be written (with 01-02 run).
- API-coverage detector not yet run.

## OUTLINE COMPLETE
