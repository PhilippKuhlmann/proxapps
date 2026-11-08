# AdGuard Home – unattended install + initial configuration.
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Env: ADMIN_USER, ADMIN_PASS, WEB_PORT (default 80)

WEB_PORT="${WEB_PORT:-80}"
DNS_PORT=53
AGH_DIR=/opt/AdGuardHome
WIZARD=http://127.0.0.1:3000

(( ${#ADMIN_PASS} >= 8 )) || { echo "ADMIN_PASS muss mindestens 8 Zeichen lang sein" >&2; exit 1; }
[[ "$WEB_PORT" != "$DNS_PORT" ]] || { echo "Weboberfläche darf nicht auf Port 53 laufen" >&2; exit 1; }

case "$(uname -m)" in
  x86_64) ARCH=amd64 ;;
  aarch64) ARCH=arm64 ;;
  armv7l) ARCH=armv7 ;;
  *) echo "Nicht unterstützte Architektur $(uname -m)" >&2; exit 1 ;;
esac

# Nothing else may hold port 53.
if ss -Hlnu 'sport = :53' | grep -q . || ss -Hlnt 'sport = :53' | grep -q .; then
  if systemctl is-active -q systemd-resolved 2>/dev/null; then
    echo "==> systemd-resolved gibt Port 53 frei"
    mkdir -p /etc/systemd/resolved.conf.d
    printf '[Resolve]\nDNSStubListener=no\n' > /etc/systemd/resolved.conf.d/adguardhome.conf
    systemctl restart systemd-resolved
  else
    echo "Port 53 ist bereits belegt:" >&2; ss -lnptu 'sport = :53' >&2; exit 1
  fi
fi

if [[ ! -x "$AGH_DIR/AdGuardHome" ]]; then
  echo "==> Lade AdGuard Home (linux_${ARCH}) herunter"
  tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
  curl -fsSL --retry 3 -o "$tmp/agh.tar.gz" \
    "https://static.adtidy.org/adguardhome/release/AdGuardHome_linux_${ARCH}.tar.gz"
  tar -xzf "$tmp/agh.tar.gz" -C /opt
fi
echo "==> Version: $("$AGH_DIR/AdGuardHome" --version)"

if [[ ! -f /etc/systemd/system/AdGuardHome.service ]]; then
  echo "==> Dienst einrichten"
  (cd "$AGH_DIR" && ./AdGuardHome -s install)
fi
systemctl enable -q AdGuardHome
systemctl is-active -q AdGuardHome || systemctl start AdGuardHome

wait_for() {
  local url=$1; shift
  for _ in $(seq 1 90); do
    curl -fsS -o /dev/null --max-time 2 "$@" "$url" 2>/dev/null && return 0
    sleep 1
  done
  echo "Zeitüberschreitung beim Warten auf $url" >&2; return 1
}

if [[ -f "$AGH_DIR/AdGuardHome.yaml" ]]; then
  echo "==> Bereits eingerichtet, Assistent wird übersprungen"
else
  echo "==> Warte auf den Einrichtungsassistenten"
  wait_for "$WIZARD/control/install/get_addresses"

  check="$(jq -nc --argjson wp "$WEB_PORT" --argjson dp "$DNS_PORT" \
    '{web:{ip:"0.0.0.0",port:$wp,autofix:false},dns:{ip:"0.0.0.0",port:$dp,autofix:false},set_static_ip:false}')"
  resp="$(curl -fsS -H 'Content-Type: application/json' -d "$check" "$WIZARD/control/install/check_config")"
  status="$(jq -r '(.web.status // "") + (.dns.status // "")' <<<"$resp")"
  [[ -z "$status" ]] || { echo "Portprüfung fehlgeschlagen: $status" >&2; exit 1; }

  echo "==> Admin-Konto '$ADMIN_USER' und Ports (Web $WEB_PORT, DNS $DNS_PORT) setzen"
  body="$(jq -nc --arg u "$ADMIN_USER" --arg p "$ADMIN_PASS" --argjson wp "$WEB_PORT" --argjson dp "$DNS_PORT" \
    '{web:{ip:"0.0.0.0",port:$wp},dns:{ip:"0.0.0.0",port:$dp},username:$u,password:$p}')"
  code="$(curl -sS -o /tmp/agh-configure.out -w '%{http_code}' -H 'Content-Type: application/json' \
    -d "$body" "$WIZARD/control/install/configure")"
  if [[ "$code" != 200 ]]; then
    echo "Einrichtung fehlgeschlagen (HTTP $code): $(cat /tmp/agh-configure.out)" >&2; exit 1
  fi
  rm -f /tmp/agh-configure.out
fi

echo "==> Prüfe Weboberfläche auf Port $WEB_PORT"
wait_for "http://127.0.0.1:${WEB_PORT}/control/status" -u "${ADMIN_USER}:${ADMIN_PASS}"
curl -fsS -u "${ADMIN_USER}:${ADMIN_PASS}" "http://127.0.0.1:${WEB_PORT}/control/status" \
  | jq -e '.running == true' >/dev/null
echo "==> AdGuard Home läuft (DNS :53, Web :${WEB_PORT})"
