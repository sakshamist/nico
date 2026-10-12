#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Sprint 1 acceptance-criteria verification (AC-1 .. AC-10).
 *
 * Run against a live app + database:
 *   php -S 127.0.0.1:8080 -t public &
 *   php tests/verify_sprint1.php
 *
 * Exits non-zero if any criterion fails, so it can gate a deploy.
 */

require __DIR__ . '/../autoload.php';

use Nico\Edge\Auth\Session;
use Nico\Edge\Auth\UserRepository;
use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;
use Nico\Edge\Support\Migrator;
use Nico\Edge\Support\PasswordHasher;

Config::boot(__DIR__ . '/../.env');

$base = getenv('NICO_BASE_URL') ?: 'http://127.0.0.1:8080';

$passed = 0;
$failed = 0;
$results = [];

function check(string $ac, string $description, bool $ok, string $detail = ''): void
{
    global $passed, $failed, $results;

    $results[] = [$ac, $description, $ok, $detail];

    if ($ok) {
        $passed++;
        printf("  \033[32mPASS\033[0m  %-6s %s\n", $ac, $description);
    } else {
        $failed++;
        printf("  \033[31mFAIL\033[0m  %-6s %s\n", $ac, $description);
        if ($detail !== '') {
            printf("        %s\n", $detail);
        }
    }
}

/** @return array{status:int,body:array} */
function http(string $method, string $url, array $body = [], ?string $cookieJar = null): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    if ($body !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    if ($cookieJar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    $payload = substr((string) $raw, $headerSize);
    $decoded = json_decode($payload, true);

    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : ['raw' => $payload]];
}

echo "\nSprint 1 — acceptance criteria\n";
echo str_repeat('=', 60) . "\n\n";

$pdo = Database::pdo();

// ---------------------------------------------------------------- AC-1
echo "AC-1  Migration runner creates schema and is idempotent\n";
$migrator = new Migrator($pdo, __DIR__ . '/../migrations');
$pdo->exec('DROP TABLE IF EXISTS _migrations');
$migrator->migrate();
$afterFirst = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
)->fetchColumn();

$secondRun = $migrator->migrate();

$expected = ['users', 'resources', 'files', 'jobs', 'extracted_text',
             'knowledge_refs', 'outputs', 'blog_posts'];

$present = [];
foreach ($expected as $table) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute(['t' => $table]);
    $present[$table] = ((int) $stmt->fetchColumn()) === 1;
}

$allPresent = !in_array(false, $present, true);
check('AC-1', 'all 8 entities created', $allPresent,
    'missing: ' . implode(', ', array_keys(array_filter($present, fn($v) => !$v))));

// MariaDB serves information_schema.STATISTICS from a cached statistics table
// that can lag immediately after DDL, so refresh it before asserting on indexes.
// ANALYZE returns a result set that must be drained, or the next query fails
// with "unbuffered queries are active".
foreach (['users', 'resources', 'files', 'jobs', 'extracted_text',
          'knowledge_refs', 'outputs', 'blog_posts'] as $table) {
    $pdo->query("ANALYZE TABLE {$table}")->fetchAll();
}

$indexes = $pdo->query(
    "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = 'idx_jobs_status_created'"
)->fetchAll();
check('AC-1', 'claim-path index present', count($indexes) === 1);

check('AC-1', 'second migrate() is a no-op', $secondRun === [],
    're-ran: ' . implode(', ', $secondRun));

// ---------------------------------------------------------------- AC-2
echo "\nAC-2  No secrets in tracked files\n";
$tracked = trim((string) shell_exec(
    'cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && git ls-files'
));

