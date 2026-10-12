<?php

declare(strict_types=1);

namespace Nico\Edge\Auth;

use PDO;

/**
 * Session lifecycle (FR-7).
 *
 * Sessions live in PHP's session store, hardened with cookie attributes. The
 * session id is regenerated on login to prevent session fixation, and logout
 * destroys the record outright rather than merely clearing it, so an old cookie
 * cannot be replayed (AC-6).
 */
final class Session
{
    private bool $started = false;

    public function __construct(
        private readonly string $name = 'nico_session',
        private readonly int $lifetime = 7200,
    ) {
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        if (PHP_SAPI !== 'cli') {
            session_name($this->name);
            session_set_cookie_params([
                'lifetime' => 0,          // session cookie; expires when the browser closes
                'path'     => '/',
                'domain'   => '',
                'secure'   => ($_SERVER['HTTPS'] ?? '') === 'on',
                'httponly' => true,       // not readable from JavaScript (FR-6 hardening)
                'samesite' => 'Lax',      // limits cross-site submission
            ]);
        }

        session_start();
        $this->started = true;
    }

    /** Regenerates the id on privilege change (FR-7). */
    public function regenerate(): void
    {
        $this->start();

        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
        }
    }

    public function put(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function forget(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    /**
     * Fully destroys the session (AC-6). Clearing $_SESSION alone would leave a
     * valid, attacker-replayable cookie.
     */
    public function destroy(): void
    {
        $this->start();

        $_SESSION = [];

        if (PHP_SAPI !== 'cli') {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name() ?: $this->name,
                    '',
                    [
                        'expires'  => time() - 42000,
                        'path'     => $params['path'],
                        'domain'   => $params['domain'],
                        'secure'   => $params['secure'],
                        'httponly' => $params['httponly'],
                        'samesite' => $params['samesite'] ?? 'Lax',
                    ]
                );
            }

            session_destroy();
        }

        $this->started = false;
    }

    /** True once the configured idle/expiry window has elapsed (FR-7). */
    public function isExpired(): bool
    {
        $this->start();
        $startedAt = (int) ($_SESSION['__started_at'] ?? 0);

        return $startedAt > 0 && (time() - $startedAt) > $this->lifetime;
    }

    public function markStarted(): void
    {
        $this->start();
        $_SESSION['__started_at'] = time();
    }

    public function id(): string
    {
        $this->start();

        return session_id() ?: '';
    }
}
