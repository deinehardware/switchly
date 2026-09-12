<?php
declare(strict_types=1);

/**
 * Main authenticated web interface.
 *
 * Permissions are rendered as data attributes for the JavaScript controllers.
 * State-changing requests are still validated by the API layer.
 */
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Permission.php';
require_once __DIR__ . '/../src/AppInfo.php';
Auth::requireLogin();
// Ermittelt Rolle und effektive Rechte frisch aus der Datenbank für die sichtbare Navigation.
$db = Database::getConnection();
$username = $_SESSION['username'] ?? 'user';
$stmt = $db->prepare('SELECT role FROM users WHERE username = :u');
$stmt->execute(['u' => $username]);
$role = $stmt->fetchColumn() ?: ($_SESSION['role'] ?? 'user');
$_SESSION['role'] = $role;
$isAdmin = $role === 'admin';
$currentUser = Permission::currentUser();
$permissions = $currentUser ? Permission::codesFor($currentUser) : [];
$can = static fn(string $code): bool => in_array($code, $permissions, true);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0d1020">
<meta name="application-name" content="<?=AppInfo::NAME?>">
<meta name="switchly-version" content="<?=AppInfo::DISPLAY_VERSION?>">
<title><?=AppInfo::NAME?> · <?=AppInfo::DISPLAY_VERSION?></title>
<link rel="icon" href="assets/favicon.ico?v=1.4.7" sizes="any">
<link rel="icon" type="image/svg+xml" href="assets/switchly.svg?v=1.4.7">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png?v=1.4.7">
<link rel="manifest" href="assets/site.webmanifest?v=1.4.7">
<link rel="stylesheet" href="assets/style.css?v=1.4.7">
</head>
<body data-app-version="<?=AppInfo::DISPLAY_VERSION?>" data-username="<?=htmlspecialchars($username, ENT_QUOTES)?>" data-role="<?=htmlspecialchars($role, ENT_QUOTES)?>" data-permissions="<?=htmlspecialchars(json_encode($permissions), ENT_QUOTES)?>" data-csrf="<?=htmlspecialchars(Auth::csrfToken(), ENT_QUOTES)?>">
<div class="app-layout">
<!-- Dauerhafte Navigation mit ausschließlich freigegebenen Verwaltungsbereichen. -->
<aside class="sidebar">
  <div class="sidebar-header">
    <div class="logo-box"><img class="brand-mark" src="assets/switchly-mark.svg?v=1.4.7" alt="Switchly" width="44" height="44"></div>
    <div class="sidebar-title"><h2><?=AppInfo::NAME?></h2><span>Network Control Center</span><small class="brand-version"><?=AppInfo::DISPLAY_VERSION?></small></div>
  </div>

  <nav class="main-nav">
    <div class="nav-label">NAVIGATION</div>
    <a href="#dashboard" class="nav-item active" data-view="dashboard"><span class="nav-icon">▦</span><span>Dashboard</span></a>
    <a href="#switches" class="nav-item" data-view="switches"><span class="nav-icon">♧</span><span>Switches</span></a>
    <?php if ($can('monitoring.view')): ?><a href="#monitoring" class="nav-item" data-view="monitoring"><span class="nav-icon">⌁</span><span>Monitoring</span></a><?php endif; ?>
    <?php if ($can('logs.view')): ?>
      <a href="#logs" class="nav-item" data-view="logs"><span class="nav-icon">▱</span><span>System Logs</span><span class="admin-tag">ADMIN</span></a>
    <?php endif; ?>
    <?php if ($can('users.manage')): ?>
      <a href="#users" class="nav-item" data-view="users"><span class="nav-icon">♙</span><span>Benutzerverwaltung</span><span class="admin-tag">ADMIN</span></a>
    <?php endif; ?>
    <?php if ($can('permissions.manage')): ?>
      <a href="#permissions" class="nav-item" data-view="permissions"><span class="nav-icon">⌘</span><span>Rechteverwaltung</span><span class="admin-tag">ADMIN</span></a>
    <?php endif; ?>
    <?php if ($can('sso.manage')): ?>
      <a href="#openid" class="nav-item" data-view="openid"><span class="nav-icon">◎</span><span>OpenID Connect</span><span class="admin-tag">ADMIN</span></a>
    <?php endif; ?>
    <?php if ($can('alerts.manage')): ?>
      <a href="#alerts" class="nav-item" data-view="alerts"><span class="nav-icon">△</span><span>Alerts</span><span class="admin-tag">ADMIN</span></a>
    <?php endif; ?>
  </nav>

  <div class="sidebar-section-title"><span>SWITCHES</span><?php if ($can('switches.manage')): ?><button id="addSwitchBtn" class="sidebar-add" type="button">＋ Hinzufügen</button><?php endif; ?></div>
  <nav id="switchList" class="switch-list"></nav>

  <div class="sidebar-footer">
    <div class="sidebar-divider"></div>
    <div class="logged-in">Angemeldet als: <strong><?= htmlspecialchars($username) ?></strong></div>
    <div class="role-chip"><?= $isAdmin ? 'ADMINISTRATOR' : 'BENUTZER' ?></div>
    <a href="profile.php" class="profile-btn"><span>⚙</span> Profil &amp; 2FA</a>
    <a href="logout.php" class="btn-logout"><span>↪</span> Abmelden</a>
  </div>
