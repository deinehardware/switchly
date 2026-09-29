<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/** Stores the application default and each signed-in user's UI language. */
final class Locale
{
    public const SUPPORTED = ['de', 'en'];

    public static function normalize(mixed $language, string $fallback = 'de'): string
    {
        $value = strtolower(trim((string)$language));
        return in_array($value, self::SUPPORTED, true) ? $value : $fallback;
    }

    public static function defaultLanguage(): string
    {
        $value = Database::getConnection()->query('SELECT default_language FROM app_settings WHERE id = 1')->fetchColumn();
        return self::normalize($value);
    }

    /** @param array<string, mixed>|null $user */
    public static function current(?array $user = null): string
    {
        if ($user !== null && !empty($user['language'])) {
            return self::normalize($user['language'], self::defaultLanguage());
        }
        if (!empty($_SESSION['username'])) {
            $stmt = Database::getConnection()->prepare('SELECT language FROM users WHERE username = :username');
            $stmt->execute(['username' => (string)$_SESSION['username']]);
            $language = $stmt->fetchColumn();
            if (is_string($language) && $language !== '') return self::normalize($language);
        }
        return self::defaultLanguage();
    }

    public static function setUserLanguage(int $userId, string $language): void
    {
        $language = self::normalize($language, '');
        if ($language === '') throw new InvalidArgumentException('Nicht unterstützte Sprache.');
        $stmt = Database::getConnection()->prepare('UPDATE users SET language = :language WHERE id = :id');
        $stmt->execute(['language' => $language, 'id' => $userId]);
    }

    public static function setDefaultLanguage(string $language): void
    {
        $language = self::normalize($language, '');
        if ($language === '') throw new InvalidArgumentException('Nicht unterstützte Standardsprache.');
        $stmt = Database::getConnection()->prepare('UPDATE app_settings SET default_language = :language, updated_at = CURRENT_TIMESTAMP WHERE id = 1');
        $stmt->execute(['language' => $language]);
    }
}
