<a id="deutsch"></a>

> **Deutsch** | [English](#english)

![Switchly Network Control Center](docs/images/readme-banner.png)


# Switchly

[![Version](https://img.shields.io/badge/version-v1.4.7_Beta-6d5dfc?style=for-the-badge)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-8.5-777bb4?style=for-the-badge&logo=php&logoColor=white)](Dockerfile)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ed?style=for-the-badge&logo=docker&logoColor=white)](#docker-compose-empfohlen)
[![Lizenz](https://img.shields.io/badge/Lizenz-GPL--3.0-64748b?style=for-the-badge&logo=gnu&logoColor=white)](LICENSE)

Switchly ist ein selbst gehostetes Control Panel für das Monitoring und die Verwaltung von MikroTik-Switches mit SwitchOS. Eine kompakte Lösung für kleine und mittlere Switch-Umgebungen.

> [!IMPORTANT]
> **v1.4.7 Beta** ist eine Vorabversion. Reboot, Restore sowie VLAN-, LAG- und Portänderungen wirken unmittelbar auf das Gerät. Erstelle vorher ein Backup und teste Änderungen zuerst an einem Test-Switch.

## Funktionen

- zentrale Übersicht für mehrere SwitchOS-Switches
- System-, Port-, Traffic- und Fehler-Monitoring
- Port-, VLAN- und LAG/LACP-Verwaltung
- Reboot sowie `.swb`-Backup und Restore
- Benutzer, Gruppen, granulare Rechte und Switch-Freigaben
- lokale Anmeldung, E-Mail-Verifikation, TOTP-2FA und OpenID Connect
- E-Mail-, JSON-Webhook- und Discord-Benachrichtigungen
- Monitoring-Historie, Logs und Worker-Health

## Schnellstart

| Variante | Empfohlen für | Persistente Daten |
| --- | --- | --- |
| [Docker Compose](#docker-compose-empfohlen) | Standardinstallation | Docker-Volume `switchly_data` |
| [Docker CLI](#docker-cli) | manuell verwaltete Container | Docker-Volume `switchly_data` |
| [Bare Metal](#bare-metal-schnellstart-debianubuntu) | Apache/PHP direkt auf Linux | `/var/lib/switchly/database.sqlite` |

### Docker Compose (empfohlen)

Voraussetzungen: Docker Engine, Docker Compose v2 und Netzwerkzugriff zu den Switches.

```bash
cp .env.example .env
```

Optional kannst du in `.env` ein initiales Admin-Passwort mit mindestens 12 Zeichen festlegen:

```env
SWITCHLY_ADMIN_PASSWORD=
```

Danach starten:

```bash
docker compose up -d --build
docker compose ps
```

Switchly ist anschließend unter `http://<SERVER-IP>:8080` erreichbar.

Bleibt das Passwort leer, wird beim ersten Start ein sicheres Passwort erzeugt und genau einmal im Container-Log ausgegeben:

```bash
docker compose logs switchly
```

### Docker CLI

```bash
cp .env.example .env
docker build --pull -t switchly:1.4.7-beta .
docker volume create switchly_data
docker run -d \
  --name switchly \
  --restart always \
  --user 33:33 \
  --read-only \
  --cap-drop ALL \
  --security-opt no-new-privileges \
  --tmpfs /tmp:rw,nosuid,nodev,noexec,mode=1777 \
  --env-file .env \
  -e SWITCHLY_DB_PATH=/var/lib/switchly/database.sqlite \
  -p 8080:8080 \
  -v switchly_data:/var/lib/switchly \
  switchly:1.4.7-beta
```

Bei der Docker CLI wird der Host-Port direkt mit `-p` festgelegt. `SWITCHLY_HTTP_PORT` gilt nur für Docker Compose.

### Bare-Metal-Schnellstart (Debian/Ubuntu)

Voraussetzungen: ein Debian-/Ubuntu-Host, Root-Rechte, PHP 8.5 und Netzwerkzugriff zu den Switches. Die folgende Anleitung ist vollständig in dieser README enthalten.

#### 1. Pakete und Anwendung installieren

Die Befehle werden im geklonten oder entpackten Projektverzeichnis ausgeführt:

```bash
sudo apt update
sudo apt install apache2 libapache2-mod-php php-cli php-curl \
  php-sqlite3 php-opcache curl unzip
sudo a2enmod headers expires

sudo install -d -m 750 -o root -g www-data /var/www/switchly
sudo install -d -m 770 -o www-data -g www-data /var/lib/switchly
sudo install -d -m 750 -o root -g www-data /etc/switchly

sudo tar \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.env' \
  --exclude='data' \
  --exclude='docs' \
  --exclude='scripts' \
  -cf - . | sudo tar -xf - -C /var/www/switchly

sudo chown -R root:www-data /var/www/switchly
sudo find /var/www/switchly -type d -exec chmod 750 {} \;
sudo find /var/www/switchly -type f -exec chmod 640 {} \;
```

#### 2. Umgebung konfigurieren

```bash
sudoedit /etc/switchly/switchly.env
```

Inhalt:

```env
TZ=Europe/Berlin
SWITCHLY_DB_PATH=/var/lib/switchly/database.sqlite
SWITCHLY_ADMIN_USER=admin
SWITCHLY_ADMIN_PASSWORD=
SWITCHLY_BASE_URL=

SWITCHLY_MONITOR_ENABLED=1
SWITCHLY_MONITOR_INTERVAL=60
SWITCHLY_LOG_RETENTION_DAYS=90
```

Danach die Datei schützen:

```bash
sudo chown root:www-data /etc/switchly/switchly.env
sudo chmod 640 /etc/switchly/switchly.env
```

Für öffentliche Links, E-Mail-Verifikation oder OIDC sollte `SWITCHLY_BASE_URL` auf die spätere HTTPS-Adresse zeigen.

#### 3. Apache konfigurieren

<details>
<summary><strong>Apache VirtualHost und Umgebungsdatei anzeigen</strong></summary>

Datei `/etc/apache2/sites-available/switchly.conf`:

```apache
<VirtualHost *:80>
    ServerName switchly.local
    ServerAlias *
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

Damit Apache die Umgebung lädt, Datei `/etc/systemd/system/apache2.service.d/switchly.conf` anlegen:

```ini
[Service]
EnvironmentFile=/etc/switchly/switchly.env
```

</details>

Dateien öffnen und den Inhalt aus dem aufgeklappten Abschnitt einfügen:

```bash
sudoedit /etc/apache2/sites-available/switchly.conf
sudo mkdir -p /etc/systemd/system/apache2.service.d
sudoedit /etc/systemd/system/apache2.service.d/switchly.conf
```

`ServerAlias *` ist für einen dedizierten Host gedacht. Werden bereits andere Websites betrieben, entferne den Alias und setze einen eindeutigen `ServerName`.

#### 4. Hintergrund-Worker einrichten

<details>
<summary><strong>systemd-Service und Timer anzeigen</strong></summary>

Datei `/etc/systemd/system/switchly-worker.service`:

```ini
[Unit]
Description=Switchly background monitor
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/switchly
EnvironmentFile=/etc/switchly/switchly.env
ExecStart=/usr/bin/php /var/www/switchly/bin/monitor.php
PrivateTmp=true
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateDevices=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictSUIDSGID=true
LockPersonality=true
ReadWritePaths=/var/lib/switchly
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
```

Datei `/etc/systemd/system/switchly-worker.timer`:

```ini
[Unit]
Description=Run the Switchly background monitor every 60 seconds

[Timer]
OnBootSec=5s
OnUnitActiveSec=60s
AccuracySec=1s
Persistent=true
Unit=switchly-worker.service

[Install]
WantedBy=timers.target
```

</details>

Dateien öffnen und den Inhalt aus dem aufgeklappten Abschnitt einfügen:

```bash
sudoedit /etc/systemd/system/switchly-worker.service
sudoedit /etc/systemd/system/switchly-worker.timer
```

#### 5. Installation starten und prüfen

```bash
sudo a2ensite switchly.conf
sudo apache2ctl configtest
sudo systemctl daemon-reload
sudo systemctl restart apache2
sudo systemctl enable --now switchly-worker.timer
sudo systemctl start switchly-worker.service

sudo systemctl status switchly-worker.timer
curl --fail http://127.0.0.1/api/health.php
```

Switchly ist anschließend unter `http://<SERVER-IP>` erreichbar. TLS-, Reverse-Proxy- und erweiterte Diagnosehinweise stehen in der [Installationsanleitung](docs/installation.md).

## Erster Login

Beim ersten Start erzeugt Switchly automatisch die SQLite-Datenbank und das Administratorkonto. Ist `SWITCHLY_ADMIN_PASSWORD` leer, wird ein zufälliges Passwort erzeugt und einmalig im Container- beziehungsweise Service-Log ausgegeben.

1. Mit dem konfigurierten Administratorkonto anmelden.
2. TOTP-2FA im Profil aktivieren.
3. Einen Switch mit Management-Adresse und Digest-Zugangsdaten hinzufügen.
4. Über **Aktualisieren** die Live-Verbindung testen.
5. Rollen, Gruppen und Switch-Freigaben konfigurieren.
6. Vor schreibenden Geräteaktionen ein `.swb`-Backup erstellen.

> [!NOTE]
> Die Admin-Variablen werden nur beim Erzeugen einer leeren Datenbank ausgewertet. Ein späterer Wertwechsel in `.env` ändert kein bestehendes Passwort.

## Konfiguration

| Variable | Standard | Zweck |
| --- | --- | --- |
| `SWITCHLY_HTTP_PORT` | `8080` | Host-Port bei Docker Compose |
| `TZ` | `Europe/Berlin` | Zeitzone für PHP und Logs |
| `SWITCHLY_DB_PATH` | Docker-intern gesetzt | Pfad der SQLite-Datenbank |
| `SWITCHLY_ADMIN_USER` | `admin` | Benutzer einer neuen Installation |
| `SWITCHLY_ADMIN_PASSWORD` | leer | optionales Initialpasswort; leer erzeugt ein sicheres Zufallspasswort |
| `SWITCHLY_BASE_URL` | automatische Erkennung | öffentliche Basis-URL |
| `SWITCHLY_MONITOR_ENABLED` | `1` | Hintergrund-Monitoring aktivieren |
| `SWITCHLY_MONITOR_INTERVAL` | `60` | Worker-Intervall in Sekunden |
| `SWITCHLY_LOG_RETENTION_DAYS` | `90` | Aufbewahrung der Logs |

SMTP wird nach der Anmeldung unter **Alerts → E-Mail & SMTP** konfiguriert.

Alle verbliebenen Umgebungsvariablen, OIDC-Einstellungen und Reverse-Proxy-Beispiele sind in der [Konfigurationsanleitung](docs/configuration.md) beschrieben. Veraltete Umgebungsvariablen früherer Releases werden nicht mehr ausgewertet.

## Betrieb und Updates

### Zustand prüfen

```bash
# Docker Compose
docker compose ps
docker compose logs --tail=100 switchly
curl --fail http://127.0.0.1:8080/api/health.php

# Bare Metal
sudo systemctl status switchly-worker.timer
sudo journalctl -u switchly-worker.service -n 100
curl --fail http://127.0.0.1/api/health.php
```

Im Health-JSON bestätigt `status: "ok"` die Webanwendung. Zusätzlich sollte `background_monitor.fresh` wahr sein.

### Docker-Backup

```bash
docker compose stop
docker run --rm -v switchly_data:/source:ro -v "$PWD:/backup" alpine:3.22 \
  tar -czf "/backup/switchly-backup-$(date +%F).tar.gz" -C /source .
docker compose start
```

Die Sicherung enthält Secrets und muss vertraulich behandelt werden. Bare-Metal-Backup und Restore sind in der [Betriebsdokumentation](docs/operations.md) beschrieben.

### Docker-Update

```bash
# Erst .env und data/ sichern
docker compose down --remove-orphans
docker compose build --pull
docker compose up -d
```

Nach jedem Update Login, Health-Endpunkt, Live-Aktualisierung und Worker prüfen. Der vollständige Ablauf steht unter [Betrieb und Updates](docs/operations.md).

## FAQ

<details>
<summary><strong>Warum startet Switchly ohne Admin-Passwort nicht?</strong></summary>

Switchly erzeugt bei einer leeren Datenbank automatisch ein sicheres Passwort. Zeige es mit `docker compose logs switchly` an. Das Passwort erscheint nur beim ersten Anlegen des Administratorkontos.

</details>

<details>
<summary><strong>Wo liegen meine Daten?</strong></summary>

Bei Compose im Docker-Volume `switchly_data`, bei Bare Metal standardmäßig unter `/var/lib/switchly/database.sqlite`. Die Datenbank enthält vertrauliche Zugangsdaten und Secrets.

</details>

<details>
<summary><strong>Warum wird ein Switch als offline angezeigt?</strong></summary>

Prüfe Management-Adresse, Port, Digest-Zugangsdaten, Routing und Firewall. Teste danach den direkten Zugriff aus dem Container oder vom Bare-Metal-Host. Die einzelnen Schritte stehen in der [Betriebsdokumentation](docs/operations.md#fehlerdiagnose).

</details>

<details>
<summary><strong>Warum sind VLAN- oder LAG-Daten leer?</strong></summary>

SwitchOS-Endpunkte und Felder unterscheiden sich je nach Modell und Firmware. Switchly verwendet `/lacp.b`, `/fwd.b` und `/vlan.b`; die konkrete Firmware muss diese Endpunkte unterstützen.

</details>

Weitere Antworten zu Installation, Worker, Updates, OIDC, Webhooks und Sicherheit stehen im [vollständigen FAQ](docs/faq.md).

## Dokumentation

| Dokument                                            | Inhalt                                       |
| --------------------------------------------------- | -------------------------------------------- |
| [Dokumentationszentrum](docs/README.md)             | Einstieg und Schnellnavigation               |
| [Installation](docs/installation.md)                | Docker, Compose und Bare Metal               |
| [Konfiguration](docs/configuration.md)              | Umgebung, SMTP, OIDC und Reverse Proxy       |
| [Bedienung](docs/user-guide.md)                     | Switches, Ports, VLAN, LAG und Benutzer      |
| [Betrieb](docs/operations.md)                       | Worker, Backup, Updates und Fehlerdiagnose   |
| [Projektstruktur](docs/structure.md)                | Zweck und Sichtbarkeit aller Verzeichnisse   |
| [Vollständige Referenz](docs/reference.md)          | sämtliche Detailinformationen                |
| [FAQ](docs/faq.md)                                  | häufige Fragen und Lösungen                  |
| [Entwicklung und Beiträge](CONTRIBUTING.md)         | lokale Prüfung und Release-Ablauf            |
| [Drittanbieter-Komponenten](THIRD_PARTY_NOTICES.md) | Laufzeit- und Drittanbieter-Komponenten      |
| [Changelog](CHANGELOG.md)                           | Versionshistorie                             |
| [Sicherheitsrichtlinie](SECURITY.md)                | vertrauliche Meldung von Schwachstellen      |

## Fehler melden

Prüfe zunächst bestehende Issues und das [FAQ](docs/faq.md). Ein guter Bugreport enthält:

- Switchly-Version sowie Installationsart
- Betriebssystem, Browser und PHP-/Docker-Version
- Switch-Modell und SwitchOS-Firmware
- erwartetes und tatsächliches Verhalten
- reproduzierbare Schritte und bereinigte Logauszüge

Zugangsdaten, `.env`, Datenbanken, Session-Cookies, TOTP-Secrets und vollständige Webhook-URLs gehören niemals in ein öffentliches Issue. Sicherheitslücken werden gemäß [SECURITY.md](SECURITY.md) vertraulich gemeldet.

## Beitragen

Beiträge sind willkommen. Der übliche Ablauf:

1. Repository forken und einen thematischen Branch erstellen.
2. Änderungen klein, nachvollziehbar und dokumentiert halten.
3. `bash ./scripts/quality-check.sh` ausführen.
4. Füge bei Änderungen an der Benutzeroberfläche aussagekräftige Screenshots hinzu. Nenne bei Änderungen an der SwitchOS-Translation-API außerdem das getestete Switch-Modell und die installierte Firmwareversion.
5. Pull Request mit Problem, Lösung, Risiken und Testschritten einreichen.

Die vollständigen Konventionen stehen in [CONTRIBUTING.md](CONTRIBUTING.md). Mit einem Beitrag bestätigst du, ihn unter `GPL-3.0-only` veröffentlichen zu dürfen.

## Lizenz

Switchly wird unter der [GNU General Public License Version 3](LICENSE) veröffentlicht (`GPL-3.0-only`). Separat lizenzierte Komponenten sind in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) dokumentiert. MikroTik und SwitchOS sind Marken ihrer jeweiligen Eigentümer. Switchly ist nicht mit MikroTik verbunden und wird nicht von MikroTik unterstützt.

---

<a id="english"></a>

> [Deutsch](#deutsch) | **English**

![Switchly Network Control Center](docs/images/readme-banner.png)


<a id="en-switchly"></a>

# Switchly

[![Version](https://img.shields.io/badge/version-v1.4.7_Beta-6d5dfc?style=for-the-badge)](CHANGELOG.md#english)
[![PHP](https://img.shields.io/badge/PHP-8.5-777bb4?style=for-the-badge&logo=php&logoColor=white)](Dockerfile)
[![Docker](https://img.shields.io/badge/Docker-Compose-2496ed?style=for-the-badge&logo=docker&logoColor=white)](#en-docker-compose-recommended)
[![License](https://img.shields.io/badge/License-GPL--3.0-64748b?style=for-the-badge&logo=gnu&logoColor=white)](LICENSE)

Switchly is a self-hosted network control center for monitoring and managing MikroTik switches with SwitchOS. A responsive web interface, SQLite and an independent background worker form a compact solution for small and medium switch environments.

> [!IMPORTANT]
> **v1.4.7 Beta** is a pre-release version. Reboots, restores, and changes to VLANs, LAGs, or ports take effect immediately on the device. Create a backup first and test changes on a non-production switch.

<a id="en-functions"></a>

## Features

- Centralized overview of multiple SwitchOS switches
- System, port, traffic, and error monitoring
- Port, VLAN, and LAG/LACP management
- Reboots and `.swb` configuration backup and restore
- Users, groups, granular permissions and switch access assignments
- Local sign-in, email verification, TOTP-based 2FA, and OpenID Connect
- Email, JSON webhook, and Discord notifications
- Monitoring history, logs, and worker health

<a id="en-quick-start"></a>

## Quick start

| Variant | Recommended for | Persistent data |
| --- | --- | --- |
| [Docker Compose](#en-docker-compose-recommended) | Standard installation | Docker volume `switchly_data` |
| [Docker CLI](#en-docker-cli) | Manually managed containers | Docker volume `switchly_data` |
| [Bare metal](#en-quick-bare-metal-start-debianubuntu) | Apache/PHP directly on Linux | `/var/lib/switchly/database.sqlite` |

<a id="en-docker-compose-recommended"></a>

### Docker Compose (recommended)

Requirements: Docker Engine, Docker Compose v2 and network access to the switches.

```bash
cp .env.example .env
```

Optionally set an initial administrator password containing at least 12 characters in `.env`:

```env
SWITCHLY_ADMIN_PASSWORD=
```

Then start:

```bash
docker compose up -d --build
docker compose ps
```

Switchly is then available at `http://<SERVER-IP>:8080`.

When the password is empty, Switchly generates a secure password on first startup and prints it exactly once to the container log:

```bash
docker compose logs switchly
```

<a id="en-docker-cli"></a>

### Docker CLI

```bash
cp .env.example .env
docker build --pull -t switchly:1.4.7-beta .
docker volume create switchly_data
docker run -d \
  --name switchly \
  --restart always \
  --user 33:33 \
  --read-only \
  --cap-drop ALL \
  --security-opt no-new-privileges \
  --tmpfs /tmp:rw,nosuid,nodev,noexec,mode=1777 \
  --env-file .env \
  -e SWITCHLY_DB_PATH=/var/lib/switchly/database.sqlite \
  -p 8080:8080 \
  -v switchly_data:/var/lib/switchly \
  switchly:1.4.7-beta
```

With Docker CLI, set the host port directly using `-p`. `SWITCHLY_HTTP_PORT` applies only to Docker Compose.

<a id="en-quick-bare-metal-start-debianubuntu"></a>

### Bare-metal quick start (Debian/Ubuntu)

Requirements: a Debian or Ubuntu host, root privileges, PHP 8.5, and network access to the switches. This README contains the complete procedure.

<a id="en-1-install-packages-and-application"></a>

#### 1. Install packages and application

The commands are executed in the cloned or unpacked project directory:

```bash
sudo apt update
sudo apt install apache2 libapache2-mod-php php-cli php-curl \
  php-sqlite3 php-opcache curl unzip
sudo a2enmod headers expires

sudo install -d -m 750 -o root -g www-data /var/www/switchly
sudo install -d -m 770 -o www-data -g www-data /var/lib/switchly
sudo install -d -m 750 -o root -g www-data /etc/switchly

sudo tar \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.env' \
  --exclude='data' \
  --exclude='docs' \
  --exclude='scripts' \
  -cf - . | sudo tar -xf - -C /var/www/switchly

sudo chown -R root:www-data /var/www/switchly
sudo find /var/www/switchly -type d -exec chmod 750 {} \;
sudo find /var/www/switchly -type f -exec chmod 640 {} \;
```

<a id="en-2-configure-environment"></a>

#### 2. Configure environment

```bash
sudoedit /etc/switchly/switchly.env
```

Content:

```env
TZ=Europe/Berlin
SWITCHLY_DB_PATH=/var/lib/switchly/database.sqlite
SWITCHLY_ADMIN_USER=admin
SWITCHLY_ADMIN_PASSWORD=
SWITCHLY_BASE_URL=

SWITCHLY_MONITOR_ENABLED=1
SWITCHLY_MONITOR_INTERVAL=60
SWITCHLY_LOG_RETENTION_DAYS=90
```

Protect the file:

```bash
sudo chown root:www-data /etc/switchly/switchly.env
sudo chmod 640 /etc/switchly/switchly.env
```

For public links, email verification, and OIDC, set `SWITCHLY_BASE_URL` to the final HTTPS address.

<a id="en-3-configure-apache"></a>

#### 3. Configure Apache

<details>
<summary><strong>Show the Apache virtual host and environment file</strong></summary>

File `/etc/apache2/sites-available/switchly.conf`:

```apache
<VirtualHost *:80>
    ServerName switchly.local
    ServerAlias *
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

To make Apache load the environment, create `/etc/systemd/system/apache2.service.d/switchly.conf`:

```ini
[Service]
EnvironmentFile=/etc/switchly/switchly.env
```

</details>

Open the files and paste the contents shown above:

```bash
sudoedit /etc/apache2/sites-available/switchly.conf
sudo mkdir -p /etc/systemd/system/apache2.service.d
sudoedit /etc/systemd/system/apache2.service.d/switchly.conf
```

`ServerAlias *` is intended for a dedicated host. If other websites are already operated, remove the alias and set a unique `ServerName`.

<a id="en-4-setting-up-background-workers"></a>

#### 4. Setting up background workers

<details>
<summary><strong>Show systemd service and timer</strong></summary>

File `/etc/systemd/system/switchly-worker.service`:

```ini
[Unit]
Description=Switchly background monitor
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/switchly
EnvironmentFile=/etc/switchly/switchly.env
ExecStart=/usr/bin/php /var/www/switchly/bin/monitor.php
PrivateTmp=true
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateDevices=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictSUIDSGID=true
LockPersonality=true
ReadWritePaths=/var/lib/switchly
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
```

File `/etc/systemd/system/switchly-worker.timer`:

```ini
[Unit]
Description=Run the Switchly background monitor every 60 seconds

[Timer]
OnBootSec=5s
OnUnitActiveSec=60s
AccuracySec=1s
Persistent=true
Unit=switchly-worker.service

[Install]
WantedBy=timers.target
```

</details>

Open the files and paste the contents shown above:

```bash
sudoedit /etc/systemd/system/switchly-worker.service
sudoedit /etc/systemd/system/switchly-worker.timer
```

<a id="en-5-start-and-check-installation"></a>

#### 5. Start and check installation

```bash
sudo a2ensite switchly.conf
sudo apache2ctl configtest
sudo systemctl daemon-reload
sudo systemctl restart apache2
sudo systemctl enable --now switchly-worker.timer
sudo systemctl start switchly-worker.service

sudo systemctl status switchly-worker.timer
curl --fail http://127.0.0.1/api/health.php
```

Switchly is then available at `http://<SERVER-IP>`. TLS, reverse-proxy, and advanced troubleshooting instructions are available in the [installation guide](docs/installation.md#english).

<a id="en-first-login"></a>

## First login

On first startup, Switchly creates the SQLite database and administrator account. If `SWITCHLY_ADMIN_PASSWORD` is empty, it generates a random password and prints it once to the container or service log.

1. Sign in with the configured administrator account.
2. Enable TOTP-based 2FA in your profile.
3. Add a switch with management address and digest access data.
4. Select **Update** to test the live connection.
5. Configure roles, groups, and switch access assignments.
6. Create an `.swb` backup before performing write operations on a device.

> [!NOTE]
> The administrator variables are evaluated only when an empty database is created. Changing them later in `.env` does not update an existing password.

<a id="en-configuration"></a>

## Configuration

| Variables | Standard | Purpose |
| --- | --- | --- |
| `SWITCHLY_HTTP_PORT` | `8080` | Host port at Docker Compose |
| `TZ` | `Europe/Berlin` | Time zone for PHP and logs |
| `SWITCHLY_DB_PATH` | Docker internally set | Path of the SQLite database |
| `SWITCHLY_ADMIN_USER` | `admin` | User of a new installation |
| `SWITCHLY_ADMIN_PASSWORD` | empty | Optional initial password; an empty value generates a secure random password |
| `SWITCHLY_BASE_URL` | Automatic detection | Basic public URL |
| `SWITCHLY_MONITOR_ENABLED` | `1` | Activate background monitoring |
| `SWITCHLY_MONITOR_INTERVAL` | `60` | Worker intervals in seconds |
| `SWITCHLY_LOG_RETENTION_DAYS` | `90` | Log retention in days |

Configure SMTP after signing in under **Alerts → Email & SMTP**. The API never returns the stored password to the browser.

All remaining environment variables, OIDC settings, and reverse-proxy examples are described in the [configuration guide](docs/configuration.md#english). Deprecated environment variables from earlier releases are no longer evaluated.

<a id="en-operation-and-updates"></a>

## Operation and updates

<a id="en-check-condition"></a>

### Check system status

```bash
# Docker Compose
docker compose ps
docker compose logs --tail=100 switchly
curl --fail http://127.0.0.1:8080/api/health.php

# Bare Metal
sudo systemctl status switchly-worker.timer
sudo journalctl -u switchly-worker.service -n 100
curl --fail http://127.0.0.1/api/health.php
```

Confirmed in Health-JSON `status: "ok"` the web application. In addition, `background_monitor.fresh` be true.

<a id="en-docker-backup"></a>

### Docker backup

```bash
docker compose stop
docker run --rm -v switchly_data:/source:ro -v "$PWD:/backup" alpine:3.22 \
  tar -czf "/backup/switchly-backup-$(date +%F).tar.gz" -C /source .
docker compose start
```

The backup contains secrets and must be treated as confidential. Bare-metal backup and restore procedures are described in the [operations guide](docs/operations.md#english).

<a id="en-docker-update"></a>

### Update with Docker

```bash
# Back up .env and data/ first
docker compose down --remove-orphans
docker compose build --pull
docker compose up -d
```

Check login, health endpoint, live update and worker after each update. The complete process is under [Operation and updates](docs/operations.md#english).

<a id="en-faq"></a>

## FAQ

<details>
<summary><strong>Why doesn’t Switchly start without an admin password?</strong></summary>

For an empty database, Switchly generates a secure password automatically. Display it with `docker compose logs switchly`. It is printed only when the administrator account is first created.

</details>

<details>
<summary><strong>Where is my data?</strong></summary>

With Compose, data is stored in the `switchly_data` Docker volume. Bare-metal installations use `/var/lib/switchly/database.sqlite` by default. The database contains confidential credentials and secrets.

</details>

<details>
<summary><strong>Why is a switch shown as offline?</strong></summary>

Check management address, port, digest access data, routing, and firewall. After that, test direct access from the container or from the bare metal host. The individual steps are in the [Operational documentation](docs/operations.md#en-error-diagnosis).

</details>

<details>
<summary><strong>Why is VLAN or LAG data empty?</strong></summary>

SwitchOS endpoints and fields differ by model and firmware. Switchly used `/lacp.b`, `/fwd.b` and `/vlan.b`; The specific firmware must support these endpoints.

</details>

More information about installation, the worker, updates, OIDC, webhooks, and security is available in the [complete FAQ](docs/faq.md#english).

<a id="en-documentation"></a>

## Documentation

| Documentation | Content |
| --- | --- |
| [Documentation center](docs/README.md#english) | Getting started and fast navigation |
| [Installation](docs/installation.md#english) | Docker, Compose and Bare Metal |
| [Configuration](docs/configuration.md#english) | Environment, SMTP, OIDC and Reverse Proxy |
| [User guide](docs/user-guide.md#english) | Switches, ports, VLAN, LAG and users |
| [API](docs/reference.md#en-11-internal-cms-api) | Authentication, endpoints and status codes |
| [Operations](docs/operations.md#english) | Worker, backup, updates and error diagnosis |
| [Project structure](docs/structure.md#english) | Purpose and visibility of all directories |
| [Full reference](docs/reference.md#english) | All detailed information |
| [FAQ](docs/faq.md#english) | Frequent questions and solutions |
| [Development and contributions](CONTRIBUTING.md#english) | local testing and release process |
| [Third party components](THIRD_PARTY_NOTICES.md#english) | Runtime and third-party components |
| [Changelog](CHANGELOG.md#english) | Version history |
| [Security policy](SECURITY.md#english) | Confidential vulnerability reporting |

<a id="en-report-errors"></a>

## Report an issue

First check existing issues and the [FAQ](docs/faq.md#english). A good bug report contains:

- Switchly version and installation type
- Operating system, browser and PHP/Docker version
- Switch model and SwitchOS firmware
- expected and actual behavior
- reproducible steps and corrected log statements

Never include credentials, `.env` files, databases, session cookies, TOTP secrets, or complete webhook URLs in a public issue. Report vulnerabilities confidentially as described in [SECURITY.md](SECURITY.md#english).

<a id="en-contributions"></a>

## Contributions

Contributions are welcome. Follow this process:

1. Fork the repository and create a focused branch.
2. Keep changes small, traceable and documented.
3. Run `bash ./scripts/quality-check.sh`.
4. Add meaningful screenshots when changes are made to the user interface. For changes to the SwitchOS Translation API, also mention the tested switch model and the installed firmware version.
5. Submit pull request with problem, solution, risks and test steps.

The full guidelines are available in [CONTRIBUTING.md](CONTRIBUTING.md#english). By contributing, you confirm that you may publish your work under `GPL-3.0-only`.

<a id="en-license"></a>

## License

Switchly is published under the [GNU General Public License Version 3](LICENSE) (`GPL-3.0-only`). Separately licensed components are documented in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md#english). MikroTik and SwitchOS are trademarks of their respective owners. Switchly is neither affiliated with nor supported by MikroTik.
