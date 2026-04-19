#!/usr/bin/env bash
# =============================================================================
# git-branch-status.sh
# =============================================================================
#
# PURPOSE:
#   Single-shot report answering "what's the state of everything?".
#   Separates the three axes that are easy to conflate:
#     A. Worktree state (shared across all checkouts — one set of files)
#     B. Local branch vs remote (per-branch ahead/behind, or never pushed)
#     C. Integration status (merged into develop? open/merged/closed PR?)
#
# WHY THREE BLOCKS:
#   "Unstaged" and "staged" changes live in a single working tree + index
#   shared by every checkout — they are NOT per-branch. If you switch
#   branches with uncommitted changes, git carries them along (or refuses
#   if they conflict). So a per-branch "Unstaged" column would be
#   misleading. This script shows worktree state once, then a per-branch
#   table for the things that really are per-branch.
#
# USAGE:
#   ./ops/dev/git-branch-status.sh            # local refs only (fast)
#   ./ops/dev/git-branch-status.sh --fetch    # git fetch --all --prune first
#   ./ops/dev/git-branch-status.sh --no-pr    # skip gh PR lookup
#   ./ops/dev/git-branch-status.sh --no-color
#
# EXIT CODE:
#   Always 0 on success. This is a report, not a linter.
# =============================================================================

set -euo pipefail

BASE_BRANCH="develop"
UPSTREAM_REPO="HiEventsDev/Hi.Events"

DO_FETCH=false
NO_PR=false
NO_COLOR=false

usage() {
    sed -n '3,30p' "$0" | sed 's/^# \{0,1\}//'
    exit 0
}

for arg in "$@"; do
    case "$arg" in
        --fetch)    DO_FETCH=true ;;
        --no-pr)    NO_PR=true ;;
        --no-color) NO_COLOR=true ;;
        --help|-h)  usage ;;
        *) echo "Unknown flag: $arg" >&2; exit 1 ;;
    esac
done

if [ -t 1 ] && [ "$NO_COLOR" = false ]; then
    RED=$'\033[0;31m'
    GREEN=$'\033[0;32m'
    YELLOW=$'\033[0;33m'
    CYAN=$'\033[0;36m'
    BOLD=$'\033[1m'
    DIM=$'\033[2m'
    NC=$'\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; CYAN=''; BOLD=''; DIM=''; NC=''
fi

REPO_ROOT=$(git rev-parse --show-toplevel)
cd "$REPO_ROOT"

if [ "$DO_FETCH" = true ]; then
    echo "${DIM}Fetching from all remotes...${NC}"
    git fetch --all --prune --quiet
    echo ""
fi

CURRENT=$(git branch --show-current 2>/dev/null || echo "")
[ -z "$CURRENT" ] && CURRENT="(detached HEAD)"

# -----------------------------------------------------------------------------
# Block A: worktree state
# -----------------------------------------------------------------------------

echo "${BOLD}${CYAN}=== Worktree (shared state — not tied to any branch) ===${NC}"
echo "  On branch: ${BOLD}${CURRENT}${NC}"
echo ""

WT_STATUS=$(git status --porcelain=v1 2>/dev/null || true)

UNSTAGED_LINES=""
STAGED_LINES=""
UNSTAGED_COUNT=0
STAGED_COUNT=0

if [ -n "$WT_STATUS" ]; then
    while IFS= read -r line; do
        [ -z "$line" ] && continue
        xy="${line:0:2}"
        path="${line:3}"
        if [ "$xy" = "??" ]; then
            UNSTAGED_LINES+="    ?? $path"$'\n'
            UNSTAGED_COUNT=$((UNSTAGED_COUNT + 1))
        else
            x="${xy:0:1}"
            y="${xy:1:1}"
            if [ "$x" != " " ] && [ "$x" != "?" ]; then
                STAGED_LINES+="    $x  $path"$'\n'
                STAGED_COUNT=$((STAGED_COUNT + 1))
            fi
            if [ "$y" != " " ] && [ "$y" != "?" ]; then
                UNSTAGED_LINES+="    $y  $path"$'\n'
                UNSTAGED_COUNT=$((UNSTAGED_COUNT + 1))
            fi
        fi
    done <<< "$WT_STATUS"
