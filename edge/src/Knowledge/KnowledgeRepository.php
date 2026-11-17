<?php

declare(strict_types=1);

namespace Nico\Edge\Knowledge;

use Nico\Edge\Projects\MembershipRepository;
use Nico\Edge\Support\Config;
use Nico\Edge\Support\Database;
use PDO;
use Throwable;

/**
 * M2 knowledge substrate.
 *
 * Ingestion (M1) is out of scope for now, so knowledge enters as authored text
 * notes/briefs. Each source is chunked and stored on the edge; retrieval is a
 * transparent lexical ranker with provenance. The interface is intentionally the
 * same shape a vector-backed implementation will expose later.
 */
final class KnowledgeRepository
{
    public const KINDS = ['note', 'brief', 'transcript', 'document'];

    private const STOPWORDS = [
        'the', 'and', 'for', 'that', 'with', 'this', 'from', 'are', 'was', 'were',
        'have', 'has', 'had', 'not', 'but', 'you', 'your', 'they', 'their', 'them',
        'will', 'would', 'can', 'could', 'should', 'into', 'about', 'than', 'then',
        'when', 'where', 'which', 'what', 'who', 'how', 'why', 'its', 'it', 'is',
        'of', 'to', 'in', 'on', 'at', 'as', 'by', 'or', 'an', 'a', 'be', 'we',
        'our', 'us', 'i', 'my', 'me', 'he', 'she', 'his', 'her', 'also', 'more',
    ];

    private PDO $pdo;
    private ?EmbeddingIndexer $indexer;

    public function __construct(?PDO $pdo = null, ?EmbeddingIndexer $indexer = null)
    {
        $this->pdo = $pdo ?? Database::pdo();

        if ($indexer === null && Config::bool('OLLAMA_ENABLED', false)) {
            $indexer = new EmbeddingIndexer($this->pdo);
        }

        $this->indexer = $indexer;
    }

    /**
     * Stores an authored text source plus its resource row and chunks.
     *
     * @return array{source_id:int,resource_id:int,chunks:int}
     */
    public function createSource(
        int $userId,
        string $role,
        string $title,
        string $body,
        string $kind = 'note',
        ?int $projectId = null,
    ): array {
        return $this->insertSource($userId, $role, $title, $body, $kind, $projectId, 'text', null, null);
    }

    /**
     * Stores a PDF-backed source and its raw file row in one transaction.
     *
     * @param array{original_name:string,path:string,mime:string,bytes:int,sha256:string} $file
     * @param list<array{page:int,text:string}>|null $segments
     * @return array{source_id:int,resource_id:int,chunks:int}
     */
    public function createIngestedSource(
        int $userId,
        string $role,
        string $title,
        string $body,
        ?int $projectId,
        array $file,
        ?array $segments = null,
    ): array {
        return $this->insertSource($userId, $role, $title, $body, 'document', $projectId, 'pdf', $file, $segments);
    }

    /** @return list<array<string,mixed>> */
    public function filesForResource(int $resourceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, resource_id, kind, path, mime, bytes, sha256, original_name, created_at
               FROM files WHERE resource_id = :resource ORDER BY id DESC'
        );
        $stmt->execute(['resource' => $resourceId]);

