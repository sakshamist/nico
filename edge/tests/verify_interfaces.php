#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Slice A acceptance checks: admin assigns material → intern learns → admin sees progress.
 */

require __DIR__ . '/../autoload.php';

use Nico\Edge\Auth\UserRepository;
use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;

putenv('OLLAMA_ENABLED=0');
Config::boot(__DIR__ . '/../.env');

$base = getenv('NICO_BASE_URL') ?: 'http://127.0.0.1:8080';

if (@file_get_contents($base . '/health') === false) {
    fwrite(STDERR, "Edge is not reachable at {$base}. Start it with ./dev.sh serve.\n");
    exit(2);
}

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        printf("  \033[32mPASS\033[0m  %s\n", $label);
        return;
    }
    $failed++;
    printf("  \033[31mFAIL\033[0m  %s\n", $label);
    if ($detail !== '') {
        printf("        %s\n", substr($detail, 0, 400));
    }
}

/** @return array{status:int,body:array,text:string,headers:string} */
function http(string $method, string $url, ?array $body = null, ?string $jar = null): array
{
    $ch = curl_init($url);
    $headers = [];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    if ($headers !== []) {
        $options[CURLOPT_HTTPHEADER] = $headers;
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
    return [
        'status'  => $status,
        'body'    => is_array($decoded) ? $decoded : [],
        'text'    => $payload,
        'headers' => substr($raw, 0, $headerSize),
    ];
}

/** @return array{status:int,text:string,headers:string} */
function httpForm(string $url, array $fields, ?string $jar = null): array
{
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
    ];
    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }
    curl_setopt_array($ch, $options);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [
        'status'  => $status,
        'text'    => substr($raw, $headerSize),
        'headers' => substr($raw, 0, $headerSize),
    ];
}

/** @return array{status:int,text:string,headers:string} */
function httpUpload(string $url, array $fields, string $path, ?string $jar = null): array
{
    $ch = curl_init($url);
    $fields['pdf'] = new CURLFile($path, 'application/pdf', 'course.pdf');
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => $fields,
    ];
    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }
    curl_setopt_array($ch, $options);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [
        'status'  => $status,
        'text'    => substr($raw, $headerSize),
        'headers' => substr($raw, 0, $headerSize),
    ];
}

function csrfFrom(string $html): string
{
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m);
    return $m[1] ?? '';
}

function pdfFixture(string $text): string
{
    $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    $stream = "BT /F1 12 Tf 72 720 Td ({$escaped}) Tj ET";
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        4 => '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($n = 1; $n <= count($objects); $n++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
    }
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

echo "\nInterfaces — Slice A\n";
echo str_repeat('=', 60) . "\n\n";

$pdo = Database::pdo();
$users = new UserRepository();
$adminEmail = 'slice_admin_' . bin2hex(random_bytes(4)) . '@test.local';
$adminPassword = 'correct-horse-battery';
$adminId = $users->create($adminEmail, $adminPassword, 'admin');
$adminJar = (string) tempnam(sys_get_temp_dir(), 'slice_admin_');
http('POST', "$base/auth/login", ['email' => $adminEmail, 'password' => $adminPassword], $adminJar);

$internEmail = 'slice_intern_' . bin2hex(random_bytes(4)) . '@test.local';
$internJar = (string) tempnam(sys_get_temp_dir(), 'slice_intern_');
$internReg = http('POST', "$base/auth/register", ['email' => $internEmail, 'password' => $adminPassword], $internJar);
$internId = (int) ($internReg['body']['id'] ?? 0);
http('POST', "$base/auth/login", ['email' => $internEmail, 'password' => $adminPassword], $internJar);

$otherJar = (string) tempnam(sys_get_temp_dir(), 'slice_other_');
$otherEmail = 'slice_other_' . bin2hex(random_bytes(4)) . '@test.local';
$otherReg = http('POST', "$base/auth/register", ['email' => $otherEmail, 'password' => $adminPassword], $otherJar);
$otherId = (int) ($otherReg['body']['id'] ?? 0);
http('POST', "$base/auth/login", ['email' => $otherEmail, 'password' => $adminPassword], $otherJar);

