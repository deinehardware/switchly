<a id="deutsch"></a>

> **Deutsch** | [English](#english)

# Konfiguration

Docker Compose liest `.env`; Bare-Metal-Installationen verwenden standardmäßig `/etc/switchly/switchly.env`. Zugangsdaten für SMTP und OpenID Connect werden über die geschützte Administrationsoberfläche verwaltet.

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

Veraltete Variablen aus Releases vor dem Switchly-Rebranding werden nicht mehr ausgewertet. Die Admin-Variablen werden nur beim Erzeugen einer leeren Datenbank verwendet. Ein späterer Wertwechsel setzt bestehende Konten nicht zurück.

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

Für ein persönliches Gmail-Konto wird typischerweise folgende Konfiguration verwendet:

| Feld | Wert |
| --- | --- |
| SMTP-Host | `smtp.gmail.com` |
| SMTP-Port | `587` |
| Verschlüsselung | `STARTTLS` |
| SMTP-Benutzername | vollständige Gmail-Adresse |
| SMTP-Passwort | 16-stelliges Google-App-Passwort, nicht das normale Kontopasswort |
| Absenderadresse | dieselbe Gmail-Adresse |

Google-App-Passwörter setzen die Bestätigung in zwei Schritten voraus. Beim Einfügen eines gruppiert angezeigten App-Passworts entfernt Switchly Leerzeichen automatisch. Der E-Mail-Test gibt bei einem Fehler die konkrete SMTP-Phase und die bereinigte Serverantwort aus, ohne Zugangsdaten zu protokollieren.

### Gmail OAuth2

Wenn Google passwortbasiertes SMTP mit `534 WebLoginRequired` blockiert, kann Switchly die Gmail API per OAuth2 verwenden:

1. In der Google Cloud Console ein Projekt anlegen, die Gmail API aktivieren und den OAuth-Zustimmungsbildschirm konfigurieren. Im Testmodus das eigene Gmail-Konto als Testnutzer eintragen.
2. Einen OAuth-Client vom Typ **Webanwendung** anlegen.
3. In Switchly unter **Alerts → E-Mail & SMTP** Client-ID, Client-Secret und die Gmail-Absenderadresse eintragen sowie **Gmail OAuth2 verwenden** aktivieren.
4. Die von Switchly angezeigte Callback-URL in Google als **Autorisierte Weiterleitungs-URI** eintragen.
5. Die Alert-Einstellungen speichern und danach **Mit Google verbinden** auswählen.
6. Nach der Google-Zustimmung den E-Mail-Test erneut ausführen.

Switchly fordert ausschließlich den eingeschränkten Versand-Scope `gmail.send` an. Client-Secret und Refresh-Token werden nur serverseitig in SQLite gespeichert und niemals von der Alert-API zurückgegeben.

Der globale Alert-Schalter lässt sich nur aktivieren, wenn mindestens ein nutzbarer Kanal konfiguriert ist. Beim erneuten Aktivieren werden die Alert-Zustände neu scharfgestellt. Fehlgeschlagene Ausfall- und Recovery-Zustellungen bleiben nach dem konfigurierten Cooldown wiederholbar und werden mit dem Ereignis `ALERT_DELIVERY_FAILED` protokolliert. Der Schalter an einer Switch-Karte nimmt ausschließlich dieses Gerät aus den Alerts heraus; Messwerte und Verlauf laufen weiter.

Ein erfolgreicher manueller Alert-Test sowie das Speichern geänderter Alert-Einstellungen setzen den Cooldown für fehlgeschlagene, noch nicht zugestellte Ausfallalarme zurück. Bereits offline erkannte Switches werden dadurch beim nächsten Worker-Lauf sofort erneut zugestellt.

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

Docker Compose reads `.env`; bare-metal installations use `/etc/switchly/switchly.env` by default. SMTP and OpenID Connect credentials are managed through the protected administration interface.

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

Deprecated variables from releases before the Switchly rebranding are no longer evaluated. Administrator variables are used only when an empty database is initialized. Changing them later does not reset existing accounts.

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

A personal Gmail account typically uses this configuration:

| Field | Value |
| --- | --- |
| SMTP host | `smtp.gmail.com` |
| SMTP port | `587` |
| Encryption | `STARTTLS` |
| SMTP username | full Gmail address |
| SMTP password | 16-character Google app password, not the normal account password |
| Sender address | the same Gmail address |

Google app passwords require 2-Step Verification. When a grouped app password is pasted, Switchly removes whitespace automatically. If the email test fails, it reports the exact SMTP stage and a sanitized server response without logging credentials.

### Gmail OAuth2

If Google blocks password-based SMTP with `534 WebLoginRequired`, Switchly can use the Gmail API through OAuth2:

1. Create a Google Cloud project, enable the Gmail API, and configure the OAuth consent screen. While in testing mode, add your Gmail account as a test user.
2. Create an OAuth client of type **Web application**.
3. In Switchly under **Alerts → Email & SMTP**, enter the client ID, client secret, and Gmail sender address, then enable **Use Gmail OAuth2**.
4. Add the callback URL displayed by Switchly to Google's **Authorized redirect URIs**.
5. Save the alert settings, then select **Connect Google**.
6. After granting consent at Google, run the email test again.

Switchly requests only the limited `gmail.send` scope. The client secret and refresh token are stored server-side in SQLite and are never returned by the Alerts API.

The master alert switch can only be enabled when at least one usable channel is configured. Re-enabling alerts re-arms their state. Failed outage and recovery deliveries remain eligible for retry after the configured cooldown and are logged as `ALERT_DELIVERY_FAILED`. The switch-card toggle excludes only that device from alerts; monitoring samples and history continue normally.

A successful manual alert test, as well as saving changed alert settings, clears the cooldown for failed outage alerts that have not yet been delivered. Switches that are already known to be offline are therefore retried on the next worker pass.

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
