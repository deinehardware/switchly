<?php

declare(strict_types=1);

/** Consumes a one-time email-verification token and renders the result. */

require_once __DIR__ . '/../src/EmailService.php';
require_once __DIR__ . '/../src/AppInfo.php';

// Der einmalige Token wird serverseitig verbraucht und nie in die Ausgabe übernommen.
$verified = EmailService::verify(trim((string)($_GET['token'] ?? '')));
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#0d1020">
  <meta name="application-name" content="<?= AppInfo::NAME ?>">
  <title>E-Mail bestätigen – <?= AppInfo::NAME ?> · <?= AppInfo::DISPLAY_VERSION ?></title>
  <link rel="icon" href="assets/favicon.ico?v=1.4.7" sizes="any">
  <link rel="icon" type="image/svg+xml" href="assets/switchly.svg?v=1.4.7">
  <link rel="apple-touch-icon" href="assets/apple-touch-icon.png?v=1.4.7">
  <link rel="manifest" href="assets/site.webmanifest?v=1.4.7">
  <link rel="stylesheet" href="assets/style.css?v=1.4.7">
</head>
<body class="auth-page">
  <div class="auth-card">
    <div class="auth-brand">
      <div class="logo-box">
        <img class="brand-mark" src="assets/switchly-mark.svg?v=1.4.7" alt="Switchly" width="44" height="44">
      </div>
      <div>
        <h1><?= AppInfo::NAME ?></h1>
        <span>E-Mail-Verifikation</span>
        <small class="brand-version"><?= AppInfo::DISPLAY_VERSION ?></small>
      </div>
    </div>

    <div class="notice <?= $verified ? 'success' : 'error' ?>">
      <?= $verified
          ? 'Die E-Mail-Adresse wurde bestätigt. Du kannst dich jetzt anmelden.'
          : 'Der Link ist ungültig oder abgelaufen.' ?>
    </div>
    <a class="btn btn-primary btn-wide" href="login.php">Zur Anmeldung</a>
  </div>
</body>
</html>
