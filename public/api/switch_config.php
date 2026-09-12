<?php

declare(strict_types=1);

/**
 * GET /api/switch_config.php — live LAG and VLAN configuration.
 * Requires switches.view plus access to the requested switch ID.
 */

require_once __DIR__ . '/../../src/Api.php';
require_once __DIR__ . '/../../src/SwOS.php';

Api::bootstrap();
$user = Api::requirePermission('switches.view');

// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    $id = trim((string)($_GET['id'] ?? ''));
    $section = trim((string)($_GET['section'] ?? 'all'));
    if ($id === '') throw new RuntimeException('Keine Switch-ID angegeben.');
    Api::requireSwitchAccess($user, $id);
    $stmt = Database::getConnection()->prepare('SELECT * FROM switches WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $switch = $stmt->fetch();
    if (!$switch) Api::respond(['error' => 'Switch nicht gefunden.'], 404);
    $client = new SwOS([
        'host' => $switch['host'], 'port' => (int)$switch['port'],
        'username' => $switch['username'], 'password' => $switch['password'],
    ]);

    $result = [];
    $errors = [];
    if ($section === 'all' || $section === 'lag') {
        try { $result['lag'] = $client->getLagConfiguration(); }
        catch (Throwable $e) { $errors['lag'] = $e->getMessage(); }
    }
    if ($section === 'all' || $section === 'vlan') {
        try { $result['vlan'] = $client->getVlanConfiguration(); }
        catch (Throwable $e) { $errors['vlan'] = $e->getMessage(); }
    }
    Api::respond(['success' => true, 'config' => $result, 'errors' => $errors]);
} catch (Throwable $e) {
    Api::error($e, 400);
}
