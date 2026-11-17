#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * M0 acceptance-criteria verification (AC-M0-6, AC-M0-7, AC-M0-8).
 *
 * Covers the Phase 1 platform contracts: projects/membership, capability
 * profiles, tasks + best-fit assignment, versioned artifacts, the durable job
 * queue, the HMAC worker API, and authorization on both surfaces.
 *
 * Run against a live app + database:
 *   ./dev.sh serve
 *   ./dev.sh test:m0
 */

require __DIR__ . '/../autoload.php';

use Nico\Edge\Http\WorkerAuth;
use Nico\Edge\Queue\JobQueue;
use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;
use Nico\Edge\Support\Migrator;

Config::boot(__DIR__ . '/../.env');

$base = getenv('NICO_BASE_URL') ?: 'http://127.0.0.1:8080';
$secret = (string) Config::get('WORKER_SECRET', '');

if (@file_get_contents($base . '/health') === false) {
    fwrite(STDERR, "Edge is not reachable at {$base}. Start it with ./dev.sh serve.\n");
    exit(2);
}

$passed = 0;
$failed = 0;

function check(string $ac, string $description, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        printf("  \033[32mPASS\033[0m  %-7s %s\n", $ac, $description);
    } else {
        $failed++;
        printf("  \033[31mFAIL\033[0m  %-7s %s\n", $ac, $description);
        if ($detail !== '') {
            printf("        %s\n", $detail);
        }
    }
}

/** @return array{status:int,body:array} */
function http(string $method, string $url, array $body = [], ?string $cookieJar = null): array
{
    $headers = [];

    if ($body !== []) {
        $headers[] = 'Content-Type: application/json';
    }

    $result = httpRaw($method, $url, $headers, $body === [] ? null : json_encode($body), $cookieJar);

    return ['status' => $result['status'], 'body' => $result['body']];
}

/** @return array{status:int,body:array} */
function httpRaw(
    string $method,
    string $url,
    array $headers = [],
    ?string $rawBody = null,
    ?string $cookieJar = null,
): array {
    $ch = curl_init($url);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ];

    if ($rawBody !== null) {
        $options[CURLOPT_POSTFIELDS] = $rawBody;
    }

    if ($cookieJar !== null) {
        $options[CURLOPT_COOKIEJAR] = $cookieJar;
        $options[CURLOPT_COOKIEFILE] = $cookieJar;
    }

    curl_setopt_array($ch, $options);

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    $payload = substr($raw, $headerSize);
    $decoded = json_decode($payload, true);

    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : ['raw' => $payload]];
}

/** @return array{id:int,jar:string,email:string} */
function newUser(string $base, string $prefix): array
{
    $jar = (string) tempnam(sys_get_temp_dir(), 'nico_m0_');
    $email = $prefix . '_' . bin2hex(random_bytes(5)) . '@test.local';
    $password = 'correct-horse-battery';

    $reg = http('POST', "{$base}/auth/register", ['email' => $email, 'password' => $password], $jar);
    $login = http('POST', "{$base}/auth/login", ['email' => $email, 'password' => $password], $jar);

    check('setup', "register+login {$prefix}", $reg['status'] === 201 && $login['status'] === 200,
        json_encode([$reg, $login]));

    return ['id' => (int) ($reg['body']['id'] ?? 0), 'jar' => $jar, 'email' => $email];
}

/** @return array{status:int,body:array} */
function workerCall(
    string $base,
    string $method,
    string $path,
    string $body = '',
    ?int $timestamp = null,
    string $worker = 'm0-worker',
    bool $sign = true,
): array {
    $headers = ['Content-Type: application/json'];

    if ($sign) {
        $timestamp ??= time();
        $signature = WorkerAuth::signature(
            $method,
            $path,
            $timestamp,
            $body,
            (string) Config::get('WORKER_SECRET', ''),
        );

        $headers[] = 'X-Nico-Timestamp: ' . $timestamp;
        $headers[] = 'X-Nico-Signature: ' . $signature;
        $headers[] = 'X-Nico-Worker-Id: ' . $worker;
    }

    return httpRaw($method, $base . $path, $headers, $body);
}

echo "\nM0 — acceptance criteria\n";
echo str_repeat('=', 60) . "\n\n";

