<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/** Applies independent, hashed account and source-IP limits to login attempts. */
final class LoginRateLimiter
{
    private const WINDOW = 900;
    private const BLOCK = 900;

    /** Returns the remaining block duration for either account or source address. */
    public static function retryAfter(string $category, string $identity): int
    {
        $now = time();
        $maximum = 0;
        foreach (self::keys($category, $identity) as $key) {
            $stmt = Database::getConnection()->prepare('SELECT blocked_until FROM auth_rate_limits WHERE rate_key=:key');
            $stmt->execute(['key' => $key]);
            $maximum = max($maximum, (int)($stmt->fetchColumn() ?: 0) - $now);
        }
        return max(0, $maximum);
    }

    /** Records a failed attempt and starts a temporary block at the threshold. */
    public static function recordFailure(string $category, string $identity): void
    {
        $now = time();
        Database::getConnection()->prepare('DELETE FROM auth_rate_limits WHERE last_failure_at < :cutoff')->execute(['cutoff' => $now - 86400]);
        foreach (self::keys($category, $identity) as $scope => $key) {
            $limit = $scope === 'account' ? ($category === 'totp' ? 6 : 5) : ($category === 'totp' ? 30 : 25);
            $stmt = Database::getConnection()->prepare('SELECT failures,window_started_at FROM auth_rate_limits WHERE rate_key=:key');
            $stmt->execute(['key' => $key]);
            $row = $stmt->fetch();
            $started = (int)($row['window_started_at'] ?? $now);
            $failures = ($row && $started >= $now - self::WINDOW) ? (int)$row['failures'] + 1 : 1;
            if ($started < $now - self::WINDOW) $started = $now;
            $blockedUntil = $failures >= $limit ? $now + self::BLOCK : 0;
            $write = Database::getConnection()->prepare('INSERT INTO auth_rate_limits (rate_key,category,failures,window_started_at,last_failure_at,blocked_until) VALUES (:key,:category,:failures,:started,:last,:blocked) ON CONFLICT(rate_key) DO UPDATE SET failures=excluded.failures,window_started_at=excluded.window_started_at,last_failure_at=excluded.last_failure_at,blocked_until=excluded.blocked_until');
            $write->execute(['key' => $key, 'category' => $category, 'failures' => $failures, 'started' => $started, 'last' => $now, 'blocked' => $blockedUntil]);
        }
    }

    /** Removes counters after successful authentication. */
    public static function clear(string $category, string $identity): void
    {
        $stmt = Database::getConnection()->prepare('DELETE FROM auth_rate_limits WHERE rate_key=:key');
        // Keep the broader IP bucket so one successful account cannot erase
        // evidence of a password-spraying attempt against other accounts.
        $stmt->execute(['key' => self::keys($category, $identity)['account']]);
        Database::getConnection()->prepare('DELETE FROM auth_rate_limits WHERE last_failure_at < :cutoff')->execute(['cutoff' => time() - 86400]);
    }

    /** Builds privacy-preserving keys without trusting proxy-supplied headers. */
    private static function keys(string $category, string $identity): array
    {
        $account = strtolower(trim($identity));
        $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return [
            'account' => hash('sha256', $category . "\0account\0" . $account),
            'ip' => hash('sha256', $category . "\0ip\0" . $address),
        ];
    }
}
