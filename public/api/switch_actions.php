<?php

declare(strict_types=1);

/**
 * Switch maintenance and configuration endpoint.
 * GET is reserved for backup downloads; mutations require POST, CSRF,
 * switch-level access and the permission associated with the requested action.
 */

require_once __DIR__ . '/../../src/Api.php';
require_once __DIR__ . '/../../src/SwOS.php';
require_once __DIR__ . '/../../src/Logger.php';

Api::bootstrap();

// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    $action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
    $permission = match ($action) {
        'reboot' => 'switch.reboot',
        'backup' => 'switch.backup',
        'restore' => 'switch.restore',
        'port_enable', 'port_disable', 'port_rename', 'port_link' => 'switch.port.manage',
        'lag_save' => 'switch.lag.manage',
        'vlan_port_save', 'vlan_save', 'vlan_delete' => 'switch.vlan.manage',
        default => 'switch.control',
    };
    Api::requireLogin();
    $user = Permission::currentUser();
    if (!$user || (!Permission::has($user, $permission) && !Permission::has($user, 'switch.control'))) {
        Api::respond(['error' => 'Für diese Switch-Aktion fehlt die Berechtigung.'], 403);
    }
    $id = trim((string)($_POST['id'] ?? $_GET['id'] ?? ''));
    if ($id === '') throw new RuntimeException('Keine Switch-ID angegeben.');
    Api::requireSwitchAccess($user, $id);

    $stmt = Database::getConnection()->prepare('SELECT * FROM switches WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $switch = $stmt->fetch();
    if (!$switch) Api::respond(['error' => 'Switch nicht gefunden.'], 404);
    $client = new SwOS([
        'host' => $switch['host'], 'port' => (int)$switch['port'],
        'username' => $switch['username'], 'password' => $switch['password'],
    ]);

    // Aktion „backup“: liefert ein Konfigurations-Backup als Download.
    if ($action === 'backup') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') Api::respond(['error' => 'Backup-Downloads erfordern GET.'], 405);
        $bytes = $client->downloadBackup();
        Logger::switchEvent('INFO', 'SWITCH_BACKUP', 'Konfigurations-Backup heruntergeladen.', $id);
        header_remove('Content-Type');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) . '-' . date('Ymd-His') . '.swb"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') Api::respond(['error' => 'Nur POST ist erlaubt.'], 405);
    // Aktion „reboot“: startet den Switch neu.
    if ($action === 'reboot') {
        $client->reboot();
        Logger::switchEvent('WARNING', 'SWITCH_REBOOT', 'Switch-Neustart ausgelöst.', $id);
    // Aktion „restore“: prüft und überträgt ein Backup.
    } elseif ($action === 'restore') {
        if (!isset($_FILES['backup'])) throw new RuntimeException('Keine Backup-Datei hochgeladen.');
        if ((int)($_FILES['backup']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Backup-Upload fehlgeschlagen (Upload-Code ' . (int)$_FILES['backup']['error'] . ').');
        if (!is_uploaded_file($_FILES['backup']['tmp_name'])) throw new RuntimeException('Backup-Upload konnte nicht validiert werden.');
        if ((int)$_FILES['backup']['size'] < 1 || (int)$_FILES['backup']['size'] > 16 * 1024 * 1024) throw new RuntimeException('Die Backup-Datei muss zwischen 1 Byte und 16 MB groß sein.');
        if (strtolower(pathinfo((string)$_FILES['backup']['name'], PATHINFO_EXTENSION)) !== 'swb') throw new RuntimeException('Nur .swb-Dateien sind erlaubt.');
        $client->restoreBackup((string)$_FILES['backup']['tmp_name'], (string)$_FILES['backup']['name']);
        $client->reboot();
        Logger::switchEvent('WARNING', 'SWITCH_RESTORE', 'Konfigurations-Backup wiederhergestellt und Neustart ausgelöst.', $id);
    // Aktion „port_enable“: aktiviert den gewählten Port.
    } elseif ($action === 'port_enable' || $action === 'port_disable') {
        $port = (int)($_POST['port'] ?? 0);
        $enabled = $action === 'port_enable';
        $client->setPortEnabled($port, $enabled);
        Logger::switchEvent('WARNING', $enabled ? 'PORT_ENABLED' : 'PORT_DISABLED', 'Port ' . $port . ($enabled ? ' aktiviert.' : ' deaktiviert.'), $id);
    // Aktion „port_rename“: ändert den Namen des gewählten Ports.
    } elseif ($action === 'port_rename') {
        $port = (int)($_POST['port'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $client->renamePort($port, $name);
        Logger::switchEvent('INFO', 'PORT_RENAMED', 'Port ' . $port . ' in „' . $name . '“ umbenannt.', $id);
    // Aktion „port_link“: ändert Aushandlung und Geschwindigkeit des Ports.
    } elseif ($action === 'port_link') {
        $port = (int)($_POST['port'] ?? 0);
        $auto = filter_var($_POST['auto_negotiation'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $speedCode = isset($_POST['speed_code']) && $_POST['speed_code'] !== '' ? (int)$_POST['speed_code'] : null;
        $client->setPortLinkSettings($port, $auto, $speedCode);
        Logger::switchEvent('WARNING', 'PORT_LINK_CHANGED', 'Port ' . $port . ': Auto-Negotiation ' . ($auto ? 'aktiviert' : 'deaktiviert') . ($auto ? '.' : ', Speed-Code ' . $speedCode . '.'), $id);
    // Aktion „lag_save“: speichert Modus und Gruppe eines LAG-Ports.
    } elseif ($action === 'lag_save') {
        $port = (int)($_POST['port'] ?? 0);
        $mode = (int)($_POST['mode'] ?? -1);
        $group = (int)($_POST['group'] ?? 0);
        $client->setLagPort($port, $mode, $group);
        Logger::switchEvent('WARNING', 'LAG_CHANGED', 'LAG für Port ' . $port . ' geändert (Modus ' . $mode . ', Gruppe ' . $group . ').', $id);
    // Aktion „vlan_port_save“: speichert die VLAN-Einstellungen eines Ports.
    } elseif ($action === 'vlan_port_save') {
        $port = (int)($_POST['port'] ?? 0);
        $pvid = (int)($_POST['pvid'] ?? 0);
        $mode = (int)($_POST['mode'] ?? -1);
        $receive = (int)($_POST['receive'] ?? 0);
        $force = filter_var($_POST['force_vlan'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $client->setVlanPort($port, $pvid, $mode, $receive, $force);
        Logger::switchEvent('WARNING', 'VLAN_PORT_CHANGED', 'VLAN-Einstellungen für Port ' . $port . ' geändert (PVID ' . $pvid . ').', $id);
    // Aktion „vlan_save“: legt einen VLAN-Eintrag an oder aktualisiert ihn.
    } elseif ($action === 'vlan_save') {
        $index = isset($_POST['index']) && $_POST['index'] !== '' ? (int)$_POST['index'] : null;
        $vlanId = (int)($_POST['vlan_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $members = json_decode((string)($_POST['members'] ?? '[]'), true);
        if (!is_array($members)) throw new RuntimeException('Ungültige Portauswahl.');
        $mask = 0;
        foreach ($members as $member) {
            $member = (int)$member;
            if ($member < 1 || $member > 32) throw new RuntimeException('Ungültiger VLAN-Port.');
            $mask |= 1 << ($member - 1);
        }
        $client->saveVlan($index, $vlanId, $name, $mask);
        Logger::switchEvent('WARNING', $index === null ? 'VLAN_CREATED' : 'VLAN_UPDATED', 'VLAN ' . $vlanId . ($index === null ? ' angelegt.' : ' bearbeitet.'), $id);
    // Aktion „vlan_delete“: löscht einen VLAN-Eintrag.
    } elseif ($action === 'vlan_delete') {
        $index = (int)($_POST['index'] ?? -1);
        $client->deleteVlan($index);
        Logger::switchEvent('WARNING', 'VLAN_DELETED', 'VLAN-Tabelleneintrag ' . $index . ' gelöscht.', $id);
    } else {
        Api::respond(['error' => 'Ungültige Aktion.'], 400);
    }

    Database::getConnection()->prepare('UPDATE switch_cache SET updated_at = 0 WHERE switch_id = :id')->execute(['id' => $id]);
    Api::respond(['success' => true]);
} catch (Throwable $e) {
    if (isset($id) && $id !== '') Logger::switchEvent('ERROR', 'SWITCH_ACTION_FAILED', $e->getMessage(), $id);
    Api::error($e, 400);
}
