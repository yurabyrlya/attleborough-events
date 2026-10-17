#!/usr/bin/env bash
#
# Polite source probe for re-checks and new sources (D-14, D-25, D-26; RES-03).
#
# Use it to re-check a source (did robots.txt change? is it still blocked?),
# to capture a fresh fixture, or to try a new source URL. It never tries to
# get past a challenge, a CAPTCHA, a login or a robots.txt disallow: it
# records "blocked" (or "skipped") and moves on. Some sites (Breckland
# Council, Norfolk County Council, Norfolk Museums, TicketSource) challenge
# our honest User-Agent on every network (D-25); for those the script's job is
# only to notice if that ever changes. Never edit the User-Agent to get in.
# Some hosts block datacenter or corporate IPs but serve residential ones
# (the Town Council, D-26), so note which network you ran it from.
#
# What it does, per target:
#   1. Fetches robots.txt with our identifying User-Agent and skips any path
#      the `User-agent: *` group (or our own group) disallows.
#   2. Waits at least 5 s between requests to one host, longer if robots.txt
#      sets Crawl-delay. A delay over 10 minutes (Attleborough Primary sets
#      12000 s) is honoured across runs: the script remembers the last request
#      per host in ~/.cache/whatson-probe and tells you when to run it again.
#   3. Saves each response's raw bytes to
#      tests/Fixtures/sources/{slug}/{YYYY-MM-DD}-{type}.{ext} and appends an
#      entry (URL, time, status, headers, robots status, sha256) to that
#      folder's manifest.json.
#   4. Never saves a challenge page: HTTP 403/429/503, a `cf-mitigated`
#      header, or a challenge/CAPTCHA marker in the body counts as "blocked".
#   5. Prints a summary of what it saved and what to do before committing.
#
# REDACT BEFORE COMMIT. The repo is public. Read every saved file, replace
# personal emails with redacted@example.invalid, UK phone numbers with
# 01632 960000 and private names with [name removed], then set "modified"
# and "redactions" in manifest.json (tests/Fixtures/sources/README.md has the
# full procedure). tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php
# fails on any email or phone number left behind.
#
# Usage:   ops/probe-sources.sh [slug ...]
#          (no arguments = every target below)
# Needs:   bash, curl, and shasum or sha256sum (gzip only for .xml.gz sitemaps)
# Exit:    0 when the run finished (some targets may be "blocked"; see summary)

set -uo pipefail

UA='WhatsOnAttleboroughResearch/0.1 (+mailto:hello@whatsonattleborough.co.uk)'
UA_TOKEN='whatsonattleboroughresearch'
MIN_DELAY=5
LONG_DELAY=600          # Crawl-delay above this is honoured across runs
MAX_DETAIL_PAGES=3      # event pages per target found through sitemaps
MAX_CHILD_SITEMAPS=4    # child sitemaps followed per target
MAX_BYTES=20000000

ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
OUT="$ROOT/tests/Fixtures/sources"
STATE="${XDG_CACHE_HOME:-$HOME/.cache}/whatson-probe"
TODAY="$(date -u +%Y-%m-%d)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$STATE"

SUMMARY="$WORK/summary.txt"
SAVED="$WORK/saved.txt"
: >"$SUMMARY"
: >"$SAVED"

# --------------------------------------------------------------------------
# Targets: slug | base URL | listing paths (comma-separated, may be empty) |
#          sitemap keyword regex for event pages (empty = no sitemap walk)
# Listing paths for the Cloudflare sites are best guesses (their pages were
# never reachable with our User-Agent); paths that 404 are recorded and
# skipped. Add a line here to try a new source. Nothing bypasses robots.txt.
# --------------------------------------------------------------------------
TARGETS='
attleborough-town-council|https://attleboroughtc.org.uk|/?post_type=tribe_events&ical=1|
attleborough-primary-school|https://www.attleboroughprimary.org|/key-information/school-calendar|
breckland-council|https://www.breckland.gov.uk|/events,/whats-on|attleborough
norfolk-county-council-libraries|https://www.norfolk.gov.uk|/libraries-local-history-and-archives/libraries/your-local-library/attleborough-library|attleborough
norfolk-museums|https://www.museums.norfolk.gov.uk|/gressenhall-farm-and-workhouse/whats-on,/whats-on|gressenhall
ticketsource|https://www.ticketsource.co.uk|/attleborough-players|attleborough
'

