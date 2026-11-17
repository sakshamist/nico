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
use Nico\Edge\Ai\AiClientInterface;
use Nico\Edge\Artifacts\ArtifactRepository;
use Nico\Edge\Generation\AiContentService;
use Nico\Edge\Generation\LessonService;
use Nico\Edge\Generation\QuizService;
use Nico\Edge\Projects\MembershipRepository;
use Nico\Edge\Knowledge\EmbeddingIndexer;
use Nico\Edge\Knowledge\HybridSearchService;
use Nico\Edge\Knowledge\KnowledgeRepository;
use Nico\Edge\Knowledge\PdfIngestionService;
use Nico\Edge\Knowledge\PdfTextExtractor;
use Nico\Edge\Knowledge\Storage;
use Nico\Edge\Knowledge\VectorStore;
use Nico\Edge\Projects\ProjectRepository;
use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;
use Nico\Edge\Support\Migrator;

putenv('OLLAMA_ENABLED=0');
Config::boot(__DIR__ . '/../.env');

$base = getenv('NICO_BASE_URL') ?: 'http://127.0.0.1:8080';

if (@file_get_contents($base . '/health') === false) {
    fwrite(STDERR, "Edge is not reachable at {$base}. Start it with ./dev.sh serve.\n");
    exit(2);
}

class UnavailablePdfTextExtractor extends PdfTextExtractor
{
    public function extract(string $absolutePath): ?string
    {
        return null;
    }

    /** @return list<array{page:int,text:string}>|null */
    public function extractSegments(string $absolutePath): ?array
    {
        return null;
    }
}

class FakeAiClient implements AiClientInterface
{
    private ?string $chatResponse = null;

    public function setChatResponse(?string $response): void
    {
        $this->chatResponse = $response;
    }

    public function available(): bool
    {
        return true;
    }

    public function embed(string $text): ?array
    {
        $seed = unpack('N', hash('sha256', $text, true))[1];
        $vector = [];
        for ($i = 0; $i < 8; $i++) {
            $vector[] = (($seed >> $i) & 1) === 1 ? 1.0 : 0.01;
        }

        return $vector;
    }

    public function chat(array $messages, array $options = []): ?string
    {
        return $this->chatResponse;
    }
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

    return [
        'status'  => $status,
        'body'    => is_array($decoded) ? $decoded : [],
        'raw'     => $payload,
        'text'    => $payload,
        'headers' => substr($raw, 0, $headerSize),
    ];
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

    return [
        'status'  => $status,
        'body'    => is_array($decoded) ? $decoded : [],
        'raw'     => $payload,
        'text'    => $payload,
        'headers' => substr($raw, 0, $headerSize),
    ];
}

