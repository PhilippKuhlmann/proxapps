# BookStack – Docker Compose with the linuxserver.io image and MariaDB, optional LDAP (AD).
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Env: APP_URL, WEB_PORT, LDAP_ENABLED (1|0), LDAP_SERVER, LDAP_BASE_DN, LDAP_BIND_DN,
#      LDAP_BIND_PASS, LDAP_USER_FILTER, DOCKER_MTU (optional)

WEB_PORT="${WEB_PORT:-6875}"
DIR=/opt/bookstack
APP_URL="${APP_URL:-http://${PROXAPPS_IP}:${WEB_PORT}}"
APP_URL="${APP_URL%/}"

[[ "$APP_URL" =~ ^https?://[A-Za-z0-9.-]+(:[0-9]+)?(/.*)?$ ]] || { echo "APP_URL ist keine gültige Adresse" >&2; exit 1; }
ldap=$([[ "${LDAP_ENABLED:-0}" == 1 ]] && echo 1 || echo 0)
if [[ "$ldap" == 1 ]]; then
  for v in LDAP_SERVER LDAP_BASE_DN; do
    [[ -n "${!v:-}" ]] || { echo "Für LDAP fehlt ${v}" >&2; exit 1; }
  done
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
install -d -m 0755 -o 1000 -g 1000 "$DIR/config"
install -d -m 0700 "$DIR/db"

rand() { head -c 64 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

# Secrets are created once; a second run (re-deploy into the same container) keeps them.
if [[ ! -f "$DIR/secrets.env" ]]; then
  umask 077
  printf 'APP_KEY=base64:%s\nDB_PASS=%s\nDB_ROOT_PASS=%s\n' \
    "$(head -c 32 /dev/urandom | base64)" "$(rand 32)" "$(rand 32)" > "$DIR/secrets.env"
fi
. "$DIR/secrets.env"

umask 077
{
  echo "PUID=1000"
  echo "PGID=1000"
  echo "TZ=$(cat /etc/timezone 2>/dev/null || echo Etc/UTC)"
  echo "APP_URL=${APP_URL}"
  echo "APP_KEY=${APP_KEY}"
  echo "APP_LANG=de"
  echo "DB_HOST=db"
  echo "DB_PORT=3306"
  echo "DB_DATABASE=bookstack"
  echo "DB_USERNAME=bookstack"
  echo "DB_PASSWORD=${DB_PASS}"
  if [[ "$ldap" == 1 ]]; then
    echo "AUTH_METHOD=ldap"
    echo "LDAP_SERVER=${LDAP_SERVER}"
    echo "LDAP_BASE_DN=${LDAP_BASE_DN}"
    echo "LDAP_DN=${LDAP_BIND_DN:-false}"
    echo "LDAP_PASS=${LDAP_BIND_PASS:-false}"
    echo "LDAP_USER_FILTER=${LDAP_USER_FILTER:-(&(sAMAccountName={user}))}"
    echo "LDAP_VERSION=3"
    echo "LDAP_ID_ATTRIBUTE=BIN;objectGUID"
    echo "LDAP_EMAIL_ATTRIBUTE=mail"
    echo "LDAP_DISPLAY_NAME_ATTRIBUTE=displayName"
    echo "LDAP_FOLLOW_REFERRALS=false"
    # AD groups are matched to BookStack roles by name (group "Admin" -> role "Admin").
    echo "LDAP_USER_TO_GROUPS=true"
    echo "LDAP_GROUP_ATTRIBUTE=memberOf"
    echo "LDAP_REMOVE_FROM_GROUPS=false"
  fi
} > "$DIR/bookstack.env"
printf 'WEB_PORT=%s\nLDAP_ENABLED=%s\n' "$WEB_PORT" "$ldap" > "$DIR/proxapps.env"

cat > "$DIR/docker-compose.yml" <<YAML
# Written by ProxApps (catalog bookstack). Data lives in /opt/bookstack.
name: bookstack
services:
  db:
    image: docker.io/library/mariadb:11.4
    container_name: bookstack-db
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: \${DB_ROOT_PASS}
      MARIADB_DATABASE: bookstack
      MARIADB_USER: bookstack
      MARIADB_PASSWORD: \${DB_PASS}
      MARIADB_AUTO_UPGRADE: "1"
    healthcheck:
      # TCP ping as the app user: only the final server listens on TCP (not the init server).
      test: ["CMD-SHELL", "mariadb-admin ping -h 127.0.0.1 -u bookstack --password=\$\$MARIADB_PASSWORD --silent"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 30s
    volumes: [/opt/bookstack/db:/var/lib/mysql]
  bookstack:
    image: lscr.io/linuxserver/bookstack:latest
    container_name: bookstack
    restart: unless-stopped
    depends_on:
      db: {condition: service_healthy}
    env_file: bookstack.env
    ports: ["${WEB_PORT}:80"]
    volumes: [/opt/bookstack/config:/config]
YAML

cd "$DIR"
echo "==> Lade die Images (BookStack, MariaDB)"
docker compose --env-file secrets.env pull -q
echo "==> Starte BookStack (der erste Start richtet die Datenbank ein)"
docker compose --env-file secrets.env up -d

up() { [[ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:${WEB_PORT}/login" || true)" == 200 ]]; }
for _ in $(seq 1 200); do up && break; sleep 3; done
up || { echo "BookStack antwortet nicht" >&2; docker compose --env-file secrets.env logs --tail 40 bookstack >&2; exit 1; }

echo "PROXAPPS_VAR:ACCESS_URL=${APP_URL}/"
if [[ "$ldap" == 1 ]]; then
  echo "PROXAPPS_VAR:LOGIN_HINT=Anmeldung mit deinem AD-Konto. Mitglieder der AD-Gruppe „Admin“ werden BookStack-Admins; weitere Gruppen ordnest du in den Rollen unter „Externe Authentifizierungs-IDs“ zu."
else
  echo "PROXAPPS_VAR:LOGIN_HINT=Erste Anmeldung mit admin@admin.com und dem Passwort „password“ – ändere beides sofort unter „Mein Konto“."
fi
echo "==> BookStack $(docker inspect --format '{{index .Config.Labels "build_version"}}' bookstack | grep -oE 'v[0-9]+(\.[0-9]+)+' | head -n1) läuft"
