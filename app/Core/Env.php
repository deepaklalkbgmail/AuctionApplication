<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env reader — no Composer dependency.
 *
 * Values are kept in a private static array rather than being pushed into
 * $_ENV / getenv(), so a stray phpinfo() or var_dump($_ENV) can't leak the
 * database password.
 */
final class Env
{
    /** @var array<string,string>|null */
    private static ?array $vars = null;

    public static function load(string $path): void
    {
        if (self::$vars !== null) {
            return;                       // already loaded
        }

        self::$vars = [];

        if (!is_readable($path)) {
            return;                       // no .env: fall back to defaults
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip an inline comment, but only outside quotes.
            if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
                $value = trim(preg_split('/\s+#/', $value, 2)[0]);
            }

            // Strip surrounding quotes.
            if (strlen($value) > 1
                && ($value[0] === '"' || $value[0] === "'")
                && $value[-1] === $value[0]
            ) {
                $value = substr($value, 1, -1);
            }

            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$vars[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === null
            ? $default
            : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }
}