/** @return array{status:int,body:array,raw:string} */
function httpUpload(
    string $url,
    array $fields,
    string $filePath,
    string $fileName,
    string $mime,
    ?string $jar = null,
): array {
    $ch = curl_init($url);
    $fields['pdf'] = new CURLFile($filePath, $mime, $fileName);
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
    $payload = substr($raw, $headerSize);
    $decoded = json_decode($payload, true);

    return [
        'status'  => $status,
        'body'    => is_array($decoded) ? $decoded : [],
        'raw'     => $raw,
        'text'    => $payload,
        'headers' => substr($raw, 0, $headerSize),
    ];
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

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

    return $pdf;
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

$source2 = http('POST', "{$base}/knowledge/sources", [
    'title'      => 'Battery handling checklist',
    'body'       => 'Battery handling requires insulated tools and a fire resistant charging area. Label every pack after charging.',
    'kind'       => 'note',
    'project_id' => $projectId,
], $alice['jar']);
$source2Id = (int) ($source2['body']['source']['id'] ?? 0);

$filtered = http('POST', "{$base}/knowledge/search", [
    'query'       => 'battery handling insulated tools',
    'project_id'  => $projectId,
    'source_ids'  => [$source2Id],
], $alice['jar']);
$filteredResults = $filtered['body']['results'] ?? [];
check('M2', 'source_ids filter returns only authorized requested sources', $filtered['status'] === 200
    && $filteredResults !== []
    && array_unique(array_column($filteredResults, 'source_id')) === [$source2Id],
    json_encode($filtered['body']));

$bobPersonal = http('POST', "{$base}/knowledge/sources", [
    'title' => 'Bob private notes',
    'body'  => 'A private note about emergency stop checks.',
    'kind'  => 'note',
], $bob['jar']);
$bobPersonalId = (int) ($bobPersonal['body']['source']['id'] ?? 0);
$unauthorizedFilter = http('POST', "{$base}/knowledge/search", [
    'query'      => 'emergency stop',
    'source_ids' => [$bobPersonalId],
], $alice['jar']);
check('M2', 'source_ids filter rejects unauthorized source',
    $unauthorizedFilter['status'] === 422, (string) $unauthorizedFilter['status']);

check('M2', 'search reports lexical mode when Ollama is disabled',
    ($search['body']['mode'] ?? '') === 'lexical', json_encode($search['body']));

$fakeAi = new FakeAiClient();
$vectorStore = new VectorStore(new Storage((string) Config::get('VECTOR_PATH', 'storage/vectors')));
$indexer = new EmbeddingIndexer($pdo, $fakeAi, $vectorStore);
$indexed = $indexer->index(
    $sourceId,
    (int) ($source['body']['source']['resource_id'] ?? 0),
    (new KnowledgeRepository($pdo, null))->chunks($sourceId),
);
$refCount = (int) $pdo->query('SELECT COUNT(*) FROM knowledge_refs')->fetchColumn();
check('M2', 'embeddings are indexed with model metadata', $indexed > 0 && $refCount >= $indexed,
    'indexed=' . $indexed . ' refs=' . $refCount);

$ranked = (new HybridSearchService($fakeAi, $vectorStore))->rank($results, 'emergency stop');
check('M2', 'hybrid search reranks with vector scores', ($ranked['mode'] ?? '') === 'hybrid'
    && isset($ranked['results'][0]['vector_score']), json_encode($ranked['mode'] ?? null));

// ------------------------------------------------------------------ M1
echo "\nM1  PDF ingestion\n";

$libraryNew = http('GET', $base . '/app/library/new', [], $alice['jar']);
preg_match('/name="_csrf" value="([a-f0-9]+)"/', $libraryNew['raw'], $pdfCsrfMatch);
$pdfCsrf = $pdfCsrfMatch[1] ?? '';

$pdfText = 'SERVER SAFETY PHRASE alpha bravo charlie';
$browserText = 'BROWSER SAFETY PHRASE this should lose to server extraction';
$pdfPath = (string) tempnam(sys_get_temp_dir(), 'nico_pdf_');
file_put_contents($pdfPath, pdfFixture($pdfText));

$pdfUpload = httpUpload(
    $base . '/app/library/pdf',
    [
        '_csrf'         => $pdfCsrf,
        'title'         => 'PDF safety upload',
        'project_id'     => (string) $projectId,
        'extracted_text' => $browserText,
        'page_segments'  => json_encode([['page' => 1, 'text' => $pdfText]]),
    ],
    $pdfPath,
    'safety notes.pdf',
    'application/pdf',
    $alice['jar'],
);

check('M1', 'PDF upload creates a document source', $pdfUpload['status'] === 302,
    $pdfUpload['status'] . ' ' . substr($pdfUpload['text'], 0, 200));

$pdfStmt = $pdo->prepare(
    "SELECT id, resource_id, kind, body FROM knowledge_sources
      WHERE title = 'PDF safety upload' ORDER BY id DESC LIMIT 1"
);
$pdfStmt->execute();
$pdfSource = $pdfStmt->fetch();
$pdfSourceId = (int) ($pdfSource['id'] ?? 0);

$resourceStmt = $pdo->prepare('SELECT source_type, status FROM resources WHERE id = :id LIMIT 1');
$resourceStmt->execute(['id' => (int) ($pdfSource['resource_id'] ?? 0)]);
$pdfResource = $resourceStmt->fetch();

check('M1', 'PDF source is a document backed by a pdf resource', $pdfSourceId > 0
    && ($pdfSource['kind'] ?? '') === 'document'
    && ($pdfResource['source_type'] ?? '') === 'pdf'
    && ($pdfResource['status'] ?? '') === 'done', json_encode([$pdfSource, $pdfResource]));

check('M1', 'server extraction wins over browser text', $pdfSource !== false
    && str_contains((string) $pdfSource['body'], $pdfText)
    && !str_contains((string) $pdfSource['body'], 'BROWSER SAFETY PHRASE'),
    substr((string) ($pdfSource['body'] ?? ''), 0, 200));

$fileStmt = $pdo->prepare(
    "SELECT path, mime, bytes, sha256, original_name FROM files
      WHERE resource_id = :resource AND kind = 'raw' ORDER BY id DESC LIMIT 1"
);
$fileStmt->execute(['resource' => (int) ($pdfSource['resource_id'] ?? 0)]);
$pdfFile = $fileStmt->fetch();
$storage = new Storage();
$absolutePdf = is_array($pdfFile) ? $storage->absolutePath((string) $pdfFile['path']) : '';

check('M1', 'PDF stored outside webroot with checksum and original name', is_array($pdfFile)
    && str_starts_with((string) $pdfFile['path'], 'pdf/')
    && ($pdfFile['original_name'] ?? '') === 'safety notes.pdf'
    && (int) $pdfFile['bytes'] === filesize($pdfPath)
    && $pdfFile['sha256'] === hash_file('sha256', $pdfPath)
    && is_file($absolutePdf)
    && !str_contains($absolutePdf, '/public/'), json_encode($pdfFile));

$pdfChunks = (new KnowledgeRepository($pdo))->chunks($pdfSourceId);
check('M2', 'PDF text is chunked for retrieval', $pdfChunks !== []
    && str_contains(implode(' ', array_column($pdfChunks, 'text')), $pdfText),
    json_encode(array_column($pdfChunks, 'text')));

check('M2', 'PDF chunks carry page provenance', $pdfChunks !== []
    && array_unique(array_column($pdfChunks, 'page')) === [1],
    json_encode(array_column($pdfChunks, 'page')));

$browserSegments = PdfIngestionService::parseSegments(
    json_encode([['page' => 7, 'text' => 'Browser page seven text']]),
    (int) Config::get('PDF_TEXT_MAX_BYTES', 2 * 1024 * 1024),
);
check('M2', 'browser page segments are preserved when server extraction is unavailable',
    $browserSegments !== null
    && ($browserSegments[0]['page'] ?? 0) === 7
    && ($browserSegments[0]['text'] ?? '') === 'Browser page seven text',
    json_encode($browserSegments));

$download = http('GET', $base . "/app/sources/{$pdfSourceId}/download", [], $alice['jar']);
check('M1', 'owner can download the original PDF', $download['status'] === 200
    && str_starts_with($download['text'], '%PDF-')
    && stripos($download['headers'], 'application/pdf') !== false,
    substr($download['headers'], 0, 500));

$guestDownload = http('GET', $base . "/app/sources/{$pdfSourceId}/download", [], $bob['jar']);
check('M1', 'non-member cannot download the PDF', $guestDownload['status'] === 403,
    (string) $guestDownload['status']);

$fallbackService = new PdfIngestionService(null, new UnavailablePdfTextExtractor(), null);
$fallbackText = $fallbackService->resolveText('BROWSER FALLBACK PHRASE', $pdfPath);
check('M1', 'browser text is used when server extraction is unavailable',
    ($fallbackText['extractor'] ?? '') === 'browser'
        && ($fallbackText['body'] ?? '') === 'BROWSER FALLBACK PHRASE',
    json_encode($fallbackText));

$filesBefore = (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
$badPath = (string) tempnam(sys_get_temp_dir(), 'nico_bad_');
file_put_contents($badPath, 'this is not a pdf');
$badUpload = httpUpload(
    $base . '/app/library/pdf',
    [
        '_csrf'          => $pdfCsrf,
        'title'          => 'Invalid PDF upload',
        'extracted_text' => 'Some text that would otherwise be accepted.',
    ],
    $badPath,
    'notes.txt',
    'text/plain',
    $alice['jar'],
);
$filesAfter = (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
$invalidStmt = $pdo->prepare("SELECT COUNT(*) FROM knowledge_sources WHERE title = 'Invalid PDF upload'");
$invalidStmt->execute();

check('M1', 'non-PDF upload leaves no source or file row', $badUpload['status'] === 302
    && $filesAfter === $filesBefore && (int) $invalidStmt->fetchColumn() === 0,
    $badUpload['status'] . ' files ' . $filesBefore . '->' . $filesAfter);

@unlink($pdfPath);
@unlink($badPath);

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

$aiChunks = (new KnowledgeRepository($pdo, null))->chunks($sourceId);
$firstChunkId = (int) ($aiChunks[0]['id'] ?? 0);
$fakeAi->setChatResponse(json_encode([
    'questions' => [[
        'prompt'       => 'What must be checked before a field test?',
        'options'      => ['Hazard assessment', 'Weather app', 'Lunch plan'],
        'answer_index' => 0,
        'explanation'  => 'The source requires a hazard assessment first.',
        'source_refs'  => [['chunk_id' => $firstChunkId]],
    ]],
]));
$aiQuizService = new QuizService(
    new KnowledgeRepository($pdo, null),
    new ArtifactRepository($pdo),
    new MembershipRepository($pdo),
    new AiContentService($fakeAi),
);
$aiQuizId = $aiQuizService->generateForSource($sourceId, $alice['id'], 'student');
$aiQuizArtifact = (new ArtifactRepository($pdo))->find($aiQuizId);
check('M3', 'Ollama-generated quiz keeps grounded provenance',
    str_starts_with((string) ($aiQuizArtifact['content']['generator'] ?? ''), 'ollama:')
    && (int) ($aiQuizArtifact['content']['questions'][0]['source_refs'][0]['chunk_id'] ?? 0) === $firstChunkId,
    json_encode($aiQuizArtifact['content']['generator'] ?? null));

$fakeAi->setChatResponse(json_encode([
    'steps' => [[
        'title'      => 'Check the site',
        'body'       => 'Complete a hazard assessment before the field test.',
        'key_points' => ['hazard', 'assessment'],
        'source_refs' => [['chunk_id' => $firstChunkId]],
    ]],
]));
$aiLessonService = new LessonService(
    new KnowledgeRepository($pdo, null),
    new ArtifactRepository($pdo),
    new MembershipRepository($pdo),
    new AiContentService($fakeAi),
);
$aiLessonId = $aiLessonService->generateForSource($sourceId, $alice['id'], 'student');
$aiLessonArtifact = (new ArtifactRepository($pdo))->find($aiLessonId);
check('M4', 'Ollama-generated lesson keeps grounded provenance',
    str_starts_with((string) ($aiLessonArtifact['content']['generator'] ?? ''), 'ollama:')
    && (int) ($aiLessonArtifact['content']['steps'][0]['source_refs'][0]['chunk_id'] ?? 0) === $firstChunkId,
    json_encode($aiLessonArtifact['content']['generator'] ?? null));
$fakeAi->setChatResponse(null);

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
