<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/AppInfo.php';

/** Composes and delivers transactional verification and switch-alert e-mails. */
final class EmailService
{
    /**
     * Replaces the user's verification token and sends a 24-hour link.
     *
     * @param array<string, mixed> $user Database user row.
     */
    public static function sendVerification(array $user): bool
    {
        $email = trim((string)($user['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $stmt = Database::getConnection()->prepare("UPDATE users SET email_verification_token = :token, email_verification_expires_at = datetime('now', '+24 hours'), email_verified_at = NULL WHERE id = :id");
        $stmt->execute(['token' => $hash, 'id' => $user['id']]);

        $baseUrl = self::baseUrl();
        $link = $baseUrl . '/verify_email.php?token=' . rawurlencode($token);
        $subject = 'E-Mail-Adresse für ' . AppInfo::NAME . ' bestätigen';
        $text = "Hallo {$user['username']},\n\nbitte bestätige deine E-Mail-Adresse für " . AppInfo::NAME . ":\n{$link}\n\nDer Link ist 24 Stunden gültig. Falls du diese E-Mail nicht angefordert hast, kannst du sie ignorieren.\n\n" . AppInfo::DISPLAY_VERSION . "\n";
        $safeName = htmlspecialchars((string)$user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLogo = htmlspecialchars(self::inlineLogoDataUri(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#080b18;font-family:Arial,Helvetica,sans-serif;color:#f3f1ff">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#080b18;padding:36px 16px"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:580px;background:#111426;border:1px solid #2d3150;border-radius:16px;overflow:hidden">'
            . '<tr><td style="padding:24px 30px;background:#151936;border-bottom:1px solid #2d3150"><table role="presentation" cellspacing="0" cellpadding="0"><tr>'
            . '<td style="width:46px;height:46px"><img src="' . $safeLogo . '" width="46" height="46" alt="Switchly" style="display:block;width:46px;height:46px;border:0;border-radius:12px"></td>'
            . '<td style="padding-left:14px"><div style="font-size:20px;font-weight:800;color:#fff">' . AppInfo::NAME . '</div><div style="margin-top:3px;font-size:12px;color:#9da6c5">Network Control Center · ' . AppInfo::DISPLAY_VERSION . '</div></td>'
            . '</tr></table></td></tr><tr><td style="padding:34px 30px">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;color:#53dfc8">E-MAIL BESTÄTIGEN</div>'
            . '<h1 style="margin:10px 0 14px;font-size:25px;line-height:1.3;color:#fff">Hallo ' . $safeName . ',</h1>'
            . '<p style="margin:0 0 26px;font-size:15px;line-height:1.65;color:#bec3dd">Bitte bestätige deine E-Mail-Adresse, damit dein Konto in ' . AppInfo::NAME . ' aktiviert wird.</p>'
            . '<table role="presentation" cellspacing="0" cellpadding="0"><tr><td style="border-radius:9px;background:#6d5dfc"><a href="' . $safeLink . '" style="display:inline-block;padding:14px 24px;color:#fff;text-decoration:none;font-size:14px;font-weight:700">E-Mail-Adresse bestätigen</a></td></tr></table>'
            . '<p style="margin:26px 0 0;font-size:12px;line-height:1.6;color:#7f94aa">Der Link ist 24 Stunden gültig. Falls du diese E-Mail nicht angefordert hast, kannst du sie ignorieren.</p>'
            . '<div style="margin-top:24px;padding-top:20px;border-top:1px solid #2d3150;font-size:11px;line-height:1.5;color:#727b9b">Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:<br><a href="' . $safeLink . '" style="color:#7fe8d7;word-break:break-all">' . $safeLink . '</a></div>'
            . '</td></tr></table><div style="padding:18px;font-size:11px;color:#626a89">' . AppInfo::NAME . ' · ' . AppInfo::DISPLAY_VERSION . ' · Automatisch generierte Nachricht</div>'
            . '</td></tr></table></body></html>';
        $sent = self::send($email, $subject, $text, $html);
        Logger::write($sent ? 'INFO' : 'WARNING', $sent ? 'EMAIL_VERIFICATION_SENT' : 'EMAIL_VERIFICATION_FAILED', 'Verifikationsmail für ' . $user['username'] . ($sent ? ' versendet.' : ' konnte nicht versendet werden.'), null);
        return $sent;
    }

    /** Atomically consumes a valid, unexpired verification token. */
    public static function verify(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
        $stmt = Database::getConnection()->prepare("UPDATE users SET email_verified_at = CURRENT_TIMESTAMP, email_verification_token = NULL, email_verification_expires_at = NULL WHERE email_verification_token = :token AND email_verification_expires_at >= CURRENT_TIMESTAMP");
        $stmt->execute(['token' => hash('sha256', $token)]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Resolves the configured public URL or a validated proxy-aware request
     * URL. Explicit configuration is preferred for production e-mail links.
     */
    public static function baseUrl(): string
    {
        $detectedUrl = self::detectedBaseUrl();
        $configuredUrl = rtrim(trim((string)(getenv('SWITCHLY_BASE_URL') ?: '')), '/');
        // Der frühere Docker-Default zeigte immer auf localhost. Außerhalb eines
        // lokalen Aufrufs wird dieser Platzhalter deshalb automatisch ignoriert.
        return self::isValidBaseUrl($configuredUrl) && (!self::isLocalUrl($configuredUrl) || self::isLocalUrl($detectedUrl))
            ? $configuredUrl
            : $detectedUrl;
    }

    /**
     * Sends a down or recovery notification for one managed switch.
     *
     * @param array<string, mixed> $switch Normalized switch identity.
     */
    public static function sendSwitchAlert(string $to, array $switch, bool $online, string $detail = ''): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        $name = (string)($switch['name'] ?? $switch['id'] ?? 'Switch');
        $host = (string)($switch['host'] ?? '');
        $status = $online ? 'wieder online' : 'nicht erreichbar';
        $subject = ($online ? '[RECOVERY] ' : '[ALARM] ') . $name . ' ist ' . $status;
        $text = AppInfo::NAME . ' ' . AppInfo::DISPLAY_VERSION . "\n\nSwitch: {$name}\nAdresse: {$host}\nStatus: {$status}\nZeit: " . date('c') . "\n" . ($detail !== '' ? "Details: {$detail}\n" : '');
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeHost = htmlspecialchars($host, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDetail = htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLogo = htmlspecialchars(self::inlineLogoDataUri(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $color = $online ? '#18d780' : '#ff5365';
        $label = $online ? 'WIEDER ONLINE' : 'SWITCH DOWN';
        $html = '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;background:#07111f;font-family:Arial,Helvetica,sans-serif;color:#e8eef7"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:32px 14px;background:#07111f"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:580px;background:#0e1c2e;border:1px solid #263b55;border-radius:14px;overflow:hidden">'
            . '<tr><td style="padding:22px 28px;background:#151936;border-bottom:1px solid #2d3150"><table role="presentation" cellspacing="0" cellpadding="0"><tr><td style="width:42px;height:42px"><img src="' . $safeLogo . '" width="42" height="42" alt="Switchly" style="display:block;width:42px;height:42px;border:0;border-radius:11px"></td><td style="padding-left:13px"><strong style="font-size:20px;color:#fff">' . AppInfo::NAME . '</strong><div style="margin-top:4px;font-size:12px;color:#9da6c5">Network Alert · ' . AppInfo::DISPLAY_VERSION . '</div></td></tr></table></td></tr>'
            . '<tr><td style="padding:30px 28px"><div style="display:inline-block;padding:6px 10px;border-radius:999px;background:' . $color . '22;color:' . $color . ';font-size:11px;font-weight:700">' . $label . '</div>'
            . '<h1 style="margin:18px 0 20px;font-size:24px;color:#fff">' . $safeName . '</h1>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#091423;border:1px solid #263b55;border-radius:9px"><tr><td style="padding:14px;color:#8da4bd;font-size:12px">Adresse</td><td style="padding:14px;text-align:right;color:#fff;font-size:13px">' . $safeHost . '</td></tr><tr><td style="padding:14px;border-top:1px solid #263b55;color:#8da4bd;font-size:12px">Status</td><td style="padding:14px;border-top:1px solid #263b55;text-align:right;color:' . $color . ';font-size:13px;font-weight:700">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</td></tr></table>'
            . ($safeDetail !== '' ? '<p style="margin:20px 0 0;padding:13px;border-radius:7px;background:#091423;color:#aebed0;font-size:12px;line-height:1.5">' . $safeDetail . '</p>' : '')
            . '<p style="margin:20px 0 0;color:#6f849a;font-size:11px">Erkannt am ' . htmlspecialchars(date('d.m.Y H:i:s T'), ENT_QUOTES, 'UTF-8') . '</p></td></tr></table></td></tr></table></body></html>';
        return self::send($to, $subject, $text, $html);
    }

    /** Ermittelt die öffentliche Basisadresse unter Berücksichtigung vertrauenswürdiger Proxy-Header. */
    private static function detectedBaseUrl(): string
    {
        $forwardedProto = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]);
        $https = $forwardedProto !== ''
            ? strtolower($forwardedProto) === 'https'
            : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $forwardedHost = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
        $host = $forwardedHost !== '' ? $forwardedHost : (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/[^a-zA-Z0-9.:[\]-]/', '', $host);
        $forwardedPrefix = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? ''))[0]);
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/api/users.php'));
        $detectedPrefix = dirname($scriptName);
        if (basename($detectedPrefix) === 'api') $detectedPrefix = dirname($detectedPrefix);
        $prefix = $forwardedPrefix !== '' ? $forwardedPrefix : ($detectedPrefix === '/' || $detectedPrefix === '.' ? '' : $detectedPrefix);
        $prefix = '/' . trim((string)preg_replace('/[^a-zA-Z0-9._~\/-]/', '', $prefix), '/');
        if ($prefix === '/') $prefix = '';
        return ($https ? 'https' : 'http') . '://' . ($host !== '' ? $host : 'localhost') . $prefix;
    }

    /** Lädt die lokale Bildmarke als eingebettete Data-URI für HTML-E-Mails. */
    private static function inlineLogoDataUri(): string
    {
        $path = __DIR__ . '/../public/assets/switchly-mark-192.png';
        $bytes = is_file($path) ? file_get_contents($path) : false;
        return is_string($bytes) && $bytes !== ''
            ? 'data:image/png;base64,' . base64_encode($bytes)
            : '';
    }

    /** Erkennt lokale Platzhalteradressen, die nicht für öffentliche Links geeignet sind. */
    private static function isLocalUrl(string $url): bool
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        return in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true);
    }

    /** Prüft, ob eine vollständige HTTP- oder HTTPS-Basisadresse vorliegt. */
    private static function isValidBaseUrl(string $url): bool
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return false;
        return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && (string)parse_url($url, PHP_URL_HOST) !== '';
    }

    /** Wählt zwischen konfiguriertem SMTP-Versand und der PHP-Mailfunktion. */
    private static function send(string $to, string $subject, string $text, string $html): bool
    {
        $settings = self::smtpSettings();
        if ($settings['host'] !== '') return self::sendSmtp($to, $subject, $text, $html, $settings);
        $from = $settings['from'] !== '' ? $settings['from'] : 'switchly@localhost';
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
        [$headers, $body] = self::composeMessage($from, $to, $subject, $text, $html, false);
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        return @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
    }

    /** Überträgt eine Nachricht über eine optionale TLS- und Login-geschützte SMTP-Verbindung. */
    private static function sendSmtp(string $to, string $subject, string $text, string $html, array $settings): bool
    {
        $host = $settings['host'];
        $port = $settings['port'];
        $secure = $settings['secure'];
        $transport = $secure === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) return false;
        stream_set_timeout($socket, 10);

        $read = static function () use ($socket): string {
            $response = '';
            do { $line = fgets($socket, 515); if ($line === false) break; $response .= $line; } while (isset($line[3]) && $line[3] === '-');
            return $response;
        };
        $command = static function (string $line, array $ok) use ($socket, $read): bool {
            fwrite($socket, $line . "\r\n");
            return in_array((int)substr($read(), 0, 3), $ok, true);
        };

        if ((int)substr($read(), 0, 3) !== 220 || !$command('EHLO switchly', [250])) { fclose($socket); return false; }
        if ($secure === 'tls') {
            if (!$command('STARTTLS', [220]) || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) || !$command('EHLO switchly', [250])) { fclose($socket); return false; }
        }
        $username = $settings['username'];
        if ($username !== '') {
            if (!$command('AUTH LOGIN', [334]) || !$command(base64_encode($username), [334]) || !$command(base64_encode($settings['password']), [235])) { fclose($socket); return false; }
        }
        $from = $settings['from'] !== '' ? $settings['from'] : $username;
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) { fclose($socket); return false; }
        if (!$command('MAIL FROM:<' . $from . '>', [250]) || !$command('RCPT TO:<' . $to . '>', [250, 251]) || !$command('DATA', [354])) { fclose($socket); return false; }
        [$headers, $messageBody] = self::composeMessage($from, $to, $subject, $text, $html, true);
        $dotSafeBody = preg_replace('/(?m)^\./', '..', $messageBody);
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $dotSafeBody;
        fwrite($socket, $payload . "\r\n.\r\n");
        $success = (int)substr($read(), 0, 3) === 250;
        $command('QUIT', [221]);
        fclose($socket);
        return $success;
    }

