<?php

declare(strict_types=1);

/**
 * GET/POST /api/language.php — reads or changes the personal UI language and
 * lets administrators configure the application-wide default language.
 */

require_once __DIR__ . '/../../src/Api.php';
require_once __DIR__ . '/../../src/Locale.php';
require_once __DIR__ . '/../../src/Logger.php';

Api::bootstrap();
$user = Api::requirePermission('dashboard.view');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        Api::respond([
            'language' => Locale::current($user),
            'default_language' => Locale::defaultLanguage(),
            'supported' => Locale::SUPPORTED,
            'can_set_default' => ($user['role'] ?? 'user') === 'admin',
        ]);
    }

    $action = trim((string)($_POST['action'] ?? 'set_user'));
    $language = (string)($_POST['language'] ?? '');
    if ($action === 'set_user') {
        Locale::setUserLanguage((int)$user['id'], $language);
        Logger::info('LANGUAGE_CHANGED', 'Oberflächensprache geändert.', (string)$user['username']);
        Api::respond(['success' => true, 'language' => Locale::normalize($language)]);
    }
    if ($action === 'set_default') {
        if (($user['role'] ?? 'user') !== 'admin') Api::respond(['error' => 'Nur Administratoren dürfen die Standardsprache ändern.'], 403);
        Locale::setDefaultLanguage($language);
        Logger::info('DEFAULT_LANGUAGE_CHANGED', 'Standardsprache geändert.', (string)$user['username']);
        Api::respond(['success' => true, 'default_language' => Locale::normalize($language)]);
    }
    Api::respond(['error' => 'Ungültige Sprachaktion.'], 400);
} catch (Throwable $error) {
    Api::error($error, 400);
}
