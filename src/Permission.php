<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Resolves effective feature permissions and per-switch access control.
 *
 * Explicit user rights take precedence over group rights. Accounts without
 * any migrated permission rows retain the narrow legacy read-only defaults.
 */
final class Permission
{
    public const CATALOG = [
        'dashboard.view' => 'Dashboard anzeigen',
        'switches.view' => 'Switches und Portstatus anzeigen',
        'switches.manage' => 'Switches anlegen, bearbeiten und löschen',
        'switch.control' => 'Switch-Wartungsaktionen ausführen',
        'switch.reboot' => 'Switch neu starten',
        'switch.backup' => 'Konfiguration herunterladen',
        'switch.restore' => 'Konfiguration wiederherstellen',
        'switch.port.manage' => 'Ports aktivieren, deaktivieren und umbenennen',
        'switch.lag.manage' => 'LAG/LACP verwalten',
        'switch.vlan.manage' => 'VLANs und Port-Zuordnungen verwalten',
        'monitoring.view' => 'Monitoring anzeigen',
        'alerts.manage' => 'Ausfall-Alerts verwalten',
        'logs.view' => 'CMS- und Switch-Logs anzeigen',
        'users.manage' => 'Benutzer verwalten',
        'permissions.manage' => 'Rechte und Gruppen verwalten',
        'sso.manage' => 'OpenID Connect verwalten',
    ];

    private const LEGACY_DEFAULTS = ['dashboard.view', 'switches.view', 'monitoring.view'];

    /** @return array<string, mixed>|null Current database user or null. */
    public static function currentUser(): ?array
    {
        $username = (string)($_SESSION['username'] ?? '');
        if ($username === '') return null;
        $stmt = Database::getConnection()->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        return $stmt->fetch() ?: null;
    }

    /** Tests one catalog permission for a fully loaded user row. */
    public static function has(array $user, string $code): bool
    {
        if (($user['role'] ?? 'user') === 'admin') return true;
        if (!isset(self::CATALOG[$code])) return false;

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT allowed FROM user_permissions WHERE user_id = :uid AND permission_code = :code');
        $stmt->execute(['uid' => $user['id'], 'code' => $code]);
        $direct = $stmt->fetchColumn();
        if ($direct !== false) return (bool)$direct;

        $stmt = $db->prepare('SELECT gp.allowed FROM group_permissions gp JOIN group_members gm ON gm.group_id = gp.group_id WHERE gm.user_id = :uid AND gp.permission_code = :code ORDER BY gp.allowed DESC LIMIT 1');
        $stmt->execute(['uid' => $user['id'], 'code' => $code]);
        $group = $stmt->fetchColumn();
        if ($group !== false) return (bool)$group;

        $stmt = $db->prepare('SELECT (SELECT COUNT(*) FROM user_permissions WHERE user_id = :uid) + (SELECT COUNT(*) FROM group_permissions gp JOIN group_members gm ON gm.group_id = gp.group_id WHERE gm.user_id = :uid)');
        $stmt->execute(['uid' => $user['id']]);
        return (int)$stmt->fetchColumn() === 0 && in_array($code, self::LEGACY_DEFAULTS, true);
    }

    /**
     * Tests the device allow-list. No assigned device means unrestricted
     * device visibility; the first assignment turns the union into a list.
     */
    public static function canAccessSwitch(array $user, string $switchId): bool
    {
        if (($user['role'] ?? 'user') === 'admin') return true;
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT COUNT(*) FROM user_switch_access WHERE user_id = :uid');
        $stmt->execute(['uid' => $user['id']]);
        $directCount = (int)$stmt->fetchColumn();
        $stmt = $db->prepare('SELECT COUNT(*) FROM group_switch_access gsa JOIN group_members gm ON gm.group_id = gsa.group_id WHERE gm.user_id = :uid');
        $stmt->execute(['uid' => $user['id']]);
        $groupCount = (int)$stmt->fetchColumn();
        if (($directCount + $groupCount) === 0) return true;

        $stmt = $db->prepare('SELECT 1 FROM user_switch_access WHERE user_id = :uid AND switch_id = :sid UNION SELECT 1 FROM group_switch_access gsa JOIN group_members gm ON gm.group_id = gsa.group_id WHERE gm.user_id = :uid AND gsa.switch_id = :sid LIMIT 1');
        $stmt->execute(['uid' => $user['id'], 'sid' => $switchId]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return list<string>|null Null represents unrestricted switch access. */
    public static function accessibleSwitchIds(array $user): ?array
    {
        if (($user['role'] ?? 'user') === 'admin') return null;
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT switch_id FROM user_switch_access WHERE user_id = :uid UNION SELECT gsa.switch_id FROM group_switch_access gsa JOIN group_members gm ON gm.group_id = gsa.group_id WHERE gm.user_id = :uid');
        $stmt->execute(['uid' => $user['id']]);
        $ids = array_column($stmt->fetchAll(), 'switch_id');
        return $ids === [] ? null : array_values(array_unique($ids));
    }

    /** @return list<string> Effective permission codes, including expanded control rights. */
    public static function codesFor(array $user): array
    {
        $codes = array_values(array_filter(array_keys(self::CATALOG), static fn(string $code): bool => self::has($user, $code)));
        if (in_array('switch.control', $codes, true)) {
            $codes = array_values(array_unique(array_merge($codes, ['switch.reboot', 'switch.backup', 'switch.restore', 'switch.port.manage', 'switch.lag.manage', 'switch.vlan.manage'])));
        }
        return $codes;
    }
}
