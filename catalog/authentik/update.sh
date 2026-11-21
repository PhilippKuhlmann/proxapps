# authentik – follow the newest release (YYYY.M image tag) and recreate the containers.
# Runs inside the container as root. Database and media in /opt/authentik are kept; authentik
# migrates the database itself on start.

DIR=/opt/authentik
[[ -f "$DIR/docker-compose.yml" ]] || { echo "authentik ist nicht unter $DIR eingerichtet" >&2; exit 1; }
cd "$DIR"
. ./proxapps.env

version() { docker inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' authentik 2>/dev/null || true; }
ready() { curl -sf -o /dev/null --max-time 3 "http://127.0.0.1:${WEB_PORT}/-/health/ready/"; }

# >>> release lines (tested by .github/scripts/test-authentik-lines.sh)
# Release tags look like "version/2026.8.6"; the image tag is the release line (2026.8).
# release_lines: tags on stdin -> distinct release lines, oldest first (release candidates are skipped).
release_lines() { sed -nE 's~^version/([0-9]{4}\.[0-9]{1,2})\.[0-9]+$~\1~p' | sort -uV; }
# next_line CURRENT: release lines on stdin -> the first line after CURRENT (empty when CURRENT is the newest).
next_line() {
  local cur=$1 line
  while read -r line; do
    if [[ "$line" != "$cur" && "$(printf '%s\n%s\n' "$cur" "$line" | sort -V | tail -n1)" == "$line" ]]; then
      echo "$line"
      return 0
    fi
  done
  return 0
}
# <<< release lines

echo "==> Installiert: $(version)"
# authentik only supports upgrading one release line at a time (the migrations build on each
# other): move to the next line, never straight to the newest.
lines="$(curl -fsSL --max-time 15 'https://api.github.com/repos/goauthentik/authentik/releases?per_page=100' \
  | jq -r '.[] | select((.prerelease | not) and (.draft | not)) | .tag_name' | release_lines || true)"
if [[ -z "$lines" ]]; then
  echo "Warnung: Release-Liste von GitHub nicht abrufbar – bleibe auf ${AUTHENTIK_TAG}." >&2
else
  newest="$(tail -n1 <<<"$lines")"
  next="$(next_line "$AUTHENTIK_TAG" <<<"$lines")"
  if [[ -n "$next" ]]; then
    if [[ "$next" == "$newest" ]]; then
      echo "==> Neue Release-Linie ${next}"
    else
      echo "==> Nächste Release-Linie ${next} (neueste: ${newest}). Für ${newest} das Update erneut ausführen."
    fi
    sed -i "s/^AUTHENTIK_TAG=.*/AUTHENTIK_TAG=${next}/" proxapps.env
    AUTHENTIK_TAG="$next"
  fi
fi
cat proxapps.env secrets.env > .env
chmod 600 .env
docker compose pull -q
docker compose up -d
for _ in $(seq 1 200); do ready && break; sleep 3; done
ready || { echo "authentik antwortet nach dem Update nicht" >&2; docker compose logs --tail 40 server worker >&2; exit 1; }
docker image prune -f >/dev/null
echo "==> authentik läuft jetzt mit $(version)"
