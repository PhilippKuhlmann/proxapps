# authentik – Docker Compose with server, worker and PostgreSQL (no Redis since 2025.10).
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Env: ADMIN_EMAIL, ADMIN_PASS, WEB_PORT, DOCKER_MTU (optional)
#      (PUBLIC_URL is only used by PK95 Core for the reverse proxy.)

WEB_PORT="${WEB_PORT:-9000}"
DIR=/opt/authentik
TAG=2026.8

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
install -d -m 0700 "$DIR/pgdata"
# The authentik images run as UID 1000.
install -d -m 0755 -o 1000 -g 1000 "$DIR/data" "$DIR/custom-templates" "$DIR/certs"

rand() { head -c 96 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

# Secrets are created once; a second run (re-deploy into the same container) keeps them.
if [[ ! -f "$DIR/secrets.env" ]]; then
  umask 077
  printf 'AUTHENTIK_SECRET_KEY=%s\nPG_PASS=%s\n' "$(rand 60)" "$(rand 32)" > "$DIR/secrets.env"
fi
# Keep an image tag that update.sh may have raised.
if [[ -f "$DIR/proxapps.env" ]]; then
  old="$(sed -n 's/^AUTHENTIK_TAG=//p' "$DIR/proxapps.env")"
  TAG="${old:-$TAG}"
fi
umask 077
printf 'AUTHENTIK_TAG=%s\nWEB_PORT=%s\n' "$TAG" "$WEB_PORT" > "$DIR/proxapps.env"
# .env is what docker compose reads for ${...} in the compose file.
cat "$DIR/proxapps.env" "$DIR/secrets.env" > "$DIR/.env"
# Only read on the very first start (creates the user akadmin); removed again below.
{
  echo "AUTHENTIK_BOOTSTRAP_EMAIL=${ADMIN_EMAIL}"
  echo "AUTHENTIK_BOOTSTRAP_PASSWORD=${ADMIN_PASS}"
} > "$DIR/bootstrap.env"

cat > "$DIR/docker-compose.yml" <<'YAML'
# Written by ProxApps (catalog authentik). Data lives in /opt/authentik.
name: authentik
x-authentik-env: &authentik-env
  AUTHENTIK_SECRET_KEY: ${AUTHENTIK_SECRET_KEY}
  AUTHENTIK_POSTGRESQL__HOST: postgresql
  AUTHENTIK_POSTGRESQL__NAME: authentik
  AUTHENTIK_POSTGRESQL__USER: authentik
  AUTHENTIK_POSTGRESQL__PASSWORD: ${PG_PASS}
  AUTHENTIK_ERROR_REPORTING__ENABLED: "false"
services:
  postgresql:
    image: docker.io/library/postgres:16-alpine
    container_name: authentik-db
    restart: unless-stopped
    environment:
      POSTGRES_DB: authentik
      POSTGRES_USER: authentik
      POSTGRES_PASSWORD: ${PG_PASS}
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -d authentik -U authentik"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 20s
    volumes: [/opt/authentik/pgdata:/var/lib/postgresql/data]
  server:
    image: ghcr.io/goauthentik/server:${AUTHENTIK_TAG}
    container_name: authentik
    command: server
    restart: unless-stopped
    shm_size: 512mb
    depends_on:
      postgresql: {condition: service_healthy}
    environment: *authentik-env
    env_file: [{path: bootstrap.env, required: false}]
    ports: ["${WEB_PORT}:9000"]
    volumes:
      - /opt/authentik/data:/data
      - /opt/authentik/custom-templates:/templates
  worker:
    image: ghcr.io/goauthentik/server:${AUTHENTIK_TAG}
    container_name: authentik-worker
    command: worker
    restart: unless-stopped
    shm_size: 512mb
    depends_on:
      postgresql: {condition: service_healthy}
    environment: *authentik-env
    env_file: [{path: bootstrap.env, required: false}]
    volumes:
      - /opt/authentik/data:/data
      - /opt/authentik/certs:/certs
      - /opt/authentik/custom-templates:/templates
YAML

cd "$DIR"
echo "==> Lade die Images (authentik ${TAG}, PostgreSQL)"
docker compose pull -q
echo "==> Starte authentik (der erste Start richtet die Datenbank ein und dauert einige Minuten)"
docker compose up -d

ready() { curl -sf -o /dev/null --max-time 3 "http://127.0.0.1:${WEB_PORT}/-/health/ready/"; }
for _ in $(seq 1 200); do ready && break; sleep 3; done
ready || { echo "authentik antwortet nicht" >&2; docker compose logs --tail 40 server worker >&2; exit 1; }

# The worker creates akadmin from the bootstrap values while applying the default blueprints.
has_admin() {
  docker exec authentik-worker ak shell -c \
    "import sys; from authentik.core.models import User; sys.exit(0 if User.objects.filter(username='akadmin').exists() else 1)" \
    >/dev/null 2>&1
}
for _ in $(seq 1 100); do has_admin && break; sleep 3; done
has_admin || { echo "Der Benutzer akadmin wurde nicht angelegt" >&2; docker compose logs --tail 40 worker >&2; exit 1; }

# The bootstrap password only matters for the first start; afterwards it is changed in authentik.
rm -f "$DIR/bootstrap.env"
docker compose up -d >/dev/null 2>&1
for _ in $(seq 1 100); do ready && break; sleep 3; done
echo "==> authentik $(docker inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' authentik) läuft"
