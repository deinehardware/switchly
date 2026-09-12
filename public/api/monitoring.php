<?php

declare(strict_types=1);

/**
 * GET /api/monitoring.php — bounded monitoring history from SQLite.
 * Requires monitoring.view and applies switch-level access restrictions.
 */

require_once __DIR__ . '/../../src/Api.php';

Api::bootstrap();
$user = Api::requirePermission('monitoring.view');

// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    $db = Database::getConnection();
    $switchId = trim((string)($_GET['id'] ?? 'stack'));
    $hours = min(720, max(1, (int)($_GET['hours'] ?? 24)));
    $since = time() - ($hours * 3600);
    $ids = Permission::accessibleSwitchIds($user);
    $params = ['since' => $since];
    $where = ['m.sampled_at >= :since'];

    if ($switchId !== 'stack') {
        Api::requireSwitchAccess($user, $switchId);
        $where[] = 'm.switch_id = :switch_id';
        $params['switch_id'] = $switchId;
    } elseif (is_array($ids)) {
        if ($ids === []) Api::respond(['samples' => [], 'summary' => []]);
        $placeholders = [];
        foreach ($ids as $i => $id) { $key = 'sid' . $i; $placeholders[] = ':' . $key; $params[$key] = $id; }
        $where[] = 'm.switch_id IN (' . implode(',', $placeholders) . ')';
    }

    $sql = 'SELECT m.switch_id, s.name AS switch_name, m.sampled_at, m.is_online, m.ports_up, m.ports_total, m.rx_bytes, m.tx_bytes, m.errors, m.response_ms FROM monitoring_samples m JOIN switches s ON s.id = m.switch_id WHERE ' . implode(' AND ', $where) . ' ORDER BY m.sampled_at ASC LIMIT 5000';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $samples = $stmt->fetchAll();

    $latest = [];
    foreach ($samples as $sample) $latest[$sample['switch_id']] = $sample;
    $summary = [
        'switches' => count($latest),
        'online' => count(array_filter($latest, static fn(array $row): bool => (int)$row['is_online'] === 1)),
        'ports_up' => array_sum(array_map(static fn(array $row): int => (int)$row['ports_up'], $latest)),
        'ports_total' => array_sum(array_map(static fn(array $row): int => (int)$row['ports_total'], $latest)),
        'errors' => array_sum(array_map(static fn(array $row): int => (int)$row['errors'], $latest)),
    ];
    Api::respond(['samples' => $samples, 'summary' => $summary]);
} catch (Throwable $e) {
    Api::error($e, 500);
}
