<a id="deutsch"></a>

> **Deutsch** | [English](#english)

![Switchly](docs/images/readme-banner.png)

<div align="center">
  <h1>Sicherheitsrichtlinie</h1>
  <p><a href="README.md">Projektübersicht</a> · <a href="docs/README.md">Dokumentation</a> · <a href="docs/faq.md">FAQ</a></p>
</div>

## Sicherheitsproblem melden

Bitte keine öffentlichen Issues für Schwachstellen anlegen. Nutze bevorzugt **Private vulnerability reporting** im GitHub-Repository. Falls diese Funktion nicht aktiviert ist, kontaktiere den Repository-Eigentümer über einen bereits bekannten, nicht öffentlichen Kanal.

Eine Meldung sollte enthalten:

- betroffene Version und Komponente
- technische Beschreibung und Auswirkungen
- reproduzierbare Schritte oder einen minimalen Nachweis
- bekannte Voraussetzungen und mögliche Abhilfen
- gewünschte Namensnennung, falls zutreffend

Zugangsdaten, Tokens, Datenbanken und personenbezogene Daten dürfen nicht in die Meldung aufgenommen werden. Nach Eingang sollte innerhalb von sieben Tagen eine erste Rückmeldung erfolgen; ein Veröffentlichungstermin wird abhängig von Schweregrad und Verfügbarkeit einer Korrektur abgestimmt.

## Betriebsgrenzen

Switchly speichert Switch-Zugangsdaten, TOTP-Secrets, OIDC-Client-Secrets und optionale Webhook-Secrets in der SQLite-Datenbank. Die Datenbank ist deshalb wie ein Secret zu behandeln. Die Kommunikation mit SwitchOS-Switches erfolgt üblicherweise per HTTP Digest und sollte ausschließlich in einem vertrauenswürdigen Management-Netz stattfinden.

Der Docker-Container läuft als `www-data` ohne zusätzliche Linux-Capabilities, verwendet Port 8080 statt eines privilegierten Ports und besitzt ein schreibgeschütztes Root-Dateisystem. Nur das Daten-Volume und das temporäre Dateisystem sind beschreibbar. SMTP-Passwörter werden ausschließlich über die authentifizierte Oberfläche gesetzt und in API-Antworten immer redigiert.

---

<a id="english"></a>

> [Deutsch](#deutsch) | **English**

![Switchly](docs/images/readme-banner.png)

<div align="center">
  <h1>Security Policy</h1>
  <p><a href="README.md#english">Project overview</a> · <a href="docs/README.md#english">Documentation</a> · <a href="docs/faq.md#english">FAQ</a></p>
</div>

<a id="en-report-security-issues"></a>

## Report security issues

Do not create public issues for vulnerabilities. Use **Private vulnerability reporting** in the GitHub repository. If that feature is unavailable, contact the repository owner through an established private channel.

A report should include:

- Affected version and component
- Technical description and potential impact
- Reproducible steps or a minimal proof of concept
- Known prerequisites and possible mitigations
- Preferred attribution, if applicable

Do not include credentials, tokens, databases, or personal data in the report. We aim to provide an initial response within seven days. A disclosure date will be agreed upon based on severity and the availability of a fix.

<a id="en-operational-limits"></a>

## Operational limits

Switchly stores switch credentials, TOTP secrets, OIDC client secrets, and optional webhook secrets in its SQLite database. Treat the database as confidential. Communication with SwitchOS switches generally uses HTTP Digest and should take place only within a trusted management network.

The Docker container runs as `www-data` without additional Linux capabilities, listens on unprivileged port 8080, and uses a read-only root filesystem. Only the data volume and temporary filesystem are writable. SMTP passwords are configured exclusively through the authenticated interface and are always redacted from API responses.
