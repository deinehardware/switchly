<a id="deutsch"></a>

> **Deutsch** | [English](#english)

![Switchly](docs/images/readme-banner.png)

<div align="center">
  <h1>Changelog</h1>
  <p><a href="README.md">Projektübersicht</a> · <a href="docs/README.md">Dokumentation</a> · <a href="docs/faq.md">FAQ</a></p>
</div>

Diese Datei dokumentiert die offiziellen Veröffentlichungen von Switchly.

## [1.4.9-beta] – 2026-10-25

### Geändert

- Das Container-Image verwendet jetzt PHP 8.5.12 auf Debian Trixie mit Apache 2.4.69.
- Für die Verschlüsselung gespeicherter Zugangsdaten ist die neue Umgebungsvariable `SWITCHLY_SECRET_KEY` erforderlich.
- Die Installations-, Konfigurations-, Betriebs- und Sicherheitsdokumentation wurde an die neuen Schutzmaßnahmen angepasst.
- Backups müssen neben der SQLite-Datenbank auch die `.env` mit dem zugehörigen `SWITCHLY_SECRET_KEY` enthalten.

### Behoben

- Vorhandene unverschlüsselte Switch-, SMTP-, OAuth-, Webhook- und TOTP-Geheimnisse werden beim ersten Start nach dem Upgrade automatisch und innerhalb einer Datenbanktransaktion verschlüsselt.
- DNS-Antworten ausgehender OIDC- und Webhook-Verbindungen werden validiert und an die jeweilige cURL-Anfrage gebunden. Dadurch werden DNS-Rebinding-Angriffe erschwert.
- Die Anmeldung verwendet auch bei unbekannten Benutzern einen Passworthash-Vergleich, um Rückschlüsse auf vorhandene Konten anhand unterschiedlicher Antwortzeiten zu verhindern.
- Veraltete oder widersprüchliche Hinweise zur Speicherung von Geheimnissen wurden in der Dokumentation korrigiert.

### Sicherheit

- PHP wurde auf Version 8.5.12 aktualisiert, um unter anderem `CVE-2026-109618` und `CVE-2026-109619` zu beheben.
- Apache wurde auf Version 2.4.69 aktualisiert, um bekannte Schwachstellen wie `CVE-2026-57941`, `CVE-2026-56154` und `CVE-2026-59797` zu beheben.
- Gespeicherte Geräte-, SMTP-, OAuth-, Webhook- und TOTP-Geheimnisse werden per AES-256-GCM verschlüsselt und gegen unbemerkte Veränderungen geschützt.
- Temporäre Login- und TOTP-Sperren pro Konto und Quell-IP verhindern unbegrenzte Brute-Force- und Password-Spraying-Versuche.
- Benutzernamen werden in der Rate-Limit-Datenbank ausschließlich als Hash gespeichert.
- Netzwerkzielprüfungen blockieren Loopback-, Link-Local-, reservierte und standardmäßig private Integrationsziele, ohne private Management-Adressen von Switches auszuschließen.
- Private OIDC- und Webhook-Ziele können bei Bedarf ausdrücklich über `SWITCHLY_ALLOW_PRIVATE_INTEGRATIONS=1` zugelassen werden.
- Eine Content Security Policy, Permissions Policy und Cross-Origin-Opener-Policy wurden ergänzt.
- Die PHP-Session-Konfiguration wurde weiter gehärtet.
- Neue automatisierte Regressionstests prüfen Verschlüsselung, Kontextbindung, Netzwerkzielschutz und Anmeldesperren.

> [!IMPORTANT]
> Vor dem ersten Start von Version 1.4.9 muss ein dauerhafter `SWITCHLY_SECRET_KEY` erzeugt und in `.env` eingetragen werden:
>
> ```bash
> openssl rand -base64 32
> ```
>
> Der Schlüssel darf anschließend nicht geändert oder verloren werden. Ohne den ursprünglichen Schlüssel können verschlüsselte Zugangsdaten nicht wiederhergestellt werden.

## [1.4.8-beta] – 2026-09-29

### Added

- Gmail-Konten können nun alternativ über OAuth 2.0 und die Gmail API angebunden werden.
- Einzelne Switches können vom Alert-Monitoring ausgeschlossen werden.
- Die Sprache der Benutzeroberfläche kann zwischen Deutsch und Englisch umgeschaltet werden.
- Unterstützung für den MikroTik CRS354-48G-4S+2Q+ hinzugefügt.

### Fixed

- Der E-Mail-Verbindungstest zeigt nun die konkrete SMTP-Fehlermeldung an.
- Nach einem erfolgreichen Alert-Test oder einer Änderung der Alert-Konfiguration werden zuvor fehlgeschlagene und noch nicht versendete Ausfallalarme automatisch reaktiviert. Dadurch können sie bereits beim nächsten Worker-Durchlauf erneut zugestellt werden.

## [1.4.7-beta] – 2026-09-12

**Erste Beta-Veröffentlichung auf GitHub**

### Umfang der Erstveröffentlichung

- zentrale Verwaltung mehrerer MikroTik-Switches mit SwitchOS;
- Dashboard mit Online-Status, Modell, Firmware, Uptime, Ports und Verkehrszählern;
- Verwaltung von Ports, Portnamen, Auto-Negotiation und Portgeschwindigkeiten;
- Konfiguration von LAG/LACP, VLAN-Tabellen, PVID und VLAN-Portzuweisungen;
- Neustart sowie Download und Wiederherstellung von SwitchOS-Konfigurationen;
- Hintergrund-Monitoring mit Verlauf, Status-Cache und Systemprotokollen;
- Ausfall- und Wiederherstellungsbenachrichtigungen per E-Mail, Discord oder generischem Webhook;
- SMTP-Konfiguration über die geschützte Administrationsoberfläche;
- lokale Benutzerkonten, E-Mail-Verifikation und TOTP-basierte Zwei-Faktor-Authentifizierung;
- Benutzer-, Gruppen- und switchbezogene Berechtigungen;
- generische OpenID-Connect-Anbindung, beispielsweise an Keycloak;
- Installation mit Docker Compose oder als Bare-Metal-System mit Apache und systemd;
- lokale Auslieferung von Chart.js und QRCode.js ohne externe CDN-Abhängigkeit;
- deutsch- und englischsprachige Projekt-, Installations- und Betriebsdokumentation.

### Plattform und Betrieb

- PHP 8.5 mit Apache und SQLite;
- Containerbetrieb als unprivilegierter Benutzer `www-data`;
- persistente Docker-Daten im benannten Volume `switchly_data`;
- Docker-Neustartrichtlinie `always`;
- Healthcheck für Webanwendung und Hintergrund-Worker;
- zentrale Versionsverwaltung über `VERSION` und `scripts/set-version.sh`;
- automatisierte Qualitätsprüfungen für PHP, JavaScript, CSS, Shell, Markdown, Projektstruktur und Versionskonsistenz.

### Sicherheit

- ausschließlich `public/` wird als Webroot veröffentlicht;
- schreibgeschütztes Container-Dateisystem mit gezielt freigegebenen Laufzeitpfaden;
- entfernte Linux-Capabilities und aktiviertes `no-new-privileges`;
- Session-, CSRF- und Berechtigungsprüfung für geschützte Aktionen;
- automatische Erzeugung eines sicheren initialen Administratorpassworts, wenn kein Passwort vorgegeben wurde;
- einmalige Ausgabe des generierten Administratorpassworts im Startprotokoll;
- SMTP-Passwörter werden in API-Antworten vollständig ausgeblendet;
- sensible Laufzeitdateien, Datenbanken und lokale Konfigurationen sind von der Veröffentlichung ausgeschlossen.

> **Hinweis:** Diese Version ist als Beta gekennzeichnet. Vor dem produktiven Einsatz sollten Backups, Berechtigungen, Netzwerkzugriffe und Wiederherstellungsabläufe in der eigenen Umgebung getestet werden.

---

<a id="english"></a>

> [Deutsch](#deutsch) | **English**

![Switchly](docs/images/readme-banner.png)

<div align="center">
  <h1>Changelog</h1>
  <p><a href="README.md#english">Project overview</a> · <a href="docs/README.md#english">Documentation</a> · <a href="docs/faq.md#english">FAQ</a></p>
</div>

This file documents official Switchly releases.

## [1.4.9-beta] – 2026-10-25

### Changed

- The container image now uses PHP 8.5.12 on Debian Trixie with Apache 2.4.69.
- The new `SWITCHLY_SECRET_KEY` environment variable is required to encrypt stored credentials.
- The installation, configuration, operations, and security documentation has been updated for the new protections.
- Backups must include both the SQLite database and the `.env` file containing the corresponding `SWITCHLY_SECRET_KEY`.

### Fixed

- Existing unencrypted switch, SMTP, OAuth, webhook, and TOTP secrets are encrypted automatically in a database transaction on the first start after upgrading.
- DNS responses used by outbound OIDC and webhook connections are validated and pinned to their respective cURL requests, mitigating DNS-rebinding attacks.
- Password verification is also performed for unknown usernames to prevent account enumeration through response-time differences.
- Outdated or contradictory documentation about stored secrets has been corrected.

### Security

- Updated PHP to version 8.5.12 to address vulnerabilities including `CVE-2026-109618` and `CVE-2026-109619`.
- Updated Apache to version 2.4.69 to address known vulnerabilities including `CVE-2026-57941`, `CVE-2026-56154`, and `CVE-2026-59797`.
- Stored device, SMTP, OAuth, webhook, and TOTP secrets are encrypted and authenticated using AES-256-GCM.
- Temporary login and TOTP limits per account and source IP prevent unlimited brute-force and password-spraying attempts.
- Usernames are stored only as hashes in the rate-limit database.
- Network target validation blocks loopback, link-local, reserved, and private integration targets by default without blocking private switch-management addresses.
- Private OIDC and webhook targets can be explicitly enabled with `SWITCHLY_ALLOW_PRIVATE_INTEGRATIONS=1`.
- Added a Content Security Policy, Permissions Policy, and Cross-Origin-Opener-Policy.
- Added further PHP session hardening.
- New automated regression tests cover encryption, context binding, network-target protection, and authentication limits.

> [!IMPORTANT]
> Before starting version 1.4.9 for the first time, generate a persistent `SWITCHLY_SECRET_KEY` and add it to `.env`:
>
> ```bash
> openssl rand -base64 32
> ```
>
> Do not change or lose this key after deployment. Encrypted credentials cannot be recovered without the original key.

## [1.4.8-beta] – 2026-09-29

### Added

- Gmail accounts can now be connected using OAuth 2.0 and the Gmail API as an alternative to password-based SMTP authentication.
- Individual switches can now be excluded from alert monitoring.
- The user interface language can now be switched between German and English.
- Added support for the MikroTik CRS354-48G-4S+2Q+.

### Fixed

- The email connection test now displays the specific SMTP error message.
- After a successful alert test or a change to the alert configuration, previously failed and unsent outage alerts are automatically reactivated. They can therefore be delivered again during the next worker run.

## [1.4.7-beta] – 2026-09-12

**Initial public beta release on GitHub**

### Initial release scope

- centralized management of multiple MikroTik switches running SwitchOS;
- dashboard showing online status, model, firmware, uptime, ports, and traffic counters;
- management of port state, port names, auto-negotiation, and port speeds;
- configuration of LAG/LACP, VLAN tables, PVID, and VLAN port assignments;
- switch reboot and download or restoration of SwitchOS configurations;
- background monitoring with history, status caching, and system logs;
- outage and recovery notifications by email, Discord, or generic webhook;
- SMTP configuration through the protected administration interface;
- local user accounts, email verification, and TOTP-based two-factor authentication;
- user, group, and switch-specific permissions;
- generic OpenID Connect integration, for example with Keycloak;
- installation with Docker Compose or as a bare-metal system using Apache and systemd;
- local delivery of Chart.js and QRCode.js without an external CDN dependency;
- German and English project, installation, and operations documentation.

### Platform and operation

- PHP 8.5 with Apache and SQLite;
- containers run as the unprivileged `www-data` user;
- persistent Docker data stored in the named `switchly_data` volume;
- Docker restart policy set to `always`;
- health checks for the web application and background worker;
- centralized version management through `VERSION` and `scripts/set-version.sh`;
- automated quality checks for PHP, JavaScript, CSS, shell scripts, Markdown, project structure, and version consistency.

### Security

- only `public/` is exposed as the web root;
- read-only container filesystem with explicitly writable runtime paths;
- all Linux capabilities are dropped and `no-new-privileges` is enabled;
- session, CSRF, and permission checks protect administrative actions;
- a secure initial administrator password is generated automatically when no password is provided;
- the generated administrator password is printed once in the startup log;
- SMTP passwords are fully redacted from API responses;
- sensitive runtime files, databases, and local configuration are excluded from publication.

> **Note:** This version is marked as beta. Before production use, test backups, permissions, network access, and recovery procedures in your own environment.