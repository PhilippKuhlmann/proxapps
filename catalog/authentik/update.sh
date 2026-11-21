# authentik – follow the newest release (YYYY.M image tag) and recreate the containers.
# Runs inside the container as root. Database and media in /opt/authentik are kept; authentik
# migrates the database itself on start.

DIR=/opt/authentik
[[ -f "$DIR/docker-compose.yml" ]] || { echo "authentik ist nicht unter $DIR eingerichtet" >&2; exit 1; }
cd "$DIR"
. ./proxapps.env

version() { docker inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' authentik 2>/dev/null || true; }
ready() { curl -sf -o /dev/null --max-time 3 "http://127.0.0.1:${WEB_PORT}/-/health/ready/"; }

echo "==> Installiert: $(version)"
# Release tags look like "version/2026.8.6"; the image tag is the release line (2026.8).
latest="$(curl -fsSL --max-time 15 https://api.github.com/repos/goauthentik/authentik/releases/latest | jq -r '.tag_name // empty' || true)"
line="$(sed -nE 's~^[^0-9]*([0-9]{4}\.[0-9]{1,2})\..*~\1~p' <<<"$latest")"
if [[ -n "$line" && "$line" != "$AUTHENTIK_TAG" ]] \
  && [[ "$(printf '%s\n%s\n' "$AUTHENTIK_TAG" "$line" | sort -V | tail -n1)" == "$line" ]]; then
  echo "==> Neue Release-Linie ${line}"
  sed -i "s/^AUTHENTIK_TAG=.*/AUTHENTIK_TAG=${line}/" proxapps.env
fi
cat proxapps.env secrets.env > .env
chmod 600 .env
docker compose pull -q
docker compose up -d
for _ in $(seq 1 200); do ready && break; sleep 3; done
ready || { echo "authentik antwortet nach dem Update nicht" >&2; docker compose logs --tail 40 server worker >&2; exit 1; }
docker image prune -f >/dev/null
echo "==> authentik läuft jetzt mit $(version)"
