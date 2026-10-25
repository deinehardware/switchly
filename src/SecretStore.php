<?php

declare(strict_types=1);

/**
 * Encrypts application secrets at rest with an installation-specific key.
 * AES-GCM also authenticates the ciphertext and its database-field context.
 */
final class SecretStore
{
    private const PREFIX = 'enc:v1:';

    /** Fails fast during startup when no durable installation key is configured. */
    public static function assertConfigured(): void
    {
        self::key();
    }

    /** Encrypts a non-empty secret for one specific record and field. */
    public static function encrypt(string $plainText, string $context): string
    {
        if ($plainText === '') return '';
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, $context, 16);
        if ($cipherText === false) throw new RuntimeException('Ein Anwendungsgeheimnis konnte nicht verschlüsselt werden.');
        return self::PREFIX . rtrim(strtr(base64_encode($iv . $tag . $cipherText), '+/', '-_'), '=');
    }

    /** Decrypts and authenticates a stored secret; plaintext is never accepted silently. */
    public static function decrypt(string $stored, string $context): string
    {
        if ($stored === '') return '';
        if (!self::isEncrypted($stored)) throw new RuntimeException('Unverschlüsseltes Anwendungsgeheimnis erkannt; die Datenbankmigration ist unvollständig.');
        $encoded = substr($stored, strlen(self::PREFIX));
        $padding = (4 - strlen($encoded) % 4) % 4;
        $payload = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
        if ($payload === false || strlen($payload) < 29) throw new RuntimeException('Ein gespeichertes Anwendungsgeheimnis ist beschädigt.');
        $plainText = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16), $context);
        if ($plainText === false) throw new RuntimeException('Ein gespeichertes Anwendungsgeheimnis konnte nicht entschlüsselt werden. Prüfe SWITCHLY_SECRET_KEY.');
        return $plainText;
    }

    /** Returns whether the value uses Switchly's authenticated envelope format. */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /** Decrypts a switch credential immediately before a device connection. */
    public static function decryptSwitch(array $switch): array
    {
        $switch['password'] = self::decrypt((string)($switch['password'] ?? ''), 'switches:' . (string)$switch['id'] . ':password');
        return $switch;
    }

    /** Decrypts server-only alert and mail credentials. */
    public static function decryptAlertSettings(array $settings): array
    {
        foreach (['webhook_secret', 'smtp_password', 'gmail_client_secret', 'gmail_refresh_token'] as $field) {
            $settings[$field] = self::decrypt((string)($settings[$field] ?? ''), 'alerts:1:' . $field);
        }
        return $settings;
    }

    /** Decrypts a user's TOTP seed when authentication needs it. */
    public static function decryptUser(array $user): array
    {
        $user['two_factor_secret'] = self::decrypt((string)($user['two_factor_secret'] ?? ''), 'users:' . (string)$user['id'] . ':two_factor_secret');
        return $user;
    }

    /** Decodes the configured base64 or hexadecimal 256-bit master key. */
    private static function key(): string
    {
        $configured = trim((string)getenv('SWITCHLY_SECRET_KEY'));
        $key = preg_match('/^[a-f0-9]{64}$/i', $configured) ? hex2bin($configured) : base64_decode($configured, true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('SWITCHLY_SECRET_KEY muss ein Base64- oder Hex-kodierter 32-Byte-Schlüssel sein. Erzeuge ihn mit: openssl rand -base64 32');
        }
        return $key;
    }
}
