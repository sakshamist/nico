<?php

declare(strict_types=1);

namespace Nico\Edge\Progress;

use Nico\Edge\Support\Database;
use PDO;

/**
 * M8 resume state for click-next lessons and quizzes.
 */
final class ProgressRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** @return array{position:int,completed:bool,updated_at:string}|null */
    public function get(int $userId, int $artifactId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT position, completed, updated_at FROM learning_progress
              WHERE user_id = :user AND artifact_id = :artifact LIMIT 1'
        );
        $stmt->execute(['user' => $userId, 'artifact' => $artifactId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'position'   => (int) $row['position'],
            'completed'  => (bool) $row['completed'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    public function save(int $userId, int $artifactId, int $position, bool $completed): void
    {
        $this->pdo->prepare(
            'INSERT INTO learning_progress (user_id, artifact_id, position, completed, updated_at)
             VALUES (:user, :artifact, :position, :completed, :now)
             ON DUPLICATE KEY UPDATE
                position = VALUES(position),
                completed = VALUES(completed),
                updated_at = VALUES(updated_at)'
        )->execute([
            'user'      => $userId,
            'artifact'  => $artifactId,
            'position'  => max(0, $position),
            'completed' => $completed ? 1 : 0,
            'now'       => gmdate('c'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT lp.*, a.module, a.type, a.project_id, a.resource_id, a.content
               FROM learning_progress lp
               JOIN artifacts a ON a.id = lp.artifact_id
              WHERE lp.user_id = :user
              ORDER BY lp.updated_at DESC'
        );
        $stmt->execute(['user' => $userId]);

        return array_map(static function (array $row): array {
            $content = is_string($row['content'] ?? null)
                ? (json_decode((string) $row['content'], true) ?: [])
                : [];
            $row['user_id'] = (int) $row['user_id'];
            $row['artifact_id'] = (int) $row['artifact_id'];
            $row['position'] = (int) $row['position'];
            $row['completed'] = (bool) $row['completed'];
            $row['project_id'] = $row['project_id'] === null ? null : (int) $row['project_id'];
            $row['resource_id'] = $row['resource_id'] === null ? null : (int) $row['resource_id'];
            $row['title'] = is_array($content) ? (string) ($content['title'] ?? 'Artifact') : 'Artifact';

            return $row;
        }, $stmt->fetchAll());
    }
}
