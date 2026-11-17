#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * M2–M8 acceptance smoke test (ingestion M1 intentionally excluded).
 *
 * Exercises the module data flow end to end: authored knowledge → lexical
 * retrieval → quiz/lesson generation → attempts/progress → project report →
 * M6 teams/RSVP/submissions → server-rendered workspace.
 *
 *   ./dev.sh serve
 *   ./dev.sh test:modules
 */

require __DIR__ . '/../autoload.php';

use Nico\Edge\Activities\ActivityRepository;
use Nico\Edge\Knowledge\KnowledgeRepository;
use Nico\Edge\Projects\ProjectRepository;
use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;
use Nico\Edge\Support\Migrator;

Config::boot(__DIR__ . '/../.env');

$base = getenv('NICO_BASE_URL') ?: 'http://127.0.0.1:8080';

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
            printf("        %s\n", substr($detail, 0, 500));
        }
    }
}

/** @return array{status:int,body:array,raw:string} */
function http(
    string $method,
    string $url,
    array $body = [],
    ?string $jar = null,
    array $headers = [],
): array {
    $ch = curl_init($url);
    $rawHeaders = $headers;

    if ($body !== []) {
        $rawHeaders[] = 'Content-Type: application/json';
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $rawHeaders,
    ];

    if ($body !== []) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }

    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }

    curl_setopt_array($ch, $options);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $payload = substr($raw, $headerSize);
    $decoded = json_decode($payload, true);

    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'raw' => $payload];
}

/** @return array{status:int,body:array,raw:string} */
function httpForm(
    string $method,
    string $url,
    array $body = [],
    ?string $jar = null,
): array {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ];

    if ($body !== []) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($body);
    }

    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }

    curl_setopt_array($ch, $options);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $payload = substr($raw, $headerSize);
    $decoded = json_decode($payload, true);

    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'raw' => $payload];
}

/** @return array{id:int,jar:string,email:string} */
function user(string $base, string $prefix): array
{
    $jar = (string) tempnam(sys_get_temp_dir(), 'nico_mod_');
    $email = $prefix . '_' . bin2hex(random_bytes(5)) . '@test.local';
    $password = 'correct-horse-battery';

    $reg = http('POST', "{$base}/auth/register", ['email' => $email, 'password' => $password], $jar);
    $login = http('POST', "{$base}/auth/login", ['email' => $email, 'password' => $password], $jar);

    check('setup', "account {$prefix}", $reg['status'] === 201 && $login['status'] === 200);

    return [
        'id'    => (int) ($reg['body']['id'] ?? 0),
        'jar'   => $jar,
        'email' => $email,
    ];
}

echo "\nM2–M8 — module workflow\n";
echo str_repeat('=', 60) . "\n\n";

$pdo = Database::pdo();
$migrator = new Migrator($pdo, __DIR__ . '/../migrations');
$rerun = $migrator->migrate();

check('M2', 'migrations are re-runnable', $rerun === [], implode(', ', $rerun));

$tables = [
    'knowledge_sources', 'knowledge_chunks', 'learning_progress', 'assessment_attempts',
    'activities', 'activity_teams', 'activity_team_members', 'event_rsvps',
    'activity_submissions',
];
$missing = [];

foreach ($tables as $table) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute(['t' => $table]);

    if ((int) $stmt->fetchColumn() !== 1) {
        $missing[] = $table;
    }
}

check('M2', 'module tables exist', $missing === [], implode(', ', $missing));

// ------------------------------------------------------------------ M2
echo "\nM2  Knowledge substrate\n";

$alice = user($base, 'alice');
$bob = user($base, 'bob');

$project = http('POST', "{$base}/projects", [
    'title'       => 'Robotics safety sprint',
    'description' => 'Field tests and safety training.',
    'kind'        => 'live',
], $alice['jar']);

$projectId = (int) ($project['body']['id'] ?? 0);
check('M2', 'project created', $project['status'] === 201 && $projectId > 0, json_encode($project));

$body = "Robotics safety begins with a hazard assessment before every field test. "
    . "The operator must verify emergency stop access and keep the workspace clear. "
    . "Battery handling requires insulated tools and a fire resistant charging area. "
    . "After each session the team records incidents and reviews the safety checklist.";

