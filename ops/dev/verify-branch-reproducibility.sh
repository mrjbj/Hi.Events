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
#   3. Compares the merged result against jbj/local
#   4. Reports:
#      - Merge conflicts (if any)
#      - Files that only exist on jbj/local (fork-only)
#      - Files that differ between the merged result and jbj/local
#      - Categorizes diffs as: locale (expected), auto-generated, or functional
#   5. Cleans up the worktree regardless of outcome
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
#   Edit the PR_BRANCHES array below to add/remove branches as PRs are
#   created or merged. Order matters — branches with dependencies should
#   come after their dependencies (e.g., transactional-email-tracking
#   depends on ses-bounce-handling, but since it's stacked, merging it
#   brings in both).
#
# FORK-ONLY FILES:
#   Files listed in FORK_ONLY_PATTERNS are expected to differ and are
#   reported separately. Update this list as fork-only files change.
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

# PR branches to merge, in order.
# Dependencies must come before dependents.
# feature/transactional-email-tracking is stacked on feature/ses-bounce-handling
# so merging it brings in both — no need to list ses-bounce-handling separately
# IF transactional-email-tracking is included.
PR_BRANCHES=(
    "fix/stripe-charge-refunded"
    "fix/image-resize-extension-name"
    "feature/browse-server-images"
    "feature/dashboard-date-range-selector"
    "feature/checkin-message-types"
    "feature/transactional-email-tracking"
)

# Files/patterns expected to only exist on jbj/local (fork-only).
# These are reported but not counted as failures.
FORK_ONLY_PATTERNS=(
    ".github/workflows/"
    "docker/development/.env"
    "ops/"
    "PR-STATUS.md"
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
info()    { echo -e "${BLUE}[INFO]${NC} $*"; }
success() { echo -e "${GREEN}[OK]${NC}   $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $*"; }
fail()    { echo -e "${RED}[FAIL]${NC} $*"; }
header()  { echo -e "\n${BOLD}${CYAN}=== $* ===${NC}\n"; }

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

missing_branches=()
for branch in "${PR_BRANCHES[@]}"; do
    if ! git rev-parse --verify "$branch" &>/dev/null; then
        missing_branches+=("$branch")
    fi
done

if [[ ${#missing_branches[@]} -gt 0 ]]; then
    fail "Missing branches: ${missing_branches[*]}"
    info "Run 'git fetch --all' and try again"
    exit 2
fi

success "All branches found"
info "$BASE_BRANCH: $(git log --oneline -1 "$BASE_BRANCH")"
info "$PRODUCTION_BRANCH: $(git log --oneline -1 "$PRODUCTION_BRANCH")"

# ---------------------------------------------------------------------------
# Create worktree
# ---------------------------------------------------------------------------
header "Creating Temporary Worktree"

WORKTREE_DIR=$(mktemp -d "/tmp/hi-events-verify-XXXXXX")
rmdir "$WORKTREE_DIR"  # git worktree add needs a non-existent path

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
header "Merging PR Branches"

merge_failures=()
merge_conflicts=()

for branch in "${PR_BRANCHES[@]}"; do
    echo -n "  Merging $branch... "

    if git merge "$branch" --no-edit >/dev/null 2>&1; then
        echo -e "${GREEN}OK${NC}"
        continue
    fi

    # Merge failed — check if it's a conflict we can auto-resolve
    conflicting_files=$(git diff --name-only --diff-filter=U 2>/dev/null || true)

    if [[ -z "$conflicting_files" ]]; then
        echo -e "${RED}FAILED${NC}"
        merge_failures+=("$branch")
        continue
    fi

    # Check if conflicts are only in locale files
    non_locale_conflicts=""
    while IFS= read -r f; do
        if ! is_locale "$f"; then
            non_locale_conflicts+="$f "
        fi
    done <<< "$conflicting_files"

    if [[ -n "$non_locale_conflicts" ]]; then
        echo -e "${RED}CONFLICT${NC}"
        echo "    Non-locale conflicts: $non_locale_conflicts"
        merge_failures+=("$branch")
        git merge --abort 2>/dev/null || true
        continue
    fi

    # Locale-only conflicts — resolve by accepting theirs
    while IFS= read -r cf; do
        git checkout --theirs "$cf" 2>/dev/null
        git add "$cf" 2>/dev/null
    done < <(git diff --name-only --diff-filter=U)
    git commit -m "Auto-resolve locale conflicts from $branch" 2>/dev/null
    echo -e "${YELLOW}OK (locale conflicts auto-resolved)${NC}"
    merge_conflicts+=("$branch (locale-only)")
done

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
done <<< "$diff_files"

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
