<?php

declare(strict_types=1);

/**
 * GET /api/switch.php — cached or live status for one switch.
 * Requires switches.view plus access to the requested switch ID.
 */

require_once __DIR__ . '/../../src/Api.php';
require_once __DIR__ . '/../../src/SwOS.php';
require_once __DIR__ . '/../../src/Logger.php';
require_once __DIR__ . '/../../src/AlertService.php';

Api::bootstrap();
$user = Api::requirePermission('switches.view');

// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') Api::respond(['error' => 'Keine Switch-ID angegeben.', 'online' => false], 400);
    Api::requireSwitchAccess($user, $id);

    $force = ($_GET['force'] ?? '') === '1';
    $cacheOnly = ($_GET['cache_only'] ?? '') === '1';
    $silent = ($_GET['silent'] ?? '') === '1';
    $db = Database::getConnection();

    $stmt = $db->prepare('SELECT * FROM switches WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $switch = $stmt->fetch();
    if (!$switch) Api::respond(['error' => 'Switch nicht gefunden.', 'online' => false], 404);

    $cacheStmt = $db->prepare('SELECT * FROM switch_cache WHERE switch_id = :id');
    $cacheStmt->execute(['id' => $id]);
    $cached = $cacheStmt->fetch();
    $now = time();

    if (!$force && $cached && ($cacheOnly || ($now - (int)$cached['updated_at']) < 60)) {
        Api::respond([
            'id' => $switch['id'],
            'name' => $switch['name'],
            'host' => $switch['host'],
            'online' => (bool)$cached['is_online'],
            'info' => json_decode((string)$cached['info_json'], true) ?: [],
            'ports' => json_decode((string)$cached['ports_json'], true) ?: [],
            'cached' => true,
            'updated_at' => (int)$cached['updated_at'],
            'last_error' => (string)($cached['last_error'] ?? ''),
        ]);
    }
    if ($cacheOnly) {
        Api::respond([
            'id' => $switch['id'],
            'name' => $switch['name'],
            'host' => $switch['host'],
            'online' => false,
            'info' => [],
            'ports' => [],
            'cached' => true,
            'updated_at' => null,
            'last_error' => 'Noch keine Daten vom Hintergrund-Worker vorhanden.',
        ]);
    }

    try {
        $client = new SwOS([
            'host' => $switch['host'],
            'port' => (int)$switch['port'],
            'username' => $switch['username'],
            'password' => $switch['password'],
        ]);
        $startedAt = microtime(true);
        $info = $client->getSystemInfo();
        $ports = $client->getPortStatistics();
        $responseMs = (int)round((microtime(true) - $startedAt) * 1000);

        $upsert = $db->prepare("INSERT INTO switch_cache (switch_id, info_json, ports_json, updated_at, is_online, last_error)
            VALUES (:id, :info, :ports, :updated, 1, '')
            ON CONFLICT(switch_id) DO UPDATE SET
                info_json = excluded.info_json,
                ports_json = excluded.ports_json,
                updated_at = excluded.updated_at,
                is_online = 1,
                last_error = ''");
        $upsert->execute([
            'id' => $id,
            'info' => json_encode($info, JSON_UNESCAPED_UNICODE),
            'ports' => json_encode($ports, JSON_UNESCAPED_UNICODE),
            'updated' => $now,
        ]);
        $totals = ['up' => 0, 'rx' => 0, 'tx' => 0, 'errors' => 0];
        foreach ($ports as $port) {
            if (!empty($port['link'])) $totals['up']++;
            $totals['rx'] += (int)($port['rx_bytes'] ?? 0);
            $totals['tx'] += (int)($port['tx_bytes'] ?? 0);
            $totals['errors'] += (int)($port['rx_errors'] ?? 0) + (int)($port['tx_errors'] ?? 0);
        }
        $db->prepare('INSERT INTO monitoring_samples (switch_id, sampled_at, is_online, ports_up, ports_total, rx_bytes, tx_bytes, errors, response_ms) VALUES (:id, :sampled, 1, :up, :total, :rx, :tx, :errors, :response)')->execute(['id' => $id, 'sampled' => $now, 'up' => $totals['up'], 'total' => count($ports), 'rx' => $totals['rx'], 'tx' => $totals['tx'], 'errors' => $totals['errors'], 'response' => $responseMs]);
        $db->prepare('DELETE FROM monitoring_samples WHERE sampled_at < :cutoff')->execute(['cutoff' => $now - 2592000]);
        AlertService::recordStatus($switch, true);

        Api::respond([
            'id' => $switch['id'],
            'name' => $switch['name'],
            'host' => $switch['host'],
            'online' => true,
            'info' => $info,
            'ports' => $ports,
            'cached' => false,
            'updated_at' => $now,
        ]);
    } catch (Throwable $e) {
        if (!$silent) Logger::switchEvent('ERROR', 'SWITCH_ERROR', 'Fehler bei ' . $switch['name'] . ': ' . $e->getMessage(), $id);

        $upsert = $db->prepare("INSERT INTO switch_cache (switch_id, info_json, ports_json, updated_at, is_online, last_error)
            VALUES (:id, '{}', '[]', :updated, 0, :error)
            ON CONFLICT(switch_id) DO UPDATE SET
                updated_at = excluded.updated_at,
                is_online = 0,
                last_error = excluded.last_error");
        $upsert->execute(['id' => $id, 'updated' => $now, 'error' => $e->getMessage()]);
        $db->prepare('INSERT INTO monitoring_samples (switch_id, sampled_at, is_online) VALUES (:id, :sampled, 0)')->execute(['id' => $id, 'sampled' => $now]);
        AlertService::recordStatus($switch, false, $e->getMessage());

        Api::respond([
            'id' => $switch['id'],
            'name' => $switch['name'],
            'host' => $switch['host'],
            'online' => false,
            'error' => $e->getMessage(),
            'info' => $cached ? (json_decode((string)$cached['info_json'], true) ?: []) : [],
            'ports' => $cached ? (json_decode((string)$cached['ports_json'], true) ?: []) : [],
            'cached' => (bool)$cached,
            'updated_at' => $now,
        ]);
    }
} catch (Throwable $e) {
    Api::error($e, 500);
}
