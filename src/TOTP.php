<?php
declare(strict_types=1);

require_once __DIR__ . '/AppInfo.php';

/** RFC 6238 TOTP helper using SHA-1, a 30-second step and six digits. */
final class TOTP
{
    private const PERIOD = 30;
    private const DIGITS = 6;

    /** Generates a Base32 secret from cryptographically secure random bytes. */
    public static function generateSecret(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bytes = random_bytes($length);
        $secret = '';
        $buffer = 0;
        $bits = 0;
        for ($i = 0; $i < strlen($bytes); $i++) {
            $buffer = ($buffer << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $secret .= $alphabet[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) $secret .= $alphabet[($buffer << (5 - $bits)) & 31];
        return $secret;
    }

    /** Removes separators and characters outside the RFC 4648 Base32 alphabet. */
    public static function normalizeSecret(string $secret): string
    {
        return strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
    }

    /** Calculates the six-digit code for one timestamp without changing state. */
    public static function code(string $secret, ?int $timestamp = null): string
    {
        $secret = self::normalizeSecret($secret);
        $counter = intdiv($timestamp ?? time(), self::PERIOD);
        $binary = self::base32Decode($secret);
        $msg = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $msg, $binary, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string)($value % 1000000), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Accepts the current time step and a bounded clock-skew window. */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) return false;
        $now = time();
        for ($offset = -$window; $offset <= $window; $offset++) {
            $expected = self::code($secret, $now + ($offset * self::PERIOD));
            if (hash_equals($expected, $code)) return true;
        }
        return false;
    }

    /** Builds the otpauth URI consumed by compatible authenticator applications. */
    public static function provisioningUri(string $secret, string $username, string $issuer = AppInfo::NAME): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $username)
            . '?secret=' . rawurlencode(self::normalizeSecret($secret))
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    /** Dekodiert das normalisierte Base32-Geheimnis für die HMAC-Berechnung. */
    private static function base32Decode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $output = '';
        foreach (str_split($input) as $char) {
            $value = strpos($alphabet, $char);
            if ($value === false) continue;
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xff);
            }
        }
        return $output;
    }
}
