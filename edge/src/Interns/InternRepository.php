<?php

declare(strict_types=1);

namespace Nico\Edge\Interns;

use Nico\Edge\Support\Database;
use PDO;

final class InternRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** @return list<array<string,mixed>> */
    public function interns(): array
    {
        $stmt = $this->pdo->query(
            "SELECT u.id, u.email, u.created_at,
                    (SELECT COUNT(*) FROM intern_material_assignments ia WHERE ia.intern_id = u.id) AS material_count
               FROM users u
              WHERE u.role = 'student'
              ORDER BY u.id DESC"
        );

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['material_count'] = (int) $row['material_count'];

            return $row;
        }, $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, email, role, created_at FROM users WHERE id = :id AND role = 'student' LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['id'] = (int) $row['id'];

        return $row;
    }

    public function assignSource(int $internId, int $sourceId, int $adminId): void
    {
        $this->pdo->prepare(
            'INSERT INTO intern_material_assignments (intern_id, source_id, assigned_by, created_at)
             VALUES (:intern, :source, :admin, :now)
             ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by), created_at = VALUES(created_at)'
        )->execute([
            'intern' => $internId,
            'source' => $sourceId,
            'admin'  => $adminId,
            'now'    => gmdate('c'),
        ]);
    }

    public function isAssigned(int $internId, int $sourceId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM intern_material_assignments WHERE intern_id = :intern AND source_id = :source LIMIT 1'
        );
        $stmt->execute(['intern' => $internId, 'source' => $sourceId]);

        return $stmt->fetchColumn() !== false;
    }

    public function isAssignedToAnyIntern(int $sourceId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM intern_material_assignments WHERE source_id = :source LIMIT 1'
        );
        $stmt->execute(['source' => $sourceId]);

        return $stmt->fetchColumn() !== false;
    }

    public function assignArtifact(int $internId, int $artifactId, ?int $adminId): void
    {
        $this->pdo->prepare(
            'INSERT INTO intern_artifact_assignments (intern_id, artifact_id, assigned_by, created_at)
             VALUES (:intern, :artifact, :admin, :now)
             ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by), created_at = VALUES(created_at)'
        )->execute([
            'intern'  => $internId,
            'artifact' => $artifactId,
            'admin'   => $adminId,
            'now'     => gmdate('c'),
        ]);
    }

    /** @return list<int> */
    public function artifactInternIds(int $artifactId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT intern_id FROM intern_artifact_assignments WHERE artifact_id = :artifact ORDER BY intern_id'
        );
        $stmt->execute(['artifact' => $artifactId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string,mixed>> */
    public function assignedSources(int $internId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.title, s.kind, s.body, s.resource_id, ia.created_at AS assigned_at
               FROM intern_material_assignments ia
               JOIN knowledge_sources s ON s.id = ia.source_id
              WHERE ia.intern_id = :intern AND s.status = \'ready\'
              ORDER BY ia.created_at DESC, s.id DESC'
        );
        $stmt->execute(['intern' => $internId]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['resource_id'] = (int) $row['resource_id'];

            return $row;
        }, $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function artifacts(int $internId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.id, a.module, a.type, a.content, a.created_at
               FROM artifacts a
               JOIN knowledge_sources s ON s.resource_id = a.resource_id
               JOIN intern_material_assignments ia ON ia.source_id = s.id
               LEFT JOIN intern_artifact_assignments aa ON aa.artifact_id = a.id
              WHERE ia.intern_id = :intern AND a.is_current = 1 AND a.status = \'ready\'
                AND (aa.id IS NULL OR aa.intern_id = :owner)
              ORDER BY a.id DESC'
        );
        $stmt->execute(['intern' => $internId, 'owner' => $internId]);

        return array_map(self::hydrateArtifact(...), $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function artifact(int $internId, int $artifactId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.id, a.module, a.type, a.content, a.created_at
               FROM artifacts a
               JOIN knowledge_sources s ON s.resource_id = a.resource_id
               JOIN intern_material_assignments ia ON ia.source_id = s.id
               LEFT JOIN intern_artifact_assignments aa ON aa.artifact_id = a.id
              WHERE ia.intern_id = :intern AND a.id = :artifact
                AND a.is_current = 1 AND a.status = \'ready\'
                AND (aa.id IS NULL OR aa.intern_id = :owner)
              LIMIT 1'
        );
        $stmt->execute(['intern' => $internId, 'artifact' => $artifactId, 'owner' => $internId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrateArtifact($row);
    }

    /** @return list<array<string,mixed>> */
    public function progress(int $internId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT lp.artifact_id, lp.position, lp.completed, lp.updated_at,
                    JSON_UNQUOTE(JSON_EXTRACT(a.content, \'$.title\')) AS title
               FROM learning_progress lp
               JOIN artifacts a ON a.id = lp.artifact_id
               JOIN knowledge_sources s ON s.resource_id = a.resource_id
               JOIN intern_material_assignments ia ON ia.source_id = s.id
              WHERE lp.user_id = :owner AND ia.intern_id = :intern
              ORDER BY lp.updated_at DESC'
        );
        $stmt->execute(['owner' => $internId, 'intern' => $internId]);

        return array_map(static function (array $row): array {
            $row['artifact_id'] = (int) $row['artifact_id'];
            $row['position'] = (int) $row['position'];
            $row['completed'] = (bool) $row['completed'];

            return $row;
        }, $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function attempts(int $internId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT aa.id, aa.artifact_id, aa.score, aa.total, aa.created_at,
                    JSON_UNQUOTE(JSON_EXTRACT(a.content, \'$.title\')) AS title
               FROM assessment_attempts aa
               JOIN artifacts a ON a.id = aa.artifact_id
               JOIN knowledge_sources s ON s.resource_id = a.resource_id
               JOIN intern_material_assignments ia ON ia.source_id = s.id
               WHERE aa.user_id = :owner AND ia.intern_id = :intern AND aa.status = \'completed\'
               ORDER BY aa.created_at DESC'
        );
        $stmt->execute(['owner' => $internId, 'intern' => $internId]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['artifact_id'] = (int) $row['artifact_id'];
            $row['score'] = $row['score'] === null ? null : (int) $row['score'];
            $row['total'] = (int) $row['total'];

            return $row;
        }, $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function allMaterials(): array
    {
        $stmt = $this->pdo->query(
            'SELECT s.id, s.title, s.kind, s.resource_id, s.created_at,
                    GROUP_CONCAT(u.email ORDER BY u.email SEPARATOR ", ") AS interns,
                    COUNT(DISTINCT ia.intern_id) AS intern_count
               FROM knowledge_sources s
               JOIN intern_material_assignments ia ON ia.source_id = s.id
               JOIN users u ON u.id = ia.intern_id
              WHERE s.status = \'ready\'
              GROUP BY s.id
              ORDER BY s.id DESC'
        );

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['resource_id'] = (int) $row['resource_id'];
            $row['intern_count'] = (int) $row['intern_count'];

            return $row;
        }, $stmt->fetchAll());
    }

    /** @return list<int> */
    public function assignedInternIds(int $sourceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT intern_id FROM intern_material_assignments WHERE source_id = :source ORDER BY intern_id'
        );
        $stmt->execute(['source' => $sourceId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<int> $internIds */
    public function replaceAssignments(int $sourceId, array $internIds, int $adminId): void
    {
        $valid = [];
        foreach (array_unique(array_map('intval', $internIds)) as $internId) {
            if ($this->find($internId) !== null) {
                $valid[] = $internId;
            }
        }

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->pdo->prepare('DELETE FROM intern_material_assignments WHERE source_id = :source')
                ->execute(['source' => $sourceId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO intern_material_assignments (intern_id, source_id, assigned_by, created_at)
                 VALUES (:intern, :source, :admin, :now)'
            );
            $now = gmdate('c');
            foreach ($valid as $internId) {
                $insert->execute([
                    'intern' => $internId,
                    'source' => $sourceId,
                    'admin'  => $adminId,
                    'now'    => $now,
                ]);
            }

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function artifactsForSource(int $sourceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.id, a.type, a.content, a.created_at,
                    (SELECT GROUP_CONCAT(u.email ORDER BY u.email SEPARATOR ", ")
                       FROM intern_artifact_assignments aa
                       JOIN users u ON u.id = aa.intern_id
                      WHERE aa.artifact_id = a.id) AS exclusive_interns
               FROM artifacts a
               JOIN knowledge_sources s ON s.resource_id = a.resource_id
              WHERE s.id = :source AND a.is_current = 1 AND a.status = \'ready\'
              ORDER BY a.id DESC'
        );
        $stmt->execute(['source' => $sourceId]);

        return array_map(self::hydrateArtifact(...), $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function artifactForAdmin(int $artifactId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, type, content, created_at FROM artifacts
              WHERE id = :id AND is_current = 1 AND status = \'ready\' LIMIT 1'
        );
        $stmt->execute(['id' => $artifactId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrateArtifact($row);
    }

    /** @param array<string,mixed> $row */
    private static function hydrateArtifact(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $decoded = json_decode((string) ($row['content'] ?? ''), true);
        $row['content'] = is_array($decoded) ? $decoded : [];
        $row['scope'] = empty($row['exclusive_interns'] ?? '') ? 'shared' : 'per_intern';

        return $row;
    }
}
