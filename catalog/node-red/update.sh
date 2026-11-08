# Node-RED – pull the newest image and recreate the container if it changed.
# Runs inside the container as root. Flows, credentials and nodes in /opt/node-red/data are kept.

DIR=/opt/node-red
IMAGE=nodered/node-red:latest
[[ -x "$DIR/run.sh" ]] || { echo "Node-RED ist nicht unter $DIR eingerichtet" >&2; exit 1; }
. "$DIR/proxapps.env"

version() { docker exec node-red node -p "require('/usr/src/node-red/node_modules/node-red/package.json').version" 2>/dev/null || true; }

before="$(version)"
old="$(docker inspect --format '{{.Image}}' node-red 2>/dev/null || true)"
echo "==> Installiert: ${before:-unbekannt}"
docker pull -q "$IMAGE"
new="$(docker image inspect --format '{{.Id}}' "$IMAGE")"
if [[ -n "$old" && "$old" == "$new" ]] && docker ps --format '{{.Names}}' | grep -qx node-red; then
  echo "==> Node-RED ist bereits aktuell"
  exit 0
fi

echo "==> Container mit dem neuen Image neu erstellen"
"$DIR/run.sh"
for _ in $(seq 1 90); do
  curl -sf -o /dev/null --max-time 3 "http://127.0.0.1:${WEB_PORT}/" && break
  sleep 2
done
curl -sf -o /dev/null --max-time 5 "http://127.0.0.1:${WEB_PORT}/" \
  || { echo "Node-RED antwortet nach dem Update nicht" >&2; docker logs --tail 30 node-red >&2; exit 1; }
docker image prune -f >/dev/null
echo "==> Node-RED läuft jetzt mit $(version)"
