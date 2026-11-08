# AdGuard Home – update to the newest release.
# Runs inside the container as root (bash, set -euo pipefail is already active).
# Only the binary is replaced; AdGuardHome.yaml, filters, statistics and the query log stay.

AGH_DIR=/opt/AdGuardHome
case "$(uname -m)" in
  x86_64) ARCH=amd64 ;;
  aarch64) ARCH=arm64 ;;
  armv7l) ARCH=armv7 ;;
  *) echo "Nicht unterstützte Architektur $(uname -m)" >&2; exit 1 ;;
esac

[[ -x "$AGH_DIR/AdGuardHome" ]] || { echo "AdGuard Home ist nicht unter $AGH_DIR installiert" >&2; exit 1; }

current="$("$AGH_DIR/AdGuardHome" --version | grep -oE 'v[0-9]+(\.[0-9]+)+' | head -n1)"
latest="$(curl -fsSL --retry 3 https://static.adtidy.org/adguardhome/release/version.json | jq -r '.version')"
echo "==> Installiert: ${current:-unbekannt}, verfügbar: ${latest:-unbekannt}"
if [[ -z "$latest" || "$latest" == "null" ]]; then
  echo "Neueste Version konnte nicht ermittelt werden" >&2; exit 1
fi
if [[ "$current" == "$latest" ]]; then
  echo "==> AdGuard Home ist bereits aktuell"
  exit 0
fi

tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
echo "==> Lade AdGuard Home $latest (linux_${ARCH})"
curl -fsSL --retry 3 -o "$tmp/agh.tar.gz" \
  "https://static.adtidy.org/adguardhome/release/AdGuardHome_linux_${ARCH}.tar.gz"
tar -xzf "$tmp/agh.tar.gz" -C "$tmp"
[[ -x "$tmp/AdGuardHome/AdGuardHome" ]] || { echo "Archiv enthält keine AdGuardHome-Binärdatei" >&2; exit 1; }

echo "==> Tausche die Binärdatei aus"
systemctl stop AdGuardHome
cp -a "$AGH_DIR/AdGuardHome" "$AGH_DIR/AdGuardHome.previous"
install -m 0755 "$tmp/AdGuardHome/AdGuardHome" "$AGH_DIR/AdGuardHome"
systemctl start AdGuardHome

for _ in $(seq 1 30); do
  systemctl is-active -q AdGuardHome && break
  sleep 1
done
systemctl is-active -q AdGuardHome || {
  echo "AdGuard Home startet nicht – stelle die vorige Version wieder her" >&2
  install -m 0755 "$AGH_DIR/AdGuardHome.previous" "$AGH_DIR/AdGuardHome"
  systemctl start AdGuardHome || true
  exit 1
}
rm -f "$AGH_DIR/AdGuardHome.previous"
echo "==> AdGuard Home läuft jetzt mit $("$AGH_DIR/AdGuardHome" --version | grep -oE 'v[0-9]+(\.[0-9]+)+' | head -n1)"
