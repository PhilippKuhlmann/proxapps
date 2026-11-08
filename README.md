# ProxApps

Weboberfläche im DokuVault-Design, mit der du Apps per Klick als **Debian-LXC** auf deinem
**Proxmox VE** (Einzelserver oder Cluster) ausrollst. Jede App bringt empfohlene Ressourcen mit, die du
anpassen kannst. VMID und IP legst du selbst fest oder lässt sie vorschlagen.

**Stack:** Laravel 13, Livewire 4, Tailwind 4, SQLite, Queue-Worker. Alles steckt in einem
Docker-Container mit nginx, php-fpm und den Workern unter supervisord.

## Funktionen

- **Proxmox-Verbindungen** per API-Token. Das TLS-Zertifikat wird nach Bestätigung des Fingerabdrucks
  festgepinnt (Public-Key-Pin, vor dem ersten gesendeten Byte geprüft). Optional gibt es einen
  **Ressourcenpool**, auf den sich die Schreibrechte des Tokens beschränken lassen.
- **App-Katalog** (`catalog/<id>/app.yaml` + `install.sh`). Mitgeliefert wird **AdGuard Home**,
  vollständig unbeaufsichtigt eingerichtet: Admin-Konto und Ports sind gesetzt, kein Einrichtungsassistent.
- **Deploy-Formular**:
  - wählt Node, Speicher, Debian-Vorlage (fehlt sie, wird sie automatisch geladen) und Bridge/VLAN;
  - zeigt die empfohlenen Ressourcen mit Hinweis an und lässt sie zurücksetzen;
  - prüft VMID und IP live gegen alle Gäste-Konfigurationen und per Ping.
- **IP → VMID**: Das Standardmuster `{o3}{o4:03}` macht aus `10.10.250.55` die VMID `250055` und aus
  `10.10.240.40` die `240040`. Es ist unter *Einstellungen* oder pro Netzwerkprofil änderbar.
  - Platzhalter: `{vlan}`, `{o1}`…`{o4}`, `{o4:03}` (mit führenden Nullen).
- **Netzwerkprofile** (Bridge, VLAN, Netz, Gateway, DNS, Suchdomain, IP-Pool) schlagen die nächste freie
  IP vor.
- **Live-Protokoll** jedes Deployments mit Schritt-Anzeige: Prüfungen → Vorlage → Anlegen → Start →
  SSH → Grundsystem → App → Funktionsprüfung.
- **Ergebnisseite** mit Zugangs-URL, Benutzer und Passwort (nur auf Klick sichtbar). Dazu
  Start/Stopp/Neustart/Entfernen des Containers und „Erneut ausrollen“.
- **Sicherheitsnetz**: ProxApps startet, stoppt oder löscht nur Container, die den Tag `proxapps` *und*
  den erwarteten Hostnamen tragen.

## Zentrale Updates

Unter **Updates** siehst du alle von ProxApps verwalteten Container mit:
- installierter und neuester App-Version,
- Anzahl ausstehender Debian-Pakete,
- Ergebnis des letzten Updates.

ProxApps prüft das alle 6 Stunden. Mit „Jetzt prüfen“ stößt du die Prüfung sofort an.

Ein Update läuft pro Container so ab:

1. **Prüfen:** Der Container läuft und trägt die ProxApps-Tags.
2. **Snapshot** in Proxmox (`proxapps_upd_<Datum>`), wenn aktiviert.
3. **Debian-Pakete:** `apt dist-upgrade`.
4. **App-Update** über das `update.sh` der App. Bei AdGuard Home wird nur die Binärdatei getauscht; Einstellungen und Statistik bleiben erhalten.
5. **Funktionsprüfung** über den Healthcheck der App.
6. **Aufräumen:** Ältere ProxApps-Snapshots werden gelöscht. Standard: die 3 neuesten behalten; fremde Snapshots bleiben unberührt.

Schlägt ein Schritt fehl, rollt ProxApps automatisch auf den Snapshot zurück (abschaltbar). Jeden noch
vorhandenen Snapshot kannst du beim Deployment mit „Zurückrollen“ wiederherstellen.

Optional gibt es ein **Update-Fenster**, z. B. täglich um 03:00. Darin prüft ProxApps alle Container
und aktualisiert die mit ausstehenden Updates.

Für Snapshots braucht der Token zusätzlich `VM.Snapshot` und `VM.Snapshot.Rollback`. Beides ist in der
Rolle unten enthalten.

Damit eine Katalog-App zentral aktualisiert werden kann, braucht ihre `app.yaml` diesen Block:

