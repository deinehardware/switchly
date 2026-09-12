<?php

declare(strict_types=1);

/**
 * Thin TOTP self-service HTTP controller for the authenticated user.
 * GET returns state; POST delegates enrollment changes to TwoFactorService.
 */

require_once __DIR__ . '/../../src/Api.php';
require_once __DIR__ . '/../../src/TwoFactorService.php';

Api::bootstrap();
Api::requireLogin();

$username = (string)($_SESSION['username'] ?? '');

// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    $action = (string)($_POST['action'] ?? $_GET['action'] ?? 'status');
    // Aktion „status“: liefert den aktuellen Status.
    if ($action === 'status') Api::respond(TwoFactorService::status($username));
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') Api::respond(['error' => 'Nur POST ist erlaubt.'], 405);

    // Aktion „begin“: beginnt die 2FA-Einrichtung.
    if ($action === 'begin') {
        Api::respond(TwoFactorService::begin($username));
    }

    // Aktion „enable“: bestätigt und aktiviert 2FA.
    if ($action === 'enable') {
        Api::respond(TwoFactorService::enable($username, trim((string)($_POST['code'] ?? ''))));
    }

    // Aktion „disable“: deaktiviert 2FA.
    if ($action === 'disable') {
        Api::respond(TwoFactorService::disable($username, (string)($_POST['password'] ?? '')));
    }

    Api::respond(['error' => 'Ungültige Aktion.'], 400);
} catch (DomainException $e) {
    Api::error($e, 400);
} catch (Throwable $e) {
    $detail = substr((string)preg_replace('/[\r\n]+/', ' ', $e->getMessage()), 0, 500);
    error_log('[Switchly] 2FA request failed: ' . $detail);
    Logger::error('TWO_FACTOR_REQUEST_FAILED', $detail, $username);
    Api::respond(['error' => 'Die 2FA-Anfrage konnte nicht verarbeitet werden.'], 500);
}
