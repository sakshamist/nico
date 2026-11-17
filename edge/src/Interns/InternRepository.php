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
              WHERE ia.intern_id = :intern AND a.is_current = 1 AND a.status = \'ready\'
              ORDER BY a.id DESC'
        );
        $stmt->execute(['intern' => $internId]);

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
              WHERE ia.intern_id = :intern AND a.id = :artifact
                AND a.is_current = 1 AND a.status = \'ready\'
              LIMIT 1'
        );
        $stmt->execute(['intern' => $internId, 'artifact' => $artifactId]);
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

    /** @param array<string,mixed> $row */
    private static function hydrateArtifact(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $decoded = json_decode((string) ($row['content'] ?? ''), true);
        $row['content'] = is_array($decoded) ? $decoded : [];

        return $row;
    }
}
