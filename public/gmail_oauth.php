<?php

declare(strict_types=1);

/** Starts and completes Gmail's server-side OAuth authorization flow. */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Permission.php';
require_once __DIR__ . '/../src/EmailService.php';
require_once __DIR__ . '/../src/GmailOAuthService.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/SecretStore.php';

Auth::startSession();
Auth::requireLogin();
$user = Permission::currentUser();
if (!$user || !Permission::has($user, 'alerts.manage')) {
    http_response_code(403);
    exit('Keine Berechtigung für Gmail OAuth2.');
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$redirectUri = rtrim(EmailService::baseUrl(), '/') . '/gmail_oauth.php';

try {
    if (($_GET['action'] ?? '') === 'start') {
        $settings = GmailOAuthService::settings();
        if (trim((string)($settings['gmail_client_id'] ?? '')) === '' || trim((string)($settings['gmail_client_secret'] ?? '')) === '') {
            throw new RuntimeException('Bitte zuerst Google Client-ID und Client-Secret speichern.');
        }
        $state = bin2hex(random_bytes(32));
        $_SESSION['gmail_oauth_state'] = $state;
        $_SESSION['gmail_oauth_started_at'] = time();
        $query = http_build_query([
            'client_id' => $settings['gmail_client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => GmailOAuthService::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query);
        exit;
    }

    $expected = (string)($_SESSION['gmail_oauth_state'] ?? '');
    $startedAt = (int)($_SESSION['gmail_oauth_started_at'] ?? 0);
    unset($_SESSION['gmail_oauth_state'], $_SESSION['gmail_oauth_started_at']);
    if ($expected === '' || !hash_equals($expected, (string)($_GET['state'] ?? '')) || $startedAt < time() - 600) throw new RuntimeException('Google-OAuth-State ist ungültig oder abgelaufen.');
    if (isset($_GET['error'])) throw new RuntimeException('Google-Autorisierung abgebrochen: ' . (string)$_GET['error']);
    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') throw new RuntimeException('Google hat keinen Autorisierungscode geliefert.');
    $settings = GmailOAuthService::settings();
    $tokens = GmailOAuthService::postForm('https://oauth2.googleapis.com/token', [
        'client_id' => (string)$settings['gmail_client_id'],
        'client_secret' => (string)$settings['gmail_client_secret'],
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => $redirectUri,
    ]);
    $refreshToken = trim((string)($tokens['refresh_token'] ?? ''));
    if ($refreshToken === '') throw new RuntimeException('Google hat kein Refresh-Token geliefert. Verbindung bitte erneut starten.');
    Database::getConnection()->prepare('UPDATE alert_settings SET gmail_refresh_token=:token,gmail_oauth_enabled=1,updated_at=CURRENT_TIMESTAMP WHERE id=1')->execute(['token' => SecretStore::encrypt($refreshToken, 'alerts:1:gmail_refresh_token')]);
    Logger::write('INFO', 'GMAIL_OAUTH_CONNECTED', 'Gmail OAuth2 wurde verbunden.');
    header('Location: index.php#alerts');
    exit;
} catch (Throwable $error) {
    Logger::warning('GMAIL_OAUTH_FAILED', $error->getMessage());
    $_SESSION['gmail_oauth_error'] = $error->getMessage();
    header('Location: index.php#alerts');
    exit;
}
