<a id="deutsch"></a>

> **Deutsch** | [English](#english)

![Switchly](docs/images/readme-banner.png)

<div align="center">
  <h1>Zu Switchly beitragen</h1>
  <p><a href="README.md">Projektübersicht</a> · <a href="docs/reference.md#20-support-checkliste-für-änderungen">Entwicklung</a> · <a href="SECURITY.md">Sicherheit</a></p>
</div>

Vielen Dank für dein Interesse an Switchly. Beiträge sollten nachvollziehbar, sicher und in möglichst überschaubarem Umfang eingereicht werden.

## Lizenz für Beiträge

Switchly steht unter der GNU General Public License Version 3.0 (`GPL-3.0-only`). Mit dem Einreichen eines Beitrags bestätigst du, dass du den Beitrag unter dieser Lizenz veröffentlichen darfst.

## Vor einem Beitrag

1. Prüfe bestehende Issues und Pull Requests auf mögliche Überschneidungen.
2. Beschreibe Fehler möglichst genau und nenne das verwendete Switch-Modell, die installierte SwitchOS-Firmwareversion sowie das erwartete und das tatsächliche Verhalten.
3. Sicherheitsprobleme nicht öffentlich melden; dafür gilt [SECURITY.md](SECURITY.md).

## Lokale Einrichtung

```bash
cp .env.example .env
docker compose up -d --build
bash ./scripts/quality-check.sh
```

Verwende ausschließlich Testzugänge und ein isoliertes Management-Netz. Reboot, Restore, VLAN-, LAG- und Portänderungen müssen zuerst an einem Testgerät geprüft werden.

## Code-Konventionen

- PHP mit `declare(strict_types=1)` und typisierten Signaturen schreiben.
- Eingaben an der API-Grenze validieren und Datenbankwerte binden.
- Schreibende API-Aufrufe nur als `POST` mit CSRF-Prüfung zulassen.
- Berechtigungs- und Switch-Zugriffsprüfungen nicht umgehen.
- Nicht offensichtliche SwitchOS-Formate und Firmware-Eigenheiten direkt am Code dokumentieren.
- Keine Zugangsdaten, Datenbanken, Backups oder produktiven Hostnamen committen.
- Benutzertexte auf Deutsch und technische Bezeichner konsistent auf Englisch halten.

## Pull Requests

Ein Pull Request sollte enthalten:

- eine klare Beschreibung des Problems und der gewählten Lösung
- Angaben zu betroffenen Komponenten und möglichen Risiken
- nachvollziehbare Testschritte
- bei Änderungen an der Benutzeroberfläche aussagekräftige Screenshots
- bei Änderungen an der SwitchOS-Translation-API das getestete Switch-Modell und die installierte Firmwareversion
- eine aktualisierte Dokumentation und `CHANGELOG.md`, sofern sich das sichtbare Verhalten ändert

Vor dem Einreichen muss `bash ./scripts/quality-check.sh` erfolgreich durchlaufen.

---

<a id="english"></a>

> [Deutsch](#deutsch) | **English**

![Switchly](docs/images/readme-banner.png)

<div align="center">
  <h1>Contribute to Switchly</h1>
  <p><a href="README.md#english">Project overview</a> · <a href="docs/reference.md#en-20-support-checklist-for-changes">Development</a> · <a href="SECURITY.md#english">Security</a></p>
</div>

Thank you for your interest in Switchly. Contributions should be clear, secure, and limited to a manageable scope.

<a id="en-license-for-contributions"></a>

## License for contributions

Switchly is licensed under the GNU General Public License version 3.0 (`GPL-3.0-only`). By submitting a contribution, you confirm that you have the right to publish it under this license.

<a id="en-before-a-contribution"></a>

## Before a contribution

1. Check existing issues and pull requests for possible overlaps.
2. Describe errors as accurately as possible and specify the switch model used, the SwitchOS firmware version installed, and the expected and actual behavior.
3. Do not report security issues publicly; follow [SECURITY.md](SECURITY.md#english) instead.

<a id="en-local-setup"></a>

## Local setup

```bash
cp .env.example .env
docker compose up -d --build
bash ./scripts/quality-check.sh
```

Use test credentials only and work in an isolated management network. Test reboots, restores, and changes to VLANs, LAGs, or ports on a non-production device first.

<a id="en-code-conventions"></a>

## Code conventions

- PHP with `declare(strict_types=1)` and typed signatures.
- Validate inputs at the API boundary and bind database values.
- Allow write operations only through `POST` requests protected by CSRF validation.
- Do not circumvent authorization and switch access checks.
- Document non-obvious SwitchOS formats and firmware-specific behavior directly in the code.
- Never commit credentials, databases, backups, or production hostnames.
- Keep user texts in German and technical identifiers consistent in English.

<a id="en-pull-requests"></a>

## Pull Requests

A pull request should include:

- A clear description of the problem and the chosen solution
- Information on components affected and potential risks
- Reproducible test steps
- Meaningful screenshots for changes to the user interface
- For changes to the SwitchOS translation API, the tested switch model and installed firmware version
- Updated documentation and `CHANGELOG.md` when the visible behavior changes

Before submitting, make sure `bash ./scripts/quality-check.sh` completes successfully.