</aside>

<!-- Hauptbereich: JavaScript schaltet jeweils genau eine freigegebene Ansicht sichtbar. -->
<main class="main-content">
  <!-- Dashboard mit Switch-Karten, Kurzprotokoll und der dynamischen Gerätedetailansicht. -->
  <section id="view-dashboard" class="view-section active-view">
    <header class="top-bar">
      <div><span class="subhead">◉ INFRASTRUKTUR</span><h1>Switch Übersicht</h1></div>
      <div class="top-actions">
        <button id="overviewRefresh" class="btn">↻ <span>Aktualisieren</span></button>
        <?php if ($can('switches.manage')): ?><button id="overviewAdd" class="btn btn-primary">＋ <span>Switch hinzufügen</span></button><?php endif; ?>
      </div>
    </header>

    <section id="switchOverviewGrid" class="switch-overview-grid"></section>

    <?php if ($can('logs.view')): ?>
    <section class="card dashboard-log-card" id="dashboardLogsCard">
      <div class="logs-head">
        <div class="section-title-row"><h2>System Logs</h2><span class="admin-only-badge">ADMIN ONLY</span></div>
        <div class="logs-toolbar">
          <select id="dashboardLogLevel" class="control-select"><option value="">Alle Level</option><option value="INFO">INFO</option><option value="WARNING">WARNUNG</option><option value="ERROR">ERROR</option></select>
          <select id="dashboardLogRange" class="control-select"><option value="24">▣ Letzte 24 Stunden</option><option value="168">Letzte 7 Tage</option><option value="720">Letzte 30 Tage</option><option value="0">Alle</option></select>
          <div class="log-search-wrap"><input id="dashboardLogSearch" class="log-search" placeholder="Suche..."><span>⌕</span></div>
        </div>
      </div>
      <div class="table-responsive"><table class="logs-table"><thead><tr><th>ZEITPUNKT</th><th>LEVEL</th><th>BENUTZER</th><th>AKTION</th><th>DETAILS</th></tr></thead><tbody id="dashboardLogsBody"><tr><td colspan="5" class="table-empty">Lade Logs…</td></tr></tbody></table></div>
      <div class="logs-footer"><span id="dashboardLogsCount">—</span><button class="page-btn active">1</button></div>
    </section>
    <?php endif; ?>

    <div id="switchDetail" class="detail-view hidden">
      <header class="top-bar detail-header">
        <div><span id="headerSwitchInfo" class="subhead">—</span><h2 id="headerSwitchName">Switch</h2></div>
        <div class="top-actions"><span id="cacheBadge" class="badge badge-muted">INIT</span><button id="refreshButton" class="btn btn-primary">↻ Aktualisieren</button><button id="showJsonBtn" class="btn">Raw JSON</button><?php if ($can('switch.backup')): ?><button id="backupSwitchBtn" class="btn">⇩ Backup</button><?php endif; ?><?php if ($can('switch.restore')): ?><button id="restoreSwitchBtn" class="btn">⇧ Restore</button><?php endif; ?><?php if ($can('switch.reboot')): ?><button id="rebootSwitchBtn" class="btn btn-danger">⏻ Reboot</button><?php endif; ?><?php if ($can('switches.manage')): ?><button id="deleteSwitchBtn" class="btn btn-danger">Löschen</button><?php endif; ?></div>
      </header>
      <section class="kpi-grid">
        <div class="kpi-card"><span class="kpi-label">MODELL</span><div id="modelValue" class="kpi-value">—</div></div>
        <div class="kpi-card"><span class="kpi-label">FIRMWARE</span><div id="swosValue" class="kpi-value">—</div></div>
        <div class="kpi-card"><span class="kpi-label">UPTIME</span><div id="uptimeValue" class="kpi-value">—</div></div>
        <div class="kpi-card"><span class="kpi-label">TEMPERATUR</span><div id="temperatureValue" class="kpi-value">—</div></div>
        <div class="kpi-card"><span class="kpi-label">SPANNUNG</span><div id="voltageValue" class="kpi-value">—</div></div>
      </section>
      <section class="card graph-card"><div class="card-header"><div class="card-title"><h3>PAKET-VERKEHR (RX vs. TX)</h3><span>Empfangene (RX) &amp; Gesendete (TX) Pakete pro Port</span></div></div><div class="chart-container"><canvas id="trafficChart"></canvas></div></section>
      <section class="card"><div class="card-header"><div class="card-title"><h3>PHYSICAL PORTS</h3><span>Live-Status der Switch-Schnittstellen</span></div></div><div id="visualPortsContainer" class="physical-ports-container"></div></section>
      <section class="card"><div class="card-header"><div class="card-title"><h3 id="portSummary">PORT STATUS &amp; STATISTICS</h3><span id="portSub">Ports geladen</span></div><input type="text" id="portSearch" class="input-search" placeholder="Port suchen (z.B. Port 1)..."></div><div class="table-responsive"><table><thead><tr><th>PORT</th><th>ADMIN</th><th>STATUS</th><th>SPEED</th><th>DUPLEX</th><th>AUTO-NEG.</th><th>RX BYTES</th><th>TX BYTES</th><th>RX PACKETS</th><th>TX PACKETS</th><th>ERRORS</th><?php if ($can('switch.port.manage')): ?><th>AKTIONEN</th><?php endif; ?></tr></thead><tbody id="portsBody"><tr><td colspan="12" class="table-empty">Lade Port-Daten...</td></tr></tbody></table></div></section>
      <section class="config-grid">
        <section class="card"><div class="card-header"><div class="card-title"><h3>LAG / LACP</h3><span>Passive, Active oder statische Gruppen pro Port</span></div><button id="lagRefresh" type="button" class="btn btn-small">↻</button></div><div id="lagError" class="notice error hidden"></div><div class="table-responsive"><table class="config-table"><thead><tr><th>PORT</th><th>MODUS</th><th>GRUPPE</th><th>TRUNK</th><th>PARTNER</th><?php if ($can('switch.lag.manage')): ?><th>AKTION</th><?php endif; ?></tr></thead><tbody id="lagBody"><tr><td colspan="6" class="table-empty">Lade LAG-Konfiguration…</td></tr></tbody></table></div></section>
        <section class="card"><div class="card-header"><div class="card-title"><h3>PORT-VLAN</h3><span>PVID, Filtermodus und eingehende Frames</span></div><button id="vlanRefresh" type="button" class="btn btn-small">↻</button></div><div id="vlanError" class="notice error hidden"></div><div class="table-responsive"><table class="config-table"><thead><tr><th>PORT</th><th>PVID</th><th>MODUS</th><th>RECEIVE</th><th>FORCE</th><?php if ($can('switch.vlan.manage')): ?><th>AKTION</th><?php endif; ?></tr></thead><tbody id="vlanPortBody"><tr><td colspan="6" class="table-empty">Lade VLAN-Portkonfiguration…</td></tr></tbody></table></div></section>
      </section>
      <section class="card"><div class="card-header"><div class="card-title"><h3>VLAN-TABELLE</h3><span>VLANs anlegen, bearbeiten, löschen und Ports zuweisen</span></div><?php if ($can('switch.vlan.manage')): ?><button id="newVlanBtn" type="button" class="btn btn-primary btn-small">＋ VLAN anlegen</button><?php endif; ?></div><div class="table-responsive"><table><thead><tr><th>VLAN-ID</th><th>NAME</th><th>PORTS</th><?php if ($can('switch.vlan.manage')): ?><th>AKTIONEN</th><?php endif; ?></tr></thead><tbody id="vlanBody"><tr><td colspan="4" class="table-empty">Lade VLAN-Tabelle…</td></tr></tbody></table></div></section>
      <div id="lastUpdate" class="footer-status">Letztes Update: —</div>
    </div>
  </section>

  <!-- Verwaltung aller für den Benutzer sichtbaren Switches. -->
  <section id="view-switches" class="view-section">
    <header class="top-bar">
      <div><span class="subhead">◉ INFRASTRUKTUR</span><h1>Switches</h1></div>
      <div class="top-actions">
        <button id="switchesRefresh" class="btn">↻ Aktualisieren</button>
        <?php if ($can('switches.manage')): ?><button id="switchesAdd" class="btn btn-primary">＋ Switch hinzufügen</button><?php endif; ?>
      </div>
    </header>
    <section id="switchesManagementGrid" class="switch-management-grid"></section>
  </section>

  <?php if ($can('monitoring.view')): ?>
  <!-- Historische Monitoring-Werte und Verfügbarkeitsdiagramm. -->
  <section id="view-monitoring" class="view-section">
    <header class="top-bar"><div><span class="subhead">BETRIEB</span><h1>Monitoring</h1></div><div class="top-actions"><select id="monitorScope" class="control-select"><option value="stack">Gesamter Stack</option></select><select id="monitorRange" class="control-select"><option value="1">Letzte Stunde</option><option value="24" selected>Letzte 24 Stunden</option><option value="168">Letzte 7 Tage</option><option value="720">Letzte 30 Tage</option></select><button id="monitorRefresh" class="btn btn-primary">↻ Aktualisieren</button></div></header>
    <section class="kpi-grid monitor-kpis"><div class="kpi-card"><span class="kpi-label">SWITCHES ONLINE</span><div id="monitorOnline" class="kpi-value">—</div></div><div class="kpi-card"><span class="kpi-label">PORTS AKTIV</span><div id="monitorPorts" class="kpi-value">—</div></div><div class="kpi-card"><span class="kpi-label">FEHLER</span><div id="monitorErrors" class="kpi-value">—</div></div></section>
    <section class="card graph-card"><div class="card-header"><div class="card-title"><h3>VERFÜGBARKEIT &amp; PORTS</h3><span>Historische Samples aus den automatischen Abfragen</span></div></div><div class="chart-container"><canvas id="monitorChart"></canvas></div></section>
    <section class="card monitor-table-card"><div class="card-header"><div class="card-title"><h3>LETZTE MESSWERTE</h3><span>Switch, Status, Ports, Traffic und Antwortzeit</span></div></div><div class="table-responsive"><table><thead><tr><th>ZEIT</th><th>SWITCH</th><th>STATUS</th><th>PORTS</th><th>RX</th><th>TX</th><th>FEHLER</th><th>ANTWORT</th></tr></thead><tbody id="monitorBody"></tbody></table></div></section>
  </section>
  <?php endif; ?>

  <?php if ($can('logs.view')): ?>
  <!-- Filterbares Ereignisprotokoll von Anwendung und Geräten. -->
  <section id="view-logs" class="view-section">
    <header class="top-bar"><div><span class="subhead">ADMINISTRATION</span><h1>System Logs</h1></div><div class="top-actions"><select id="logScopeFilter" class="control-select"><option value="all">CMS + Stack</option><option value="cms">Nur CMS</option><option value="stack">Gesamter Switch-Stack</option><option value="switch">Einzelner Switch</option></select><select id="logSwitchFilter" class="control-select hidden"><option value="">Switch wählen</option></select><select id="logLevelFilter" class="control-select"><option value="">Alle Level</option><option value="INFO">INFO</option><option value="WARNING">WARNUNG</option><option value="ERROR">ERROR</option></select><button id="logsRefresh" class="btn btn-primary">↻ Aktualisieren</button><?php if ($isAdmin): ?><button id="logsClear" class="btn btn-danger">⌫ Logs löschen</button><?php endif; ?></div></header>
    <section class="card"><div class="card-header"><div class="card-title"><h3>EREIGNISPROTOKOLL</h3><span>CMS, Stack oder einzelner Switch per Dropdown</span></div><div class="log-search-wrap compact"><input id="fullLogSearch" class="log-search" placeholder="Suche..."><span>⌕</span></div></div><div class="table-responsive"><table><thead><tr><th>ZEITPUNKT</th><th>QUELLE</th><th>LEVEL</th><th>BENUTZER</th><th>AKTION</th><th>IP</th><th>DETAILS</th></tr></thead><tbody id="logsBody"></tbody></table></div></section>
  </section>
  <?php endif; ?>

  <?php if ($can('users.manage')): ?>
  <!-- Lokale Benutzerkonten, Rollen, E-Mail-Status und 2FA-Verwaltung. -->
  <section id="view-users" class="view-section">
    <header class="top-bar"><div><span class="subhead">ADMINISTRATION</span><h1>Benutzerverwaltung</h1></div><button id="newUserBtn" class="btn btn-primary">＋ Benutzer hinzufügen</button></header>
    <section class="card users-card"><div class="card-header"><div class="card-title"><h3>BENUTZER</h3><span>Konten, E-Mail-Verifikation, Rollen und 2FA verwalten</span></div></div><div class="table-responsive"><table><thead><tr><th>BENUTZER</th><th>E-MAIL</th><th>ROLLE</th><th>2FA</th><th>ANGELEGT</th><th>AKTIONEN</th></tr></thead><tbody id="usersBody"></tbody></table></div></section>
  </section>
  <?php endif; ?>

  <?php if ($can('permissions.manage')): ?>
  <!-- Direkte, gruppenbasierte und gerätebezogene Berechtigungen. -->
  <section id="view-permissions" class="view-section">
    <header class="top-bar"><div><span class="subhead">ADMINISTRATION</span><h1>Rechteverwaltung</h1></div><button id="newGroupBtn" class="btn btn-primary">＋ Gruppe hinzufügen</button></header>
    <div class="permission-layout"><section class="card"><div class="card-header"><div class="card-title"><h3>BENUTZERRECHTE</h3><span>Direkte Rechte, Gruppen und einzelne Switches</span></div></div><div id="permissionUsers" class="permission-list"></div></section><section class="card"><div class="card-header"><div class="card-title"><h3>GRUPPEN</h3><span>Gemeinsame Rechte und Switch-Zugriffe</span></div></div><div id="permissionGroups" class="permission-list"></div></section></div>
  </section>
  <?php endif; ?>

  <?php if ($can('sso.manage')): ?>
  <!-- OpenID-Connect-Anbieter, Client und Kontozuordnung. -->
  <section id="view-openid" class="view-section">
    <header class="top-bar"><div><span class="subhead">ANMELDUNG</span><h1>OpenID Connect</h1></div></header>
    <form id="oidcForm">
      <div class="oidc-layout">
        <section class="card"><div class="card-header"><div class="card-title"><h3>PROVIDER</h3><span>Keycloak oder ein anderer standardkonformer OIDC-Anbieter</span></div></div>
          <label class="setting-toggle"><input id="oidcEnabled" type="checkbox"><span>OpenID-Connect-Anmeldung aktivieren</span></label>
          <div class="form-grid alert-fields"><div class="form-group"><label>Anzeigename</label><input id="oidcName" placeholder="Keycloak"></div><div class="form-group"><label>Öffentliche CMS-URL</label><input id="oidcBaseUrl" type="url" placeholder="https://monitor.example.de"></div></div>
          <div class="form-group"><label>Discovery-URL</label><input id="oidcDiscoveryUrl" type="url" placeholder="https://keycloak.example/realms/mein-realm/.well-known/openid-configuration"></div>
          <div class="form-group"><label>Callback-URL im Provider</label><input id="oidcCallbackUrl" readonly></div>
        </section>
        <section class="card"><div class="card-header"><div class="card-title"><h3>CLIENT</h3><span>Zugangsdaten und Token-Endpoint-Authentifizierung</span></div></div>
          <div class="form-group"><label>Client-ID</label><input id="oidcClientId" autocomplete="off"></div>
          <div class="form-group"><label>Client-Secret <span id="oidcSecretHint"></span></label><input id="oidcClientSecret" type="password" autocomplete="new-password" placeholder="Leer lassen, um vorhandenes Secret zu behalten"></div>
          <div class="form-grid"><div class="form-group"><label>Client-Authentifizierung</label><select id="oidcClientAuth"><option value="post">client_secret_post</option><option value="basic">client_secret_basic</option><option value="none">none</option></select></div><div class="form-group"><label>Scopes</label><input id="oidcScopes" value="openid email profile"></div></div>
          <label class="setting-toggle"><input id="oidcPublicClient" type="checkbox"><span>Öffentlicher Client ohne Secret</span></label>
        </section>
        <section class="card"><div class="card-header"><div class="card-title"><h3>BENUTZER</h3><span>Kontozuordnung und automatische Anlage</span></div></div>
          <label class="setting-toggle"><input id="oidcAutoCreate" type="checkbox"><span>Unbekannte Benutzer automatisch anlegen</span></label>
          <label class="setting-toggle"><input id="oidcTrustEmail" type="checkbox"><span>E-Mail auch ohne email_verified vertrauen</span></label>
          <div class="form-group alert-fields"><label>Erlaubte E-Mail-Domains</label><input id="oidcDomains" placeholder="example.de, firma.local"></div>
          <div class="form-grid"><div class="form-group"><label>E-Mail-Claim</label><input id="oidcEmailClaim" value="email"></div><div class="form-group"><label>Benutzername-Claim</label><input id="oidcUsernameClaim" value="preferred_username"></div><div class="form-group"><label>Namens-Claim</label><input id="oidcNameClaim" value="name"></div><div class="form-group"><label>Verifiziert-Claim</label><input id="oidcVerifiedClaim" value="email_verified"></div></div>
        </section>
        <section class="card"><div class="card-header"><div class="card-title"><h3>TRANSPORT</h3><span>Sichere Standardwerte beibehalten</span></div></div>
          <label class="setting-toggle"><input id="oidcTlsVerify" type="checkbox" checked><span>TLS-Zertifikat prüfen</span></label>
          <label class="setting-toggle danger-toggle"><input id="oidcAllowHttp" type="checkbox"><span>Unsicheres HTTP erlauben (nur Testnetz)</span></label>
          <div id="oidcTestResult" class="notice hidden"></div>
        </section>
      </div>
      <div class="alert-actions"><button type="button" id="oidcTestBtn" class="btn">Discovery testen</button><button type="submit" class="btn btn-primary">OpenID Connect speichern</button></div>
    </form>
  </section>
  <?php endif; ?>

  <?php if ($can('alerts.manage')): ?>
  <!-- Ausfall-, Wiederherstellungs- und Benachrichtigungseinstellungen. -->
  <section id="view-alerts" class="view-section">
    <header class="top-bar"><div><span class="subhead">MONITORING</span><h1>Switch-Down Alerts</h1></div></header>
    <form id="alertForm">
      <div class="alert-layout">
        <section class="card">
          <div class="card-header"><div class="card-title"><h3>ALLGEMEIN</h3><span>Unabhängige Prüfung im Container, auch ohne geöffnetes Dashboard</span></div></div>
          <label class="setting-toggle"><input id="alertEnabled" type="checkbox"><span>Ausfall-Alerts aktivieren</span></label>
          <div class="form-grid alert-fields"><div class="form-group"><label>Fehlversuche vor Alarm</label><input id="alertThreshold" type="number" min="1" max="10" value="2"></div><div class="form-group"><label>Cooldown (Minuten)</label><input id="alertCooldown" type="number" min="1" max="1440" value="30"></div></div>
          <label class="setting-toggle"><input id="alertRecovery" type="checkbox" checked><span>Recovery senden, wenn Switch wieder online ist</span></label>
        </section>
        <section class="card">
          <div class="card-header"><div class="card-title"><h3>E-MAIL &amp; SMTP</h3><span>SMTP wird sicher in Switchly verwaltet; Passwörter werden nie zurückgegeben</span></div></div>
          <label class="setting-toggle"><input id="alertEmailEnabled" type="checkbox"><span>E-Mail-Benachrichtigung</span></label>
          <div class="form-group alert-fields"><label>Empfänger (Komma oder Leerzeichen getrennt)</label><input id="alertRecipients" type="text" placeholder="noc@example.com, admin@example.com"></div>
          <div class="form-grid">
            <div class="form-group"><label>SMTP-Host</label><input id="smtpHost" type="text" maxlength="253" placeholder="smtp.example.com"></div>
            <div class="form-group"><label>SMTP-Port</label><input id="smtpPort" type="number" min="1" max="65535" value="587"></div>
          </div>
          <div class="form-grid">
            <div class="form-group"><label>Verschlüsselung</label><div class="select-wrap"><select id="smtpSecure"><option value="tls">STARTTLS</option><option value="ssl">TLS/SSL</option><option value="">Keine</option></select></div></div>
            <div class="form-group"><label>Absenderadresse</label><input id="smtpFrom" type="email" placeholder="switchly@example.com"></div>
          </div>
          <div class="form-grid">
            <div class="form-group"><label>SMTP-Benutzername</label><input id="smtpUsername" type="text" autocomplete="username"></div>
            <div class="form-group"><label>SMTP-Passwort <span id="smtpPasswordHint"></span></label><input id="smtpPassword" type="password" autocomplete="new-password" placeholder="Leer lassen = unverändert"></div>
          </div>
        </section>
        <section class="card">
          <div class="card-header"><div class="card-title"><h3>WEBHOOK</h3><span>Discord wird automatisch als formatierte Nachricht gesendet</span></div></div>
          <label class="setting-toggle"><input id="alertWebhookEnabled" type="checkbox"><span>Webhook-Benachrichtigung</span></label>
          <div class="form-group alert-fields"><label>Webhook-URL</label><input id="alertWebhookUrl" type="url" placeholder="https://discord.com/api/webhooks/…"></div>
          <div class="form-group"><label>Secret / Token <span id="alertSecretHint"></span></label><input id="alertWebhookSecret" type="password" placeholder="Nur für generische Webhooks erforderlich"></div>
        </section>
      </div>
      <div class="alert-actions"><button type="button" id="alertTestBtn" class="btn">Test senden</button><button type="submit" class="btn btn-primary">Einstellungen speichern</button></div>
    </form>
    <section class="card alert-state-card"><div class="card-header"><div class="card-title"><h3>ALERT-STATUS</h3><span id="monitorJobStatus">Hintergrund-Worker: Status wird geladen…</span></div></div><div class="table-responsive"><table><thead><tr><th>SWITCH</th><th>STATUS</th><th>FEHLVERSUCHE</th><th>ALARM GESENDET</th><th>LETZTE ÄNDERUNG</th></tr></thead><tbody id="alertStatesBody"></tbody></table></div></section>
  </section>
  <?php endif; ?>
