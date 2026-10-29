<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static bool $loaded = false;
    private static array $values = [];

    public static function load(string $rootPath): void
    {
        if (self::$loaded) {
            return;
        }

        date_default_timezone_set('Africa/Accra');

        $envFile = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile) && is_readable($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                    continue;
                }
                [$key, $val] = explode('=', $trimmed, 2);
                $key = trim($key);
                $val = trim($val);
                if (
                    (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))
                ) {
                    $val = substr($val, 1, -1);
                }
                self::$values[$key] = $val;
            }
        }

        self::$loaded = true;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$values[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }
        return $_ENV[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $val = self::get($key);
        if ($val === null) {
            return $default;
        }
        if (is_bool($val)) {
            return $val;
        }
        return in_array(strtolower((string) $val), ['1', 'true', 'yes', 'on'], true);
    }
}
