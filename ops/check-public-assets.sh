#!/usr/bin/env bash
#
# Public asset check (CAL-01, T-01-37): every resource / references is
# same-origin, the Vite stylesheet and its self-hosted fonts load, the brand
# files (favicon.svg/.ico, apple-touch-icon, og:image) load, and / has no
# script element.
#
# Usage: ops/check-public-assets.sh [BASE_URL]
#   BASE_URL defaults to http://localhost:8000 (local APP_URL, served by
#   `php artisan serve`). Plan 05-02 runs it against the production URL.
#
# The immutable Cache-Control check on /build/* applies only to https bases
# (Caddy sets it in production; PHP's built-in server does not).
set -euo pipefail

BASE_URL="${1:-http://localhost:8000}"
BASE_URL="${BASE_URL%/}"
DENY='fonts\.bunny\.net|fonts\.googleapis\.com|fonts\.gstatic\.com|use\.typekit\.net|cdn\.jsdelivr\.net|unpkg\.com|cdnjs\.cloudflare\.com|cdn\.tailwindcss\.com'

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

# A URL is first-party when it is relative (no scheme, not //) or on BASE_URL's host.
is_first_party() {
    case "$1" in
        //* | [a-zA-Z]*:*) [ "$(host_of "$1")" = "$BASE_HOST" ] ;;
        *) return 0 ;;
    esac
}

# fetch URL NAME: writes $TMP/NAME.body and $TMP/NAME.headers, prints the status code.
fetch() {
    curl -sS -L --max-time 20 -D "$TMP/$2.headers" -o "$TMP/$2.body" -w '%{http_code}' "$1" 2>/dev/null || echo "000"
}

# header_of NAME HEADER: last value of HEADER in $TMP/NAME.headers, lower-cased.
header_of() {
    { grep -i "^$2:" "$TMP/$1.headers" || true; } | tail -n 1 | sed -E 's/^[^:]*:[[:space:]]*//' | tr -d '\r' | tr '[:upper:]' '[:lower:]'
}

