# Vaultwarden – pull the newest image and recreate the container if it changed.
# Runs inside the container as root. Data in /opt/vaultwarden/data is kept.

DIR=/opt/vaultwarden
IMAGE=vaultwarden/server:latest
[[ -x "$DIR/run.sh" ]] || { echo "Vaultwarden ist nicht unter $DIR eingerichtet" >&2; exit 1; }
. "$DIR/proxapps.env"

version() { docker exec vaultwarden /vaultwarden --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n1 || true; }

before="$(version)"
old="$(docker inspect --format '{{.Image}}' vaultwarden 2>/dev/null || true)"
echo "==> Installiert: ${before:-unbekannt}"
docker pull -q "$IMAGE"
new="$(docker image inspect --format '{{.Id}}' "$IMAGE")"
if [[ -n "$old" && "$old" == "$new" ]] && docker ps --format '{{.Names}}' | grep -qx vaultwarden; then
  echo "==> Vaultwarden ist bereits aktuell"
  exit 0
fi

echo "==> Container mit dem neuen Image neu erstellen"
"$DIR/run.sh"
for _ in $(seq 1 60); do
  curl -ksf -o /dev/null --max-time 3 "${SCHEME}://127.0.0.1:${WEB_PORT}/alive" && break
  sleep 2
done
curl -ksf -o /dev/null --max-time 5 "${SCHEME}://127.0.0.1:${WEB_PORT}/alive" \
  || { echo "Vaultwarden antwortet nach dem Update nicht" >&2; docker logs --tail 30 vaultwarden >&2; exit 1; }
docker image prune -f >/dev/null
echo "==> Vaultwarden läuft jetzt mit $(version)"
