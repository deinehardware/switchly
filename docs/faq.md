<a id="deutsch"></a>

> **Deutsch** | [English](#english)

![Switchly FAQ](images/readme-banner.png)


<div align="center">
  <h1>Häufig gestellte Fragen</h1>
  <p>Kurze Antworten auf typische Fragen zu Installation, Betrieb und SwOS.</p>

  <p>
    <a href="README.md">Doku-Übersicht</a> ·
    <a href="reference.md">Komplette Referenz</a> ·
    <a href="../SECURITY.md">Sicherheit</a>
  </p>
</div>

## Installation und Anmeldung

<details>
<summary><strong>Welches Passwort verwende ich beim ersten Login?</strong></summary>

Benutzername und Passwort stammen aus `SWITCHLY_ADMIN_USER` und `SWITCHLY_ADMIN_PASSWORD` in deiner `.env`. Das initiale Passwort muss mindestens 12 Zeichen lang sein und wird nur beim Erzeugen einer leeren Datenbank verwendet.

```env
SWITCHLY_ADMIN_USER=admin
SWITCHLY_ADMIN_PASSWORD=EIN-LANGES-ZUFAELLIGES-PASSWORT
```

</details>

<details>
<summary><strong>Warum startet Docker nicht und verlangt SWITCHLY_ADMIN_PASSWORD?</strong></summary>

Switchly besitzt absichtlich kein Standardpasswort. Öffne `.env`, setze `SWITCHLY_ADMIN_PASSWORD` und starte den Container erneut:

```bash
docker compose up -d --build
```

</details>

<details>
<summary><strong>Warum ändert ein neuer Wert in .env mein bestehendes Passwort nicht?</strong></summary>

Die Variablen für den Administrator werden ausschließlich bei einer leeren Datenbank verwendet. Bestehende Passwörter werden im Profil geändert. Dadurch kann ein Deployment oder Update kein Konto unbemerkt zurücksetzen.

</details>

<details>
<summary><strong>Wo werden meine Daten gespeichert?</strong></summary>

Im Docker-Betrieb liegt die SQLite-Datenbank im benannten Volume `switchly_data`. Das Volume bleibt bei `docker compose down` erhalten, kann Zugangsdaten und Secrets enthalten und muss entsprechend geschützt werden.

</details>

## Switches und Monitoring

<details>
<summary><strong>Warum wird mein Switch als offline angezeigt?</strong></summary>

Prüfe Host, Port, Benutzername, Passwort, Routing und Firewall. Teste die Verbindung zuerst aus dem Container:

```bash
docker compose exec switchly \
  curl --digest -u 'admin:SWITCH-PASSWORT' \
  http://SWITCH-IP/sys.b
```

Weitere Schritte: [Fehlerdiagnose – Switch offline](reference.md#switch-ist-offline).

</details>

<details>
<summary><strong>Warum zeigt der Worker „noch kein Lauf“?</strong></summary>

Kontrolliere, ob `SWITCHLY_MONITOR_ENABLED=1` gesetzt ist, das Datenverzeichnis beschreibbar ist und der Container den Switch erreicht. Danach Worker und Health-Endpunkt prüfen:

```bash
docker compose logs --tail=200 switchly
docker compose exec switchly php /var/www/html/bin/monitor.php
curl http://127.0.0.1:8080/api/health.php
```

</details>

<details>
<summary><strong>Was bedeutet status: ok am Health-Endpunkt?</strong></summary>

`status: "ok"` bestätigt, dass der PHP-Endpunkt antwortet. Für das Hintergrund-Monitoring zusätzlich `background_monitor.fresh` prüfen. Nur ein frischer Heartbeat bestätigt einen aktuellen Worker-Lauf.

</details>

<details>
<summary><strong>Warum unterscheiden sich Dashboard und Live-Daten?</strong></summary>

Normale Dashboard-Aktualisierungen lesen den letzten Zustand aus dem SQLite-Cache. Die Schaltfläche **Aktualisieren** erzwingt eine direkte Abfrage. Kurzzeitige Abweichungen bis zum nächsten Worker-Lauf sind daher erwartbar.

</details>

## VLAN, LAG und Ports

<details>
<summary><strong>Warum sind VLAN oder LAG leer?</strong></summary>

Unterstützung und Feldformate unterscheiden sich je nach Modell und Firmware. Switchly verwendet `/lacp.b`, `/fwd.b` und `/vlan.b`. Prüfe diese Endpunkte direkt und verwende nicht pauschal `/lag.b` oder `/vlans.b`.

</details>

<details>
<summary><strong>Warum stimmt die angezeigte Portgeschwindigkeit nicht?</strong></summary>

`spd` enthält üblicherweise die ausgehandelte Ist-Geschwindigkeit, `spdc` die konfigurierte Soll-Geschwindigkeit. Bei Link-Down kann der Ist-Wert leer oder `0` sein. Auch Porttyp, Modul und Auto-Negotiation beeinflussen die Anzeige.

</details>

<details>
<summary><strong>Kann ich VLAN-, LAG- und Portänderungen gefahrlos testen?</strong></summary>

Solche Änderungen wirken unmittelbar auf das Gerät und können den Management-Zugang unterbrechen. Vorher eine `.swb`-Sicherung erstellen und zuerst einen Testport oder Test-Switch verwenden.

</details>

## Backup, Updates und Wiederherstellung

<details>
<summary><strong>Was muss ich vor einem Update sichern?</strong></summary>

Mindestens `.env` und das komplette `data/`-Verzeichnis. Für ein konsistentes Offline-Backup den Container kurz stoppen:

```bash
docker compose stop
docker run --rm -v switchly_data:/source:ro -v "$PWD:/backup" alpine:3.22 \
  tar -czf "/backup/switchly-backup-$(date +%F).tar.gz" -C /source .
docker compose start
```

</details>

<details>
<summary><strong>Gehen meine Daten bei docker compose down verloren?</strong></summary>

Nein. `docker compose down` entfernt Container und Netzwerk, nicht das benannte Docker-Volume `switchly_data`. Lösche das Volume nicht mit `docker compose down -v`, solange du keine geprüfte Sicherung besitzt.

</details>

<details>
<summary><strong>Wie aktualisiere ich eine bestehende Installation?</strong></summary>

Sicherung erstellen, neue Programmdateien einspielen, `.env` und `data/` beibehalten und das Image neu bauen:

```bash
docker compose down --remove-orphans
docker compose up -d --build
```

Anschließend Health-Endpunkt, Login, manuellen Refresh und Worker-Status prüfen.

</details>

## E-Mail, Webhooks und OpenID Connect

<details>
<summary><strong>Warum enthält eine Verifikationsmail die falsche URL?</strong></summary>

Setze `SWITCHLY_BASE_URL` auf die öffentliche HTTPS-Adresse und reiche hinter einem Reverse Proxy mindestens `Host` sowie `X-Forwarded-Proto` weiter.

```env
SWITCHLY_BASE_URL=https://monitor.example.de
```

</details>

<details>
<summary><strong>Warum sendet Discord oder mein Webhook nicht?</strong></summary>

Aktiviere Alerts und den Webhook-Kanal, verwende **Test senden** und prüfe DNS, Proxy und TLS aus dem Container. Eine Failure Threshold von `2` löst beim ersten Fehler noch keinen Down-Alert aus.

</details>

<details>
<summary><strong>Welche OIDC-Anbieter werden unterstützt?</strong></summary>

Switchly unterstützt einen standardkonformen OpenID-Connect-Anbieter, beispielsweise Keycloak. Verwendet werden Authorization Code Flow, PKCE, `state`, `nonce`, Discovery und JWKS. Es gibt keine separaten Google-, Microsoft- oder GitHub-Adapter.

</details>

<details>
<summary><strong>Warum schlägt der OIDC-Callback fehl?</strong></summary>

Die Callback-URL muss im Identity Provider exakt hinterlegt sein. Prüfe außerdem Discovery-URL, öffentliche CMS-URL, Client-Typ, Authentifizierungsmethode, `openid`-Scope, Systemzeit und TLS-Zertifikat.

</details>

## Sicherheit und Kompatibilität

<details>
<summary><strong>Warum kommuniziert Switchly per HTTP mit den Switches?</strong></summary>

Viele SwOS-Geräte stellen ihre Management-Endpunkte nur über HTTP Digest bereit. Digest schützt das Passwort während der Anmeldung, verschlüsselt aber nicht den gesamten Datenverkehr. Switchly und Geräte gehören daher in ein geschütztes Management-Netz oder VPN.

</details>

<details>
<summary><strong>Welche MikroTik-Switches sind kompatibel?</strong></summary>

Die Implementierung zielt insbesondere auf CRS3xx/CSS3xx mit SwitchOS 2.x. Da Endpunkte und Felder firmwareabhängig sind, ist eine pauschale Zusage für jedes Modell nicht möglich. Lesezugriffe und anschließend Schreibaktionen immer mit der konkreten Firmware testen.

</details>

## Noch keine Antwort gefunden?

1. [Fehlerdiagnose](reference.md#17-fehlerdiagnose) durcharbeiten.
2. [Bekannte Grenzen](reference.md#18-bekannte-grenzen) prüfen.
3. Container- und CMS-Logs ohne Zugangsdaten sammeln.
4. Ein Issue mit Switchly-Version, Umgebung, Switch-Modell und SwOS-Version erstellen.

> [!CAUTION]
> Datenbankinhalte, `.env`, Session-Cookies, Switch-Passwörter, TOTP-Secrets und vollständige Webhook-URLs niemals in ein öffentliches Issue kopieren.

---

<div align="center">
  <sub><a href="README.md">← Zur Doku-Übersicht</a> · <a href="../README.md">Zum Projekt</a></sub>
</div>

---

<a id="english"></a>

> [Deutsch](#deutsch) | **English**

![Switchly FAQ](images/readme-banner.png)


<div align="center">
  <h1>Frequently asked questions</h1>
  <p>Short answers to typical questions about installation, operation and SwOS.</p>

  <p>
    <a href="README.md#english">Documentation overview</a> ·
    <a href="reference.md#english">Complete reference</a> ·
    <a href="../SECURITY.md#english">Security</a>
  </p>
</div>

<a id="en-installation-and-registration"></a>

## Installation and sign-in

<details>
<summary><strong>What password do I use for the first login?</strong></summary>

Username and password come from `SWITCHLY_ADMIN_USER` and `SWITCHLY_ADMIN_PASSWORD` in your `.env`. The initial password must be at least 12 characters long and is only used when creating an empty database.

```env
SWITCHLY_ADMIN_USER=admin
SWITCHLY_ADMIN_PASSWORD=A-LONG-RANDOM-PASSWORD
```

</details>

<details>
<summary><strong>Why does Docker fail to start and request SWITCHLY_ADMIN_PASSWORD?</strong></summary>

Switchly intentionally has no default password. Open `.env`, set `SWITCHLY_ADMIN_PASSWORD`, and restart the container:

```bash
docker compose up -d --build
```

</details>

<details>
<summary><strong>Why doesn’t a new value in .env change my existing password?</strong></summary>

The variables for the administrator are used only for an empty database. Existing passwords are changed in the profile. As a result, a deployment or update cannot reset an account unnoticed.

</details>

<details>
<summary><strong>Where is my data stored?</strong></summary>

With Docker, the SQLite database is stored in the named `switchly_data` volume. The volume survives `docker compose down`, may contain credentials and secrets, and must therefore be protected accordingly.

</details>

<a id="en-switches-and-monitoring"></a>

## Switches and monitoring

<details>
<summary><strong>Why is my switch shown as offline?</strong></summary>

Check host, port, username, password, routing, and firewall. Test the connection from the container first:

```bash
docker compose exec switchly \
  curl --digest -u 'admin:SWITCH-PASSWORD' \
  http://SWITCH-IP/sys.b
```

Further steps: [Troubleshooting – Switch offline](reference.md#en-switch-is-offline).

</details>

<details>
<summary><strong>Why does the worker show “no run yet”?</strong></summary>

Verify that `SWITCHLY_MONITOR_ENABLED=1` is set, the data directory is writable, and the container can reach the switch. Then inspect the worker and health endpoint:

```bash
docker compose logs --tail=200 switchly
docker compose exec switchly php /var/www/html/bin/monitor.php
curl http://127.0.0.1:8080/api/health.php
```

</details>

<details>
<summary><strong>What does status: ok mean at the health endpoint?</strong></summary>

`status: "ok"` confirms that the PHP endpoint responds. When background monitoring is enabled, also check `background_monitor.fresh`. Only a fresh heartbeat confirms a recent worker run.

</details>

<details>
<summary><strong>Why are dashboard and live data different?</strong></summary>

Normal dashboard updates read the last state from the SQLite cache. The **Update** button forces a direct query. Short-term deviations until the next worker run are therefore expected.

</details>

<a id="en-vlan-lag-and-ports"></a>

## VLAN, LAG and ports

<details>
<summary><strong>Why are VLAN or LAG empty?</strong></summary>

Endpoint support and field formats vary by model and firmware. Switchly uses `/lacp.b`, `/fwd.b`, and `/vlan.b`. Check these endpoints directly instead of assuming `/lag.b` or `/vlans.b` is available.

</details>

<details>
<summary><strong>Why is the displayed port speed not correct?</strong></summary>

`spd` usually contains the negotiated link speed, while `spdc` contains the configured target speed. When the link is down, the actual value may be empty or `0`. Port type, transceiver, and auto-negotiation also affect the displayed value.

</details>

<details>
<summary><strong>Can I safely test VLAN, LAG and port changes?</strong></summary>

These changes take effect immediately and may interrupt management access. Create an `.swb` backup first and use a test port or non-production switch.

</details>

<a id="en-backup-updates-and-recovery"></a>

## Backup, Updates and Recovery

<details>
<summary><strong>What do I need to back up before an update?</strong></summary>

Back up at least `.env` and the complete `data/` directory. For a consistent offline backup, stop the container briefly:

```bash
docker compose stop
docker run --rm -v switchly_data:/source:ro -v "$PWD:/backup" alpine:3.22 \
  tar -czf "/backup/switchly-backup-$(date +%F).tar.gz" -C /source .
docker compose start
```

</details>

<details>
<summary><strong>Is my data lost at docker compose down?</strong></summary>

No. `docker compose down` removes the container and network, but not the named `switchly_data` Docker volume. Do not run `docker compose down -v` unless you have a verified backup.

</details>

<details>
<summary><strong>How do I update an existing installation?</strong></summary>

Create a backup, deploy the new application files, retain `.env` and `data/`, and rebuild the image:

```bash
docker compose down --remove-orphans
docker compose up -d --build
```

Then check health endpoint, login, manual refresh and worker status.

</details>

<a id="en-email-webhooks-and-openid-connect"></a>

## Email, Webhooks and OpenID Connect

<details>
<summary><strong>Why does a verification email contain the wrong URL?</strong></summary>

Set `SWITCHLY_BASE_URL` to the public HTTPS address. Behind a reverse proxy, forward at least `Host` and `X-Forwarded-Proto`.

```env
SWITCHLY_BASE_URL=https://monitor.example.de
```

</details>

<details>
<summary><strong>Why doesn’t Discord or my webhook send?</strong></summary>

Enable alerts and the webhook channel, select **Send test**, and check DNS, proxy, and TLS connectivity from the container. A failure threshold of `2` does not trigger a down alert after the first error.

</details>

<details>
<summary><strong>Which OIDC providers are supported?</strong></summary>

Switchly supports standards-compliant OpenID Connect providers such as Keycloak. It uses Authorization Code Flow, PKCE, `state`, `nonce`, discovery, and JWKS. There are no provider-specific Google, Microsoft, or GitHub adapters.

</details>

<details>
<summary><strong>Why does the OIDC callback fail?</strong></summary>

Register the callback URL exactly as shown in the identity provider. Also verify the discovery URL, public application URL, client type, authentication method, `openid` scope, system time, and TLS certificate.

</details>

<a id="en-safety-and-compatibility"></a>

## Security and compatibility

<details>
<summary><strong>Why does Switchly communicate with the switches via HTTP?</strong></summary>

Many SwitchOS devices only provide their management endpoints via HTTP Digest. Digest protects the password during login, but does not encrypt all traffic. Switchly and devices therefore belong in a protected management network or VPN.

</details>

<details>
<summary><strong>Which MikroTik switches are compatible?</strong></summary>

The implementation primarily targets CRS3xx and CSS3xx devices running SwitchOS 2.x. Because endpoints and fields vary by device family, compatibility cannot be guaranteed for every model. Test read operations first, followed by write operations, using the exact firmware version in your environment.

</details>

<a id="en-no-answer-yet"></a>

## No answer yet?

1. Work through [Troubleshooting](reference.md#en-17-error-diagnosis).
2. Review the [Known limitations](reference.md#en-18-known-limits).
3. Collect container and application logs without credentials.
4. Open an issue and include the Switchly version, environment, switch model, and SwitchOS firmware version.

> [!CAUTION]
> Never copy database contents, `.env`, session cookies, switch passwords, TOTP secrets, or complete webhook URLs into a public issue.

---

<div align="center">
  <sub><a href="README.md#english">← Documentation overview</a> · <a href="../README.md#english">Project</a></sub>
</div>