$pdo = Database::pdo();

// ---------------------------------------------------------------- AC-M0-6
echo "AC-M0-6  Platform contracts (projects, people, tasks, artifacts)\n";

$migrator = new Migrator($pdo, __DIR__ . '/../migrations');
$rerun = $migrator->migrate();

check('M0-6', 'migrations re-run is a no-op', $rerun === [], 're-ran: ' . implode(', ', $rerun));

$tables = ['projects', 'project_members', 'user_profiles', 'user_capabilities',
           'tasks', 'task_assignment_log', 'artifacts'];
$missingTables = [];

foreach ($tables as $table) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute(['t' => $table]);

    if ((int) $stmt->fetchColumn() !== 1) {
        $missingTables[] = $table;
    }
}

check('M0-6', 'all M0 tables exist', $missingTables === [], implode(', ', $missingTables));

$jobColumns = ['project_id', 'result_artifact_id', 'lease_token', 'last_error',
               'available_at', 'finished_at'];
$missingColumns = [];

foreach ($jobColumns as $column) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'jobs\' AND COLUMN_NAME = :c'
    );
    $stmt->execute(['c' => $column]);

    if ((int) $stmt->fetchColumn() !== 1) {
        $missingColumns[] = $column;
    }
}

check('M0-6', 'jobs gained queue/project columns', $missingColumns === [],
    implode(', ', $missingColumns));

$owner = newUser($base, 'm0_owner');
$alice = newUser($base, 'm0_alice');
$bob = newUser($base, 'm0_bob');
$outsider = newUser($base, 'm0_outsider');

$created = http('POST', "{$base}/projects", [
    'title'       => 'M0 contract project ' . bin2hex(random_bytes(3)),
    'description' => 'verification workspace',
    'kind'        => 'live',
], $owner['jar']);

check('M0-6', 'project created', $created['status'] === 201, json_encode($created['body']));

$projectId = (int) ($created['body']['id'] ?? 0);

$withMembers = http('POST', "{$base}/projects/{$projectId}/members", [
    'user_id' => $alice['id'],
    'role'    => 'member',
], $owner['jar']);
check('M0-6', 'owner adds a member', $withMembers['status'] === 201,
    json_encode($withMembers['body']));

http('POST', "{$base}/projects/{$projectId}/members", [
    'user_id' => $bob['id'],
    'role'    => 'member',
], $owner['jar']);

check('M0-6', 'member cannot add members', (function () use ($base, $projectId, $alice, $owner): bool {
    $r = http('POST', "{$base}/projects/{$projectId}/members", [
        'user_id' => $owner['id'],
        'role'    => 'member',
    ], $alice['jar']);

    return $r['status'] === 403;
})(), '');

check('M0-6', 'non-member cannot view project', (function () use ($base, $projectId, $outsider): bool {
    $r = http('GET', "{$base}/projects/{$projectId}", [], $outsider['jar']);

    return $r['status'] === 403;
})(), '');

check('M0-6', 'unauthenticated project list is rejected', (function () use ($base): bool {
    $r = http('GET', "{$base}/projects");

    return $r['status'] === 401;
})(), '');

$capA = http('POST', "{$base}/profiles/{$alice['id']}/capabilities", [
    'tag' => 'php', 'level' => 5,
], $alice['jar']);
check('M0-6', 'profile capability stored', $capA['status'] === 201, json_encode($capA['body']));

http('POST', "{$base}/profiles/{$bob['id']}/capabilities", ['tag' => 'php', 'level' => 3], $bob['jar']);
http('PUT', "{$base}/profiles/{$bob['id']}", ['availability' => 'away'], $bob['jar']);

check('M0-6', 'only self or admin edits a profile', (function () use ($base, $alice, $owner): bool {
    $r = http('GET', "{$base}/profiles/{$alice['id']}", [], $owner['jar']);

    return $r['status'] === 403;
})(), '');

$task1 = http('POST', "{$base}/projects/{$projectId}/tasks", [
    'title'        => 'Build API endpoint',
    'requirements' => [['tag' => 'php', 'level' => 2]],
], $owner['jar']);

check('M0-6', 'task created', $task1['status'] === 201, json_encode($task1['body']));
$task1Id = (int) ($task1['body']['id'] ?? 0);