say() { printf '%s\n' "$*"; }
note() { printf '%-34s %-9s %s\n' "$1" "$2" "$3" >>"$SUMMARY"; }

sha256() {
    if command -v shasum >/dev/null 2>&1; then shasum -a 256 "$1" | cut -d' ' -f1; else sha256sum "$1" | cut -d' ' -f1; fi
}

json_escape() { printf '%s' "$1" | tr -d '\r' | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }

host_of() { printf '%s' "$1" | sed -E 's#^https?://([^/]+).*#\1#'; }
path_of() { printf '%s' "$1" | sed -E 's#^https?://[^/]+##; s#^$#/#'; }

header() { # header NAME FILE -> value of the last occurrence (after redirects)
    grep -i "^$1:" "$2" 2>/dev/null | tail -1 | cut -d: -f2- | sed 's/^ *//' | tr -d '\r'
}

# Wait until the host's delay has passed since our last request to it.
wait_for_host() {
    local host="$1" delay="$2" last now left
    last="$(cat "$STATE/last-$host" 2>/dev/null || echo 0)"
    now="$(date +%s)"
    left=$((last + delay - now))
    if [ "$left" -gt "$LONG_DELAY" ]; then
        return 1
    fi
    [ "$left" -gt 0 ] && sleep "$left"
    return 0
}

stamp_host() { date +%s >"$STATE/last-$1"; }

# Raw GET. Writes $WORK/hdr and $WORK/body, prints the status code.
get() {
    rm -f "$WORK/hdr" "$WORK/body"
    curl -sS -m 30 --max-filesize "$MAX_BYTES" -A "$UA" -D "$WORK/hdr" -o "$WORK/body" \
        -w '%{http_code}' "$1" 2>"$WORK/curl.err" || true
}

is_challenge() { # is_challenge STATUS -> 0 when the response is a bot wall
    case "$1" in 403 | 429 | 503) return 0 ;; esac
    grep -qi '^cf-mitigated:' "$WORK/hdr" 2>/dev/null && return 0
    grep -qiE '<title>Just a moment|/cdn-cgi/challenge-platform|lsrecaptcha|<title>Bot Verification|captcha-delivery|hcaptcha\.com/1/api' "$WORK/body" 2>/dev/null && return 0
    return 1
}

