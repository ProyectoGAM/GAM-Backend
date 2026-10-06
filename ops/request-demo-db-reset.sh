#!/usr/bin/env bash
set -euo pipefail

reset_requested=false

if [[ "${GITHUB_EVENT_NAME:-}" == push \
    && "${GITHUB_REF:-}" == refs/heads/main \
    && "${DEMO_DB_RESET_ENABLED:-}" == true ]]; then
    associated_prs="$(gh api "repos/${GITHUB_REPOSITORY}/commits/${GITHUB_SHA}/pulls?per_page=100")"
    if python3 -c '
import json
import sys

merge_sha = sys.argv[1]
pull_requests = json.load(sys.stdin)
sys.exit(0 if any(
    pr.get("merged_at") is not None
    and (pr.get("base") or {}).get("ref") == "main"
    and pr.get("merge_commit_sha") == merge_sha
    for pr in pull_requests
) else 1)
' "$GITHUB_SHA" <<< "$associated_prs"; then
        reset_requested=true
    fi
fi

printf '%s\n' "$reset_requested"
