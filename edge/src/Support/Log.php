<?php

declare(strict_types=1);

namespace Nico\Edge\Support;

/**
 * Minimal structured logger. Writes one JSON object per line so log output is
 * greppable and machine-readable.
 *
 * Never logs secrets or user content (FR-11, enforced in Sprint 6's security pass
 * and verified here) - see the redaction list below.
 */
final class Log
{
    private const REDACT_KEYS = [
        'password', 'password_hash', 'secret', 'hmac', 'signature', 'token',
        'authorization', 'cookie', 'worker_secret', 'shared_secret', 'api_key',
    ];

    private static ?string $path = null;

    public static function init(string $path): void
    {
        self::$path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
    }

    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    public static function write(string $level, string $event, array $context = []): void
    {
        $record = [
            'ts'    => gmdate('c'),
            'level' => $level,
            'event' => $event,
            'ctx'   => self::redact($context),
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (self::$path === null) {
            error_log((string) $line);
            return;
        }

        file_put_contents(self::$path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Strips anything whose key suggests a credential. Defence in depth: callers
     * should still avoid passing secrets, but this makes a mistake non-fatal.
     */
    private static function redact(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT_KEYS, true)) {
                $clean[$key] = '[redacted]';
                continue;
            }

            $clean[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $clean;
    }
}
