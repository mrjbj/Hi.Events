#!/bin/bash
  # check-migrations.sh - Run on Elestio instance
  # Usage: ./check-migrations.sh [status|migrate]

 'cd ~/elestio_app_directory/hi-events'

  case "${1:-status}" in
    status)
      docker compose exec all-in-one php /app/backend/artisan migrate:status
      ;;
    migrate)
      docker compose exec all-in-one php /app/backend/artisan migrate
      ;;
    *)
      echo "Usage: $0 [status|migrate]"
      ;;
  esac

  Or as one-liners to copy-paste:

  # Check status
  # docker compose exec all-in-one php /app/backend/artisan migrate:status

  # Run migrations
  # docker compose exec all-in-one php /app/backend/artisan migrate
