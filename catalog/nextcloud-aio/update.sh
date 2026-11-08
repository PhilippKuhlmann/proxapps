# Nextcloud All-in-One – update AIO (mastercontainer + all Nextcloud containers).
# Runs inside the container as root. Debian packages incl. Docker are already updated by ProxApps.
# Uses the documented external trigger: daily-backup.sh with AUTOMATIC_UPDATES=1 (no backup).

MASTER=nextcloud-aio-mastercontainer

docker ps -a --format '{{.Names}}' | grep -qx "$MASTER" || { echo "Kein AIO-Mastercontainer gefunden" >&2; exit 1; }
docker start "$MASTER" >/dev/null 2>&1 || true

if ! docker ps -a --format '{{.Names}}' | grep -qx nextcloud-aio-nextcloud; then
  echo "==> Die AIO-Ersteinrichtung ist noch nicht abgeschlossen – nur das Mastercontainer-Image wird aktualisiert."
  echo "    (AIO aktualisiert sich nach der Einrichtung selbst über diesen Weg.)"
  docker pull -q ghcr.io/nextcloud-releases/all-in-one:latest
  exit 0
fi

wait_master() {
  for _ in $(seq 1 120); do
    if docker ps --format '{{.Names}}' | grep -qx "$MASTER" \
       && ! docker ps --format '{{.Names}}' | grep -qx nextcloud-aio-watchtower; then
      return 0
    fi
    sleep 5
  done
  return 1
}

before="$(docker exec "$MASTER" cat /var/www/docker-aio/php/templates/includes/aio-version.twig 2>/dev/null | tr -d ' \n' || true)"
echo "==> AIO-Version vorher: ${before:-unbekannt}"

# First run may only update the mastercontainer (it restarts itself via watchtower);
# run again afterwards so the Nextcloud containers get updated as well.
for round in 1 2; do
  echo "==> Update-Lauf ${round}: Container stoppen, aktualisieren, starten"
  docker exec --env AUTOMATIC_UPDATES=1 --env DAILY_BACKUP=0 "$MASTER" /daily-backup.sh || true
  wait_master || { echo "Mastercontainer kommt nach dem Update nicht zurück" >&2; exit 1; }
done

after="$(docker exec "$MASTER" cat /var/www/docker-aio/php/templates/includes/aio-version.twig 2>/dev/null | tr -d ' \n' || true)"
echo "==> AIO-Version nachher: ${after:-unbekannt}"
if docker exec --user www-data nextcloud-aio-nextcloud php occ status --output=json >/tmp/occ.json 2>/dev/null; then
  echo "==> Nextcloud $(jq -r '.versionstring // "?"' /tmp/occ.json) läuft"
fi
rm -f /tmp/occ.json
