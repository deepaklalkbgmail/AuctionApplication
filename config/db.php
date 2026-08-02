<?php

declare(strict_types=1);

/**
 * =====================================================================
 *  Secure PDO connection layer
 * =====================================================================
 *
 *  Usage
 *      require_once __DIR__ . '/config.php';       // loads .env + this file
 *      $pdo = Database::pdo();
 *
 *      $players = Database::all(
 *          'SELECT id, full_name FROM players WHERE tournament_id = ? AND status = ?',
 *          [$tournamentId, 'available']
 *      );
 *
 *  Security decisions worth knowing about
 *  --------------------------------------
 *  1. ATTR_EMULATE_PREPARES => false
 *     Statements are prepared on the MySQL server, so parameters travel out
 *     of band and are never string-interpolated into SQL. This is what makes
 *     injection structurally impossible rather than "escaped correctly".
 *
 *  2. ERRMODE_EXCEPTION
 *     A failed query throws instead of returning false and continuing with
 *     bad state. The handler below converts driver exceptions into a generic
 *     message in production so DSNs, table names and credentials never reach
 *     the browser — the detail goes to the log instead.
 *
 *  3. FETCH_ASSOC
 *     No duplicated numeric keys, so var_dump()-style leaks stay small and
 *     json_encode() of a row is clean.
 *
 *  4. utf8mb4 in the DSN
 *     Set at connect time, not via a later SET NAMES — a mismatched client
 *     charset has historically been an injection vector.
 *
 *  5. Nothing here is echoed. This file must live OUTSIDE the web root.
 */

require_once __DIR__ . '/../app/Core/Env.php';

use App\Core\Env;

final class Database
{
    private static ?PDO $instance = null;

    /** Non-instantiable: the connection is process-wide. */
    private function __construct()
    {
    }

    /**
     * Lazily open (and then reuse) a single connection per request.
     */
    public static function pdo(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        Env::load(dirname(__DIR__) . '/.env');

        $host    = Env::get('DB_HOST', '127.0.0.1');
        $port    = Env::get('DB_PORT', '3306');
        $name    = Env::get('DB_NAME', 'cric_auction');
        $charset = Env::get('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $options = [
            // Throw on error — never fail silently and carry on.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Associative rows only.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real server-side prepares: the anti-SQL-injection guarantee.
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Keep INT/DECIMAL columns from arriving as unexpected types.
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            // Persistent connections interact badly with transactions +
            // GET_LOCK during the auction; keep them off.
            PDO::ATTR_PERSISTENT         => false,
            // Strict SQL mode: silent truncation of a bid amount is a bug,
            // not a warning.
            PDO::MYSQL_ATTR_INIT_COMMAND =>
                "SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION', time_zone='+00:00'",
        ];

        try {
            self::$instance = new PDO($dsn, (string) Env::get('DB_USER', ''), (string) Env::get('DB_PASS', ''), $options);
        } catch (PDOException $e) {
            // The exception message contains the DSN and sometimes the user —
            // log it, show the user nothing useful to an attacker.
            error_log('[DB] connection failed: ' . $e->getMessage());

            if (Env::get('APP_ENV', 'local') === 'production') {
                http_response_code(503);
                exit('Service temporarily unavailable.');
            }

            throw new RuntimeException(
                'Database connection failed. Check .env and that MySQL is running. (' . $e->getMessage() . ')',
                (int) $e->getCode(),
                $e
            );
        }

        return self::$instance;
    }

    /**
     * Prepare + execute. The ONLY way queries should be run in this app —
     * there is deliberately no method that accepts a pre-built SQL string
     * with values already inside it.
     *
     * @param array<string|int,mixed> $params
     */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);

        foreach ($params as $key => $value) {
            // Bind with an explicit type so ints stay ints under
            // emulate-prepares=false (LIMIT ? in particular).
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };

            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }

        $stmt->execute();

        return $stmt;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /** Single scalar value from the first column of the first row. */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** Rows affected by an INSERT / UPDATE / DELETE. */
    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Run a closure inside a transaction, rolling back on any exception.
     *
     * Every auction write goes through this: placing a bid and closing a lot
     * must read the lot with SELECT … FOR UPDATE and update the team purse in
     * the same atomic unit, or two owners bidding at the same millisecond can
     * both "win".
     *
     * @template T
     * @param callable(PDO):T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $result = $work($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /** True when the database is reachable — used to pick demo vs live data. */
    public static function isAvailable(): bool
    {
        try {
            self::pdo()->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