    /**
     * Returns SMTP settings saved through the administration interface.
     *
     * @return array{host:string,port:int,secure:string,username:string,password:string,from:string}
     */
    private static function smtpSettings(): array
    {
        $row = Database::getConnection()->query('SELECT smtp_host,smtp_port,smtp_secure,smtp_username,smtp_password,smtp_from FROM alert_settings WHERE id=1')->fetch() ?: [];
        return [
            'host' => trim((string)($row['smtp_host'] ?? '')),
            'port' => (int)($row['smtp_port'] ?? 587),
            'secure' => strtolower((string)($row['smtp_secure'] ?? 'tls')),
            'username' => (string)($row['smtp_username'] ?? ''),
            'password' => (string)($row['smtp_password'] ?? ''),
            'from' => (string)($row['smtp_from'] ?? ''),
        ];
    }

    /** Erzeugt Header und multipart/alternative-Inhalt für Text- und HTML-Mail. */
    private static function composeMessage(string $from, string $to, string $subject, string $text, string $html, bool $includeRecipients): array
    {
        $boundary = 'switchly_' . bin2hex(random_bytes(12));
        $text = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $text));
        $headers = ['From: ' . AppInfo::NAME . ' <' . $from . '>'];
        if ($includeRecipients) {
            $headers[] = 'To: <' . $to . '>';
            $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        }
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $text . "\r\n--" . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n--" . $boundary . "--\r\n";
        return [$headers, $body];
    }
}
