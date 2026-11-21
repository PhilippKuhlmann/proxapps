# Jellyfin – Docker Compose with the official image jellyfin/jellyfin.
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Env: MEDIA_PATH, PUBLIC_URL (optional), WEB_PORT, DOCKER_MTU (optional)

WEB_PORT="${WEB_PORT:-8096}"
MEDIA_PATH="${MEDIA_PATH:-/srv/media}"
DIR=/opt/jellyfin
TAG=12

[[ "$MEDIA_PATH" =~ ^/[A-Za-z0-9._/-]*$ && "$MEDIA_PATH" != *..* ]] || { echo "MEDIA_PATH muss ein absoluter Pfad sein" >&2; exit 1; }

if ! command -v docker >/dev/null 2>&1; then
  echo "==> Docker installieren (offizielle Paketquelle)"
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL --retry 3 https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  . /etc/os-release
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian ${VERSION_CODENAME} stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -q
  apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-compose-plugin
fi
if [[ -n "${DOCKER_MTU:-}" ]]; then
  echo "==> Docker-MTU ${DOCKER_MTU}"
  mkdir -p /etc/docker
  jq -n --argjson mtu "$DOCKER_MTU" \
    '{mtu: $mtu, "default-network-opts": {bridge: {"com.docker.network.driver.mtu": ($mtu|tostring)}}}' \
    > /etc/docker/daemon.json
fi
systemctl enable -q docker
systemctl restart docker

install -d -m 0750 "$DIR"
install -d -m 0755 "$DIR/config" "$DIR/cache"
# Usually a Proxmox mount point (mpX) added later; create the folder so Jellyfin starts anyway.
install -d -m 0755 "$MEDIA_PATH"

# Keep an image tag that update.sh may have raised.
if [[ -f "$DIR/proxapps.env" ]]; then
  old="$(sed -n 's/^JELLYFIN_TAG=//p' "$DIR/proxapps.env")"
  TAG="${old:-$TAG}"
fi
published="${PUBLIC_URL:-http://${PROXAPPS_IP}:${WEB_PORT}}"
printf 'JELLYFIN_TAG=%s\nWEB_PORT=%s\nMEDIA_PATH=%s\nPUBLISHED_URL=%s\n' \
  "$TAG" "$WEB_PORT" "$MEDIA_PATH" "${published%/}" > "$DIR/proxapps.env"

cat > "$DIR/docker-compose.yml" <<'YAML'
# Written by ProxApps (catalog jellyfin). Settings and metadata live in /opt/jellyfin.
name: jellyfin
services:
  jellyfin:
    image: docker.io/jellyfin/jellyfin:${JELLYFIN_TAG}
    container_name: jellyfin
    restart: unless-stopped
    environment:
      JELLYFIN_PublishedServerUrl: ${PUBLISHED_URL}
    ports:
      - "${WEB_PORT}:8096"
      - "7359:7359/udp"
    volumes:
      - /opt/jellyfin/config:/config
      - /opt/jellyfin/cache:/cache
      - ${MEDIA_PATH}:/media
      - /etc/localtime:/etc/localtime:ro
YAML

cd "$DIR"
echo "==> Lade das Image jellyfin:${TAG}"
docker compose --env-file proxapps.env pull -q
echo "==> Starte Jellyfin"
docker compose --env-file proxapps.env up -d

up() { [[ "$(curl -s --max-time 3 "http://127.0.0.1:${WEB_PORT}/health" || true)" == Healthy ]]; }
for _ in $(seq 1 100); do up && break; sleep 3; done
up || { echo "Jellyfin antwortet nicht" >&2; docker compose --env-file proxapps.env logs --tail 40 >&2; exit 1; }
echo "==> Jellyfin $(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' jellyfin) läuft"
