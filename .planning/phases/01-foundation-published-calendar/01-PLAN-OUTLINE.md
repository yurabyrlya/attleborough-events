# Phase 1: Foundation & Published Calendar - Plan Outline (chunked)

| Plan ID | Objective | Wave | Depends On | Requirements |
|---------|-----------|------|------------|--------------|
| 01-01 | Walking skeleton: scaffold + schema + PublishEvent; admin creates one-off event in Filament, it appears on `/`; single-event timing/expiry with 25 Oct 2026 DST fixture; CI on PG18 (Pint/Larastan/Pest); Laravel Boost dev-only (D-09) | 1 | - | OPS-01, ADM-01, ADM-02, CAL-01, REC-02 |
| 01-04 | Recurring series: weekly + nth/last weekday via sabre with DST fixtures, until/count, edit via UpdatePublishedEvent, daily `calendar:sync-occurrences` | 2 | 01-01 | ADM-02, ADM-03 (edit), CAL-08, REC-01, REC-02 |
| 01-05 | Status control: per-occurrence cancel/hide, event hide/unhide/cancel/reinstate; status survives sync; Cancelled badge | 3 | 01-04, 01-06 | ADM-03, CAL-07 |
| 01-06 | Public filters: Today/This weekend/This week (London overlap, DST), category, Free, family-friendly as plain links, `noindex`, full card fields, D-05 empty states | 2 | 01-01 | CAL-01, CAL-02, CAL-03, CAL-04, CAL-05, CAL-06, CAL-07 |
| 01-07 | Brand & look: vite legitimacy checkpoint then Tailwind 4, self-hosted font, wordmark/icons, favicon + default OG, dark mode (D-01, D-05..D-08); CI builds assets + gates; verified against the local app | 3 | 01-06 | CAL-01, CAL-06, CAL-07 |
| 01-08 | Event page: slug URL + 301, meta/OG/canonical, full description + attribution, per-occurrence `.ics` stable UID; verified in CI + local app | 4 | 01-04, 01-05, 01-06, 01-07 | EVT-01, EVT-02, EVT-06 |
| 01-10 | Event page completion: Event JSON-LD (<=10, eventStatus), Google Calendar link, Web Share + WhatsApp/Facebook/email/copy fallbacks, local event-page check; local phone check | 5 | 01-08 | EVT-03, EVT-06, EVT-07 |
| 01-09 | Ops visibility: heartbeats table, scheduler + queue heartbeats, Filament "System health" page with failed jobs (retry/delete), daily logs 14d; verified locally with schedule:work + queue:work | 3 | 01-04 | OPS-04 |

Notes carried from the outline planner:
- Edge probe: all 40 items placed; 31 -> must_haves.truths in the owning plan (EVT-07 as backstop); 9 unclassified rows (OPS-01, OPS-02, OPS-04, CAL-03, CAL-04, CAL-05, CAL-08, EVT-03, ADM-01) -> flagged assumptions.
- Prohibitions (descriptor-less): no event public without admin publish / no demo data in prod (01-01); no LLM runtime in prod, Boost dev-only (01-01); no jokey copy on dates/prices/cancellations (01-07); cancelled items stay listed until they expire (01-05/06).
- One-way decisions: none (domain, URL scheme, ICS UID format flagged costly).
- Threat IDs: 01-01 uses T-01-01..04 + T-01-SC; later plans continue from T-01-05 (no reuse).
- 01-SKELETON.md written; revised 2026-10-16 (skeleton = 01-01 local end-to-end + CI).
- API-coverage detector not yet run.
- Revision 1 (checker iteration 1): 01-08 split for context budget (135k, 1.35x). 01-10 (wave 5) takes JSON-LD, Google link, sharing, live event-page check and phone UAT with EVT-03, EVT-06 (Google half), EVT-07, their truths, edge rows and threats (T-01-63, T-01-66 moved; new T-01-67..69). 01-08 depends_on now names 01-04 and 01-05 explicitly.
- Scope change 2026-10-16 (owner-approved): Phase 1 is local-dev + CI only. Former 01-02 (server provisioning + owner infra checkpoints) and 01-03 (zero-downtime deploy + production admin) moved to Phase 5 as `.planning/phases/05-launch-readiness/05-01-PLAN.md` and `05-02-PLAN.md`; their threat IDs moved to T-05-01..T-05-12 (Phase 1 IDs T-01-05..T-01-16 are retired, not reused). 01-07 depends_on 01-03 → dropped (now [01-06]); 01-09 depends_on 01-03 → dropped (now [01-04]); waves unchanged. Production-only items from 01-07 (deploy:assets, CI artifact, carry-over, production asset check; T-01-38..40 → T-05-13..15), 01-08/01-10 (production event-page check, zero-downtime during deploy, production phone UAT, Rich Results Test) and 01-09 (production LOG_* values, systemd worker proof, ops-drill.sh, HEALTHY within 7 minutes, check.sh/README edits) were absorbed into 05-02. Push to main runs CI only. D-02 (whatsonattleborough.co.uk) stays the brand name; only purchase/hosting are deferred.

## OUTLINE COMPLETE
