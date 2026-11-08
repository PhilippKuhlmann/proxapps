# ProxApps base provisioning, runs before every app install script.
# Variables: PROXAPPS_UPGRADE (1/0), PROXAPPS_TZ (zone name)

echo "==> Paketquellen aktualisieren"
apt-get update -q

if [ "${PROXAPPS_UPGRADE:-1}" = "1" ]; then
  echo "==> Grundsystem aktualisieren"
  apt-get -y -q -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold dist-upgrade
fi

echo "==> Basispakete installieren"
apt-get install -y -q --no-install-recommends curl ca-certificates jq tar gzip iproute2

if [ -n "${PROXAPPS_TZ:-}" ] && [ -f "/usr/share/zoneinfo/${PROXAPPS_TZ}" ]; then
  echo "==> Zeitzone ${PROXAPPS_TZ}"
  ln -sf "/usr/share/zoneinfo/${PROXAPPS_TZ}" /etc/localtime
  echo "${PROXAPPS_TZ}" > /etc/timezone
fi

apt-get -y -q autoremove >/dev/null
apt-get clean
echo "==> Grundsystem bereit: $(. /etc/os-release && echo "$PRETTY_NAME")"
