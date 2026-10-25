<?php
declare(strict_types=1);
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/TOTP.php';
require_once __DIR__ . '/SecretStore.php';
require_once __DIR__ . '/LoginRateLimiter.php';

/**
 * Handles local authentication, the optional second factor and PHP sessions.
 * Authorization is intentionally separate and lives in Permission.
 */
class Auth
{
    /** Starts the hardened application session exactly once per request. */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('switchly_session');
            $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            $secure = $forwardedProto !== ''
                ? $forwardedProto === 'https'
                : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $cookie = session_get_cookie_params();
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $cookie['path'] ?: '/',
                'domain' => $cookie['domain'],
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /** Returns true only after primary authentication and any pending TOTP challenge. */
    public static function isLoggedIn(): bool
    {
        self::startSession();
        return !empty($_SESSION['logged_in']) && empty($_SESSION['pending_2fa']);
    }

    /** Returns the session-bound CSRF token, creating it with a CSPRNG if needed. */
    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return (string)$_SESSION['csrf_token'];
    }

    /** Performs a timing-safe comparison against the token stored in the session. */
    public static function validateCsrf(?string $token): bool
    {
        self::startSession();
        return is_string($token) && $token !== '' && !empty($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
    }

    /** Redirects unauthenticated browser requests to the login page. */
    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }

    /**
     * Verifies primary credentials.
     *
     * @return 'success'|'2fa'|'email_unverified'|'failed'|'rate_limited'
     */
    public static function login(string $username, string $password): string
    {
        self::startSession();
        if (LoginRateLimiter::retryAfter('password', $username) > 0) {
            Logger::warning('LOGIN_RATE_LIMITED', 'Zu viele Anmeldeversuche; Anmeldung vorübergehend gesperrt.', $username);
            return 'rate_limited';
        }
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = :user');
        $stmt->execute(['user' => $username]);
        $user = $stmt->fetch();
        // Verify against a real hash even for an unknown username so account
        // existence is not exposed through a cheap timing difference.
        $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $passwordValid = password_verify($password, (string)($user['password_hash'] ?? $dummyHash));

        if (!$user || !$passwordValid) {
            LoginRateLimiter::recordFailure('password', $username);
            Logger::warning('LOGIN_FAILED', 'Fehlgeschlagene Anmeldung für Benutzer ' . $username, $username);
            return 'failed';
        }
        LoginRateLimiter::clear('password', $username);
        $user = SecretStore::decryptUser($user);

        if (!empty($user['email']) && empty($user['email_verified_at'])) {
            Logger::warning('LOGIN_EMAIL_UNVERIFIED', 'Anmeldung mit unbestätigter E-Mail-Adresse.', $user['username']);
            return 'email_unverified';
        }

        if (!empty($user['two_factor_enabled']) && !empty($user['two_factor_secret'])) {
            $_SESSION['pending_2fa'] = true;
            $_SESSION['pending_user_id'] = (int)$user['id'];
            $_SESSION['pending_username'] = $user['username'];
            Logger::info('LOGIN_PASSWORD_OK_2FA_REQUIRED', 'Passwort korrekt, 2FA-Code erforderlich.', $user['username']);
            return '2fa';
        }

        self::completeLogin($user);
        return 'success';
    }

    /** Completes a pending login and reports failures without allowing brute force. */
    public static function verifyPending2FA(string $code): string
    {
        self::startSession();
        if (empty($_SESSION['pending_2fa']) || empty($_SESSION['pending_user_id'])) return 'failed';
        $identity = (string)($_SESSION['pending_username'] ?? $_SESSION['pending_user_id']);
        if (LoginRateLimiter::retryAfter('totp', $identity) > 0) return 'rate_limited';
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => (int)$_SESSION['pending_user_id']]);
        $user = $stmt->fetch();
        if (!$user) return 'failed';
        $user = SecretStore::decryptUser($user);
        if (empty($user['two_factor_enabled']) || empty($user['two_factor_secret'])) return 'failed';
        if (!TOTP::verify($user['two_factor_secret'], $code)) {
            LoginRateLimiter::recordFailure('totp', $identity);
            Logger::warning('LOGIN_2FA_FAILED', 'Ungültiger 2FA-Code.', $user['username']);
            return 'failed';
        }
        LoginRateLimiter::clear('totp', $identity);
        self::completeLogin($user);
        Logger::info('LOGIN_2FA_SUCCESS', '2FA-Anmeldung erfolgreich.', $user['username']);
        return 'success';
    }

    /**
     * Starts an application session for an OIDC-resolved local account.
     *
     * @return 'success'|'2fa'
     * @throws RuntimeException When the linked local account no longer exists.
     */
    public static function loginSsoUser(int $userId, string $provider): string
    {
        self::startSession();
        $stmt = Database::getConnection()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) throw new RuntimeException('Das mit SSO verknüpfte Benutzerkonto wurde nicht gefunden.');
        $user = SecretStore::decryptUser($user);

        if (!empty($user['two_factor_enabled']) && !empty($user['two_factor_secret'])) {
            $_SESSION['pending_2fa'] = true;
            $_SESSION['pending_user_id'] = (int)$user['id'];
            $_SESSION['pending_username'] = $user['username'];
            Logger::info('SSO_OK_2FA_REQUIRED', 'SSO über ' . $provider . ' erfolgreich, lokaler 2FA-Code erforderlich.', $user['username']);
            return '2fa';
        }

        self::completeLogin($user);
        Logger::info('SSO_LOGIN_SUCCESS', 'SSO-Anmeldung über ' . $provider . ' erfolgreich.', $user['username']);
        return 'success';
    }

    /** Rotates the session identifier before granting an authenticated session. */
    private static function completeLogin(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['role'] = $user['role'] ?? 'user';
        unset($_SESSION['pending_2fa'], $_SESSION['pending_user_id'], $_SESSION['pending_username']);
        Logger::info('LOGIN_SUCCESS', 'Erfolgreiche Anmeldung.', $user['username']);
    }

    /** Clears server-side session state and expires the browser session cookie. */
    public static function logout(): void
    {
        self::startSession();
        $username = $_SESSION['username'] ?? null;
        Logger::info('LOGOUT', 'Benutzer abgemeldet.', $username);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