fi

if [ "$UNSTAGED_COUNT" -eq 0 ] && [ "$STAGED_COUNT" -eq 0 ]; then
    echo "  ${GREEN}Worktree: clean${NC}"
else
    echo "  ${YELLOW}Unstaged${NC} (modified/untracked in working tree) — $UNSTAGED_COUNT"
    if [ "$UNSTAGED_COUNT" -gt 0 ]; then
        printf "%s" "$UNSTAGED_LINES"
    else
        echo "    (none)"
    fi
    echo ""
    echo "  ${YELLOW}Staged${NC} (in index, ready to commit) — $STAGED_COUNT"
    if [ "$STAGED_COUNT" -gt 0 ]; then
        printf "%s" "$STAGED_LINES"
    else
        echo "    (none)"
    fi
    echo ""
    echo "  ${DIM}→ These travel with you when you switch branches. Commit, stash, or discard.${NC}"
fi
echo ""

# -----------------------------------------------------------------------------
# Block B: per-branch table
# -----------------------------------------------------------------------------

declare -A PR_STATE PR_NUMBER
HAS_PR_DATA=false

if [ "$NO_PR" = false ] && command -v gh &>/dev/null && gh auth status &>/dev/null; then
    if command -v jq &>/dev/null; then
        PR_JSON=$(gh pr list --repo "$UPSTREAM_REPO" --state all --author "@me" \
            --limit 200 --json number,state,headRefName 2>/dev/null || echo "[]")
        while IFS=$'\t' read -r num state ref; do
            [ -z "$ref" ] && continue
            # PRs listed newest-first. Prefer newer — skip if already set.
            if [ -z "${PR_STATE[$ref]:-}" ]; then
                PR_STATE[$ref]="$state"
                PR_NUMBER[$ref]="$num"
            fi
        done < <(echo "$PR_JSON" | jq -r '.[] | "\(.number)\t\(.state)\t\(.headRefName)"')
        HAS_PR_DATA=true
    fi
fi

declare -A IS_MERGED_LOCAL
while IFS= read -r b; do
    [ -n "$b" ] && IS_MERGED_LOCAL[$b]=1
done < <(git branch --merged "$BASE_BRANCH" --format='%(refname:short)' 2>/dev/null || true)

echo "${BOLD}${CYAN}=== Per-branch status ===${NC}"

TAB=$'\t'
TABLE_HEADER=" ${TAB}Branch${TAB}Upstream${TAB}Ahead${TAB}Behind${TAB}Merged${TAB}PR"
TABLE_ROWS=""

NEVER_PUSHED=()
HAS_AHEAD=()
PR_OPEN=()
PR_MERGED_SAFE_TO_DELETE=()

