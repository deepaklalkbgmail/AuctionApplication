<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Session-backed authentication and role gating.
 *
 * Roles: admin | team_owner | scorer | viewer | player
 *
 * Signing in takes either a username or an email address, because a player
 * chooses a username at registration but a scorer is handed one by an
 * administrator, and neither should have to remember which they were given.
 */
final class Auth
{
    public const ROLE_ADMIN  = 'admin';
    public const ROLE_OWNER  = 'team_owner';
    public const ROLE_SCORER = 'scorer';
    public const ROLE_VIEWER = 'viewer';
    public const ROLE_PLAYER = 'player';

    /**
     * Why the last attempt failed: 'credentials' | 'pending' | 'rejected' |
     * 'suspended'. Only 'credentials' is generic on purpose — the other
     * three describe an account the person has already proved they own, so
     * naming the reason tells them nothing they did not just demonstrate,
     * and saves an "it says no and I don't know why" support call.
     */
    private static string $failure = 'credentials';

    public static function attempt(PDO $pdo, string $identifier, string $password): bool
    {
        self::$failure = 'credentials';

        $identifier = trim($identifier);

        $stmt = $pdo->prepare(
            'SELECT id, username, name, email, password_hash, role, status,
                    must_change_password, team_id, avatar_url, photo_path
               FROM users
              WHERE (username = :username OR email = :email) AND is_active = 1
              LIMIT 1'
        );
        // Two placeholders for one value: native prepared statements (we run
        // with emulation off) bind by position, so a repeated name is an
        // error rather than a convenience.
        $stmt->execute([':username' => $identifier, ':email' => $identifier]);
        $user = $stmt->fetch();
        $user = is_array($user) ? $user : null;

        // password_verify is constant-time; hashing against a dummy when the
        // account is unknown keeps "no such user" and "wrong password"
        // indistinguishable by response time.
        $hash = $user['password_hash']
            ?? '$2y$12$usqCTHtBIzeb0dLB2Tj4LOoUKPUyM4kU2eMPFyhSDdBBzXbPRs9Xu';

        if (!password_verify($password, $hash) || $user === null) {
            return false;
        }

        // The password was right. Now: is this account allowed in at all?
        // Checking after the password means the status of an account is
        // never disclosed to someone who cannot open it.
        if (($user['status'] ?? 'approved') !== 'approved') {
            self::$failure = (string) $user['status'];

            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                ->execute([':h' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), ':id' => $user['id']]);
        }

        // New session id on privilege change, so a fixated id cannot be
        // ridden into an authenticated session. Skipped when there is no
        // live session to rotate — the test scripts run from the CLI.
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }

        $_SESSION['user'] = [
            'id'         => (int) $user['id'],
            'username'   => $user['username'],
            'name'       => $user['name'],
            'email'      => $user['email'],
            'role'       => $user['role'],
            'team_id'    => $user['team_id'] !== null ? (int) $user['team_id'] : null,
            'avatar_url' => $user['avatar_url'],
            'photo_path' => $user['photo_path'],
            // A password an administrator issued is a temporary one. Every
            // gated screen sends the person to password.php until they have
            // replaced it, so an issued password never becomes permanent.
            'must_change_password' => (int) ($user['must_change_password'] ?? 0) === 1,
        ];

        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute([':id' => $user['id']]);

        return true;
    }

    /** Why the last attempt failed. See $failure. */
    public static function failureReason(): string
    {
        return self::$failure;
    }

    /** A message safe to show for the last failure. */
    public static function failureMessage(): string
    {
        return match (self::$failure) {
            'pending'   => 'Your registration is still waiting for an administrator to approve it.',
            'rejected'  => 'This registration was not approved. Please contact the organisers.',
            'suspended' => 'This account has been suspended. Please contact the organisers.',
            default     => 'Those credentials do not match our records.',
        };
    }

    public static function mustChangePassword(): bool
    {
        return (bool) (self::user()['must_change_password'] ?? false);
    }

    /** Called after a successful change, so the session stops redirecting. */
    public static function clearPasswordChangeFlag(): void
    {
        if (isset($_SESSION['user'])) {
            $_SESSION['user']['must_change_password'] = false;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_destroy();
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function role(): string
    {
        return self::user()['role'] ?? self::ROLE_VIEWER;
    }

    public static function id(): ?int
    {
        $id = self::user()['id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public static function teamId(): ?int
    {
        return self::user()['team_id'] ?? null;
    }

    public static function is(string ...$roles): bool
    {
        return in_array(self::role(), $roles, true);
    }

    /**
     * Re-read the parts of the session that an administrator can change
     * underneath somebody who is already signed in: their role, their team,
     * whether the account is still approved, and whether a reset has left a
     * password that must be replaced.
     *
     * Without this, an owner assigned a team mid-session keeps being told
     * they have none until they sign out and back in, and — the part that
     * matters — a suspended account keeps working until its session
     * expires. One indexed primary-key read per gated request is a fair
     * price for both.
     *
     * @return bool false when the account may no longer be here
     */
    public static function refresh(): bool
    {
        $id = self::id();

        if ($id === null) {
            return false;
        }

        try {
            $row = \Database::one(
                'SELECT role, status, team_id, must_change_password, is_active
                   FROM users WHERE id = :id',
                [':id' => $id]
            );
        } catch (\Throwable) {
            // A database that is briefly unreachable must not sign everybody
            // out; the session stands until it comes back.
            return true;
        }

        if ($row === null || (int) $row['is_active'] !== 1 || $row['status'] !== 'approved') {
            return false;
        }

        $_SESSION['user']['role']                 = $row['role'];
        $_SESSION['user']['team_id']              = $row['team_id'] !== null ? (int) $row['team_id'] : null;
        $_SESSION['user']['must_change_password'] = (int) $row['must_change_password'] === 1;

        return true;
    }

    /** Hard gate for controller actions. Halts the request on failure. */
    public static function require(string ...$roles): void
    {
        // Built from APP_URL rather than hard-coded, so the app still works
        // when it is served from a subdirectory (example.com/APL/) as it
        // usually is on shared hosting.
        $base = defined('APP_URL') && APP_URL !== '' ? APP_URL : '';

        if (!self::check()) {
            header('Location: ' . $base . '/login.php');
            exit;
        }

        // An account that has been suspended, rejected or deleted since it
        // signed in stops here rather than at the end of its session.
        if (!self::refresh()) {
            self::logout();
            header('Location: ' . $base . '/login.php?ended=1');
            exit;
        }

        // An issued password gets you exactly one screen: the one that
        // replaces it.
        if (self::mustChangePassword()
            && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'password.php'
        ) {
            header('Location: ' . $base . '/password.php?forced=1');
            exit;
        }

        if ($roles !== [] && !self::is(...$roles)) {
            http_response_code(403);
            exit('403 — You do not have permission to perform this action.');
        }
    }
}
