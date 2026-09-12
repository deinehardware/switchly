<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/** Persists structured audit and operational events in SQLite. */
final class Logger
{
    /**
     * Writes one best-effort log record.
     * Logging failures are swallowed deliberately so diagnostics cannot break
     * the user operation that produced the event.
     */
    public static function write(string $level, string $event, string $message = '', ?string $username = null, ?string $switchId = null, string $source = 'cms'): void
    {
        try {
            $stmt = Database::getConnection()->prepare(
                'INSERT INTO system_logs (level, event, message, username, ip_address, switch_id, source, created_at)
                 VALUES (:level, :event, :message, :username, :ip, :switch_id, :source, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                'level' => strtoupper($level),
                'event' => $event,
                'message' => $message,
                'username' => $username ?? ($_SESSION['username'] ?? null),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'switch_id' => $switchId,
                'source' => $source,
            ]);
        } catch (Throwable) {
            // Logging darf die eigentliche Anwendung nicht blockieren.
        }
    }

    /** Convenience wrapper for CMS informational events. */
    public static function info(string $event, string $message = '', ?string $username = null): void
    {
        self::write('INFO', $event, $message, $username);
    }

    /** Convenience wrapper for CMS warning events. */
    public static function warning(string $event, string $message = '', ?string $username = null): void
    {
        self::write('WARNING', $event, $message, $username);
    }

    /** Convenience wrapper for CMS error events. */
    public static function error(string $event, string $message = '', ?string $username = null): void
    {
        self::write('ERROR', $event, $message, $username);
    }

    /** Writes a device-scoped record that can be filtered by switch ID. */
    public static function switchEvent(string $level, string $event, string $message, string $switchId, ?string $username = null): void
    {
        self::write($level, $event, $message, $username, $switchId, 'switch');
    }
}
