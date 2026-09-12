<?php

declare(strict_types=1);

/**
 * Account settings for password changes and TOTP enrollment.
 * TOTP secrets are returned only during enrollment and must never be logged.
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/TOTP.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/AppInfo.php';

Auth::requireLogin();

$username = (string)$_SESSION['username'];
$db = Database::getConnection();
$stmt = $db->prepare('SELECT * FROM users WHERE username = :username');
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();

// Beendet veraltete Sitzungen, deren Benutzerkonto nicht mehr existiert.
if (!$user) {
    Auth::logout();
    header('Location: login.php');
    exit;
}

$message = '';
$error = '';

// Ändert das Passwort nur nach CSRF-Prüfung und Bestätigung des bisherigen Passworts.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    if (!Auth::validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Sicherheits-Token ist ungültig. Bitte Seite neu laden.';
    } else {
        try {
            $currentPassword = (string)($_POST['old_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');

            if (!password_verify($currentPassword, $user['password_hash'])) {
                throw new RuntimeException('Das aktuelle Passwort ist falsch.');
            }
            if (strlen($newPassword) < 8) {
                throw new RuntimeException('Das neue Passwort muss mindestens 8 Zeichen lang sein.');
            }

            $db->prepare('UPDATE users SET password_hash = :password WHERE id = :id')->execute([
                'password' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $user['id'],
            ]);
            Logger::info('PASSWORD_CHANGED', 'Passwort geändert.');
            $message = 'Passwort wurde geändert.';
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#0d1020">
  <meta name="application-name" content="<?= AppInfo::NAME ?>">
  <title>Profil &amp; 2FA – <?= AppInfo::NAME ?> · <?= AppInfo::DISPLAY_VERSION ?></title>
  <link rel="icon" href="assets/favicon.ico?v=1.4.7" sizes="any">
  <link rel="icon" type="image/svg+xml" href="assets/switchly.svg?v=1.4.7">
  <link rel="apple-touch-icon" href="assets/apple-touch-icon.png?v=1.4.7">
  <link rel="manifest" href="assets/site.webmanifest?v=1.4.7">
  <link rel="stylesheet" href="assets/style.css?v=1.4.7">
</head>
<body
  class="profile-page"
  data-app-version="<?= AppInfo::DISPLAY_VERSION ?>"
  data-csrf="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES) ?>"
>
  <div class="app-layout">
    <!-- Kompakte Navigation zurück zur Anwendung und zur Abmeldung. -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <div class="logo-box">
          <img class="brand-mark" src="assets/switchly-mark.svg?v=1.4.7" alt="Switchly" width="44" height="44">
        </div>
        <div class="sidebar-title">
          <h2><?= AppInfo::NAME ?></h2>
          <span>Network Control Center</span>
          <small class="brand-version"><?= AppInfo::DISPLAY_VERSION ?></small>
        </div>
      </div>

      <nav class="main-nav">
        <div class="nav-label">KONTO</div>
        <a href="index.php" class="nav-item">← Dashboard</a>
      </nav>

      <div class="sidebar-footer">
        <div class="logged-in">Angemeldet als: <strong><?= htmlspecialchars($username) ?></strong></div>
        <a href="profile.php" class="profile-btn active-profile">⚙ Profil &amp; 2FA</a>
        <a href="logout.php" class="btn-logout">Abmelden</a>
      </div>
    </aside>

    <!-- Kontodaten, Passwortänderung und Zwei-Faktor-Einrichtung. -->
    <main class="main-content">
      <header class="top-bar">
        <div>
          <span class="subhead">KONTO</span>
          <h1>Profil &amp; 2FA</h1>
        </div>
      </header>

      <?php if ($message): ?>
        <div class="notice success"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="notice error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <!-- Trennt allgemeine Kontoeinstellungen von den 2FA-Sicherheitseinstellungen. -->
      <section class="profile-grid">
        <section class="card">
          <div class="card-title">
            <h3>PASSWORT</h3>
            <span>Ändere das Kennwort für dein Konto.</span>
          </div>
          <form method="post" class="profile-form">
            <input type="hidden" name="action" value="password">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES) ?>">
            <div class="form-group">
              <label>Benutzername</label>
              <input value="<?= htmlspecialchars($username) ?>" disabled>
            </div>
            <div class="form-group">
              <label>Aktuelles Passwort</label>
              <input type="password" name="old_password" autocomplete="current-password" required>
            </div>
            <div class="form-group">
              <label>Neues Passwort</label>
              <input type="password" name="new_password" minlength="8" autocomplete="new-password" required>
            </div>
            <button class="btn btn-primary">Passwort ändern</button>
          </form>
        </section>

        <section class="card" id="twoFactorCard">
          <div class="card-title">
            <h3>ZWEI-FAKTOR-AUTHENTIFIZIERUNG</h3>
            <span>Kompatibel mit Google Authenticator, Microsoft Authenticator, Aegis und anderen TOTP-Apps.</span>
          </div>
          <div id="twoFactorStatus" class="twofa-status"></div>
          <div id="twoFactorSetup" class="hidden">
            <div class="twofa-setup-grid">
              <div><div id="twoFactorQr" class="qr-box"></div></div>
              <div>
                <p class="muted">1. Scanne den QR-Code mit deiner Authenticator-App.</p>
                <p class="muted">2. Alternativ kannst du den Schlüssel manuell eingeben:</p>
                <code id="twoFactorSecret" class="secret-code"></code>
                <details class="uri-details">
                  <summary>otpauth-URI anzeigen</summary>
                  <textarea id="twoFactorUri" readonly></textarea>
                </details>
                <div class="form-group">
                  <label>6-stelliger Bestätigungscode</label>
                  <input id="twoFactorCode" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code">
                </div>
                <button id="confirm2faBtn" class="btn btn-primary">2FA aktivieren</button>
                <button id="cancel2faBtn" class="btn">Abbrechen</button>
              </div>
            </div>
          </div>
          <div id="twoFactorActions"></div>
        </section>
      </section>
    </main>
  </div>

  <script src="assets/vendor/qrcodejs/qrcode.min.js?v=1.0.0"></script>
  <script src="assets/profile.js?v=1.4.7"></script>
</body>
</html>
