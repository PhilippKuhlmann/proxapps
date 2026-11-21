# Stirling-PDF – Docker Compose with the official "fat" image (all tools, OCR deu+eng).
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Env: LOGIN_ENABLED (1|0), ADMIN_USER, ADMIN_PASS, WEB_PORT, DOCKER_MTU (optional)
#      (PUBLIC_URL is only used by PK95 Core for the reverse proxy.)

WEB_PORT="${WEB_PORT:-8080}"
DIR=/opt/stirling-pdf
login=$([[ "${LOGIN_ENABLED:-1}" == 1 ]] && echo 1 || echo 0)

if [[ "$login" == 1 ]]; then
  [[ "$ADMIN_USER" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] || { echo "ADMIN_USER ist ungültig" >&2; exit 1; }
  (( ${#ADMIN_PASS} >= 12 )) || { echo "ADMIN_PASS muss mindestens 12 Zeichen lang sein" >&2; exit 1; }
fi

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
install -d -m 0755 -o 1000 -g 1000 "$DIR/configs" "$DIR/logs" "$DIR/customFiles" "$DIR/pipeline" "$DIR/storage"

umask 077
{
  echo "PUID=1000"
  echo "PGID=1000"
  echo "SYSTEM_DEFAULTLOCALE=de-DE"
  echo "LANGS=de_DE"
  echo "SYSTEM_ENABLEANALYTICS=false"
  echo "DISABLE_ADDITIONAL_FEATURES=false"
  echo "JAVA_CUSTOM_OPTS=-XX:MaxRAMPercentage=60"
  if [[ "$login" == 1 ]]; then
    echo "SECURITY_ENABLELOGIN=true"
    echo "SECURITY_LOGINMETHOD=normal"
    echo "SECURITY_INITIALLOGIN_USERNAME=${ADMIN_USER}"
    echo "SECURITY_INITIALLOGIN_PASSWORD=${ADMIN_PASS}"
  else
    echo "SECURITY_ENABLELOGIN=false"
  fi
} > "$DIR/stirling.env"
printf 'WEB_PORT=%s\nLOGIN_ENABLED=%s\n' "$WEB_PORT" "$login" > "$DIR/proxapps.env"

cat > "$DIR/docker-compose.yml" <<'YAML'
# Written by ProxApps (catalog stirling-pdf). Settings and stored files live in /opt/stirling-pdf.
name: stirling-pdf
services:
  stirling-pdf:
    image: docker.io/stirlingtools/stirling-pdf:latest-fat
    container_name: stirling-pdf
    restart: unless-stopped
    env_file: stirling.env
    ports: ["${WEB_PORT}:8080"]
    volumes:
      - /opt/stirling-pdf/configs:/configs
      - /opt/stirling-pdf/logs:/logs
      - /opt/stirling-pdf/customFiles:/customFiles
      - /opt/stirling-pdf/pipeline:/pipeline
      - /opt/stirling-pdf/storage:/storage
YAML

cd "$DIR"
echo "==> Lade das Image stirling-pdf:latest-fat (rund 2 GB)"
docker compose --env-file proxapps.env pull -q
echo "==> Starte Stirling-PDF"
docker compose --env-file proxapps.env up -d

up() { curl -sf --max-time 5 "http://127.0.0.1:${WEB_PORT}/api/v1/info/status" 2>/dev/null | grep -q UP; }
for _ in $(seq 1 120); do up && break; sleep 3; done
up || { echo "Stirling-PDF antwortet nicht" >&2; docker compose --env-file proxapps.env logs --tail 40 >&2; exit 1; }

# The initial password only matters for the first start; afterwards it is changed in Stirling-PDF.
sed -i '/^SECURITY_INITIALLOGIN_PASSWORD=/d' "$DIR/stirling.env"
if [[ "$login" == 1 ]]; then
  echo "PROXAPPS_VAR:LOGIN_USER=${ADMIN_USER}"
else
  echo "PROXAPPS_VAR:LOGIN_USER="
fi
echo "==> Stirling-PDF $(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' stirling-pdf) läuft"