        return array_map(self::hydrateFile(...), $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function rawFileForResource(int $resourceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, resource_id, kind, path, mime, bytes, sha256, original_name, created_at
               FROM files
              WHERE resource_id = :resource AND kind = \'raw\'
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['resource' => $resourceId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrateFile($row);
    }

    /**
     * @param array{original_name:string,path:string,mime:string,bytes:int,sha256:string}|null $file
     * @param list<array{page:int,text:string}>|null $segments
     * @return array{source_id:int,resource_id:int,chunks:int}
     */
    private function insertSource(
        int $userId,
        string $role,
        string $title,
        string $body,
        string $kind,
        ?int $projectId,
        string $resourceType,
        ?array $file,
        ?array $segments,
    ): array {
        if ($segments !== null) {
            $parts = [];
            foreach ($segments as $segment) {
                $text = trim((string) ($segment['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            $body = implode("\n\n", $parts);
        }

        $title = trim($title);
        $body = trim($body);

        if ($title === '' || strlen($title) > 255) {
            throw new \InvalidArgumentException('Source title must be 1-255 characters.');
        }

        if ($body === '') {
            throw new \InvalidArgumentException('Source body cannot be empty.');
        }

        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown source kind: {$kind}");
        }

        if ($projectId !== null) {
            $memberships = new MembershipRepository($this->pdo);
            if (!$memberships->canView($projectId, $userId, $role)) {
                throw new \InvalidArgumentException('You are not a member of that project.');
            }
        }

        $now = gmdate('c');
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO resources (user_id, title, source_type, status, created_at)
                 VALUES (:user, :title, :type, \'done\', :now)'
            )->execute(['user' => $userId, 'title' => $title, 'type' => $resourceType, 'now' => $now]);

            $resourceId = (int) $this->pdo->lastInsertId();

            if ($file !== null) {
                $this->pdo->prepare(
                    'INSERT INTO files
                        (resource_id, kind, path, mime, bytes, sha256, original_name, created_at)
                     VALUES
                        (:resource, \'raw\', :path, :mime, :bytes, :sha, :name, :now)'
                )->execute([
                    'resource' => $resourceId,
                    'path'     => $file['path'],
                    'mime'     => $file['mime'],
                    'bytes'    => $file['bytes'],
                    'sha'      => $file['sha256'],
                    'name'     => $file['original_name'],
                    'now'      => $now,
                ]);
            }

            $this->pdo->prepare(
                'INSERT INTO knowledge_sources
                    (user_id, project_id, resource_id, title, kind, body, status, created_at, updated_at)
                 VALUES
                    (:user, :project, :resource, :title, :kind, :body, \'ready\', :now, :now_updated)'
            )->execute([
                'user'         => $userId,
                'project'      => $projectId,
                'resource'     => $resourceId,
                'title'        => $title,
                'kind'         => $kind,
                'body'         => $body,
                'now'          => $now,
                'now_updated'  => $now,
            ]);

            $sourceId = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare(
                'INSERT INTO knowledge_chunks
                    (source_id, ordinal, page, text, token_count, content_hash, created_at)
                 VALUES (:source, :ordinal, :page, :text, :tokens, :hash, :now)'
            );

            $chunkRows = [];
            $ordinal = 0;
            $pageSegments = $segments ?? [['page' => null, 'text' => $body]];

            foreach ($pageSegments as $segment) {
                $page = $segment['page'] ?? null;
                $page = $page === null ? null : (int) $page;

                foreach (self::chunk((string) ($segment['text'] ?? '')) as $piece) {
                    $insert->execute([
                        'source'  => $sourceId,
                        'ordinal' => $ordinal,
                        'page'    => $page,
                        'text'    => $piece,
                        'tokens'  => str_word_count($piece),
                        'hash'    => hash('sha256', $piece),
                        'now'     => $now,
                    ]);

                    $chunkRows[] = [
                        'id'          => (int) $this->pdo->lastInsertId(),
                        'source_id'   => $sourceId,
                        'resource_id' => $resourceId,
                        'ordinal'     => $ordinal,
                        'page'        => $page,
                        'text'        => $piece,
                    ];
                    $ordinal++;
                }
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            if ($this->indexer !== null && $chunkRows !== []) {
                try {
                    $this->indexer->index($sourceId, $resourceId, $chunkRows);
                } catch (Throwable $e) {
                    \Nico\Edge\Support\Log::error('embedding.index_failed', [
                        'source_id' => $sourceId,
                        'message'   => $e->getMessage(),
                    ]);
                }
            }

            return ['source_id' => $sourceId, 'resource_id' => $resourceId, 'chunks' => count($chunkRows)];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function findSource(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, p.title AS project_title
               FROM knowledge_sources s
               LEFT JOIN projects p ON p.id = s.project_id
              WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    public function canViewSource(int $sourceId, int $userId, string $role): bool
    {
        $source = $this->findSource($sourceId);

        if ($source === null) {
            return false;
        }

        if ($role === 'admin' || (int) $source['user_id'] === $userId) {
            return true;
        }

        if ($source['project_id'] !== null) {
            return (new MembershipRepository($this->pdo))
                ->canView((int) $source['project_id'], $userId, $role);
        }

        return false;
    }

    /** @return list<array<string,mixed>> */
    public function listSources(int $userId, string $role, ?int $projectId = null): array
    {
        $sql = 'SELECT s.*, p.title AS project_title, u.email AS owner_email
                  FROM knowledge_sources s
                  LEFT JOIN projects p ON p.id = s.project_id
                  JOIN users u ON u.id = s.user_id
                 WHERE s.status = \'ready\'';
        $params = [];

        if ($role !== 'admin') {
            $sql .= ' AND (s.user_id = :user
                           OR (s.project_id IS NOT NULL AND EXISTS (
                                 SELECT 1 FROM project_members pm
                                  WHERE pm.project_id = s.project_id AND pm.user_id = :member)))';
            $params['user'] = $userId;
            $params['member'] = $userId;
        }

        if ($projectId !== null) {
            $sql .= ' AND s.project_id = :project';
            $params['project'] = $projectId;
        }

        $sql .= ' ORDER BY s.created_at DESC, s.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function chunks(int $sourceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, source_id, ordinal, page, text, token_count, content_hash
               FROM knowledge_chunks WHERE source_id = :source ORDER BY ordinal ASC'
        );
        $stmt->execute(['source' => $sourceId]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['source_id'] = (int) $row['source_id'];
            $row['ordinal'] = (int) $row['ordinal'];
            $row['page'] = $row['page'] === null ? null : (int) $row['page'];
            $row['token_count'] = (int) $row['token_count'];

            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * Retrieves ranked chunks for a query within an authorized scope.
     *
     * @param list<int>|null $sourceIds
     * @return list<array<string,mixed>>
     */
    public function search(
        string $query,
        int $userId,
        string $role,
        ?int $projectId = null,
        ?int $sourceId = null,
        int $limit = 5,
        ?array $sourceIds = null,
    ): array {
        if ($sourceId !== null && !$this->canViewSource($sourceId, $userId, $role)) {
            throw new \InvalidArgumentException('You cannot read that source.');
        }

        if ($projectId !== null) {
            if (!(new MembershipRepository($this->pdo))->canView($projectId, $userId, $role)) {
                throw new \InvalidArgumentException('You cannot read that project.');
            }
        }

        $authorizedSourceIds = [];
        if ($sourceIds !== null) {
            foreach ($sourceIds as $candidate) {
                $candidate = (int) $candidate;
                if ($candidate <= 0 || isset($authorizedSourceIds[$candidate])) {
                    continue;
                }
                if (!$this->canViewSource($candidate, $userId, $role)) {
                    throw new \InvalidArgumentException('You cannot read one of those sources.');
                }
                $authorizedSourceIds[$candidate] = $candidate;
            }

            if ($authorizedSourceIds === []) {
                return [];
            }
        }

        $sql = 'SELECT c.id, c.source_id, c.ordinal, c.page, c.text, c.token_count,
                       s.title AS source_title, s.resource_id, s.project_id
                  FROM knowledge_chunks c
                  JOIN knowledge_sources s ON s.id = c.source_id
                 WHERE s.status = \'ready\'';
        $params = [];

        if ($role !== 'admin') {
            $sql .= ' AND (s.user_id = :user
                           OR (s.project_id IS NOT NULL AND EXISTS (
                                 SELECT 1 FROM project_members pm
                                  WHERE pm.project_id = s.project_id AND pm.user_id = :member)))';
            $params['user'] = $userId;
            $params['member'] = $userId;
        }

        if ($projectId !== null) {
            $sql .= ' AND s.project_id = :project';
            $params['project'] = $projectId;
        }

        if ($sourceId !== null) {
            $sql .= ' AND s.id = :source';
            $params['source'] = $sourceId;
        }

        if ($authorizedSourceIds !== []) {
            $placeholders = [];
            foreach (array_values($authorizedSourceIds) as $index => $id) {
                $name = 'list' . $index;
                $placeholders[] = ':' . $name;
                $params[$name] = $id;
            }
            $sql .= ' AND s.id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY c.id DESC LIMIT 500';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $terms = self::queryTerms($query);
        $phrase = strtolower(trim($query));
        $limit = max(1, min(50, $limit));

        foreach ($rows as &$row) {
            $haystack = strtolower($row['text'] . ' ' . $row['source_title']);
            $score = 0;

            foreach ($terms as $term) {
                $count = substr_count($haystack, $term);
                if ($count > 0) {
                    $score += $count;
                    $score += 2 * substr_count(strtolower((string) $row['source_title']), $term);
                }
            }

            if ($phrase !== '' && str_contains($haystack, $phrase)) {
                $score += 5;
            }

            $row['score'] = $score;
            $row['id'] = (int) $row['id'];
            $row['source_id'] = (int) $row['source_id'];
            $row['ordinal'] = (int) $row['ordinal'];
            $row['page'] = $row['page'] === null ? null : (int) $row['page'];
            $row['token_count'] = (int) $row['token_count'];
            $row['resource_id'] = (int) $row['resource_id'];
            $row['project_id'] = $row['project_id'] === null ? null : (int) $row['project_id'];
        }
        unset($row);

        usort($rows, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['id'] <=> $b['id'];
        });

        if ($terms !== []) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['score'] > 0,
            ));
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * All current chunks for a project, ordered for generation (no auth check;
     * callers must authorize the project first).
     *
     * @return list<array<string,mixed>>
     */
    public function chunksForProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.source_id, c.ordinal, c.page, c.text, s.title AS source_title,
                    s.resource_id, s.project_id
               FROM knowledge_chunks c
               JOIN knowledge_sources s ON s.id = c.source_id
              WHERE s.project_id = :project AND s.status = \'ready\'
              ORDER BY s.created_at ASC, s.id ASC, c.ordinal ASC'
        );
        $stmt->execute(['project' => $projectId]);

        return self::hydrateChunks($stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function chunksForSource(int $sourceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.source_id, c.ordinal, c.page, c.text, s.title AS source_title,
                    s.resource_id, s.project_id
               FROM knowledge_chunks c
               JOIN knowledge_sources s ON s.id = c.source_id
              WHERE s.id = :source AND s.status = \'ready\'
              ORDER BY c.ordinal ASC'
        );
        $stmt->execute(['source' => $sourceId]);

        return self::hydrateChunks($stmt->fetchAll());
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function hydrateChunks(array $rows): array
    {
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['source_id'] = (int) $row['source_id'];
            $row['ordinal'] = (int) $row['ordinal'];
            $row['page'] = $row['page'] === null ? null : (int) $row['page'];
            $row['resource_id'] = (int) $row['resource_id'];
            $row['project_id'] = $row['project_id'] === null ? null : (int) $row['project_id'];

            return $row;
        }, $rows);
    }

    public static function chunk(string $text, int $targetChars = 700): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return [];
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];
        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '') {
                continue;
            }

            if (strlen($sentence) > $targetChars * 2) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                foreach (self::splitLong($sentence, $targetChars) as $piece) {
                    $chunks[] = $piece;
                }

                continue;
            }

            if ($current !== '' && strlen($current) + strlen($sentence) + 1 > $targetChars) {
                $chunks[] = $current;
                $current = '';
            }

            $current .= ($current === '' ? '' : ' ') . $sentence;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter($chunks, static fn (string $c): bool => trim($c) !== ''));
    }

    /** @return list<string> */
    private static function splitLong(string $sentence, int $targetChars): array
    {
        $words = preg_split('/\s+/u', $sentence) ?: [];
        $pieces = [];
        $current = '';

        foreach ($words as $word) {
            if ($current !== '' && strlen($current) + strlen($word) + 1 > $targetChars) {
                $pieces[] = $current;
                $current = '';
            }

            $current .= ($current === '' ? '' : ' ') . $word;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /** @return list<string> unique lowercase query terms */
    public static function queryTerms(string $query): array
    {
        $words = preg_split('/[^a-z0-9]+/i', strtolower($query)) ?: [];
        $terms = [];

        foreach ($words as $word) {
            if (strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $terms[$word] = true;
        }

        return array_keys($terms);
    }

    /** @return list<string> most frequent meaningful words */
    public static function keywords(string $text, int $limit = 5): array
    {
        $words = preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [];
        $counts = [];

        foreach ($words as $word) {
            if (strlen($word) < 4 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }

        arsort($counts);

        return array_slice(array_keys($counts), 0, $limit);
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['resource_id'] = (int) $row['resource_id'];
        $row['project_id'] = $row['project_id'] === null ? null : (int) $row['project_id'];

        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function hydrateFile(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['resource_id'] = (int) $row['resource_id'];
        $row['bytes'] = (int) $row['bytes'];

        return $row;
    }
}