</main>
</div>

<?php if ($can('switches.manage')): ?><!-- Switch hinzufügen -->
<div id="addModal" class="modal" aria-hidden="true"><div class="modal-content"><button type="button" class="modal-x" data-close="addModal">×</button><div class="modal-heading"><h3>Neuen Switch hinzufügen</h3><p>MikroTik SwOS Switch zur Überwachung hinzufügen.</p></div><form id="addSwitchForm"><div class="form-grid"><div class="form-group"><label>ID</label><input type="text" name="id" required placeholder="core-sw"></div><div class="form-group"><label>Name</label><input type="text" name="name" required placeholder="Core Switch"></div></div><div class="form-grid"><div class="form-group"><label>IP-Adresse / Host</label><input type="text" name="host" required placeholder="192.168.178.70"></div><div class="form-group small-field"><label>Port</label><input type="number" name="port" value="80" required></div></div><div class="form-group"><label>Benutzername</label><input type="text" name="username" value="admin" required></div><div class="form-group"><label>Passwort</label><input type="password" name="password" placeholder="Leer lassen, falls keines gesetzt ist"></div><div class="modal-actions"><button type="button" id="closeAddModal" class="btn">Abbrechen</button><button type="submit" class="btn btn-primary">Speichern</button></div></form></div></div>
<?php endif; ?>

