<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/AppInfo.php';
require_once __DIR__ . '/SecretStore.php';
require_once __DIR__ . '/NetworkPolicy.php';

/**
 * Maintains per-switch alert state and delivers e-mail or webhook messages.
 * Repeated failures are coalesced using the configured threshold and cooldown.
 */
final class AlertService
{
    /** Contains a human-readable reason when the most recent delivery failed. */
    private static string $lastDeliveryError = '';

    /** Contains the webhook-specific error used by the manual test endpoint. */
    private static string $lastWebhookError = '';

    /** @return array<string, mixed> Persisted singleton alert configuration. */
    public static function settings(): array
    {
        $row = Database::getConnection()->query('SELECT * FROM alert_settings WHERE id = 1')->fetch();
        return $row ? SecretStore::decryptAlertSettings($row) : [];
    }

    /**
     * Validates and replaces alert settings from the administration form.
     *
     * @param array<string, mixed> $values
     * @throws RuntimeException For malformed recipients, SMTP values or URLs.
     */
    public static function save(array $values): void
    {
        // Read the previous master state before writing so a disabled alert
        // configuration can be re-armed only on a real off-to-on transition.
        $previousSettings = self::settings();
        $recipients = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\\s]+/', (string)($values['email_recipients'] ?? '')) ?: []))));
        foreach ($recipients as $email) if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ungültige Alert-E-Mail-Adresse: ' . $email);
        $webhookUrl = trim((string)($values['webhook_url'] ?? ''));
        if ($webhookUrl !== '') {
            if (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($webhookUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) throw new RuntimeException('Webhook muss eine gültige HTTP- oder HTTPS-URL sein.');
            if ((string)parse_url($webhookUrl, PHP_URL_USER) !== '' || (string)parse_url($webhookUrl, PHP_URL_PASS) !== '') throw new RuntimeException('Zugangsdaten dürfen nicht Bestandteil der Webhook-URL sein.');
            NetworkPolicy::requireIntegrationUrl($webhookUrl, true, 'Webhook-URL');
        }
        $threshold = max(1, min(10, (int)($values['failure_threshold'] ?? 2)));
        $cooldown = max(1, min(1440, (int)($values['cooldown_minutes'] ?? 30)));
        $smtpHost = trim((string)($values['smtp_host'] ?? ''));
        $smtpPort = (int)($values['smtp_port'] ?? 587);
        $smtpSecure = strtolower(trim((string)($values['smtp_secure'] ?? 'tls')));
        $smtpUsername = trim((string)($values['smtp_username'] ?? ''));
        $smtpFrom = strtolower(trim((string)($values['smtp_from'] ?? '')));
        $gmailEnabled = !empty($values['gmail_oauth_enabled']);
        $gmailClientId = trim((string)($values['gmail_client_id'] ?? ''));
        $gmailClientSecret = (string)($values['gmail_client_secret'] ?? '');
        $gmailSender = strtolower(trim((string)($values['gmail_sender'] ?? '')));
        if ($smtpHost !== '' && (!preg_match('/^[a-zA-Z0-9.-]+$/', $smtpHost) || strlen($smtpHost) > 253)) throw new RuntimeException('SMTP-Host ist ungültig.');
        if ($smtpPort < 1 || $smtpPort > 65535) throw new RuntimeException('SMTP-Port muss zwischen 1 und 65535 liegen.');
        if (!in_array($smtpSecure, ['', 'tls', 'ssl'], true)) throw new RuntimeException('SMTP-Verschlüsselung muss TLS, SSL oder keine sein.');
        if (preg_match('/[\r\n]/', $smtpUsername)) throw new RuntimeException('SMTP-Benutzername enthält ungültige Zeichen.');
        if ($smtpFrom !== '' && !filter_var($smtpFrom, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP-Absenderadresse ist ungültig.');
        if ($smtpHost !== '' && $smtpFrom === '') throw new RuntimeException('Für SMTP muss eine Absenderadresse angegeben werden.');
        if ($gmailSender !== '' && !filter_var($gmailSender, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Die Gmail-Absenderadresse ist ungültig.');
        if ($gmailEnabled && ($gmailClientId === '' || $gmailClientSecret === '' || $gmailSender === '')) throw new RuntimeException('Für Gmail OAuth2 werden Client-ID, Client-Secret und Absenderadresse benötigt.');

        // An enabled master switch without a usable channel looks healthy in
        // the UI but can never deliver an alert. Reject that configuration.
        $alertsEnabled = !empty($values['enabled']);
        $emailEnabled = !empty($values['email_enabled']);
        $webhookEnabled = !empty($values['webhook_enabled']);
        if ($alertsEnabled && !$emailEnabled && !$webhookEnabled) {
            throw new RuntimeException('Zum Aktivieren der Alerts muss mindestens ein Benachrichtigungskanal aktiviert sein.');
        }
        if ($alertsEnabled && $emailEnabled && $recipients === []) {
            throw new RuntimeException('Für E-Mail-Alerts muss mindestens ein Empfänger angegeben werden.');
        }
        if ($alertsEnabled && $webhookEnabled && $webhookUrl === '') {
            throw new RuntimeException('Für Webhook-Alerts muss eine Webhook-URL angegeben werden.');
        }

        $stmt = Database::getConnection()->prepare("UPDATE alert_settings SET enabled=:enabled,email_enabled=:email_enabled,email_recipients=:recipients,webhook_enabled=:webhook_enabled,webhook_url=:url,webhook_secret=:secret,recovery_enabled=:recovery,failure_threshold=:threshold,cooldown_minutes=:cooldown,smtp_host=:smtp_host,smtp_port=:smtp_port,smtp_secure=:smtp_secure,smtp_username=:smtp_username,smtp_password=:smtp_password,smtp_from=:smtp_from,gmail_oauth_enabled=:gmail_enabled,gmail_client_id=:gmail_client_id,gmail_client_secret=:gmail_client_secret,gmail_sender=:gmail_sender,updated_at=CURRENT_TIMESTAMP WHERE id=1");
        $stmt->execute([
            'enabled' => $alertsEnabled ? 1 : 0,
            'email_enabled' => $emailEnabled ? 1 : 0,
            'recipients' => implode(', ', $recipients),
            'webhook_enabled' => $webhookEnabled ? 1 : 0,
            'url' => $webhookUrl,
            'secret' => SecretStore::encrypt(trim((string)($values['webhook_secret'] ?? '')), 'alerts:1:webhook_secret'),
            'recovery' => !empty($values['recovery_enabled']) ? 1 : 0,
            'threshold' => $threshold,
            'cooldown' => $cooldown,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_secure' => $smtpSecure,
            'smtp_username' => $smtpUsername,
            'smtp_password' => SecretStore::encrypt((string)($values['smtp_password'] ?? ''), 'alerts:1:smtp_password'),
            'smtp_from' => $smtpFrom,
            'gmail_enabled' => $gmailEnabled ? 1 : 0,
            'gmail_client_id' => $gmailClientId,
            'gmail_client_secret' => SecretStore::encrypt($gmailClientSecret, 'alerts:1:gmail_client_secret'),
            'gmail_sender' => $gmailSender,
        ]);

        // A newly enabled master configuration starts with fresh counters.
        // This also allows an already-offline switch to trigger a new alert.
        if ($alertsEnabled && empty($previousSettings['enabled'])) {
            Database::getConnection()->exec('DELETE FROM alert_states');
        }
    }

    /** Records one probe result and emits a transition alert when required. */
    public static function recordStatus(array $switch, bool $online, string $detail = ''): void
    {
        // Per-switch exclusion affects only alerts; polling and history remain
        // active. Missing values are treated as enabled for legacy callers.
        if (array_key_exists('alert_monitoring_enabled', $switch) && empty($switch['alert_monitoring_enabled'])) return;
        $db = Database::getConnection();
        $settings = self::settings();
        $stmt = $db->prepare('SELECT * FROM alert_states WHERE switch_id = :id');
        $stmt->execute(['id' => $switch['id']]);
        $state = $stmt->fetch() ?: ['last_status' => null, 'consecutive_failures' => 0, 'down_alert_sent' => 0, 'last_change_at' => 0, 'last_alert_at' => 0];
        $now = time();
        $previous = $state['last_status'] === null ? null : (bool)$state['last_status'];
        $failures = $online ? 0 : (($previous === false) ? (int)$state['consecutive_failures'] + 1 : 1);
        $sent = (int)$state['down_alert_sent'];
        $changedAt = ($previous === null || $previous !== $online) ? $now : (int)$state['last_change_at'];

        if (!empty($settings['enabled'])) {
            // Cooldown is based on the last delivery attempt, not only on a
            // successful down alert. Broken SMTP/webhooks therefore do not
            // generate a log entry on every worker pass.
            $lastAttemptAt = (int)$state['last_alert_at'];
            $cooldownElapsed = $lastAttemptAt === 0 || ($now - $lastAttemptAt) >= ((int)$settings['cooldown_minutes'] * 60);
            if (!$online && $failures >= (int)$settings['failure_threshold'] && $cooldownElapsed) {
                $delivered = self::deliver($switch, false, $detail, $settings);
                $state['last_alert_at'] = $now;
                if ($delivered) {
                    $sent = 1;
                    Logger::switchEvent('ERROR', 'SWITCH_DOWN_ALERT', 'Ausfall-Alert für ' . $switch['name'] . ' versendet.', (string)$switch['id']);
                } else {
                    Logger::switchEvent('ERROR', 'ALERT_DELIVERY_FAILED', 'Ausfall-Alert für ' . $switch['name'] . ' konnte nicht versendet werden: ' . self::$lastDeliveryError, (string)$switch['id']);
                }
            } elseif ($online && $sent && !empty($settings['recovery_enabled']) && ($previous === false || $cooldownElapsed)) {
                // The first online transition sends recovery immediately.
                // Keep the down state armed when that delivery fails so a
                // later online worker pass can retry after the cooldown.
                $delivered = self::deliver($switch, true, '', $settings);
                $state['last_alert_at'] = $now;
                if ($delivered) {
                    $sent = 0;
                    Logger::switchEvent('INFO', 'SWITCH_RECOVERY_ALERT', 'Recovery-Alert für ' . $switch['name'] . ' versendet.', (string)$switch['id']);
                } else {
                    Logger::switchEvent('ERROR', 'ALERT_DELIVERY_FAILED', 'Recovery-Alert für ' . $switch['name'] . ' konnte nicht versendet werden: ' . self::$lastDeliveryError, (string)$switch['id']);
                }
            } elseif ($online) {
                // Without recovery notifications an online probe completes
                // the incident immediately; otherwise a pending recovery is
                // retained until it has actually been delivered.
                if (empty($settings['recovery_enabled'])) $sent = 0;
            }
        } elseif ($online) {
            $sent = 0;
        }

        $upsert = $db->prepare("INSERT INTO alert_states (switch_id,last_status,consecutive_failures,down_alert_sent,last_change_at,last_alert_at) VALUES (:id,:status,:failures,:sent,:changed,:alerted) ON CONFLICT(switch_id) DO UPDATE SET last_status=excluded.last_status,consecutive_failures=excluded.consecutive_failures,down_alert_sent=excluded.down_alert_sent,last_change_at=excluded.last_change_at,last_alert_at=excluded.last_alert_at");
        $upsert->execute(['id' => $switch['id'], 'status' => $online ? 1 : 0, 'failures' => $failures, 'sent' => $sent, 'changed' => $changedAt, 'alerted' => (int)$state['last_alert_at']]);
    }

    /**
     * Sends a synthetic down notification through all enabled channels.
     *
     * @return array{delivered: bool, error: string}
     */
    public static function test(array $settings): array
    {
        self::$lastWebhookError = '';
        $switch = ['id' => 'test', 'name' => AppInfo::NAME . ' Test-Alert', 'host' => '127.0.0.1'];
        return [
            'delivered' => self::deliver($switch, false, 'Dies ist ein manueller Test der Benachrichtigungskanäle.', $settings),
            // Return the combined delivery reason so SMTP-only failures are
            // just as visible to administrators as webhook failures.
            'error' => self::$lastDeliveryError,
        ];
    }

    /**
     * Clears the retry cooldown only for failed, still-unsent incidents.
     * The next worker pass can therefore deliver immediately after an
     * administrator has repaired and successfully tested a channel.
     */
    public static function rearmPendingFailures(): int
    {
        $stmt = Database::getConnection()->prepare('UPDATE alert_states SET last_alert_at=0 WHERE down_alert_sent=0 AND consecutive_failures>0');
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** Versendet einen Alarm über alle aktivierten Kanäle und meldet, ob mindestens einer erfolgreich war. */
    private static function deliver(array $switch, bool $online, string $detail, array $settings): bool
    {
        self::$lastDeliveryError = '';
        $delivered = false;
        $attempted = false;
        $errors = [];
        if (!empty($settings['email_enabled'])) {
            $recipients = array_filter(array_map('trim', preg_split('/[,;\\s]+/', (string)$settings['email_recipients']) ?: []));
            foreach ($recipients as $recipient) {
                $attempted = true;
                $sent = EmailService::sendSwitchAlert($recipient, $switch, $online, $detail);
                $delivered = $sent || $delivered;
                if (!$sent) {
                    $reason = trim(EmailService::lastError());
                    $errors[] = 'E-Mail an ' . $recipient . ' fehlgeschlagen' . ($reason !== '' ? ': ' . $reason : '');
                }
            }
        }
        if (!empty($settings['webhook_enabled']) && trim((string)$settings['webhook_url']) !== '') {
            $attempted = true;
            $sent = self::sendWebhook((string)$settings['webhook_url'], (string)$settings['webhook_secret'], $switch, $online, $detail);
            $delivered = $sent || $delivered;
            if (!$sent) $errors[] = self::$lastWebhookError !== '' ? self::$lastWebhookError : 'Webhook-Versand fehlgeschlagen';
        }
        if (!$attempted) $errors[] = 'kein nutzbarer Benachrichtigungskanal konfiguriert';
        self::$lastDeliveryError = $delivered ? '' : implode('; ', $errors);
        return $delivered;
    }

    /** Erzeugt und versendet die passende Discord- oder generische Webhook-Nachricht. */
    private static function sendWebhook(string $url, string $secret, array $switch, bool $online, string $detail): bool
    {
        self::$lastWebhookError = '';
        try {
            $resolve = NetworkPolicy::curlResolve($url, true, 'Webhook-URL');
        } catch (Throwable $error) {
            self::$lastWebhookError = $error->getMessage();
            Logger::warning('WEBHOOK_DELIVERY_BLOCKED', self::$lastWebhookError);
            return false;
        }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $isDiscord = $host === 'discord.com' || str_ends_with($host, '.discord.com') || $host === 'discordapp.com' || str_ends_with($host, '.discordapp.com');
        $event = $online ? 'switch.recovered' : 'switch.down';
        $timestamp = date(DATE_ATOM);

        if ($isDiscord) {
            $title = $online ? '✅ Switch wieder online' : '🚨 Switch nicht erreichbar';
            $description = trim($detail) !== ''
                ? substr(trim($detail), 0, 4000)
                : ($online ? 'Die Verbindung zum Switch wurde wiederhergestellt.' : 'Der Switch antwortet nicht auf die Statusprüfung.');
            $body = [
                'username' => AppInfo::NAME,
                'allowed_mentions' => ['parse' => []],
                'embeds' => [[
                    'title' => $title,
                    'description' => $description,
                    'color' => $online ? 0x22c55e : 0xef4444,
                    'fields' => [
                        ['name' => 'Switch', 'value' => substr((string)$switch['name'], 0, 1024), 'inline' => true],
                        ['name' => 'Adresse', 'value' => substr((string)$switch['host'], 0, 1024), 'inline' => true],
                        ['name' => 'Status', 'value' => $online ? 'ONLINE' : 'OFFLINE', 'inline' => true],
                    ],
                    'timestamp' => $timestamp,
                    'footer' => ['text' => AppInfo::NAME . ' · ' . AppInfo::DISPLAY_VERSION],
                ]],
            ];
            // Discord only guarantees that a message was stored when wait=true.
            if (!preg_match('/(?:^|[?&])wait=/', $url)) $url .= str_contains($url, '?') ? '&wait=true' : '?wait=true';
        } else {
            $body = [
                'source' => AppInfo::NAME,
                'application' => AppInfo::publicInfo(),
                'event' => $event,
                'timestamp' => $timestamp,
                'switch' => ['id' => $switch['id'], 'name' => $switch['name'], 'host' => $switch['host']],
                'online' => $online,
                'detail' => $detail,
            ];
        }

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            self::$lastWebhookError = 'Webhook-Payload konnte nicht als JSON erzeugt werden.';
            Logger::warning('WEBHOOK_DELIVERY_FAILED', self::$lastWebhookError);
            return false;
        }
        $headers = ['Content-Type: application/json', 'User-Agent: ' . AppInfo::USER_AGENT];
        if (!$isDiscord && $secret !== '') {
            $headers[] = 'X-Switchly-Webhook-Token: ' . $secret;
            // Übergangsheader für vorhandene Webhook-Empfänger aus Versionen
            // vor dem Switchly-Rebranding.
            $headers[] = 'X-SwOS-Webhook-Token: ' . $secret;
        }
        $ch = curl_init($url);
        $options = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_ENCODING => ''];
        if ($resolve !== []) $options[CURLOPT_RESOLVE] = $resolve;
        if (defined('CURLOPT_PROTOCOLS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        if ($response !== false && $status >= 200 && $status < 300) return true;

        $responseDetail = is_string($response) ? trim(substr($response, 0, 500)) : '';
        self::$lastWebhookError = $response === false
            ? 'Webhook-Verbindung fehlgeschlagen: ' . ($curlError !== '' ? $curlError : 'unbekannter cURL-Fehler')
            : 'Webhook antwortete mit HTTP ' . $status . ($responseDetail !== '' ? ': ' . $responseDetail : '.');
        Logger::warning('WEBHOOK_DELIVERY_FAILED', 'Webhook-Host ' . ($host !== '' ? $host : 'unbekannt') . ': ' . self::$lastWebhookError);
        return false;
    }
}
