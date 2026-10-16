#!/usr/bin/env bash
#
# Dev-only brand asset generator (D-07). Run by hand on a laptop with Google
# Chrome; the outputs are committed, so CI and servers never need Chrome.
#
# Writes:
#   public/favicon.svg            32x32 viewBox, white calendar-pin on Norfolk green
#   public/favicon.ico            32x32 PNG wrapped in an ICO container
#   public/apple-touch-icon.png   180x180, opaque
#   public/images/og-default.png  1200x630, from ops/brand/og-default.html
#
# Usage: ops/render-brand-assets.sh   (CHROME=/path/to/chromium to override)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
ACCENT="#2E6B3F"

[ -x "$CHROME" ] || { echo "Chrome not found at $CHROME (set CHROME)" >&2; exit 1; }
command -v php >/dev/null || { echo "php not found" >&2; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# The glyph's <path> elements come from the master icon, so every asset matches it.
PATHS="$(grep -oE '<path [^>]*/>' "$ROOT/resources/svg/calendar-pin.svg" | tr '\n' ' ')"
[ -n "$PATHS" ] || { echo "no <path> elements in resources/svg/calendar-pin.svg" >&2; exit 1; }

# shoot WIDTH HEIGHT URL OUT [extra chrome flags...]
shoot() {
    local width="$1" height="$2" url="$3" out="$4"
    shift 4
    "$CHROME" --headless=new --disable-gpu --hide-scrollbars --no-first-run --no-default-browser-check \
        --user-data-dir="$TMP/chrome-profile" \
        --force-device-scale-factor=1 --window-size="$width,$height" \
        --allow-file-access-from-files --virtual-time-budget=3000 \
        "$@" --screenshot="$out" "$url" >/dev/null 2>&1
    [ -s "$out" ] || { echo "Chrome did not write $out" >&2; exit 1; }
}

# (a) SVG favicon: rounded accent square, white glyph at stroke-width 2.
cat > "$ROOT/public/favicon.svg" <<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">
  <rect width="32" height="32" rx="7" fill="$ACCENT"/>
  <g transform="translate(16 16) scale(1.15) translate(-12 -12)" fill="none" stroke="#FFFFFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    $PATHS
  </g>
</svg>
SVG

# (b) Raster renders.
cat > "$TMP/apple-touch.html" <<HTML
<!DOCTYPE html>
<html><head><style>
html, body { margin: 0; width: 180px; height: 180px; background: $ACCENT; overflow: hidden; }
body { display: flex; align-items: center; justify-content: center; }
svg { width: 108px; height: 108px; }
</style></head><body>
<svg viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">$PATHS</svg>
</body></html>
HTML

cat > "$TMP/favicon-32.html" <<HTML
<!DOCTYPE html>
<html><head><style>
html, body { margin: 0; width: 32px; height: 32px; background: transparent; overflow: hidden; }
img { display: block; width: 32px; height: 32px; }
</style></head><body><img src="favicon.svg" alt=""></body></html>
HTML
cp "$ROOT/public/favicon.svg" "$TMP/favicon.svg"

mkdir -p "$ROOT/public/images"
shoot 180 180 "file://$TMP/apple-touch.html" "$ROOT/public/apple-touch-icon.png"
shoot 32 32 "file://$TMP/favicon-32.html" "$TMP/favicon-32.png" --default-background-color=00000000
shoot 1200 630 "file://$ROOT/ops/brand/og-default.html" "$ROOT/public/images/og-default.png"

# (c) favicon.ico: ICONDIR (reserved 0, type 1, count 1) + one ICONDIRENTRY
# (32, 32, 0 colours, 0 reserved, 1 plane, 32 bpp, PNG length, offset 22) + PNG.
# shellcheck disable=SC2016 # PHP source, $vars are PHP variables
php -r '
$png = file_get_contents($argv[1]);
$ico = pack("vvv", 0, 1, 1)
    . pack("CCCCvvVV", 32, 32, 0, 0, 1, 32, strlen($png), 22)
    . $png;
file_put_contents($argv[2], $ico);
' "$TMP/favicon-32.png" "$ROOT/public/favicon.ico"

# (d) Size checks.
# shellcheck disable=SC2016 # PHP source, $vars are PHP variables
php -r '
[$touch, $og, $icoPath, $svgPath] = array_slice($argv, 1);
$fail = function (string $why): never { fwrite(STDERR, "FAIL: $why\n"); exit(1); };
$t = getimagesize($touch);
$o = getimagesize($og);
$ico = file_get_contents($icoPath);
if ($t === false || $t[0] !== 180 || $t[1] !== 180 || $t["mime"] !== "image/png") { $fail("apple-touch-icon.png is not a 180x180 PNG"); }
if ($o === false || $o[0] !== 1200 || $o[1] !== 630 || $o["mime"] !== "image/png") { $fail("og-default.png is not a 1200x630 PNG"); }
if (substr($ico, 0, 4) !== "\x00\x00\x01\x00" || strlen($ico) <= 22) { $fail("favicon.ico has no ICO header or no image"); }
$embedded = getimagesizefromstring(substr($ico, 22));
if ($embedded === false || $embedded[0] !== 32 || $embedded[1] !== 32) { $fail("favicon.ico does not hold a 32x32 PNG"); }
if (! preg_match("/<svg\\b[^>]*\\bviewBox=\"0 0 32 32\"/", (string) file_get_contents($svgPath))) { $fail("favicon.svg has no 32x32 viewBox"); }
' "$ROOT/public/apple-touch-icon.png" "$ROOT/public/images/og-default.png" "$ROOT/public/favicon.ico" "$ROOT/public/favicon.svg"

echo "BRAND ASSETS OK"
