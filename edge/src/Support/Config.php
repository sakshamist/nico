<?php

declare(strict_types=1);

namespace Nico\Edge\Support;

/**
 * Environment-backed configuration (FR-1).
 *
 * Values come from the environment only. Nothing secret is ever read from a
 * tracked file, and no getter logs its own value.
 */
final class Config
{
    private static ?array $values = null;

    public static function boot(string $envFile): void
    {
        self::$values = self::loadEnvFile($envFile) ?: [];
    }

    /**
     * Reads a key, falling back to the process environment. Required keys throw
     * so a misconfigured deploy fails fast rather than half-working.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $fromFile = self::$values[$key] ?? null;
        if ($fromFile !== null) {
            return $fromFile;
        }

        $fromEnv = getenv($key);
        if ($fromEnv !== false) {
            return $fromEnv;
        }

        if ($default === null) {
            throw new \RuntimeException("Missing required config key: {$key}");
        }

        return $default;
    }

    public static function require(string $key): mixed
    {
        return self::get($key);
    }

    public static function int(string $key, ?int $default = null): int
    {
        return (int) self::get($key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return in_array(is_string($value) ? strtolower($value) : $value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    /**
     * Parses a dotenv-style file. Real environment variables already present
     * win, so the file is a convenience for local dev, not an override.
     */
    private static function loadEnvFile(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $values = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $values[$key] = $value;
        }

        return $values;
    }
}
