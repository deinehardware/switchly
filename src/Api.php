<?php

declare(strict_types=1);

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Permission.php';

/**
 * Shared boundary for the session-authenticated JSON API.
 *
 * Endpoint files remain deliberately small: this class applies response
 * headers and CSRF protection while Permission enforces authorization.
 */
final class Api
{
    /** Initializes JSON headers and validates CSRF for every POST request. */
    public static function bootstrap(bool $noCache = true): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($noCache) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
            if (!Auth::validateCsrf($token)) self::respond(['error' => 'Sicherheits-Token ist ungültig oder abgelaufen. Bitte Seite neu laden.'], 419);
        }
    }

    /** Terminates an unauthenticated API request with HTTP 401. */
    public static function requireLogin(): void
    {
        if (!Auth::isLoggedIn()) {
            self::respond(['error' => 'Nicht angemeldet.'], 401);
        }
    }

    /** @return array<string, mixed> Authenticated administrator row. */
    public static function requireAdmin(): array
    {
        self::requireLogin();
        $db = Database::getConnection();
        $username = (string)($_SESSION['username'] ?? '');
        $stmt = $db->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user || ($user['role'] ?? 'user') !== 'admin') {
            self::respond(['error' => 'Nur Administratoren dürfen diese Funktion verwenden.'], 403);
        }

        return $user;
    }

    /** Returns the current user or terminates with HTTP 401/403. */
    public static function requirePermission(string $code): array
    {
        self::requireLogin();
        $user = Permission::currentUser();
        if (!$user || !Permission::has($user, $code)) {
            self::respond(['error' => 'Für diese Funktion fehlt die Berechtigung: ' . $code], 403);
        }
        return $user;
    }

    /** Terminates with HTTP 403 when the user cannot access the switch. */
    public static function requireSwitchAccess(array $user, string $switchId): void
    {
        if (!Permission::canAccessSwitch($user, $switchId)) {
            self::respond(['error' => 'Kein Zugriff auf diesen Switch.'], 403);
        }
    }

    /** Emits the final JSON response and stops endpoint execution. */
    public static function respond(array $payload, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Converts a handled exception into the API's canonical error envelope. */
    public static function error(Throwable $error, int $status = 400): never
    {
        self::respond(['error' => $error->getMessage()], $status);
    }
}
