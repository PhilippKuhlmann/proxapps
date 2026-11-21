# RustDesk Server OSS – hbbs (ID/rendezvous) + hbbr (relay) per Docker Compose, host network.
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Env: RELAY_HOST (optional)

DIR=/opt/rustdesk
TAG=1

if [[ -n "${RELAY_HOST:-}" && ! "$RELAY_HOST" =~ ^[A-Za-z0-9.-]+(:[0-9]+)?$ ]]; then
  echo "RELAY_HOST ist kein gültiger Hostname" >&2; exit 1
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
systemctl enable -q docker
systemctl restart docker

install -d -m 0750 "$DIR"
install -d -m 0700 "$DIR/data"

# Address the clients enter as "ID server" and "relay server".
server="${RELAY_HOST:-${PROXAPPS_IP}}"
# An existing proxapps.env (re-deploy) keeps the image tag that update.sh may have raised.
if [[ -f "$DIR/proxapps.env" ]]; then
  TAG="$(sed -n 's/^RUSTDESK_TAG=//p' "$DIR/proxapps.env")"
  TAG="${TAG:-1}"
fi
printf 'RUSTDESK_TAG=%s\nRELAY_HOST=%s\n' "$TAG" "$server" > "$DIR/proxapps.env"

cat > "$DIR/docker-compose.yml" <<'YAML'
# Written by ProxApps (catalog rustdesk-server). Keys and database live in /opt/rustdesk/data.
name: rustdesk
services:
  hbbs:
    image: docker.io/rustdesk/rustdesk-server:${RUSTDESK_TAG}
    container_name: hbbs
    command: ["hbbs", "-r", "${RELAY_HOST}"]
    network_mode: host
    restart: unless-stopped
    depends_on: [hbbr]
    volumes: [/opt/rustdesk/data:/root]
  hbbr:
    image: docker.io/rustdesk/rustdesk-server:${RUSTDESK_TAG}
    container_name: hbbr
    command: ["hbbr"]
    network_mode: host
    restart: unless-stopped
    volumes: [/opt/rustdesk/data:/root]
YAML

cd "$DIR"
echo "==> Lade das Image rustdesk/rustdesk-server:${TAG}"
docker compose --env-file proxapps.env pull -q
echo "==> Starte hbbs und hbbr"
docker compose --env-file proxapps.env up -d

# hbbs creates the key pair on its first start.
for _ in $(seq 1 60); do
  [[ -s "$DIR/data/id_ed25519.pub" ]] && break
  sleep 2
done
[[ -s "$DIR/data/id_ed25519.pub" ]] \
  || { echo "hbbs hat keinen Schlüssel erzeugt" >&2; docker compose --env-file proxapps.env logs --tail 40 >&2; exit 1; }
for port in 21115 21116 21117; do
  for _ in $(seq 1 30); do
    (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null && break
    sleep 2
  done
  (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null \
    || { echo "Port ${port} ist nicht offen" >&2; docker compose --env-file proxapps.env logs --tail 40 >&2; exit 1; }
done

# The public key is not secret: every client needs it.
echo "PROXAPPS_VAR:SERVER_ADDRESS=${server}"
echo "PROXAPPS_VAR:PUBLIC_KEY=$(tr -d '\n' < "$DIR/data/id_ed25519.pub")"
echo "==> RustDesk Server läuft (ID-Server und Relay: ${server})"
