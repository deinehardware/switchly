<a id="deutsch"></a>

> **Deutsch** | [English](#english)

# Installation

Switchly unterstützt Docker Compose, die Docker CLI und einen direkten Betrieb mit Apache/PHP. Für produktive Installationen wird Docker Compose empfohlen.

## Voraussetzungen

- Netzwerkzugriff des Hosts auf alle zu verwaltenden SwitchOS-Switches
- bei Docker: Docker Engine und Docker Compose v2
- bei Bare Metal: Debian/Ubuntu, Apache und PHP 8.5
- optional erreichbare SMTP-, OIDC- und Webhook-Dienste

## Docker Compose

```bash
cp .env.example .env
```

Optional ein eigenes Initialpasswort setzen; leer erzeugt Switchly ein sicheres Zufallspasswort:

```env
SWITCHLY_ADMIN_PASSWORD=
```

Starten und prüfen:

```bash
docker compose up -d --build
docker compose ps
docker compose logs switchly
curl --fail http://127.0.0.1:8080/api/health.php
```

Die SQLite-Datenbank bleibt im Docker-Volume `switchly_data` erhalten. Der Container läuft als `www-data` auf dem internen Port 8080, besitzt keine zusätzlichen Linux-Capabilities und verwendet ein schreibgeschütztes Root-Dateisystem. Das Image basiert auf `php:8.5-apache-bookworm`.

## Docker CLI

```bash
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

## Bare Metal

Die vollständige, direkt ausführbare Kurzinstallation befindet sich in der [README](../README.md#bare-metal-schnellstart-debianubuntu). Die wichtigsten Zielpfade sind:

| Zweck | Pfad |
| --- | --- |
| Anwendung | `/var/www/switchly` |
| öffentlicher Document Root | `/var/www/switchly/public` |
| SQLite und Laufzeitdaten | `/var/lib/switchly` |
| Umgebungsdatei | `/etc/switchly/switchly.env` |
| Apache VirtualHost | `/etc/apache2/sites-available/switchly.conf` |
| Worker | `/etc/systemd/system/switchly-worker.service` |
| Zeitplan | `/etc/systemd/system/switchly-worker.timer` |

Nur `/var/www/switchly/public` darf als Document Root konfiguriert werden. `src/`, `bin/`, `deploy/`, `docs/`, `licenses/` und die Projektkonfiguration müssen außerhalb des Webzugriffs bleiben.

Einsatzfertige Vorlagen liegen unter [`deploy/bare-metal`](../deploy/bare-metal).

## Installation prüfen

```bash
# Docker
docker compose logs --tail=100 switchly
curl --fail http://127.0.0.1:8080/api/health.php

# Bare Metal
sudo apache2ctl configtest
sudo systemctl status switchly-worker.timer
sudo journalctl -u switchly-worker.service -n 100
curl --fail http://127.0.0.1/api/health.php
```

Im Health-JSON müssen `status` auf `ok` und bei aktiviertem Worker `background_monitor.fresh` auf `true` stehen.

## Upgrade von einer älteren Verzeichnisstruktur

Ab der überarbeiteten Struktur zeigt Apache auf `public/`. Bei einem bestehenden Bare-Metal-System muss deshalb `DocumentRoot /var/www/switchly/public` gesetzt werden. Vorher Anwendung, Umgebungsdatei und Datenbank sichern. Bestehende URLs wie `/login.php` oder `/api/overview.php` ändern sich nicht.

Ältere Docker-Installationen mit `./data` müssen die Daten einmalig in das benannte Volume übertragen:

```bash
docker compose down
docker volume create switchly_data
docker run --rm -v "$PWD/data:/source:ro" -v switchly_data:/target alpine:3.22 \
  sh -c 'cp -a /source/. /target/ && chown -R 33:33 /target'
docker compose up -d --build
```

---

<a id="english"></a>

> [Deutsch](#deutsch) | **English**

<a id="en-installation"></a>

# Installation

Switchly supports Docker Compose, Docker CLI, and direct deployment with Apache and PHP. Docker Compose is recommended for production installations.

<a id="en-conditions"></a>

## Requirements

- Host network access to all SwitchOS switches to be managed
- Docker: Docker Engine and Docker Compose v2
- Bare Metal: Debian/Ubuntu, Apache and PHP 8.5
- Optionally accessible SMTP, OIDC and Webhook services

<a id="en-docker-compose"></a>

## Docker Compose

```bash
cp .env.example .env
```

Optionally set your own initial password. If left empty, Switchly generates a secure random password:

```env
SWITCHLY_ADMIN_PASSWORD=
```

Start and check:

```bash
docker compose up -d --build
docker compose ps
docker compose logs switchly
curl --fail http://127.0.0.1:8080/api/health.php
```

The SQLite database persists in the `switchly_data` Docker volume. The container runs as `www-data` on internal port 8080, has no additional Linux capabilities, and uses a read-only root filesystem. The image is based on `php:8.5-apache-bookworm`.

<a id="en-docker-cli"></a>

## Docker CLI

```bash
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

<a id="en-bare-metal"></a>

## Bare metal

The [README](../README.md#en-quick-bare-metal-start-debianubuntu) contains the complete, ready-to-run installation procedure. The main paths are:

| Purpose | Path |
| --- | --- |
| Application | `/var/www/switchly` |
| Public document root | `/var/www/switchly/public` |
| SQLite and runtime data | `/var/lib/switchly` |
| Environment file | `/etc/switchly/switchly.env` |
| Apache VirtualHost | `/etc/apache2/sites-available/switchly.conf` |
| Worker service | `/etc/systemd/system/switchly-worker.service` |
| Worker timer | `/etc/systemd/system/switchly-worker.timer` |

Configure only `/var/www/switchly/public` as the document root. `src/`, `bin/`, `deploy/`, `docs/`, `licenses/`, and the project configuration must remain inaccessible from the web.

Ready-to-use templates are available under [`deploy/bare-metal`](../deploy/bare-metal).

<a id="en-checking-installation-installation"></a>

## Verify the installation

```bash
# Docker
docker compose logs --tail=100 switchly
curl --fail http://127.0.0.1:8080/api/health.php

# Bare Metal
sudo apache2ctl configtest
sudo systemctl status switchly-worker.timer
sudo journalctl -u switchly-worker.service -n 100
curl --fail http://127.0.0.1/api/health.php
```

In the health response, `status` must be `ok`. When the worker is enabled, `background_monitor.fresh` must also be `true`.

<a id="en-upgrade-from-an-older-directory-structure"></a>

## Upgrade from an older directory structure

The revised structure points Apache to `public/`. For an existing bare-metal installation, set `DocumentRoot /var/www/switchly/public`. Back up the application, environment file, and database first. Existing URLs such as `/login.php` and `/api/overview.php` remain unchanged.

Older Docker installations using `./data` must migrate their data to the named volume once:

```bash
docker compose down
docker volume create switchly_data
docker run --rm -v "$PWD/data:/source:ro" -v switchly_data:/target alpine:3.22 \
  sh -c 'cp -a /source/. /target/ && chown -R 33:33 /target'
docker compose up -d --build
```
