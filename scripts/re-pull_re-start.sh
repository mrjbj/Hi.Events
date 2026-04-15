#!/bin/bash 
# pull fresh docker image, stop/start docker stack

'cd ~/elestio_app_directory/hi-events'
 
docker pull ghcr.io/mrjbj/hi-events-all-in-one:latest && docker compose down && docker compose up -d