$source = http('POST', "{$base}/knowledge/sources", [
    'title'      => 'Robotics safety brief',
    'body'       => $body,
    'kind'       => 'brief',
    'project_id' => $projectId,
], $alice['jar']);

$sourceId = (int) ($source['body']['source']['id'] ?? 0);
$chunks = $source['body']['chunks'] ?? [];

check('M2', 'source stored with chunks', $source['status'] === 201 && $sourceId > 0 && count($chunks) >= 1,
    json_encode($source['body']));
check('M2', 'source links a resource row', (int) ($source['body']['source']['resource_id'] ?? 0) > 0);

$search = http('POST', "{$base}/knowledge/search", [
    'query'      => 'emergency stop battery safety',
    'project_id' => $projectId,
], $alice['jar']);

$results = $search['body']['results'] ?? [];
check('M2', 'lexical retrieval ranks grounded chunks', $search['status'] === 200 && $results !== [],
    json_encode($search['body']));
check('M2', 'search result carries provenance and score',
    isset($results[0]['source_id'], $results[0]['score'], $results[0]['resource_id'])
        && (int) $results[0]['source_id'] === $sourceId);

$bobSource = http('GET', "{$base}/knowledge/sources/{$sourceId}", [], $bob['jar']);
check('M2', 'non-member cannot read project source', $bobSource['status'] === 403);

$bobSearch = http('POST', "{$base}/knowledge/search", [
    'query'      => 'emergency stop',
    'project_id' => $projectId,
], $bob['jar']);
check('M2', 'non-member cannot search project scope', $bobSearch['status'] === 422);

// ------------------------------------------------------------------ M3
echo "\nM3  Quizzes and attempts\n";

$quiz = http('POST', "{$base}/projects/{$projectId}/generate", ['type' => 'quiz'], $alice['jar']);
$quizId = (int) ($quiz['body']['id'] ?? 0);
$questions = $quiz['body']['content']['questions'] ?? [];

check('M3', 'quiz artifact generated', $quiz['status'] === 201 && $quiz['body']['module'] === 'M3',
    json_encode($quiz['body']));
check('M3', 'quiz has grounded MCQs', $questions !== [] && count($questions) <= 5
    && isset($questions[0]['options'], $questions[0]['answer_index'], $questions[0]['source_refs']));

$attempt = http('POST', "{$base}/artifacts/{$quizId}/attempts", [], $alice['jar']);
$attemptId = (int) ($attempt['body']['attempt']['id'] ?? 0);
check('M3', 'attempt starts with empty answers', $attempt['status'] === 201 && $attemptId > 0
    && count($attempt['body']['attempt']['answers']) === count($questions), json_encode($attempt['body']));

$last = null;

foreach ($questions as $index => $question) {
    $last = http('POST', "{$base}/attempts/{$attemptId}/answers", [
        'question_index' => $index,
        'option_index'   => (int) $question['answer_index'],
    ], $alice['jar']);
}

check('M3', 'all-correct attempt scores full marks',
    $last !== null && ($last['body']['complete'] ?? false) === true
        && (int) ($last['body']['score'] ?? -1) === count($questions)
        && (int) ($last['body']['attempt']['score'] ?? -1) === count($questions),
    json_encode($last['body'] ?? []));

$bobAttempt = http('GET', "{$base}/attempts/{$attemptId}", [], $bob['jar']);
check('M3', 'attempts are private to their owner', $bobAttempt['status'] === 404);

// ------------------------------------------------------------------ M4
echo "\nM4  Lessons and progress\n";

$lesson = http('POST', "{$base}/projects/{$projectId}/generate", ['type' => 'lesson'], $alice['jar']);
$lessonId = (int) ($lesson['body']['id'] ?? 0);
$steps = $lesson['body']['content']['steps'] ?? [];

check('M4', 'lesson artifact generated', $lesson['status'] === 201 && $lesson['body']['module'] === 'M4');
check('M4', 'lesson has ordered steps with key points', $steps !== []
    && isset($steps[0]['title'], $steps[0]['body'], $steps[0]['key_points'], $steps[0]['source_refs']));

$progress = http('POST', "{$base}/artifacts/{$lessonId}/progress", [
    'position'  => 1,
    'completed' => false,
], $alice['jar']);
check('M4', 'lesson progress persists', $progress['status'] === 200
    && (int) ($progress['body']['progress']['position'] ?? -1) === 1);

