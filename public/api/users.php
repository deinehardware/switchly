<?php

declare(strict_types=1);

/**
 * GET/POST local-user administration endpoint.
 * Requires users.manage and supports account updates, deletion, TOTP reset and
 * verification-email delivery.
 */

require_once __DIR__ . '/../../src/Api.php';
require_once __DIR__ . '/../../src/Logger.php';
require_once __DIR__ . '/../../src/EmailService.php';

Api::bootstrap();
$me = Api::requirePermission('users.manage');

// Verarbeitet validierte Eingaben und wandelt das Ergebnis in eine definierte HTTP-Antwort um.
try {
    $db = Database::getConnection();
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');

    // Aktion „list“: liefert die angeforderten Datensätze.
    if ($action === 'list') {
        $users = $db->query('SELECT id, username, email, email_verified_at, role, two_factor_enabled, created_at FROM users ORDER BY username')->fetchAll();
        Api::respond(['users' => $users]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') Api::respond(['error' => 'Nur POST ist erlaubt.'], 405);

    // Aktion „save“: validiert und speichert die übermittelten Werte.
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? 'user');
        $email = strtolower(trim((string)($_POST['email'] ?? '')));

        if ($username === '') throw new RuntimeException('Benutzername ist erforderlich.');
        if (!preg_match('/^[a-zA-Z0-9._-]{2,64}$/', $username)) throw new RuntimeException('Der Benutzername enthält ungültige Zeichen.');
        if (!in_array($role, ['admin', 'user'], true)) throw new RuntimeException('Ungültige Rolle.');
        if (($me['role'] ?? 'user') !== 'admin' && $role === 'admin') throw new RuntimeException('Nur Administratoren dürfen Administrator-Konten verwalten.');
        if ($password !== '' && strlen($password) < 8) throw new RuntimeException('Das Passwort muss mindestens 8 Zeichen lang sein.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Die E-Mail-Adresse ist ungültig.');
        $emailValue = $email !== '' ? $email : null;
        $sendVerification = false;

        if ($id > 0) {
            $stmt = $db->prepare('SELECT username, email, role FROM users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $target = $stmt->fetch();
            if (!$target) throw new RuntimeException('Benutzer nicht gefunden.');
            if (($me['role'] ?? 'user') !== 'admin' && ($target['role'] ?? 'user') === 'admin') throw new RuntimeException('Administratorkonten dürfen nicht bearbeitet werden.');
            if ($target['username'] === 'admin' && $role !== 'admin') throw new RuntimeException('Der Hauptadministrator kann nicht herabgestuft werden.');

            if ($password !== '') {
                $stmt = $db->prepare('UPDATE users SET username = :username, email = :email, password_hash = :password, role = :role, email_verified_at = CASE WHEN COALESCE(email, \'\') = COALESCE(:email_compare, \'\') THEN email_verified_at ELSE NULL END WHERE id = :id');
                $stmt->execute(['username' => $username, 'email' => $emailValue, 'email_compare' => $emailValue, 'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role, 'id' => $id]);
            } else {
                $stmt = $db->prepare('UPDATE users SET username = :username, email = :email, role = :role, email_verified_at = CASE WHEN COALESCE(email, \'\') = COALESCE(:email_compare, \'\') THEN email_verified_at ELSE NULL END WHERE id = :id');
                $stmt->execute(['username' => $username, 'email' => $emailValue, 'email_compare' => $emailValue, 'role' => $role, 'id' => $id]);
            }
            $sendVerification = $emailValue !== null && (string)($target['email'] ?? '') !== $emailValue;
            Logger::info('USER_UPDATED', 'Benutzer ' . $username . ' wurde aktualisiert.');
        } else {
            if (strlen($password) < 8) throw new RuntimeException('Für einen neuen Benutzer ist ein Passwort mit mindestens 8 Zeichen erforderlich.');
            $stmt = $db->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (:username, :email, :password, :role)');
            $stmt->execute(['username' => $username, 'email' => $emailValue, 'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role]);
            $id = (int)$db->lastInsertId();
            $sendVerification = $emailValue !== null;
            Logger::info('USER_CREATED', 'Benutzer ' . $username . ' wurde angelegt.');
        }

        $emailSent = null;
        if ($sendVerification) {
            $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $saved = $stmt->fetch();
            if ($saved) $emailSent = EmailService::sendVerification($saved);
        }
        Api::respond(['success' => true, 'email_sent' => $emailSent]);
    }

    // Aktion „delete“: löscht den ausgewählten Datensatz.
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT username, role FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $target = $stmt->fetch();
        if (!$target) throw new RuntimeException('Benutzer nicht gefunden.');
        if (($me['role'] ?? 'user') !== 'admin' && ($target['role'] ?? 'user') === 'admin') throw new RuntimeException('Administratorkonten dürfen nicht gelöscht werden.');
        if ($target['username'] === $me['username'] || $target['username'] === 'admin') throw new RuntimeException('Dieser Benutzer kann nicht gelöscht werden.');

        $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
        Logger::warning('USER_DELETED', 'Benutzer ' . $target['username'] . ' wurde gelöscht.');
        Api::respond(['success' => true]);
    }

    // Aktion „reset2fa“: setzt die Zwei-Faktor-Konfiguration eines Benutzers zurück.
    if ($action === 'reset2fa') {
        if (($me['role'] ?? 'user') !== 'admin') Api::respond(['error' => 'Nur Administratoren dürfen 2FA zurücksetzen.'], 403);
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT username FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $target = $stmt->fetch();
        if (!$target) throw new RuntimeException('Benutzer nicht gefunden.');

        $db->prepare('UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = :id')->execute(['id' => $id]);
        Logger::warning('2FA_RESET', '2FA für Benutzer ' . $target['username'] . ' wurde durch einen Administrator zurückgesetzt.');
        Api::respond(['success' => true]);
    }

    // Aktion „resend_verification“: versendet die E-Mail-Verifikation erneut.
    if ($action === 'resend_verification') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $target = $stmt->fetch();
        if (!$target || empty($target['email'])) throw new RuntimeException('Benutzer oder E-Mail-Adresse nicht gefunden.');
        if (($me['role'] ?? 'user') !== 'admin' && ($target['role'] ?? 'user') === 'admin') throw new RuntimeException('Administratorkonten dürfen nicht bearbeitet werden.');
        if (!empty($target['email_verified_at'])) throw new RuntimeException('Die E-Mail-Adresse ist bereits bestätigt.');
        $sent = EmailService::sendVerification($target);
        if (!$sent) throw new RuntimeException('Die E-Mail konnte nicht versendet werden. Prüfe die SMTP-Konfiguration.');
        Api::respond(['success' => true]);
    }

    Api::respond(['error' => 'Ungültige Aktion.'], 400);
} catch (PDOException $e) {
    if (str_contains(strtolower($e->getMessage()), 'unique')) Api::respond(['error' => 'Benutzername oder E-Mail-Adresse ist bereits vergeben.'], 409);
    Api::error($e, 400);
} catch (Throwable $e) {
    Api::error($e, 400);
}
