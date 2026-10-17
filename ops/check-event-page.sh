#!/usr/bin/env bash
#
# Event page check (EVT-02, EVT-03, EVT-06, EVT-07): the first event page
# linked from / has a self canonical and og:url on BASE_URL, exactly one
# og:image, 1-10 Event JSON-LD blocks, only same-origin first-party scripts
# under /build/assets/, a downloadable .ics whose UID is stable across two
# fetches, a Google Calendar link, the no-JS share fallbacks, and no
# deny-listed third-party host.
#
# Usage: ops/check-event-page.sh [BASE_URL]
#   BASE_URL defaults to http://localhost:8000 (local APP_URL, served by
#   `php artisan serve`). Plan 05-02 runs it against the production URL.
#
# Exit codes: 0 EVENT PAGE OK, 1 a check failed, 3 no published event on /.
set -euo pipefail

BASE_URL="${1:-http://localhost:8000}"
BASE_URL="${BASE_URL%/}"
DENY='fonts\.bunny\.net|fonts\.googleapis\.com|fonts\.gstatic\.com|use\.typekit\.net|cdn\.jsdelivr\.net|unpkg\.com|cdnjs\.cloudflare\.com|cdn\.tailwindcss\.com|connect\.facebook\.net|platform\.twitter\.com|addthis|sharethis'
UID_DOMAIN='@whatsonattleborough.co.uk'

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

# Lower-cased host of an absolute or protocol-relative URL ("" for relative).
host_of() {
    case "$1" in
        //* | [a-zA-Z]*://*) ;;
        *) echo ""; return ;;
    esac
    printf '%s\n' "$1" \
        | sed -E 's#^[a-zA-Z][a-zA-Z0-9+.-]*:##; s#^//##; s#[/?\#].*$##; s#^.*@##; s#:[0-9]+$##' \
        | tr '[:upper:]' '[:lower:]'
}

# Path part of a same-origin URL (absolute URLs lose scheme and authority).
path_of() {
    case "$1" in
        //* | [a-zA-Z]*://*) printf '%s\n' "$1" | sed -E 's#^([a-zA-Z][a-zA-Z0-9+.-]*:)?//[^/]*##' ;;
        *) printf '%s\n' "$1" ;;
    esac
}

absolute() {
    case "$1" in
        //*) echo "${BASE_URL%%//*}$1" ;;
        [a-zA-Z]*://*) echo "$1" ;;
        /*) echo "$BASE_URL$1" ;;
        *) echo "$BASE_URL/$1" ;;
    esac
}

# fetch URL NAME: writes $TMP/NAME.body and $TMP/NAME.headers, prints the status code (no redirects followed).
fetch() {
    curl -sS --max-time 20 -D "$TMP/$2.headers" -o "$TMP/$2.body" -w '%{http_code}' "$1" 2>/dev/null || echo "000"
}

# header_of NAME HEADER: last value of HEADER in $TMP/NAME.headers, lower-cased.
header_of() {
    { grep -i "^$2:" "$TMP/$1.headers" || true; } | tail -n 1 | sed -E 's/^[^:]*:[[:space:]]*//' | tr -d '\r' | tr '[:upper:]' '[:lower:]'
}

# Strip a leading attr= and the surrounding quotes from src="..." / href='...'.
attr_value() {
    sed -E "s/^[[:space:]]*[A-Za-z]+=//; s/^[\"']//; s/[\"']\$//; s/&amp;/\\&/g"
}

# meta_content FILE PROPERTY: content values of <meta property="PROPERTY">, one per line.
meta_content() {
    { grep -oiE "<meta[[:space:]][^>]*property=(\"$2\"|'$2')[^>]*>" "$1" || true; } \
        | { grep -oiE "[[:space:]]content=(\"[^\"]*\"|'[^']*')" || true; } \
        | attr_value
}

BASE_HOST="$(host_of "$BASE_URL")"
[ -n "$BASE_HOST" ] || fail "BASE_URL must be absolute, got $BASE_URL"

# --- First event linked from / ----------------------------------------------
status="$(fetch "$BASE_URL/" index)"
[ "$status" = "200" ] || fail "GET / is not 200 (got $status)"

path="$({ grep -oE "href=(\"/events/[a-z0-9-]+\"|'/events/[a-z0-9-]+')" "$TMP/index.body" || true; } | head -n 1 | attr_value)"

if [ -z "$path" ]; then
    echo "NO PUBLISHED EVENT: publish one upcoming event in /admin (locally any fixture is fine; in production a genuine one, never a test entry), then re-run" >&2
    exit 3
fi

page_url="$BASE_URL$path"

status="$(fetch "$page_url" page)"
[ "$status" = "200" ] || fail "GET $path is not 200 (got $status)"

tr '\n\r' '  ' < "$TMP/page.body" > "$TMP/page.flat"

# --- Canonical, og:url and og:image ------------------------------------------
canonical="$({ grep -oiE "<link[[:space:]][^>]*rel=(\"canonical\"|'canonical')[^>]*>" "$TMP/page.flat" || true; } \
    | { grep -oiE "[[:space:]]href=(\"[^\"]*\"|'[^']*')" || true; } | head -n 1 | attr_value)"
