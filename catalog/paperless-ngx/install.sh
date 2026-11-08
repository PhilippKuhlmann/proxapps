# Paperless-ngx – Docker Compose with PostgreSQL and Redis (optional Tika + Gotenberg).
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Env: ADMIN_USER, ADMIN_PASS, OCR_LANGUAGE, OFFICE (1|0), PAPERLESS_URL (optional), WEB_PORT,
#      DOCKER_MTU (optional)

WEB_PORT="${WEB_PORT:-8000}"
DIR=/opt/paperless

(( ${#ADMIN_PASS} >= 10 )) || { echo "ADMIN_PASS muss mindestens 10 Zeichen lang sein" >&2; exit 1; }

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
install -d -m 0755 -o 1000 -g 1000 "$DIR/data" "$DIR/media" "$DIR/consume" "$DIR/export"
install -d -m 0700 "$DIR/pgdata"

rand() { head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

# Secrets are created once; a second run (re-deploy into the same container) keeps them.
if [[ ! -f "$DIR/secrets.env" ]]; then
  umask 077
  printf 'PAPERLESS_SECRET_KEY=%s\nPOSTGRES_PASSWORD=%s\n' "$(rand 50)" "$(rand 32)" > "$DIR/secrets.env"
fi
. "$DIR/secrets.env"

hosts="${PROXAPPS_IP},${PROXAPPS_HOSTNAME},localhost,127.0.0.1"
origins="http://${PROXAPPS_IP}:${WEB_PORT}"
if [[ -n "${PAPERLESS_URL:-}" ]]; then
  url_host="${PAPERLESS_URL#*://}"; url_host="${url_host%%[:/]*}"
  hosts="${hosts},${url_host}"
  origins="${origins},${PAPERLESS_URL%/}"
fi
office=$([[ "${OFFICE:-0}" == 1 ]] && echo 1 || echo 0)

umask 077
{
  echo "PAPERLESS_SECRET_KEY=${PAPERLESS_SECRET_KEY}"
  echo "PAPERLESS_DBHOST=db"
  echo "PAPERLESS_DBPASS=${POSTGRES_PASSWORD}"
  echo "PAPERLESS_REDIS=redis://redis:6379"
  echo "PAPERLESS_ADMIN_USER=${ADMIN_USER}"
  echo "PAPERLESS_ADMIN_PASSWORD=${ADMIN_PASS}"
  echo "PAPERLESS_OCR_LANGUAGE=${OCR_LANGUAGE:-deu+eng}"
  echo "PAPERLESS_TIME_ZONE=$(cat /etc/timezone 2>/dev/null || echo UTC)"
  echo "PAPERLESS_ALLOWED_HOSTS=${hosts}"
  echo "PAPERLESS_CSRF_TRUSTED_ORIGINS=${origins}"
  [[ -n "${PAPERLESS_URL:-}" ]] && echo "PAPERLESS_URL=${PAPERLESS_URL%/}"
  echo "USERMAP_UID=1000"
  echo "USERMAP_GID=1000"
  if [[ "$office" == 1 ]]; then
    echo "PAPERLESS_TIKA_ENABLED=1"
    echo "PAPERLESS_TIKA_ENDPOINT=http://tika:9998"
    echo "PAPERLESS_TIKA_GOTENBERG_ENDPOINT=http://gotenberg:3000"
  fi
} > "$DIR/paperless.env"
printf 'WEB_PORT=%s\nOFFICE=%s\n' "$WEB_PORT" "$office" > "$DIR/proxapps.env"

cat > "$DIR/docker-compose.yml" <<YAML
# Written by ProxApps (catalog paperless-ngx). Data lives in /opt/paperless.
name: paperless
services:
  redis:
    image: docker.io/library/redis:8
    container_name: paperless-redis
    restart: unless-stopped
    volumes: [redisdata:/data]
  db:
    image: docker.io/library/postgres:17
    container_name: paperless-db
    restart: unless-stopped
    volumes: [/opt/paperless/pgdata:/var/lib/postgresql/data]
    environment:
      POSTGRES_DB: paperless
      POSTGRES_USER: paperless
      POSTGRES_PASSWORD: \${POSTGRES_PASSWORD}
  webserver:
    image: ghcr.io/paperless-ngx/paperless-ngx:latest
    container_name: paperless
    restart: unless-stopped
    depends_on: [db, redis]
    ports: ["${WEB_PORT}:8000"]
    env_file: paperless.env
    volumes:
      - /opt/paperless/data:/usr/src/paperless/data
      - /opt/paperless/media:/usr/src/paperless/media
      - /opt/paperless/export:/usr/src/paperless/export
      - /opt/paperless/consume:/usr/src/paperless/consume
YAML
if [[ "$office" == 1 ]]; then
  cat >> "$DIR/docker-compose.yml" <<'YAML'
  gotenberg:
    image: docker.io/gotenberg/gotenberg:8
    container_name: paperless-gotenberg
    restart: unless-stopped
    command: ["gotenberg", "--chromium-disable-javascript=true", "--chromium-allow-list=file:///tmp/.*"]
  tika:
    image: docker.io/apache/tika:latest
    container_name: paperless-tika
    restart: unless-stopped
YAML
fi
cat >> "$DIR/docker-compose.yml" <<'YAML'
volumes:
  redisdata:
YAML

cd "$DIR"
echo "==> Lade die Images (Paperless, PostgreSQL, Redis$([[ "$office" == 1 ]] && echo ', Tika, Gotenberg'))"
docker compose --env-file secrets.env pull -q
echo "==> Starte Paperless (der erste Start richtet die Datenbank ein und dauert einige Minuten)"
docker compose --env-file secrets.env up -d

for _ in $(seq 1 240); do
  curl -sf -o /dev/null --max-time 3 "http://127.0.0.1:${WEB_PORT}/accounts/login/" && break
  sleep 3
done
curl -sf -o /dev/null --max-time 5 "http://127.0.0.1:${WEB_PORT}/accounts/login/" \
  || { echo "Paperless antwortet nicht" >&2; docker compose --env-file secrets.env logs --tail 40 webserver >&2; exit 1; }

# The admin password only matters for the first start; afterwards it is changed in Paperless.
sed -i '/^PAPERLESS_ADMIN_PASSWORD=/d' "$DIR/paperless.env"
echo "==> Paperless-ngx $(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' paperless) läuft"
