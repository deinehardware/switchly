<?php

declare(strict_types=1);

/** Terminates the current session and redirects to the login page. */

require_once __DIR__ . '/../src/Auth.php';

Auth::logout();
header('Location: login.php');
exit;
