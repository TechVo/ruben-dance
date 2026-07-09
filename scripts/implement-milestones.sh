#!/usr/bin/env bash
# Headless milestone runner: one fresh `claude -p` (Sonnet) per milestone,
# verification gate between milestones, stops on the first failure.
#
# Usage:
#   scripts/implement-milestones.sh            # run all milestones 01..16
#   scripts/implement-milestones.sh 05         # start from milestone 05
#   scripts/implement-milestones.sh 05 08      # run milestones 05..08
#
# Permissions: headless mode cannot prompt. Either maintain an allowlist in
# .claude/settings.json, or export RD_SKIP_PERMISSIONS=1 to run with
# --dangerously-skip-permissions (trusted local machine only).

set -euo pipefail
cd "$(dirname "$0")/.."

FROM="${1:-01}"
TO="${2:-16}"
PLUGIN_DIR="plugin/ruben-dance"

PERM_ARGS=(--permission-mode acceptEdits)
if [[ "${RD_SKIP_PERMISSIONS:-0}" == "1" ]]; then
  PERM_ARGS=(--dangerously-skip-permissions)
fi

gate() {
  local m="$1"
  echo "=== Gate after M${m} ==="
  if [[ -f "$PLUGIN_DIR/composer.json" ]]; then
    (cd "$PLUGIN_DIR" && composer phpcs && composer test) || {
      echo "GATE FAILED after M${m}: phpcs/tests red. Stopping." >&2
      exit 1
    }
  fi
  if git rev-parse --git-dir >/dev/null 2>&1; then
    if ! git diff --quiet || ! git diff --cached --quiet; then
      echo "GATE FAILED after M${m}: uncommitted changes left behind. Stopping." >&2
      exit 1
    fi
  fi
}

for file in docs/implementation/[0-9][0-9]-*.md; do
  num="$(basename "$file" | cut -d- -f1)"
  [[ "$num" == "00" ]] && continue
  [[ "$num" < "$FROM" || "$num" > "$TO" ]] && continue

  echo "=== M${num}: $file ==="
  claude -p "You are the milestone-implementer agent defined in .claude/agents/milestone-implementer.md. Follow that definition exactly. Your assigned milestone file is: ${file}" \
    --model sonnet \
    "${PERM_ARGS[@]}"

  gate "$num"
done

echo "All requested milestones completed and gated."
