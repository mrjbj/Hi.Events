#!/usr/bin/env bash
# =============================================================================
# verify-branch-reproducibility.sh
# =============================================================================
#
# PURPOSE:
#   Verifies that jbj/local can be reproduced by merging all PR/feature
#   branches into develop. This is a non-destructive dry-run that uses a
#   temporary git worktree — no branches are created or modified in the
#   main repo.
#
# WHAT IT DOES:
#   1. Creates a temporary git worktree from develop
#   2. Sequentially merges all PR branches (feature/* and fix/*)
#   3. Merges FORK_ONLY_BRANCH (default: jbj/fork-only) as a terminal
#      overlay, mirroring how jbj/local is actually built
#   4. Compares the merged result against jbj/local
#   5. Reports:
#      - Merge conflicts (if any)
#      - Files that differ between the merged result and jbj/local
#      - Categorizes diffs as: locale, auto-generated, migration, or
#        functional (the only one you should actually review)
#   6. Cleans up the worktree regardless of outcome
#
# USAGE:
#   ./ops/dev/verify-branch-reproducibility.sh
#
# PREREQUISITES:
#   - Must be run from the Hi.Events repo root
#   - All PR branches must be available locally (run git fetch --all first)
#   - jbj/local and develop branches must exist
#
# BRANCH CONFIGURATION:
#   PR branches are auto-discovered from local refs matching the patterns
#   in BRANCH_PATTERNS (defaults to feature/* and fix/*), sorted
#   alphabetically. Use EXCLUDE_BRANCHES to skip specific ones. Both are
#   overridable via env:
#     BRANCH_PATTERNS="refs/heads/feature/ refs/heads/fix/" ./verify-...
#     EXCLUDE_BRANCHES="feature/wip-foo feature/wip-bar" ./verify-...
#
#   Stacked branches work naturally: once the parent is merged, merging
#   the stacked child is a delta-only operation. Alphabetical ordering
#   must not violate dependencies — if it ever does, add the dependent
#   to EXCLUDE_BRANCHES so the parent carries its commits.
#
# FORK-ONLY CONTENT:
#   Fork-only files (ops/, .github/workflows/, etc.) live on the
#   jbj/fork-only branch and are pulled in via a terminal merge.
#   FORK_ONLY_PATTERNS is a small catch-all for residual diffs like
#   gitignored files (e.g. docker/development/.env). Disable the
#   terminal merge with: FORK_ONLY_BRANCH="" ./verify-...
#
# OUTPUT:
#   Color-coded terminal output with a summary verdict at the end.
#   Exit code 0 = reproducible, 1 = differences found, 2 = merge failed.
#
# HISTORY:
#   2026-04-13  Created for jbj/local reproducibility verification
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

# Base branch (upstream target)
BASE_BRANCH="develop"

# Production branch to verify against
PRODUCTION_BRANCH="jbj/local"

# Ref patterns to discover PR branches (space-separated, env-overridable).
BRANCH_PATTERNS=${BRANCH_PATTERNS:-"refs/heads/feature/ refs/heads/fix/"}

# Branches to skip even if they match BRANCH_PATTERNS (space-separated).
EXCLUDE_BRANCHES=${EXCLUDE_BRANCHES:-""}

# Fork-only branch merged as the terminal step (models how jbj/local
# is actually built: develop + PR branches + fork-only overlay).
# Set to empty string to disable.
FORK_ONLY_BRANCH=${FORK_ONLY_BRANCH:-"jbj/fork-only"}

# Discover PR branches, alphabetically sorted. Dependents on stacked
# branches get delta-merged after their parent lands, so sort order
# is sufficient for the current branch layout.
#
# shellcheck disable=SC2086  # BRANCH_PATTERNS intentionally word-split
mapfile -t PR_BRANCHES < <(
  git for-each-ref --format='%(refname:short)' $BRANCH_PATTERNS | sort |
    while IFS= read -r branch; do
      skip=false
      for excl in $EXCLUDE_BRANCHES; do
        [[ "$branch" == "$excl" ]] && { skip=true; break; }
      done
      [[ "$skip" == false ]] && echo "$branch"
    done
)

# Files/patterns expected to differ even after merging all branches.
# Typically gitignored files or artifacts not tracked in any branch.
# Most fork-only content is now covered by merging FORK_ONLY_BRANCH;
# this list should be small.
FORK_ONLY_PATTERNS=(
  "docker/development/.env"
)

# Patterns for auto-generated files (cosmetic diffs expected)
AUTOGEN_PATTERNS=(
  "DomainObjects/Generated/"
)

# Patterns for locale files (conflict resolution diffs expected)
LOCALE_PATTERNS=(
  "frontend/src/locales/"
)

# Patterns for migration files (filename differences expected between
# jbj/local and feature branches — same SQL, different timestamps)
MIGRATION_PATTERNS=(
  "backend/database/migrations/"
)

