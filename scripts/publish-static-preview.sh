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
# `git checkout --orphan` refuses a name that already exists, so build the
# commit on a throwaway branch and push that to gh-pages instead.
STAGING="static-preview-$$"
cleanup() {
    git -C "$REPO_ROOT" worktree remove --force "$WORKTREE" 2>/dev/null || true
    git -C "$REPO_ROOT" branch -D "$STAGING" 2>/dev/null || true
    rm -rf "$WORKTREE"
}
trap cleanup EXIT

cd "$REPO_ROOT"
HEAD_SHA="$(git rev-parse --short HEAD)"
git worktree add --force --detach "$WORKTREE" >/dev/null
cd "$WORKTREE"
git checkout --orphan "$STAGING" >/dev/null 2>&1
git rm -rq --cached . 2>/dev/null || true
find . -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +

cp -a "$SRC/." .
git add -A
git commit -qm "Static preview built from $HEAD_SHA"
git push -q --force origin "HEAD:refs/heads/$BRANCH"

echo "Pushed $BRANCH ($(find . -name '*.html' | wc -l) pages)."
