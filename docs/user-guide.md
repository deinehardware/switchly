<a id="deutsch"></a>

> **Deutsch** | [English](#english)

# Bedienungsanleitung

## Ersteinrichtung

1. Mit dem konfigurierten Administratorkonto anmelden.
2. Im Profil TOTP-2FA aktivieren.
3. Unter **Switches** ein Gerät mit ID, Name, Host, Port und Digest-Zugangsdaten anlegen.
4. Über **Live aktualisieren** die Gerätekommunikation prüfen.
5. Benutzer, Gruppen, Rechte und Switch-Freigaben einrichten.
6. Vor schreibenden Geräteänderungen ein `.swb`-Backup erstellen.

## Cache und Live-Daten

Die normale Oberfläche liest System- und Portinformationen aus dem SQLite-Cache. Der Hintergrund-Worker aktualisiert diesen Cache regelmäßig. Eine manuelle Aktualisierung fragt den Switch unmittelbar ab und kann deshalb bis zum Geräte-Timeout dauern.

VLAN- und LAG-Konfigurationen werden live vom Gerät gelesen und nicht historisch versioniert.

## Ports

Je nach Berechtigung können Ports aktiviert, deaktiviert, umbenannt und hinsichtlich Auto-Negotiation oder Geschwindigkeit konfiguriert werden. Unterstützte Geschwindigkeiten hängen von Switch, Porttyp, Firmware und Transceiver ab.

## VLAN

Switchly verwaltet:

- PVID pro Port
- VLAN-Modus `Disabled`, `Optional`, `Enabled` oder `Strict`
- Annahme von beliebigen, nur getaggten oder nur ungetaggten Frames
- Force VLAN ID
- VLAN-IDs 1 bis 4094, Namen und Mitgliedsports

Eine fehlerhafte VLAN-Änderung kann den Management-Zugang unterbrechen. Änderungen immer zuerst an einem Testport prüfen.

## LAG/LACP

Pro Port stehen Passive LACP, Active LACP und Static sowie die Gruppen 0 bis 16 zur Verfügung. Switchly verwendet den SwitchOS-Endpunkt `/lacp.b`.

## Backup, Restore und Reboot

- **Backup** lädt die aktuelle `.swb`-Konfiguration herunter.
- **Restore** akzeptiert Dateien bis 16 MiB und startet den Switch anschließend neu.
- **Reboot** unterbricht die Geräteverbindung unmittelbar.

Diese Aktionen benötigen eigene Berechtigungen oder das Sammelrecht `switch.control`.

## Benutzer und Rechte

Administratoren besitzen Vollzugriff. Normale Benutzer erhalten Rechte direkt oder über Gruppen. Ohne Switch-Zuordnung sind alle Geräte sichtbar; sobald mindestens eine direkte oder geerbte Zuordnung existiert, gilt die resultierende Liste als Allowlist.

Die vollständigen Rechtecodes stehen in der [API- und Berechtigungsreferenz](reference.md#97-berechtigungen-und-gruppen).

## SMTP und Alerts

Unter **Alerts** können berechtigte Benutzer Ausfall- und Wiederherstellungsalarme aktivieren, Empfänger verwalten und SMTP konfigurieren. Trage Host, Port, Verschlüsselung, Absender und bei Bedarf Zugangsdaten ein. Mit **Test senden** lässt sich die Konfiguration unmittelbar prüfen. Ein leeres Passwortfeld behält das vorhandene SMTP-Passwort bei.

---

<a id="english"></a>

> [Deutsch](#deutsch) | **English**

<a id="en-user-guide"></a>

# User guide

<a id="en-initial-equipment"></a>

## Initial setup

1. Sign in with the configured administrator account.
2. Enable TOTP-based 2FA in your profile.
3. Create a device under **Switches** with an ID, name, host, port, and Digest credentials.
4. Check device communication via **Live Update**.
5. Set up users, groups, permissions, and switch access assignments.
6. Create an `.swb` backup before making changes to a device.

<a id="en-cache-and-live-data"></a>

## Cache and live data

The normal interface reads system and port information from the SQLite cache. The background worker updates this cache regularly. A manual update requests the switch immediately and can therefore take until the device timeout.

VLAN and LAG configurations are read live from the device and are not historically versioned.

<a id="en-ports"></a>

## Ports

Depending on permission, ports can be activated, deactivated, renamed and configured for auto-negotiation or speed. Supported speeds depend on the switch, port type, firmware and transceiver.

<a id="en-vlan"></a>

## VLAN

Switchly manages:

- PVID per port
- VLAN mode `Disabled`, `Optional`, `Enabled` or `Strict`
- Acceptance of any, tagged or untagged frames
- Force VLAN ID
- VLAN IDs 1 to 4094, names and member ports

A faulty VLAN change can interrupt management access. Always check changes on a test port first.

<a id="en-laglacp"></a>

## LAG/LACP

Passive LACP, active LACP, static mode, and groups 0 through 16 are available for each port. Switchly uses the SwitchOS endpoint `/lacp.b`.

<a id="en-backup-restore-and-reboot"></a>

## Backup, Restore and Reboot

- **Backup** downloads the current `.swb` configuration.
- **Restore** accepts files up to 16 MiB and then restarts the switch.
- **Reboot*** interrupts the device connection immediately.

These actions require their respective permissions or the aggregate permission `switch.control`.

<a id="en-users-and-rights"></a>

## Users and permissions

Administrators have full access. Regular users receive permissions directly or through groups. If no switch is assigned, all devices are visible. Once at least one direct or inherited assignment exists, the resulting list acts as an allowlist.

The complete permission-code list is available in the [API and permissions reference](reference.md#en-97-permissions-and-groups).

<a id="en-smtp-and-alerts"></a>

## SMTP and alerts

Under **Alerts**, authorized users can enable outage and recovery notifications, manage recipients, and configure SMTP. Enter the host, port, encryption mode, sender, and credentials when required. Use **Send test** to verify the configuration immediately. Leaving the password field empty preserves the existing SMTP password.