$leaks = [];
foreach (explode("\n", $tracked) as $file) {
    if ($file === '' || !is_file($file)) {
        continue;
    }

    $contents = (string) file_get_contents($file);

    // A committed .env is a leak regardless of its contents.
    if (preg_match('#(^|/)\.env$#', $file) === 1) {
        $leaks[] = "{$file} (a .env file is tracked)";
        continue;
    }

    if (preg_match_all(
        '/^\s*(DB_PASSWORD|WORKER_SECRET|SESSION_SECRET)\s*=\s*(\S+)\s*$/m',
        $contents, $m
    ) > 0) {
        foreach ($m[2] as $value) {
            if ($value !== '' && !str_contains($value, 'devpass') && !str_contains($value, 'change-me')) {
                $leaks[] = "{$file}: {$m[1][array_search($value, $m[2], true)]}";
            }
        }
    }
}
check('AC-2', 'no secret values in tracked files', $leaks === [],
    implode('; ', $leaks));

// ---------------------------------------------------------------- AC-4
echo "\nAC-4  Atomic-claim support is probed at runtime\n";
$caps = Database::capabilities();
$capsSource = (new ReflectionMethod(Database::class, 'capabilities'));
$src = file_get_contents((string) $capsSource->getFileName());

// The probe must execute SQL, not branch on a version string.
$probeIsRuntime = str_contains($src, 'probeSkipLocked')
    && str_contains($src, 'FOR UPDATE SKIP LOCKED')
    && preg_match('/version_compare.*skip_locked/is', $src) !== 1;

check('AC-4', 'SKIP LOCKED detected by executing the statement',
    $caps['skip_locked'] === true && $probeIsRuntime,
    "engine={$caps['engine']} {$caps['version']} skip_locked=" . var_export($caps['skip_locked'], true));
check('AC-4', 'capability carries no version-string branch', $probeIsRuntime);

// ---------------------------------------------------------------- AC-5 / AC-6
echo "\nAC-5  Register, log in, access, log out\n";
$jar = tempnam(sys_get_temp_dir(), 'nico_ac5_');
$email = 'ac5_' . bin2hex(random_bytes(6)) . '@test.local';
$password = 'correct-horse-battery';

$reg = http('POST', "{$base}/auth/register", ['email' => $email, 'password' => $password], $jar);
check('AC-5', 'register succeeds', $reg['status'] === 201, json_encode($reg['body']));

$login = http('POST', "{$base}/auth/login", ['email' => $email, 'password' => $password], $jar);
check('AC-5', 'login succeeds', $login['status'] === 200, json_encode($login['body']));
check('AC-5', 'login response omits password_hash',
    !isset($login['body']['user']['password_hash']));

$me = http('GET', "{$base}/auth/me", [], $jar);
check('AC-5', 'session grants access', $me['status'] === 200, json_encode($me['body']));

$badPassword = http('POST', "{$base}/auth/login", ['email' => $email, 'password' => 'wrong']);
$badEmail = http('POST', "{$base}/auth/login", ['email' => 'nobody@test.local', 'password' => 'wrong']);
check('AC-5', 'bad password rejected 401', $badPassword['status'] === 401);
check('AC-5', 'unknown email gives an identical 401',
    $badEmail['status'] === 401
    && $badEmail['body']['error'] === ($badPassword['body']['error'] ?? null),
    'unknown-email vs wrong-password responses must be indistinguishable');

// Preserve the pre-logout cookie, then log out and replay it (AC-6).
copy($jar, $jar . '.pre');
$logout = http('POST', "{$base}/auth/logout", [], $jar);
check('AC-6', 'logout succeeds', $logout['status'] === 200);

$replay = http('GET', "{$base}/auth/me", [], $jar . '.pre');
check('AC-6', 'pre-logout session cannot be replayed', $replay['status'] === 401,
    'got ' . $replay['status'] . ' — session id was not destroyed');

@unlink($jar);
@unlink($jar . '.pre');

// ---------------------------------------------------------------- AC-7
echo "\nAC-7  Resource ownership is enforced\n";
$users = new UserRepository();

$a = $users->create('ac7_a_' . bin2hex(random_bytes(4)) . '@test.local', 'password-abc-123');
$b = $users->create('ac7_b_' . bin2hex(random_bytes(4)) . '@test.local', 'password-abc-123');

