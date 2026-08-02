<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Output escaping, CSRF tokens and hardened session bootstrap.
 *
 * Rule for the whole codebase: nothing that came from the database, the
 * request, or a user ever reaches a template without passing through e().
 */
final class Security
{
    private const CSRF_KEY = '_csrf_token';

    /**
     * Start a session with cookie flags that block the common theft vectors.
     * Called once from config.php before any output.
     */
    public static function startSession(bool $isProduction): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Reject a session id the client invented (session fixation).
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'lifetime' => 0,               // dies with the browser session
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isProduction,   // HTTPS-only in production
            'httponly' => true,            // invisible to document.cookie
            'samesite' => 'Lax',           // blocks cross-site POST replay
        ]);

        session_name('CRICAUCTION_SID');
        session_start();
    }

    /**
     * Escape for HTML text and attribute context.
     * ENT_QUOTES covers both quote styles; ENT_SUBSTITUTE stops invalid UTF-8
     * from silently blanking the whole string.
     */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Safe embedding of PHP data inside a <script> block. */
    public static function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        ) ?: '{}';
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_KEY];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::e(self::csrfToken()) . '">';
    }

    /** Timing-safe check. Every state-changing POST must call this first. */
    public static function verifyCsrf(?string $token): bool
    {
        $expected = $_SESSION[self::CSRF_KEY] ?? '';

        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /** Baseline response headers; sent from the front controller. */
    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }

    /** Money helper: 4250000 -> "₹42.50 L" / "₹1.25 Cr". */
    public static function money(float $amount): string
    {
        if ($amount >= 10000000) {
            return '₹' . rtrim(rtrim(number_format($amount / 10000000, 2), '0'), '.') . ' Cr';
        }

        if ($amount >= 100000) {
            return '₹' . rtrim(rtrim(number_format($amount / 100000, 2), '0'), '.') . ' L';
        }

        return '₹' . number_format($amount);
    }
}
