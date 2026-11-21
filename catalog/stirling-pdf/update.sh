# Stirling-PDF – pull the newest "fat" image and recreate the container.
# Runs inside the container as root. Settings, users and stored files in /opt/stirling-pdf are kept.

DIR=/opt/stirling-pdf
[[ -f "$DIR/docker-compose.yml" ]] || { echo "Stirling-PDF ist nicht unter $DIR eingerichtet" >&2; exit 1; }
cd "$DIR"
. ./proxapps.env

version() { docker inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' stirling-pdf 2>/dev/null || true; }
up() { curl -sf --max-time 5 "http://127.0.0.1:${WEB_PORT}/api/v1/info/status" 2>/dev/null | grep -q UP; }

echo "==> Installiert: $(version)"
docker compose --env-file proxapps.env pull -q
docker compose --env-file proxapps.env up -d
for _ in $(seq 1 120); do up && break; sleep 3; done
up || { echo "Stirling-PDF antwortet nach dem Update nicht" >&2; docker compose --env-file proxapps.env logs --tail 40 >&2; exit 1; }
docker image prune -f >/dev/null
echo "==> Stirling-PDF läuft jetzt mit $(version)"
