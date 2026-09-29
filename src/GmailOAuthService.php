<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/** Handles Google OAuth tokens and Gmail API message delivery. */
final class GmailOAuthService
{
    public const SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    /** Returns stored Gmail OAuth configuration, including server-only secrets. */
    public static function settings(): array
    {
        return Database::getConnection()->query('SELECT gmail_oauth_enabled,gmail_client_id,gmail_client_secret,gmail_refresh_token,gmail_sender FROM alert_settings WHERE id=1')->fetch() ?: [];
    }

    /** Returns whether Gmail OAuth is selected and fully authorized. */
    public static function ready(array $settings): bool
    {
        return !empty($settings['gmail_oauth_enabled'])
            && trim((string)($settings['gmail_client_id'] ?? '')) !== ''
            && trim((string)($settings['gmail_client_secret'] ?? '')) !== ''
            && trim((string)($settings['gmail_refresh_token'] ?? '')) !== ''
            && filter_var((string)($settings['gmail_sender'] ?? ''), FILTER_VALIDATE_EMAIL);
    }

    /** Exchanges a refresh token for a short-lived Google access token. */
    public static function accessToken(array $settings): string
    {
        $response = self::postForm('https://oauth2.googleapis.com/token', [
            'client_id' => (string)$settings['gmail_client_id'],
            'client_secret' => (string)$settings['gmail_client_secret'],
            'refresh_token' => (string)$settings['gmail_refresh_token'],
            'grant_type' => 'refresh_token',
        ]);
        $token = trim((string)($response['access_token'] ?? ''));
        if ($token === '') throw new RuntimeException('Google hat kein Zugriffstoken geliefert. Bitte Gmail erneut verbinden.');
        return $token;
    }

    /** Sends one RFC 5322 message through Gmail's messages.send endpoint. */
    public static function sendRaw(string $rawMessage, array $settings): void
    {
        $token = self::accessToken($settings);
        $payload = json_encode(['raw' => rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=')], JSON_UNESCAPED_SLASHES);
        if ($payload === false) throw new RuntimeException('Gmail-Nachricht konnte nicht kodiert werden.');
        $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            $detail = is_string($body) ? trim(substr($body, 0, 500)) : $error;
            throw new RuntimeException('Gmail API antwortete mit HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '.'));
        }
    }

    /** Performs a form-encoded OAuth request and returns decoded JSON. */
    public static function postForm(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986), CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $data = is_string($body) ? json_decode($body, true) : null;
        if ($body === false || $status < 200 || $status >= 300 || !is_array($data)) {
            $detail = is_array($data) ? (string)($data['error_description'] ?? $data['error'] ?? '') : $error;
            throw new RuntimeException('Google OAuth fehlgeschlagen' . ($detail !== '' ? ': ' . $detail : ' (HTTP ' . $status . ').'));
        }
        return $data;
    }
}
