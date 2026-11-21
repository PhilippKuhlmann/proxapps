# RustDesk Server – follow the newest release and recreate hbbs/hbbr.
# Runs inside the container as root. Keys and database in /opt/rustdesk/data are kept, so the
# clients keep working with the same public key.

DIR=/opt/rustdesk
[[ -f "$DIR/docker-compose.yml" ]] || { echo "RustDesk ist nicht unter $DIR eingerichtet" >&2; exit 1; }
cd "$DIR"
. ./proxapps.env

version() { docker inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' hbbs 2>/dev/null || true; }

echo "==> Installiert: $(version)"
# The image tag is the major version; a new major release raises it.
latest="$(curl -fsSL --max-time 15 https://api.github.com/repos/rustdesk/rustdesk-server/releases/latest | jq -r '.tag_name // empty' || true)"
major="${latest#v}"; major="${major%%.*}"
if [[ "$major" =~ ^[0-9]+$ && "$RUSTDESK_TAG" =~ ^[0-9]+$ ]] && (( major > RUSTDESK_TAG )); then
  echo "==> Neue Hauptversion ${major}"
  sed -i "s/^RUSTDESK_TAG=.*/RUSTDESK_TAG=${major}/" proxapps.env
fi
docker compose --env-file proxapps.env pull -q
docker compose --env-file proxapps.env up -d
for port in 21115 21116 21117; do
  for _ in $(seq 1 30); do
    (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null && break
    sleep 2
  done
  (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null \
    || { echo "Port ${port} ist nach dem Update nicht offen" >&2; docker compose --env-file proxapps.env logs --tail 40 >&2; exit 1; }
done
docker image prune -f >/dev/null
echo "==> RustDesk Server läuft jetzt mit $(version)"