$adminList = http('GET', "$base/admin/interns", null, $adminJar);
$adminListAsIntern = http('GET', "$base/admin/interns", null, $internJar);
$internHome = http('GET', "$base/intern", null, $internJar);
$internHomeAsAdmin = http('GET', "$base/intern", null, $adminJar);

check('admin can open interns', $adminList['status'] === 200 && str_contains($internEmail, '@'), (string) $adminList['status']);
check('intern cannot open admin area', $adminListAsIntern['status'] === 403, (string) $adminListAsIntern['status']);
check('intern has a clean home', $internHome['status'] === 200 && str_contains($internHome['text'], 'Welcome back'), (string) $internHome['status']);
check('admin cannot open intern home', $internHomeAsAdmin['status'] === 403, (string) $internHomeAsAdmin['status']);

$detail = http('GET', "$base/admin/interns/$internId?tab=material", null, $adminJar);
$csrf = csrfFrom($detail['text']);
$material = httpForm("$base/admin/interns/$internId/material", [
    '_csrf' => $csrf,
    'title' => 'Intern safety notes',
    'kind'  => 'note',
    'body'  => 'Emergency stop access must be checked before every field test.',
], $adminJar);
$assignment = $pdo->prepare('SELECT source_id FROM intern_material_assignments WHERE intern_id = :id');
$assignment->execute(['id' => $internId]);
$sourceId = (int) $assignment->fetchColumn();

check('admin can assign written material', $material['status'] === 302 && $sourceId > 0, (string) $material['status']);

$course = http('GET', "$base/intern/course", null, $internJar);
check('intern sees assigned material', $course['status'] === 200
    && str_contains($course['text'], 'Intern safety notes'), (string) $course['status']);
check('intern UI has no module labels', !preg_match('/\bM[0-8]\b/', $course['text']));

$lessonGen = httpForm("$base/admin/interns/$internId/sources/$sourceId/generate", [
    '_csrf' => $csrf,
    'type'  => 'lesson',
], $adminJar);
$artifactStmt = $pdo->prepare("SELECT id FROM artifacts WHERE resource_id = (SELECT resource_id FROM knowledge_sources WHERE id = :source) AND type = 'lesson' ORDER BY id DESC LIMIT 1");
$artifactStmt->execute(['source' => $sourceId]);
$lessonId = (int) $artifactStmt->fetchColumn();

check('admin can generate a lesson', $lessonGen['status'] === 302 && $lessonId > 0, (string) $lessonGen['status']);

$lessonPage = http('GET', "$base/intern/learn/$lessonId", null, $internJar);
check('intern can open the lesson', $lessonPage['status'] === 200 && str_contains($lessonPage['text'], 'Lesson'), (string) $lessonPage['status']);

$lessonCsrf = csrfFrom($lessonPage['text']);
$progressPost = httpForm("$base/intern/learn/$lessonId/progress", [
    '_csrf'    => $lessonCsrf,
    'position' => '0',
    'completed' => '1',
], $internJar);
$adminProgress = http('GET', "$base/admin/interns/$internId?tab=progress", null, $adminJar);
check('admin sees lesson progress', $progressPost['status'] === 302
    && $adminProgress['status'] === 200
    && str_contains($adminProgress['text'], 'completed'), (string) $adminProgress['status']);

$quizGen = httpForm("$base/admin/interns/$internId/sources/$sourceId/generate", [
    '_csrf' => $csrf,
    'type'  => 'quiz',
], $adminJar);
$quizStmt = $pdo->prepare("SELECT id, content FROM artifacts WHERE resource_id = (SELECT resource_id FROM knowledge_sources WHERE id = :source) AND type = 'quiz' ORDER BY id DESC LIMIT 1");
$quizStmt->execute(['source' => $sourceId]);
$quizRow = $quizStmt->fetch();
$quizId = (int) ($quizRow['id'] ?? 0);
$quizContent = json_decode((string) ($quizRow['content'] ?? ''), true);
$questions = $quizContent['questions'] ?? [];

