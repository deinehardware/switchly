<?php

declare(strict_types=1);

/**
 * Local login and second-factor challenge page.
 * Authentication errors remain deliberately generic for unauthenticated users.
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/SsoService.php';
require_once __DIR__ . '/../src/AppInfo.php';
require_once __DIR__ . '/../src/Locale.php';

Auth::startSession();
$error = '';
$twoFaStep = !empty($_SESSION['pending_2fa']);
$providers = SsoService::availableProviders();
$language = Locale::defaultLanguage();
$assetVersion = rawurlencode(AppInfo::VERSION . '-' . (string)max(
    (int)@filemtime(__DIR__ . '/assets/style.css'),
    (int)@filemtime(__DIR__ . '/assets/i18n.js')
));
// Übernimmt einen einmaligen Fehler aus dem OIDC-Rücksprung und entfernt ihn aus der Sitzung.
if (!empty($_SESSION['sso_error'])) {
    $error = (string)$_SESSION['sso_error'];
    unset($_SESSION['sso_error']);
}

// Verarbeitet entweder Benutzername/Passwort oder den noch offenen zweiten Faktor.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Schließt eine bereits begonnene Anmeldung mit dem TOTP-Code ab.
    if (($_POST['action'] ?? '') === 'verify_2fa') {
        if (Auth::verifyPending2FA((string)($_POST['code'] ?? ''))) {
            header('Location: index.php');
            exit;
        }
        $twoFaStep = true;
        $error = 'Der 2FA-Code ist ungültig oder abgelaufen.';
    } else {
        // Prüft die primären Zugangsdaten und entscheidet, ob ein TOTP-Schritt folgt.
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $result = Auth::login($username, $password);
        if ($result === 'success') {
            header('Location: index.php');
            exit;
        }
        if ($result === '2fa') {
            $twoFaStep = true;
        } elseif ($result === 'email_unverified') {
            $error = 'Bitte bestätige zuerst deine E-Mail-Adresse.';
        } else {
            $error = 'Ungültiger Benutzername oder Passwort.';
        }
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars($language, ENT_QUOTES) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#0d1020">
  <meta name="application-name" content="<?=AppInfo::NAME?>">
  <title>Anmelden – <?=AppInfo::NAME?> · <?=AppInfo::DISPLAY_VERSION?></title>
  <link rel="icon" href="assets/favicon.ico?v=<?=$assetVersion?>" sizes="any">
  <link rel="icon" type="image/svg+xml" href="assets/switchly.svg?v=<?=$assetVersion?>">
  <link rel="apple-touch-icon" href="assets/apple-touch-icon.png?v=<?=$assetVersion?>">
  <link rel="manifest" href="assets/site.webmanifest?v=<?=$assetVersion?>">
  <link rel="stylesheet" href="assets/style.css?v=<?=$assetVersion?>">
</head>
<body class="auth-page" data-language="<?= htmlspecialchars($language, ENT_QUOTES) ?>">
<div class="auth-card">
  <!-- Produktkennung und mögliche neutrale Anmeldefehler. -->
  <div class="auth-brand"><div class="logo-box"><img class="brand-mark" src="assets/switchly-mark.svg?v=<?=$assetVersion?>" alt="Switchly" width="44" height="44"></div><div><h1><?=AppInfo::NAME?></h1><span>Network Control Center</span><small class="brand-version"><?=AppInfo::DISPLAY_VERSION?></small></div></div>
  <?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <!-- Je nach Sitzungszustand erscheint der zweite Faktor oder die primäre Anmeldung. -->
  <?php if ($twoFaStep): ?>
    <div class="auth-step">
      <span class="subhead">ZWEI-FAKTOR-AUTHENTIFIZIERUNG</span>
      <h2>Code eingeben</h2>
      <p>Öffne deine Authenticator-App und gib den 6-stelligen Code ein.</p>
      <form method="post">
        <input type="hidden" name="action" value="verify_2fa">
        <div class="form-group"><label>6-stelliger Code</label><input class="otp-input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" name="code" autocomplete="one-time-code" required autofocus></div>
        <button class="btn btn-primary btn-wide">Anmelden</button>
      </form>
      <a class="auth-back" href="logout.php">Abbrechen</a>
    </div>
  <?php else: ?>
    <div class="auth-step">
      <span class="subhead">ANMELDUNG</span>
      <h2>Willkommen zurück</h2>
      <form method="post">
        <div class="form-group"><label>Benutzername</label><input name="username" autocomplete="username" required autofocus></div>
        <div class="form-group"><label>Passwort</label><input type="password" name="password" autocomplete="current-password" required></div>
        <button class="btn btn-primary btn-wide">Anmelden</button>
      </form>
      <?php if ($providers): ?>
        <div class="sso-divider"><span>oder mit SSO</span></div>
        <div class="sso-buttons">
          <?php foreach ($providers as $provider): ?>
            <a class="sso-button sso-<?= htmlspecialchars($provider['id']) ?>" href="sso.php?action=start&amp;provider=<?= rawurlencode($provider['id']) ?>">
              <span class="sso-mark" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($provider['name'], 0, 1))) ?></span>
              <span>Mit <?= htmlspecialchars($provider['name']) ?> anmelden</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<script charset="UTF-8" src="assets/i18n.js?v=<?=$assetVersion?>"></script>
</body>
</html>
