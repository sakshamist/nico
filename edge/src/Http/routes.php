<?php

declare(strict_types=1);

namespace Nico\Edge\Http;

use Nico\Edge\Auth\Session;
use Nico\Edge\Auth\UserRepository;
use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;
use Nico\Edge\Support\Log;

/**
 * Routes registered by Sprint 1.
 */
function routes(App $app): void
{
    // ---------------------------------------------------------------
    // Health (FR-5, AC-3)
    // ---------------------------------------------------------------
    $app->get('/health', static function (App $app): void {
        Response::noStore();

        $report = [
            'status'  => 'ok',
            'time'    => gmdate('c'),
            'env'     => Config::get('APP_ENV', 'unknown'),
            'php'     => PHP_VERSION,
        ];

        // Database connectivity.
        try {
            $caps = Database::capabilities();

            $report['database'] = [
                'status'        => 'up',
                'engine'        => $caps['engine'],
                'version'       => $caps['version'],
                'version_raw'   => $caps['version_raw'],
                'skip_locked'   => $caps['skip_locked'],
                'json_type'     => $caps['json_type'],
            ];

            // The job queue cannot use the fast claim path without this, so make
            // it impossible to miss.
            if (!$caps['skip_locked']) {
                $report['status'] = 'degraded';
                $report['warnings'][] = 'SKIP LOCKED unsupported; queue will use the fallback claim strategy.';
            }

            // Migration state.
            $pdo = Database::pdo();
            $applied = $pdo->query('SELECT name FROM _migrations ORDER BY id')->fetchAll();

            $files = array_map(
                'basename',
                glob(Config::get('MIGRATIONS_PATH', __DIR__ . '/../../migrations') . '/*.sql') ?: []
            );
            sort($files, SORT_STRING);

            $done = array_column($applied, 'name');
            $pending = array_values(array_diff($files, $done));

            $report['migrations'] = [
                'applied' => count($done),
                'pending' => $pending,
                'up_to_date' => $pending === [],
            ];
        } catch (\Throwable $e) {
            $report['status'] = 'unhealthy';
            $report['database'] = ['status' => 'down', 'error' => $e->getMessage()];

            Log::error('health.database_down', ['error' => $e->getMessage()]);

            Response::json($report, 503);

            return;
        }

        // Session configuration.
        $report['session'] = [
            'name'      => Config::get('SESSION_NAME', 'nico_session'),
            'lifetime'  => Config::int('SESSION_LIFETIME', 7200),
            'secure'    => ($_SERVER['HTTPS'] ?? '') === 'on',
            'httponly'  => true,
        ];

        // Effective upload ceiling (Sprint 2 depends on this being visible here).
        $uploadLimit = effectiveUploadLimit();
        $report['upload'] = [
            'upload_max_filesize' => $uploadLimit['upload_max_filesize'],
            'post_max_size'       => $uploadLimit['post_max_size'],
            'memory_limit'        => $uploadLimit['memory_limit'],
            'consistent'          => $uploadLimit['consistent'],
        ];

        Response::json($report, $report['status'] === 'unhealthy' ? 503 : 200);
    });

    // ---------------------------------------------------------------
    // Auth (FR-6, FR-7, AC-5, AC-6)
    // ---------------------------------------------------------------
    $app->post('/auth/register', static function (App $app): void {
        $body = jsonBody();

        $email = (string) ($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $role = (string) ($body['role'] ?? 'student');

        if (!in_array($role, UserRepository::ROLES, true)) {
            Response::json(['error' => 'invalid_role'], 422);
            return;
        }

        if (strlen($password) < 8) {
            Response::json(['error' => 'password_too_short', 'min' => 8], 422);
            return;
        }

        $users = new UserRepository();

        // Self-service admin signup would be an escalation path.
        if ($role === 'admin' && !$users->count() === 0) {
            Response::json(['error' => 'admin_creation_disabled'], 403);
            return;
        }

        try {
            $id = $users->create($email, $password, $role);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_users_email')) {
                Response::json(['error' => 'email_taken'], 409);
                return;
            }
            throw $e;
        } catch (\InvalidArgumentException $e) {
            Response::json(['error' => 'invalid_email'], 422);
            return;
        }

        Log::info('auth.registered', ['user_id' => $id, 'role' => $role]);

        Response::json(['id' => $id, 'email' => strtolower($email), 'role' => $role], 201);
    });

    $app->post('/auth/login', static function (App $app): void {
        $body = jsonBody();
        $email = (string) ($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');

        $session = new Session(
            (string) Config::get('SESSION_NAME', 'nico_session'),
            Config::int('SESSION_LIFETIME', 7200),
        );

        $user = (new UserRepository())->verifyCredentials($email, $password);

        if ($user === null) {
            // Deliberately identical for unknown-email and wrong-password.
            Response::json(['error' => 'invalid_credentials'], 401);
            return;
        }

        $session->destroy();          // discard any pre-auth session
        $session->start();
        $session->regenerate();       // prevent session fixation
        $session->markStarted();
        $session->put('user_id', (int) $user['id']);
        $session->put('role', (string) $user['role']);
        $session->put('email', (string) $user['email']);

        Log::info('auth.login', ['user_id' => (int) $user['id']]);

        Response::json([
            'user' => ['id' => (int) $user['id'], 'email' => $user['email'], 'role' => $user['role']],
        ]);
    });

    $app->post('/auth/logout', static function (App $app): void {
        $session = new Session(
            (string) Config::get('SESSION_NAME', 'nico_session'),
            Config::int('SESSION_LIFETIME', 7200),
        );

        $userId = (int) $session->get('user_id', 0);
        $session->destroy();

        Log::info('auth.logout', ['user_id' => $userId]);

        Response::json(['ok' => true]);
    });

    $app->get('/auth/me', static function (App $app): void {
        $session = new Session();

        $userId = (int) $session->get('user_id', 0);
        if ($userId === 0 || $session->isExpired()) {
            Response::json(['error' => 'unauthenticated'], 401);
            return;
        }

        $user = (new UserRepository())->findById($userId);

        if ($user === null) {
            $session->destroy();
            Response::json(['error' => 'unauthenticated'], 401);
            return;
        }

        unset($user['password_hash']);

        Response::json(['user' => $user]);
    });
}

/**
 * Resolves the real upload ceiling from PHP's ini values.
 *
 * Uploaded files land in $_FILES only when post_max_size is not exceeded, so an
 * oversized request looks like an empty POST. Surfacing the inconsistency here
 * catches the exact misconfiguration that silently breaks uploads (Sprint 2).
 *
 * @return array{upload_max_filesize:string,post_max_size:string,memory_limit:string,consistent:bool}
 */
function effectiveUploadLimit(): array
{
    $upload = (string) ini_get('upload_max_filesize');
    $post = (string) ini_get('post_max_size');
    $memory = (string) ini_get('memory_limit');

    return [
        'upload_max_filesize' => $upload,
        'post_max_size'       => $post,
        'memory_limit'        => $memory,
        'consistent'          => iniBytes($post) >= iniBytes($upload)
            && iniBytes($memory) >= iniBytes($post),
    ];
}

/** Converts a php.ini shorthand size ("512M", "2G", "1K") to bytes. */
function iniBytes(string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    return match ($unit) {
        'g'     => (int) ($number * 1024 ** 3),
        'm'     => (int) ($number * 1024 ** 2),
        'k'     => (int) ($number * 1024),
        default => (int) $number,
    };
}

function jsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}
