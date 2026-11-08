# Nextcloud All-in-One – installs Docker and starts the AIO mastercontainer.
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Env: AIO_MODE (reverse_proxy|direct), APACHE_PORT, NEXTCLOUD_UPLOAD_LIMIT, NEXTCLOUD_MEMORY_LIMIT,
#      NEXTCLOUD_MAX_TIME, DOCKER_MTU (optional)

AIO_MODE="${AIO_MODE:-reverse_proxy}"
APACHE_PORT="${APACHE_PORT:-11000}"
IMAGE=ghcr.io/nextcloud-releases/all-in-one:latest
MASTER=nextcloud-aio-mastercontainer
CONFIG=/var/lib/docker/volumes/nextcloud_aio_mastercontainer/_data/data/configuration.json

case "$(uname -m)" in
  x86_64|aarch64) ;;
  *) echo "Nextcloud AIO unterstützt nur x86_64 und aarch64, nicht $(uname -m)" >&2; exit 1 ;;
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
  apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

if [[ -n "${DOCKER_MTU:-}" ]]; then
  echo "==> Docker-MTU ${DOCKER_MTU} (Standard-Bridge und alle neuen Netzwerke)"
  mkdir -p /etc/docker
  jq -n --argjson mtu "$DOCKER_MTU" \
    '{mtu: $mtu, "default-network-opts": {bridge: {"com.docker.network.driver.mtu": ($mtu|tostring)}}}' \
    > /etc/docker/daemon.json
fi
systemctl enable -q docker
systemctl restart docker
echo "==> Docker $(docker version --format '{{.Server.Version}}') läuft"

if docker ps -a --format '{{.Names}}' | grep -qx "$MASTER"; then
  echo "==> Mastercontainer existiert bereits"
  docker start "$MASTER" >/dev/null
else
  echo "==> Lade das AIO-Image"
  docker pull -q "$IMAGE"
  args=(--init --sig-proxy=false --name "$MASTER" --restart always
        --publish 8080:8080
        --env "NEXTCLOUD_UPLOAD_LIMIT=${NEXTCLOUD_UPLOAD_LIMIT:-16G}"
        --env "NEXTCLOUD_MEMORY_LIMIT=${NEXTCLOUD_MEMORY_LIMIT:-1024M}"
        --env "NEXTCLOUD_MAX_TIME=${NEXTCLOUD_MAX_TIME:-3600}"
        --volume nextcloud_aio_mastercontainer:/mnt/docker-aio-config
        --volume /var/run/docker.sock:/var/run/docker.sock:ro)
  if [[ "$AIO_MODE" == "reverse_proxy" ]]; then
    echo "==> Reverse-Proxy-Modus: Nextcloud lauscht später auf Port ${APACHE_PORT}"
    args+=(--env "APACHE_PORT=${APACHE_PORT}" --env APACHE_IP_BINDING=0.0.0.0
           --env APACHE_ADDITIONAL_NETWORK= --env SKIP_DOMAIN_VALIDATION=false)
  else
    echo "==> Direkter Modus: Ports 80, 443 und 8443 werden von AIO verwendet"
    args+=(--publish 80:80 --publish 8443:8443)
  fi
  docker run -d "${args[@]}" "$IMAGE" >/dev/null
  echo "==> Mastercontainer gestartet"
fi

# The AIO interface only answers requests that look like a browser page navigation
# (Fetch Metadata headers); anything else gets "403 Forbidden".
BROWSER=(-H 'Sec-Fetch-Mode: navigate' -H 'Sec-Fetch-Dest: document' -H 'Sec-Fetch-Site: none')

echo "==> Warte auf die AIO-Oberfläche (Port 8080)"
code=000
for _ in $(seq 1 150); do
  code="$(curl -ks "${BROWSER[@]}" -o /dev/null -w '%{http_code}' --max-time 3 https://127.0.0.1:8080/ || true)"
  [[ "$code" == 200 || "$code" == 302 ]] && break
  sleep 2
done
[[ "$code" == 200 || "$code" == 302 ]] || { echo "AIO-Oberfläche antwortet nicht (HTTP $code)" >&2; docker logs --tail 30 "$MASTER" >&2; exit 1; }

echo "==> Lese die AIO-Passphrase"
pass=""
for _ in $(seq 1 30); do
  # The passphrase is generated (and configuration.json written) when the setup page is opened.
  [[ -f "$CONFIG" ]] || curl -ks "${BROWSER[@]}" -o /dev/null --max-time 10 https://127.0.0.1:8080/setup || true
  if [[ -f "$CONFIG" ]]; then
    pass="$(jq -r '.password // empty' "$CONFIG" 2>/dev/null || true)"
    [[ -n "$pass" ]] && break
  fi
  sleep 2
done
if [[ -n "$pass" ]]; then
  echo "PROXAPPS_SECRET:AIO_PASSPHRASE=${pass}"
else
  echo "Hinweis: Passphrase nicht gefunden – sie steht in der AIO-Oberfläche bzw. in ${CONFIG}."
  [[ -f "$CONFIG" ]] && echo "Schlüssel in configuration.json: $(jq -r 'keys|join(", ")' "$CONFIG" 2>/dev/null || true)"
fi
echo "==> Nextcloud AIO ist bereit für die Einrichtung: https://${PROXAPPS_IP}:8080"
