'use strict';

/** Lightweight browser translation layer for static and dynamically rendered UI text. */
(() => {
  const language = document.body.dataset.language === 'en' ? 'en' : 'de';
  const english = {
    'NAVIGATION':'NAVIGATION','Switch \u00DCbersicht':'Switch overview','Switches':'Switches','Monitoring':'Monitoring',
    'System Logs':'System logs','Benutzerverwaltung':'User management','Rechteverwaltung':'Permission management',
    'Alerts':'Alerts','Hinzuf\u00FCgen':'Add','Sprache':'Language','Sprache ausw\u00E4hlen':'Select language',
    'Standardsprache':'Default language','Deutsch':'German','Angemeldet als:':'Signed in as:','LOKALISIERUNG':'LOCALIZATION',
    'Spracheinstellungen':'Language settings','W\u00E4hle deine pers\u00F6nliche Oberfl\u00E4chensprache.':'Choose your personal interface language.','Standardsprache ausw\u00E4hlen':'Select default language',
    'Meine Sprache':'My language','Gilt nur f\u00FCr dein Benutzerkonto':'Applies only to your user account',
    'F\u00FCr Anmeldung und Benutzer ohne eigene Auswahl':'For sign-in and users without their own selection','Standard speichern':'Save default',
    'BENUTZER':'USER','Profil & 2FA':'Profile & 2FA','Abmelden':'Sign out','INFRASTRUKTUR':'INFRASTRUCTURE',
    'Aktualisieren':'Refresh','Switch hinzuf\u00FCgen':'Add switch','Alle Level':'All levels','WARNUNG':'WARNING',
    'Letzte 24 Stunden':'Last 24 hours','Letzte 7 Tage':'Last 7 days','Letzte 30 Tage':'Last 30 days',
    'Alle':'All','Suche...':'Search...','ZEITPUNKT':'TIME','BENUTZER':'USER','AKTION':'ACTION','DETAILS':'DETAILS',
    'Lade Logs\u2026':'Loading logs\u2026','L\u00F6schen':'Delete','MODELL':'MODEL','TEMPERATUR':'TEMPERATURE','SPANNUNG':'VOLTAGE',
    'PAKET-VERKEHR (RX vs. TX)':'PACKET TRAFFIC (RX vs. TX)','Empfangene (RX) & Gesendete (TX) Pakete pro Port':'Received (RX) & sent (TX) packets per port',
    'Live-Status der Switch-Schnittstellen':'Live status of switch interfaces','PORT STATUS & STATISTICS':'PORT STATUS & STATISTICS',
    'Ports geladen':'Ports loaded','Port suchen (z.B. Port 1)...':'Search port (e.g. Port 1)...','GESCHWINDIGKEIT':'SPEED',
    'AKTIONEN':'ACTIONS','Lade Port-Daten...':'Loading port data...','Passive, Active oder statische Gruppen pro Port':'Passive, active or static groups per port',
    'MODUS':'MODE','GRUPPE':'GROUP','AKTION':'ACTION','Lade LAG-Konfiguration\u2026':'Loading LAG configuration\u2026',
    'PVID, Filtermodus und eingehende Frames':'PVID, filtering mode and incoming frames','EMPFANG':'RECEIVE',
    'Lade VLAN-Konfiguration\u2026':'Loading VLAN configuration\u2026','VLAN-TABELLE':'VLAN TABLE','VLAN anlegen':'Create VLAN',
    'Switch-Verwaltung':'Switch management','Alle verwalteten Ger\u00E4te und Schnellaktionen':'All managed devices and quick actions',
    'Noch keine Switches vorhanden.':'No switches yet.','\u00D6ffnen \u2192':'Open \u2192','Live aktualisieren':'Refresh live',
    'Aktiv':'Active','Letztes Update:':'Last update:','Noch keine Daten':'No data yet','Neuen MikroTik Switch \u00FCberwachen':'Monitor a new MikroTik switch',
    'Neuen Switch hinzuf\u00FCgen':'Add new switch','MikroTik SwOS Switch zur \u00DCberwachung hinzuf\u00FCgen.':'Add a MikroTik SwOS switch for monitoring.',
    'IP-Adresse / Host':'IP address / host','Benutzername':'Username','Passwort':'Password',
    'Leer lassen, falls keines gesetzt ist':'Leave empty if none is configured','Alert-Monitoring f\u00FCr diesen Switch aktivieren':'Enable alert monitoring for this switch',
    'Abbrechen':'Cancel','Speichern':'Save','Switch-Down Alerts':'Switch-down alerts','ALLGEMEIN':'GENERAL',
    'Ausfall-Alerts aktivieren':'Enable outage alerts','Fehlversuche vor Alarm':'Failures before alert','Cooldown (Minuten)':'Cooldown (minutes)',
    'Recovery senden, wenn Switch wieder online ist':'Send recovery when switch is online again','ALERT-STATUS':'ALERT STATUS',
    'Hintergrund-Worker: Status wird geladen\u2026':'Background worker: loading status\u2026','FEHLVERSUCHE':'FAILURES',
    'ALARM GESENDET':'ALERT SENT','LETZTE \u00C4NDERUNG':'LAST CHANGE','Ja':'Yes','Nein':'No','UNBEKANNT':'UNKNOWN',
    'Alert-Monitoring aktiv':'Alert monitoring enabled','Von Alerts ausgenommen':'Excluded from alerts','AUSGENOMMEN':'EXCLUDED','Alert-Monitoring umschalten':'Toggle alert monitoring',
    'KONTO':'ACCOUNT','PASSWORT':'PASSWORD','\u00C4ndere das Kennwort f\u00FCr dein Konto.':'Change your account password.',
    'Aktuelles Passwort':'Current password','Neues Passwort':'New password','Passwort \u00E4ndern':'Change password',
    'ZWEI-FAKTOR-AUTHENTIFIZIERUNG':'TWO-FACTOR AUTHENTICATION','Code eingeben':'Enter code',
    '\u00D6ffne deine Authenticator-App und gib den 6-stelligen Code ein.':'Open your authenticator app and enter the 6-digit code.',
    '6-stelliger Code':'6-digit code','ANMELDUNG':'SIGN IN','Willkommen zur\u00FCck':'Welcome back','Anmelden':'Sign in',
    'oder mit SSO':'or with SSO','Keine Logs vorhanden.':'No logs available.','Noch keine Gruppen vorhanden.':'No groups yet.',
    'ADMINISTRATION':'ADMINISTRATION','ANGELEGT':'CREATED','ANTWORT':'RESPONSE','BETRIEB':'OPERATION',
    'BENUTZERRECHTE':'USER PERMISSIONS','CLIENT':'CLIENT','EREIGNISPROTOKOLL':'EVENT LOG','FEHLER':'ERRORS',
    'GRUPPEN':'GROUPS','LETZTE MESSWERTE':'LATEST SAMPLES','LEVEL':'LEVEL','MONITORING':'MONITORING',
    'PORTS AKTIV':'ACTIVE PORTS','PROVIDER':'PROVIDER','QUELLE':'SOURCE','ROLLE':'ROLE','SWITCHES ONLINE':'SWITCHES ONLINE',
    'TRANSPORT':'TRANSPORT','VERF\u00dcGBARKEIT & PORTS':'AVAILABILITY & PORTS','ZEIT':'TIME',
    'Absenderadresse':'Sender address','Achte darauf, den Management-Port nicht versehentlich aus dem VLAN zu entfernen.':'Be careful not to accidentally remove the management port from the VLAN.',
    'Administrator':'Administrator','Anzeigename':'Display name','Backup-Datei (.swb)':'Backup file (.swb)',
    'Benutzer':'User','Benutzer hinzuf\u00fcgen':'Add user','Benutzer in dieser Gruppe':'Users in this group','Benutzername-Claim':'Username claim',
    'Benutzerrechte':'User permissions','Beschreibung':'Description','CMS + Stack':'CMS + stack',
    'CMS, Stack oder einzelner Switch per Dropdown':'CMS, stack or individual switch via dropdown','Callback-URL im Provider':'Callback URL in provider',
    'Client-Authentifizierung':'Client authentication','Client-ID':'Client ID','Client-Secret':'Client secret',
    'Direkte Rechte erg\u00e4nzen Gruppenrechte. Ohne Switch-Auswahl gelten alle Switches.':'Direct permissions supplement group permissions. Without a switch selection, all switches apply.',
    'Direkte Rechte, Gruppen und einzelne Switches':'Direct permissions, groups and individual switches',
    'Discord wird automatisch als formatierte Nachricht gesendet':'Discord is sent automatically as a formatted message',
    'Discovery testen':'Test discovery','Discovery-URL':'Discovery URL','E-Mail auch ohne email_verified vertrauen':'Trust email even without email_verified',
    'E-Mail-Adresse':'Email address','E-Mail-Benachrichtigung':'Email notification','E-Mail-Claim':'Email claim',
    'Eine .swb-Datei hochladen. Die aktuelle Konfiguration kann \u00fcberschrieben werden.':'Upload an .swb file. The current configuration may be overwritten.',
    'Einstellungen speichern':'Save settings','Einzelner Switch':'Individual switch','Empf\u00e4nger (Komma oder Leerzeichen getrennt)':'Recipients (comma or space separated)',
    'Erlaubte E-Mail-Domains':'Allowed email domains','Gemeinsame Rechte und Switch-Zugriffe':'Shared permissions and switch access',
    'Gesamter Stack':'Entire stack','Gesamter Switch-Stack':'Entire switch stack','Gruppe hinzuf\u00fcgen':'Add group','Gruppe speichern':'Save group','Gruppen':'Groups',
    'Historische Samples aus den automatischen Abfragen':'Historical samples from automatic polls','Keycloak oder ein anderer standardkonformer OIDC-Anbieter':'Keycloak or another standards-compliant OIDC provider',
    'Kompatibel mit Google Authenticator, Microsoft Authenticator, Aegis und anderen TOTP-Apps.':'Compatible with Google Authenticator, Microsoft Authenticator, Aegis and other TOTP apps.',
    'Konten, E-Mail-Verifikation, Rollen und 2FA verwalten':'Manage accounts, email verification, roles and 2FA',
    'Konto, Rolle und Zugangsdaten konfigurieren.':'Configure account, role and credentials.',
    'Lade VLAN-Portkonfiguration\u2026':'Loading VLAN port configuration\u2026','Lade VLAN-Tabelle\u2026':'Loading VLAN table\u2026','Letzte Stunde':'Last hour',
    'Mitglieder, Gruppenrechte und Switch-Zugriffe zentral verwalten.':'Centrally manage members, group permissions and switch access.',
    'Mitgliedsports':'Member ports','Namens-Claim':'Name claim','Nur CMS':'CMS only','OpenID Connect speichern':'Save OpenID Connect',
    'OpenID-Connect-Anmeldung aktivieren':'Enable OpenID Connect sign-in','\u00d6ffentliche CMS-URL':'Public CMS URL','\u00d6ffentlicher Client ohne Secret':'Public client without secret',
    'Rechte':'Permissions','Rechte speichern':'Save permissions','Restore starten':'Start restore','Rolle':'Role',
    'SMTP wird sicher in Switchly verwaltet; Passw\u00f6rter werden nie zur\u00fcckgegeben':'SMTP is securely managed in Switchly; passwords are never returned',
    'SMTP-Benutzername':'SMTP username','SMTP-Host':'SMTP host','SMTP-Passwort':'SMTP password','SMTP-Port':'SMTP port',
    'Gmail OAuth2 verwenden':'Use Gmail OAuth2','Google OAuth Client-ID':'Google OAuth client ID','Google OAuth Client-Secret':'Google OAuth client secret',
    'Gmail-Absenderadresse':'Gmail sender address','Mit Google verbinden':'Connect Google','NICHT VERBUNDEN':'NOT CONNECTED','VERBUNDEN':'CONNECTED',
    'Optional statt SMTP-Passwort verwenden':'Optionally use instead of an SMTP password','Callback-URL':'Callback URL',
    'Zuerst Einstellungen speichern. In Google als autorisierte Weiterleitungs-URI die angezeigte Callback-URL eintragen.':'Save the settings first. Add the displayed callback URL in Google as an authorized redirect URI.',
    'Schlie\u00dfen':'Close','Scopes':'Scopes','Secret / Token':'Secret / token','Sichere Standardwerte beibehalten':'Keep secure defaults',
    'Switch w\u00e4hlen':'Select switch','Switch, Status, Ports, Traffic und Antwortzeit':'Switch, status, ports, traffic and response time',
    'Switch-Konfiguration wiederherstellen':'Restore switch configuration','Switch-Zugriff':'Switch access',
    'TLS-Zertifikat pr\u00fcfen':'Verify TLS certificate','Test senden':'Send test','Unabh\u00e4ngige Pr\u00fcfung im Container, auch ohne ge\u00f6ffnetes Dashboard':'Independent checks in the container, even without an open dashboard',
    'Unbekannte Benutzer automatisch anlegen':'Automatically create unknown users','Unsicheres HTTP erlauben (nur Testnetz)':'Allow insecure HTTP (test network only)',
    'Unverarbeitete Antwort des ausgew\u00e4hlten Switches.':'Raw response from the selected switch.',
    'VLAN speichern':'Save VLAN','VLAN-ID, Name und Mitgliedsports konfigurieren.':'Configure VLAN ID, name and member ports.',
    'VLANs anlegen, bearbeiten, l\u00f6schen und Ports zuweisen':'Create, edit and delete VLANs and assign ports',
    'Verifiziert-Claim':'Verified claim','Verschl\u00fcsselung':'Encryption','Vor dem Restore bitte ein aktuelles Backup herunterladen.':'Download a current backup before restoring.',
    'Webhook-Benachrichtigung':'Webhook notification','Webhook-URL':'Webhook URL','Zugangsdaten und Token-Endpoint-Authentifizierung':'Credentials and token endpoint authentication',
    '1. Scanne den QR-Code mit deiner Authenticator-App.':'1. Scan the QR code with your authenticator app.',
    '2. Alternativ kannst du den Schl\u00fcssel manuell eingeben:':'2. Alternatively, enter the key manually:',
    '6-stelliger Best\u00e4tigungscode':'6-digit confirmation code','2FA aktivieren':'Enable 2FA','otpauth-URI anzeigen':'Show otpauth URI'
  };
  const patterns = [
    [/^Letztes Update:\s*(.+)$/,'Last update: $1'],
    [/^Hintergrund-Worker:\s*(.+)$/,'Background worker: $1'],
    [/^(\d+) Switches gepr\u00FCft$/,'$1 switches checked'],
    [/^Switch "(.+)" wirklich l\u00F6schen\?$/,'Really delete switch "$1"?']
  ];
  const logExact = {
    'Oberfl\u00E4chensprache ge\u00E4ndert.':'Interface language changed.',
    'Standardsprache ge\u00E4ndert.':'Default language changed.',
    '2FA-Anmeldung erfolgreich.':'2FA sign-in successful.',
    'Erfolgreiche Anmeldung.':'Sign-in successful.',
    'Passwort korrekt, 2FA-Code erforderlich.':'Password accepted; 2FA code required.',
    'Ung\u00FCltiger 2FA-Code.':'Invalid 2FA code.',
    'Benutzer abgemeldet.':'User signed out.',
    'Passwort ge\u00E4ndert.':'Password changed.',
    '2FA erfolgreich aktiviert.':'2FA enabled successfully.',
    '2FA wurde deaktiviert.':'2FA was disabled.',
    'Anmeldung mit unbest\u00E4tigter E-Mail-Adresse.':'Sign-in attempted with an unverified email address.',
    'Konfigurations-Backup heruntergeladen.':'Configuration backup downloaded.',
    'Switch-Neustart ausgel\u00F6st.':'Switch restart triggered.',
    'Konfigurations-Backup wiederhergestellt und Neustart ausgel\u00F6st.':'Configuration backup restored and restart triggered.',
    'OpenID-Connect-Discovery erfolgreich getestet.':'OpenID Connect discovery tested successfully.',
    'OpenID-Connect-Einstellungen wurden ge\u00E4ndert.':'OpenID Connect settings changed.',
    'Alert-Einstellungen wurden ge\u00E4ndert.':'Alert settings changed.',
    'Test-Alert wurde zugestellt.':'Test alert delivered.',
    'Test-Alert konnte \u00FCber keinen Kanal zugestellt werden.':'Test alert could not be delivered through any channel.',
    'Zum Aktivieren der Alerts muss mindestens ein Benachrichtigungskanal aktiviert sein.':'At least one notification channel must be enabled before alerts can be activated.',
    'F\u00FCr E-Mail-Alerts muss mindestens ein Empf\u00E4nger angegeben werden.':'At least one recipient is required for email alerts.',
    'F\u00FCr Webhook-Alerts muss eine Webhook-URL angegeben werden.':'A webhook URL is required for webhook alerts.',
    'Hintergrundpr\u00FCfung: Switch ist wieder online.':'Background check: switch is online again.'
  };
  // Dynamic log messages retain device names and transport details while the
  // surrounding operational text follows the selected interface language.
  const logPatterns = [
    [/^Switch (.+) wurde gespeichert\.$/,'Switch $1 was saved.'],
    [/^Switch (.+) wurde gel\u00F6scht\.$/,'Switch $1 was deleted.'],
    [/^Alert-Monitoring wurde aktiviert\.$/,'Alert monitoring was enabled.'],
    [/^Alert-Monitoring wurde deaktiviert\.$/,'Alert monitoring was disabled.'],
    [/^Fehlgeschlagene Anmeldung f\u00FCr Benutzer (.+)$/,'Failed sign-in for user $1'],
    [/^Benutzer (.+) wurde aktualisiert\.$/,'User $1 was updated.'],
    [/^Benutzer (.+) wurde angelegt\.$/,'User $1 was created.'],
    [/^Benutzer (.+) wurde gel\u00F6scht\.$/,'User $1 was deleted.'],
    [/^2FA f\u00FCr Benutzer (.+) wurde durch einen Administrator zur\u00FCckgesetzt\.$/,'2FA for user $1 was reset by an administrator.'],
    [/^Port (\d+) aktiviert\.$/,'Port $1 enabled.'],
    [/^Port (\d+) deaktiviert\.$/,'Port $1 disabled.'],
    [/^VLAN (\d+) angelegt\.$/,'VLAN $1 created.'],
    [/^VLAN (\d+) bearbeitet\.$/,'VLAN $1 updated.'],
    [/^VLAN-Tabelleneintrag (\d+) gel\u00F6scht\.$/,'VLAN table entry $1 deleted.'],
    [/^Rechte f\u00FCr Benutzer-ID (\d+) aktualisiert\.$/,'Permissions for user ID $1 updated.'],
    [/^Rechtegruppe (.+) gespeichert\.$/,'Permission group $1 saved.'],
    [/^Rechtegruppe-ID (\d+) gel\u00F6scht\.$/,'Permission group ID $1 deleted.'],
    [/^Ausfall-Alert f\u00FCr (.+) versendet\.$/,'Outage alert sent for $1.'],
    [/^Recovery-Alert f\u00FCr (.+) versendet\.$/,'Recovery alert sent for $1.'],
    [/^Ausfall-Alert f\u00FCr (.+) konnte nicht versendet werden:\s*(.*)$/,'Outage alert for $1 could not be delivered: $2'],
    [/^Recovery-Alert f\u00FCr (.+) konnte nicht versendet werden:\s*(.*)$/,'Recovery alert for $1 could not be delivered: $2'],
    [/^(\d+) ausstehende Alert-Zust\u00E4nde wurden erneut aktiviert\.$/,'$1 pending alert states were re-armed.'],
    [/^Hintergrundpr\u00FCfung: Switch ist nicht mehr erreichbar\.\s*(.*)$/,'Background check: switch is no longer reachable. $1'],
    [/^Fehler bei (.+):\s*(.*)$/,'Error for $1: $2']
  ];
  const translate = value => {
    if (language !== 'en') return value;
    if (Object.prototype.hasOwnProperty.call(english, value)) return english[value];
    for (const [pattern, replacement] of patterns) if (pattern.test(value)) return value.replace(pattern, replacement);
    return value;
  };
  const translateLog = (event, message) => {
    const value = String(message || '');
    if (language !== 'en' || value === '') return value;
    if (Object.prototype.hasOwnProperty.call(logExact, value)) return logExact[value];
    for (const [pattern, replacement] of logPatterns) if (pattern.test(value)) return value.replace(pattern, replacement).trim();
    return translate(value);
  };
  const translateText = node => {
    const value = node.nodeValue || '', trimmed = value.trim();
    if (!trimmed) return;
    const result = translate(trimmed);
    if (result !== trimmed) node.nodeValue = value.replace(trimmed, result);
  };
  const apply = root => {
    if (language !== 'en' || !root) return;
    if (root.nodeType === Node.TEXT_NODE) { translateText(root); return; }
    if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_NODE) return;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let node; while ((node = walker.nextNode())) translateText(node);
    const elements = root.querySelectorAll ? root.querySelectorAll('[placeholder],[title],[aria-label]') : [];
    elements.forEach(el => ['placeholder','title','aria-label'].forEach(attr => { if (el.hasAttribute(attr)) el.setAttribute(attr, translate(el.getAttribute(attr))); }));
  };
  const post = async (action, selectedLanguage) => {
    const form = new FormData(); form.append('action', action); form.append('language', selectedLanguage);
    const response = await fetch('api/language.php', {method:'POST', body:form, headers:{'X-CSRF-Token':document.body.dataset.csrf || ''}});
    const data = await response.json(); if (!response.ok || !data.success) throw new Error(data.error || 'Language could not be saved.');
    return data;
  };
  const bind = () => {
    document.getElementById('languageSettingsBtn')?.addEventListener('click', () => document.getElementById('languageModal')?.classList.add('active'));
    document.querySelectorAll('[data-set-language]').forEach(button => button.addEventListener('click', async () => {
      if (button.dataset.setLanguage === language) return;
      button.disabled = true;
      try { await post('set_user', button.dataset.setLanguage); window.location.reload(); }
      catch (error) { alert(error.message); button.disabled = false; }
    }));
    document.querySelectorAll('[data-set-default-language]').forEach(button => button.addEventListener('click', async () => {
      const selected = button.dataset.setDefaultLanguage, buttons = [...document.querySelectorAll('[data-set-default-language]')], status = document.getElementById('languageStatus');
      if (button.classList.contains('active')) return;
      buttons.forEach(item => { item.disabled = true; });
      if (status) status.textContent = language === 'en' ? 'Saving\u2026' : 'Speichere\u2026';
      try {
        await post('set_default', selected);
        buttons.forEach(item => item.classList.toggle('active', item.dataset.setDefaultLanguage === selected));
        if (status) status.textContent = language === 'en' ? 'Default saved.' : 'Standard gespeichert.';
      } catch (error) {
        if (status) status.textContent = error.message; else alert(error.message);
      } finally {
        buttons.forEach(item => { item.disabled = false; });
      }
    }));
  };
  window.I18n = {language, locale:() => language === 'en' ? 'en-US' : 'de-DE', t:translate, log:translateLog, apply};
  document.documentElement.lang = language;
  document.addEventListener('DOMContentLoaded', () => {
    apply(document.body); bind();
    new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(apply))).observe(document.body, {childList:true, subtree:true});
  });
})();
