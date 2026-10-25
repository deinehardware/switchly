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

Switchly verschlüsselt Switch-Zugangsdaten, TOTP-Secrets, SMTP-Passwörter, OAuth-Tokens und optionale Webhook-Secrets in SQLite per AES-256-GCM. Der separate `SWITCHLY_SECRET_KEY` muss zusammen mit Backups sicher und dauerhaft verwahrt werden; die Datenbank bleibt dennoch vertraulich. Die Kommunikation mit SwitchOS-Switches erfolgt üblicherweise per HTTP Digest und sollte ausschließlich in einem vertrauenswürdigen Management-Netz stattfinden.

Lokale Passwort- und TOTP-Anmeldungen besitzen getrennte temporäre Sperren pro Konto und Quell-IP. Ausgehende OIDC- und Webhook-Verbindungen blockieren standardmäßig private, Loopback-, Link-Local- und reservierte Ziele. Private Integrationsziele erfordern die bewusste Freigabe `SWITCHLY_ALLOW_PRIVATE_INTEGRATIONS=1`; private Management-Adressen für Switches bleiben zulässig.

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

Switchly encrypts switch credentials, TOTP secrets, SMTP passwords, OAuth tokens, and optional webhook secrets in SQLite with AES-256-GCM. The separate `SWITCHLY_SECRET_KEY` must be retained securely with backups; the database should still be treated as confidential. Communication with SwitchOS switches generally uses HTTP Digest and should take place only within a trusted management network.

Local password and TOTP authentication use separate temporary limits per account and source IP. Outbound OIDC and webhook connections block private, loopback, link-local, and reserved targets by default. Private integration targets require the explicit `SWITCHLY_ALLOW_PRIVATE_INTEGRATIONS=1` opt-in; private switch management addresses remain supported.

The Docker container runs as `www-data` without additional Linux capabilities, listens on unprivileged port 8080, and uses a read-only root filesystem. Only the data volume and temporary filesystem are writable. SMTP passwords are configured exclusively through the authenticated interface and are always redacted from API responses.
