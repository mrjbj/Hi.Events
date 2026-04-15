#!/bin/bash
# docker-cleanup.sh — Show what's taking space and offer cleanup options
# Usage: ./docker-cleanup.sh <command>
#   help       — Show usage (default)
#   report     — Show what's taking space (no changes)
#   inspect    — Drill into each volume to show contents and largest files
#   safe       — Remove things that are safe to delete
#   moderate   — Safe + unused images (will need re-pull later)
#   aggressive — Moderate + dangling volumes (USE WITH CAUTION)

set -e

YELLOW='\033[1;33m'
GREEN='\033[1;32m'
RED='\033[1;31m'
CYAN='\033[1;36m'
NC='\033[0m'

MODE="${1:-help}"

# Detect what kind of data a volume holds based on file signatures
detect_volume_type() {
  local vol="$1"
  local listing
  listing=$(docker run --rm -v "$vol":/inspect:ro alpine ls /inspect 2>/dev/null || echo "")

  if echo "$listing" | grep -q "PG_VERSION"; then
    echo "PostgreSQL data"
  elif echo "$listing" | grep -q "appendonly.aof\|dump.rdb"; then
    echo "Redis data"
  elif echo "$listing" | grep -q "ibdata1\|mysql"; then
    echo "MySQL data"
  elif echo "$listing" | grep -q "WiredTiger\|mongod.lock"; then
    echo "MongoDB data"
  elif echo "$listing" | grep -q "vendor"; then
    # Check if it's PHP/Composer
    if docker run --rm -v "$vol":/inspect:ro alpine test -f /inspect/vendor/autoload.php 2>/dev/null; then
      echo "PHP vendor (Composer)"
    else
      echo "Unknown"
    fi
  elif echo "$listing" | grep -q "node_modules"; then
    echo "Node.js modules"
  elif echo "$listing" | grep -q "elasticsearch\|nodes"; then
    echo "Elasticsearch data"
  else
    echo "Unknown"
  fi
}

# Inspect a single volume: show type and top 4 largest items
inspect_volume() {
  local vol="$1"
  local vol_type
  vol_type=$(detect_volume_type "$vol")

  # Check which containers use this volume
  local containers
  containers=$(docker ps -a --filter "volume=$vol" --format "{{.Names}} ({{.Status}})" 2>/dev/null)

  if [ -n "$containers" ]; then
    echo -e "  ${YELLOW}Volume: ${vol}${NC} [${CYAN}${vol_type}${NC}] — ${GREEN}IN USE${NC}"
    echo "$containers" | while IFS= read -r c; do
      echo -e "    container: ${GREEN}${c}${NC}"
    done
  else
    echo -e "  ${YELLOW}Volume: ${vol}${NC} [${CYAN}${vol_type}${NC}] — ${RED}NOT IN USE (orphaned)${NC}"
  fi

  # Get top 4 largest items (skip the root /inspect entry itself)
  local top_items
  top_items=$(docker run --rm -v "$vol":/inspect:ro alpine \
    sh -c 'du -ah /inspect 2>/dev/null | sort -rh | grep -v "^[^ ]*[[:space:]]/inspect$" | head -4' 2>/dev/null)

  if [ -z "$top_items" ]; then
    echo -e "    ${RED}(empty or unreadable)${NC}"
  else
    echo "$top_items" | while IFS= read -r line; do
      # Strip the /inspect prefix for cleaner display
      cleaned=$(echo "$line" | sed 's|/inspect/||; s|/inspect||')
      echo -e "    $cleaned"
    done
  fi
  echo ""
}

