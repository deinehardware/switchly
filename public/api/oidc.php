<?php

declare(strict_types=1);

/**
 * GET/POST OpenID Connect administration endpoint.
 * Requires sso.manage and never returns the stored client secret.
 */

require_once __DIR__ . '/../../src/Api.php';
require_once __DIR__ . '/../../src/SsoService.php';
require_once __DIR__ . '/../../src/Logger.php';

Api::bootstrap();
Api::requirePermission('sso.manage');

// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        Api::respond(['settings' => SsoService::adminSettings()]);
    }

    $action = trim((string)($_POST['action'] ?? 'save'));
    if (!in_array($action, ['save', 'test'], true)) Api::respond(['error' => 'Ungültige OpenID-Aktion.'], 400);

    SsoService::saveSettings([
        'enabled' => filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'provider_name' => (string)($_POST['provider_name'] ?? ''),
        'public_base_url' => (string)($_POST['public_base_url'] ?? ''),
        'discovery_url' => (string)($_POST['discovery_url'] ?? ''),
        'client_id' => (string)($_POST['client_id'] ?? ''),
        'client_secret' => (string)($_POST['client_secret'] ?? ''),
        'scopes' => (string)($_POST['scopes'] ?? ''),
        'client_auth_method' => (string)($_POST['client_auth_method'] ?? ''),
        'public_client' => filter_var($_POST['public_client'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'allow_http' => filter_var($_POST['allow_http'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'tls_verify' => filter_var($_POST['tls_verify'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'email_claim' => (string)($_POST['email_claim'] ?? ''),
        'username_claim' => (string)($_POST['username_claim'] ?? ''),
        'name_claim' => (string)($_POST['name_claim'] ?? ''),
        'email_verified_claim' => (string)($_POST['email_verified_claim'] ?? ''),
        'auto_create' => filter_var($_POST['auto_create'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'allowed_domains' => (string)($_POST['allowed_domains'] ?? ''),
        'trust_provider_email' => filter_var($_POST['trust_provider_email'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ]);

    $test = null;
    // Aktion „test“: prüft die aktuell übermittelten Einstellungen.
    if ($action === 'test') $test = SsoService::testConnection();
    Logger::write('INFO', $action === 'test' ? 'OIDC_CONNECTION_TESTED' : 'OIDC_SETTINGS_CHANGED', $action === 'test' ? 'OpenID-Connect-Discovery erfolgreich getestet.' : 'OpenID-Connect-Einstellungen wurden geändert.');
    Api::respond(['success' => true, 'test' => $test, 'settings' => SsoService::adminSettings()]);
} catch (Throwable $error) {
    Api::error($error, 400);
}
