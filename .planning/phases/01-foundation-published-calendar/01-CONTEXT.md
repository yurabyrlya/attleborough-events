# Phase 1: Foundation & Published Calendar - Context

**Gathered:** 2026-10-16
**Status:** Ready for planning

<domain>
## Phase Boundary

Residents can open a live, mobile-first calendar at the production HTTPS URL (`https://whatsonattleborough.co.uk`) and browse admin-entered events, including recurring ones. They can open event pages, add occurrences to their calendar and share them. The admin logs in to Filament and creates, edits, hides and cancels events and occurrences. CI runs on every push and deploys to the VPS with zero downtime, and basic monitoring (scheduler heartbeat, failed jobs, log rotation) is visible.

Requirements: OPS-01, OPS-02, OPS-04, CAL-01..08, EVT-01, EVT-02, EVT-03, EVT-06, EVT-07, REC-01, REC-02, ADM-01, ADM-02, ADM-03.

Not in this phase: drafts, review queue and submissions (Phase 2); any ingestion or crawling (Phases 3-4); sitemap, expired-page policy, report-a-problem, feeds, privacy/legal pages, analytics, WCAG audit and the backup restore drill (Phase 5). Phase 1 still builds to WCAG 2.2 AA from the start.

</domain>

<decisions>
## Implementation Decisions

### Brand, domain & look
- **D-01:** The public site name is **"What's On Attleborough"**. Use it in the header, `<title>` suffix, Open Graph `og:site_name` and share text. The Laravel app/repo name stays `attleborough-events`.
- **D-02:** The production domain is **`whatsonattleborough.co.uk`**, canonical with no `www` (`www` 301-redirects to the apex). — **Reversibility:** costly — canonical URLs, event slugs shared on social media, OG metadata and (from Phase 3) the crawler User-Agent contact URL all embed the domain; changing it later needs 301s and loses accumulated link equity.
- **D-03:** DNS is hosted on **Cloudflare (free plan)**. Cloudflare is already in the stack for Turnstile (Phase 2) and R2 backups (Phase 5), and provides free email routing. The decision about the orange-cloud proxy is left to the planner; Caddy handles TLS on the origin either way.
- **D-04:** The public contact email is **`hello@whatsonattleborough.co.uk`**, forwarded to the owner via Cloudflare Email Routing. Configure it as `MAIL_FROM_ADDRESS` / contact address in config. It will be reused for the crawler User-Agent (Phase 3) and the privacy notice (Phase 5).
- **D-05:** The voice is a **friendly community noticeboard**: warm, plain English, a little personality (e.g. empty state "Nothing on today. Why not check the weekend?"), and never jokey on factual fields (dates, prices, cancellations). British English spelling and UK date formats (e.g. "Sat 14 Nov, 7:30pm").
- **D-06:** The visual style is **clean and local**: white background, **one warm accent colour (Norfolk green)**, clear readable type, and **simple category icons** used as image placeholders (no third-party images). The accent must pass WCAG 2.2 AA contrast for text and focus states in both themes.
- **D-07:** The logo is a **text wordmark plus a small calendar-pin icon**, delivered as inline SVG in the repo. Favicon and apple-touch-icon are derived from the icon. A default OG image is generated from wordmark + accent (static asset is fine).
- **D-08:** **Dark mode follows the device setting** (`prefers-color-scheme`, no toggle). Tailwind 4 dark variant; both themes meet AA contrast.