$assign1 = http('POST', "{$base}/tasks/{$task1Id}/assign", [], $owner['jar']);
check('M0-6', 'best fit prefers higher capability margin',
    ($assign1['body']['state'] ?? '') === 'assigned'
        && (int) ($assign1['body']['assigned_to'] ?? 0) === $alice['id'],
    json_encode($assign1['body']));

$task2 = http('POST', "{$base}/projects/{$projectId}/tasks", [
    'title'        => 'Away member is skipped',
    'requirements' => [['tag' => 'php', 'level' => 2]],
], $owner['jar']);
$assign2 = http('POST', "{$base}/tasks/{$task2['body']['id']}/assign", [], $owner['jar']);
check('M0-6', 'away members are excluded',
    (int) ($assign2['body']['assigned_to'] ?? 0) === $alice['id'],
    json_encode($assign2['body']));

$task3 = http('POST', "{$base}/projects/{$projectId}/tasks", [
    'title'        => 'Concurrency cap is respected',
    'requirements' => [['tag' => 'php', 'level' => 2]],
], $owner['jar']);
$task3Id = (int) ($task3['body']['id'] ?? 0);

$filler = http('POST', "{$base}/projects/{$projectId}/tasks", [
    'title' => 'Capacity fixture',
], $owner['jar']);
$fillerId = (int) ($filler['body']['id'] ?? 0);

http('POST', "{$base}/tasks/{$fillerId}/assign", [
    'user_id' => $bob['id'], 'reason' => 'capacity fixture',
], $owner['jar']);

http('PUT', "{$base}/profiles/{$bob['id']}", [
    'availability' => 'available', 'max_open_tasks' => 1,
], $bob['jar']);

$assign3 = http('POST', "{$base}/tasks/{$task3Id}/assign", [], $owner['jar']);
check('M0-6', 'members at their cap are excluded',
    (int) ($assign3['body']['assigned_to'] ?? 0) === $alice['id'],
    json_encode($assign3['body']));

// Drop the capacity fixture (and its assignment log) so Bob's history is clean.
$pdo->prepare('DELETE FROM tasks WHERE id = :id')->execute(['id' => $fillerId]);

// Release everything, equalize capabilities, and let fairness decide: Alice has
// been assigned before, Bob never has, so Bob wins the tie.
foreach ([$task1Id, (int) $task2['body']['id'], $task3Id] as $id) {
    http('POST', "{$base}/tasks/{$id}/release", [], $owner['jar']);
}

http('POST', "{$base}/profiles/{$alice['id']}/capabilities", ['tag' => 'php', 'level' => 2], $alice['jar']);
http('PUT', "{$base}/profiles/{$bob['id']}", [
    'availability' => 'available', 'max_open_tasks' => 5,
], $bob['jar']);

$tieBreak = http('POST', "{$base}/tasks/{$task3Id}/assign", [], $owner['jar']);
check('M0-6', 'tie-break prefers never/least recently assigned',
    (int) ($tieBreak['body']['assigned_to'] ?? 0) === $bob['id'],
    json_encode($tieBreak['body']));

$task4 = http('POST', "{$base}/projects/{$projectId}/tasks", [
    'title'        => 'Impossible requirement',
    'requirements' => [['tag' => 'rust', 'level' => 9]],
], $owner['jar']);
$task4Id = (int) ($task4['body']['id'] ?? 0);

$noFit = http('POST', "{$base}/tasks/{$task4Id}/assign", [], $owner['jar']);
check('M0-6', 'no candidates flags needs_human',
    ($noFit['body']['state'] ?? '') === 'needs_human', json_encode($noFit['body']));

$override = http('POST', "{$base}/tasks/{$task4Id}/assign", [
    'user_id' => $bob['id'], 'reason' => 'manual test override',
], $owner['jar']);
check('M0-6', 'manual override assigns regardless of capability',
    ($override['body']['state'] ?? '') === 'assigned'
        && (int) ($override['body']['assigned_to'] ?? 0) === $bob['id'],
    json_encode($override['body']));

