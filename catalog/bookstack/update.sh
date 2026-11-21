# BookStack – pull the newest images and recreate changed containers.
# Runs inside the container as root. Pages, uploads and the database in /opt/bookstack are kept;
# the image runs the database migrations itself on start.

DIR=/opt/bookstack
[[ -f "$DIR/docker-compose.yml" ]] || { echo "BookStack ist nicht unter $DIR eingerichtet" >&2; exit 1; }
cd "$DIR"
. ./proxapps.env

version() { docker inspect --format '{{index .Config.Labels "build_version"}}' bookstack 2>/dev/null | grep -oE 'v[0-9]+(\.[0-9]+)+' | head -n1 || true; }
up() { [[ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:${WEB_PORT}/login" || true)" == 200 ]]; }

echo "==> Installiert: $(version)"
docker compose --env-file secrets.env pull -q
docker compose --env-file secrets.env up -d
for _ in $(seq 1 200); do up && break; sleep 3; done
up || { echo "BookStack antwortet nach dem Update nicht" >&2; docker compose --env-file secrets.env logs --tail 40 bookstack >&2; exit 1; }
docker image prune -f >/dev/null
echo "==> BookStack läuft jetzt mit $(version)"
