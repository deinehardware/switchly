<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/AppInfo.php';

/**
 * Maintains per-switch alert state and delivers e-mail or webhook messages.
 * Repeated failures are coalesced using the configured threshold and cooldown.
 */
final class AlertService
{
    private static string $lastWebhookError = '';

    /** @return array<string, mixed> Persisted singleton alert configuration. */
    public static function settings(): array
    {
        $row = Database::getConnection()->query('SELECT * FROM alert_settings WHERE id = 1')->fetch();
        return $row ?: [];
    }

    /**
     * Validates and replaces alert settings from the administration form.
     *
     * @param array<string, mixed> $values
     * @throws RuntimeException For malformed recipients, SMTP values or URLs.
     */
    public static function save(array $values): void
    {
        $recipients = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\\s]+/', (string)($values['email_recipients'] ?? '')) ?: []))));
        foreach ($recipients as $email) if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ungültige Alert-E-Mail-Adresse: ' . $email);
        $webhookUrl = trim((string)($values['webhook_url'] ?? ''));
        if ($webhookUrl !== '') {
            if (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($webhookUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) throw new RuntimeException('Webhook muss eine gültige HTTP- oder HTTPS-URL sein.');
            if ((string)parse_url($webhookUrl, PHP_URL_USER) !== '' || (string)parse_url($webhookUrl, PHP_URL_PASS) !== '') throw new RuntimeException('Zugangsdaten dürfen nicht Bestandteil der Webhook-URL sein.');
        }
        $threshold = max(1, min(10, (int)($values['failure_threshold'] ?? 2)));
        $cooldown = max(1, min(1440, (int)($values['cooldown_minutes'] ?? 30)));
        $smtpHost = trim((string)($values['smtp_host'] ?? ''));
        $smtpPort = (int)($values['smtp_port'] ?? 587);
        $smtpSecure = strtolower(trim((string)($values['smtp_secure'] ?? 'tls')));
        $smtpUsername = trim((string)($values['smtp_username'] ?? ''));
        $smtpFrom = strtolower(trim((string)($values['smtp_from'] ?? '')));
        if ($smtpHost !== '' && (!preg_match('/^[a-zA-Z0-9.-]+$/', $smtpHost) || strlen($smtpHost) > 253)) throw new RuntimeException('SMTP-Host ist ungültig.');
        if ($smtpPort < 1 || $smtpPort > 65535) throw new RuntimeException('SMTP-Port muss zwischen 1 und 65535 liegen.');
        if (!in_array($smtpSecure, ['', 'tls', 'ssl'], true)) throw new RuntimeException('SMTP-Verschlüsselung muss TLS, SSL oder keine sein.');
        if (preg_match('/[\r\n]/', $smtpUsername)) throw new RuntimeException('SMTP-Benutzername enthält ungültige Zeichen.');
        if ($smtpFrom !== '' && !filter_var($smtpFrom, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP-Absenderadresse ist ungültig.');
        if ($smtpHost !== '' && $smtpFrom === '') throw new RuntimeException('Für SMTP muss eine Absenderadresse angegeben werden.');
        $stmt = Database::getConnection()->prepare("UPDATE alert_settings SET enabled=:enabled,email_enabled=:email_enabled,email_recipients=:recipients,webhook_enabled=:webhook_enabled,webhook_url=:url,webhook_secret=:secret,recovery_enabled=:recovery,failure_threshold=:threshold,cooldown_minutes=:cooldown,smtp_host=:smtp_host,smtp_port=:smtp_port,smtp_secure=:smtp_secure,smtp_username=:smtp_username,smtp_password=:smtp_password,smtp_from=:smtp_from,updated_at=CURRENT_TIMESTAMP WHERE id=1");
        $stmt->execute([
            'enabled' => !empty($values['enabled']) ? 1 : 0,
            'email_enabled' => !empty($values['email_enabled']) ? 1 : 0,
            'recipients' => implode(', ', $recipients),
            'webhook_enabled' => !empty($values['webhook_enabled']) ? 1 : 0,
            'url' => $webhookUrl,
            'secret' => trim((string)($values['webhook_secret'] ?? '')),
            'recovery' => !empty($values['recovery_enabled']) ? 1 : 0,
            'threshold' => $threshold,
            'cooldown' => $cooldown,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_secure' => $smtpSecure,
            'smtp_username' => $smtpUsername,
            'smtp_password' => (string)($values['smtp_password'] ?? ''),
            'smtp_from' => $smtpFrom,
        ]);
    }

    /** Records one probe result and emits a transition alert when required. */
    public static function recordStatus(array $switch, bool $online, string $detail = ''): void
    {
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
            $cooldownElapsed = !$sent || ($now - (int)$state['last_alert_at']) >= ((int)$settings['cooldown_minutes'] * 60);
            if (!$online && $failures >= (int)$settings['failure_threshold'] && $cooldownElapsed) {
                $delivered = self::deliver($switch, false, $detail, $settings);
                if ($delivered) {
                    $sent = 1;
                    $state['last_alert_at'] = $now;
                    Logger::switchEvent('ERROR', 'SWITCH_DOWN_ALERT', 'Ausfall-Alert für ' . $switch['name'] . ' versendet.', (string)$switch['id']);
                }
            } elseif ($online && $sent && !empty($settings['recovery_enabled'])) {
                if (self::deliver($switch, true, '', $settings)) Logger::switchEvent('INFO', 'SWITCH_RECOVERY_ALERT', 'Recovery-Alert für ' . $switch['name'] . ' versendet.', (string)$switch['id']);
                $sent = 0;
                $state['last_alert_at'] = $now;
            } elseif ($online) {
                $sent = 0;
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
            'error' => self::$lastWebhookError,
        ];
    }

    /** Versendet einen Alarm über alle aktivierten Kanäle und meldet, ob mindestens einer erfolgreich war. */
    private static function deliver(array $switch, bool $online, string $detail, array $settings): bool
    {
        $delivered = false;
        if (!empty($settings['email_enabled'])) {
            $recipients = array_filter(array_map('trim', preg_split('/[,;\\s]+/', (string)$settings['email_recipients']) ?: []));
            foreach ($recipients as $recipient) $delivered = EmailService::sendSwitchAlert($recipient, $switch, $online, $detail) || $delivered;
        }
        if (!empty($settings['webhook_enabled']) && trim((string)$settings['webhook_url']) !== '') {
            $delivered = self::sendWebhook((string)$settings['webhook_url'], (string)$settings['webhook_secret'], $switch, $online, $detail) || $delivered;
        }
        return $delivered;
    }

    /** Erzeugt und versendet die passende Discord- oder generische Webhook-Nachricht. */
    private static function sendWebhook(string $url, string $secret, array $switch, bool $online, string $detail): bool
    {
        self::$lastWebhookError = '';
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
        if (defined('CURLOPT_PROTOCOLS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($response !== false && $status >= 200 && $status < 300) return true;

        $responseDetail = is_string($response) ? trim(substr($response, 0, 500)) : '';
        self::$lastWebhookError = $response === false
            ? 'Webhook-Verbindung fehlgeschlagen: ' . ($curlError !== '' ? $curlError : 'unbekannter cURL-Fehler')
            : 'Webhook antwortete mit HTTP ' . $status . ($responseDetail !== '' ? ': ' . $responseDetail : '.');
        Logger::warning('WEBHOOK_DELIVERY_FAILED', 'Webhook-Host ' . ($host !== '' ? $host : 'unbekannt') . ': ' . self::$lastWebhookError);
        return false;
    }
}
