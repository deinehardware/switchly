<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/TOTP.php';
require_once __DIR__ . '/Logger.php';

/**
 * Implements authenticated TOTP self-service independently of HTTP transport.
 *
 * The public endpoint is responsible only for request validation and JSON
 * responses. Database changes, password verification and audit events remain
 * centralized here so they can be reused and tested without a web request.
 */
final class TwoFactorService
{
    /** Returns whether TOTP is enabled for the named local account. */
    public static function status(string $username): array
    {
        $user = self::userByUsername($username);

        return ['enabled' => (int)$user['two_factor_enabled']];
    }

    /**
     * Creates and persists a pending TOTP secret and returns enrollment data.
     * Existing active enrollment must be disabled deliberately before a new
     * secret can be generated.
     */
    public static function begin(string $username): array
    {
        $user = self::userByUsername($username);
        if ((int)$user['two_factor_enabled'] === 1) {
            throw new DomainException('2FA ist bereits aktiviert. Deaktiviere sie zuerst, um sie neu einzurichten.');
        }

        $secret = TOTP::generateSecret();
        $stmt = Database::getConnection()->prepare(
            'UPDATE users SET two_factor_secret = :secret WHERE id = :id'
        );
        $stmt->execute(['secret' => $secret, 'id' => $user['id']]);

        return [
            'success' => true,
            'secret' => $secret,
            'uri' => TOTP::provisioningUri($secret, (string)$user['username']),
        ];
    }

    /** Confirms the pending TOTP secret with a valid authenticator code. */
    public static function enable(string $username, string $code): array
    {
        $user = self::userByUsername($username);
        if ((int)$user['two_factor_enabled'] === 1) {
            throw new DomainException('2FA ist bereits aktiviert.');
        }

        $secret = (string)($user['two_factor_secret'] ?? '');
        if ($secret === '' || !TOTP::verify($secret, $code)) {
            throw new DomainException('Code ungültig. Prüfe Uhrzeit und Authenticator-App.');
        }

        $stmt = Database::getConnection()->prepare(
            'UPDATE users SET two_factor_enabled = 1 WHERE id = :id'
        );
        $stmt->execute(['id' => $user['id']]);
        Logger::info('2FA_ENABLED', '2FA erfolgreich aktiviert.', $username);

        return ['success' => true];
    }

    /** Disables TOTP after verifying the current local account password. */
    public static function disable(string $username, string $password): array
    {
        $user = self::userByUsername($username);
        if (!password_verify($password, (string)$user['password_hash'])) {
            throw new DomainException('Passwort ist falsch.');
        }

        $stmt = Database::getConnection()->prepare(
            'UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $user['id']]);
        Logger::warning('2FA_DISABLED', '2FA wurde deaktiviert.', $username);

        return ['success' => true];
    }

    /** Loads the local account or fails without exposing database details. */
    private static function userByUsername(string $username): array
    {
        if ($username === '') {
            throw new DomainException('Benutzer nicht gefunden.');
        }

        $stmt = Database::getConnection()->prepare(
            'SELECT id, username, password_hash, two_factor_enabled, two_factor_secret
             FROM users WHERE username = :username'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new DomainException('Benutzer nicht gefunden.');
        }

        return $user;
    }
}
