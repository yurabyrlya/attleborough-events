# Phase 4: Extraction Ladder, Geofence & Live Sources - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md; this log preserves the alternatives considered.

**Date:** 2026-10-18
**Phase:** 04-extraction-ladder-geofence-live-sources
**Areas discussed:** Production wording, Attleborough Primary JS array, Selector config, Geofence matching, TEC and RSS, JSON-LD (plus test-run preview and seeding, added during analysis)

**How it ran:** the owner invoked `/gsd-discuss-phase 4`, and the area-selection question returned "user not available". Following the workflow's auto rules (and the owner's standing instruction to work autonomously), every area was selected and the recommended option chosen. Lists are used instead of tables, at the owner's earlier request for readability.

---

## Production wording (SC5 / RES-04)

- **Run P0 sources on the local app now, and move the production half to Phase 5** ✓ (recommended: production only exists after Phase 5)
- Keep "in production" in Phase 4 (would block Phase 4 on Phase 5 infrastructure)
- Pull server provisioning forward into Phase 4 (scope change)

**Choice:** [auto] first option → D-01, D-02. ROADMAP SC5 reworded, Phase 5 SC8 added, RES-04 traceability split, STATE concern resolved.

## Attleborough Primary embedded JS array

- **A small platform adapter (`fse_school_calendar`) via the existing `adapter` column** ✓ (fixture exists; spec rung 2 covers platform adapters; may also cover Rosecroft)
- Manual entry only (calendar stale, mostly pupil-only)
- A generic "regex over script data" option in the selector config (too loose; invites guessing)

**Choice:** [auto] adapter, lowest priority, last wave → D-13.

## Selector configuration

- **Structured, admin-editable fields in the Sources form, with "?" help** ✓ (EXT-04 says per-source configuration; matches the owner's SourceFieldHelp request)
- Raw JSON `parser_config` textarea (error-prone for the admin)
- Per-site PHP adapters in code (needs a developer for each new site)

**Choice:** [auto] structured fields → D-08. Date and time rules → D-09 (no all-day guess; `infer_with_weekday` year policy; arch test).

## Geofence matching

- **Positive match only (postcode district → known venue → place name in venue/address/title); location text with no match is skipped; no location at all → flagged draft** ✓
- Any non-match becomes a flagged draft (would flood the queue with regional events)
- Also scan descriptions (too noisy)
- 8-mile radius with geocoding (third-party service; PIPE-02 doesn't require it)

**Choice:** [auto] first option → D-14, D-15, D-16 (online-only skipped on `filter` sources; mixed attendance counts as physical).

## TEC REST and RSS

- **Town Council switches from ICS to TEC REST in place** ✓ (both use the `tec_post_id` identity; all 24 overlapping fixture ids match; REST gives the full window)
- Keep the Town Council on ICS and use TEC only for new sources (ICS lists only 30 items, so missing detection is weak)
- Add TEC REST as a second Town Council source (duplicate pairs)

**Choice:** [auto] in-place switch, allowed only between identity-compatible methods → D-03, D-04, D-05.

- **RSS is in the DoD with both `date_required` and `link_follow`; the Baptist RSS is seeded as an inactive fallback** ✓
- Ship RSS as "supported, unused" (research did find a qualifying feed, so EXT-05 counts)

**Choice:** [auto] → D-06, D-07.

## JSON-LD

- **Listing-page or single-page JSON-LD; fixtures from Visit Wymondham (listing) and one Visit Norfolk event page (`@graph`); Visit Norfolk not live** ✓
- Build listing → detail link-follow for Visit Norfolk now (many fetches per run against the 240 s deadline; few local events)
- Use Eventbrite pages (its ToS prohibits scraping)

**Choice:** [auto] first option → D-10, D-11, D-12. `eventStatus`: Cancelled/Postponed → cancellation draft; Rescheduled → change detection; MovedOnline → online rule.

## Test-run preview and seeding (added during analysis)

- **Extend the Phase 3 preview to every rung, plus raw matched rows for selectors; save-then-test** ✓ → D-17
- **SourceSeeder adds the new sources inactive and create-only** ✓ → D-18
- **Remove the `Mode: mvp` tag from Phase 4, as the owner did for Phases 1-3** ✓ → D-19

## The agent's Discretion

- TEC page cap and `link_follow` cap (about 20).
- Selector form layout and the preview's raw-rows view.
- Extractor class shapes and dispatch.
- Wording of skip and parse-error messages.
- The Event-subtype list and the online-location keywords.

## Deferred Ideas

- Radius geofence from JSON-LD `geo` coordinates.
- Visit Norfolk live via link-follow (prefer a partner feed).
- Test-running unsaved selector config.
- Retry-After (WR-04) and feed personal-data retention (WR-06): already before launch.
