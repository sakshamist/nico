<?php

declare(strict_types=1);

namespace Nico\Edge\Artifacts;

use Nico\Edge\Support\Database;
use PDO;
use Throwable;

/**
 * The common artifact envelope (M0 contract).
 *
 * Modules emit typed, versioned records instead of ad-hoc blobs so M7 can compose
 * and M8 can render them uniformly. Rows are immutable: a new emission supersedes
 * the previous current row for the same (project|resource, module, type) instead
 * of overwriting it, preserving provenance for reports.
 */
final class ArtifactRepository
{
    public const MODULES = ['M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8'];

    public const STATUSES = ['generating', 'ready', 'invalid', 'failed'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /**
     * @param array{
     *   project_id?:?int, resource_id?:?int, module:string, type:string,
     *   schema_version?:int, content?:mixed, provenance?:mixed,
     *   status?:string, created_by?:?int
     * } $data
     */
    public function create(array $data): int
    {
        $module = (string) ($data['module'] ?? '');
        $type = trim((string) ($data['type'] ?? ''));
        $status = (string) ($data['status'] ?? 'ready');

        if (!in_array($module, self::MODULES, true)) {
            throw new \InvalidArgumentException("Unknown artifact module: {$module}");
        }

        if ($type === '' || strlen($type) > 64) {
            throw new \InvalidArgumentException('Artifact type must be 1-64 characters.');
        }

        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown artifact status: {$status}");
        }

        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
        $resourceId = isset($data['resource_id']) ? (int) $data['resource_id'] : null;

        if ($projectId === null && $resourceId === null) {
            throw new \InvalidArgumentException('An artifact needs a project or a resource.');
        }

        $schemaVersion = max(1, (int) ($data['schema_version'] ?? 1));
        $createdBy = isset($data['created_by']) ? (int) $data['created_by'] : null;
        $content = self::encode($data['content'] ?? null);
        $provenance = self::encode($data['provenance'] ?? null);

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // Serialize writers for the same scope so version numbers cannot collide.
            if ($projectId !== null) {
                $lock = $this->pdo->prepare('SELECT id FROM projects WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $projectId]);
            } else {
                $lock = $this->pdo->prepare('SELECT id FROM resources WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $resourceId]);
            }

            $scopeSql = $projectId !== null
                ? 'project_id = :scope AND resource_id IS NULL'
                : 'resource_id = :scope AND project_id IS NULL';
            $scope = $projectId ?? $resourceId;

            $currentStmt = $this->pdo->prepare(
                "SELECT id, version FROM artifacts
                 WHERE {$scopeSql} AND module = :module AND type = :type AND is_current = 1
                 ORDER BY version DESC LIMIT 1 FOR UPDATE"
            );
            $currentStmt->execute([
                'scope'  => $scope,
                'module' => $module,
                'type'   => $type,
            ]);
            $current = $currentStmt->fetch() ?: null;

            $version = $current === null ? 1 : ((int) $current['version']) + 1;
            $supersedes = $current === null ? null : (int) $current['id'];
            $now = gmdate('c');

            if ($current !== null) {
                $supersede = $this->pdo->prepare(
                    'UPDATE artifacts SET is_current = 0, updated_at = :at WHERE id = :id'
                );
                $supersede->execute(['at' => $now, 'id' => $current['id']]);
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO artifacts
                    (project_id, resource_id, module, type, schema_version, version,
                     supersedes_id, is_current, content, provenance, status, created_by,
                     created_at, updated_at)
                 VALUES
                    (:project_id, :resource_id, :module, :type, :schema_version, :version,
                     :supersedes_id, 1, :content, :provenance, :status, :created_by,
                     :created_at, :updated_at)'
            );
            $insert->execute([
                'project_id'     => $projectId,
                'resource_id'    => $resourceId,
                'module'         => $module,
                'type'           => $type,
                'schema_version' => $schemaVersion,
                'version'        => $version,
                'supersedes_id'  => $supersedes,
                'content'        => $content,
                'provenance'     => $provenance,
                'status'         => $status,
                'created_by'     => $createdBy,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            $id = (int) $this->pdo->lastInsertId();

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $id;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artifacts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForProject(int $projectId, ?string $module = null, bool $currentOnly = true): array
    {
        $sql = 'SELECT * FROM artifacts WHERE project_id = :id';
        $params = ['id' => $projectId];

        if ($module !== null) {
            $sql .= ' AND module = :module';
            $params['module'] = $module;
        }

        if ($currentOnly) {
            $sql .= ' AND is_current = 1';
        }

        $sql .= ' ORDER BY id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function listForResource(int $resourceId, ?string $module = null, bool $currentOnly = true): array
    {
        $sql = 'SELECT * FROM artifacts WHERE resource_id = :id';
        $params = ['id' => $resourceId];

        if ($module !== null) {
            $sql .= ' AND module = :module';
            $params['module'] = $module;
        }

        if ($currentOnly) {
            $sql .= ' AND is_current = 1';
        }

        $sql .= ' ORDER BY id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * Current artifacts the user can see: project memberships plus owned
     * resources. Admins see everything.
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(int $userId, string $role, int $limit = 50): array
    {
        $sql = 'SELECT a.*, p.title AS project_title, r.title AS resource_title
                  FROM artifacts a
                  LEFT JOIN projects p ON p.id = a.project_id
                  LEFT JOIN resources r ON r.id = a.resource_id
                 WHERE a.is_current = 1';
        $params = [];

        if ($role !== 'admin') {
            $sql .= ' AND (
                        (a.project_id IS NOT NULL AND a.project_id IN (
                            SELECT project_id FROM project_members WHERE user_id = :project_user))
                        OR (a.resource_id IS NOT NULL AND (
                            a.resource_id IN (
                                SELECT id FROM resources WHERE user_id = :resource_user)
                            OR EXISTS (
                                SELECT 1 FROM knowledge_sources s
                                JOIN project_members pm ON pm.project_id = s.project_id
                                WHERE s.resource_id = a.resource_id AND pm.user_id = :source_member)
                        ))
                      )';
            $params['project_user'] = $userId;
            $params['resource_user'] = $userId;
            $params['source_member'] = $userId;
        }

        $sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . max(1, min(200, $limit));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        $row['content'] = self::decode($row['content'] ?? null);
        $row['provenance'] = self::decode($row['provenance'] ?? null);
        $row['schema_version'] = (int) $row['schema_version'];
        $row['version'] = (int) $row['version'];
        $row['is_current'] = (bool) $row['is_current'];

        return $row;
    }

    private static function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function decode(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return $decoded ?? $value;
    }
}