<!-- Technische Rohdaten des aktuell ausgewählten Switches. -->
<div id="jsonModal" class="modal" aria-hidden="true"><div class="modal-content modal-lg"><button type="button" class="modal-x" data-close="jsonModal">×</button><div class="modal-heading"><h3>Raw SwOS Response</h3><p>Unverarbeitete Antwort des ausgewählten Switches.</p></div><pre id="jsonViewer"></pre><div class="modal-actions"><button id="closeJsonModal" class="btn">Schließen</button></div></div></div>

<?php if ($can('switch.restore')): ?><div id="restoreModal" class="modal" aria-hidden="true"><div class="modal-content"><button type="button" class="modal-x" data-close="restoreModal">×</button><div class="modal-heading"><h3>Switch-Konfiguration wiederherstellen</h3><p>Eine .swb-Datei hochladen. Die aktuelle Konfiguration kann überschrieben werden.</p></div><form id="restoreForm"><div class="form-group"><label>Backup-Datei (.swb)</label><input type="file" name="backup" accept=".swb" required></div><div class="notice error">Vor dem Restore bitte ein aktuelles Backup herunterladen.</div><div class="modal-actions"><button type="button" class="btn" data-close="restoreModal">Abbrechen</button><button type="submit" class="btn btn-danger">Restore starten</button></div></form></div></div><?php endif; ?>
<?php if ($can('switch.vlan.manage')): ?><div id="vlanModal" class="modal" aria-hidden="true"><div class="modal-content modal-lg"><button type="button" class="modal-x" data-close="vlanModal">×</button><div class="modal-heading"><h3 id="vlanModalTitle">VLAN anlegen</h3><p>VLAN-ID, Name und Mitgliedsports konfigurieren.</p></div><form id="vlanForm"><input type="hidden" id="vlanIndex"><div class="form-grid"><div class="form-group"><label>VLAN-ID</label><input id="vlanId" type="number" min="1" max="4094" required></div><div class="form-group"><label>Name</label><input id="vlanName" maxlength="32" placeholder="z.B. Management"></div></div><div class="form-group"><label>Mitgliedsports</label><div id="vlanMemberChecks" class="check-grid"></div></div><div class="notice error">Achte darauf, den Management-Port nicht versehentlich aus dem VLAN zu entfernen.</div><div class="modal-actions"><button type="button" class="btn" data-close="vlanModal">Abbrechen</button><button class="btn btn-primary">VLAN speichern</button></div></form></div></div><?php endif; ?>

