<?php

declare(strict_types=1);

/** OpenID Connect authorization start and callback endpoint. */

require_once __DIR__ . '/../src/SsoService.php';

Auth::startSession();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
try {
    // Startet bei ausdrücklicher Aktion den Authorization-Code-Flow.
    if (($_GET['action'] ?? '') === 'start') {
        SsoService::start(trim((string)($_GET['provider'] ?? '')));
    }
    // Ohne Startaktion verarbeitet die Seite den Rücksprung des Anbieters.
    $result = SsoService::callback();
    header('Location: ' . ($result === '2fa' ? 'login.php' : 'index.php'));
    exit;
} catch (Throwable $error) {
    Logger::warning('SSO_LOGIN_FAILED', $error->getMessage());
    $_SESSION['sso_error'] = $error->getMessage();
    header('Location: login.php');
    exit;
}