$history = http('GET', "{$base}/tasks/{$task1Id}/assignments", [], $owner['jar']);
check('M0-6', 'assignment history is recorded',
    $history['status'] === 200 && count($history['body']['history'] ?? []) >= 2,
    json_encode($history['body']));

check('M0-6', 'non-assignee member cannot update a task', (function () use ($base, $task3Id, $alice): bool {
    $r = http('PATCH', "{$base}/tasks/{$task3Id}", ['status' => 'in_progress'], $alice['jar']);

    return $r['status'] === 403;
})(), '');

$assigneePatch = http('PATCH', "{$base}/tasks/{$task3Id}", ['status' => 'in_progress'], $bob['jar']);
check('M0-6', 'assignee can update task status',
    $assigneePatch['status'] === 200 && ($assigneePatch['body']['status'] ?? '') === 'in_progress',
    json_encode($assigneePatch['body']));

$art1 = http('POST', "{$base}/projects/{$projectId}/artifacts", [
    'module'  => 'M3',
    'type'    => 'quiz',
    'content' => ['questions' => [['q' => '2+2', 'a' => 4]]],
], $owner['jar']);
$art2 = http('POST', "{$base}/projects/{$projectId}/artifacts", [
    'module'  => 'M3',
    'type'    => 'quiz',
    'content' => ['questions' => [['q' => '3+3', 'a' => 6]]],
], $owner['jar']);

check('M0-6', 'artifact versions increment and supersede',
    ($art2['body']['version'] ?? 0) === 2
        && (int) ($art2['body']['supersedes_id'] ?? 0) === (int) ($art1['body']['id'] ?? -1),
    json_encode([$art1['body'], $art2['body']]));

$old = http('GET', "{$base}/artifacts/{$art1['body']['id']}", [], $owner['jar']);
check('M0-6', 'superseded artifact is no longer current',
    (int) ($old['body']['is_current'] ?? 1) === 0, json_encode($old['body']));

$list = http('GET', "{$base}/projects/{$projectId}/artifacts?module=M3", [], $owner['jar']);
check('M0-6', 'artifact listing returns the current version only',
    count($list['body']['artifacts'] ?? []) === 1, json_encode($list['body']));

check('M0-6', 'non-member cannot read project artifacts', (function () use ($base, $projectId, $outsider): bool {
    $r = http('GET', "{$base}/projects/{$projectId}/artifacts", [], $outsider['jar']);

    return $r['status'] === 403;
})(), '');

// ---------------------------------------------------------------- AC-M0-7
echo "\nAC-M0-7  Durable queue + HMAC worker API\n";

$savedQueued = $pdo->query('SELECT id, available_at FROM jobs WHERE status = \'queued\'')->fetchAll();
$pdo->exec("UPDATE jobs SET available_at = '2999-01-01 00:00:00' WHERE status = 'queued'");

$testJobIds = [];