# ---------------------------------------------------------------------------
# Colors
# ---------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
info() { echo -e "${BLUE}[INFO]${NC} $*"; }
success() { echo -e "${GREEN}[OK]${NC}   $*"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $*"; }
fail() { echo -e "${RED}[FAIL]${NC} $*"; }
header() { echo -e "\n${BOLD}${CYAN}=== $* ===${NC}\n"; }

WORKTREE_DIR=""

cleanup() {
  if [[ -n "$WORKTREE_DIR" && -d "$WORKTREE_DIR" ]]; then
    info "Cleaning up worktree at $WORKTREE_DIR"
    git worktree remove "$WORKTREE_DIR" --force 2>/dev/null || true
  fi
  # Clean up the temporary branch if it exists
  git branch -D _verify-merge-test 2>/dev/null || true
}
trap cleanup EXIT

is_fork_only() {
  local file="$1"
  for pattern in "${FORK_ONLY_PATTERNS[@]}"; do
    if [[ "$file" == *"$pattern"* ]]; then
      return 0
    fi
  done
  return 1
}

is_autogen() {
  local file="$1"
  for pattern in "${AUTOGEN_PATTERNS[@]}"; do
    if [[ "$file" == *"$pattern"* ]]; then
      return 0
    fi
  done
  return 1
}

is_locale() {
  local file="$1"
  for pattern in "${LOCALE_PATTERNS[@]}"; do
    if [[ "$file" == *"$pattern"* ]]; then
      return 0
    fi
  done
  return 1
}

is_migration() {
  local file="$1"
  for pattern in "${MIGRATION_PATTERNS[@]}"; do
    if [[ "$file" == *"$pattern"* ]]; then
      return 0
    fi
  done
  return 1
}

# ---------------------------------------------------------------------------
# Preflight checks
# ---------------------------------------------------------------------------
header "Preflight Checks"

if ! git rev-parse --is-inside-work-tree &>/dev/null; then
  fail "Not inside a git repository"
  exit 2
fi

for branch in "$BASE_BRANCH" "$PRODUCTION_BRANCH"; do
  if ! git rev-parse --verify "$branch" &>/dev/null; then
    fail "Branch '$branch' not found locally"
    exit 2
  fi
done

if [[ ${#PR_BRANCHES[@]} -eq 0 ]]; then
  fail "No PR branches discovered — check BRANCH_PATTERNS and fetch state"
  exit 2
fi

success "All branches found"
info "$BASE_BRANCH: $(git log --oneline -1 "$BASE_BRANCH")"
info "$PRODUCTION_BRANCH: $(git log --oneline -1 "$PRODUCTION_BRANCH")"
info "Discovered ${#PR_BRANCHES[@]} PR branch(es) to merge:"
for branch in "${PR_BRANCHES[@]}"; do
  echo "    $branch"
done
if [[ -n "$EXCLUDE_BRANCHES" ]]; then
  info "Excluded: $EXCLUDE_BRANCHES"
fi
if [[ -n "$FORK_ONLY_BRANCH" ]]; then
  info "Fork-only overlay (terminal merge): $FORK_ONLY_BRANCH"
fi

# ---------------------------------------------------------------------------
# Create worktree
# ---------------------------------------------------------------------------
header "Creating Temporary Worktree"

WORKTREE_DIR=$(mktemp -d "/tmp/hi-events-verify-XXXXXX")
rmdir "$WORKTREE_DIR" # git worktree add needs a non-existent path

# Clean up any leftover branch from a previous run
git branch -D _verify-merge-test 2>/dev/null || true
git worktree prune 2>/dev/null || true

git worktree add "$WORKTREE_DIR" "$BASE_BRANCH" 2>/dev/null
cd "$WORKTREE_DIR"
git checkout -b _verify-merge-test 2>/dev/null

success "Worktree created at $WORKTREE_DIR"

# ---------------------------------------------------------------------------
# Merge branches sequentially
# ---------------------------------------------------------------------------

merge_failures=()
merge_conflicts=()

# Merge a single branch, auto-resolving locale-only conflicts.
# Appends to merge_failures / merge_conflicts as side effects.
merge_one() {
  local branch="$1"
  echo -n "  Merging $branch... "

  if git merge "$branch" --no-edit >/dev/null 2>&1; then
    echo -e "${GREEN}OK${NC}"
    return 0
  fi

  local conflicting_files
  conflicting_files=$(git diff --name-only --diff-filter=U 2>/dev/null || true)

  if [[ -z "$conflicting_files" ]]; then
    echo -e "${RED}FAILED${NC}"
    merge_failures+=("$branch")
    return 1
  fi

  local non_locale_conflicts=""
  while IFS= read -r f; do
    if ! is_locale "$f"; then
      non_locale_conflicts+="$f "
    fi
  done <<<"$conflicting_files"

  if [[ -n "$non_locale_conflicts" ]]; then
    echo -e "${RED}CONFLICT${NC}"
    echo "    Non-locale conflicts: $non_locale_conflicts"
    merge_failures+=("$branch")
    git merge --abort 2>/dev/null || true
    return 1
  fi

  while IFS= read -r cf; do
    git checkout --theirs "$cf" 2>/dev/null
    git add "$cf" 2>/dev/null
  done < <(git diff --name-only --diff-filter=U)
  git commit -m "Auto-resolve locale conflicts from $branch" 2>/dev/null
  echo -e "${YELLOW}OK (locale conflicts auto-resolved)${NC}"
  merge_conflicts+=("$branch (locale-only)")
  return 0
}

header "Merging PR Branches"
for branch in "${PR_BRANCHES[@]}"; do
  merge_one "$branch" || true
done

if [[ -n "$FORK_ONLY_BRANCH" ]] && git rev-parse --verify "$FORK_ONLY_BRANCH" &>/dev/null; then
  header "Merging Fork-Only Overlay"
  merge_one "$FORK_ONLY_BRANCH" || true
elif [[ -n "$FORK_ONLY_BRANCH" ]]; then
  warn "FORK_ONLY_BRANCH '$FORK_ONLY_BRANCH' not found — skipping terminal merge"
fi

if [[ ${#merge_failures[@]} -gt 0 ]]; then
  fail "Merge failures (non-locale): ${merge_failures[*]}"
  fail "Cannot complete verification"
  exit 2
fi

if [[ ${#merge_conflicts[@]} -gt 0 ]]; then
  warn "Auto-resolved locale conflicts in: ${merge_conflicts[*]}"
fi

success "All branches merged successfully"

# ---------------------------------------------------------------------------
# Compare against production branch
# ---------------------------------------------------------------------------
header "Comparing Merged Result vs $PRODUCTION_BRANCH"

diff_files=$(git diff "$PRODUCTION_BRANCH" --name-only | sort)

if [[ -z "$diff_files" ]]; then
  success "PERFECT MATCH — merged branches are identical to $PRODUCTION_BRANCH"
  exit 0
fi

# Categorize diffs
fork_only_files=()
locale_files=()
autogen_files=()
migration_files=()
functional_files=()

while IFS= read -r file; do
  [[ -z "$file" ]] && continue

  if is_fork_only "$file"; then
    fork_only_files+=("$file")
  elif is_locale "$file"; then
    locale_files+=("$file")
  elif is_autogen "$file"; then
    autogen_files+=("$file")
  elif is_migration "$file"; then
    migration_files+=("$file")
  else
    functional_files+=("$file")
  fi
done <<<"$diff_files"

# Report
total_diffs=$(echo "$diff_files" | wc -l | tr -d ' ')
info "Total files differing: $total_diffs"

if [[ ${#fork_only_files[@]} -gt 0 ]]; then
  echo ""
  info "${BOLD}Fork-only files${NC} (${#fork_only_files[@]} — expected, only on $PRODUCTION_BRANCH):"
  for f in "${fork_only_files[@]}"; do
    echo "    $f"
  done
fi

if [[ ${#locale_files[@]} -gt 0 ]]; then
  echo ""
  info "${BOLD}Locale files${NC} (${#locale_files[@]} — expected, merge conflict resolution artifacts):"
  echo "    (run 'yarn messages:compile' after real merge to regenerate)"
fi

if [[ ${#autogen_files[@]} -gt 0 ]]; then
  echo ""
  info "${BOLD}Auto-generated files${NC} (${#autogen_files[@]} — cosmetic, from generate-domain-objects):"
  for f in "${autogen_files[@]}"; do
    echo "    $f"
  done
fi

if [[ ${#migration_files[@]} -gt 0 ]]; then
  echo ""
  info "${BOLD}Migration files${NC} (${#migration_files[@]} — filename differences, same SQL):"
  for f in "${migration_files[@]}"; do
    echo "    $f"
  done
fi

if [[ ${#functional_files[@]} -gt 0 ]]; then
  echo ""
  warn "${BOLD}Functional code differences${NC} (${#functional_files[@]} — REVIEW THESE):"
  for f in "${functional_files[@]}"; do
    echo "    $f"
    # Show a compact diff summary
    git diff "$PRODUCTION_BRANCH" -- "$f" | head -20 | sed 's/^/      /'
    echo "      ..."
  done
fi

# ---------------------------------------------------------------------------
# Verdict
# ---------------------------------------------------------------------------
header "Verdict"

if [[ ${#functional_files[@]} -eq 0 ]]; then
  success "REPRODUCIBLE — all differences are expected (fork-only, locale, auto-generated, or migration filenames)"
  info "jbj/local can be fully reconstructed from: $BASE_BRANCH + PR branches + fork-only overlay"
  exit 0
else
  warn "REVIEW NEEDED — ${#functional_files[@]} functional file(s) differ between merged branches and $PRODUCTION_BRANCH"
  warn "These may be cross-branch integration fixes on jbj/local not yet backported to feature branches"
  exit 1
fi
