<?php

declare(strict_types=1);

namespace Nico\Edge\Projects;

use Nico\Edge\Support\Database;
use PDO;
use Throwable;

/**
 * Project workspaces (M0 contract, used by M5/M6/M7).
 *
 * A project is the container for ongoing work: resources, tasks, people,
 * hackathons/events, and the artifacts/reports produced from them. Creating a
 * project also creates its owner membership in the same transaction.
 */
final class ProjectRepository
{
    public const KINDS = ['live', 'general'];

    public const STATUSES = ['active', 'archived', 'completed'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    public function create(
        int $ownerId,
        string $title,
        ?string $description = null,
        string $kind = 'general',
    ): int {
        $title = trim($title);

        if ($title === '' || strlen($title) > 255) {
            throw new \InvalidArgumentException('Project title must be 1-255 characters.');
        }

        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown project kind: {$kind}");
        }

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $now = gmdate('c');

            $insert = $this->pdo->prepare(
                'INSERT INTO projects (owner_id, title, description, kind, status, created_at, updated_at)
                 VALUES (:owner, :title, :description, :kind, \'active\', :now, :now_updated)'
            );
            $insert->execute([
                'owner'       => $ownerId,
                'title'       => $title,
                'description' => $description,
                'kind'        => $kind,
                'now'         => $now,
                'now_updated' => $now,
            ]);

            $projectId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                'INSERT INTO project_members (project_id, user_id, role, created_at)
                 VALUES (:project, :user, \'owner\', :now)'
            )->execute(['project' => $projectId, 'user' => $ownerId, 'now' => $now]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $projectId;
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
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, m.role AS membership_role
               FROM projects p
               JOIN project_members m ON m.project_id = p.id
              WHERE m.user_id = :user
              ORDER BY p.created_at DESC, p.id DESC'
        );
        $stmt->execute(['user' => $userId]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function updateStatus(int $id, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown project status: {$status}");
        }

        $this->pdo->prepare('UPDATE projects SET status = :status, updated_at = :now WHERE id = :id')
            ->execute(['status' => $status, 'now' => gmdate('c'), 'id' => $id]);
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['owner_id'] = (int) $row['owner_id'];
        $row['needs_attention'] = (bool) $row['needs_attention'];

        return $row;
    }
}
