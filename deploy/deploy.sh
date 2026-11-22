#!/usr/bin/env bash
# Runs on the VPS, in the clone of the repository (GitHub Actions calls it over SSH):
# updates main, rebuilds the image and restarts the app.
set -euo pipefail

cd "$(dirname "$0")/.."

if [[ ! -f .env.prod.local ]]; then
    echo "Missing .env.prod.local: create it from deploy/env.prod.local.dist." >&2
    exit 1
fi

git fetch --quiet origin main
git reset --hard origin/main

compose=(docker compose --env-file .env.prod.local -f compose.prod.yaml)
"${compose[@]}" build --pull
"${compose[@]}" up -d --remove-orphans
docker image prune -f >/dev/null

# The schema of the dossiers, as the migrations say, as the user the app runs as.
"${compose[@]}" exec -T app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# The app answers once it is up (and Caddy has its certificate): the login page.
server_name=$(grep -E '^SERVER_NAME=' .env.prod.local | cut -d= -f2- | tr -d "'\"")
for _ in $(seq 1 60); do
    if curl -fsS -o /dev/null "https://${server_name}/login"; then
        echo "Deployed $(git rev-parse --short HEAD) on https://${server_name}."
        exit 0
    fi
    sleep 2
done

echo "https://${server_name}/login does not answer: ${compose[*]} logs app" >&2
exit 1