$sourceQuiz = http('POST', "{$base}/knowledge/sources/{$sourceId}/generate", ['type' => 'quiz'], $alice['jar']);
$sourceQuizId = (int) ($sourceQuiz['body']['id'] ?? 0);
check('M2', 'source-scoped artifact generated', $sourceQuiz['status'] === 201 && $sourceQuizId > 0);
$bobSourceQuiz = http('GET', "{$base}/app/learn/{$sourceQuizId}", [], $bob['jar']);
check('M2', 'shared-source artifact stays private before membership', $bobSourceQuiz['status'] === 404);

// ------------------------------------------------------------------ M5/M7
echo "\nM5/M7  Tasks and report composition\n";

$task = http('POST', "{$base}/projects/{$projectId}/tasks", [
    'title'        => 'Run the safety drill',
    'requirements' => [['tag' => 'safety', 'level' => 1]],
    'priority'     => 2,
    'artifact_id'  => $quizId,
    'milestone'    => 'Field test',
    'due_at'       => gmdate('Y-m-d H:i', time() + 86400),
], $alice['jar']);
$taskId = (int) ($task['body']['id'] ?? 0);
check('M5', 'task created with artifact, milestone, and due date', $task['status'] === 201 && $taskId > 0
    && (int) ($task['body']['artifact_id'] ?? 0) === $quizId
    && ($task['body']['milestone'] ?? null) === 'Field test'
    && !empty($task['body']['due_at']));

$dependent = http('POST', "{$base}/projects/{$projectId}/tasks", [
    'title'               => 'Review drill notes',
    'milestone'           => 'Field test',
    'depends_on_task_id'  => $taskId,
], $alice['jar']);
$dependentId = (int) ($dependent['body']['id'] ?? 0);
check('M5', 'task can depend on another task in the project', $dependent['status'] === 201
    && $dependentId > 0 && (int) ($dependent['body']['depends_on_task_id'] ?? 0) === $taskId);

$profile = http('PUT', "{$base}/profiles/{$alice['id']}", [
    'max_open_tasks' => 5,
    'availability'   => 'available',
], $alice['jar']);
$capability = http('POST', "{$base}/profiles/{$alice['id']}/capabilities", [
    'tag'   => 'safety',
    'level' => 3,
], $alice['jar']);
$assigned = http('POST', "{$base}/tasks/{$taskId}/assign", [], $alice['jar']);
check('M5', 'best-fit assignment uses capability profile',
    $profile['status'] === 200 && $capability['status'] === 201
        && ($assigned['body']['state'] ?? '') === 'assigned'
        && (int) ($assigned['body']['assigned_to'] ?? 0) === $alice['id'],
    json_encode([$profile, $capability, $assigned]));

$profilePage = http('GET', $base . '/app/profile', [], $alice['jar']);
check('M5', 'capability profile page renders', $profilePage['status'] === 200
    && str_contains($profilePage['raw'], 'Your capability profile'));

$report = http('POST', "{$base}/projects/{$projectId}/generate", ['type' => 'report'], $alice['jar']);
$reportId = (int) ($report['body']['id'] ?? 0);
$sections = $report['body']['content']['sections'] ?? [];
$citations = $report['body']['content']['citations'] ?? [];

check('M7', 'report artifact generated', $report['status'] === 201 && $report['body']['module'] === 'M7');
check('M7', 'report has sections and citations', $sections !== [] && $citations !== [],
    json_encode($report['body']));
check('M7', 'report cites upstream artifacts and sources',
    in_array('artifact', array_column($citations, 'kind'), true)
        && in_array('source', array_column($citations, 'kind'), true));
$delivery = array_values(array_filter(
    $sections,
    static fn (array $section): bool => ($section['heading'] ?? '') === 'Delivery progress',
))[0] ?? [];
check('M7', 'report counts open work accurately',
    str_contains((string) ($delivery['body'] ?? ''), '2 open'), json_encode($delivery));

// ------------------------------------------------------------------ M6
echo "\nM6  Activities, RSVP, teams, submissions\n";