# Parse robots.txt into "allow PATTERN", "disallow PATTERN", "delay N" lines
# for our own group if present, else the `User-agent: *` group.
parse_robots() {
    tr -d '\r' <"$1" | awk -v token="$UA_TOKEN" '
        function flush() { if (ours) { for (i = 1; i <= n; i++) own[++on] = rules[i] } else if (star) { for (i = 1; i <= n; i++) any[++an] = rules[i] } n = 0; ours = 0; star = 0 }
        { sub(/#.*/, ""); gsub(/^[ \t]+|[ \t]+$/, "") }
        $0 == "" { next }
        {
            split($0, kv, ":"); key = tolower(kv[1]); val = substr($0, index($0, ":") + 1); gsub(/^[ \t]+|[ \t]+$/, "", val)
            if (key == "user-agent") {
                if (inrules) { flush(); inrules = 0 }
                ua = tolower(val); if (ua == "*") star = 1; else if (index(token, ua) == 1 || index(ua, token) == 1) ours = 1
                next
            }
            if (key == "allow" || key == "disallow") { inrules = 1; if (val != "") rules[++n] = key " " val; next }
            if (key == "crawl-delay") { inrules = 1; rules[++n] = "delay " val; next }
        }
        END { flush(); if (on) for (i = 1; i <= on; i++) print own[i]; else for (i = 1; i <= an; i++) print any[i] }'
}

# robots_allows RULES_FILE PATH -> 0 when allowed (longest match wins, Allow wins ties)
robots_allows() {
    awk -v path="$2" '
        function rx(p,   out, c, i) {
            out = ""
            for (i = 1; i <= length(p); i++) {
                c = substr(p, i, 1)
                if (c == "*") out = out ".*"
                else if (c == "$" && i == length(p)) out = out "$"
                else if (index(".+?()[]{}|^\\", c)) out = out "\\" c
                else out = out c
            }
            return "^" out
        }
        $1 == "allow" || $1 == "disallow" {
            p = $2; if (match(path, rx(p))) { l = length(p); if (l > best || (l == best && $1 == "allow")) { best = l; verdict = $1 } }
        }
        END { exit (verdict == "disallow") ? 1 : 0 }' "$1"
}

robots_delay() { awk '$1 == "delay" { d = int($2) } END { print d + 0 }' "$1"; }

# Fetch robots.txt for a base URL (cached for 24 h in ~/.cache/whatson-probe,
# as RFC 9309 allows, so a long Crawl-delay is never broken by a re-fetch).
# Sets ROBOTS_STATUS, ROBOTS_RULES (file), DELAY.
load_robots() {
    local base="$1" host status cache
    host="$(host_of "$base")"
    cache="$STATE/robots-$host"
    ROBOTS_RULES="$WORK/robots-$host.rules"
    if [ -f "$cache" ] && [ -n "$(find "$cache" -mmin -1440 2>/dev/null)" ] && [ -f "$cache.status" ]; then
        ROBOTS_STATUS="$(cat "$cache.status")"
        parse_robots "$cache" >"$ROBOTS_RULES"
    else
        wait_for_host "$host" "$MIN_DELAY" || true
        status="$(get "$base/robots.txt")"
        stamp_host "$host"
        if is_challenge "$status"; then
            ROBOTS_STATUS="blocked (robots.txt HTTP $status$(grep -qi '^cf-mitigated:' "$WORK/hdr" && printf ', cf-mitigated: challenge'))"
            : >"$ROBOTS_RULES"
            return 1
        fi
        case "$status" in
            200) cp "$WORK/body" "$cache"; ROBOTS_STATUS="allowed (robots.txt 200, fetched $(date -u +%Y-%m-%dT%H:%M:%SZ))" ;;
            404 | 410) : >"$cache"; ROBOTS_STATUS="no robots.txt ($status): no rules" ;;
            *) ROBOTS_STATUS="unreachable (robots.txt HTTP $status): treated as disallow-all"; : >"$ROBOTS_RULES"; return 1 ;;
        esac
        printf '%s' "$ROBOTS_STATUS" >"$cache.status"
        parse_robots "$cache" >"$ROBOTS_RULES"
    fi
    DELAY="$(robots_delay "$ROBOTS_RULES")"
    [ "$DELAY" -lt "$MIN_DELAY" ] && DELAY="$MIN_DELAY"
    return 0
}

ext_for() {
    case "$(header content-type "$WORK/hdr")" in
        *calendar*) echo ics ;;
        *json*) echo json ;;
        *rss* | *atom* | *xml*) echo xml ;;
        *) echo html ;;
    esac
}

