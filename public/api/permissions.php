<?php

declare(strict_types=1);

/**
 * GET/POST permissions, groups and switch assignments.
 * Requires permissions.manage. Submitted arrays replace complete assignments
 * within a database transaction.
 */

require_once __DIR__ . '/../../src/Api.php';
require_once __DIR__ . '/../../src/Logger.php';

Api::bootstrap();
Api::requirePermission('permissions.manage');

/** Liest eine übermittelte JSON-Liste und gibt ausschließlich eindeutige positive IDs zurück. */
function jsonIds(string $name): array {
    $value = json_decode((string)($_POST[$name] ?? '[]'), true);
    if (!is_array($value)) throw new RuntimeException('Ungültige Liste: ' . $name);
    return array_values(array_unique(array_map('strval', $value)));
}

// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    $db = Database::getConnection();
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');

    // Aktion „list“: liefert die angeforderten Datensätze.
    if ($action === 'list') {
        $users = $db->query('SELECT id, username, role FROM users ORDER BY username')->fetchAll();
        $configuredUsers = array_flip(array_map('strval', array_column($db->query("SELECT user_id FROM user_permissions WHERE permission_code = '__configured__'")->fetchAll(), 'user_id')));
        foreach ($users as &$permissionUser) {
            $permissionUser['effective_permissions'] = Permission::codesFor($permissionUser);
            $permissionUser['permissions_configured'] = isset($configuredUsers[(string)$permissionUser['id']]);
        }
        unset($permissionUser);
        $groups = $db->query('SELECT id, name, description FROM permission_groups ORDER BY name')->fetchAll();
        $switches = $db->query('SELECT id, name FROM switches ORDER BY name')->fetchAll();
        $userPermissions = $db->query('SELECT user_id, permission_code, allowed FROM user_permissions')->fetchAll();
        $groupPermissions = $db->query('SELECT group_id, permission_code, allowed FROM group_permissions')->fetchAll();
        $members = $db->query('SELECT group_id, user_id FROM group_members')->fetchAll();
        $userSwitches = $db->query('SELECT user_id, switch_id FROM user_switch_access')->fetchAll();
        $groupSwitches = $db->query('SELECT group_id, switch_id FROM group_switch_access')->fetchAll();
        Api::respond(compact('users', 'groups', 'switches', 'userPermissions', 'groupPermissions', 'members', 'userSwitches', 'groupSwitches') + ['catalog' => Permission::CATALOG]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') Api::respond(['error' => 'Nur POST ist erlaubt.'], 405);

    // Aktion „save_user“: ersetzt die Zuweisungen eines Benutzers.
    if ($action === 'save_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $permissions = jsonIds('permissions');
        $groups = array_map('intval', jsonIds('groups'));
        $switches = jsonIds('switches');
        $db->beginTransaction();
        $db->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute(['id' => $userId]);
        $db->prepare('DELETE FROM group_members WHERE user_id = :id')->execute(['id' => $userId]);
        $db->prepare('DELETE FROM user_switch_access WHERE user_id = :id')->execute(['id' => $userId]);
        $stmt = $db->prepare('INSERT INTO user_permissions (user_id, permission_code, allowed) VALUES (:id, :code, 1)');
        foreach ($permissions as $code) { if (isset(Permission::CATALOG[$code])) $stmt->execute(['id' => $userId, 'code' => $code]); }
        $db->prepare("INSERT INTO user_permissions (user_id, permission_code, allowed) VALUES (:id, '__configured__', 0)")->execute(['id' => $userId]);
        $stmt = $db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:group_id, :user_id)');
        foreach ($groups as $groupId) $stmt->execute(['group_id' => $groupId, 'user_id' => $userId]);
        $stmt = $db->prepare('INSERT INTO user_switch_access (user_id, switch_id) VALUES (:user_id, :switch_id)');
        foreach ($switches as $switchId) $stmt->execute(['user_id' => $userId, 'switch_id' => $switchId]);
        $db->commit();
        Logger::warning('USER_PERMISSIONS_UPDATED', 'Rechte für Benutzer-ID ' . $userId . ' aktualisiert.');
        Api::respond(['success' => true]);
    }

    // Aktion „save_group“: speichert Gruppe, Mitglieder und Zuweisungen.
    if ($action === 'save_group') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $permissions = jsonIds('permissions');
        $switches = jsonIds('switches');
        $members = array_map('intval', jsonIds('members'));
        if ($name === '' || strlen($name) > 64) throw new RuntimeException('Gruppenname ist erforderlich und darf maximal 64 Zeichen lang sein.');
        $db->beginTransaction();
        if ($groupId > 0) {
            $db->prepare('UPDATE permission_groups SET name = :name, description = :description WHERE id = :id')->execute(compact('name', 'description') + ['id' => $groupId]);
        } else {
            $db->prepare('INSERT INTO permission_groups (name, description) VALUES (:name, :description)')->execute(compact('name', 'description'));
            $groupId = (int)$db->lastInsertId();
        }
        $db->prepare('DELETE FROM group_permissions WHERE group_id = :id')->execute(['id' => $groupId]);
        $db->prepare('DELETE FROM group_switch_access WHERE group_id = :id')->execute(['id' => $groupId]);
        $db->prepare('DELETE FROM group_members WHERE group_id = :id')->execute(['id' => $groupId]);
        $stmt = $db->prepare('INSERT INTO group_permissions (group_id, permission_code, allowed) VALUES (:id, :code, 1)');
        foreach ($permissions as $code) { if (isset(Permission::CATALOG[$code])) $stmt->execute(['id' => $groupId, 'code' => $code]); }
        $stmt = $db->prepare('INSERT INTO group_switch_access (group_id, switch_id) VALUES (:id, :switch_id)');
        foreach ($switches as $switchId) $stmt->execute(['id' => $groupId, 'switch_id' => $switchId]);
        $stmt = $db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:id, :user_id)');
        foreach ($members as $userId) $stmt->execute(['id' => $groupId, 'user_id' => $userId]);
        $db->commit();
        Logger::warning('PERMISSION_GROUP_SAVED', 'Rechtegruppe ' . $name . ' gespeichert.');
        Api::respond(['success' => true, 'group_id' => $groupId]);
    }

    // Aktion „delete_group“: löscht die ausgewählte Gruppe.
    if ($action === 'delete_group') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $db->prepare('DELETE FROM permission_groups WHERE id = :id')->execute(['id' => $groupId]);
        Logger::warning('PERMISSION_GROUP_DELETED', 'Rechtegruppe-ID ' . $groupId . ' gelöscht.');
        Api::respond(['success' => true]);
    }

    Api::respond(['error' => 'Ungültige Aktion.'], 400);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    Api::error($e, 400);
}
