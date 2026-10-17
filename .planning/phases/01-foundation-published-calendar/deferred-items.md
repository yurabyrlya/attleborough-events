# Phase 01: Deferred Items

Out-of-scope discoveries logged by executors (not fixed in the plan that found them).

## From 01-11 (2026-10-17)

### Existing "never echoes the request Host header" tests do not spoof the Host

- **Files:** `tests/Feature/Public/EventSeoTest.php:139`, `BrandHeadTest.php:72`, `EventCalendarAndShareTest.php:160/288`, `EventJsonLdTest.php:204` (all use `$this->withHeader('Host', 'evil.example')->get('/relative/path')`).
- **Finding:** Laravel's test client builds the request from the URI. A relative path gets `config('app.url')`'s host, and Symfony's `Request::create()` then overwrites `HTTP_HOST` with the URI host. The `Host` header never reaches the app, so these assertions pass whether or not the code is Host-safe. 01-11 proved this: its first RED run with that idiom rendered `http://localhost:8000/build/...` even with CR-01 unfixed.
- **Working idiom (used by 01-11 `HostHeaderAssetsTest::spoofedHostGet()`):** request the absolute URI, e.g. `$this->withHeader('Host', 'evil.example')->get('http://evil.example/events/quiz-night')`.
- **Why deferred:** 01-11 must not edit those files (01-14 owns their fixture updates). The production code is now Host-proof (01-11 forceRootUrl plus root-relative Vite paths), and the new regression test covers `/`, the event page and `/admin` with a real spoof. The old tests are weak, but they don't hide a live bug.
- **Suggested owner:** 01-14 or a later test-hardening pass: switch the idiom to the absolute evil.example URI (the `.ics` case included).