<?php if ($can('users.manage')): ?>
<!-- Benutzer hinzufügen oder bearbeiten; wird nur aus der Benutzerverwaltung geöffnet. -->
<div id="userModal" class="modal" aria-hidden="true"><div class="modal-content user-modal-content"><button type="button" class="modal-x" data-close="userModal">×</button><div class="modal-heading"><h3 id="userModalTitle">Benutzer hinzufügen</h3><p id="userModalSubtitle">Konto, Rolle und Zugangsdaten konfigurieren.</p></div><form id="userForm"><input type="hidden" name="id" id="userId"><div class="form-group"><label>Benutzername</label><input name="username" id="userName" required placeholder="z.B. max.mustermann"></div><div class="form-group"><label>E-Mail-Adresse</label><input type="email" name="email" id="userEmail" placeholder="name@firma.de"></div><div class="form-group"><label>Rolle</label><div class="select-wrap"><select name="role" id="userRole"><option value="user">Benutzer</option><option value="admin">Administrator</option></select></div></div><div class="form-group"><label>Passwort <span id="passwordHint"></span></label><input type="password" name="password" id="userPassword" placeholder="Mindestens 8 Zeichen"></div><div class="modal-actions"><button type="button" id="closeUserModal" class="btn">Abbrechen</button><button type="submit" class="btn btn-primary">Speichern</button></div></form></div></div>
<?php endif; ?>
<?php if ($can('permissions.manage')): ?>
<div id="permissionModal" class="modal" aria-hidden="true"><div class="modal-content modal-lg"><button type="button" class="modal-x" data-close="permissionModal">×</button><div class="modal-heading"><h3 id="permissionModalTitle">Benutzerrechte</h3><p>Direkte Rechte ergänzen Gruppenrechte. Ohne Switch-Auswahl gelten alle Switches.</p></div><form id="permissionForm"><input type="hidden" id="permissionUserId"><div class="permission-columns"><div><h4>Rechte</h4><div id="permissionChecks" class="check-grid"></div></div><div><h4>Gruppen</h4><div id="permissionGroupChecks" class="check-grid"></div><h4>Switch-Zugriff</h4><div id="permissionSwitchChecks" class="check-grid"></div></div></div><div class="modal-actions"><button type="button" class="btn" data-close="permissionModal">Abbrechen</button><button class="btn btn-primary">Rechte speichern</button></div></form></div></div>
<div id="groupModal" class="modal" aria-hidden="true"><div class="modal-content modal-lg"><button type="button" class="modal-x" data-close="groupModal">×</button><div class="modal-heading"><h3 id="groupModalTitle">Gruppe hinzufügen</h3><p>Mitglieder, Gruppenrechte und Switch-Zugriffe zentral verwalten.</p></div><form id="groupForm"><input type="hidden" id="groupId"><div class="form-grid"><div class="form-group"><label>Name</label><input id="groupName" required></div><div class="form-group"><label>Beschreibung</label><input id="groupDescription"></div></div><div class="permission-columns"><div><h4>Benutzer in dieser Gruppe</h4><div id="groupUserChecks" class="check-grid"></div><h4>Rechte</h4><div id="groupPermissionChecks" class="check-grid"></div></div><div><h4>Switch-Zugriff</h4><div id="groupSwitchChecks" class="check-grid"></div></div></div><div class="modal-actions"><button type="button" class="btn" data-close="groupModal">Abbrechen</button><button class="btn btn-primary">Gruppe speichern</button></div></form></div></div>
<?php endif; ?>

<script src="assets/dashboard.js?v=1.4.7"></script>
<script src="assets/app.js?v=1.4.7"></script>
</body>
</html>
