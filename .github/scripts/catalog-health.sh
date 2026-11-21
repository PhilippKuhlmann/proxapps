#!/usr/bin/env bash
# Runs the healthcheck of catalog/<app>/app.yaml against 127.0.0.1, like the "verify" step of
# DeploymentRunner: healthcheck.url must answer below HTTP 500, healthcheck.tcp must accept
# connections. Placeholders {ip} and {KEY} come from the env file.
# Usage: catalog-health.sh <app> <env-file>
set -euo pipefail
app=$1
set -a
# shellcheck disable=SC1090
. "$2"
set +a
yaml="catalog/${app}/app.yaml"

render() {
  local s=$1 k
  s=${s//\{ip\}/127.0.0.1}
  while [[ $s =~ \{([A-Z][A-Z0-9_]*)\} ]]; do
    k=${BASH_REMATCH[1]}
    s=${s//\{$k\}/${!k:-}}
  done
  printf '%s' "$s"
}

timeout=$(yq -r '.healthcheck.timeout // 120' "$yaml")
url=$(yq -r '.healthcheck.url // ""' "$yaml")
tcp=$(yq -r '.healthcheck.tcp // ""' "$yaml")
deadline=$((SECONDS + timeout))

if [[ -n "$url" ]]; then
  url=$(render "$url")
  echo "Prüfe ${url}"
  while :; do
    code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 5 "$url" || true)
    if [[ "$code" != 000 && "$code" -lt 500 ]]; then
      echo "HTTP ${code} – Dienst erreichbar"
      exit 0
    fi
    ((SECONDS < deadline)) || { echo "Dienst unter ${url} nicht erreichbar (HTTP ${code})" >&2; exit 1; }
    sleep 3
  done
elif [[ -n "$tcp" ]]; then
  target=$(render "$tcp")
  echo "Prüfe TCP ${target}"
  while :; do
    if timeout 3 bash -c "exec 3<>/dev/tcp/${target%:*}/${target##*:}" 2>/dev/null; then
      echo "Port ${target} erreichbar"
      exit 0
    fi
    ((SECONDS < deadline)) || { echo "Port ${target} nimmt keine Verbindungen an" >&2; exit 1; }
    sleep 3
  done
fi
echo "Keine Funktionsprüfung definiert" >&2
exit 1