# Append one entry to {slug}/manifest.json (created if missing).
manifest_add() {
    local slug="$1" file="$2" url="$3" status="$4" manifest entry tmp
    manifest="$OUT/$slug/manifest.json"
    entry="$(printf '        {\n            "file": "%s",\n            "type": "%s",\n            "url": "%s",\n            "fetched_at": "%s",\n            "http_status": %s,\n            "headers": {\n                "content-type": "%s",\n                "etag": "%s",\n                "last-modified": "%s",\n                "cache-control": "%s"\n            },\n            "robots_status": "%s",\n            "robots_rules": [%s],\n            "sha256_raw": "%s",\n            "redactions": {"email": 0, "phone": 0, "name": 0},\n            "modified": false,\n            "events": null,\n            "captured_by": "ops/probe-sources.sh",\n            "notes": "UNREVIEWED: read, redact, then fill events/redactions/modified before commit"\n        }' \
        "$file" "$(printf '%s' "$file" | sed -E 's/^[0-9-]{11}//; s/\.[a-z]+$//')" "$(json_escape "$url")" \
        "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$status" \
        "$(json_escape "$(header content-type "$WORK/hdr")")" "$(json_escape "$(header etag "$WORK/hdr")")" \
        "$(json_escape "$(header last-modified "$WORK/hdr")")" "$(json_escape "$(header cache-control "$WORK/hdr")")" \
        "$(json_escape "$ROBOTS_STATUS")" "$(sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/^/"/' -e 's/$/"/' "$ROBOTS_RULES" | paste -sd, -)" \
        "$(sha256 "$OUT/$slug/$file")")"
    if [ ! -f "$manifest" ]; then
        printf '{\n    "source": "%s",\n    "files": [\n%s\n    ]\n}\n' "$slug" "$entry" >"$manifest"
        return
    fi
    # Our manifests end with "    ]" then "}": drop those two lines and append.
    tmp="$WORK/manifest.tmp"
    sed -e '$d' "$manifest" | sed -e '$d' >"$tmp"
    if grep -q '"files": \[$' "$tmp" && ! tail -1 "$tmp" | grep -q '}'; then
        printf '%s\n    ]\n}\n' "$entry" >>"$tmp"
    else
        printf ',\n%s\n    ]\n}\n' "$entry" >>"$tmp"
    fi
    mv "$tmp" "$manifest"
}

# fetch_and_save SLUG TYPE URL -> saves the page unless blocked/disallowed
fetch_and_save() {
    local slug="$1" type="$2" url="$3" host path status ext file hops=0 loc
    host="$(host_of "$url")"
    if ls "$OUT/$slug/$TODAY-$type."* >/dev/null 2>&1; then
        note "$slug" exists "$TODAY-$type.* already captured today, not fetched again ($url)"
        return
    fi
    while :; do
        path="$(path_of "$url")"
        if ! robots_allows "$ROBOTS_RULES" "$path"; then
            note "$slug" skipped "robots disallows $path"
            return
        fi
        if ! wait_for_host "$host" "$DELAY"; then
            note "$slug" deferred "Crawl-delay ${DELAY}s: run again after $(date -r $(($(cat "$STATE/last-$host") + DELAY)) '+%H:%M' 2>/dev/null || echo later) for $url"
            return
        fi
        status="$(get "$url")"
        stamp_host "$host"
        case "$status" in
            301 | 302 | 303 | 307 | 308)
                loc="$(header location "$WORK/hdr")"
                case "$loc" in /*) loc="https://$host$loc" ;; esac
                if [ "$(host_of "$loc")" != "$host" ] || [ "$hops" -ge 3 ]; then
                    note "$slug" skipped "redirect off-host or too long: $url -> $loc"
                    return
                fi
                url="$loc"; hops=$((hops + 1)); continue ;;
        esac
        break
    done
    if is_challenge "$status"; then
        note "$slug" blocked "HTTP $status$(grep -qi '^cf-mitigated:' "$WORK/hdr" && printf ', cf-mitigated: challenge') at $url (not saved)"
        return
    fi
    if [ "$status" != 200 ]; then
        note "$slug" missing "HTTP $status at $url"
        return
    fi
    ext="$(ext_for)"
    mkdir -p "$OUT/$slug"
    file="$TODAY-$type.$ext"
    if [ -e "$OUT/$slug/$file" ]; then
        note "$slug" exists "$file already present, not overwritten ($url)"
        return
    fi
    cp "$WORK/body" "$OUT/$slug/$file"
    manifest_add "$slug" "$file" "$url" "$status"
    note "$slug" saved "$file <- $url"
    printf '%s\n' "tests/Fixtures/sources/$slug/$file" >>"$SAVED"
}

# Collect up to MAX_DETAIL_PAGES sitemap URLs on the host matching KEYWORD.
sitemap_urls() {
    local base="$1" keyword="$2" host sm child n=0
    host="$(host_of "$base")"
    : >"$WORK/locs"
    for sm in $(grep -i '^sitemap:' "$STATE/robots-$host" 2>/dev/null | cut -d: -f2- | tr -d ' \r') "$base/sitemap.xml"; do
        robots_allows "$ROBOTS_RULES" "$(path_of "$sm")" || continue
        wait_for_host "$host" "$DELAY" || return
        [ "$(get "$sm")" = 200 ] || { stamp_host "$host"; continue; }
        stamp_host "$host"
        is_challenge 200 && return
        case "$sm" in *.gz) gzip -dc "$WORK/body" >"$WORK/sm" 2>/dev/null || continue ;; *) cp "$WORK/body" "$WORK/sm" ;; esac
        grep -o '<loc>[^<]*</loc>' "$WORK/sm" | sed -e 's#</*loc>##g' -e 's/&amp;/\&/g' >"$WORK/sm.locs"
        if grep -q '<sitemapindex' "$WORK/sm"; then
            for child in $(grep -iE 'event|whats|venue|library|attleborough' "$WORK/sm.locs" | head -n "$MAX_CHILD_SITEMAPS"); do
                robots_allows "$ROBOTS_RULES" "$(path_of "$child")" || continue
                wait_for_host "$host" "$DELAY" || break
                [ "$(get "$child")" = 200 ] || { stamp_host "$host"; continue; }
                stamp_host "$host"
                case "$child" in *.gz) gzip -dc "$WORK/body" 2>/dev/null ;; *) cat "$WORK/body" ;; esac |
                    grep -o '<loc>[^<]*</loc>' | sed -e 's#</*loc>##g' -e 's/&amp;/\&/g' >>"$WORK/locs"
            done
        else
            cat "$WORK/sm.locs" >>"$WORK/locs"
        fi
        break
    done
    grep -iE "$keyword" "$WORK/locs" | grep -iE 'event|whats-on|/e/|[0-9]{4}' | sort -u | while read -r u; do
        robots_allows "$ROBOTS_RULES" "$(path_of "$u")" || continue
        n=$((n + 1)); [ "$n" -gt "$MAX_DETAIL_PAGES" ] && break
        printf '%s\n' "$u"
    done
}

probe_target() {
    local slug="$1" base="$2" listings="$3" keyword="$4" p i=0 u t
    say "== $slug ($base)"
    if ! load_robots "$base"; then
        note "$slug" blocked "$ROBOTS_STATUS"
        return
    fi
    IFS=',' read -r -a paths <<<"$listings"
    for p in "${paths[@]}"; do
        [ -n "$p" ] || continue
        i=$((i + 1))
        case "$p" in
            *ical=1*) t=ical ;;
            */wp-json/tribe/*) t=tec-p1 ;;
            *) t="events-page$([ "$i" -gt 1 ] && printf -- '-%s' "$i")" ;;
        esac
        fetch_and_save "$slug" "$t" "$base$p"
    done
    [ -n "$keyword" ] || return
    i=0
    for u in $(sitemap_urls "$base" "$keyword"); do
        i=$((i + 1))
        fetch_and_save "$slug" "event-$(printf '%02d' "$i")" "$u"
    done
}

# Sourcing with PROBE_SOURCES_LIB=1 loads the functions only (for testing the
# robots.txt parser offline).
if [ "${PROBE_SOURCES_LIB:-}" = 1 ]; then
    return 0 2>/dev/null || exit 0
fi

# --------------------------------------------------------------------------
say "What's On Attleborough: polite source probe ($TODAY)"
say "User-Agent: $UA"
say "Writing to $OUT"
say ""

printf '%s\n' "$TARGETS" | while IFS='|' read -r slug base listings keyword; do
    [ -n "$slug" ] || continue
    if [ "$#" -gt 0 ]; then
        case " $* " in *" $slug "*) ;; *) continue ;; esac
    fi
    probe_target "$slug" "$base" "$listings" "$keyword"
done

say ""
say "Summary"
say "-------"
cat "$SUMMARY"
say ""
if [ -s "$SAVED" ]; then
    say "Saved files (UNREVIEWED):"
    sed 's/^/  /' "$SAVED"
    say ""
    say "Before committing:"
    say "  1. Read every file above. Redact personal emails (redacted@example.invalid),"
    say "     UK phone numbers (01632 960000) and private names ([name removed])."
    say "     Keep the document valid (tests/Fixtures/sources/README.md, Redaction)."
    say "  2. In each manifest.json set events, redactions and modified, and replace the"
    say "     UNREVIEWED note. Delete any file you don't want (and its manifest entry)."
    say "  3. ./vendor/bin/pest --testsuite=Unit tests/Unit/Fixtures/SourceFixturesHaveNoPersonalDataTest.php"
    say "  4. git add the files and manifests listed above, or hand them to the agent."
else
    say "Nothing saved. Hand the summary above to the agent."
fi