```yaml
updates:
  script: update.sh                       # läuft im Container
  installed_version: "<Befehl, der die installierte Version ausgibt>"
  latest_version:
    url: https://…/version.json           # ProxApps liest die neueste Version hier
    json: version                         # Schlüssel im JSON
```

## Installation (Docker)

```bash
git clone <repo> /opt/proxapps && cd /opt/proxapps
echo "APP_URL=http://<host-ip>:8080" > .env
docker compose up -d --build
```

Danach `http://<host-ip>:8080` öffnen. Beim ersten Aufruf legst du das Admin-Konto an.

- **Daten:** Alles Persistente liegt im Volume `proxapps-storage`: SQLite-Datenbank, Anwendungsschlüssel
  (`app.key`, verschlüsselt Token und Passwörter) und das SSH-Schlüsselpaar des Deployers.
  **Sichere dieses Volume.**
- **Build-Netz:** `docker-compose.yml` baut mit `network: host`. So brechen Paket-Downloads nicht ab,
  wenn die Uplink-MTU unter 1500 liegt (z. B. PPPoE).
- **TLS:** Hinter einem Reverse-Proxy mit TLS zusätzlich `SESSION_SECURE_COOKIE=true` setzen.
- **Ressourcen:** Für den Docker-Host reichen 1–2 vCPU und 1–2 GB RAM. Beim ersten Build ist mehr RAM
  angenehmer.

## Proxmox-Token mit minimalen Rechten

Diese Variante beschränkt alle Schreibrechte auf einen eigenen Pool. Bestehende Gäste kann ProxApps dann
technisch nicht verändern:

```bash
pvesh create /pools --poolid proxapps --comment "Von ProxApps ausgerollte Container"
pveum role add ProxAppsRead    --privs "VM.Audit,Datastore.Audit,Sys.Audit,SDN.Audit,Pool.Audit"
pveum role add ProxAppsDeploy  --privs "VM.Allocate,VM.Audit,VM.Config.CPU,VM.Config.Memory,VM.Config.Disk,VM.Config.Network,VM.Config.Options,VM.PowerMgmt,VM.Snapshot,VM.Snapshot.Rollback,Pool.Audit"
pveum role add ProxAppsStorage --privs "Datastore.AllocateSpace,Datastore.AllocateTemplate,Datastore.Audit"
pveum user add proxapps@pve --comment "ProxApps"
pveum user token add proxapps@pve deploy --privsep 1      # Secret wird nur einmal angezeigt
for t in "--users proxapps@pve" "--tokens proxapps@pve!deploy"; do
  pveum acl modify /                      --roles ProxAppsRead    $t
  pveum acl modify /pool/proxapps         --roles ProxAppsDeploy  $t
  pveum acl modify /storage/local         --roles ProxAppsStorage $t   # Vorlagen-Speicher
  pveum acl modify /storage/local-zfs     --roles ProxAppsStorage $t   # Container-Speicher
  pveum acl modify /sdn/zones/localnetwork --roles PVESDNUser     $t   # Bridges/VLANs
done
```

Danach unter *Proxmox-Verbindungen* eintragen und als Ressourcenpool `proxapps` angeben.

- **Tags:** Proxmox prüft Tag-Rechte nur am Pfad `/vms/<id>`. ProxApps setzt die Tags deshalb direkt
  nach dem Anlegen, sobald der Container im Pool ist.
- **SSH in die Container:** Neue Container bekommen den öffentlichen Deployer-Schlüssel (siehe
  *Einstellungen*). ProxApps braucht deshalb SSH-Zugriff (Port 22) auf die IP des neuen Containers.

## Eigene Apps hinzufügen

Lege dafür `catalog/<id>/` mit drei Dateien an (Vorlage: `catalog/adguard-home/`):

| Datei | Inhalt |
|---|---|
| `app.yaml` | Name, Beschreibung, `resources` (cores/memory/swap/disk), `parameters` (text/password/number/boolean/select), `healthcheck.url`, `access.url`, Hinweise |
| `install.sh` | läuft als root per SSH im Container mit `set -euo pipefail`; Parameter kommen als Umgebungsvariablen, dazu `PROXAPPS_IP`, `PROXAPPS_HOSTNAME`, `PROXAPPS_VMID` |
| `icon.svg` | optionales Icon |

Vor jedem App-Skript läuft `resources/provisioning/base.sh`: apt update/upgrade, curl, jq, Zeitzone.

## Entwicklung

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate && php artisan migrate
npm run dev  &  php artisan serve  &  php artisan queue:work --timeout=3700
php artisan test
```
