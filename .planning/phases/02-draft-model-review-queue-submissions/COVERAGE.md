# API Coverage — Phase 2 external surfaces

> Full coverage by default. Opt-outs are explicit, reasoned decisions.
>
> Phase 2 has one server-side external integration: **Cloudflare Turnstile** (SUB-04, D-17),
> verified by the in-house `App\Submissions\TurnstileVerifier` on Laravel's `Http` client
> (plan 02-09). Tests stay offline via `Http::fake()`.
>
> The source-research plan (02-02) **probes** third-party endpoints (The Events Calendar REST
> `/wp-json/tribe/events/v1/events`, TEC iCal `?ical=1`, RSS/Atom feeds, JSON-LD pages) with
> curl to record evidence and capture fixtures. That is research, not an application
> integration: no app code calls these endpoints in Phase 2. The TEC adapter and feed rungs
> are built in Phases 3-4, which own their own coverage matrices.

## Cloudflare Turnstile

| capability | decision | reason |
|---|---|---|
| siteverify (server-side token validation: `secret`, `response`) | INTEGRATE | |
| siteverify `remoteip` | INTEGRATE | |
| siteverify `idempotency_key` | INTEGRATE | |
| response `success` / `error-codes` handling (fail closed) | INTEGRATE | |
| response `action` check | INTEGRATE | |
| response `hostname` check | INTEGRATE | |
| response `challenge_ts` (token age) | OPT-OUT | not needed: Cloudflare already rejects tokens older than 300 s as `timeout-or-duplicate` |
| response `cdata` (customer data) | OPT-OUT | not needed: no per-request data to bind beyond `action` |
| client widget, implicit rendering (`cf-turnstile` div + `api.js`) | INTEGRATE | |
| client widget `data-action` | INTEGRATE | |
| client widget explicit rendering (`turnstile.render()` JS API) | OPT-OUT | not needed: one static form; implicit rendering needs no custom JS |
| client widget appearance/theme/language options | OPT-OUT | not needed: defaults are accessible; Cloudflare states WCAG 2.2 AA |
| client callbacks (`data-callback`, `expired-callback`, `error-callback`) | OPT-OUT | not needed: the server reports failures on submit; no extra client JS on public pages |
| pre-clearance (cf_clearance cookie for WAF) | OPT-OUT | explicitly out of scope: no Cloudflare WAF challenge integration; would add cookies |
| ephemeral IDs (Enterprise Bot Management) | OPT-OUT | not needed: Enterprise-only feature |
| Turnstile widget management API (create/rotate sitekeys via API) | OPT-OUT | not needed yet: keys are created once in the Cloudflare dashboard at deploy (Phase 5) |
| Turnstile analytics API | OPT-OUT | not needed: spam volume is visible in the review queue and refusal logs |
| Cloudflare always-pass test keys (local development, `.env.example`) | INTEGRATE | |
| Cloudflare always-fail and token-already-spent test keys | OPT-OUT | not needed: failure paths are exercised offline with `Http::fake` siteverify responses (success:false, timeout-or-duplicate, connection failure); real test keys would need network in tests |