[ "$canonical" = "$page_url" ] || fail "canonical is '$canonical', expected '$page_url'"

og_url="$(meta_content "$TMP/page.flat" 'og:url' | head -n 1)"
[ "$og_url" = "$page_url" ] || fail "og:url is '$og_url', expected '$page_url'"

og_images="$(meta_content "$TMP/page.flat" 'og:image' | grep -c . || true)"
[ "$og_images" = "1" ] || fail "og:image count is $og_images, expected 1"

# --- JSON-LD -------------------------------------------------------------------
jsonld="$({ grep -oiE "<script[[:space:]][^>]*type=(\"application/ld\\+json\"|'application/ld\\+json')" "$TMP/page.flat" || true; } | grep -c . || true)"
{ [ "$jsonld" -ge 1 ] && [ "$jsonld" -le 10 ]; } || fail "JSON-LD block count is $jsonld, expected 1-10"

# JSON_HEX_TAG output never contains "<", so the block body runs to the next tag.
first_ld="$({ grep -oiE "<script[[:space:]][^>]*type=(\"application/ld\\+json\"|'application/ld\\+json')[^>]*>[^<]*" "$TMP/page.flat" || true; } | head -n 1)"
printf '%s' "$first_ld" | grep -q '"@type":"Event"' || fail "the first JSON-LD block has no \"@type\":\"Event\""
printf '%s' "$first_ld" | grep -q '"eventStatus"' || fail "the first JSON-LD block has no eventStatus"

# --- Scripts: only the same-origin share module ---------------------------------
{ grep -oiE '<script[[:space:]][^>]*src=[^>]*>' "$TMP/page.flat" || true; } \
    | { grep -oiE "[[:space:]]src=(\"[^\"]*\"|'[^']*')" || true; } | attr_value > "$TMP/scripts"
script_count="$(grep -c . "$TMP/scripts" || true)"
[ "$script_count" = "1" ] || fail "script count with src is $script_count, expected 1 (the share module)"

n=0
while IFS= read -r src; do
    [ -n "$src" ] || continue
    host="$(host_of "$src")"
    { [ -z "$host" ] || [ "$host" = "$BASE_HOST" ]; } || fail "THIRD-PARTY script: $src"
    case "$(path_of "$src")" in
        /build/assets/*) ;;
        *) fail "script is not under /build/assets/: $src" ;;
    esac
    status="$(fetch "$(absolute "$src")" "script$n")"
    [ "$status" = "200" ] || fail "script $src is not 200 (got $status)"
    case "$(header_of "script$n" content-type)" in
        *javascript*) ;;
        *) fail "script $src content-type is '$(header_of "script$n" content-type)', expected javascript" ;;
    esac
    n=$((n + 1))
done < "$TMP/scripts"

# --- .ics with a stable UID -------------------------------------------------------
ics="$({ grep -oE "href=(\"/events/[a-z0-9-]+/[0-9]{4}-[0-9]{2}-[0-9]{2}\\.ics\"|'/events/[a-z0-9-]+/[0-9]{4}-[0-9]{2}-[0-9]{2}\\.ics')" "$TMP/page.body" || true; } | head -n 1 | attr_value)"
[ -n "$ics" ] || fail "no .ics link on $path"

uid_of() {
    status="$(fetch "$BASE_URL$ics" "$1")"
    [ "$status" = "200" ] || fail "GET $ics is not 200 (got $status)"
    case "$(header_of "$1" content-type)" in
        text/calendar*) ;;
        *) fail "$ics content-type is '$(header_of "$1" content-type)', expected text/calendar" ;;
    esac
    grep -q '^BEGIN:VCALENDAR' "$TMP/$1.body" || fail "$ics has no BEGIN:VCALENDAR"
    { grep -m 1 '^UID:' "$TMP/$1.body" || true; } | tr -d '\r'
}

uid_first="$(uid_of ics1)"
case "$uid_first" in
    UID:*"$UID_DOMAIN") ;;
    *) fail "$ics UID line '$uid_first' does not end in $UID_DOMAIN" ;;
esac
uid_second="$(uid_of ics2)"
[ "$uid_first" = "$uid_second" ] || fail "UID changed between fetches: '$uid_first' then '$uid_second'"

# --- Google Calendar link and share fallbacks ---------------------------------------
grep -qF 'href="https://calendar.google.com/calendar/render?action=TEMPLATE' "$TMP/page.flat" \
    || fail "no Google Calendar link on $path"
grep -qF 'href="https://wa.me/?text=' "$TMP/page.flat" || fail "no WhatsApp share link on $path"
grep -qF 'href="https://www.facebook.com/sharer/sharer.php?u=' "$TMP/page.flat" || fail "no Facebook share link on $path"
grep -qF 'href="mailto:?subject=' "$TMP/page.flat" || fail "no email share link on $path"

# --- Deny-list ---------------------------------------------------------------------
if grep -qiE "$DENY" "$TMP/page.body"; then
    fail "deny-list host on $path: $(grep -oiE "$DENY" "$TMP/page.body" | head -n 1)"
fi

echo "EVENT PAGE OK: path=$path jsonld=$jsonld uid=${uid_first#UID:}"