$insert = $pdo->prepare(
    "INSERT INTO resources (user_id, title, source_type, status, created_at)
     VALUES (:u, 'A doc', 'pdf', 'uploaded', :at)"
);

$insert->execute(['u' => $a, 'at' => gmdate('c')]);
$aRes = (int) $pdo->lastInsertId();
$insert->execute(['u' => $b, 'at' => gmdate('c')]);
$bRes = (int) $pdo->lastInsertId();

check('AC-7', 'owner may access their own resource', $users->ownsResource($a, $aRes));
check('AC-7', 'non-owner is denied', !$users->ownsResource($a, $bRes));
check('AC-7', 'denial holds in the reverse direction', !$users->ownsResource($b, $aRes));
check('AC-7', 'admin retains access (FR-8)',
    $users->canAccessResource($a, 'admin', $bRes));
check('AC-7', 'student blocked cross-user via canAccess',
    !$users->canAccessResource($a, 'student', $bRes));

// ---------------------------------------------------------------- AC-8
echo "\nAC-8  Passwords are hashed, never stored in plaintext\n";
$hash = (string) $pdo->query("SELECT password_hash FROM users WHERE id = {$a}")->fetchColumn();

check('AC-8', 'stored value is a bcrypt/argon hash',
    str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon2'),
    substr($hash, 0, 12));
check('AC-8', 'hash verifies the correct password', PasswordHasher::verify('password-abc-123', $hash));
check('AC-8', 'hash rejects a wrong password', !PasswordHasher::verify('nope', $hash));

$leakCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE password_hash = 'password-abc-123'
        OR password_hash LIKE '%password-abc-123%'"
)->fetchColumn();
check('AC-8', 'plaintext absent from the users table', $leakCount === 0);

// ---------------------------------------------------------------- AC-3
echo "\nAC-3  Health reports status and capabilities\n";
$health = http('GET', "{$base}/health");
$body = $health['body'];

check('AC-3', 'health returns 200 when the database is up', $health['status'] === 200);
check('AC-3', 'reports engine and version',
    ($body['database']['engine'] ?? '') !== '' && ($body['database']['version'] ?? '') !== '');
check('AC-3', 'reports atomic-claim support',
    array_key_exists('skip_locked', $body['database'] ?? []));
check('AC-3', 'reports migration state',
    array_key_exists('up_to_date', $body['migrations'] ?? []));
check('AC-3', 'reports the effective upload ceiling',
    ($body['upload']['upload_max_filesize'] ?? '') !== '');

// ---------------------------------------------------------------- FR-10
echo "\nFR-10 Role and status vocabularies are constrained in the data layer\n";
$enforced = true;
$detail = '';

try {
    $pdo->prepare(
        "INSERT INTO users (email, password_hash, role, created_at)
         VALUES ('bad_role@test.local', 'x', 'wizard', :at)"
    )->execute(['at' => gmdate('c')]);
    $enforced = false;
    $detail = 'insert with role=wizard was accepted';
} catch (PDOException) {
    // expected: the ENUM rejects it
}

check('FR-10', 'invalid role rejected by the database', $enforced, $detail);

$vocabOk = true;
foreach ([
    ['jobs', 'status'],
    ['resources', 'status'],
    ['files', 'kind'],
] as [$table, $column]) {
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);

    if (!str_starts_with((string) $stmt->fetchColumn(), 'enum(')) {
        $vocabOk = false;
        $detail = "{$table}.{$column} is not an enum";
    }
}
check('FR-10', 'status vocabularies are database-constrained', $vocabOk, $detail);

// ---------------------------------------------------------------- summary
echo "\n" . str_repeat('=', 60) . "\n";
printf("passed: %d   failed: %d\n", $passed, $failed);

if ($failed > 0) {
    echo "\nFAILING CRITERIA\n";
    foreach ($results as [$ac, $desc, $ok, $detail]) {
        if (!$ok) {
            printf("  %-6s %s%s\n", $ac, $desc, $detail !== '' ? " — {$detail}" : '');
        }
    }
}

exit($failed === 0 ? 0 : 1);
