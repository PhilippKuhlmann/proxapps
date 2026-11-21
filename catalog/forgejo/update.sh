# Forgejo – follow the newest major release and recreate the container.
# Runs inside the container as root. Repositories, database and settings in /opt/forgejo/data are
# kept; Forgejo migrates the database itself on start.

DIR=/opt/forgejo
[[ -f "$DIR/docker-compose.yml" ]] || { echo "Forgejo ist nicht unter $DIR eingerichtet" >&2; exit 1; }
cd "$DIR"
. ./proxapps.env

version() { docker inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' forgejo 2>/dev/null || true; }
up() { curl -sf -o /dev/null --max-time 3 "http://127.0.0.1:${WEB_PORT}/api/healthz"; }

echo "==> Installiert: $(version)"
# The image tag is the major version; a new major release raises it.
latest="$(curl -fsSL --max-time 15 https://codeberg.org/api/v1/repos/forgejo/forgejo/releases/latest | jq -r '.tag_name // empty' || true)"
major="${latest#v}"; major="${major%%.*}"
if [[ "$major" =~ ^[0-9]+$ && "$FORGEJO_TAG" =~ ^[0-9]+$ ]] && (( major > FORGEJO_TAG )); then
  echo "==> Neue Hauptversion ${major}"
  sed -i "s/^FORGEJO_TAG=.*/FORGEJO_TAG=${major}/" proxapps.env
fi
docker compose --env-file proxapps.env pull -q
docker compose --env-file proxapps.env up -d
for _ in $(seq 1 100); do up && break; sleep 3; done
up || { echo "Forgejo antwortet nach dem Update nicht" >&2; docker compose --env-file proxapps.env logs --tail 40 >&2; exit 1; }
docker image prune -f >/dev/null
echo "==> Forgejo läuft jetzt mit $(version)"
