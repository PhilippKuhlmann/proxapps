# Immich – official Docker Compose bundle of the latest release, admin account via the API.
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Env: ADMIN_EMAIL, ADMIN_PASS, MACHINE_LEARNING (1|0), WEB_PORT, DOCKER_MTU (optional)

WEB_PORT="${WEB_PORT:-2283}"
DIR=/opt/immich
RELEASE=https://github.com/immich-app/immich/releases/latest/download

(( ${#ADMIN_PASS} >= 10 )) || { echo "ADMIN_PASS muss mindestens 10 Zeichen lang sein" >&2; exit 1; }
case "$(uname -m)" in
  x86_64|aarch64) ;;
  *) echo "Immich unterstützt nur x86_64 und aarch64, nicht $(uname -m)" >&2; exit 1 ;;
esac

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

install -d -m 0750 "$DIR" "$DIR/library" "$DIR/postgres"
cd "$DIR"

echo "==> Lade das Compose-Paket des neuesten Immich-Release"
curl -fsSL --retry 3 -o docker-compose.yml "$RELEASE/docker-compose.yml"
[[ -f .env ]] || curl -fsSL --retry 3 -o .env "$RELEASE/example.env"

# Configure .env (kept on re-runs: the database password must not change).
setenv() { if grep -qE "^#? *$1=" .env; then sed -i -E "s|^#? *$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi; }
setenv UPLOAD_LOCATION /opt/immich/library
setenv DB_DATA_LOCATION /opt/immich/postgres
setenv TZ "$(cat /etc/timezone 2>/dev/null || echo UTC)"
if grep -qE '^DB_PASSWORD=postgres$' .env || ! grep -q '^DB_PASSWORD=' .env; then
  setenv DB_PASSWORD "$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)"
fi
chmod 0600 .env

# Published port: the bundle maps 2283:2283; put our port in an override (survives updates).
ml=$([[ "${MACHINE_LEARNING:-1}" == 1 ]] && echo 1 || echo 0)
{
  echo "# Written by ProxApps: port and machine learning switch (docker-compose.yml stays original)."
  echo "services:"
  echo "  immich-server:"
  echo "    ports: !override"
  echo "      - '${WEB_PORT}:2283'"
} > docker-compose.override.yml
printf 'WEB_PORT=%s\nMACHINE_LEARNING=%s\n' "$WEB_PORT" "$ml" > proxapps.env

up() {
  if [[ "$ml" == 1 ]]; then
    docker compose up -d
  else
    docker compose up -d --scale immich-machine-learning=0
  fi
}

echo "==> Lade die Images (das dauert beim ersten Mal einige Minuten)"
docker compose pull -q
echo "==> Starte Immich"
up

for _ in $(seq 1 300); do
  [[ "$(curl -sf --max-time 3 "http://127.0.0.1:${WEB_PORT}/api/server/ping" | jq -r '.res // empty' 2>/dev/null)" == pong ]] && break
  sleep 3
done
[[ "$(curl -sf --max-time 5 "http://127.0.0.1:${WEB_PORT}/api/server/ping" | jq -r '.res // empty' 2>/dev/null)" == pong ]] \
  || { echo "Immich antwortet nicht" >&2; docker compose logs --tail 40 immich-server >&2; exit 1; }

echo "==> Admin-Konto anlegen"
body="$(jq -n --arg email "$ADMIN_EMAIL" --arg password "$ADMIN_PASS" '{email: $email, password: $password, name: "Admin"}')"
code="$(curl -s -o /tmp/immich-signup.json -w '%{http_code}' --max-time 20 -H 'Content-Type: application/json' \
  -d "$body" "http://127.0.0.1:${WEB_PORT}/api/auth/admin-sign-up")"
case "$code" in
  201|200) echo "==> Admin ${ADMIN_EMAIL} angelegt" ;;
  400) echo "==> Es gibt schon einen Administrator, Konto bleibt unverändert" ;;
  *) echo "Admin-Konto konnte nicht angelegt werden (HTTP ${code}): $(cat /tmp/immich-signup.json)" >&2; rm -f /tmp/immich-signup.json; exit 1 ;;
esac
rm -f /tmp/immich-signup.json

v="$(curl -sf "http://127.0.0.1:${WEB_PORT}/api/server/version" | jq -r '"v\(.major).\(.minor).\(.patch)"')"
echo "==> Immich ${v} läuft$([[ "$ml" == 1 ]] || echo ' (ohne Machine Learning)')"
