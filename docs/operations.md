<a id="deutsch"></a>

> **Deutsch** | [English](#english)

# Betrieb

## Healthcheck

```bash
# Docker Compose
curl --fail http://127.0.0.1:8080/api/health.php

# Bare Metal
curl --fail http://127.0.0.1/api/health.php
```

`status: "ok"` bestätigt die Webanwendung. Bei aktiviertem Monitoring muss zusätzlich `background_monitor.fresh` wahr sein.

## Worker

```bash
# Docker
docker compose logs --tail=200 switchly
docker compose exec switchly php /var/www/html/bin/monitor.php

# Bare Metal
sudo systemctl status switchly-worker.timer
sudo systemctl start switchly-worker.service
sudo journalctl -u switchly-worker.service -n 100
```

Ein Dateilock verhindert überlappende Läufe. Der Worker speichert Cache, Monitoring-Samples, Alert-Zustände, Logs und seinen Heartbeat in SQLite.

## Backup

Docker:

```bash
docker compose stop
docker run --rm -v switchly_data:/source:ro -v "$PWD:/backup" alpine:3.22 \
  tar -czf "/backup/switchly-backup-$(date +%F).tar.gz" -C /source .
docker compose start
```

Bare Metal:

```bash
sudo systemctl stop switchly-worker.timer
sudo systemctl stop apache2
sudo tar -czf "/root/switchly-backup-$(date +%F).tar.gz" \
  /var/lib/switchly /etc/switchly /var/www/switchly
sudo systemctl start apache2
sudo systemctl start switchly-worker.timer
```

Backups enthalten Zugangsdaten und Secrets und müssen verschlüsselt beziehungsweise zugriffsgeschützt gespeichert werden.

## Updates

```bash
# Nach erfolgreichem Backup
docker compose down --remove-orphans
docker compose build --pull
docker compose up -d
```

Bei Bare Metal neue Dateien nach `/var/www/switchly` kopieren, den Document Root `/var/www/switchly/public` beibehalten und anschließend Apache sowie Worker neu starten.

## Fehlerdiagnose

### Switch offline

```bash
docker compose exec switchly \
  curl --digest -u 'admin:SWITCH-PASSWORT' \
  http://SWITCH-IP/sys.b
```

Host, Port, Benutzer, Passwort, Routing, Firewall und das geschützte Management-Netz prüfen.

### Worker veraltet

- `SWITCHLY_MONITOR_ENABLED=1` kontrollieren
- Schreibrechte des Datenbankverzeichnisses prüfen
- Worker manuell ausführen
- `background_monitor.message` und Logs auswerten
- Intervall von Umgebungsdatei und systemd-Timer abgleichen

### VLAN oder LAG leer

Die Gerätepfade `/lacp.b`, `/fwd.b` und `/vlan.b` direkt abfragen. Modell- und Firmwareunterschiede können Felder oder Schreiboperationen verändern.

### SQLite readonly oder locked

Pfad, Eigentümer und Verzeichnisrechte kontrollieren. Für Offline-Backups Webserver und Worker stoppen. Eine aktive WAL-Datenbank nicht unkontrolliert als einzelne Datei kopieren.

---

<a id="english"></a>

> [Deutsch](#deutsch) | **English**

<a id="en-operation"></a>

# Operationss

<a id="en-health-check"></a>

## Health check

```bash
# Docker Compose
curl --fail http://127.0.0.1:8080/api/health.php

# Bare Metal
curl --fail http://127.0.0.1/api/health.php
```

`status: "ok"` confirms that the web application is available. When monitoring is enabled, `background_monitor.fresh` must also be `true`.

<a id="en-workers"></a>

## Workers

```bash
# Docker
docker compose logs --tail=200 switchly
docker compose exec switchly php /var/www/html/bin/monitor.php

# Bare Metal
sudo systemctl status switchly-worker.timer
sudo systemctl start switchly-worker.service
sudo journalctl -u switchly-worker.service -n 100
```

A file lock prevents overlapping runs. The worker stores cache data, monitoring samples, alert states, logs, and its heartbeat in SQLite.

<a id="en-backup"></a>

## Backup

Docker:

```bash
docker compose stop
docker run --rm -v switchly_data:/source:ro -v "$PWD:/backup" alpine:3.22 \
  tar -czf "/backup/switchly-backup-$(date +%F).tar.gz" -C /source .
docker compose start
```

Bare metal:

```bash
sudo systemctl stop switchly-worker.timer
sudo systemctl stop apache2
sudo tar -czf "/root/switchly-backup-$(date +%F).tar.gz" \
  /var/lib/switchly /etc/switchly /var/www/switchly
sudo systemctl start apache2
sudo systemctl start switchly-worker.timer
```

Backups contain access data and secrets and must be stored encrypted or protected from access.

<a id="en-updates"></a>

## Updates

```bash
# After a successful backup
docker compose down --remove-orphans
docker compose build --pull
docker compose up -d
```

For bare-metal installations, copy the new files to `/var/www/switchly`, verify the document root `/var/www/switchly/public`, and then restart Apache and the worker.

<a id="en-error-diagnosis"></a>

## Troubleshooting

<a id="en-switch-offline"></a>

### Switch offline

```bash
docker compose exec switchly \
  curl --digest -u 'admin:SWITCH-PASSWORD' \
  http://SWITCH-IP/sys.b
```

Check host, port, user, password, routing, firewall and the protected management network.

<a id="en-workers-outdated"></a>

### Worker is stale

- Verify `SWITCHLY_MONITOR_ENABLED=1`
- Check write permissions for the database directory
- Run the worker manually
- Review `background_monitor.message` and the logs
- Compare the interval in the environment file with the systemd timer

<a id="en-vlan-or-lag-empty"></a>

### VLAN or LAG empty

Query the device paths `/lacp.b`, `/fwd.b`, and `/vlan.b` directly. Model and firmware differences can affect fields and write operations.

<a id="en-sqlite-readonly-or-locked"></a>

### SQLite readonly or locked

Check the path, owner, and directory permissions. Stop the web server and worker before creating an offline backup. Do not copy an active WAL database as a single file.
