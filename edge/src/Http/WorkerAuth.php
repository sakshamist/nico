<?php

declare(strict_types=1);

namespace Nico\Edge\Http;

use Nico\Edge\Support\Config;

/**
 * HMAC authentication for the compute worker API (M0 queue contract).
 *
 * The worker is a client behind NAT: it signs every request with a shared secret
 * over a canonical string of method, path+query, timestamp and body hash. The
 * secret is never placed in a URL, a log line or an error response.
 */
final class WorkerAuth
{
    public const TIMESTAMP_HEADER = 'X-Nico-Timestamp';

    public const SIGNATURE_HEADER = 'X-Nico-Signature';

    public const WORKER_HEADER = 'X-Nico-Worker-Id';

    /**
     * Verifies the current request.
     *
     * @return array{ok:bool,error:?string,worker_id:?string} never includes the secret or expected signature
     */
    public static function check(): array
    {
        if (Config::bool('REQUIRE_HTTPS', false) && !self::isSecure()) {
            return self::failure('https_required');
        }

        $secret = (string) Config::get('WORKER_SECRET', '');
        if ($secret === '') {
            return self::failure('worker_secret_unconfigured');
        }

        $workerId = Request::header(self::WORKER_HEADER);
        $timestamp = Request::header(self::TIMESTAMP_HEADER);
        $signature = Request::header(self::SIGNATURE_HEADER);

        if ($workerId === null || $timestamp === null || $signature === null) {
            return self::failure('worker_auth_missing');
        }

        if (!ctype_digit($timestamp)) {
            return self::failure('worker_auth_invalid');
        }

        $skew = Config::int('WORKER_CLOCK_SKEW', 300);
        if (abs(time() - (int) $timestamp) > $skew) {
            return self::failure('worker_auth_stale');
        }

        $expected = self::signature(
            Request::method(),
            Request::pathAndQuery(),
            (int) $timestamp,
            Request::rawBody(),
            $secret,
        );

        if (!hash_equals($expected, $signature)) {
            return self::failure('worker_auth_invalid');
        }

        return ['ok' => true, 'error' => null, 'worker_id' => $workerId];
    }

    /** The canonical signature string. Public so the worker client and tests share it. */
    public static function signature(
        string $method,
        string $pathAndQuery,
        int $timestamp,
        string $rawBody,
        string $secret,
    ): string {
        $canonical = strtoupper($method) . "\n"
            . $pathAndQuery . "\n"
            . $timestamp . "\n"
            . hash('sha256', $rawBody);

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * @return array{ok:bool,error:?string,worker_id:?string}
     */
    private static function failure(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'worker_id' => null];
    }

    private static function isSecure(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

        if ($https === 'on' || $https === '1') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