# --- Actions ---
case "$MODE" in
  help)
    echo -e "${CYAN}Usage:${NC} $0 <command>"
    echo ""
    echo "Commands:"
    echo -e "  ${GREEN}help${NC}        Show this help message (default)"
    echo -e "  ${GREEN}report${NC}      Show what's taking space (read-only, no changes)"
    echo -e "  ${GREEN}inspect${NC}     Drill into each volume to show contents and largest files"
    echo -e "  ${GREEN}safe${NC}        Remove safe items (dangling images, stopped containers, build cache)"
    echo -e "  ${YELLOW}moderate${NC}    Safe + unused images (will need re-pull later)"
    echo -e "  ${RED}aggressive${NC}  Moderate + dangling volumes (USE WITH CAUTION)"
    ;;
  report)
    echo -e "${CYAN}=== Docker Disk Usage Overview ===${NC}"
    docker system df
    echo ""

    echo -e "${CYAN}=== Dangling Images (untagged build leftovers) ===${NC}"
    DANGLING=$(docker images -f "dangling=true" -q | wc -l | tr -d ' ')
    echo -e "  Count: ${YELLOW}${DANGLING}${NC}"
    echo -e "  Safety: ${GREEN}SAFE${NC} — orphaned layers, never used by running containers"
    echo -e "  Space impact: ${GREEN}HIGH${NC} — often the biggest source of waste"
    echo -e "  To remove:  ${GREEN}docker image prune -f${NC}"
    echo ""

    echo -e "${CYAN}=== Unused Images (no container references them) ===${NC}"
    docker images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}\t{{.CreatedSince}}" | head -20
    TOTAL_IMAGES=$(docker images -q | wc -l | tr -d ' ')
    USED_IMAGES=$(docker ps -a --format "{{.Image}}" | sort -u | wc -l | tr -d ' ')
    echo -e "  Total images: ${YELLOW}${TOTAL_IMAGES}${NC}, Used by containers: ${YELLOW}${USED_IMAGES}${NC}"
    echo -e "  Safety: ${YELLOW}MODERATE${NC} — unused ones are safe but will need re-pull if needed later"
    echo -e "  Space impact: ${GREEN}HIGH${NC}"
    echo -e "  To remove all unused:  ${YELLOW}docker image prune -a -f${NC}"
    echo -e "  To remove one:         ${YELLOW}docker rmi <image_id>${NC}"
    echo ""

    echo -e "${CYAN}=== Stopped Containers ===${NC}"
    docker ps -a --filter "status=exited" --format "table {{.Names}}\t{{.Status}}\t{{.Size}}" | head -20
    STOPPED=$(docker ps -a --filter "status=exited" -q | wc -l | tr -d ' ')
    echo -e "  Count: ${YELLOW}${STOPPED}${NC}"
    echo -e "  Safety: ${GREEN}SAFE${NC} — unless you need their logs"
    echo -e "  Space impact: ${YELLOW}LOW-MODERATE${NC}"
    echo -e "  To remove all stopped: ${GREEN}docker container prune -f${NC}"
    echo -e "  To remove one:         ${GREEN}docker rm <container_id>${NC}"
    echo ""

    echo -e "${CYAN}=== Volumes ===${NC}"
    DANGLING_VOLS=$(docker volume ls -f "dangling=true" -q | wc -l | tr -d ' ')
    ALL_VOLS=$(docker volume ls -q)
    if [ -z "$ALL_VOLS" ]; then
      echo -e "  ${YELLOW}No volumes found.${NC}"
    else
      for vol in $ALL_VOLS; do
        containers=$(docker ps -a --filter "volume=$vol" --format "{{.Names}} ({{.Status}})" 2>/dev/null)
        if [ -n "$containers" ]; then
          clist=$(echo "$containers" | paste -sd", " -)
          echo -e "  ${GREEN}IN USE${NC}   $vol  ← ${clist}"
        else
          echo -e "  ${RED}ORPHAN${NC}   $vol"
        fi
      done
    fi
    echo ""
    echo -e "  Dangling (unused) volumes: ${YELLOW}${DANGLING_VOLS}${NC}"
    echo -e "  Safety: ${RED}DANGEROUS${NC} — named volumes may contain database data, uploads, etc."
    echo -e "  Dangling volumes are ${YELLOW}USUALLY SAFE${NC} but verify they're not from a stopped DB container"
    echo -e "  Space impact: ${GREEN}HIGH${NC} — database volumes can be very large"
    echo -e "  To remove dangling:    ${YELLOW}docker volume prune -f${NC}"
    echo -e "  To remove one:         ${RED}docker volume rm <volume_name>${NC}"
    echo -e "  To inspect first:      ${GREEN}$0 inspect${NC}"
    echo ""

    echo -e "${CYAN}=== Build Cache ===${NC}"
    docker builder du --verbose 2>/dev/null | tail -5 || docker system df | grep "Build Cache"
    echo -e "  Safety: ${GREEN}SAFE${NC} — just makes next build slower"
    echo -e "  Space impact: ${GREEN}HIGH${NC} — can accumulate to many GB"
    echo -e "  To remove:  ${GREEN}docker builder prune -f${NC}"
    echo ""

    echo -e "${CYAN}=== Unused Networks ===${NC}"
    UNUSED_NETS=$(docker network ls -f "dangling=true" -q 2>/dev/null | wc -l | tr -d ' ')
    echo -e "  Count: ${YELLOW}${UNUSED_NETS}${NC}"
    echo -e "  Safety: ${GREEN}SAFE${NC}"
    echo -e "  Space impact: ${RED}NEGLIGIBLE${NC} — networks use almost no disk"
    echo -e "  To remove:  ${GREEN}docker network prune -f${NC}"
    echo ""

    echo -e "${CYAN}=== Summary ===${NC}"
    echo "  This was a dry run. No changes were made."
    echo "  Each section above shows the command to clean that specific category."
    echo ""
    echo "  Bundled cleanup options:"
    echo -e "    ${GREEN}$0 safe${NC}        — dangling images + stopped containers + build cache + networks"
    echo -e "    ${YELLOW}$0 moderate${NC}    — all of safe + unused images (will need re-pull later)"
    echo -e "    ${RED}$0 aggressive${NC}  — all of moderate + dangling volumes"
    ;;
  inspect)
    echo -e "${CYAN}=== Volume Inspection ===${NC}"
    echo "Mounting each volume read-only in a temporary Alpine container..."
    echo ""
    VOLUMES=$(docker volume ls -q)
    if [ -z "$VOLUMES" ]; then
      echo -e "  ${YELLOW}No volumes found.${NC}"
    else
      for vol in $VOLUMES; do
        inspect_volume "$vol"
      done
    fi
    ;;
  safe)
    echo -e "${CYAN}=== Running Safe Cleanup ===${NC}"
    echo "Removing dangling images..."
    docker image prune -f
    echo ""
    echo "Removing stopped containers..."
    docker container prune -f
    echo ""
    echo "Removing build cache..."
    docker builder prune -f
    echo ""
    echo "Removing unused networks..."
    docker network prune -f
    echo ""
    echo -e "${GREEN}Safe cleanup complete.${NC}"
    docker system df
    ;;
  moderate)
    echo -e "${YELLOW}=== Running Moderate Cleanup ===${NC}"
    echo "Removing dangling images..."
    docker image prune -f
    echo ""
    echo "Removing stopped containers..."
    docker container prune -f
    echo ""
    echo "Removing build cache..."
    docker builder prune -f
    echo ""
    echo "Removing unused networks..."
    docker network prune -f
    echo ""
    echo "Removing unused images (will need re-pull if needed later)..."
    docker image prune -a -f
    echo ""
    echo -e "${GREEN}Moderate cleanup complete.${NC}"
    docker system df
    ;;
  aggressive)
    echo -e "${RED}=== Running Aggressive Cleanup ===${NC}"
    echo -e "${RED}This will remove ALL unused images, containers, networks, and dangling volumes.${NC}"
    echo -e "${RED}It will NOT remove named volumes (to protect database data).${NC}"
    read -p "Are you sure? (y/N) " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
      docker system prune -a -f
      echo ""
      echo "Removing dangling volumes..."
      docker volume prune -f
      echo ""
      echo -e "${GREEN}Aggressive cleanup complete.${NC}"
      docker system df
    else
      echo "Cancelled."
    fi
    ;;
  *)
    echo -e "${RED}Unknown command: $MODE${NC}"
    echo ""
    $0 help
    ;;
esac
