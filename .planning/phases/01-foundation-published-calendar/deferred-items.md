# Phase 01: Deferred Items

Out-of-scope discoveries logged by executors (not fixed in the plan that found them).

## From 01-11 (2026-10-17)

### Existing "never echoes the request Host header" tests do not spoof the Host

- **Files:** `tests/Feature/Public/EventSeoTest.php:139`, `BrandHeadTest.php:72`, `EventCalendarAndShareTest.php:160/288`, `EventJsonLdTest.php:204` (all use `$this->withHeader('Host', 'evil.example')->get('/relative/path')`).
- **Finding:** Laravel's test client builds the request from the URI. A relative path gets `config('app.url')`'s host, and Symfony's `Request::create()` then overwrites `HTTP_HOST` with the URI host. The `Host` header never reaches the app, so these assertions pass whether or not the code is Host-safe. 01-11 proved this: its first RED run with that idiom rendered `http://localhost:8000/build/...` even with CR-01 unfixed.
- **Working idiom (used by 01-11 `HostHeaderAssetsTest::spoofedHostGet()`):** request the absolute URI, e.g. `$this->withHeader('Host', 'evil.example')->get('http://evil.example/events/quiz-night')`.
- **Why deferred:** 01-11 must not edit those files (01-14 owns their fixture updates). The production code is now Host-proof (01-11 forceRootUrl plus root-relative Vite paths), and the new regression test covers `/`, the event page and `/admin` with a real spoof. The old tests are weak, but they don't hide a live bug.
- **Suggested owner:** 01-14 or a later test-hardening pass: switch the idiom to the absolute evil.example URI (the `.ics` case included).

## From 01-13 (2026-10-17)

### `.ai/guidelines/attleborough.md` is behind AGENTS.md

- **Files:** `.ai/guidelines/attleborough.md` (the Boost source of the "attleborough rules" block in AGENTS.md).
- **Finding:** AGENTS.md was edited directly by 01-12 (the Panel access line naming `AdminGate`) and 01-13 (the Writes bullet for the `UpdatePublishedEvent` one-off/repeating and unsupported-rule guards). The `.ai` source has neither line. Running `php artisan boost:update` or `boost:install` would regenerate AGENTS.md from the source and silently drop both rules.
- **Why deferred:** both plans scoped the rule change to AGENTS.md (`files_modified`), and the drift started before 01-13.
- **Suggested owner:** the next plan or quick task that touches project rules: copy both lines into `.ai/guidelines/attleborough.md`, then confirm a Boost regenerate leaves AGENTS.md unchanged.

### Concurrent test runs share `attleborough_testing`

- **Finding:** while 01-13 ran, a parallel quick task (`quick-261017-e46`) committed on main and ran its own Pest suite. Both processes use `RefreshDatabase` on the same PostgreSQL database, so one run's schema rebuild removed tables mid-run in the other (random `SQLSTATE[42P01] relation "categories" does not exist` failures in unrelated Public tests). The same suite passed 451/451 once it was quiet, and 465/465 at the end of the plan.
- **Suggested owner:** a tooling quick task if parallel sessions become normal: per-process test databases (for example `DB_DATABASE=attleborough_testing_${TEST_TOKEN}` with Pest `--parallel`, or one database per worktree).

