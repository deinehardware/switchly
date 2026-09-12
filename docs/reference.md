<a id="deutsch"></a>

> **Deutsch** | [English](#english)

![Switchly Network Control Center](images/readme-banner.png)


<div align="center">
  <h1>Vollständige Dokumentation</h1>
  <p><strong>Projektversion v1.4.7 Beta · Stand 7. September 2026</strong></p>

  <p>
    <a href="../README.md">Projektübersicht</a> ·
    <a href="README.md">Doku-Übersicht</a> ·
    <a href="faq.md">FAQ</a> ·
    <a href="../SECURITY.md">Sicherheit</a>
  </p>
</div>

Diese Dokumentation beschreibt Installation, Betrieb, Funktionen, Berechtigungen, Hintergrund-Monitoring, OpenID Connect, Datensicherung und sämtliche HTTP-Endpunkte von Switchly.

> [!TIP]
> Für den ersten Start genügt der [Schnellstart](../README.md#schnellstart). Bei konkreten Problemen führt das [FAQ](faq.md) schneller zur passenden Lösung.

## Schnellnavigation

| Einstieg                                                        | Konfiguration                                                       |
| --------------------------------------------------------------- | ------------------------------------------------------------------- |
| [Projektüberblick](#1-projektüberblick)                         | [Umgebungsvariablen](#7-umgebungsvariablen)                         |
| [Docker installieren](#4-installation-mit-docker)               | [Funktionen](#9-funktionsbeschreibung)                              |
| [Bare Metal installieren](#5-bare-metal-installation-auf-linux) | [Rechte und Gruppen](#97-berechtigungen-und-gruppen)                |
| [HTTPS einrichten](#6-reverse-proxy-und-https)                  | [OpenID Connect](#de-openid-connect) |

## 1. Projektüberblick

Switchly ist ein selbst gehostetes Web-CMS für MikroTik-Switches mit SwitchOS. Die Anwendung besteht aus:

- Apache und PHP;
- einer SQLite-Datenbank;
- einem Browser-Dashboard;
- einem unabhängigen Hintergrund-Worker;
- direkten HTTP-Digest-Verbindungen zu den verwalteten SwitchOS-Geräten.

Der Webserver stellt die Benutzeroberfläche und die interne JSON-API bereit. Der Worker fragt alle eingetragenen Switches regelmäßig ab, aktualisiert den lokalen Cache, schreibt Monitoring-Samples und löst Ausfall- oder Recovery-Alerts aus. Das Dashboard liest für normale Aktualisierungen aus SQLite und bleibt deshalb auch bei langsamen oder nicht erreichbaren Switches reaktionsfähig.

### Unterstützte Hauptfunktionen

- mehrere SwitchOS-Switches zentral verwalten;
- Übersicht über Online-Status, Modell, Firmware, Uptime und aktive Ports;
- Portstatus, Portnamen, Traffic-Zähler und Fehler anzeigen;
- Ports aktivieren, deaktivieren und umbenennen;
- Auto-Negotiation aktivieren oder deaktivieren und Portgeschwindigkeit einstellen;
- LAG/LACP pro Port als Passive LACP, Active LACP oder Static verwalten;
- VLAN-Tabelle anlegen, bearbeiten und löschen;
- VLAN-Mitgliedsports, PVID, VLAN-Modus, Receive-Modus und Force VLAN ID verwalten;
- Switch neu starten;
- SwitchOS-Konfiguration als `.swb` herunterladen und wiederherstellen;
- automatischer Reboot nach erfolgreichem Config-Restore;
- Benutzer, E-Mail-Verifikation und TOTP-2FA verwalten;
- 2FA eines Benutzers durch einen Administrator zurücksetzen;
- Benutzer- und Gruppenrechte sowie Zugriff auf einzelne Switches steuern;
- Monitoring für einen einzelnen Switch oder den gesamten Stack anzeigen;
- CMS-, Stack- und Switch-Logs filtern;
- Ausfall- und Recovery-Alerts per E-Mail, generischem Webhook oder Discord senden;
- generisches OpenID Connect, beispielsweise mit Keycloak, nutzen;
- Hintergrund-Monitoring ohne geöffneten Browser durchführen.

## 2. Technische Funktionsweise

1. Der Browser authentifiziert sich beim CMS über eine PHP-Session oder OpenID Connect.
2. Das Dashboard liest Übersichtsdaten aus dem lokalen SQLite-Cache.
3. Der Hintergrund-Worker fragt `/sys.b`, `/link.b` und `/stats.b` aller Switches per HTTP Digest ab.
4. Cache, Monitoring-Historie, Worker-Status, Alert-Zustände und Logs werden in SQLite gespeichert.
5. Manuelles **Aktualisieren** löst eine direkte Live-Abfrage des ausgewählten Switches aus.
6. Konfigurationsaktionen werden unmittelbar an den Switch gesendet.

VLAN- und LAG-Konfigurationen werden live vom Gerät gelesen. Sie werden nicht zyklisch in einer eigenen Historientabelle gespeichert.

## 3. Voraussetzungen

### Netzwerk

- Der Server oder Docker-Container muss die Management-IP jedes Switches erreichen.
- Standardmäßig wird unverschlüsseltes HTTP auf dem konfigurierten Switch-Port verwendet.
- Die Anmeldung am Switch erfolgt mit HTTP Digest Authentication.
- Firewalls müssen ausgehenden Zugriff vom CMS zu den Switches erlauben.
- Für SMTP, Webhooks und OIDC muss der Server die jeweiligen externen Ziele erreichen.

### Docker

- Docker Engine;
- Docker Compose v2 mit dem Befehl `docker compose`;
- empfohlen: aktuelles Debian, Ubuntu oder eine andere unterstützte Linux-Distribution.

### Bare Metal

- Linux mit Apache 2.4;
- PHP 8.5;
- PHP-Erweiterungen `pdo_sqlite`, `curl`, `openssl`, `session`, `json` und `opcache`;
- PHP CLI für den Hintergrund-Worker;
- Schreibzugriff des Webserver-Benutzers auf das Datenbankverzeichnis.

## 4. Installation mit Docker

### 4.1 Projekt entpacken

```bash
unzip switchly.zip
cd switchly
cp .env.example .env
```

Bearbeite anschließend `.env`. Ein eigenes Initialpasswort ist optional:

```env
SWITCHLY_ADMIN_USER=admin
SWITCHLY_ADMIN_PASSWORD=
```

Die Variablen legen nur bei einer leeren Datenbank den initialen Benutzer an. Ist das Passwort leer, wird es sicher generiert und einmalig im Startprotokoll ausgegeben. Bei einer vorhandenen Datenbank ändert `.env` weder Passwort noch Rolle eines Kontos.

### 4.2 Container starten

```bash
docker compose up -d --build
```

Das CMS ist standardmäßig erreichbar unter:

```text
http://SERVER-IP:8080
```

Status und Logs prüfen:

```bash
docker compose ps
docker compose logs -f switchly
curl http://127.0.0.1:8080/api/health.php
```

### 4.3 Container stoppen oder neu starten

```bash
docker compose stop
docker compose restart switchly
docker compose down
```

`docker compose down` löscht das benannte Volume `switchly_data` nicht. Benutzer, Einstellungen und Historie bleiben erhalten.


### 4.4 Web-Port ändern

```env
SWITCHLY_HTTP_PORT=8090
```

Danach:

```bash
docker compose up -d
```

### 4.5 Erreichbarkeit eines Switches aus dem Container testen

```bash
docker compose exec switchly \
  curl --digest -u 'admin:SWITCH-PASSWORT' http://<SWITCH-IP>/sys.b
```

Bei einem leeren Switch-Passwort lautet die Angabe beispielsweise `-u 'admin:'`.

### 4.6 Optional nur lokal veröffentlichen

Wenn ein Reverse Proxy auf demselben Host verwendet wird, kann der Port in `docker-compose.yml` auf Loopback begrenzt werden:

```yaml
ports:
  - "127.0.0.1:${SWITCHLY_HTTP_PORT:-8080}:8080"
```

### 4.7 Optionales Host-Networking unter Linux

Wenn das Docker-Bridge-Netz die Switches nicht erreicht, kann unter Linux testweise `ports:` entfernt und Folgendes gesetzt werden:

```yaml
network_mode: host
```

Apache lauscht dann direkt auf Port 8080 des Hosts. Diese Variante unterscheidet sich unter Docker Desktop für Windows oder macOS und ist normalerweise nicht nötig.

## 5. Bare-Metal-Installation auf Linux

Das folgende Beispiel gilt für Debian oder Ubuntu mit Apache.

Für einen kompakten Schnellstart enthält das Repository unter `deploy/bare-metal/` fertige Vorlagen für die Umgebung, den Apache VirtualHost sowie den systemd-Service und -Timer. Die direkt ausführbaren Installationsbefehle stehen im [Bare-Metal-Schnellstart der README](../README.md#bare-metal-schnellstart-debianubuntu). Dieses Kapitel erklärt dieselbe Einrichtung vollständig und eignet sich für individuelle Anpassungen.

### 5.1 Pakete installieren

```bash
sudo apt update
sudo apt install apache2 libapache2-mod-php php-cli php-curl php-sqlite3 php-opcache curl unzip
sudo a2enmod headers expires
```

Module kontrollieren:

```bash
php -m | grep -E 'curl|openssl|PDO|pdo_sqlite|session'
php -v
```

### 5.2 Verzeichnisse anlegen und Dateien kopieren

```bash
sudo mkdir -p /var/www/switchly
sudo mkdir -p /var/lib/switchly
sudo mkdir -p /etc/switchly
sudo tar \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.env' \
  --exclude='data' \
  --exclude='deploy' \
  --exclude='docs' \
  --exclude='scripts' \
  -cf - . | sudo tar -xf - -C /var/www/switchly
sudo chown -R root:www-data /var/www/switchly
sudo find /var/www/switchly -type d -exec chmod 750 {} \;
sudo find /var/www/switchly -type f -exec chmod 640 {} \;
sudo chown -R www-data:www-data /var/lib/switchly
sudo chmod 770 /var/lib/switchly
```

Die SQLite-Datei sollte nicht im öffentlich erreichbaren Document Root liegen.

### 5.3 Umgebungsdatei anlegen

Lege `/etc/switchly/switchly.env` an:

```env
TZ=Europe/Berlin
SWITCHLY_DB_PATH=/var/lib/switchly/database.sqlite
SWITCHLY_ADMIN_USER=admin
SWITCHLY_ADMIN_PASSWORD=
SWITCHLY_BASE_URL=https://monitor.example.de
SWITCHLY_MONITOR_ENABLED=1
SWITCHLY_MONITOR_INTERVAL=60
SWITCHLY_LOG_RETENTION_DAYS=90
```

Schütze die Datei:

```bash
sudo chown root:www-data /etc/switchly/switchly.env
sudo chmod 640 /etc/switchly/switchly.env
```

Damit Apache diese Variablen erhält:

```bash
sudo systemctl nano apache2
```

Inhalt des Overrides:

```ini
[Service]
EnvironmentFile=/etc/switchly/switchly.env
```

Anschließend:

```bash
sudo systemctl daemon-reload
sudo systemctl restart apache2
```

### 5.4 Apache VirtualHost

Lege `/etc/apache2/sites-available/switchly.conf` an:

```apache
<VirtualHost *:80>
    ServerName monitor.example.de
    DocumentRoot /var/www/switchly/public

    <Directory /var/www/switchly/public>
        Options -Indexes
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
    </Directory>

    <FilesMatch "(^\.|\.env$|\.sqlite(?:-wal|-shm)?$|\.swb$)">
        Require all denied
    </FilesMatch>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "same-origin"

    ErrorLog ${APACHE_LOG_DIR}/switchly-error.log
    CustomLog ${APACHE_LOG_DIR}/switchly-access.log combined
</VirtualHost>
```

Aktivieren:

```bash
sudo a2ensite switchly.conf
sudo a2dissite 000-default.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

### 5.5 Hintergrund-Worker mit systemd

Lege `/etc/systemd/system/switchly-worker.service` an:

```ini
[Unit]
Description=Switchly background worker
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/switchly
EnvironmentFile=/etc/switchly/switchly.env
ExecStart=/usr/bin/php /var/www/switchly/bin/monitor.php
```

Lege `/etc/systemd/system/switchly-worker.timer` an:

```ini
[Unit]
Description=Run Switchly every 60 seconds

[Timer]
OnBootSec=5s
OnUnitActiveSec=60s
AccuracySec=1s
Persistent=true
Unit=switchly-worker.service

[Install]
WantedBy=timers.target
```

Aktivieren und testen:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now switchly-worker.timer
sudo systemctl start switchly-worker.service
systemctl list-timers switchly-worker.timer
sudo systemctl status switchly-worker.service
sudo journalctl -u switchly-worker.service -n 100
```

Bei Bare Metal steuert der systemd-Timer das tatsächliche Intervall. `SWITCHLY_MONITOR_INTERVAL` muss auf denselben Wert gesetzt werden, damit die GUI die Frische des Workers korrekt beurteilt. Zum Abschalten müssen sowohl `SWITCHLY_MONITOR_ENABLED=0` gesetzt als auch der Timer deaktiviert werden:

```bash
sudo systemctl disable --now switchly-worker.timer
```

## 6. Reverse Proxy und HTTPS

HTTPS wird für produktive Installationen, E-Mail-Verifikationslinks und OIDC dringend empfohlen.

Beispiel für Nginx vor dem Docker-Port:

```nginx
server {
    listen 443 ssl http2;
    server_name monitor.example.de;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Setze in `.env` beziehungsweise der systemd-Umgebung:

```env
SWITCHLY_BASE_URL=https://monitor.example.de
```

Die Anwendung kann Host, `X-Forwarded-Host`, `X-Forwarded-Proto` und gegebenenfalls `X-Forwarded-Prefix` automatisch auswerten. Eine fest gesetzte Basis-URL vermeidet jedoch falsche Links in E-Mails. Die OIDC-Basis-URL wird unabhängig davon in der CMS-GUI gespeichert.

## 7. Umgebungsvariablen

| Variable | Standard | Bedeutung |
|---|---:|---|
| `SWITCHLY_HTTP_PORT` | `8080` | Veröffentlichter Docker-Port |
| `TZ` | `Europe/Berlin` | Zeitzone für PHP und Logs |
| `SWITCHLY_DB_PATH` | im Docker fest gesetzt | Pfad der SQLite-Datenbank |
| `SWITCHLY_ADMIN_USER` | `admin` | Benutzername bei der erstmaligen Benutzeranlage |
| `SWITCHLY_ADMIN_PASSWORD` | leer | optional; leer erzeugt ein sicheres Zufallspasswort |
| `SWITCHLY_BASE_URL` | leer | Öffentliche URL für Verifikationsmails |
| `SWITCHLY_MONITOR_ENABLED` | `1` | Docker-Worker aktivieren und GUI-Status |
| `SWITCHLY_MONITOR_INTERVAL` | `60` | Worker-Intervall in Sekunden, Minimum 15 |
| `SWITCHLY_LOG_RETENTION_DAYS` | `90` | automatische Log-Aufbewahrung, 1 bis 3650 Tage |

OpenID Connect und SMTP werden vollständig in der CMS-GUI konfiguriert. Die zugehörigen Zugangsdaten werden nicht aus `.env` gelesen.

## 8. Ersteinrichtung im CMS

1. Mit dem in `.env` festgelegten initialen Administrator anmelden.
2. Unter **Profil & 2FA** sofort das Passwort ändern.
3. Optional TOTP-2FA aktivieren.
4. Unter **Switches** Geräte mit ID, Name, Host, Port und Digest-Zugangsdaten anlegen.
5. Mit **Aktualisieren** eine Live-Abfrage testen.
6. SMTP konfigurieren und Benutzer-E-Mail-Verifikation testen.
7. Unter **Alerts** Empfänger oder Webhook eintragen und **Test senden** verwenden.
8. Gruppen, Benutzerrechte und Switch-Zuordnungen einrichten.
9. Optional OpenID Connect in der GUI konfigurieren und Discovery testen.

## 9. Funktionsbeschreibung

### 9.1 Dashboard und Switch-Übersicht

Die Übersicht zeigt alle für den Benutzer freigegebenen Switches mit Online-Status, Host, Modell, Uptime, Portanzahl und aktiven Ports. Sie verwendet den SQLite-Cache. Der Browser liest den Cache periodisch im Hintergrund; es gibt keine irreführende feste Zusage, dass jedes Gerät exakt in diesem Browserintervall live abgefragt wird.

### 9.2 Switch- und Portdetails

Beim Öffnen eines Switches werden Systemdaten, Portzustände, Namen, Ist-Geschwindigkeit, Duplex, Auto-Negotiation, Zähler und Fehler angezeigt. **Aktualisieren** erzwingt eine Live-Abfrage. Normale Seitenaktualisierungen verwenden den Worker-Cache.

Die Speed-Codes der derzeitigen SwitchOS-Implementierung sind:

| Code | Geschwindigkeit |
|---:|---|
| `0` | 10 Mbit/s |
| `1` | 100 Mbit/s |
| `2` | 1 Gbit/s |
| `3` | 10 Gbit/s |
| `4` | 2,5 Gbit/s |
| `5` | 5 Gbit/s |
| `6` | 25 Gbit/s |

Welche Werte angeboten werden, hängt vom Porttyp ab. Bei deaktivierter Auto-Negotiation wird Full Duplex gesetzt. Nicht jedes SFP/QSFP-Modul oder jede SwitchOS-Version akzeptiert jede Geschwindigkeit.

### 9.3 LAG/LACP

LAG wird live über `/lacp.b` verwaltet. Pro Port stehen zur Verfügung:

- `0`: Passive LACP;
- `1`: Active LACP;
- `2`: Static;
- Gruppe `0`: keine Gruppe;
- Gruppen `1` bis `16`: Aggregationsgruppe.

Die Oberfläche zeigt außerdem Trunk- und Partnerinformationen, soweit sie vom Switch geliefert werden.

### 9.4 VLAN

Port-VLAN-Daten kommen von `/fwd.b`, die VLAN-Tabelle von `/vlan.b`.

VLAN-Modi:

| Wert | Modus |
|---:|---|
| `0` | Disabled |
| `1` | Optional |
| `2` | Enabled |
| `3` | Strict |

Receive-Modi:

| Wert | Modus |
|---:|---|
| `0` | Any |
| `1` | Only tagged |
| `2` | Only untagged |

Pro Port können PVID `1` bis `4095`, Modus, Receive-Modus und Force VLAN ID gesetzt werden. VLAN-Tabelleneinträge unterstützen VLAN-ID `1` bis `4094`, Namen bis 32 Zeichen und eine Port-Mitgliedsmaske. Die Anwendung schreibt IDs im von SwitchOS erwarteten Hexformat und liest den Wert danach zurück.

### 9.5 Reboot und Config Backup/Restore

- Reboot sendet `POST *` an `/reboot`.
- Backup lädt `/backup.swb` herunter.
- Restore lädt eine `.swb`-Datei mit maximal 16 MiB auf `/backup.swb` hoch.
- Nach erfolgreichem Restore sendet das CMS automatisch einen Reboot-Befehl.

Während des Neustarts wird der Switch vorübergehend als offline erscheinen.

### 9.6 Benutzer, E-Mail und 2FA

- Benutzername, Rolle, E-Mail und Passwort verwalten;
- E-Mail ist optional, muss nach Eintragung aber bestätigt werden;
- Verifikations-Token sind zufällig, nur gehasht gespeichert und 24 Stunden gültig;
- responsive HTML-Mail plus Text-Fallback;
- TOTP mit üblichen Authenticator-Apps;
- Administratoren können 2FA zurücksetzen;
- Benutzer können ihr Passwort und ihre eigene 2FA im Profil verwalten.

### 9.7 Berechtigungen und Gruppen

Administratoren besitzen immer alle Rechte. Das Recht `switch.control` schließt Reboot, Backup, Restore, Port-, LAG- und VLAN-Verwaltung ein.

| Recht | Funktion |
|---|---|
| `dashboard.view` | Dashboard anzeigen |
| `switches.view` | Switches und Portstatus anzeigen |
| `switches.manage` | Switches anlegen, bearbeiten, löschen |
| `switch.control` | alle Switch-Wartungsaktionen |
| `switch.reboot` | Switch neu starten |
| `switch.backup` | Konfiguration herunterladen |
| `switch.restore` | Konfiguration wiederherstellen |
| `switch.port.manage` | Portzustand, Name, Auto-Negotiation, Speed |
| `switch.lag.manage` | LAG/LACP verwalten |
| `switch.vlan.manage` | VLANs und Port-Zuordnungen verwalten |
| `monitoring.view` | Monitoring-Historie anzeigen |
| `alerts.manage` | Alerts verwalten |
| `logs.view` | CMS- und Switch-Logs anzeigen |
| `users.manage` | Benutzer verwalten |
| `permissions.manage` | Rechte und Gruppen verwalten |
| `sso.manage` | OpenID Connect verwalten |

Direkte Benutzerrechte haben Vorrang, danach gelten Gruppenrechte. Noch nicht konfigurierte ältere Standardbenutzer erhalten aus Kompatibilitätsgründen `dashboard.view`, `switches.view` und `monitoring.view`.

Für Switch-Zugriff gilt:

- keine direkte oder gruppenbasierte Switch-Zuordnung: Zugriff auf alle Switches;
- mindestens eine Zuordnung: Zugriff nur auf die Vereinigungsmenge der direkten und gruppenbasierten Zuordnungen.

### 9.8 Monitoring und Logs

Der Worker schreibt pro Lauf und Switch:

- Online-/Offline-Zustand;
- Anzahl aktiver und vorhandener Ports;
- RX-/TX-Bytes;
- Fehlerzahl;
- Antwortzeit;
- Zeitstempel.

Monitoring-Samples werden 30 Tage aufbewahrt. Logs werden gemäß `SWITCHLY_LOG_RETENTION_DAYS` bereinigt. Statuswechsel erzeugen Switch-Logs. Die Logansicht kann CMS, gesamten Stack oder einen Switch sowie Level und Zeitraum filtern.

### 9.9 Alerts

Ein Down-Alert wird erst nach der konfigurierten Anzahl aufeinanderfolgender Fehler ausgelöst. Der Cooldown verhindert wiederholte Meldungen. Optional wird beim Wiederkehren des Switches eine Recovery-Nachricht gesendet.

E-Mail verwendet die in der Alerts-Oberfläche gespeicherten SMTP-Einstellungen. Discord-Webhook-URLs werden erkannt und als Embed formatiert. Andere Webhooks erhalten JSON. Ein optionales Secret wird als `X-Switchly-Webhook-Token` übertragen. Für bestehende Integrationen sendet Switchly vorübergehend zusätzlich den früheren Header `X-SwitchOS-Webhook-Token`.

Beispiel eines generischen Webhook-Payloads:

```json
{
  "source": "Switchly",
  "application": {
    "name": "Switchly",
    "version": "1.4.7",
    "channel": "Beta",
    "display_version": "v1.4.7 Beta"
  },
  "event": "switch.down",
  "timestamp": "2026-08-21T12:00:00+00:00",
  "switch": {
    "id": "core-1",
    "name": "Core Switch",
    "host": "192.168.178.71"
  },
  "online": false,
  "detail": "Zeitüberschreitung beim Verbindungsaufbau"
}
```

Beim Recovery lautet `event` entsprechend `switch.recovered` und `online` ist `true`.

<a id="de-openid-connect"></a>

### 9.10 OpenID Connect mit Keycloak oder anderem IdP

Das CMS unterstützt genau einen generischen OpenID-Connect-Anbieter. Die Einrichtung erfolgt nach lokaler Admin-Anmeldung unter **OpenID Connect**.

Typische Keycloak-Discovery-URL:

```text
https://keycloak.example.de/realms/mein-realm/.well-known/openid-configuration
```

Callback-URL:

```text
https://monitor.example.de/sso.php
```

Unterstützt werden:

- Authorization Code Flow mit PKCE S256;
- `state` und `nonce`;
- Confidential Client mit `client_secret_post` oder `client_secret_basic`;
- Public Client ohne Secret;
- Discovery und JWKS;
- RSA-signierte ID-Tokens mit RS256, RS384 oder RS512;
- Prüfung von Issuer, Audience, `azp`, Ablaufzeit und UserInfo-Subject;
- konfigurierbare Claim-Namen;
- optionale automatische Benutzeranlage;
- optionale Einschränkung auf E-Mail-Domains.

Standardmäßig wird ein vorhandener lokaler Benutzer über exakt gleiche E-Mail-Adresse verknüpft. Automatisch erstellte Konten erhalten zunächst keine Rechte. Eine lokal aktivierte 2FA wird auch nach OIDC-Login abgefragt.

Unsicheres HTTP oder deaktivierte TLS-Prüfung sollte nur in isolierten Testnetzen verwendet werden.

## 10. Hintergrund-Worker

### Docker-Verhalten

Der Docker-Entrypoint startet bei `SWITCHLY_MONITOR_ENABLED=1` sofort einen Lauf und danach eine Schleife. Das Intervall beträgt mindestens 15 Sekunden und standardmäßig 60 Sekunden.

Der Worker:

- fragt alle Switches ab;
- aktualisiert `switch_cache`;
- schreibt `monitoring_samples`;
- aktualisiert `alert_states`;
- löst E-Mail/Webhook-Alerts aus;
- schreibt Statuswechsel in `system_logs`;
- aktualisiert `background_job_status`;
- bereinigt alte Monitoring-Samples und Logs;
- verwendet eine Sperrdatei, damit nicht zwei Läufe parallel arbeiten.

Die Anzeige **noch kein Lauf** bedeutet, dass in `background_job_status` noch kein erfolgreicher oder fehlgeschlagener Start registriert wurde. Bei Docker sollten direkt nach dem Start innerhalb weniger Sekunden Daten erscheinen.

Diagnose:

```bash
docker compose logs --tail=200 switchly
docker compose exec switchly php /var/www/html/bin/monitor.php
curl http://127.0.0.1:8080/api/health.php
```

## 11. Direkte SwitchOS-Geräte-Endpunkte

Diese Calls umgehen das CMS und sprechen den Switch unmittelbar an. Sie sind nützlich zur Diagnose. Zugangsdaten müssen entsprechend ersetzt werden.

### 11.1 Lesen

```bash
curl --digest -u 'admin:SWITCH-PASSWORT' http://192.168.178.71/sys.b
curl --digest -u 'admin:SWITCH-PASSWORT' http://192.168.178.71/link.b
curl --digest -u 'admin:SWITCH-PASSWORT' http://192.168.178.71/stats.b
curl --digest -u 'admin:SWITCH-PASSWORT' http://192.168.178.71/lacp.b
curl --digest -u 'admin:SWITCH-PASSWORT' http://192.168.178.71/fwd.b
curl --digest -u 'admin:SWITCH-PASSWORT' http://192.168.178.71/vlan.b
curl --digest -u 'admin:SWITCH-PASSWORT' -o switch-backup.swb \
  http://192.168.178.71/backup.swb
```

| Pfad | Verwendung im CMS |
|---|---|
| `/sys.b` | System, Modell, Firmware, Uptime, Temperatur |
| `/link.b` | Portstatus, Namen, Enable-Maske, Auto-Negotiation, Speed |
| `/stats.b` | Traffic- und Fehlerzähler |
| `/lacp.b` | LAG/LACP |
| `/fwd.b` | Port-VLAN-Einstellungen |
| `/vlan.b` | VLAN-Tabelle |
| `/backup.swb` | Config Backup und Restore |
| `/reboot` | Neustart |

Leere Antworten von `/lag.b` oder `/vlans.b` sind bei diesem Gerät kein CMS-Fehler: Für CRS3xx/CSS3xx mit SwitchOS 2.x verwendet das Projekt `/lacp.b`, `/fwd.b` und `/vlan.b`.

### 11.2 Reboot und Restore

```bash
curl --digest -u 'admin:SWITCH-PASSWORT' \
  -X POST -d '*' http://192.168.178.71/reboot

curl --digest -u 'admin:SWITCH-PASSWORT' \
  -F 'file=@switch-backup.swb' http://192.168.178.71/backup.swb
```

Direkte Schreibzugriffe auf `.b`-Dateien erfordern die vollständige von SwitchOS erwartete Struktur. Das CMS liest deshalb zuerst den aktuellen Inhalt, ändert gezielt Felder und sendet die Struktur zurück. Manuelle Teil-Payloads können je nach Firmware andere Werte überschreiben und sollten nur an Testgeräten verwendet werden.

## 12. Datenbank und gespeicherte Daten

SQLite verwendet WAL-Modus und einen Busy-Timeout. Wichtige Tabellen:

| Tabelle | Inhalt |
|---|---|
| `users` | lokale Benutzer, Passwort-Hash, E-Mail, 2FA |
| `sso_identities` | OIDC-Subject-Zuordnung |
| `oidc_settings` | OIDC-Konfiguration |
| `switches` | Switch-Adressen und Digest-Zugangsdaten |
| `switch_cache` | letzter Status, System- und Portdaten |
| `monitoring_samples` | historische Messwerte |
| `system_logs` | CMS- und Switch-Logs |
| `background_job_status` | letzter Worker-Lauf und Heartbeat |
| `alert_settings` | Alert-Kanäle und Schwellenwerte |
| `alert_states` | Fehlerzähler und zuletzt gesendete Alerts |
| `permission_groups` | Gruppen |
| `group_members` | Gruppenmitglieder |
| `user_permissions` | direkte Benutzerrechte |
| `group_permissions` | Gruppenrechte |
| `user_switch_access` | direkte Switch-Freigaben |
| `group_switch_access` | Switch-Freigaben für Gruppen |

Passwörter lokaler Benutzer werden gehasht. Switch-Passwörter, OIDC-Client-Secret, TOTP-Secrets und Webhook-Secret müssen zur Laufzeit entschlüsselbar beziehungsweise direkt nutzbar sein und liegen daher in der SQLite-Datei. Die Datenbank und Backups sind als vertraulich zu behandeln.

## 13. Backup und Restore des CMS

### Docker – konsistentes Offline-Backup

```bash
docker compose stop
tar -czf switchly-data-$(date +%F).tar.gz data .env
docker compose start
```

Für ein vollständiges Disaster-Recovery-Backup sollten Projektdateien, `.env` und `data/` gesichert werden.

Restore:

```bash
docker compose down
tar -xzf switchly-data-2026-08-21.tar.gz
docker compose up -d --build
```

### Bare Metal

Worker und Apache für ein einfaches Offline-Backup kurz stoppen:

```bash
sudo systemctl stop switchly-worker.timer
sudo systemctl stop apache2
sudo tar -czf /root/switchly-backup-$(date +%F).tar.gz \
  /var/lib/switchly /etc/switchly /var/www/switchly
sudo systemctl start apache2
sudo systemctl start switchly-worker.timer
```

Alternativ kann das Paket `sqlite3` mit dem SQLite-Befehl `.backup` für ein Online-Datenbankbackup verwendet werden.

## 14. Updates

1. Backup von Datenbank und Konfiguration erstellen.
2. Bei Docker das neue Projekt über die alten Programmdateien kopieren, aber `.env` und `data/` beibehalten.
3. `docker compose up -d --build` ausführen.
4. Bei Bare Metal neue Programmdateien einspielen, Eigentümer und Rechte prüfen, Apache und Worker neu starten.
5. `/api/health.php`, Login, manuellen Switch-Refresh und Worker-Status testen.

Das Schema wird beim ersten Zugriff automatisch erweitert. Bestehende Tabellen und Daten werden dabei nicht absichtlich gelöscht.

## 15. Sicherheitsempfehlungen

- CMS ausschließlich per HTTPS veröffentlichen.
- Starkes initiales Admin-Passwort setzen und danach über das Profil ändern.
- Das initiale Passwort muss mindestens 12 Zeichen lang sein; ohne diesen Wert wird keine leere Datenbank initialisiert.
- 2FA für Administratoren aktivieren.
- OIDC-Client-Secret, SMTP-Passwort, `.env` und SQLite-Datei schützen.
- `data/` niemals in ein öffentliches Repository übertragen.
- CMS-Zugriff nach Möglichkeit durch Firewall oder VPN begrenzen.
- Für Switches einen eigenen Management-Benutzer verwenden, soweit SwitchOS dies unterstützt.
- Regelmäßige Backups und Restore-Tests durchführen.
- Rechte nach dem Minimalprinzip vergeben.
- Unsicheres OIDC-HTTP und deaktivierte TLS-Prüfung nicht produktiv verwenden.
- Generische Webhook-Secrets regelmäßig wechseln.
- Die interne Session-API nicht ungeprüft als öffentliche Drittanbieter-API exponieren.

## 16. Fehlerdiagnose

### Worker zeigt „noch kein Lauf“

```bash
docker compose ps
docker compose logs --tail=200 switchly
docker compose exec switchly php /var/www/html/bin/monitor.php
curl http://127.0.0.1:8080/api/health.php
```

Prüfen:

- `SWITCHLY_MONITOR_ENABLED=1` exakt gesetzt;
- Container nach Variablenänderung neu erstellt;
- Datenbankverzeichnis für `www-data` beschreibbar;
- PHP CLI vorhanden;
- Switches vom Container erreichbar.

### CMS ist langsam

- Worker-Status und Cache prüfen;
- nicht erreichbare Switches im Worker-Log suchen;
- normale Ansicht statt dauernder manueller Live-Abfrage verwenden;
- `SWITCHLY_MONITOR_INTERVAL` sinnvoll, beispielsweise 60 Sekunden, wählen;
- Ressourcen und SQLite-Dateirechte des Hosts prüfen.

### Switch ist offline

```bash
curl --digest -u 'admin:SWITCH-PASSWORT' http://SWITCH-IP/sys.b
```

Dasselbe aus dem Container testen. Host, Port, Benutzer, Passwort, Routing und Firewall kontrollieren.

### VLAN oder LAG leer

- direkt `/lacp.b`, `/fwd.b` und `/vlan.b` testen;
- nicht `/lag.b` oder `/vlans.b` voraussetzen;
- SwitchOS-Firmware und Modell prüfen;
- nach Schreibaktionen Konfiguration erneut live laden;
- Änderungen zunächst an einem Test-Switch ausprobieren.

### Port-Speed stimmt nicht

- `spd` ist in der Regel die aktuelle Geschwindigkeit;
- `spdc` ist die konfigurierte Geschwindigkeit;
- bei Link-Down kann die aktuelle Geschwindigkeit leer oder 0 sein;
- Auto-Negotiation, Modulunterstützung und Porttyp prüfen;
- nach Änderung eine Live-Aktualisierung durchführen.

### Discord oder Webhook sendet nicht

- unter **Alerts** die Gesamtfunktion und Webhook aktivieren;
- **Test senden** verwenden;
- aus dem Container die Ziel-URL erreichen können;
- Proxy, DNS und TLS-Fehler in den CMS-Logs prüfen;
- Discord-URL vollständig und unverändert eintragen;
- Failure Threshold beachten: ein einzelner Fehlversuch löst bei Schwelle 2 noch keinen Down-Alert aus.

### E-Mail-Link zeigt falschen Host

```env
SWITCHLY_BASE_URL=https://monitor.example.de
```

Zusätzlich Proxy-Header `Host`, `X-Forwarded-Host` und `X-Forwarded-Proto` korrekt weiterreichen und Container oder Apache neu starten.

### OIDC funktioniert nicht

- Discovery-URL im Browser oder mit curl prüfen;
- Callback-URL exakt im IdP erlauben;
- öffentliche CMS-URL in der OIDC-GUI korrekt setzen;
- Client-Typ und `client_secret_post`, `client_secret_basic` oder Public Client passend wählen;
- `openid` in den Scopes belassen;
- Claims, erlaubte Domains und E-Mail-Vertrauen prüfen;
- Zeitabweichung zwischen CMS und IdP vermeiden;
- produktiv gültige TLS-Zertifikate verwenden.

### HTTP 419

Session oder CSRF-Token ist abgelaufen. Neu anmelden, Dashboard neu laden, neues `data-csrf` lesen und Request wiederholen.

### SQLite „readonly“, „locked“ oder keine Daten

- Pfad in `SWITCHLY_DB_PATH` prüfen;
- Eigentümer und Schreibrechte des Verzeichnisses prüfen;
- bei Docker das Volume `switchly_data` und die Container-UID 33 beachten;
- keine Datenbankkopie ohne zugehörigen sauberen WAL-Zustand im laufenden Betrieb erstellen;
- bei Restore Webserver und Worker stoppen.

## 17. Bekannte Grenzen

- Die API verwendet Browser-Session und CSRF, keine separaten API-Tokens.
- Nur ein generischer OIDC-Anbieter ist gleichzeitig konfigurierbar.
- Google-, GitHub- oder Microsoft-spezifische OAuth-Adapter sind nicht enthalten; ein standardkonformer OIDC-Provider wie Keycloak ist vorgesehen.
- Direkte SwitchOS-Kompatibilität kann zwischen Modellen und Firmwareständen variieren.
- Das CMS verwendet für Switch-Zugriffe HTTP Digest über HTTP; die Management-Strecke sollte deshalb in einem geschützten Netz oder VPN liegen.
- VLAN- und LAG-Konfigurationen werden live gelesen, aber nicht historisch versioniert.
- Monitoring-Aufbewahrung ist derzeit fest auf 30 Tage gesetzt.
- Der Health-Endpunkt meldet die Webanwendung mit `status:ok`; Worker-Frische muss separat ausgewertet werden.

## 18. Support-Checkliste für Änderungen

Vor produktiven Änderungen an Switch-Konfigurationen:

1. aktuelle `.swb`-Konfiguration herunterladen;
2. CMS-Datenbank sichern;
3. Änderung an einem Testport oder Test-Switch durchführen;
4. Konfiguration live neu laden;
5. Link, VLAN, LAG und Management-Erreichbarkeit prüfen;
6. erst danach auf weitere Geräte übertragen.

## 19. Häufig gestellte Fragen

<details>
<summary><strong>Welches Konto verwende ich nach der Installation?</strong></summary>

Das Konto stammt aus `SWITCHLY_ADMIN_USER` und `SWITCHLY_ADMIN_PASSWORD`. Diese Werte werden ausschließlich beim Erzeugen einer leeren Datenbank verwendet. Das Passwort muss mindestens 12 Zeichen lang sein.

</details>

<details>
<summary><strong>Bleiben Daten bei einem Container-Update erhalten?</strong></summary>

Ja, wenn `.env` und das gemountete Verzeichnis `data/` beibehalten werden. Vor einem Update trotzdem immer ein Backup erstellen und nach dem Neustart Worker sowie Health-Endpunkt kontrollieren.

</details>

<details>
<summary><strong>Warum reagiert das Dashboard trotz eines Offline-Switches schnell?</strong></summary>

Die normale Oberfläche liest aus dem SQLite-Cache. Der unabhängige Worker übernimmt langsame Geräteabfragen im Hintergrund. Nur eine manuelle Live-Aktualisierung wartet direkt auf das Gerät.

</details>

<details>
<summary><strong>Warum sehe ich keine VLAN- oder LAG-Daten?</strong></summary>

Prüfe Modell, Firmware und die Endpunkte `/lacp.b`, `/fwd.b` und `/vlan.b`. Nicht jede Firmware liefert dieselben Felder oder unterstützt alle Schreiboperationen.

</details>

Das ausführliche FAQ mit Installations-, Backup-, OIDC-, Webhook- und Sicherheitsthemen befindet sich unter [faq.md](faq.md).

## 20. Lizenz

Switchly wird unter der **GNU General Public License Version 3.0** veröffentlicht (`GPL-3.0-only`). Der vollständige, unveränderte Lizenztext befindet sich in [`LICENSE`](../LICENSE).

Beiträge und weitergegebene Änderungen unterliegen den Bedingungen der GPLv3. MikroTik und SwitchOS sind Marken ihrer jeweiligen Eigentümer; Switchly ist nicht mit MikroTik verbunden und wird nicht von MikroTik unterstützt.

---

<div align="center">
  <sub><a href="../README.md">← Zur Projektübersicht</a> · <a href="README.md">Dokumentationszentrum</a> · <a href="#schnellnavigation">Nach oben ↑</a></sub>
</div>

---

<a id="english"></a>

> [Deutsch](#deutsch) | **English**

![Switchly Network Control Center](images/readme-banner.png)


<div align="center">
  <h1>Complete documentation</h1>
  <p><strong>Project version v1.4.7 Beta · Last updated 7 September 2026</strong></p>

  <p>
    <a href="../README.md#english">Project overview</a> ·
    <a href="README.md#english">Documentation overview</a> ·
    <a href="faq.md#english">FAQ</a> ·
    <a href="../SECURITY.md#english">Security</a>
  </p>
</div>

This documentation covers Switchly installation, operation, features, permissions, background monitoring, OpenID Connect, backups, and HTTP endpoints.

> [!TIP]
> For an initial installation, follow the [quick start](../README.md#en-quick-start). For specific problems, the [FAQ](faq.md#english) will usually lead you to the right solution faster.

<a id="en-quick-navigation"></a>

## Quick navigation

| Getting started | Configuration |
| --- | --- |
| [Project overview](#en-1-project-overview) | [Environment variables](#en-7-environment-variables) |
| [Install with Docker](#en-4-installation-with-docker) | [Features](#en-9-feature-description) |
| [Install on bare metal](#en-5-bare-metal-installation-on-linux) | [Permissions and groups](#en-97-permissions-and-groups) |
| [Configure HTTPS](#en-6-reverse-proxy-and-https) | [OpenID Connect](#en-openid-connect) |

<a id="en-1-project-overview"></a>

## 1. Project overview

Switchly is a self-hosted web control panel for MikroTik switches with SwitchOS. The application consists of:

- Apache and PHP;
- a SQLite database;
- a browser dashboard;
- an independent background worker;
- direct HTTP Digest connections to the managed SwitchOS devices.

The web server provides the user interface and the internal JSON API. The worker checks all registered switches regularly, updates the local cache, writes monitoring samples and triggers failure or recovery alerts. The dashboard reads from SQLite for normal updates and therefore remains responsive even with slow or unreachable switches.

<a id="en-main-supported-functions"></a>

### Supported features

- centrally manage multiple SwitchOS switches;
- view online status, model, firmware, uptime, and active ports;
- display port status, port names, traffic counters, and errors;
- enable, disable, and rename ports;
- enable or disable auto-negotiation and configure port speed;
- manage LAG/LACP per port as passive LACP, active LACP, or static;
- create, edit, and delete VLAN table entries;
- manage VLAN member ports, PVID, VLAN mode, receive mode, and Force VLAN ID;
- restart switches;
- download and restore the SwitchOS configuration as an `.swb` file;
- reboot the switch automatically after a successful configuration restore;
- manage users, email verification, and TOTP-based 2FA;
- allow administrators to reset a user’s 2FA;
- control user and group permissions and access to individual switches;
- view monitoring data for an individual switch or the entire stack;
- filter CMS, stack, and switch logs;
- send outage and recovery alerts by email, generic webhook, or Discord;
- use generic OpenID Connect, for example with Keycloak;
- perform background monitoring without keeping a browser open.

<a id="en-2-technical-operation"></a>

## 2. How it works

1. The browser authenticates itself to the CMS via a PHP session or OpenID Connect.
2. The dashboard reads overview data from the local SQLite cache.
3. The background worker queries `/sys.b`, `/link.b`, and `/stats.b` on every switch using HTTP Digest authentication.
4. The cache, monitoring history, worker status, alert states, and logs are stored in SQLite.
5. Selecting **Refresh** triggers a direct live query of the selected switch.
6. Configuration actions are sent directly to the switch.

VLAN and LAG configurations are read live from the device. They are not periodically stored in separate history tables.

<a id="en-3-conditions"></a>

## 3. Requirements

<a id="en-network"></a>

### Network

- The server or Docker container must reach the management IP of each switch.
- By default, unencrypted HTTP is used on the configured switch port.
- The CMS authenticates to each switch using HTTP Digest authentication.
- Firewalls must allow outgoing access from the CMS to the switches.
- For SMTP, webhooks, and OIDC, the server must be able to reach the respective external endpoints.

<a id="en-docker"></a>

### Docker

- Docker Engine;
- Docker Compose v2 with the command `docker compose`;
- Recommended: a current Debian, Ubuntu, or another supported Linux distribution.

<a id="en-bare-metal"></a>

### Bare metal

- Linux with Apache 2.4;
- PHP 8.5;
- PHP extensions `pdo_sqlite`, `curl`, `openssl`, `session`, `json` and `opcache`;
- PHP CLI for the background worker;
- The web-server user must have write access to the database directory.

<a id="en-4-installation-with-docker"></a>

## 4. Installation with Docker

<a id="en-41-unpacking-the-project"></a>

### 4.1 Extract the project

```bash
unzip switchly.zip
cd switchly
cp .env.example .env
```

Then edit `.env`. A custom initial administrator password is optional:

```env
SWITCHLY_ADMIN_USER=admin
SWITCHLY_ADMIN_PASSWORD=
```

These variables create the initial user only for an empty database. If the password is empty, Switchly generates it securely and prints it once to the startup log. For an existing database, `.env` changes neither the password nor the role of any account.

<a id="en-42-launch-of-containers"></a>

### 4.2 Start the container

```bash
docker compose up -d --build
```

The CMS can be reached by default at:

```text
http://SERVER-IP:8080
```

Check status and logs:

```bash
docker compose ps
docker compose logs -f switchly
curl http://127.0.0.1:8080/api/health.php
```

<a id="en-43-stop-or-restart-containers"></a>

### 4.3 Stop or restart containers

```bash
docker compose stop
docker compose restart switchly
docker compose down
```

`docker compose down` does not delete the named `switchly_data` volume. Users, settings, and history are preserved.

<a id="en-44-change-web-port"></a>

### 4.4 Change the web port

```env
SWITCHLY_HTTP_PORT=8090
```

Then apply the change:

```bash
docker compose up -d
```

<a id="en-45-test-switch-connectivity-from-the-container"></a>

### 4.5 Test switch connectivity from the container

```bash
docker compose exec switchly \
  curl --digest -u 'admin:SWITCH-PASSWORD' http://192.168.178.71/sys.b
```

For an empty switch password, use `-u 'admin:'`.

<a id="en-46-publish-locally-only"></a>

### 4.6 Optional: publish locally only

When a reverse proxy runs on the same host, restrict the published port to the loopback interface in `docker-compose.yml`:

```yaml
ports:
  - "127.0.0.1:${SWITCHLY_HTTP_PORT:-8080}:8080"
```

<a id="en-47-host-networking-on-linux"></a>

### 4.7 Optional: host networking on Linux

If the Docker bridge network cannot reach the switches, host networking can be tested on Linux. Remove `ports:` and add:

```yaml
network_mode: host
```

Apache then listens directly on port 8080 of the host. This option behaves differently under Docker Desktop for Windows or macOS and is normally unnecessary.

<a id="en-5-bare-metal-installation-on-linux"></a>

## 5. Bare Metal Installation on Linux

The following example applies to Debian or Ubuntu with Apache.

For a compact quick start, the repository contains ready-to-use environment, Apache virtual-host, and systemd service and timer templates under `deploy/bare-metal/`. The executable installation commands are available in the [bare-metal quick start in the README](../README.md#en-quick-bare-metal-start-debianubuntu). This chapter explains the same setup in detail and can be adapted to individual environments.

<a id="en-51-install-packages"></a>

### 5.1 Install packages

```bash
sudo apt update
sudo apt install apache2 libapache2-mod-php php-cli php-curl php-sqlite3 php-opcache curl unzip
sudo a2enmod headers expires
```

Verify the required modules:

```bash
php -m | grep -E 'curl|openssl|PDO|pdo_sqlite|session'
php -v
```

<a id="en-52-create-directories-and-copy-files"></a>

### 5.2 Create directories and copy files

```bash
sudo mkdir -p /var/www/switchly
sudo mkdir -p /var/lib/switchly
sudo mkdir -p /etc/switchly
sudo tar \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.env' \
  --exclude='data' \
  --exclude='deploy' \
  --exclude='docs' \
  --exclude='scripts' \
  -cf - . | sudo tar -xf - -C /var/www/switchly
sudo chown -R root:www-data /var/www/switchly
sudo find /var/www/switchly -type d -exec chmod 750 {} \;
sudo find /var/www/switchly -type f -exec chmod 640 {} \;
sudo chown -R www-data:www-data /var/lib/switchly
sudo chmod 770 /var/lib/switchly
```

The SQLite file should not be in the publicly accessible document root.

<a id="en-53-create-environment-file"></a>

### 5.3 Create environment file

Create `/etc/switchly/switchly.env` with the following content:

```env
TZ=Europe/Berlin
SWITCHLY_DB_PATH=/var/lib/switchly/database.sqlite
SWITCHLY_ADMIN_USER=admin
SWITCHLY_ADMIN_PASSWORD=
SWITCHLY_BASE_URL=https://monitor.example.de
SWITCHLY_MONITOR_ENABLED=1
SWITCHLY_MONITOR_INTERVAL=60
SWITCHLY_LOG_RETENTION_DAYS=90
```

Protect the file:

```bash
sudo chown root:www-data /etc/switchly/switchly.env
sudo chmod 640 /etc/switchly/switchly.env
```

To provide these variables to Apache:

```bash
sudo systemctl edit apache2
```

Use the following override:

```ini
[Service]
EnvironmentFile=/etc/switchly/switchly.env
```

Then:

```bash
sudo systemctl daemon-reload
sudo systemctl restart apache2
```

<a id="en-54-apache-virtualhost"></a>

### 5.4 Apache VirtualHost

Create `/etc/apache2/sites-available/switchly.conf` with the following content:

```apache
<VirtualHost *:80>
    ServerName monitor.example.de
    DocumentRoot /var/www/switchly/public

    <Directory /var/www/switchly/public>
        Options -Indexes
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
    </Directory>

    <FilesMatch "(^\.|\.env$|\.sqlite(?:-wal|-shm)?$|\.swb$)">
        Require all denied
    </FilesMatch>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "same-origin"

    ErrorLog ${APACHE_LOG_DIR}/switchly-error.log
    CustomLog ${APACHE_LOG_DIR}/switchly-access.log combined
</VirtualHost>
```

Enable the site:

```bash
sudo a2ensite switchly.conf
sudo a2dissite 000-default.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

<a id="en-55-background-worker-with-systemd"></a>

### 5.5 Background worker with systemd

Create `/etc/systemd/system/switchly-worker.service` with the following content:

```ini
[Unit]
Description=Switchly background worker
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/switchly
EnvironmentFile=/etc/switchly/switchly.env
ExecStart=/usr/bin/php /var/www/switchly/bin/monitor.php
```

Create `/etc/systemd/system/switchly-worker.timer` with the following content:

```ini
[Unit]
Description=Run Switchly every 60 seconds

[Timer]
OnBootSec=5s
OnUnitActiveSec=60s
AccuracySec=1s
Persistent=true
Unit=switchly-worker.service

[Install]
WantedBy=timers.target
```

Enable and test the timer:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now switchly-worker.timer
sudo systemctl start switchly-worker.service
systemctl list-timers switchly-worker.timer
sudo systemctl status switchly-worker.service
sudo journalctl -u switchly-worker.service -n 100
```

On bare-metal installations, the systemd timer controls the actual interval. `SWITCHLY_MONITOR_INTERVAL` must be set to the same value so that the GUI correctly assesses the freshness of the worker. To disable monitoring, set `SWITCHLY_MONITOR_ENABLED=0` and disable the timer:

```bash
sudo systemctl disable --now switchly-worker.timer
```

<a id="en-6-reverse-proxy-and-https"></a>

## 6. Reverse Proxy and HTTPS

HTTPS is strongly recommended for production installations, email-verification links, and OIDC.

Example of Nginx in front of the Docker port:

```nginx
server {
    listen 443 ssl http2;
    server_name monitor.example.de;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Set in `.env` or the systemd environment:

```env
SWITCHLY_BASE_URL=https://monitor.example.de
```

The application can automatically evaluate `Host`, `X-Forwarded-Host`, `X-Forwarded-Proto`, and, where appropriate, `X-Forwarded-Prefix`. However, setting an explicit base URL prevents incorrect links in emails. The OIDC base URL is configured separately in the administration interface.

<a id="en-7-environment-variables"></a>

## 7. Environment variables

| Variable | Default | Description |
|---|---:|---|
| `SWITCHLY_HTTP_PORT` | `8080` | Published Docker port |
| `TZ` | `Europe/Berlin` | Time zone for PHP and logs |
| `SWITCHLY_DB_PATH` | set by Docker | Path to the SQLite database |
| `SWITCHLY_ADMIN_USER` | `admin` | Username for the initial administrator |
| `SWITCHLY_ADMIN_PASSWORD` | empty | Optional; an empty value generates a secure random password |
| `SWITCHLY_BASE_URL` | empty | Public URL used in verification emails |
| `SWITCHLY_MONITOR_ENABLED` | `1` | Enable the Docker worker and its GUI status |
| `SWITCHLY_MONITOR_INTERVAL` | `60` | Worker interval in seconds, minimum 15 |
| `SWITCHLY_LOG_RETENTION_DAYS` | `90` | Automatic log retention, 1 to 3650 days |

OpenID Connect and SMTP are configured entirely in the administration interface. Their credentials are not read from `.env`.

Environment-variable aliases from releases before the Switchly rebranding have been removed. Existing installations must migrate their `.env` file to the current `SWITCHLY_*` names before upgrading.

<a id="en-8-initial-setup-in-the-cms"></a>

## 8. Initial setup in the CMS

1. Sign in with the initial administrator configured in `.env`.
2. Change the password immediately under **Profile & 2FA**.
3. Optionally activate TOTP-2FA.
4. Under **Switches**, add devices with an ID, name, host, port, and Digest credentials.
5. Use **Refresh** to test a live query.
6. Configure SMTP and test user email verification.
7. Under **Alerts**, enter a recipient or webhook and use **Send test**.
8. Configure groups, user permissions, and switch assignments.
9. Optionally configure OpenID Connect in the interface and test discovery.

<a id="en-9-feature-description"></a>

## 9. Feature description

<a id="en-91-dashboard-and-switch-overview"></a>

### 9.1 Dashboard and switch overview

The overview shows every switch available to the user, including its online status, host, model, uptime, port count, and active ports. It uses the SQLite cache. The browser refreshes this cached view periodically; this does not imply that every device is queried live during each browser refresh.

<a id="en-92-switch-and-port-details"></a>

### 9.2 Switch and port details

Opening a switch displays system data, port states, names, current speed, duplex mode, auto-negotiation, counters, and errors. **Refresh** forces a live request. Normal page updates use the worker cache.

The speed codes of the current SwitchOS implementation are:

| Code | Speed |
|---:|---|
| `0` | 10 Mbit/s |
| `1` | 100 Mbit/s |
| `2` | 1 Gbit/s |
| `3` | 10 Gbit/s |
| `4` | 2.5 Gbit/s |
| `5` | 5 Gbit/s |
| `6` | 25 Gbit/s |

The available values depend on the port type. When auto-negotiation is disabled, full duplex is configured. Not every SFP/QSFP module or SwitchOS version supports every speed.

<a id="en-93-laglacp"></a>

### 9.3 LAG/LACP

LAG is managed live through `/lacp.b`. The following values are available per port:

- `0`: passive LACP;
- `1`: active LACP;
- `2`: static;
- Group `0`: no group;
- Groups `1` to `16`: aggregation group.

The interface also displays trunk and partner information provided by the switch.

<a id="en-94-vlan"></a>

### 9.4 VLAN

Port-VLAN data comes from `/fwd.b`, the VLAN table of `/vlan.b`.

VLAN modes:

| Value | Mode |
|---:|---|
| `0` | Disabled |
| `1` | Optional |
| `2` | Enabled |
| `3` | Strict |

Receive modes:

| Value | Mode |
|---:|---|
| `0` | Any |
| `1` | Only tagged |
| `2` | Only untagged |

Each port supports a PVID from `1` to `4095`, a VLAN mode, a receive mode, and Force VLAN ID. VLAN table entries support VLAN IDs from `1` to `4094`, names of up to 32 characters, and a port membership mask. The application writes IDs in the hexadecimal format expected by SwitchOS and then reads the value back.

<a id="en-95-reboot-and-config-backuprestore"></a>

### 9.5 Reboot and configuration backup/restore

- Reboot sends `POST *` to `/reboot`.
- Backup downloads `/backup.swb`.
- Restore uploads an `.swb` file of up to 16 MiB to `/backup.swb`.
- After successful restoration, the CMS automatically sends a reboot command.

During the reboot, the switch will appear temporarily as offline.

<a id="en-96-users-email-and-2fa"></a>

### 9.6 Users, email and 2FA

- manage username, role, email address, and password;
- email is optional but must be verified when supplied during registration;
- verification tokens are random, stored only as hashes, and valid for 24 hours;
- responsive HTML email with a plain-text fallback;
- TOTP support for standard authenticator applications;
- administrators can reset 2FA;
- users can manage their password and their own 2FA from their profile.

<a id="en-97-permissions-and-groups"></a>

### 9.7 Permissions and groups

Administrators always have every permission. The `switch.control` permission includes reboot, backup, restore, port, LAG, and VLAN management.

| Permission | Function |
|---|---|
| `dashboard.view` | Show the dashboard |
| `switches.view` | Show switches and port statuses |
| `switches.manage` | Create, edit, and delete switches |
| `switch.control` | All switch maintenance actions |
| `switch.reboot` | Restart the switch |
| `switch.backup` | Download configurations |
| `switch.restore` | Restore configurations |
| `switch.port.manage` | Port state, name, auto-negotiation, speed |
| `switch.lag.manage` | Manage LAG/LACP |
| `switch.vlan.manage` | Manage VLANs and port assignments |
| `monitoring.view` | Display monitoring history |
| `alerts.manage` | Manage alerts |
| `logs.view` | View CMS and switch logs |
| `users.manage` | Manage users |
| `permissions.manage` | Manage rights and groups |
| `sso.manage` | Manage OpenID Connect |

Direct user permissions take precedence; group permissions are applied afterwards. For compatibility, older standard users without an explicit configuration receive `dashboard.view`, `switches.view`, and `monitoring.view`.

For switch access:

- no direct or group-based switch assignment: access to all switches;
- at least one assignment: access only to the combined set of direct and group-based assignments.

<a id="en-98-monitoring-and-logs"></a>

### 9.8 Monitoring and logs

The worker writes per run and switch:

- online/offline status;
- number of active and available ports;
- RX/TX bytes;
- number of errors;
- response time;
- timestamp.

Monitoring samples are retained for 30 days. Logs are removed according to `SWITCHLY_LOG_RETENTION_DAYS`. Status changes create switch log entries. The log view can filter by CMS, entire stack, or individual switch, as well as by level and time period.

<a id="en-99-alerts"></a>

### 9.9 Alerts

A down alert is triggered only after the configured number of consecutive errors. The cooldown prevents repeated messages. Optionally, a recovery message is sent when the switch returns.

Email delivery uses the SMTP settings stored through the Alerts interface. Discord webhook URLs are detected and formatted as embeds. Other webhooks receive JSON. An optional secret is sent as `X-Switchly-Webhook-Token`; existing integrations temporarily also receive the legacy `X-SwitchOS-Webhook-Token` header.

Example generic webhook payload:

```json
{
  "source": "Switchly",
  "application": {
    "name": "Switchly",
    "version": "1.4.7",
    "channel": "Beta",
    "display_version": "v1.4.7 Beta"
  },
  "event": "switch.down",
  "timestamp": "2026-08-21T12:00:00+00:00",
  "switch": {
    "id": "core-1",
    "name": "Core Switch",
    "host": "192.168.178.71"
  },
  "online": false,
    "detail": "Connection timed out"
}
```

For a recovery notification, `event` is `switch.recovered` and `online` is `true`.

<a id="en-openid-connect"></a>

### 9.10 OpenID Connect with Keycloak or other IdP

The CMS supports one generic OpenID Connect provider at a time. Configure it under **OpenID Connect** after signing in as a local administrator.

Typical Keycloak discovery URL:

```text
https://keycloak.example.de/realms/mein-realm/.well-known/openid-configuration
```

Callback URL:

```text
https://monitor.example.de/sso.php
```

The following features are supported:

- Authorization Code Flow with PKCE S256;
- `state` and `nonce`;
- confidential clients using `client_secret_post` or `client_secret_basic`;
- public clients without a secret;
- discovery and JWKS;
- RSA-signed ID tokens with RS256, RS384 or RS512;
- validation of issuer, audience, `azp`, expiry time, and the UserInfo subject;
- configurable claim names;
- optional automatic user creation;
- optional restriction to specific email domains.

By default, an existing local user is linked only when the email address matches exactly. Automatically created accounts initially receive no permissions. Locally enabled 2FA is also required after an OIDC sign-in.

Insecure HTTP or disabled TLS verification should be used only in isolated test networks.

<a id="en-10-background-workers"></a>

## 10. Background workers

<a id="en-docker-behavior"></a>

### Docker behavior

When `SWITCHLY_MONITOR_ENABLED=1`, the Docker entry point runs the worker immediately and then starts a loop. The interval is at least 15 seconds and defaults to 60 seconds.

The worker:

- queries all switches;
- updates `switch_cache`;
- writes `monitoring_samples` records;
- updates `alert_states`;
- triggers email/webhook alerts;
- writes status changes to `system_logs`;
- updates `background_job_status`;
- removes expired monitoring samples and logs;
- uses a lock file to prevent concurrent runs.

The message **No run yet** means that `background_job_status` does not yet contain a completed or failed run. With Docker, data should appear within a few seconds of startup.

Diagnostics:

```bash
docker compose logs --tail=200 switchly
docker compose exec switchly php /var/www/html/bin/monitor.php
curl http://127.0.0.1:8080/api/health.php
```

<a id="en-11-direct-switchos-device-endpoints"></a>

## 11. Direct SwitchOS device endpoints

These calls bypass the CMS and communicate directly with the switch. They are useful for diagnosis. Replace the example credentials as required.

<a id="en-111-reading"></a>

### 11.1 Reading

```bash
curl --digest -u 'admin:SWITCH-PASSWORD' http://192.168.178.71/sys.b
curl --digest -u 'admin:SWITCH-PASSWORD' http://192.168.178.71/link.b
curl --digest -u 'admin:SWITCH-PASSWORD' http://192.168.178.71/stats.b
curl --digest -u 'admin:SWITCH-PASSWORD' http://192.168.178.71/lacp.b
curl --digest -u 'admin:SWITCH-PASSWORD' http://192.168.178.71/fwd.b
curl --digest -u 'admin:SWITCH-PASSWORD' http://192.168.178.71/vlan.b
curl --digest -u 'admin:SWITCH-PASSWORD' -o switch-backup.swb \
  http://192.168.178.71/backup.swb
```

| Path | Use in CMS |
|---|---|
| `/sys.b` | System, model, firmware, uptime, temperature |
| `/link.b` | Port status, name, enable mask, auto-negotiation, speed |
| `/stats.b` | Traffic and error counters |
| `/lacp.b` | LAG/LACP |
| `/fwd.b` | Port-VLAN settings |
| `/vlan.b` | VLAN table |
| `/backup.swb` | Config Backup and Restore |
| `/reboot` | Restart |

Empty responses from `/lag.b` or `/vlans.b` are not a CMS error for this device. For CRS3xx/CSS3xx devices running SwitchOS 2.x, the project uses `/lacp.b`, `/fwd.b`, and `/vlan.b`.

<a id="en-112-reboot-and-restore"></a>

### 11.2 Reboot and restore

```bash
curl --digest -u 'admin:SWITCH-PASSWORD' \
  -X POST -d '*' http://192.168.178.71/reboot

curl --digest -u 'admin:SWITCH-PASSWORD' \
  -F 'file=@switch-backup.swb' http://192.168.178.71/backup.swb
```

Direct write requests to `.b` files require the complete structure expected by SwitchOS. The CMS therefore reads the current content first, changes only the required fields, and sends the complete structure back. Depending on the firmware, manually constructed partial payloads may overwrite unrelated values and should be used only on test devices.

<a id="en-12-database-and-stored-data"></a>

## 12. Database and stored data

SQLite uses WAL mode and a busy timeout. Important tables:

| Table | Content |
|---|---|
| `users` | local users, password hash, email, 2FA |
| `sso_identities` | OIDC subject assignment |
| `oidc_settings` | OIDC configuration |
| `switches` | Switch addresses and Digest credentials |
| `switch_cache` | Latest status, system, and port data |
| `monitoring_samples` | Historical measurements |
| `system_logs` | CMS and switch logs |
| `background_job_status` | Last worker run and heartbeat |
| `alert_settings` | Alert channels and thresholds |
| `alert_states` | Error counters and most recently sent alerts |
| `permission_groups` | Groups |
| `group_members` | Group members |
| `user_permissions` | Direct user permissions |
| `group_permissions` | Group rights |
| `user_switch_access` | Direct switch access assignments |
| `group_switch_access` | Switch access assignments for groups |

Local user passwords are hashed. Switch passwords, the OIDC client secret, TOTP secrets, and the webhook secret must be decryptable or directly usable at runtime and are therefore stored in the SQLite file. Treat the database and its backups as confidential.

<a id="en-13-backup-and-restore-the-cms"></a>

## 13. Backup and restore the CMS

<a id="en-docker--consistent-offline-backup"></a>

### Docker – consistent offline backup

```bash
docker compose stop
tar -czf switchly-data-$(date +%F).tar.gz data .env
docker compose start
```

For a complete disaster-recovery backup, save the project files, `.env`, and `data/`.

Restore:

```bash
docker compose down
tar -xzf switchly-data-2026-08-21.tar.gz
docker compose up -d --build
```

<a id="en-bare-metal-backup"></a>

### Bare-metal backup

Briefly stop the worker and Apache for a simple offline backup:

```bash
sudo systemctl stop switchly-worker.timer
sudo systemctl stop apache2
sudo tar -czf /root/switchly-backup-$(date +%F).tar.gz \
  /var/lib/switchly /etc/switchly /var/www/switchly
sudo systemctl start apache2
sudo systemctl start switchly-worker.timer
```

Alternatively, install `sqlite3` and use the SQLite `.backup` command to create an online database backup.

<a id="en-14-updates"></a>

## 14. Updates

1. Back up the database and configuration.
2. With Docker, copy the new project over the existing application files while retaining `.env` and `data/`.
3. Run `docker compose up -d --build`.
4. On bare metal, install the new application files, verify ownership and permissions, and restart Apache and the worker.
5. Test `/api/health.php`, sign-in, a manual switch refresh, and the worker status.

The database schema is extended automatically on first access. Existing tables and data are not intentionally deleted.

<a id="en-15-security-recommendations"></a>

## 15. Security recommendations

- Publish the CMS exclusively over HTTPS.
- Set a strong initial administrator password and then change it from the profile.
- The initial password must be at least 12 characters long; without this value, an empty database is not initialized.
- Enable 2FA for administrators.
- Protect the OIDC client secret, SMTP password, `.env`, and SQLite file.
- Never publish `data/` in a public repository.
- Restrict CMS access with a firewall or VPN whenever possible.
- Use a dedicated management user for switches where supported by SwitchOS.
- Perform regular backups and restore tests.
- Grant permissions according to the principle of least privilege.
- Do not use insecure OIDC over HTTP or disabled TLS verification in production.
- Change generic webhook secrets regularly.
- Do not expose the internal session API as an unreviewed public third-party API.

<a id="en-16-troubleshooting"></a>

## 16. Troubleshooting

<a id="en-worker-shows-no-run-yet"></a>

### Worker shows “no run yet”

```bash
docker compose ps
docker compose logs --tail=200 switchly
docker compose exec switchly php /var/www/html/bin/monitor.php
curl http://127.0.0.1:8080/api/health.php
```

Check the following:

- `SWITCHLY_MONITOR_ENABLED=1` is set exactly as shown;
- the container was recreated after changing the variable;
- the database directory is writable by `www-data`;
- PHP CLI is available;
- the switches are accessible from the container.

<a id="en-cms-is-slow"></a>

### CMS is slow

- check the worker status and cache;
- look for unreachable switches in the worker log;
- use the normal view instead of repeatedly triggering manual live queries;
- choose an appropriate `SWITCHLY_MONITOR_INTERVAL`, such as 60 seconds;
- check the host resources and SQLite file permissions.

<a id="en-switch-is-offline"></a>

### Switch is offline

```bash
curl --digest -u 'admin:SWITCH-PASSWORD' http://SWITCH-IP/sys.b
```

Run the same test from the container. Verify the host, port, username, password, routing, and firewall.

<a id="en-vlan-or-lag-empty"></a>

### VLAN or LAG empty

- test `/lacp.b`, `/fwd.b`, and `/vlan.b` directly;
- do not assume that `/lag.b` or `/vlans.b` is supported;
- check the SwitchOS firmware and switch model;
- reload the configuration live after write operations;
- test changes on a test switch first.

<a id="en-port-speed-is-not-right"></a>

### Port speed is not right

- `spd` is usually the current speed;
- `spdc` is the configured speed;
- at link-down, the current speed may be empty or 0;
- check auto-negotiation, module support, and port type;
- perform a live refresh after making a change.

<a id="en-discord-or-webhook-does-not-send"></a>

### Discord or webhook does not send

- under **Alerts**, enable alerts and the webhook channel;
- use **Send test**;
- verify that the destination URL is reachable from the container;
- check the CMS logs for proxy, DNS, and TLS errors;
- enter the complete Discord URL without modifying it;
- consider the failure threshold: with a threshold of 2, one failed attempt does not trigger a down alert.

<a id="en-email-link-shows-wrong-host"></a>

### Email link shows wrong host

```env
SWITCHLY_BASE_URL=https://monitor.example.de
```

Also forward the proxy headers `Host`, `X-Forwarded-Host`, and `X-Forwarded-Proto` correctly and restart containers or Apache.

<a id="en-oidc-does-not-work"></a>

### OIDC does not work

- check the discovery URL in a browser or with curl;
- allow the exact callback URL in the identity provider;
- configure the public CMS URL correctly in the OIDC interface;
- select the appropriate client type and use `client_secret_post`, `client_secret_basic`, or a suitable public client;
- keep `openid` in the scopes;
- check claims, allowed domains, and email trust;
- avoid clock drift between the CMS and identity provider;
- use valid TLS certificates in production.

<a id="en-http-419"></a>

### HTTP 419

The session or CSRF token has expired. Sign in again, reload the dashboard, read the new `data-csrf` value, and repeat the request.

<a id="en-sqlite-readonly-locked-or-no-data"></a>

### SQLite “readonly”, “locked” or no data

- verify the path in `SWITCHLY_DB_PATH`;
- check the directory owner and write permissions;
- for Docker, check the `switchly_data` volume and container UID 33;
- do not copy the live database without ensuring a consistent WAL state;
- stop the web server and worker before restoring data.

<a id="en-17-known-limitations"></a>

## 17. Known limitations

- The API uses a browser session and CSRF protection rather than separate API tokens.
- Only one generic OIDC provider can be configured at a time.
- Google-, GitHub-, or Microsoft-specific OAuth adapters are not included; use a standards-compliant OIDC provider such as Keycloak.
- Direct SwitchOS compatibility can vary between models and firmware.
- The CMS uses HTTP Digest over HTTP for switch access; the management route should therefore be in a protected network or VPN.
- VLAN and LAG configurations are read live, but not historically versioned.
- Monitoring data is currently retained for 30 days.
- The health endpoint reports the web application as `status:ok`; worker freshness must be evaluated separately.

<a id="en-18-support-checklist-for-changes"></a>

## 18. Support checklist for changes

Before changing switch configurations in production:

1. Download the current `.swb` configuration;
2. Back up the CMS database;
3. Make the change on a test port or test switch;
4. Reload the configuration live;
5. Verify link state, VLAN, LAG, and management connectivity;
6. Only then apply the change to additional devices.

<a id="en-19-frequently-asked-questions"></a>

## 19. Frequently asked questions

<details>
<summary><strong>Which account do I use after installation?</strong></summary>

The account is created from `SWITCHLY_ADMIN_USER` and `SWITCHLY_ADMIN_PASSWORD`. These values are used only when creating an empty database. The password must be at least 12 characters long.

</details>

<details>
<summary><strong>Does data remain in a container update?</strong></summary>

Yes, provided that `.env` and the mounted `data/` directory are retained. Nevertheless, always create a backup before updating and check the worker and health endpoint after the restart.

</details>

<details>
<summary><strong>Why does the dashboard react quickly despite an offline switch?</strong></summary>

The normal interface reads from the SQLite cache. The independent worker handles slow device queries in the background. Only a manual live refresh waits directly for the device.

</details>

<details>
<summary><strong>Why can’t I see VLAN or LAG data?</strong></summary>

Check the model, firmware, and the `/lacp.b`, `/fwd.b`, and `/vlan.b` endpoints. Not every firmware version returns the same fields or supports every write operation.

</details>

The detailed FAQ covering installation, backups, OIDC, webhooks, and security is available at [faq.md](faq.md#english).

<a id="en-20-license"></a>

## 20. License

Switchly is released under the **GNU General Public License Version 3.0** (`GPL-3.0-only`). The complete, unmodified license text is available in [`LICENSE`](../LICENSE).

Contributions and shared changes are subject to the terms of GPLv3. MikroTik and SwitchOS are trademarks of their respective owners; Switchly is not affiliated with or endorsed by MikroTik.

---

<div align="center">
  <sub><a href="../README.md#english">← Project overview</a> · <a href="README.md#english">Documentation center</a> · <a href="#en-quick-navigation">Back to top ↑</a></sub>
</div>
