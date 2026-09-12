<?php

declare(strict_types=1);

/**
 * Executes exactly one background polling pass.
 * Scheduling belongs to the Docker loop or systemd timer; a non-blocking file
 * lock prevents concurrent runs from writing duplicate samples and alerts.
 */

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/SwOS.php';
require_once __DIR__ . '/../src/AlertService.php';
require_once __DIR__ . '/../src/Logger.php';

// Verhindert parallele Worker-Läufe und damit doppelte Messwerte oder Alarme.
$lock = fopen(sys_get_temp_dir() . '/switchly-monitor.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Switchly background poll skipped: another run is active.\n");
    exit(0);
}

// Zähler und Zeitwerte werden am Ende unabhängig vom Ergebnis protokolliert.
$startedAt = time();
$startedMicro = microtime(true);
$processed = 0;
$offline = 0;
$fatalError = '';
$db = null;

try {
    // Markiert den Hintergrundjob als laufend und lädt alle konfigurierten Geräte.
    $db = Database::getConnection();
    $db->prepare("INSERT INTO background_job_status (job_name,started_at,heartbeat_at,success,message) VALUES ('switch_monitor',:started,:heartbeat,0,'Läuft') ON CONFLICT(job_name) DO UPDATE SET started_at=excluded.started_at,heartbeat_at=excluded.heartbeat_at,success=0,message='Läuft'")->execute([
        'started' => $startedAt,
        'heartbeat' => $startedAt,
    ]);
    $switches = $db->query('SELECT * FROM switches ORDER BY name')->fetchAll();

    // Prüft jeden Switch einzeln, damit ein Gerätefehler den restlichen Lauf nicht beendet.
    foreach ($switches as $switch) {
        $previousStmt = $db->prepare('SELECT is_online FROM switch_cache WHERE switch_id=:id');
        $previousStmt->execute(['id' => $switch['id']]);
        $previousRaw = $previousStmt->fetchColumn();
        $previousOnline = $previousRaw === false ? null : (bool)$previousRaw;
        $sampledAt = time();

        try {
            // Liest System- und Portdaten, aktualisiert den Cache und speichert ein Sample.
            $client = new SwOS([
                'host' => $switch['host'],
                'port' => (int)$switch['port'],
                'username' => $switch['username'],
                'password' => $switch['password'],
            ]);
            $requestStarted = microtime(true);
            $info = $client->getSystemInfo();
            $ports = $client->getPortStatistics();
            $responseMs = (int)round((microtime(true) - $requestStarted) * 1000);
            $infoJson = json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $portsJson = json_encode($ports, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($infoJson === false || $portsJson === false) throw new RuntimeException('Switch-Daten konnten nicht als JSON gespeichert werden.');

            $db->prepare("INSERT INTO switch_cache (switch_id,info_json,ports_json,updated_at,is_online,last_error) VALUES (:id,:info,:ports,:updated,1,'') ON CONFLICT(switch_id) DO UPDATE SET info_json=excluded.info_json,ports_json=excluded.ports_json,updated_at=excluded.updated_at,is_online=1,last_error=''")->execute([
                'id' => $switch['id'],
                'info' => $infoJson,
                'ports' => $portsJson,
                'updated' => $sampledAt,
            ]);
            $totals = ['up' => 0, 'rx' => 0, 'tx' => 0, 'errors' => 0];
            foreach ($ports as $port) {
                if (!empty($port['link'])) $totals['up']++;
                $totals['rx'] += (int)($port['rx_bytes'] ?? 0);
                $totals['tx'] += (int)($port['tx_bytes'] ?? 0);
                $totals['errors'] += (int)($port['rx_errors'] ?? 0) + (int)($port['tx_errors'] ?? 0);
            }
            $db->prepare('INSERT INTO monitoring_samples (switch_id,sampled_at,is_online,ports_up,ports_total,rx_bytes,tx_bytes,errors,response_ms) VALUES (:id,:sampled,1,:up,:total,:rx,:tx,:errors,:response)')->execute([
                'id' => $switch['id'],
                'sampled' => $sampledAt,
                'up' => $totals['up'],
                'total' => count($ports),
                'rx' => $totals['rx'],
                'tx' => $totals['tx'],
                'errors' => $totals['errors'],
                'response' => $responseMs,
            ]);
            // Alarmfehler werden separat behandelt und ändern nicht den Online-Status.
            try {
                AlertService::recordStatus($switch, true);
            } catch (Throwable $alertError) {
                Logger::switchEvent('ERROR', 'ALERT_PROCESSING_FAILED', 'Hintergrundprüfung: Alert-Verarbeitung fehlgeschlagen. ' . substr($alertError->getMessage(), 0, 500), (string)$switch['id']);
            }
            if ($previousOnline === false) {
                Logger::switchEvent('INFO', 'SWITCH_STATUS_CHANGED', 'Hintergrundprüfung: Switch ist wieder online.', (string)$switch['id']);
            }
        } catch (Throwable $error) {
            // Bewahrt den letzten bekannten Cache, markiert das Gerät aber als offline.
            $offline++;
            $message = substr($error->getMessage(), 0, 1000);
            $db->prepare("INSERT INTO switch_cache (switch_id,info_json,ports_json,updated_at,is_online,last_error) VALUES (:id,'{}','[]',:updated,0,:error) ON CONFLICT(switch_id) DO UPDATE SET updated_at=excluded.updated_at,is_online=0,last_error=excluded.last_error")->execute([
                'id' => $switch['id'],
                'updated' => $sampledAt,
                'error' => $message,
            ]);
            $db->prepare('INSERT INTO monitoring_samples (switch_id,sampled_at,is_online) VALUES (:id,:sampled,0)')->execute(['id' => $switch['id'], 'sampled' => $sampledAt]);
            try {
                AlertService::recordStatus($switch, false, $message);
            } catch (Throwable $alertError) {
                Logger::switchEvent('ERROR', 'ALERT_PROCESSING_FAILED', 'Hintergrundprüfung: Alert-Verarbeitung fehlgeschlagen. ' . substr($alertError->getMessage(), 0, 500), (string)$switch['id']);
            }
            if ($previousOnline === true) {
                Logger::switchEvent('WARNING', 'SWITCH_STATUS_CHANGED', 'Hintergrundprüfung: Switch ist nicht mehr erreichbar. ' . $message, (string)$switch['id']);
            }
        }
        // Aktualisiert nach jedem Gerät den Heartbeat für die Health-Anzeige.
        $processed++;
        $db->prepare("UPDATE background_job_status SET heartbeat_at=:heartbeat,processed_count=:processed,error_count=:errors WHERE job_name='switch_monitor'")->execute([
            'heartbeat' => time(),
            'processed' => $processed,
            'errors' => $offline,
        ]);
    }

    // Entfernt Messwerte und Logs außerhalb der festgelegten Aufbewahrungszeit.
    $db->prepare('DELETE FROM monitoring_samples WHERE sampled_at < :cutoff')->execute(['cutoff' => time() - 2592000]);
    $retentionDays = max(1, min(3650, (int)(getenv('SWITCHLY_LOG_RETENTION_DAYS') ?: 90)));
    $db->prepare("DELETE FROM system_logs WHERE created_at < datetime('now', :retention)")->execute(['retention' => '-' . $retentionDays . ' days']);
} catch (Throwable $error) {
    // Ein fataler Fehler setzt den Prozessstatus auf fehlgeschlagen.
    $fatalError = substr($error->getMessage(), 0, 1000);
    fwrite(STDERR, 'Switchly background poll failed: ' . $fatalError . "\n");
    Logger::error('BACKGROUND_MONITOR_FAILED', $fatalError);
} finally {
    // Schreibt die abschließende Laufstatistik und gibt die Sperrdatei sicher frei.
    if ($db instanceof PDO) {
        $duration = (int)round((microtime(true) - $startedMicro) * 1000);
        $success = $fatalError === '' ? 1 : 0;
        $summary = $fatalError !== '' ? $fatalError : ($processed . ' Switches geprüft, ' . $offline . ' nicht erreichbar.');
        try {
            $db->prepare("INSERT INTO background_job_status (job_name,started_at,completed_at,heartbeat_at,success,duration_ms,processed_count,error_count,message) VALUES ('switch_monitor',:started,:completed,:heartbeat,:success,:duration,:processed,:errors,:message) ON CONFLICT(job_name) DO UPDATE SET completed_at=excluded.completed_at,heartbeat_at=excluded.heartbeat_at,success=excluded.success,duration_ms=excluded.duration_ms,processed_count=excluded.processed_count,error_count=excluded.error_count,message=excluded.message")->execute([
                'started' => $startedAt,
                'completed' => time(),
                'heartbeat' => time(),
                'success' => $success,
                'duration' => $duration,
                'processed' => $processed,
                'errors' => $offline,
                'message' => $summary,
            ]);
        } catch (Throwable $statusError) {
            fwrite(STDERR, 'Could not persist background job status: ' . $statusError->getMessage() . "\n");
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($fatalError === '' ? 0 : 1);