### Carried forward from project level (do not re-ask)
- Stack: Laravel ^13.37, PHP 8.5 (Composer floor ^8.4), PostgreSQL 18 (+ `pg_trgm`), Filament ^5.10, Livewire ^4.4, Tailwind ^4.3 + Vite 8, Alpine only on non-Livewire public pages, Pest 5, Larastan, Pint. No Redis (database queue, cache and session). Tests run on PostgreSQL, never SQLite, with `Http::preventStrayRequests()`.
- Hosting: Hetzner CX23, Ubuntu 26.04, Caddy, PHP-FPM 8.5, PG 18, systemd queue workers, cron `schedule:run`, Deployer 8 via GitHub Actions. Deploy to the VPS in this phase.
- Recurrence: sabre/vobject ^5 is the only expansion engine. Manual series are stored as RRULE (synthetic VEVENT) and expanded over a rolling 90-day horizon. Occurrences are upserted on a recurrence key, preserving admin-set status. Always expand in Europe/London and store UTC. Include DST fixtures (25 Oct 2026, 28 Mar 2027).
- Expiry: end time, or start + 3h if no end, or end of day for all-day events. A denormalised `expires_at` is used for public window queries.
- Filters: Today / This weekend / This week (Europe/London overlap semantics per REQUIREMENTS CAL-02), category, Free, family-friendly flag. These are plain query-string links that work without JS, and filter URLs are `noindex`.
- Mobile list-first, no month grid; date-grouped headings; `<time datetime>`; 24x24px minimum targets; "Cancelled" shown as text.
- Only review actions and manual entry write published events (single choke point; Phase 1 implements manual entry through the same domain action that Phase 2's approval will reuse).

### Owner prerequisites (human actions before or while executing)
- Register `whatsonattleborough.co.uk` (confirm availability at the registrar first; the `.com` is also unregistered if wanted defensively).
- Create or confirm accounts: Cloudflare (DNS + Email Routing), Hetzner Cloud (CX23), GitHub Actions secrets for deploy.
- The planner should turn these into `checkpoint:human` tasks at the right points.

### Agent's Discretion
The owner chose not to discuss these areas; the planner/researcher decides, guided by the spec, research and decisions above:
- **Home calendar layout:** default view (all upcoming, grouped by day), filter chip presentation, card density, how far ahead the list goes (pagination or "load more" via plain links), and empty states (in the D-05 voice).
- **Manual entry & venues:** free-text venue vs reusable `venues` table (the spec data model includes `venues`), the recurrence form UX (weekly / nth weekday of month, with optional end date or count, and single-occurrence cancel/hide), and price and family-friendly inputs.
- **Event page & sharing details:** how upcoming dates of a series are listed, attribution text for manual events (source note/URL), share copy, and per-event OG (default OG image acceptable in Phase 1).
- Exact Norfolk-green hex value, font choice (self-hosted, no third-party font CDNs) and icon set (must be self-hosted SVG).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Product scope & requirements
- `docs/product-specification.md` — v1.1 baseline spec. §7.1 public calendar, §7.2 categories, §7.6 admin manual entry, §8.1 publication/expiry, §8.5 recurrence, §9 data model, §10 architecture principles
- `.planning/REQUIREMENTS.md` — Phase 1 requirement IDs and exact wording (CAL, EVT, REC, ADM, OPS)
- `.planning/ROADMAP.md` — Phase 1 goal and success criteria
- `.planning/PROJECT.md` — constraints (budget, single admin, determinism), key decisions

### Research
- `.planning/research/SUMMARY.md` — spec deltas D1-D20 (D1 PHP floor, D5 add-to-calendar/share, D6 timezone/DST rules, D10 hosting, D17 manual recurrence, D18 expired-page policy for later)
- `.planning/research/STACK.md` — pinned versions, install commands, hosting/deploy recipe, testing approach
- `.planning/research/ARCHITECTURE.md` — two write models + read model, occurrence materialisation, `expires_at`, state enums, folder layout, build order
- `.planning/research/PITFALLS.md` — timezone/DST pitfalls, Laravel ops on a small VPS (workers, scheduler heartbeat, log rotation, zero-downtime deploy)
- `.planning/research/FEATURES.md` — public browsing, detail, add-to-calendar and share patterns from comparable sites

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- None. Greenfield: the repo contains only docs, `.planning/` and GSD tooling under `.github/`.

### Established Patterns
- None yet. Phase 1 establishes them (domain-grouped Laravel monolith, thin jobs / fat actions, backed-enum state machines, per ARCHITECTURE.md).

### Integration Points
- GitHub repo `yurabyrlya/attleborough-events` (public, `main`): CI/CD via GitHub Actions.
- `.gitignore` already covers Laravel paths.

</code_context>

<specifics>
## Specific Ideas

- Brand: "What's On Attleborough". The tagline can echo the spec's value proposition: "See what's on in Attleborough in one place."
- Look and feel: a friendly local noticeboard, white with a Norfolk-green accent, category icons instead of photos.

</specifics>

<deferred>
## Deferred Ideas

None. The discussion stayed within phase scope.

</deferred>

---

*Phase: 01-foundation-published-calendar*
*Context gathered: 2026-10-16*