$activities = new ActivityRepository($pdo);
$eventId = $activities->create($projectId, $alice['id'], [
    'kind'        => 'event',
    'title'       => 'Safety seminar',
    'description' => 'Guest speaker on field robotics.',
    'starts_at'   => gmdate('Y-m-d H:i', time() + 86400),
    'ends_at'     => gmdate('Y-m-d H:i', time() + 90000),
    'capacity'    => 1,
    'meta'        => ['speaker_name' => 'Dr. Rivera'],
]);
$hackId = $activities->create($projectId, $alice['id'], [
    'kind'        => 'hackathon',
    'title'       => 'Safety hacks',
    'description' => 'Build safer field workflows.',
    'starts_at'   => gmdate('Y-m-d H:i', time() + 172800),
    'meta'        => ['team_size_max' => 4, 'tracks' => 'hardware, software'],
]);

$event = $activities->find($eventId);
$hack = $activities->find($hackId);
check('M6', 'event and hackathon persisted', $event !== null && $hack !== null
    && $event['kind'] === 'event' && $hack['kind'] === 'hackathon');

$activities->updateStatus($eventId, 'completed');
$activities->updateStatus($eventId, 'completed');
check('M6', 'activity status can be updated idempotently',
    ($activities->find($eventId)['status'] ?? null) === 'completed');

$statusA = $activities->rsvp($eventId, $alice['id']);
$statusB = $activities->rsvp($eventId, $bob['id']);
$statusA2 = $activities->rsvp($eventId, $alice['id']);
$counts = $activities->rsvpCounts($eventId, $alice['id']);
check('M6', 'capacity overflows RSVP to waitlist',
    $statusA === 'going' && $statusB === 'waitlist' && $statusA2 === 'going'
        && $counts['going'] === 1 && $counts['waitlist'] === 1,
    json_encode($counts));

$teamId = $activities->createTeam($hackId, 'Team Safety', $alice['id']);
$activities->joinTeam($teamId, $bob['id']);
$teams = $activities->teams($hackId);
$submissionId = $activities->addSubmission(
    $hackId,
    $alice['id'],
    'Guardian bot',
    'A field robot with automatic hazard callouts.',
    $teamId,
);
$submissions = $activities->submissions($hackId);

check('M6', 'team has two members', $teams !== [] && (int) $teams[0]['member_count'] === 2,
    json_encode($teams));
$submissionArtifactId = (int) ($submissions[0]['artifact_id'] ?? 0);
check('M6', 'hackathon submission recorded with team and artifact', $submissionId > 0
    && $submissions !== [] && (int) $submissions[0]['team_id'] === $teamId
    && $submissionArtifactId > 0);

$smallHackId = $activities->create($projectId, $alice['id'], [
    'kind' => 'hackathon',
    'title' => 'One-person safety sprint',
    'meta' => ['team_size_max' => 1],
]);
$smallTeamId = $activities->createTeam($smallHackId, 'Solo Safety', $alice['id']);
$fullTeamRejected = false;

try {
    $activities->joinTeam($smallTeamId, $bob['id']);
} catch (\InvalidArgumentException $e) {
    $fullTeamRejected = str_contains($e->getMessage(), 'full');
}

check('M6', 'team size limit is enforced', $fullTeamRejected);

$report2 = http('POST', "{$base}/projects/{$projectId}/generate", ['type' => 'report'], $alice['jar']);
$activityCitations = array_values(array_filter(
    $report2['body']['content']['citations'] ?? [],
    static fn (array $c): bool => ($c['kind'] ?? '') === 'activity',
));
check('M7', 'regenerated report cites activities', $report2['status'] === 201 && $activityCitations !== [],
    json_encode($report2['body']['content']['citations'] ?? []));

// ------------------------------------------------------------------ M8
echo "\nM8  Delivery surface\n";

$pages = [
    '/app'                          => 200,
    '/app/projects'                 => 200,
    "/app/projects/{$projectId}"     => 200,
    '/app/library'                  => 200,
    "/app/sources/{$sourceId}"       => 200,
    "/app/activities/{$eventId}"     => 200,
    "/app/activities/{$hackId}"      => 200,
    "/app/artifacts/{$reportId}"     => 200,
    "/app/learn/{$lessonId}"         => 200,
];

foreach ($pages as $path => $expected) {
    $page = http('GET', $base . $path, [], $alice['jar']);
    check('M8', "GET {$path}", $page['status'] === $expected, $page['status'] . ' ' . substr($page['raw'], 0, 200));
}

$attemptPage = http('GET', "{$base}/app/attempts/{$attemptId}", [], $alice['jar']);
check('M8', 'quiz summary renders', $attemptPage['status'] === 200 && str_contains($attemptPage['raw'], 'Score:'));

