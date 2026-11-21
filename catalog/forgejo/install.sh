# Forgejo – Docker Compose with the official image (SQLite), Git over HTTP and SSH.
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Env: ROOT_URL, WEB_PORT, SSH_PORT, ADMIN_USER, ADMIN_PASS, ADMIN_EMAIL, REGISTRATION (1|0),
#      DOCKER_MTU (optional)

WEB_PORT="${WEB_PORT:-3000}"
SSH_PORT="${SSH_PORT:-2222}"
DIR=/opt/forgejo
TAG=17
ROOT_URL="${ROOT_URL:-http://${PROXAPPS_IP}:${WEB_PORT}}"
ROOT_URL="${ROOT_URL%/}/"

[[ "$ROOT_URL" =~ ^https?://([A-Za-z0-9.-]+)(:[0-9]+)?(/.*)?$ ]] || { echo "ROOT_URL ist keine gültige Adresse" >&2; exit 1; }
domain="${BASH_REMATCH[1]}"
[[ "$ADMIN_USER" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{1,39}$ && "${ADMIN_USER,,}" != admin ]] \
  || { echo "ADMIN_USER ist ungültig („admin“ ist in Forgejo reserviert)" >&2; exit 1; }
(( ${#ADMIN_PASS} >= 12 )) || { echo "ADMIN_PASS muss mindestens 12 Zeichen lang sein" >&2; exit 1; }
[[ "$ADMIN_EMAIL" == *@*.* ]] || { echo "ADMIN_EMAIL ist keine E-Mail-Adresse" >&2; exit 1; }

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
install -d -m 0755 -o 1000 -g 1000 "$DIR/data"

rand() { head -c 96 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

# Secrets are created once; a second run (re-deploy into the same container) keeps them.
if [[ ! -f "$DIR/secrets.env" ]]; then
  umask 077
  printf 'FORGEJO__security__SECRET_KEY=%s\nFORGEJO__security__INTERNAL_TOKEN=%s\n' "$(rand 64)" "$(rand 96)" > "$DIR/secrets.env"
fi
# Keep an image tag that update.sh may have raised.
if [[ -f "$DIR/proxapps.env" ]]; then
  old="$(sed -n 's/^FORGEJO_TAG=//p' "$DIR/proxapps.env")"
  TAG="${old:-$TAG}"
fi
registration=$([[ "${REGISTRATION:-0}" == 1 ]] && echo false || echo true)

umask 077
{
  echo "USER_UID=1000"
  echo "USER_GID=1000"
  echo "FORGEJO__database__DB_TYPE=sqlite3"
  echo "FORGEJO__server__DOMAIN=${domain}"
  echo "FORGEJO__server__SSH_DOMAIN=${domain}"
  echo "FORGEJO__server__ROOT_URL=${ROOT_URL}"
  echo "FORGEJO__server__HTTP_PORT=3000"
  echo "FORGEJO__server__SSH_PORT=${SSH_PORT}"
  echo "FORGEJO__server__SSH_LISTEN_PORT=22"
  echo "FORGEJO__security__INSTALL_LOCK=true"
  echo "FORGEJO__service__DISABLE_REGISTRATION=${registration}"
  echo "FORGEJO__log__LEVEL=Warn"
} > "$DIR/forgejo.env"
printf 'FORGEJO_TAG=%s\nWEB_PORT=%s\nSSH_PORT=%s\n' "$TAG" "$WEB_PORT" "$SSH_PORT" > "$DIR/proxapps.env"

cat > "$DIR/docker-compose.yml" <<'YAML'
# Written by ProxApps (catalog forgejo). Repositories, database and settings live in /opt/forgejo/data.
name: forgejo
services:
  forgejo:
    image: codeberg.org/forgejo/forgejo:${FORGEJO_TAG}
    container_name: forgejo
    restart: unless-stopped
    env_file: [forgejo.env, secrets.env]
    ports:
      - "${WEB_PORT}:3000"
      - "${SSH_PORT}:22"
    volumes:
      - /opt/forgejo/data:/data
      - /etc/localtime:/etc/localtime:ro
YAML

cd "$DIR"
echo "==> Lade das Image forgejo:${TAG}"
docker compose --env-file proxapps.env pull -q
echo "==> Starte Forgejo"
docker compose --env-file proxapps.env up -d

up() { curl -sf -o /dev/null --max-time 3 "http://127.0.0.1:${WEB_PORT}/api/healthz"; }
for _ in $(seq 1 100); do up && break; sleep 3; done
up || { echo "Forgejo antwortet nicht" >&2; docker compose --env-file proxapps.env logs --tail 40 >&2; exit 1; }

if docker exec -u git forgejo forgejo admin user list --admin 2>/dev/null | awk -v u="$ADMIN_USER" 'NR>1 && $2 == u {f=1} END {exit !f}'; then
  echo "==> Admin ${ADMIN_USER} gibt es schon"
else
  echo "==> Lege den Admin ${ADMIN_USER} an"
  docker exec -u git forgejo forgejo admin user create --admin --username "$ADMIN_USER" \
    --password "$ADMIN_PASS" --email "$ADMIN_EMAIL" --must-change-password=false >/dev/null
fi
echo "PROXAPPS_VAR:ACCESS_URL=${ROOT_URL}"
echo "==> Forgejo $(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' forgejo) läuft"
