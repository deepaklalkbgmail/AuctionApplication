<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Session-backed authentication and role gating.
 *
 * Roles: admin | team_owner | scorer | viewer
 */
final class Auth
{
    public const ROLE_ADMIN  = 'admin';
    public const ROLE_OWNER  = 'team_owner';
    public const ROLE_SCORER = 'scorer';
    public const ROLE_VIEWER = 'viewer';

    public static function attempt(PDO $pdo, string $email, string $password): bool
    {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, password_hash, role, team_id, avatar_url
               FROM users
              WHERE email = :email AND is_active = 1
              LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        $user = is_array($user) ? $user : null;

        // password_verify is constant-time; hashing against a dummy when the
        // email is unknown keeps "no such user" and "wrong password"
        // indistinguishable by response time.
        $hash = $user['password_hash']
            ?? '$2y$12$usqCTHtBIzeb0dLB2Tj4LOoUKPUyM4kU2eMPFyhSDdBBzXbPRs9Xu';

        if (!password_verify($password, $hash) || $user === null) {
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                ->execute([':h' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), ':id' => $user['id']]);
        }

        session_regenerate_id(true);       // new id on privilege change

        $_SESSION['user'] = [
            'id'         => (int) $user['id'],
            'name'       => $user['name'],
            'email'      => $user['email'],
            'role'       => $user['role'],
            'team_id'    => $user['team_id'] !== null ? (int) $user['team_id'] : null,
            'avatar_url' => $user['avatar_url'],
        ];

        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute([':id' => $user['id']]);

        return true;
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

    /** @return array{id:int,name:string,email:string,role:string,team_id:?int,avatar_url:?string}|null */
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

    public static function teamId(): ?int
    {
        return self::user()['team_id'] ?? null;
    }

    public static function is(string ...$roles): bool
    {
        return in_array(self::role(), $roles, true);
    }

    /** Hard gate for controller actions. Halts the request on failure. */
    public static function require(string ...$roles): void
    {
        if (!self::check()) {
            // Built from APP_URL rather than hard-coded to "/login.php", so
            // the app still works when it is served from a subdirectory
            // (example.com/cricauction/) as it often is on shared hosting.
            $base = defined('APP_URL') && APP_URL !== '' ? APP_URL : '';

            header('Location: ' . $base . '/login.php');
            exit;
        }

        if ($roles !== [] && !self::is(...$roles)) {
            http_response_code(403);
            exit('403 — You do not have permission to perform this action.');
        }
    }
}
