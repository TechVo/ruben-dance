#!/usr/bin/env bash
#
# Publish build/static-preview to the `gh-pages` branch (GitHub Pages).
# Run scripts/export-static-preview.sh first.
#
# The branch is orphan and rewritten on every publish: it holds generated
# output only, so its history carries no information worth keeping.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="${1:-$REPO_ROOT/build/static-preview}"
BRANCH="gh-pages"

[ -d "$SRC" ] || { echo "$SRC does not exist — run scripts/export-static-preview.sh first" >&2; exit 1; }

WORKTREE="$(mktemp -d)"
trap 'git -C "$REPO_ROOT" worktree remove --force "$WORKTREE" 2>/dev/null || true; rm -rf "$WORKTREE"' EXIT

cd "$REPO_ROOT"
git worktree add --force --detach "$WORKTREE" >/dev/null
cd "$WORKTREE"
git checkout --orphan "$BRANCH" >/dev/null 2>&1
git rm -rq --cached . 2>/dev/null || true
find . -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +

cp -a "$SRC/." .
git add -A
git commit -qm "Static preview built from $(git -C "$REPO_ROOT" rev-parse --short HEAD)"
git push -q --force origin "$BRANCH"

echo "Pushed $BRANCH ($(find . -name '*.html' | wc -l) pages)."
