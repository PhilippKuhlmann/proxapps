# Jellyfin – follow the newest major release and recreate the container.
# Runs inside the container as root. Settings, users and metadata in /opt/jellyfin are kept;
# the media folder is only mounted, never touched.

DIR=/opt/jellyfin
[[ -f "$DIR/docker-compose.yml" ]] || { echo "Jellyfin ist nicht unter $DIR eingerichtet" >&2; exit 1; }
cd "$DIR"
. ./proxapps.env

version() { docker inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' jellyfin 2>/dev/null || true; }
up() { [[ "$(curl -s --max-time 3 "http://127.0.0.1:${WEB_PORT}/health" || true)" == Healthy ]]; }

echo "==> Installiert: $(version)"
# The image tag is the major version; a new major release raises it.
latest="$(curl -fsSL --max-time 15 https://api.github.com/repos/jellyfin/jellyfin/releases/latest | jq -r '.tag_name // empty' || true)"
major="${latest#v}"; major="${major%%.*}"
if [[ "$major" =~ ^[0-9]+$ && "$JELLYFIN_TAG" =~ ^[0-9]+$ ]] && (( major > JELLYFIN_TAG )); then
  echo "==> Neue Hauptversion ${major}"
  sed -i "s/^JELLYFIN_TAG=.*/JELLYFIN_TAG=${major}/" proxapps.env
fi
docker compose --env-file proxapps.env pull -q
docker compose --env-file proxapps.env up -d
for _ in $(seq 1 100); do up && break; sleep 3; done
up || { echo "Jellyfin antwortet nach dem Update nicht" >&2; docker compose --env-file proxapps.env logs --tail 40 >&2; exit 1; }
docker image prune -f >/dev/null
echo "==> Jellyfin läuft jetzt mit $(version)"
