<?php

declare(strict_types=1);

/**
 * Application bootstrap — the single entry point every request goes through.
 * Loads .env, registers the autoloader, configures error handling, and
 * starts a hardened session.
 */

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Core/Env.php';

use App\Core\Env;
use App\Core\Security;

Env::load(BASE_PATH . '/.env');

// --------------------------------------------------------------- autoloader
// PSR-4: App\Models\Player  ->  app/Models/Player.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file     = BASE_PATH . '/app/' . $relative . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

require_once BASE_PATH . '/config/db.php';

// ------------------------------------------------------------------ constants
define('APP_NAME',    Env::get('APP_NAME', 'CricAuction'));
define('APP_ENV',     Env::get('APP_ENV', 'local'));
define('APP_DEBUG',   Env::bool('APP_DEBUG', APP_ENV !== 'production'));
define('APP_URL',     rtrim((string) Env::get('APP_URL', ''), '/'));
define('IS_PRODUCTION', APP_ENV === 'production');

date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'UTC'));

// -------------------------------------------------------------- error output
// Stack traces name file paths and sometimes credentials — they belong in the
// log, never in the response body.
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

if (IS_PRODUCTION) {
    set_exception_handler(static function (Throwable $e): void {
        error_log('[UNCAUGHT] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        exit('Something went wrong. The incident has been logged.');
    });
}

// ----------------------------------------------------------------- session
Security::startSession(IS_PRODUCTION);
Security::sendSecurityHeaders();

// ------------------------------------------------------- template shorthands
if (!function_exists('e')) {
    /** Escape for HTML output. Use on every echoed value, no exceptions. */
    function e(?string $value): string
    {
        return Security::e($value);
    }
}

if (!function_exists('money')) {
    /** 4250000 -> "₹42.5 L" */
    function money(float|int|string|null $amount): string
    {
        return Security::money((float) $amount);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Security::csrfField();
    }
}
