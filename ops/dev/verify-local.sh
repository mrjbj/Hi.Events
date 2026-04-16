#!/usr/bin/env bash
#
# verify-local.sh — Verify that jbj/local can be reproduced from develop
# plus all feature branches and fork-only content.
#
# Creates a temporary _verify-merge-test branch from develop, merges each
# feature branch in order, then merges jbj/fork-only. Reports conflicts
# per branch and optionally runs tests if all merges succeed.
#
# Usage:
#   ./ops/dev/verify-local.sh              # Verify merge only
#   ./ops/dev/verify-local.sh --test       # Verify merge + run tests
#   ./ops/dev/verify-local.sh --keep       # Don't delete temp branch after
#   ./ops/dev/verify-local.sh --test --keep
#
# The branch merge order and list is defined in the BRANCHES array below.
# Update this array when adding or removing feature branches.
#

set -euo pipefail

VERIFY_BRANCH="_verify-merge-test"
BASE_BRANCH="develop"
RUN_TESTS=false
KEEP_BRANCH=false

# --- Feature branches to merge, in order ---
# PR branches (based on develop, submitted upstream):
BRANCHES=(
    "fix/stripe-charge-refunded"
    "feature/browse-server-images"
    "fix/image-resize-extension-name"
    "feature/checkin-message-types"
    "feature/dashboard-date-range-selector"
    "feature/transactional-email-tracking"
)

# Fork-only branch (never submitted upstream, merged last):
FORK_ONLY_BRANCH="jbj/fork-only"

# --- Parse args ---
for arg in "$@"; do
    case "$arg" in
        --test) RUN_TESTS=true ;;
        --keep) KEEP_BRANCH=true ;;
        --help|-h)
            echo "Usage: verify-local.sh [--test] [--keep]"
            echo "  --test   Run backend tests and frontend type check after merge"
            echo "  --keep   Keep the $_VERIFY_BRANCH branch after verification"
            exit 0
            ;;
    esac
done

# --- Helpers ---
ORIGINAL_BRANCH=$(git branch --show-current)
FAILED=()
SUCCEEDED=()
LOCALE_CONFLICTS_AUTO=()

cleanup() {
    echo ""
    echo "Returning to $ORIGINAL_BRANCH..."
    git checkout "$ORIGINAL_BRANCH" --quiet 2>/dev/null || true
    if [ "$KEEP_BRANCH" = false ] && git branch --list "$VERIFY_BRANCH" | grep -q "$VERIFY_BRANCH"; then
        git branch -D "$VERIFY_BRANCH" --quiet 2>/dev/null || true
        echo "Deleted temporary branch $VERIFY_BRANCH"
    fi
}

trap cleanup EXIT

resolve_locale_conflicts() {
    local branch="$1"
    local locale_conflicts
    locale_conflicts=$(git diff --name-only --diff-filter=U | grep -E "locales/.*\.(po|js)$" || true)
    local other_conflicts
    other_conflicts=$(git diff --name-only --diff-filter=U | grep -v -E "locales/.*\.(po|js)$" || true)

    if [ -n "$other_conflicts" ]; then
        return 1
    fi

    if [ -n "$locale_conflicts" ]; then
        echo "    Auto-resolving locale conflicts (taking theirs)..."
        echo "$locale_conflicts" | xargs git checkout --theirs --
        echo "$locale_conflicts" | xargs git add
        git commit --no-edit -m "Auto-resolve locale conflicts from $branch" --quiet
        LOCALE_CONFLICTS_AUTO+=("$branch")
        return 0
    fi

    return 0
}

merge_branch() {
    local branch="$1"
    local label="$2"

    if ! git rev-parse --verify "$branch" &>/dev/null; then
        echo "  SKIP    $label ($branch does not exist)"
        return
    fi

    echo -n "  Merging $label ($branch)... "

    if git merge "$branch" --no-edit --quiet 2>/dev/null; then
        echo "OK"
        SUCCEEDED+=("$branch")
    else
        if resolve_locale_conflicts "$branch"; then
            echo "OK (locale conflicts auto-resolved)"
            SUCCEEDED+=("$branch")
        else
            echo "CONFLICT"
            FAILED+=("$branch")
            local conflicts
            conflicts=$(git diff --name-only --diff-filter=U)
            echo "    Conflicting files:"
            echo "$conflicts" | sed 's/^/      /'
            git merge --abort
        fi
    fi
}

# --- Main ---
echo "=== Verify jbj/local Reproducibility ==="
echo ""

# Ensure clean working tree
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "ERROR: Working tree is not clean. Commit or stash changes first."
    exit 1
fi

# Delete old verify branch if it exists
if git branch --list "$VERIFY_BRANCH" | grep -q "$VERIFY_BRANCH"; then
    git branch -D "$VERIFY_BRANCH" --quiet
fi

# Create temp branch from develop
echo "Creating $VERIFY_BRANCH from $BASE_BRANCH..."
git checkout -b "$VERIFY_BRANCH" "$BASE_BRANCH" --quiet
echo ""

# Merge PR branches
echo "Merging PR branches:"
for branch in "${BRANCHES[@]}"; do
    label=$(echo "$branch" | sed 's|feature/||;s|fix/||')
    merge_branch "$branch" "$label"
done
echo ""

# Merge fork-only
echo "Merging fork-only:"
merge_branch "$FORK_ONLY_BRANCH" "fork-only"
echo ""

# Report
echo "=== Results ==="
echo "Succeeded: ${#SUCCEEDED[@]}"
for b in "${SUCCEEDED[@]}"; do
    echo "  OK  $b"
done

if [ ${#LOCALE_CONFLICTS_AUTO[@]} -gt 0 ]; then
    echo ""
    echo "Locale conflicts auto-resolved: ${#LOCALE_CONFLICTS_AUTO[@]}"
    for b in "${LOCALE_CONFLICTS_AUTO[@]}"; do
        echo "  ~   $b"
    done
fi

if [ ${#FAILED[@]} -gt 0 ]; then
    echo ""
    echo "FAILED: ${#FAILED[@]}"
    for b in "${FAILED[@]}"; do
        echo "  X   $b"
    done
    echo ""
    echo "jbj/local CANNOT be reproduced cleanly. Fix conflicts above."
    exit 1
fi

echo ""
echo "All branches merged successfully into $VERIFY_BRANCH."

# Run tests if requested
if [ "$RUN_TESTS" = true ]; then
    echo ""
    echo "=== Running Tests ==="

    DOCKER_COMPOSE_DIR="$(cd "$(dirname "$0")/../../docker/development" && pwd)"
    DOCKER_COMPOSE="docker compose -f $DOCKER_COMPOSE_DIR/docker-compose.dev.yml"

    echo "Backend unit tests..."
    if $DOCKER_COMPOSE exec backend php artisan test --testsuite=Unit 2>&1 | tail -3; then
        echo "Backend tests: PASSED"
    else
        echo "Backend tests: FAILED"
    fi

    echo ""
    echo "Frontend type check..."
    FRONTEND_DIR="$(cd "$(dirname "$0")/../../frontend" && pwd)"
    if (cd "$FRONTEND_DIR" && npx tsc --noEmit 2>&1 | grep -c "error TS") 2>/dev/null; then
        echo "Frontend type check: HAS ERRORS (check output above)"
    else
        echo "Frontend type check: PASSED"
    fi
fi

echo ""
if [ "$KEEP_BRANCH" = true ]; then
    echo "Temporary branch $VERIFY_BRANCH kept for inspection."
    echo "Delete manually with: git branch -D $VERIFY_BRANCH"
else
    echo "Verification complete."
fi