$csrf = http('POST', $base . '/app/projects', ['title' => 'No token'], $alice['jar']);
check('M8', 'web POST rejects missing CSRF', $csrf['status'] === 419);

$bobProjectPage = http('GET', $base . "/app/projects/{$projectId}", [], $bob['jar']);
check('M8', 'non-member web project access is forbidden', $bobProjectPage['status'] === 403);

$aliceProjectPage = http('GET', $base . "/app/projects/{$projectId}", [], $alice['jar']);
preg_match('/name="_csrf" value="([a-f0-9]+)"/', $aliceProjectPage['raw'], $csrfMatch);
$memberAdd = httpForm('POST', $base . "/app/projects/{$projectId}/members", [
    '_csrf' => $csrfMatch[1] ?? '',
    'email' => $bob['email'],
    'role'  => 'member',
], $alice['jar']);
$bobProjectPage2 = http('GET', $base . "/app/projects/{$projectId}", [], $bob['jar']);
$bobSourceQuiz2 = http('GET', $base . "/app/learn/{$sourceQuizId}", [], $bob['jar']);
check('M5', 'web member invite adds project access',
    $memberAdd['status'] === 302 && $bobProjectPage2['status'] === 200);
check('M2', 'project member can read artifact from shared source', $bobSourceQuiz2['status'] === 200);

$manualTask = http('POST', "{$base}/projects/{$projectId}/tasks", [
    'title' => 'Inspect the charging bay',
], $alice['jar']);
$manualTaskId = (int) ($manualTask['body']['id'] ?? 0);
$manualAssign = httpForm('POST', $base . "/app/tasks/{$manualTaskId}/assign-manual", [
    '_csrf'   => $csrfMatch[1] ?? '',
    'user_id' => $bob['id'],
], $alice['jar']);
$manualTaskAfter = http('GET', $base . "/tasks/{$manualTaskId}", [], $alice['jar']);
$statusUpdate = httpForm('POST', $base . "/app/tasks/{$manualTaskId}/status", [
    '_csrf'  => $csrfMatch[1] ?? '',
    'status' => 'in_progress',
], $alice['jar']);
$manualTaskAfter2 = http('GET', $base . "/tasks/{$manualTaskId}", [], $alice['jar']);
check('M5', 'web manual assignment overrides best fit',
    $manualAssign['status'] === 302
        && (int) ($manualTaskAfter['body']['assignee_id'] ?? 0) === $bob['id']);
check('M5', 'assignee or manager can update task status',
    $statusUpdate['status'] === 302 && ($manualTaskAfter2['body']['status'] ?? '') === 'in_progress');

$projectStatus = httpForm('POST', $base . "/app/projects/{$projectId}/status", [
    '_csrf'  => $csrfMatch[1] ?? '',
    'status' => 'completed',
], $alice['jar']);
check('M5', 'manager can update project status', $projectStatus['status'] === 302
    && (new ProjectRepository($pdo))->find($projectId)['status'] === 'completed');
(new ProjectRepository($pdo))->updateStatus($projectId, 'active');

$activityEdit = httpForm('POST', $base . "/app/activities/{$eventId}/edit", [
    '_csrf'     => $csrfMatch[1] ?? '',
    'title'     => 'Safety seminar — updated',
    'starts_at' => gmdate('Y-m-d\TH:i', time() + 90000),
    'ends_at'   => gmdate('Y-m-d\TH:i', time() + 93600),
], $alice['jar']);
check('M6', 'manager can edit activity details', $activityEdit['status'] === 302
    && ($activities->find($eventId)['title'] ?? '') === 'Safety seminar — updated');

$searchPage = http('GET', $base . '/app/library?q=' . rawurlencode('emergency stop'), [], $alice['jar']);
check('M8', 'library search page renders grounded results', $searchPage['status'] === 200
    && str_contains($searchPage['raw'], 'Search results')
    && str_contains($searchPage['raw'], 'Robotics safety brief'));

$loginPage = http('GET', $base . '/login');
check('M8', 'login page renders for guests', $loginPage['status'] === 200
    && str_contains($loginPage['raw'], 'Welcome back'));

// ------------------------------------------------------------------ summary
echo "\n" . str_repeat('=', 60) . "\n";
printf("  %d passed, %d failed\n\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