try {
    $queue = new JobQueue();

    $unsigned = workerCall($base, 'POST', '/worker/claim', '', null, 'm0-worker', false);
    check('M0-7', 'unsigned worker request rejected', $unsigned['status'] === 401,
        json_encode($unsigned['body']));

    $stale = workerCall($base, 'POST', '/worker/claim', '', time() - 1000);
    check('M0-7', 'stale worker timestamp rejected', $stale['status'] === 401,
        json_encode($stale['body']));

    $job1 = $queue->enqueue('generate_report', ['project_id' => $projectId], [
        'payload' => ['scope' => 'm0-verify'],
    ]);
    $testJobIds[] = $job1;

    // Sign an empty body but send a different one.
    $ts = time();
    $sig = WorkerAuth::signature('POST', '/worker/claim', $ts, '', $secret);
    $badBody = httpRaw('POST', $base . '/worker/claim', [
        'Content-Type: application/json',
        'X-Nico-Timestamp: ' . $ts,
        'X-Nico-Signature: ' . $sig,
        'X-Nico-Worker-Id: m0-worker',
    ], 'not-the-signed-body');
    check('M0-7', 'body tampering breaks the signature', $badBody['status'] === 401,
        json_encode($badBody['body']));

    $claimed = workerCall($base, 'POST', '/worker/claim', '', null, 'm0-worker-a');
    $lease = (string) ($claimed['body']['lease_token'] ?? '');

    check('M0-7', 'signed claim returns the due job',
        $claimed['status'] === 200
            && (int) ($claimed['body']['id'] ?? 0) === $job1
            && $lease !== '',
        json_encode($claimed['body']));

    $second = workerCall($base, 'POST', '/worker/claim', '', null, 'm0-worker-b');
    check('M0-7', 'a claimed job is not handed to another worker',
        $second['status'] === 204, json_encode($second['body']));

    $file = workerCall(
        $base,
        'GET',
        "/worker/jobs/{$job1}/file?lease_token={$lease}",
        '',
        null,
        'm0-worker-a',
    );
    check('M0-7', 'file endpoint reports no source for a project-only job',
        $file['status'] === 404 && ($file['body']['error'] ?? '') === 'no_source',
        json_encode($file['body']));

    $wrongHeartbeat = workerCall($base, 'POST', "/worker/jobs/{$job1}/heartbeat",
        json_encode(['lease_token' => 'not-the-token']), null, 'm0-worker-b');
    check('M0-7', 'heartbeat rejects a foreign lease', $wrongHeartbeat['status'] === 409,
        json_encode($wrongHeartbeat['body']));

    $heartbeat = workerCall($base, 'POST', "/worker/jobs/{$job1}/heartbeat",
        json_encode(['lease_token' => $lease]), null, 'm0-worker-a');
    check('M0-7', 'heartbeat extends the lease',
        $heartbeat['status'] === 200 && !empty($heartbeat['body']['lease_expires_at']),
        json_encode($heartbeat['body']));

    $retry = workerCall($base, 'POST', "/worker/jobs/{$job1}/result", json_encode([
        'lease_token' => $lease,
        'outcome'     => 'retryable',
        'error'       => 'transient failure',
    ]), null, 'm0-worker-a');
    check('M0-7', 'retryable result returns the job to the queue',
        $retry['status'] === 200 && ($retry['body']['status'] ?? '') === 'queued',
        json_encode($retry['body']));

    $pdo->prepare('UPDATE jobs SET available_at = UTC_TIMESTAMP() WHERE id = :id')
        ->execute(['id' => $job1]);

    $claimed2 = workerCall($base, 'POST', '/worker/claim', '', null, 'm0-worker-b');
    $lease2 = (string) ($claimed2['body']['lease_token'] ?? '');
    check('M0-7', 'reclaimed job carries the incremented attempt count',
        (int) ($claimed2['body']['attempts'] ?? 0) === 2,
        json_encode($claimed2['body']));

    $permanent = workerCall($base, 'POST', "/worker/jobs/{$job1}/result", json_encode([
        'lease_token' => $lease2,
        'outcome'     => 'permanent',
        'error'       => 'unrecoverable',
    ]), null, 'm0-worker-b');
    check('M0-7', 'permanent failure is stored on the job',
        $permanent['status'] === 200
            && ($permanent['body']['status'] ?? '') === 'failed'
            && ($permanent['body']['last_error'] ?? '') === 'unrecoverable',
        json_encode($permanent['body']));

    // Dead-letter after exhausting attempts.
    $job2 = $queue->enqueue('generate_report', ['project_id' => $projectId]);
    $testJobIds[] = $job2;

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $c = workerCall($base, 'POST', '/worker/claim', '', null, 'm0-worker-c');
        $currentLease = (string) ($c['body']['lease_token'] ?? '');

        if ($attempt === 3) {
            $file2 = workerCall(
                $base,
                'GET',
                "/worker/jobs/{$job2}/file?lease_token={$currentLease}",
                '',
                null,
                'm0-worker-c',
            );
            check('M0-7', 'held lease authorizes the file endpoint',
                $file2['status'] === 404 && ($file2['body']['error'] ?? '') === 'no_source',
                json_encode($file2['body']));
        }

        workerCall($base, 'POST', "/worker/jobs/{$job2}/result", json_encode([
            'lease_token' => $currentLease,
            'outcome'     => 'retryable',
            'error'       => "attempt {$attempt} failed",
        ]), null, 'm0-worker-c');

        $pdo->prepare('UPDATE jobs SET available_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['id' => $job2]);
    }

    $deadRow = $queue->find($job2);
    $attention = (int) $pdo->query(
        "SELECT needs_attention FROM projects WHERE id = {$projectId}"
    )->fetchColumn();

    check('M0-7', 'exhausted retries dead-letter and flag the project',
        $deadRow !== null && $deadRow['status'] === 'dead' && $attention === 1,
        json_encode($deadRow));

    // A successful result can attach a versioned artifact.
    $job3 = $queue->enqueue('generate_report', ['project_id' => $projectId]);
    $testJobIds[] = $job3;

    $claimed3 = workerCall($base, 'POST', '/worker/claim', '', null, 'm0-worker-d');
    $lease3 = (string) ($claimed3['body']['lease_token'] ?? '');

    $ok = workerCall($base, 'POST', "/worker/jobs/{$job3}/result", json_encode([
        'lease_token' => $lease3,
        'outcome'     => 'ok',
        'artifact'    => [
            'module'  => 'M7',
            'type'    => 'report',
            'content' => ['summary' => 'worker-produced'],
        ],
    ]), null, 'm0-worker-d');

    $resultArtifactId = (int) ($ok['body']['result_artifact_id'] ?? 0);
    $artifact = $resultArtifactId > 0
        ? http('GET', "{$base}/artifacts/{$resultArtifactId}", [], $owner['jar'])
        : ['status' => 0, 'body' => []];

    check('M0-7', 'successful result stores and links an artifact',
        $ok['status'] === 200
            && ($ok['body']['status'] ?? '') === 'done'
            && $resultArtifactId > 0
            && ($artifact['body']['project_id'] ?? 0) === $projectId
            && ($artifact['body']['content']['summary'] ?? '') === 'worker-produced',
        json_encode([$ok['body'], $artifact['body']]));

    // Expired leases are reclaimed by the reaper.
    $job4 = $queue->enqueue('generate_report', ['project_id' => $projectId]);
    $testJobIds[] = $job4;

    $claimed4 = workerCall($base, 'POST', '/worker/claim', '', null, 'm0-worker-e');
    $lease4 = (string) ($claimed4['body']['lease_token'] ?? '');
    check('M0-7', 'fourth job claimed for expiry test', $lease4 !== '',
        json_encode($claimed4['body']));

    $pdo->prepare(
        'UPDATE jobs SET lease_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 SECOND)
          WHERE id = :id'
    )->execute(['id' => $job4]);

    $reaped = $queue->reapExpired();
    $reclaimed = $queue->find($job4);

    check('M0-7', 'expired lease is returned to the queue',
        $reaped['requeued'] >= 1
            && $reclaimed !== null
            && $reclaimed['status'] === 'queued'
            && $reclaimed['worker_id'] === null,
        json_encode([$reaped, $reclaimed]));
} finally {
    $restore = $pdo->prepare('UPDATE jobs SET available_at = :at WHERE id = :id');

    foreach ($savedQueued as $row) {
        $restore->execute([
            'at' => $row['available_at'],
            'id' => (int) $row['id'],
        ]);
    }

    foreach ($testJobIds as $id) {
        $pdo->prepare('DELETE FROM jobs WHERE id = :id')->execute(['id' => $id]);
    }
}

// ---------------------------------------------------------------- AC-M0-8
echo "\nAC-M0-8  Authorization is enforced server-side\n";

check('M0-8', 'worker endpoints reject missing credentials', (function () use ($base): bool {
    $r = workerCall($base, 'POST', '/worker/jobs/1/heartbeat', '{}', null, 'x', false);

    return $r['status'] === 401;
})(), '');

check('M0-8', 'project membership is the only path to project data', (function () use (
    $base,
    $projectId,
    $outsider
): bool {
    $project = http('GET', "{$base}/projects/{$projectId}", [], $outsider['jar']);
    $tasks = http('GET', "{$base}/projects/{$projectId}/tasks", [], $outsider['jar']);

    return $project['status'] === 403 && $tasks['status'] === 403;
})(), '');

check('M0-8', 'unknown task is not leaked to non-members', (function () use ($base, $outsider): bool {
    $r = http('GET', "{$base}/tasks/999999", [], $outsider['jar']);

    return $r['status'] === 404 || $r['status'] === 403;
})(), '');

echo "\n" . str_repeat('-', 60) . "\n";
printf("%d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