check('admin can generate a quiz', $quizGen['status'] === 302 && $quizId > 0 && $questions !== [], (string) $quizGen['status']);

$quizIntro = http('GET', "$base/intern/learn/$quizId", null, $internJar);
$quizCsrf = csrfFrom($quizIntro['text']);
$start = httpForm("$base/intern/artifacts/$quizId/start", ['_csrf' => $quizCsrf], $internJar);
preg_match('#/intern/attempts/(\d+)#', $start['headers'], $attemptMatch);
$attemptId = (int) ($attemptMatch[1] ?? 0);

foreach ($questions as $index => $question) {
    httpForm("$base/intern/attempts/$attemptId/answer", [
        '_csrf'          => $quizCsrf,
        'question_index' => (string) $index,
        'option_index'   => (string) (int) $question['answer_index'],
    ], $internJar);
}
$attemptPage = http('GET', "$base/intern/attempts/$attemptId", null, $internJar);
$adminProgress = http('GET', "$base/admin/interns/$internId?tab=progress", null, $adminJar);
check('intern can take quiz and admin sees result', $attemptPage['status'] === 200
    && str_contains($attemptPage['text'], 'Score:')
    && str_contains($adminProgress['text'], 'Quiz'), (string) $attemptPage['status']);

$otherLesson = http('GET', "$base/intern/learn/$lessonId", null, $otherJar);
check('other intern cannot see assigned learning', $otherLesson['status'] === 404, (string) $otherLesson['status']);

$pdfPath = (string) tempnam(sys_get_temp_dir(), 'slice_pdf_');
file_put_contents($pdfPath, pdfFixture('PDF course material for the intern.'));
$pdfUpload = httpUpload("$base/admin/interns/$internId/material/pdf", [
    '_csrf'          => $csrf,
    'title'          => 'Intern PDF',
    'extracted_text' => 'PDF course material for the intern.',
    'page_segments'  => json_encode([['page' => 1, 'text' => 'PDF course material for the intern.']]),
], $pdfPath, $adminJar);
$course = http('GET', "$base/intern/course", null, $internJar);
check('admin can assign a PDF', $pdfUpload['status'] === 302
    && str_contains($course['text'], 'Intern PDF'), (string) $pdfUpload['status']);
@unlink($pdfPath);

$materialPage = http('GET', "$base/admin/material", null, $adminJar);
$materialCsrf = csrfFrom($materialPage['text']);
$sharedTitle = 'Shared safety brief';
$sharedUpload = httpForm("$base/admin/material", [
    '_csrf'      => $materialCsrf,
    'title'      => $sharedTitle,
    'kind'       => 'brief',
    'body'       => 'Every intern should read this shared safety brief.',
    'intern_ids' => [(string) $internId, (string) $otherId],
], $adminJar);
$sharedStmt = $pdo->prepare('SELECT source_id FROM intern_material_assignments WHERE intern_id = :id ORDER BY source_id DESC LIMIT 1');
$sharedStmt->execute(['id' => $internId]);
$sharedSourceId = (int) $sharedStmt->fetchColumn();
$internCourse = http('GET', "$base/intern/course", null, $internJar);
$otherCourse = http('GET', "$base/intern/course", null, $otherJar);
check('material can be assigned to multiple interns at once', $sharedUpload['status'] === 302
    && $sharedSourceId > 0
    && str_contains($internCourse['text'], $sharedTitle)
    && str_contains($otherCourse['text'], $sharedTitle), (string) $sharedUpload['status']);

