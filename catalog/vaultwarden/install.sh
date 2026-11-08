# Vaultwarden – official Docker image vaultwarden/server.
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Env: ADMIN_TOKEN, TLS_MODE (proxy|self_signed), DOMAIN (optional), WEB_PORT, SIGNUPS_ALLOWED (1|0),
#      DOCKER_MTU (optional)

TLS_MODE="${TLS_MODE:-proxy}"
WEB_PORT="${WEB_PORT:-8000}"
DIR=/opt/vaultwarden
IMAGE=vaultwarden/server:latest

(( ${#ADMIN_TOKEN} >= 16 )) || { echo "ADMIN_TOKEN muss mindestens 16 Zeichen lang sein" >&2; exit 1; }

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
apt-get install -y -q --no-install-recommends argon2 openssl

if [[ -n "${DOCKER_MTU:-}" ]]; then
  echo "==> Docker-MTU ${DOCKER_MTU}"
  mkdir -p /etc/docker
  jq -n --argjson mtu "$DOCKER_MTU" \
    '{mtu: $mtu, "default-network-opts": {bridge: {"com.docker.network.driver.mtu": ($mtu|tostring)}}}' \
    > /etc/docker/daemon.json
fi
systemctl enable -q docker
systemctl restart docker

install -d -m 0700 "$DIR" "$DIR/data"

if [[ "$TLS_MODE" == "self_signed" ]]; then
  scheme=https
  if [[ ! -f "$DIR/ssl/cert.pem" ]]; then
    echo "==> Erzeuge ein selbstsigniertes Zertifikat (10 Jahre) für ${PROXAPPS_IP}"
    install -d -m 0700 "$DIR/ssl"
    openssl req -x509 -newkey rsa:3072 -nodes -days 3650 -subj "/CN=${PROXAPPS_HOSTNAME}" \
      -addext "subjectAltName=IP:${PROXAPPS_IP},DNS:${PROXAPPS_HOSTNAME}" \
      -keyout "$DIR/ssl/key.pem" -out "$DIR/ssl/cert.pem" 2>/dev/null
  fi
else
  scheme=http
fi
local_url="${scheme}://${PROXAPPS_IP}:${WEB_PORT}"
domain="${DOMAIN:-$local_url}"

echo "==> Admin-Token als Argon2-Hash ablegen"
token_hash="$(printf '%s' "$ADMIN_TOKEN" | argon2 "$(openssl rand -base64 32)" -e -id -k 65540 -t 3 -p 4)"
umask 077
{
  echo "ADMIN_TOKEN=${token_hash}"
  echo "DOMAIN=${domain%/}"
  echo "SIGNUPS_ALLOWED=$([[ "${SIGNUPS_ALLOWED:-1}" == 1 ]] && echo true || echo false)"
  echo "TZ=$(cat /etc/timezone 2>/dev/null || echo UTC)"
  if [[ "$scheme" == https ]]; then
    echo 'ROCKET_TLS={certs="/ssl/cert.pem",key="/ssl/key.pem"}'
  fi
} > "$DIR/vaultwarden.env"
printf 'WEB_PORT=%s\nSCHEME=%s\n' "$WEB_PORT" "$scheme" > "$DIR/proxapps.env"

# (Re)create the container from the current image; used by the update script, too.
cat > "$DIR/run.sh" <<'RUN'
#!/bin/bash
set -euo pipefail
cd /opt/vaultwarden
. ./proxapps.env
docker rm -f vaultwarden >/dev/null 2>&1 || true
args=(-d --name vaultwarden --restart unless-stopped --env-file vaultwarden.env
      -v /opt/vaultwarden/data:/data -p "${WEB_PORT}:80")
[[ -d ssl ]] && args+=(-v /opt/vaultwarden/ssl:/ssl:ro)
docker run "${args[@]}" vaultwarden/server:latest >/dev/null
RUN
chmod 0700 "$DIR/run.sh"

echo "==> Lade das Vaultwarden-Image"
docker pull -q "$IMAGE"
"$DIR/run.sh"
echo "==> Container gestartet, warte auf /alive"
for _ in $(seq 1 60); do
  curl -ksf -o /dev/null --max-time 3 "${scheme}://127.0.0.1:${WEB_PORT}/alive" && break
  sleep 2
done
curl -ksf -o /dev/null --max-time 5 "${scheme}://127.0.0.1:${WEB_PORT}/alive" \
  || { echo "Vaultwarden antwortet nicht" >&2; docker logs --tail 30 vaultwarden >&2; exit 1; }

echo "==> Vaultwarden $(docker exec vaultwarden /vaultwarden --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n1) läuft"
echo "PROXAPPS_VAR:ACCESS_URL=${domain%/}/"
echo "PROXAPPS_VAR:HEALTH_URL=${local_url}/alive"
echo "PROXAPPS_VAR:UPSTREAM_URL=${local_url}"
