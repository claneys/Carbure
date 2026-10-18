#!/bin/sh
# Starts the Carbure demonstration (docker-compose.yml next to this script) and updates
# its image when a new one is published on Docker Hub: the container is recreated only
# when the image (or the compose file) changed. Prints the administrator password, new
# at each (re)creation.
#   ./run.sh           start, or update if a new image exists
#   ./run.sh --reset   also recreate the container: fresh demonstration data
# Cron example (update every hour, fresh data every night):
#   0 * * * * /path/to/run.sh >> /var/log/carbure-demo.log 2>&1
#   0 4 * * * /path/to/run.sh --reset >> /var/log/carbure-demo.log 2>&1
# CARBURE_DEMO_PORT and CARBURE_DEMO_LANGUAGE are read from the environment or a .env
# file next to this script.
set -eu
cd "$(dirname "$0")"

log() { echo "$(date '+%F %T') Carbure demo: $*"; }

case "${1:-}" in
    '') recreate= ;;
    --reset) recreate=--force-recreate ;;
    *) echo "Usage: $0 [--reset]" >&2; exit 2 ;;
esac

# Container of the demo before the update (empty if not running)
before=$(docker compose ps -q carbure 2>/dev/null || true)

log "pulling the image"
docker compose pull --quiet carbure
docker compose up -d --remove-orphans $recreate
after=$(docker compose ps -q carbure)

if [ "$before" = "$after" ]; then
    log "up to date, still running"
    exit 0
fi

log "started with $(docker inspect -f '{{.Config.Image}} ({{.Image}})' "$after" | cut -c1-80)"
# Previous images of the demo, now untagged (other images are left alone)
docker image prune -f --filter 'label=org.opencontainers.image.title=Carbure (demo)' > /dev/null

# Administrator password, printed by the container once its data is loaded (Login: up
# to 1.6.0)
for i in $(seq 1 90); do
    line=$(docker logs "$after" 2>&1 | grep -E 'Administrator:|Login:' | tail -1 || true)
    if [ -n "$line" ]; then
        log "$(echo "$line" | sed 's/^ *//')"
        exit 0
    fi
    sleep 2
done
log "the container did not print its accounts: docker logs $after" >&2
exit 1
