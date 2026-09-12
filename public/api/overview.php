<?php

declare(strict_types=1);

/**
 * GET /api/overview.php — cached dashboard data.
 * Requires dashboard.view and never performs a direct SwOS request.
 */

require_once __DIR__ . '/../../src/Api.php';

Api::bootstrap();
$user = Api::requirePermission('dashboard.view');

// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    $db = Database::getConnection();
    $ids = Permission::accessibleSwitchIds($user);
    $where = '';
    $params = [];
    if (is_array($ids)) {
        if ($ids === []) Api::respond(['switches' => []]);
        $holders = [];
        foreach ($ids as $i => $id) { $key = 'sid' . $i; $holders[] = ':' . $key; $params[$key] = $id; }
        $where = ' WHERE s.id IN (' . implode(',', $holders) . ')';
    }
    $stmt = $db->prepare("SELECT s.id, s.name, s.host, s.port,
        COALESCE(c.is_online, 0) AS is_online,
        c.info_json, c.ports_json, c.updated_at
        FROM switches s
        LEFT JOIN switch_cache c ON c.switch_id = s.id
        " . $where . " ORDER BY s.name ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $switches = array_map(static function (array $row): array {
        $info = json_decode((string)($row['info_json'] ?? ''), true);
        $ports = json_decode((string)($row['ports_json'] ?? ''), true);
        $info = is_array($info) ? $info : [];
        $ports = is_array($ports) ? $ports : [];
        $active = count(array_filter($ports, static fn(array $port): bool => !empty($port['link']) || strtolower((string)($port['status'] ?? '')) === 'up'));

        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'host' => $row['host'],
            'port' => (int)$row['port'],
            'is_online' => (int)$row['is_online'],
            'updated_at' => $row['updated_at'] !== null ? (int)$row['updated_at'] : null,
            'ports_total' => count($ports),
            'ports_active' => $active,
            'uptime' => $info['uptime'] ?? '—',
            'model' => $info['model'] ?? '—',
        ];
    }, $rows);

    Api::respond(['switches' => $switches]);
} catch (Throwable $e) {
    Api::error($e, 500);
}
