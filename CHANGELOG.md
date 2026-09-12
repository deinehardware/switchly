<a id="deutsch"></a>

> **Deutsch** | [English](#english)

![Switchly](docs/images/readme-banner.png)

<div align="center">
  <h1>Changelog</h1>
  <p><a href="README.md">Projektübersicht</a> · <a href="docs/README.md">Dokumentation</a> · <a href="docs/faq.md">FAQ</a></p>
</div>

Diese Datei dokumentiert die offiziellen Veröffentlichungen von Switchly.

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
