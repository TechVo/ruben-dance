#!/usr/bin/env bash
#
# Mirror the running wp-env site into a static HTML preview suitable for
# GitHub Pages (see docs/static-preview.md for what does and does not survive
# the export).
#
# Usage: scripts/export-static-preview.sh [output-dir] [base-path]
#   output-dir  where the static site is written  (default: build/static-preview)
#   base-path   path the site is served under     (default: /ruben-dance)
#
# Requires: a running `npx wp-env start`, seeded via `npx wp-env run cli wp rd seed`,
# and wget + python3 on the host.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-$REPO_ROOT/build/static-preview}"
BASE_PATH="${2:-/ruben-dance}"
SITE="http://localhost:8888"

# Date window the seeded lessons fall in; the calendar's REST feed is snapshotted
# for exactly this range (the endpoint caps a single request at 366 days).
LESSONS_FROM="2025-06-01"
LESSONS_TO="2026-05-31"
# Month the calendar opens on. The fixture terms are 2025/26, so opening on
# "today" would show an empty grid.
CALENDAR_INITIAL_DATE="2025-09-01"

command -v wget >/dev/null || { echo "wget is required" >&2; exit 1; }
curl -sf -o /dev/null "$SITE/" || { echo "$SITE is not responding — run 'npx wp-env start' first" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "==> Crawling $SITE"

# Pages nothing links to (enrollment, account, vouchers) need to be seeded by hand.
cat > "$WORK/seeds.txt" <<EOF
$SITE/
$SITE/darkove-poukazy/
$SITE/prihlaska/
$SITE/muj-ucet/
$SITE/en/gift-vouchers/
$SITE/en/enroll/
$SITE/en/my-account/
EOF

mkdir -p "$WORK/crawl"
cd "$WORK/crawl"
wget --quiet --recursive --level=8 --page-requisites --adjust-extension --convert-links \
     --no-parent --restrict-file-names=windows \
     --span-hosts --domains=localhost,fonts.googleapis.com,fonts.gstatic.com \
     --exclude-directories=/wp-admin,/wp-json,/feed \
     --reject-regex '(wp-login|wp-admin|wp-json|/feed|action=|logout|\?s=|\?p=|\?page_id=|xmlrpc|/comments/)' \
     -e robots=off -i "$WORK/seeds.txt"

ROOT="$WORK/crawl/localhost+8888"
[ -d "$ROOT" ] || { echo "crawl produced no output" >&2; exit 1; }

# Referenced from JS rather than markup, so the crawler never sees them.
for u in wp-includes/js/zxcvbn.min.js wp-admin/js/password-strength-meter.js; do
    mkdir -p "$ROOT/$(dirname "$u")"
    curl -sf "$SITE/$u" -o "$ROOT/$u"
done

echo "==> Snapshotting the calendar REST feed"
mkdir -p "$ROOT/rd-data"
for lang in cs en; do
    curl -sf "$SITE/wp-json/rd/v1/lessons?from=$LESSONS_FROM&to=$LESSONS_TO&lang=$lang" \
         -o "$ROOT/rd-data/lessons-$lang.json"
done

echo "==> Rewriting for static hosting"
BASE_PATH="$BASE_PATH" CALENDAR_INITIAL_DATE="$CALENDAR_INITIAL_DATE" \
    python3 "$REPO_ROOT/scripts/static_preview_rewrite.py" "$ROOT"

echo "==> Writing to $OUT_DIR"
rm -rf "$OUT_DIR"
mkdir -p "$(dirname "$OUT_DIR")"
cp -a "$ROOT" "$OUT_DIR"

echo "Done: $(find "$OUT_DIR" -name '*.html' | wc -l) pages, $(du -sh "$OUT_DIR" | cut -f1)"
