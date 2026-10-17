# Source fixtures

Raw responses from live event sources, saved for Phase 3's parser tests (D-14, RES-03). Only sources on List 1 (Crawlable) of `docs/research/event-sources.md` get fixtures, and every P0/P1 source there must have at least one. Tests read these files from disk; no test touches the network (`Http::preventStrayRequests()` is global).

This repo is public. Read "Redaction" before committing a capture.

## Layout

```
tests/Fixtures/sources/
  {source-slug}/
    manifest.json
    {YYYY-MM-DD}-{type}.{ics|json|html|xml}
```

- `{source-slug}`: kebab-case source name, the same slug as in the doc's `## Fixture index` table.
- `{YYYY-MM-DD}`: the capture date (UTC).
- `{type}`: what the file is, so two captures of one source on one day never collide: `ical`, `feed`, `tec-p1`, `tec-p2` (one file per page of one TEC date window, all pages captured), `jsonld` (the HTML page holding the JSON-LD), `events-page` (server-rendered HTML for selectors), `wide` suffix for a second, wider window (e.g. `2026-10-17-ical-wide.ics`).
- Prefer a window that spans a DST change (Sun 25 Oct 2026, Sun 28 Mar 2027).
- `tests/Fixtures/sources/.gitattributes` marks `*.ics`, `*.json`, `*.html` and `*.xml` as `-text`, so the repo-wide `* text=auto eol=lf` never rewrites CRLF line endings. An unredacted fixture must hash to its `sha256_raw`.

## manifest.json

```json
{
    "source": "{source-slug}",
    "name": "Human name",
    "files": [
        {
            "file": "2026-10-17-feed.xml",
            "type": "feed",
            "url": "https://example.org/calendar/rss.xml",
            "fetched_at": "2026-10-17T18:05:08Z",
            "http_status": 200,
            "headers": {
                "content-type": "text/xml; charset=utf-8",
                "etag": null,
                "last-modified": null,
                "cache-control": "private"
            },
            "robots_status": "allowed",
            "robots_fetched_at": "2026-10-17T18:04:35Z",
            "robots_rules": ["User-agent: *", "Disallow: /admin/"],
            "sha256_raw": "sha256 of the ORIGINAL bytes, before redaction",
            "redactions": {"email": 0, "phone": 0, "name": 0},
            "modified": false,
            "events": 64,
            "window": "what date range the capture covers",
            "notes": "anything a parser author needs to know"
        }
    ]
}
```

- List files in filename order (TEC pages therefore in page order).
- `headers` keeps `content-type`, `etag`, `last-modified`, `cache-control`, plus `cf-mitigated` when present. Missing headers are `null`.
- `robots_rules` are the `User-agent: *` lines that decide the fetched path, copied as served.
- `modified` is `true` whenever the committed bytes differ from the fetched bytes (any redaction, any re-encoding).
- `events` is the number of events in the file. A capture with `events: 0` is still committed, together with a second, wider window.

## Capturing

- User-Agent `WhatsOnAttleboroughResearch/0.1 (+mailto:hello@whatsonattleborough.co.uk)`. One request at a time, at least 5 s between requests to one host, more if robots.txt sets `Crawl-delay`.
- Check robots.txt first and record the rule lines. Never bypass a bot wall, CAPTCHA, login, robots disallow or ToS prohibition: no challenge solvers, no browser User-Agent, no copied cookies.
- `curl -sS -D headers.txt -o body.raw -A "$UA" "$URL"`. Keep `body.raw` outside the repo; hash it (`shasum -a 256 body.raw`) before any change.
- If a local security gateway answers instead of the site (its own block page or redirect, TLS interception error), record "no observation (local gateway)", not "blocked by site".
- Some hosts block corporate or datacenter IPs but serve residential ones (the Town Council, D-26). Record the network in the manifest (`"network": "residential"`).
- A page the owner saved from a browser (for a site that challenges our crawler, D-25) gets `"capture": "hand"` in its manifest entry, the URL it was saved from, and the save date. It goes through the same redaction.

## How to run at home (`ops/probe-sources.sh`)

The script does everything in "Capturing" for you: robots.txt first, `Crawl-delay` honoured (across runs for long delays, using `~/.cache/whatson-probe`), our identifying User-Agent, a few requests per host, raw bytes saved with D-14 names, and a manifest entry appended for each file. It never saves a challenge page (HTTP 403/429/503, `cf-mitigated`, CAPTCHA markers); it records `blocked` instead.

```bash
ops/probe-sources.sh                      # every target in the script
ops/probe-sources.sh attleborough-primary-school ticketsource   # only these
```

1. Run it from a normal home connection, not a VPN or the office network, and read the summary it prints: `saved`, `exists`, `deferred` (Crawl-delay: run again after the printed time), `skipped` (robots disallows), `missing` (404 and similar), `blocked`.
2. A `blocked` result is final for that run. Don't change the User-Agent, use a browser's cookies or try a proxy. Tell the agent instead (D-25 lists the legitimate routes).
3. **Redact before commit.** Read every saved file, apply the "Redaction" rules below, then in its manifest entry set `events`, `redactions` and `modified`, and replace the `UNREVIEWED` note.
4. Run `./vendor/bin/pest --testsuite=Unit tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php`. It fails on any personal email or phone number left behind, and on a file missing from its manifest.
5. Commit the files and manifests the script listed, or hand them to the agent.

To try a new source, add a line to `TARGETS` in the script: slug, base URL, listing paths, and an optional sitemap keyword.

## Redaction

Read every capture before committing it. Replace:

| What | Replacement |
|---|---|
| Email addresses (except role addresses of organisations only if you are sure; when unsure, redact) | `redacted@example.invalid` |
| UK phone numbers | `01632 960000` (Ofcom drama range) |
| Private individuals' names (organisers, contacts, children, speakers who are not public figures) | `[name removed]` |

Keep the document valid for parsers:

- **ICS:** unfold lines first (RFC 5545 folding can split a value across lines), redact, then keep unfolded or re-fold at 75 octets. Check it still parses: `php -r 'require "vendor/autoload.php"; Sabre\VObject\Reader::read(file_get_contents($argv[1]));' file.ics`.
- **JSON:** decode, redact string values, re-encode with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT`. Check: `php -r 'json_decode(file_get_contents($argv[1]), flags: JSON_THROW_ON_ERROR);' file.json`.
- **HTML / XML:** replace inside text nodes and `mailto:` / `tel:` attributes only, never in markup. Check XML with `xmllint --noout file.xml`.

Set `modified: true` and the `redactions` counts in the manifest. `sha256_raw` stays the hash of the original bytes, so provenance is honest without committing personal data.

`tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php` fails on any email or UK phone number other than the two placeholders, on any fixture file missing from its `manifest.json`, and on any P0/P1 slug in the doc's Fixture index without a manifest. It cannot check names; that part is on the person committing.
