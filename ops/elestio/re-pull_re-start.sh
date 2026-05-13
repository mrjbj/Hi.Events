#!/bin/bash
# pull fresh docker image, stop/start docker stack
set -euo pipefail

cd ~/elestio_app_directory/hi-events || exit 1

IMAGE=ghcr.io/mrjbj/hi-events-all-in-one:latest

OLD_ID=$(docker image inspect --format '{{.Id}}' "$IMAGE" 2>/dev/null || echo "")
docker pull "$IMAGE"
NEW_ID=$(docker image inspect --format '{{.Id}}' "$IMAGE")

if [ "$OLD_ID" = "$NEW_ID" ]; then
  echo "No new image. Skipping restart."
  exit 0
fi

docker compose down
docker compose up -d
docker image prune -f
