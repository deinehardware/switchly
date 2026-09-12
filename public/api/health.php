<?php

declare(strict_types=1);

/**
 * Public GET endpoint for application, database and worker health.
 * No credentials, session data or managed-device details are returned.
 */

require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/AppInfo.php';
require_once __DIR__ . '/../../src/Logger.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$monitorEnabled = filter_var((string)(getenv('SWITCHLY_MONITOR_ENABLED') ?: '1'), FILTER_VALIDATE_BOOLEAN);
$monitor = null;
// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    $row = Database::getConnection()->query("SELECT * FROM background_job_status WHERE job_name='switch_monitor'")->fetch();
    if ($row) {
        $interval = max(15, (int)(getenv('SWITCHLY_MONITOR_INTERVAL') ?: 60));
        $heartbeat = (int)($row['heartbeat_at'] ?? 0);
        $row['fresh'] = $heartbeat > 0 && $heartbeat >= time() - max(120, $interval * 3);
        $monitor = $row;
    }
} catch (Throwable $error) {
    // Preserve diagnostic detail in server-side logs without exposing paths,
    // SQL fragments or driver messages through this unauthenticated endpoint.
    $detail = substr((string)preg_replace('/[\r\n]+/', ' ', $error->getMessage()), 0, 500);
    error_log('[Switchly] Health check failed: ' . $detail);
    Logger::error('HEALTH_CHECK_FAILED', $detail);
    $monitor = ['fresh' => false, 'message' => 'Workerstatus ist derzeit nicht verfügbar.'];
}

echo json_encode([
    'status' => 'ok',
    'application' => AppInfo::publicInfo(),
    'timestamp' => time(),
    'background_monitor_enabled' => $monitorEnabled,
    'background_monitor' => $monitor,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