absolute() {
    case "$1" in
        //*) echo "${BASE_URL%%//*}$1" ;;
        [a-zA-Z]*://*) echo "$1" ;;
        /*) echo "$BASE_URL$1" ;;
        *) echo "$BASE_URL/$1" ;;
    esac
}

# Strip a leading attr= and the surrounding quotes from src="..." / href='...'.
attr_value() {
    sed -E "s/^[[:space:]]*[A-Za-z]+=//; s/^[\"']//; s/[\"']\$//; s/&amp;/\\&/g"
}

BASE_HOST="$(host_of "$BASE_URL")"
[ -n "$BASE_HOST" ] || fail "BASE_URL must be absolute, got $BASE_URL"

# --- GET / -----------------------------------------------------------------
status="$(fetch "$BASE_URL/" index)"
[ "$status" = "200" ] || fail "GET / is not 200 (got $status)"

tr '\n\r' '  ' < "$TMP/index.body" > "$TMP/index.flat"

if grep -qiE '<script([[:space:]>]|$)' "$TMP/index.flat"; then
    fail "/ contains a script element"
fi

if grep -qiE "$DENY" "$TMP/index.body"; then
    fail "deny-list host in the HTML of /: $(grep -oiE "$DENY" "$TMP/index.body" | head -n 1)"
fi

# Resource-bearing tags and their src/href values.
{ grep -oiE '<(link|script|img|source|iframe)[[:space:]][^>]*>' "$TMP/index.flat" || true; } > "$TMP/tags"
{ grep -oiE "[[:space:]](src|href)=(\"[^\"]*\"|'[^']*')" "$TMP/tags" || true; } | attr_value > "$TMP/urls"

while IFS= read -r url; do
    [ -n "$url" ] || continue
    is_first_party "$url" || fail "THIRD-PARTY: $url"
done < "$TMP/urls"

# --- Stylesheet ------------------------------------------------------------
{ grep -iE "rel=(\"stylesheet\"|'stylesheet'|stylesheet)" "$TMP/tags" || true; } > "$TMP/stylesheets"
sheet_count="$(grep -c . "$TMP/stylesheets" || true)"
[ "$sheet_count" = "1" ] || fail "stylesheet count on / is $sheet_count, expected 1"

css_url="$({ grep -oiE "[[:space:]]href=(\"[^\"]*\"|'[^']*')" "$TMP/stylesheets" || true; } | head -n 1 | attr_value)"
css_path="$(path_of "$css_url")"
case "$css_path" in
    /build/assets/*.css) ;;
    *) fail "stylesheet is not under /build/assets/: $css_url" ;;
esac

status="$(fetch "$(absolute "$css_url")" css)"
[ "$status" = "200" ] || fail "stylesheet $css_path is not 200 (got $status)"
css_type="$(header_of css content-type)"
case "$css_type" in
    text/css*) ;;
    *) fail "stylesheet content-type is '$css_type', expected text/css" ;;
esac

case "$BASE_URL" in
    https://*)
        header_of css cache-control | grep -q immutable \
            || fail "stylesheet cache-control lacks immutable: '$(header_of css cache-control)'"
        ;;
esac

if grep -qiE "$DENY" "$TMP/css.body"; then
    fail "deny-list host in the CSS: $(grep -oiE "$DENY" "$TMP/css.body" | head -n 1)"
fi

# --- url() references in the CSS and the fonts they load ---------------------
{ grep -oE 'url\([^)]*\)' "$TMP/css.body" || true; } \
    | sed -E "s/^url\(//; s/\)\$//; s/^[\"']//; s/[\"']\$//" \
    | sort -u > "$TMP/css-urls"

fonts=0
while IFS= read -r ref; do
    [ -n "$ref" ] || continue
    case "$ref" in
        data:*) continue ;;
        /build/*) ;;
        *) fail "CSS url() outside /build/ or data:: $ref" ;;
    esac
    case "$ref" in
        *.woff2 | *.woff2\?*) ;;
        *) continue ;;
    esac
    status="$(fetch "$BASE_URL$ref" "font$fonts")"
    [ "$status" = "200" ] || fail "font $ref is not 200 (got $status)"
    font_type="$(header_of "font$fonts" content-type)"
    case "$BASE_URL" in
        https://*)
            case "$font_type" in
                font/woff2*) ;;
                *) fail "font $ref content-type is '$font_type', expected font/woff2" ;;
            esac
            ;;
        *)
            # PHP's built-in server mime table is not Caddy's.
            case "$font_type" in
                font/* | application/octet-stream*) ;;
                *) fail "font $ref content-type is '$font_type', expected font/*" ;;
            esac
            ;;
    esac
    fonts=$((fonts + 1))
done < "$TMP/css-urls"

[ "$fonts" -ge 1 ] || fail "the stylesheet references no woff2 font"

# --- Brand files (D-07) ------------------------------------------------------
# check_file PATH_OR_URL NAME EXPECTED_TYPE_PREFIX ("" = any type, non-empty body)
check_file() {
    local status type
    status="$(fetch "$(absolute "$1")" "$2")"
    [ "$status" = "200" ] || fail "$1 is not 200 (got $status)"
    [ -s "$TMP/$2.body" ] || fail "$1 is empty"
    if [ -n "$3" ]; then
        type="$(header_of "$2" content-type)"
        case "$type" in
            "$3"*) ;;
            *) fail "$1 content-type is '$type', expected $3" ;;
        esac
    fi
}

check_file /favicon.svg favicon-svg image/svg+xml
check_file /favicon.ico favicon-ico ""
check_file /apple-touch-icon.png apple-touch image/png

{ grep -oiE "<meta[[:space:]][^>]*property=(\"og:image\"|'og:image')[^>]*>" "$TMP/index.flat" || true; } > "$TMP/og-image"
og_count="$(grep -c . "$TMP/og-image" || true)"
[ "$og_count" = "1" ] || fail "og:image count on / is $og_count, expected 1"
og_url="$({ grep -oiE "[[:space:]]content=(\"[^\"]*\"|'[^']*')" "$TMP/og-image" || true; } | head -n 1 | attr_value)"
case "$og_url" in
    http://* | https://*) ;;
    *) fail "og:image is not an absolute URL: $og_url" ;;
esac
[ "$(host_of "$og_url")" = "$BASE_HOST" ] || fail "THIRD-PARTY: $og_url (og:image)"
check_file "$og_url" og-image image/png

echo "PUBLIC ASSETS OK: css=$css_path fonts=$fonts third-party=0"
