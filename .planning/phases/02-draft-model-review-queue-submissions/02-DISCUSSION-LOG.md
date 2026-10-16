# Phase 2: Draft Model, Review Queue & Submissions - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md. This log preserves the alternatives considered.

**Date:** 2026-10-16
**Phase:** 02-draft-model-review-queue-submissions
**Areas discussed:** Submission form & organiser experience, Draft → event conversion, Review queue workflow, Source research process

---

## Submission form & organiser experience

| Option | Description | Selected |
|--------|-------------|----------|
| Pick or type venue | Known venues `<select>` + "Somewhere else" free-text fields; no-JS friendly | ✓ |
| Free text only | Venue name/address/postcode; admin matches on review | |
| You decide | | |

| Option | Description | Selected |
|--------|-------------|----------|
| On-screen only | "Thanks, we'll review it" page with reference; no outbound email | ✓ |
| On-screen + receipt email | One-off transactional email; needs mail provider now | |
| You decide | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Organiser choice | Name public, website public, email public only on opt-in | ✓ |
| Admin-only contact | Only name + original link public | |
| All public | Everything submitted shown | |
| You decide | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Interim /privacy page | Short page now; Phase 5 expands same URL | ✓ |
| Pull full notice forward | Full LEG-01 in Phase 2 | |
| Gate form until Phase 5 | Build but don't expose | |
| You decide | | |

**User's choice:** Recommended option on all four.

---

## Draft → event conversion

| Option | Description | Selected |
|--------|-------------|----------|
| Suggest & confirm | Exact/alias/postcode then pg_trgm suggestion; admin confirms or creates; never silent | ✓ |
| Auto-create | Auto-match exact, else auto-create venue | |
| Admin picks | No suggestion | |
| You decide | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Admin translates | "Repeats" text shown beside recurrence controls; unset rule lowers quality and blocks bulk | ✓ |
| Parse simple phrases | Deterministic parse of fixed phrases into suggested rule | |
| Structured form controls | Replace free text on public form | |
| You decide | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Draft + auto-approve | "Save & publish" creates manual draft and approves in one transaction | ✓ |
| Two buttons | Save & publish / Save as draft | |
| Always queue | Manual entries approved separately | |
| You decide | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Submitter as source default | Organiser pick wins; "Not sure" falls through to keyword rules → Other | ✓ |
| Rules first | Keyword rules win; pick is a hint | |
| You decide | | |

**User's choice:** Recommended option on all four.

---

## Review queue workflow

| Option | Description | Selected |
|--------|-------------|----------|
| Soonest event first | Tie-break oldest submitted | ✓ |
| Oldest submitted first | FIFO | |
| High quality first | Easy wins first | |
| You decide | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Single letters, guarded | A/E/R/S·J/K/?; disabled in inputs; approve blocked when not publishable | ✓ |
| Modifier combos | Ctrl/Cmd+Enter etc. | |
| You decide | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Presets + note | Optional preset reason + optional note | ✓ |
| Free text only | | |
| You decide | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Keep then purge | Rejected filter + undo; purge 90 days (spam 30) | ✓ |
| Keep forever | | |
| Delete spam now | Spam deleted immediately, others 90 days | |
| You decide | | |

**User's choice:** Recommended option on all four.

---

## Source research process

| Option | Description | Selected |
|--------|-------------|----------|
| Agent first, owner fills | Agent web-fetch first pass + live probing; owner adds missing local URLs | ✓ |
| Owner first | Owner URL list, agent verifies | |
| Owner only | Agent only formats/checks | |
| You decide | | |

| Option | Description | Selected |
|--------|-------------|----------|
| tests/Fixtures + manifest, redacted | Raw bytes per source/date + manifest; PII redacted (public repo) | ✓ |
| Raw unredacted | | |
| You decide | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Parallel early | First-wave research plan with checkpoint:human URL hand-off | ✓ |
| Start now | Before Phase 2 planning | |
| Last | After code plans | |
| You decide | | |

| Option | Description | Selected |
|--------|-------------|----------|
| Conservative + ask permission | ToS prohibition → excluded/P3; unclear → ask permission | |
| robots-only | robots.txt is the bar | |
| You decide | | |

**User's choice (free text):** "list everything that you can crawl and list separately what you cannot crawl for review after"
**Notes:** Confirmed as two lists: Crawlable (P0–P2) and Not crawlable for owner review (with reasons). Follow-up: ToS-unclear sources go on the **review list** until the owner clears them.

---

## the agent's Discretion

- Quality score weights and reason wording
- Seeded keyword rules and category mappings
- `event_drafts` schema details beyond the decided fields
- Public form URL/layout, rate-limit thresholds, Turnstile failure UX, honeypot technique
- Filament page structure for queue / Approve & next / Rules; search implementation
- Organiser reference code format

## Deferred Ideas

- Email notifications to organisers on approve/reject (backlog)
- Deterministic parsing of common "repeats" phrases (declined for Phase 2; revisit if volume grows)