$sharedGenerate = httpForm("$base/admin/interns/$internId/sources/$sharedSourceId/generate", [
    '_csrf' => $csrf,
    'type'  => 'lesson',
], $adminJar);
$materialPage = http('GET', "$base/admin/material", null, $adminJar);
$internDetail = http('GET', "$base/admin/interns/$internId?tab=material", null, $adminJar);
check('admin sees material and generated learning in both places', $sharedGenerate['status'] === 302
    && $materialPage['status'] === 200
    && $internDetail['status'] === 200
    && str_contains($materialPage['text'], $sharedTitle)
    && str_contains($materialPage['text'], 'Generated material')
    && str_contains($internDetail['text'], 'Generated material'),
    'gen=' . $sharedGenerate['status']
        . ' material_page=' . $materialPage['status']
        . ' intern_detail=' . $internDetail['status']
        . ' has_shared=' . (int) str_contains($materialPage['text'], $sharedTitle)
        . ' has_generated=' . (int) str_contains($materialPage['text'], 'Generated material')
        . ' detail_generated=' . (int) str_contains($internDetail['text'], 'Generated material'));

$editAssignments = httpForm("$base/admin/material/$sharedSourceId/assignments", [
    '_csrf'      => $materialCsrf,
    'intern_ids' => [(string) $internId],
], $adminJar);
$otherCourse = http('GET', "$base/intern/course", null, $otherJar);
check('assignments can be changed later', $editAssignments['status'] === 302
    && !str_contains($otherCourse['text'], $sharedTitle), (string) $editAssignments['status']);

$scopeTitle = 'Scope test brief';
httpForm("$base/admin/material", [
    '_csrf'      => $materialCsrf,
    'title'      => $scopeTitle,
    'kind'       => 'brief',
    'body'       => 'Shared material used to check generation scope. Emergency stop access must be checked before every field test. Battery charging requires insulated tools and a fire resistant area. After each session the team records incidents and reviews the safety checklist. Operators must keep the workspace clear and report hazards immediately.',
    'intern_ids' => [(string) $internId, (string) $otherId],
], $adminJar);
$scopeStmt = $pdo->prepare('SELECT id FROM knowledge_sources WHERE title = :title ORDER BY id DESC LIMIT 1');
$scopeStmt->execute(['title' => $scopeTitle]);
$scopeSourceId = (int) $scopeStmt->fetchColumn();

$sharedLesson = httpForm("$base/admin/material/$scopeSourceId/generate", [
    '_csrf' => $materialCsrf,
    'type'  => 'lesson',
    'scope' => 'shared',
], $adminJar);
$internCourse = http('GET', "$base/intern/course", null, $internJar);
$otherCourse = http('GET', "$base/intern/course", null, $otherJar);
check('shared generation is visible to every assigned intern', $sharedLesson['status'] === 302
    && str_contains($internCourse['text'], 'Lesson: ' . $scopeTitle)
    && str_contains($otherCourse['text'], 'Lesson: ' . $scopeTitle), (string) $sharedLesson['status']);

$perInternQuiz = httpForm("$base/admin/material/$scopeSourceId/generate", [
    '_csrf' => $materialCsrf,
    'type'  => 'quiz',
    'scope' => 'per_intern',
], $adminJar);
$internCourse = http('GET', "$base/intern/course", null, $internJar);
$otherCourse = http('GET', "$base/intern/course", null, $otherJar);
$perInternStmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT aa.artifact_id) AS copies,
            COUNT(*) AS assignments
       FROM intern_artifact_assignments aa
       JOIN artifacts a ON a.id = aa.artifact_id
      WHERE a.type = "quiz"
        AND a.resource_id = (SELECT resource_id FROM knowledge_sources WHERE id = :source)'
);
$perInternStmt->execute(['source' => $scopeSourceId]);
$perInternRows = $perInternStmt->fetch();
check('per-intern generation creates one copy per assigned intern', $perInternQuiz['status'] === 302
    && (int) ($perInternRows['copies'] ?? 0) === 2
    && (int) ($perInternRows['assignments'] ?? 0) === 2
    && str_contains($internCourse['text'], 'Quiz: ' . $scopeTitle)
    && str_contains($internCourse['text'], 'Quiz: ' . $scopeTitle)
    && str_contains($otherCourse['text'], 'Quiz: ' . $scopeTitle),
    json_encode($perInternRows) . ' intern=' . (int) str_contains($internCourse['text'], 'Quiz: ' . $scopeTitle)
        . ' other=' . (int) str_contains($otherCourse['text'], 'Quiz: ' . $scopeTitle));

