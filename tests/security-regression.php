<?php

declare(strict_types=1);

/**
 * Executable regression checks for encryption, target policy and login limits.
 * The test uses a unique temporary SQLite database and removes it afterwards.
 */

$databasePath = sys_get_temp_dir() . '/switchly-security-' . bin2hex(random_bytes(8)) . '.sqlite';
putenv('SWITCHLY_DB_PATH=' . $databasePath);
putenv('SWITCHLY_SECRET_KEY=' . base64_encode(random_bytes(32)));
putenv('SWITCHLY_ADMIN_PASSWORD=security-test-password');
putenv('SWITCHLY_ALLOW_PRIVATE_INTEGRATIONS=0');
$_SERVER['REMOTE_ADDR'] = '198.51.100.25';

require_once __DIR__ . '/../src/SecretStore.php';
require_once __DIR__ . '/../src/NetworkPolicy.php';
require_once __DIR__ . '/../src/LoginRateLimiter.php';

/** Fails the test with a concise diagnostic. */
function securityAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

try {
    $encrypted = SecretStore::encrypt('sensitive-value', 'test:field');
    securityAssert($encrypted !== 'sensitive-value', 'Secret remained plaintext.');
    securityAssert(SecretStore::decrypt($encrypted, 'test:field') === 'sensitive-value', 'Secret round-trip failed.');
    try {
        SecretStore::decrypt($encrypted, 'test:other-field');
        throw new RuntimeException('Ciphertext was accepted in the wrong context.');
    } catch (RuntimeException $expected) {
        securityAssert(str_contains($expected->getMessage(), 'entschlüsselt'), 'Unexpected authenticated-decryption error.');
    }

    NetworkPolicy::requireSwitchHost('192.168.10.10');
    try {
        NetworkPolicy::requireIntegrationUrl('http://127.0.0.1/hook', true, 'Test-Webhook');
        throw new RuntimeException('Loopback integration target was accepted.');
    } catch (RuntimeException $expected) {
        securityAssert(str_contains($expected->getMessage(), 'nicht erlaubte'), 'Unexpected network-policy error.');
    }

    securityAssert(LoginRateLimiter::retryAfter('password', 'admin') === 0, 'Fresh login bucket is blocked.');
    for ($attempt = 0; $attempt < 5; $attempt++) LoginRateLimiter::recordFailure('password', 'admin');
    securityAssert(LoginRateLimiter::retryAfter('password', 'admin') > 0, 'Password limit did not activate.');
    LoginRateLimiter::clear('password', 'admin');
    securityAssert(LoginRateLimiter::retryAfter('password', 'admin') === 0, 'Successful login did not clear its buckets.');

    fwrite(STDOUT, "Security regression checks passed.\n");
} finally {
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $file) {
        if (is_file($file)) unlink($file);
    }
}
