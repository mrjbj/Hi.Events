#!/bin/bash 
cd /root/elestio_app_directory/hi-events/
docker compose exec -T postgres  sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"'  > "/root/elestio_app_directory/hi-events/backup/hievents-backup-$(date +%Y-%m-%d).sql" 2>backup/backup-errors.log