$reportGeneration = httpForm("$base/admin/material/$scopeSourceId/generate", [
    '_csrf' => $materialCsrf,
    'type'  => 'report',
    'scope' => 'shared',
], $adminJar);
$materialPage = http('GET', "$base/admin/material", null, $adminJar);
check('admin can generate a report from shared material', $reportGeneration['status'] === 302
    && str_contains($materialPage['text'], 'Report: ' . $scopeTitle), (string) $reportGeneration['status']);

$internMessage = httpForm("$base/intern/messages", [
    '_csrf' => $quizCsrf,
    'body'  => 'Hello admin, I finished the first lesson.',
], $internJar);
$adminThread = http('GET', "$base/admin/interns/$internId?tab=messages", null, $adminJar);
check('intern can message admin', $internMessage['status'] === 302
    && str_contains($adminThread['text'], 'I finished the first lesson.'), (string) $internMessage['status']);

$adminMessage = httpForm("$base/admin/interns/$internId/messages", [
    '_csrf' => $materialCsrf,
    'body'  => 'Well done. Keep going.',
], $adminJar);
$internThread = http('GET', "$base/intern/messages", null, $internJar);
check('admin can reply to intern', $adminMessage['status'] === 302
    && str_contains($internThread['text'], 'Well done. Keep going.'), (string) $adminMessage['status']);

$notePost = httpForm("$base/intern/notes", [
    '_csrf' => $quizCsrf,
    'title' => 'My private note',
    'body'  => 'Remember to review the safety checklist.',
], $internJar);
$internCourse = http('GET', "$base/intern/course", null, $internJar);
check('intern can keep private notes', $notePost['status'] === 302
    && str_contains($internCourse['text'], 'My private note'), (string) $notePost['status']);

$projectTitle = 'Shared live project';
$projectPost = httpForm("$base/admin/projects", [
    '_csrf'      => $materialCsrf,
    'title'      => $projectTitle,
    'kind'       => 'live',
    'mode'       => 'shared',
    'intern_ids' => [(string) $internId, (string) $otherId],
], $adminJar);
preg_match('#/admin/projects/(\d+)#', $projectPost['headers'], $projectMatch);
$projectId = (int) ($projectMatch[1] ?? 0);
$projectList = http('GET', "$base/admin/projects", null, $adminJar);
check('admin can create a shared project for multiple interns', $projectPost['status'] === 302
    && $projectId > 0
    && str_contains($projectList['text'], $projectTitle), (string) $projectPost['status']);

$taskPost = httpForm("$base/admin/projects/$projectId/tasks", [
    '_csrf'      => $materialCsrf,
    'title'      => 'Inspect the charging bay',
    'milestone'  => 'Week one',
    'assignee_id' => (string) $internId,
], $adminJar);
$internProjects = http('GET', "$base/intern/projects", null, $internJar);
preg_match('/tasks\/(\d+)\/status/', $internProjects['text'], $taskMatch);
$taskId = (int) ($taskMatch[1] ?? 0);
check('intern sees assigned project task', $taskPost['status'] === 302
    && $taskId > 0
    && str_contains($internProjects['text'], 'Inspect the charging bay'), (string) $taskPost['status']);

$taskDone = httpForm("$base/intern/tasks/$taskId/status", [
    '_csrf' => $quizCsrf,
    'status' => 'done',
], $internJar);
$adminProject = http('GET', "$base/admin/projects/$projectId", null, $adminJar);
check('admin sees intern task completion', $taskDone['status'] === 302
    && str_contains($adminProject['text'], 'done'), (string) $taskDone['status']);

echo "\n" . str_repeat('=', 60) . "\n";
printf("  %d passed, %d failed\n\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
