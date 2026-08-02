<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

use App\Core\Auth;
use App\Core\Security;

// POST-only: a GET logout can be triggered by any <img> tag on another site.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
    Auth::logout();
}

header('Location: login.php');
exit;