while IFS='|' read -r branch upstream track; do
    [ -z "$branch" ] && continue

    if [ "$branch" = "$CURRENT" ]; then
        marker="*"
    else
        marker=" "
    fi

    if [ -z "$upstream" ]; then
        upstream_disp="(never pushed)"
        ahead_disp="—"
        behind_disp="—"
        NEVER_PUSHED+=("$branch")
    else
        upstream_disp="$upstream"
        if [[ "$track" == *"gone"* ]]; then
            upstream_disp="$upstream (gone)"
            ahead_disp="—"
            behind_disp="—"
        else
            ahead=0
            behind=0
            if [[ "$track" =~ ahead\ ([0-9]+) ]]; then
                ahead="${BASH_REMATCH[1]}"
            fi
            if [[ "$track" =~ behind\ ([0-9]+) ]]; then
                behind="${BASH_REMATCH[1]}"
            fi
            ahead_disp="$ahead"
            behind_disp="$behind"
            [ "$ahead" -gt 0 ] && HAS_AHEAD+=("$branch (+$ahead)")
        fi
    fi

    if [ "$branch" = "$BASE_BRANCH" ] || [ "$branch" = "jbj/local" ]; then
        merged_disp="n/a"
    elif [ -n "${IS_MERGED_LOCAL[$branch]:-}" ]; then
        merged_disp="yes"
    elif [ "$HAS_PR_DATA" = true ] && [ "${PR_STATE[$branch]:-}" = "MERGED" ]; then
        merged_disp="yes (squash)"
    else
        merged_disp="no"
    fi

    if [ "$HAS_PR_DATA" = true ]; then
        if [ -n "${PR_STATE[$branch]:-}" ]; then
            pr_num="${PR_NUMBER[$branch]}"
            pr_st_lower=$(echo "${PR_STATE[$branch]}" | tr '[:upper:]' '[:lower:]')
            pr_disp="#${pr_num} ${pr_st_lower}"
            case "${PR_STATE[$branch]}" in
                OPEN)   PR_OPEN+=("$branch (#$pr_num)") ;;
                MERGED) PR_MERGED_SAFE_TO_DELETE+=("$branch (#$pr_num)") ;;
            esac
        else
            pr_disp="—"
        fi
    else
        pr_disp="(gh n/a)"
    fi

    TABLE_ROWS+="${marker}"$'\t'"${branch}"$'\t'"${upstream_disp}"$'\t'"${ahead_disp}"$'\t'"${behind_disp}"$'\t'"${merged_disp}"$'\t'"${pr_disp}"$'\n'
done < <(git for-each-ref --format='%(refname:short)|%(upstream:short)|%(upstream:track)' refs/heads/ | sort)

if command -v column &>/dev/null; then
    { printf '%s\n' "$TABLE_HEADER"; printf '%s' "$TABLE_ROWS"; } | column -t -s $'\t'
else
    printf '%s\n' "$TABLE_HEADER" | tr '\t' ' '
    printf '%s' "$TABLE_ROWS" | tr '\t' ' '
fi
echo ""

# -----------------------------------------------------------------------------
# Block C: hints
# -----------------------------------------------------------------------------

echo "${BOLD}${CYAN}=== Hints ===${NC}"

if [ ${#PR_MERGED_SAFE_TO_DELETE[@]} -gt 0 ]; then
    echo "  ${GREEN}Merged upstream — safe to delete:${NC}"
    for b in "${PR_MERGED_SAFE_TO_DELETE[@]}"; do
        bname="${b%% *}"
        echo "    git branch -D $bname && git push origin --delete $bname"
    done
fi

if [ ${#HAS_AHEAD[@]} -gt 0 ]; then
    echo "  ${YELLOW}Unpushed commits:${NC}"
    for b in "${HAS_AHEAD[@]}"; do
        echo "    $b"
    done
fi

if [ ${#NEVER_PUSHED[@]} -gt 0 ]; then
    echo "  ${YELLOW}Never pushed (no upstream):${NC}"
    for b in "${NEVER_PUSHED[@]}"; do
        echo "    git push -u origin $b"
    done
fi

if [ ${#PR_OPEN[@]} -gt 0 ]; then
    echo "  ${CYAN}Open PRs upstream:${NC}"
    for b in "${PR_OPEN[@]}"; do
        echo "    $b"
    done
fi

if [ "$DO_FETCH" = false ]; then
    echo ""
    echo "  ${DIM}(remote data as of last fetch — pass --fetch for fresh)${NC}"
fi

if [ "$HAS_PR_DATA" = false ] && [ "$NO_PR" = false ]; then
    echo "  ${DIM}(gh CLI unavailable or unauthed — PR column skipped)${NC}"
fi
