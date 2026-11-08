# Node-RED – official Docker image nodered/node-red, editor protected by an admin account.
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Env: ADMIN_USER, ADMIN_PASS, WEB_PORT, DOCKER_MTU (optional)

WEB_PORT="${WEB_PORT:-1880}"
DIR=/opt/node-red
IMAGE=nodered/node-red:latest

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
  apt-get install -y -q docker-ce docker-ce-cli containerd.io
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

echo "==> Lade das Node-RED-Image"
docker pull -q "$IMAGE"

# The image runs as user node-red (uid 1000); flows and installed nodes live in /data.
install -d -m 0750 "$DIR"
install -d -m 0750 -o 1000 -g 1000 "$DIR/data"

echo "==> Admin-Konto für den Editor anlegen (bcrypt-Hash)"
hash="$(docker run --rm --entrypoint node -e PW="$ADMIN_PASS" "$IMAGE" \
  -e "process.stdout.write(require('/usr/src/node-red/node_modules/bcryptjs').hashSync(process.env.PW, 10))")"
[[ "$hash" == \$2* ]] || { echo "bcrypt-Hash konnte nicht erzeugt werden" >&2; exit 1; }
secret="$(openssl rand -hex 32 2>/dev/null || head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"

if [[ ! -f "$DIR/data/settings.js" ]]; then
  # Own minimal settings: admin login, encrypted credentials, everything else default.
  umask 027
  jq -n --arg user "$ADMIN_USER" --arg hash "$hash" --arg secret "$secret" \
    '{user: $user, hash: $hash, secret: $secret}' > "$DIR/proxapps.json"
  cat > "$DIR/data/settings.js" <<'JS'
// Written by ProxApps. Admin account and credential secret come from /data/proxapps.json.
const p = require('/data/proxapps.json');
module.exports = {
    flowFile: 'flows.json',
    credentialSecret: p.secret,
    uiPort: process.env.PORT || 1880,
    adminAuth: { type: 'credentials', users: [{ username: p.user, password: p.hash, permissions: '*' }] },
    diagnostics: { enabled: false },
    logging: { console: { level: 'info', metrics: false, audit: false } },
    editorTheme: { projects: { enabled: false } },
    functionExternalModules: true,
};
JS
  mv "$DIR/proxapps.json" "$DIR/data/proxapps.json"
  chown 1000:1000 "$DIR/data/settings.js" "$DIR/data/proxapps.json"
  chmod 0640 "$DIR/data/settings.js" "$DIR/data/proxapps.json"
else
  echo "==> settings.js existiert bereits, Admin-Konto bleibt unverändert"
fi
printf 'WEB_PORT=%s\n' "$WEB_PORT" > "$DIR/proxapps.env"

# (Re)create the container from the current image; used by the update script, too.
cat > "$DIR/run.sh" <<'RUN'
#!/bin/bash
set -euo pipefail
cd /opt/node-red
. ./proxapps.env
docker rm -f node-red >/dev/null 2>&1 || true
docker run -d --name node-red --restart unless-stopped -e TZ="$(cat /etc/timezone 2>/dev/null || echo UTC)" \
  -v /opt/node-red/data:/data -p "${WEB_PORT}:1880" nodered/node-red:latest >/dev/null
RUN
chmod 0700 "$DIR/run.sh"
"$DIR/run.sh"

echo "==> Container gestartet, warte auf den Editor"
for _ in $(seq 1 90); do
  curl -sf -o /dev/null --max-time 3 "http://127.0.0.1:${WEB_PORT}/" && break
  sleep 2
done
curl -sf -o /dev/null --max-time 5 "http://127.0.0.1:${WEB_PORT}/" \
  || { echo "Node-RED antwortet nicht" >&2; docker logs --tail 30 node-red >&2; exit 1; }
# The admin API must refuse requests without login.
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:${WEB_PORT}/flows")"
[[ "$code" == 401 ]] || { echo "Admin-Schutz greift nicht (HTTP ${code} für /flows)" >&2; exit 1; }
echo "==> Node-RED $(docker exec node-red node -p "require('/usr/src/node-red/node_modules/node-red/package.json').version") läuft, Editor ist geschützt"
