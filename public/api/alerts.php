<?php

declare(strict_types=1);

/**
 * GET/POST alert settings and delivery-test endpoint.
 * Requires alerts.manage. Secret values are never included in responses.
 */

require_once __DIR__ . '/../../src/Api.php';
require_once __DIR__ . '/../../src/AlertService.php';
require_once __DIR__ . '/../../src/Logger.php';

Api::bootstrap();
Api::requirePermission('alerts.manage');

// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $settings = AlertService::settings();
        // The stored secret may be replaced, but is never returned to the browser.
        $settings['webhook_secret_configured'] = trim((string)($settings['webhook_secret'] ?? '')) !== '';
        $settings['smtp_password_configured'] = trim((string)($settings['smtp_password'] ?? '')) !== '';
        unset($settings['webhook_secret']);
        unset($settings['smtp_password']);
        $states = Database::getConnection()->query("SELECT a.*,s.name,s.host FROM alert_states a JOIN switches s ON s.id=a.switch_id ORDER BY s.name")->fetchAll();
        $monitorEnabled = filter_var((string)(getenv('SWITCHLY_MONITOR_ENABLED') ?: '1'), FILTER_VALIDATE_BOOLEAN);
        $monitorInterval = max(15, (int)(getenv('SWITCHLY_MONITOR_INTERVAL') ?: 60));
        $monitorJob = Database::getConnection()->query("SELECT * FROM background_job_status WHERE job_name='switch_monitor'")->fetch() ?: null;
        if ($monitorJob !== null) {
            $heartbeat = (int)($monitorJob['heartbeat_at'] ?? 0);
            $monitorJob['fresh'] = $heartbeat > 0 && $heartbeat >= time() - max(120, $monitorInterval * 3);
        }
        $monitorJob = array_merge(['enabled' => $monitorEnabled, 'interval' => $monitorInterval, 'fresh' => false], $monitorJob ?? []);
        Api::respond(['settings' => $settings, 'states' => $states, 'monitor_job' => $monitorJob]);
    }

    $action = trim((string)($_POST['action'] ?? 'save'));
    if (!in_array($action, ['save', 'test'], true)) Api::respond(['error' => 'Ungültige Alert-Aktion.'], 400);
    $current = AlertService::settings();
    $values = [
        'enabled' => filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'email_enabled' => filter_var($_POST['email_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'email_recipients' => (string)($_POST['email_recipients'] ?? ''),
        'webhook_enabled' => filter_var($_POST['webhook_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'webhook_url' => (string)($_POST['webhook_url'] ?? ''),
        'webhook_secret' => trim((string)($_POST['webhook_secret'] ?? '')) !== '' ? (string)$_POST['webhook_secret'] : (string)($current['webhook_secret'] ?? ''),
        'recovery_enabled' => filter_var($_POST['recovery_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'failure_threshold' => (int)($_POST['failure_threshold'] ?? 2),
        'cooldown_minutes' => (int)($_POST['cooldown_minutes'] ?? 30),
        'smtp_host' => (string)($_POST['smtp_host'] ?? ''),
        'smtp_port' => (int)($_POST['smtp_port'] ?? 587),
        'smtp_secure' => (string)($_POST['smtp_secure'] ?? 'tls'),
        'smtp_username' => (string)($_POST['smtp_username'] ?? ''),
        'smtp_password' => trim((string)($_POST['smtp_password'] ?? '')) !== '' ? (string)$_POST['smtp_password'] : (string)($current['smtp_password'] ?? ''),
        'smtp_from' => (string)($_POST['smtp_from'] ?? ''),
    ];
    AlertService::save($values);
    $saved = AlertService::settings();
    // Aktion „test“: prüft die aktuell übermittelten Einstellungen.
    if ($action === 'test') {
        $result = AlertService::test($saved);
        Logger::write($result['delivered'] ? 'INFO' : 'WARNING', 'ALERT_TEST', $result['delivered'] ? 'Test-Alert wurde zugestellt.' : 'Test-Alert konnte über keinen Kanal zugestellt werden.');
        if (!$result['delivered']) {
            $detail = trim((string)($result['error'] ?? ''));
            Api::respond(['error' => $detail !== '' ? $detail : 'Kein Alert-Kanal konnte den Test zustellen. Bitte SMTP/Webhook prüfen.'], 502);
        }
    } else {
        Logger::write('INFO', 'ALERT_SETTINGS_CHANGED', 'Alert-Einstellungen wurden geändert.');
    }
    Api::respond(['success' => true]);
} catch (Throwable $e) {
    Api::error($e, 400);
}
