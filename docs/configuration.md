<a id="deutsch"></a>

> **Deutsch** | [English](#english)

# Konfiguration

Docker Compose liest `.env`; Bare-Metal-Installationen verwenden standardmäßig `/etc/switchly/switchly.env`

## Umgebungsvariablen

| Variable | Standard | Beschreibung |
| --- | --- | --- |
| `SWITCHLY_HTTP_PORT` | `8080` | Host-Port bei Docker Compose |
| `TZ` | `Europe/Berlin` | Zeitzone für PHP, Worker und Logs |
| `SWITCHLY_DB_PATH` | Docker-intern gesetzt | absoluter Pfad zur SQLite-Datei |
| `SWITCHLY_ADMIN_USER` | `admin` | initialer Administrator einer leeren Datenbank |
| `SWITCHLY_ADMIN_PASSWORD` | leer | optionales Initialpasswort; leer erzeugt ein sicheres Zufallspasswort |
| `SWITCHLY_BASE_URL` | automatische Erkennung | öffentliche URL ohne abschließenden Slash |
| `SWITCHLY_MONITOR_ENABLED` | `1` | Hintergrundabfragen aktivieren |
| `SWITCHLY_MONITOR_INTERVAL` | `60` | Abfrageintervall in Sekunden, mindestens 15 |
| `SWITCHLY_LOG_RETENTION_DAYS` | `90` | automatische Aufbewahrung der Logs in Tagen |

Die Admin-Variablen werden nur beim Erzeugen einer leeren Datenbank verwendet. Ein späterer Wertwechsel setzt bestehende Konten nicht zurück.

### Automatisch erzeugtes Admin-Passwort

Ist `SWITCHLY_ADMIN_PASSWORD` beim ersten Start leer, erzeugt Switchly ein zufälliges Passwort mit hoher Entropie und schreibt es einmalig in das Container- beziehungsweise Service-Log:

```bash
docker compose logs switchly
```

Das Klartextpasswort wird nicht in einer Datei gespeichert und nach der Kontoerstellung nicht erneut ausgegeben. Ändere es nach der ersten Anmeldung und aktiviere TOTP-2FA.

## SMTP in der Benutzeroberfläche

Administratoren beziehungsweise Benutzer mit `alerts.manage` konfigurieren SMTP unter **Alerts → E-Mail & SMTP**. Unterstützt werden:

- SMTP-Host und Port
- STARTTLS, direktes TLS/SSL oder unverschlüsselte Übertragung
- optionaler Benutzername und Passwort
- Absenderadresse
- Empfänger für Ausfall- und Wiederherstellungsalarme
- direkter Versand eines Test-Alarms

Ein leeres Passwortfeld behält das vorhandene Passwort bei. API-Antworten enthalten niemals das gespeicherte Passwort. Verwende unverschlüsselte SMTP-Verbindungen nur in einem vollständig vertrauenswürdigen internen Netz.

## Öffentliche URL und Reverse Proxy

Für E-Mail-Verifikation und OIDC sollte die externe HTTPS-Adresse explizit gesetzt werden:

```env
SWITCHLY_BASE_URL=https://monitor.example.de
```

Ein Reverse Proxy muss mindestens folgende Header weiterreichen:

```nginx
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
```

## OpenID Connect

OIDC wird in der Administrationsoberfläche eingerichtet. Benötigt werden Discovery-URL, Client-ID, optional Client-Secret, Scopes und die öffentliche Basis-URL. Unterstützt werden Authorization Code Flow, PKCE S256, State, Nonce, JWKS sowie RS256/384/512.

Die Callback-URL lautet:

```text
https://monitor.example.de/sso.php
```

Automatisch angelegte Benutzer erhalten zunächst keine zusätzlichen Rechte. Eine lokal aktivierte TOTP-Prüfung bleibt auch nach OIDC-Anmeldung aktiv.

---

<a id="english"></a>

> [Deutsch](#deutsch) | **English**

# Configuration

Docker Compose reads `.env`; bare-metal installations use `/etc/switchly/switchly.env` by default. 

## Environment variables

| Variable | Default | Description |
| --- | --- | --- |
| `SWITCHLY_HTTP_PORT` | `8080` | Host port used by Docker Compose |
| `TZ` | `Europe/Berlin` | Time zone for PHP, the worker, and logs |
| `SWITCHLY_DB_PATH` | Set internally by Docker | Absolute path to the SQLite file |
| `SWITCHLY_ADMIN_USER` | `admin` | Initial administrator of an empty database |
| `SWITCHLY_ADMIN_PASSWORD` | empty | Optional initial password; an empty value generates a secure random password |
| `SWITCHLY_BASE_URL` | Automatic detection | Public URL without a trailing slash |
| `SWITCHLY_MONITOR_ENABLED` | `1` | Enable background polling |
| `SWITCHLY_MONITOR_INTERVAL` | `60` | Polling interval in seconds; minimum 15 |
| `SWITCHLY_LOG_RETENTION_DAYS` | `90` | Automatic log-retention period in days |

### Automatically generated administrator password

If `SWITCHLY_ADMIN_PASSWORD` is empty on first startup, Switchly generates a high-entropy random password and prints it once to the container or service log:

```bash
docker compose logs switchly
```

The plaintext password is not stored in a file and is never printed again after account creation. Change it after the first sign-in and enable TOTP-based 2FA.

## SMTP in the web interface

Administrators, or users granted `alerts.manage`, configure SMTP under **Alerts → Email & SMTP**. The interface supports:

- SMTP host and port
- STARTTLS, direct TLS/SSL, or an unencrypted connection
- optional username and password
- sender address
- recipients for outage and recovery notifications
- immediate delivery of a test alert

Leaving the password field empty preserves the existing password. API responses never contain the stored password. Use unencrypted SMTP only within a fully trusted internal network.

## Public URL and reverse proxy

Set the external HTTPS address explicitly for email verification and OIDC:

```env
SWITCHLY_BASE_URL=https://monitor.example.com
```

A reverse proxy must forward at least these headers:

```nginx
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
```

## OpenID Connect

Configure OIDC in the administration interface. It requires a discovery URL, client ID, optional client secret, scopes, and the public base URL. Switchly supports Authorization Code Flow, PKCE S256, state, nonce, JWKS, and RS256/384/512.

The callback URL is:

```text
https://monitor.example.com/sso.php
```

Automatically created users initially receive no additional permissions. A locally enabled TOTP check remains active after an OIDC sign-in.
