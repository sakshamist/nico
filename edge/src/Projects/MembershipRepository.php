<?php

declare(strict_types=1);

namespace Nico\Edge\Projects;

use Nico\Edge\Support\Database;
use PDO;

/**
 * Project membership and role checks.
 *
 * Roles are hierarchical for management: owner and organizer manage membership
 * and tasks; members may view and work. A platform admin may do anything, which
 * mirrors UserRepository's resource-ownership exception.
 */
final class MembershipRepository
{
    public const ROLES = ['owner', 'organizer', 'member'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    public function add(int $projectId, int $userId, string $role = 'member'): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("Unknown membership role: {$role}");
        }

        $this->pdo->prepare(
            'INSERT INTO project_members (project_id, user_id, role, created_at)
             VALUES (:project, :user, :role, :now)
             ON DUPLICATE KEY UPDATE role = VALUES(role)'
        )->execute([
            'project' => $projectId,
            'user'    => $userId,
            'role'    => $role,
            'now'     => gmdate('c'),
        ]);
    }

    public function roleOf(int $projectId, int $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT role FROM project_members WHERE project_id = :project AND user_id = :user LIMIT 1'
        );
        $stmt->execute(['project' => $projectId, 'user' => $userId]);

        $role = $stmt->fetchColumn();

        return $role === false ? null : (string) $role;
    }

    public function isMember(int $projectId, int $userId): bool
    {
        return $this->roleOf($projectId, $userId) !== null;
    }

    public function canView(int $projectId, int $userId, string $platformRole = 'student'): bool
    {
        return $platformRole === 'admin' || $this->isMember($projectId, $userId);
    }

    public function canManage(int $projectId, int $userId, string $platformRole = 'student'): bool
    {
        if ($platformRole === 'admin') {
            return true;
        }

        $role = $this->roleOf($projectId, $userId);

        return in_array($role, ['owner', 'organizer'], true);
    }

    /** @return list<array<string,mixed>> */
    public function members(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.user_id, m.role, u.email
               FROM project_members m
               JOIN users u ON u.id = m.user_id
              WHERE m.project_id = :project
              ORDER BY m.user_id ASC'
        );
        $stmt->execute(['project' => $projectId]);

        return array_map(static function (array $row): array {
            $row['user_id'] = (int) $row['user_id'];

            return $row;
        }, $stmt->fetchAll());
    }
}
