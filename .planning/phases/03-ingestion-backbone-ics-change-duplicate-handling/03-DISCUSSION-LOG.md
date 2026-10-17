# Phase 3: Ingestion Backbone, ICS & Change/Duplicate Handling - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-17
**Phase:** 03-ingestion-backbone-ics-change-duplicate-handling
**Areas discussed:** Feed volume and noise, Updates and cancellations, Duplicates and ownership, Public attribution and excerpts

---

## Feed volume and noise

| Question | Options | Selected |
|---|---|---|
| Adding a new source | Test run preview then "Create drafts" / No preview, draft straight away | Test run preview |
| Default check frequency | Daily early morning / Every 6 hours / Per source, default daily | Daily early morning |
| Feeds listing every week as a separate item | One draft per item + "Approve all like this" / One draft per item only / Group into suggested series | One draft per item + "Approve all like this" |
| Unwanted items | Per-source skip/keep title rules / No filtering | Per-source title rules |

## Updates and cancellations

| Question | Options | Selected |
|---|---|---|
| Which changes create an update draft | Facts only (title, date/time, venue, price, family, cancelled) / Any change incl. description | Facts only |
| Applying an update | Apply all changed fields / Tick fields | Apply all |
| Cancelling one date or a one-off | Keep visible as "Cancelled" / Hide | Keep visible as "Cancelled" |
| Changes to events happening soon | "Urgent" badge + top of queue (48 h) / Normal order | Urgent badge + top |

## Duplicates and ownership

| Question | Options | Selected |
|---|---|---|
| Whose details win on "Mark as duplicate" | Keep existing published details / Take the feed's version | Keep existing |
| After linking | Linked source keeps watching for changes / Attribution only | Keeps watching |
| Same event in two feeds | Organiser's own source is main attribution, others "Also listed by" / First approved wins | Organiser's own source |
| Duplicate warning view | Side by side with reason + buttons / Warning with link only | Side by side |

## Public attribution and excerpts

| Question | Options | Selected |
|---|---|---|
| Where attribution shows | Event page + short line on calendar card / Event page only | Page and card |
| Description on imported events | Auto excerpt (200 chars, contacts stripped), editable, "Read more at…" / Auto only / Admin writes own summary | Auto excerpt, editable |
| Where "Read more" links | Event's own page when given, else source events page / Always source events page | Event's own page |
| Organiser details from feeds | Never show; source as attribution / Show name only | Never show |

## Final choices

| Question | Options | Selected |
|---|---|---|
| Starting sources | Pre-load Baptist Church, Town Council, Academy ICS as inactive / Admin adds them | Pre-load inactive |
| Phase 3 MVP tag | Remove / Keep | Remove |

**Notes:** Seeding inactive source records is an owner clarification of the "seeders seed reference data only" rule (configuration, not demo events).

## Deferred Ideas

- Grouping per-occurrence feed items into suggested series (declined; revisit if volume grows).
- Per-field apply for updates (declined; revisit if needed).
