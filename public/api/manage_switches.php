<?php

declare(strict_types=1);

/**
 * POST /api/manage_switches.php — managed-switch inventory changes.
 * Requires switches.manage. Stored credentials are never returned.
 */

require_once __DIR__ . '/../../src/Api.php';
require_once __DIR__ . '/../../src/Logger.php';

Api::bootstrap();
$user = Api::requirePermission('switches.manage');

// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') Api::respond(['error' => 'Nur POST ist erlaubt.'], 405);
    $db = Database::getConnection();
    $action = (string)($_POST['action'] ?? '');

    // Aktion „add“: legt einen Switch an oder aktualisiert ihn.
    if ($action === 'add') {
        $id = strtolower((string)preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['id'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $host = trim((string)($_POST['host'] ?? ''));
        $port = (int)($_POST['port'] ?? 80);
        $username = trim((string)($_POST['username'] ?? 'admin'));
        $password = (string)($_POST['password'] ?? '');
        $alertMonitoringEnabled = filter_var($_POST['alert_monitoring_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        if ($id === '' || $name === '' || $host === '') throw new RuntimeException('ID, Name und IP/Host sind Pflichtfelder.');
        if ($port < 1 || $port > 65535) throw new RuntimeException('Der Port muss zwischen 1 und 65535 liegen.');
        if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $host)) throw new RuntimeException('Host/IP enthält ungültige Zeichen.');
        $exists = $db->prepare('SELECT 1 FROM switches WHERE id = :id');
        $exists->execute(['id' => $id]);
        if ($exists->fetchColumn()) Api::requireSwitchAccess($user, $id);

        $stmt = $db->prepare("INSERT INTO switches (id, name, host, port, username, password, alert_monitoring_enabled)
            VALUES (:id, :name, :host, :port, :username, :password, :alert_monitoring_enabled)
            ON CONFLICT(id) DO UPDATE SET
                name = excluded.name,
                host = excluded.host,
                port = excluded.port,
                username = excluded.username,
                password = excluded.password,
                alert_monitoring_enabled = excluded.alert_monitoring_enabled");
        $stmt->execute([
            'id' => $id, 'name' => $name, 'host' => $host, 'port' => $port,
            'username' => $username, 'password' => $password,
            'alert_monitoring_enabled' => $alertMonitoringEnabled,
        ]);
        Logger::switchEvent('INFO', 'SWITCH_SAVED', 'Switch ' . $id . ' wurde gespeichert.', $id);
        Api::respond(['success' => true]);
    }

    if ($action === 'set_alert_monitoring') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') throw new RuntimeException('Keine Switch-ID angegeben.');
        Api::requireSwitchAccess($user, $id);
        $enabled = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        // Resolve the row before updating it. PDO rowCount() may be zero for
        // an idempotent update even though the switch exists.
        $currentStmt = $db->prepare('SELECT alert_monitoring_enabled FROM switches WHERE id = :id');
        $currentStmt->execute(['id' => $id]);
        $current = $currentStmt->fetchColumn();
        if ($current === false) throw new RuntimeException('Switch nicht gefunden.');

        $stmt = $db->prepare('UPDATE switches SET alert_monitoring_enabled = :enabled WHERE id = :id');
        $stmt->execute(['enabled' => $enabled, 'id' => $id]);

        // Any real toggle resets counters and delivery flags. Re-enabling an
        // already-offline switch therefore starts a clean threshold cycle.
        if ((int)$current !== $enabled) {
            $db->prepare('DELETE FROM alert_states WHERE switch_id = :id')->execute(['id' => $id]);
        }
        Logger::switchEvent('INFO', 'SWITCH_ALERT_MONITORING_CHANGED', 'Alert-Monitoring wurde ' . ($enabled ? 'aktiviert.' : 'deaktiviert.'), $id);
        Api::respond(['success' => true, 'enabled' => $enabled]);
    }

    // Aktion „delete“: löscht den ausgewählten Datensatz.
    if ($action === 'delete') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') throw new RuntimeException('Keine Switch-ID angegeben.');
        Api::requireSwitchAccess($user, $id);

        $stmt = $db->prepare('DELETE FROM switches WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) throw new RuntimeException('Switch nicht gefunden.');

        $db->prepare('DELETE FROM switch_cache WHERE switch_id = :id')->execute(['id' => $id]);
        Logger::switchEvent('WARNING', 'SWITCH_DELETED', 'Switch ' . $id . ' wurde gelöscht.', $id);
        Api::respond(['success' => true]);
    }

    Api::respond(['error' => 'Ungültige Aktion.'], 400);
} catch (Throwable $e) {
    Api::error($e, 400);
}
