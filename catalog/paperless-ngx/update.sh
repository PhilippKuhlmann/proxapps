# Paperless-ngx – pull the newest images and recreate changed containers.
# Runs inside the container as root. Documents, database and index in /opt/paperless are kept;
# Paperless migrates the database itself on start.

DIR=/opt/paperless
[[ -f "$DIR/docker-compose.yml" ]] || { echo "Paperless ist nicht unter $DIR eingerichtet" >&2; exit 1; }
cd "$DIR"
. ./proxapps.env

version() { docker inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' paperless 2>/dev/null || true; }

echo "==> Installiert: $(version)"
docker compose --env-file secrets.env pull -q
docker compose --env-file secrets.env up -d
for _ in $(seq 1 240); do
  curl -sf -o /dev/null --max-time 3 "http://127.0.0.1:${WEB_PORT}/accounts/login/" && break
  sleep 3
done
curl -sf -o /dev/null --max-time 5 "http://127.0.0.1:${WEB_PORT}/accounts/login/" \
  || { echo "Paperless antwortet nach dem Update nicht" >&2; docker compose --env-file secrets.env logs --tail 40 webserver >&2; exit 1; }
docker image prune -f >/dev/null
echo "==> Paperless-ngx läuft jetzt mit $(version)"
