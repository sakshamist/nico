<?php

declare(strict_types=1);

namespace Nico\Edge\Interns;

use Nico\Edge\Support\Database;
use PDO;

final class InternProjectRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** @return list<array<string,mixed>> */
    public function adminProjects(): array
    {
        return $this->pdo->query(
            'SELECT p.id, p.title, p.kind, p.mode, p.status, p.created_at,
                    (SELECT COUNT(*) FROM project_members pm WHERE pm.project_id = p.id) AS member_count,
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) AS task_count
               FROM projects p
              ORDER BY p.id DESC'
        )->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function internProjects(int $internId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.title, p.kind, p.mode, p.status, p.description, pm.role AS membership_role
               FROM projects p
               JOIN project_members pm ON pm.project_id = p.id
              WHERE pm.user_id = :intern
              ORDER BY p.id DESC'
        );
        $stmt->execute(['intern' => $internId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function project(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, description, kind, mode, status FROM projects WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function members(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.email, pm.role
               FROM project_members pm
               JOIN users u ON u.id = pm.user_id
              WHERE pm.project_id = :project
              ORDER BY u.email'
        );
        $stmt->execute(['project' => $projectId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function tasks(int $projectId, ?int $internId = null): array
    {
        $sql = 'SELECT t.id, t.title, t.status, t.milestone, t.due_at, t.assignee_id, u.email AS assignee_email
                  FROM tasks t
                  LEFT JOIN users u ON u.id = t.assignee_id
                 WHERE t.project_id = :project';
        $params = ['project' => $projectId];

        if ($internId !== null) {
            $sql .= ' AND t.assignee_id = :intern';
            $params['intern'] = $internId;
        }

        $sql .= ' ORDER BY t.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['assignee_id'] = $row['assignee_id'] === null ? null : (int) $row['assignee_id'];

            return $row;
        }, $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function activities(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, kind, title, status, starts_at FROM activities
              WHERE project_id = :project ORDER BY id DESC'
        );
        $stmt->execute(['project' => $projectId]);

        return $stmt->fetchAll();
    }
}
