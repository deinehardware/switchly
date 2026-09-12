<?php

declare(strict_types=1);

/**
 * Log query and cleanup endpoint.
 * GET requires logs.view; POST clears logs and is restricted to administrators.
 */

require_once __DIR__ . '/../../src/Api.php';

Api::bootstrap();
$user = Api::requirePermission('logs.view');

// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    $db = Database::getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($user['role'] ?? 'user') !== 'admin') Api::respond(['error' => 'Nur Administratoren dürfen Logs löschen.'], 403);
        if (($_POST['action'] ?? '') !== 'clear') Api::respond(['error' => 'Ungültige Aktion.'], 400);
        $deleted = (int)$db->exec('DELETE FROM system_logs');
        try { $db->exec("DELETE FROM sqlite_sequence WHERE name = 'system_logs'"); } catch (Throwable) {}
        Api::respond(['success' => true, 'deleted' => $deleted]);
    }

    $limit = min(500, max(10, (int)($_GET['limit'] ?? 200)));
    $level = strtoupper(trim((string)($_GET['level'] ?? '')));
    $hours = max(0, (int)($_GET['hours'] ?? 0));
    $scope = (string)($_GET['scope'] ?? 'all');
    $switchId = trim((string)($_GET['switch_id'] ?? ''));
    $where = [];
    $params = [];

    if (in_array($level, ['INFO', 'WARNING', 'ERROR'], true)) {
        $where[] = 'level = :level';
        $params['level'] = $level;
    }
    if ($hours > 0) {
        $where[] = "created_at >= datetime('now', :window)";
        $params['window'] = '-' . $hours . ' hours';
    }
    if ($scope === 'cms') {
        $where[] = "source = 'cms'";
    } elseif ($scope === 'stack') {
        $where[] = "source = 'switch'";
    } elseif ($scope === 'switch') {
        if ($switchId === '') throw new RuntimeException('Keine Switch-ID für den Logfilter angegeben.');
        Api::requireSwitchAccess($user, $switchId);
        $where[] = 'source = \'switch\' AND switch_id = :switch_id';
        $params['switch_id'] = $switchId;
    }
    $accessibleIds = Permission::accessibleSwitchIds($user);
    if (is_array($accessibleIds) && $scope !== 'cms') {
        if ($accessibleIds === []) Api::respond(['logs' => []]);
        $holders = [];
        foreach ($accessibleIds as $i => $id) { $key = 'access' . $i; $holders[] = ':' . $key; $params[$key] = $id; }
        $where[] = "(source = 'cms' OR switch_id IN (" . implode(',', $holders) . '))';
    }

    $sql = 'SELECT id, level, event, message, username, ip_address, switch_id, source, created_at FROM system_logs';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    Api::respond(['logs' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    Api::error($e, 500);
}
