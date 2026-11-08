# Immich – compose bundle of the latest release, then pull and recreate.
# Runs inside the container as root. Photos, database and .env (password) in /opt/immich are
# kept; the server migrates the database itself on start. Read the release notes for major
# versions: they occasionally need manual steps.

DIR=/opt/immich
RELEASE=https://github.com/immich-app/immich/releases/latest/download
[[ -f "$DIR/docker-compose.yml" && -f "$DIR/.env" ]] || { echo "Immich ist nicht unter $DIR eingerichtet" >&2; exit 1; }
cd "$DIR"
. ./proxapps.env

version() { curl -sf --max-time 5 "http://127.0.0.1:${WEB_PORT}/api/server/version" | jq -r '"v\(.major).\(.minor).\(.patch)"' 2>/dev/null || true; }

echo "==> Installiert: $(version)"
curl -fsSL --retry 3 -o docker-compose.yml.new "$RELEASE/docker-compose.yml"
mv docker-compose.yml.new docker-compose.yml
docker compose pull -q
if [[ "${MACHINE_LEARNING:-1}" == 1 ]]; then
  docker compose up -d
else
  docker compose up -d --scale immich-machine-learning=0
fi
for _ in $(seq 1 300); do
  [[ "$(curl -sf --max-time 3 "http://127.0.0.1:${WEB_PORT}/api/server/ping" | jq -r '.res // empty' 2>/dev/null)" == pong ]] && break
  sleep 3
done
[[ "$(curl -sf --max-time 5 "http://127.0.0.1:${WEB_PORT}/api/server/ping" | jq -r '.res // empty' 2>/dev/null)" == pong ]] \
  || { echo "Immich antwortet nach dem Update nicht" >&2; docker compose logs --tail 40 immich-server >&2; exit 1; }
docker image prune -f >/dev/null
echo "==> Immich läuft jetzt mit $(version)"
