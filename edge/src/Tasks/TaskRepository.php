<?php

declare(strict_types=1);

namespace Nico\Edge\Tasks;

use Nico\Edge\Support\Database;
use PDO;
use Throwable;

/**
 * Project tasks and their assignment history.
 *
 * A task has one assignee in the MVP; team tasks arrive with M6. Every change of
 * assignee is written to task_assignment_log so automatic decisions and manual
 * overrides are both auditable.
 */
final class TaskRepository
{
    public const STATUSES = ['todo', 'in_progress', 'blocked', 'review', 'done', 'cancelled'];

    public const ASSIGNMENT_STATES = ['unassigned', 'suggested', 'assigned', 'needs_human'];

    public const ACTIONS = ['auto', 'manual', 'release', 'complete'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** @param array<string,mixed>|null $requirements */
    public function create(
        int $projectId,
        string $title,
        ?string $description = null,
        ?array $requirements = null,
        int $priority = 3,
        ?string $dueAt = null,
        ?int $createdBy = null,
        ?int $artifactId = null,
        ?string $milestone = null,
        ?int $dependsOnTaskId = null,
    ): int {
        $title = trim($title);

        if ($title === '' || strlen($title) > 255) {
            throw new \InvalidArgumentException('Task title must be 1-255 characters.');
        }

        if ($priority < 1 || $priority > 5) {
            throw new \InvalidArgumentException('Priority must be between 1 and 5.');
        }

        if ($artifactId !== null) {
            $linked = $this->pdo->prepare(
                'SELECT 1 FROM artifacts
                  WHERE id = :artifact AND project_id = :project AND is_current = 1 LIMIT 1'
            );
            $linked->execute(['artifact' => $artifactId, 'project' => $projectId]);

            if ($linked->fetchColumn() === false) {
                throw new \InvalidArgumentException('Linked artifact must be a current artifact in this project.');
            }
        }

        if ($milestone !== null) {
            $milestone = trim($milestone);

            if ($milestone === '') {
                $milestone = null;
            } elseif (strlen($milestone) > 120) {
                throw new \InvalidArgumentException('Milestone must be 120 characters or fewer.');
            }
        }

        if ($dependsOnTaskId !== null) {
            $dependency = $this->pdo->prepare(
                'SELECT 1 FROM tasks WHERE id = :dependency AND project_id = :project LIMIT 1'
            );
            $dependency->execute(['dependency' => $dependsOnTaskId, 'project' => $projectId]);

            if ($dependency->fetchColumn() === false) {
                throw new \InvalidArgumentException('Dependency must be a task in this project.');
            }
        }

        $now = gmdate('c');

        $this->pdo->prepare(
            'INSERT INTO tasks
                (project_id, title, description, requirements, priority, status,
                 assignment_state, due_at, artifact_id, milestone, depends_on_task_id,
                 created_by, created_at, updated_at)
             VALUES
                (:project, :title, :description, :requirements, :priority, \'todo\',
                 \'unassigned\', :due_at, :artifact, :milestone, :depends_on,
                 :created_by, :now, :now_updated)'
        )->execute([
            'project'      => $projectId,
            'title'        => $title,
            'description'  => $description,
            'requirements' => $requirements === null
                ? null
                : json_encode($requirements, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'priority'     => $priority,
            'due_at'       => $dueAt === null ? null : self::normalizeDateTime($dueAt),
            'artifact'     => $artifactId,
            'milestone'    => $milestone,
            'depends_on'   => $dependsOnTaskId,
            'created_by'   => $createdBy,
            'now'          => $now,
            'now_updated'  => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<array<string,mixed>> */
    public function listByProject(int $projectId, ?string $status = null): array
    {
        $sql = 'SELECT * FROM tasks WHERE project_id = :project';
        $params = ['project' => $projectId];

        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY priority ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function updateStatus(int $id, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown task status: {$status}");
        }

        $this->pdo->prepare('UPDATE tasks SET status = :status, updated_at = :now WHERE id = :id')
            ->execute(['status' => $status, 'now' => gmdate('c'), 'id' => $id]);
    }

    /**
     * Assigns (or releases) a task and appends an audit record in one transaction.
     *
     * @param array<string,mixed> $rationale
     * @return array{from_user_id:?int,to_user_id:?int,assignment_state:string}
     */
    public function assign(
        int $taskId,
        ?int $userId,
        ?int $actorId,
        string $action,
        array $rationale = [],
        string $assignmentState = 'assigned',
    ): array {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown assignment action: {$action}");
        }

        if (!in_array($assignmentState, self::ASSIGNMENT_STATES, true)) {
            throw new \InvalidArgumentException("Unknown assignment state: {$assignmentState}");
        }

        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $lock = $this->pdo->prepare('SELECT id, assignee_id FROM tasks WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $taskId]);
            $task = $lock->fetch();

            if ($task === false) {
                throw new \InvalidArgumentException("Unknown task: {$taskId}");
            }

            $from = $task['assignee_id'] === null ? null : (int) $task['assignee_id'];
            $now = gmdate('c');

            $this->pdo->prepare(
                'UPDATE tasks SET assignee_id = :user, assignment_state = :state, updated_at = :now
                  WHERE id = :id'
            )->execute([
                'user'  => $userId,
                'state' => $assignmentState,
                'now'   => $now,
                'id'    => $taskId,
            ]);

            $this->pdo->prepare(
                'INSERT INTO task_assignment_log
                    (task_id, from_user_id, to_user_id, action, rationale, actor_id, created_at)
                 VALUES (:task, :from, :to, :action, :rationale, :actor, :now)'
            )->execute([
                'task'      => $taskId,
                'from'      => $from,
                'to'        => $userId,
                'action'    => $action,
                'rationale' => $rationale === []
                    ? null
                    : json_encode($rationale, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'actor'     => $actorId,
                'now'       => $now,
            ]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return [
                'from_user_id'     => $from,
                'to_user_id'       => $userId,
                'assignment_state' => $assignmentState,
            ];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function openTaskCount(int $userId): int
    {
        return $this->openTaskCounts([$userId])[$userId] ?? 0;
    }

    /**
     * @param list<int> $userIds
     * @return array<int,int>
     */
    public function openTaskCounts(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', $userIds));

        $rows = $this->pdo->query(
            "SELECT assignee_id, COUNT(*) AS open_count
               FROM tasks
              WHERE assignee_id IN ({$ids})
                AND status NOT IN ('done', 'cancelled')
              GROUP BY assignee_id"
        )->fetchAll();

        $counts = array_fill_keys(array_map('intval', $userIds), 0);
        foreach ($rows as $row) {
            $counts[(int) $row['assignee_id']] = (int) $row['open_count'];
        }

        return $counts;
    }

    /**
     * Most recent auto/manual assignment per user, used for fairness tie-breaks.
     * ISO-8601 UTC strings sort chronologically as text.
     *
     * @param list<int> $userIds
     * @return array<int,?string>
     */
    public function lastAssignedAt(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', $userIds));

        $rows = $this->pdo->query(
            "SELECT to_user_id, MAX(created_at) AS last_assigned_at
               FROM task_assignment_log
              WHERE to_user_id IN ({$ids})
                AND action IN ('auto', 'manual')
              GROUP BY to_user_id"
        )->fetchAll();

        $out = array_fill_keys(array_map('intval', $userIds), null);
        foreach ($rows as $row) {
            $out[(int) $row['to_user_id']] = (string) $row['last_assigned_at'];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function assignmentHistory(int $taskId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM task_assignment_log WHERE task_id = :task ORDER BY id ASC'
        );
        $stmt->execute(['task' => $taskId]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['task_id'] = (int) $row['task_id'];
            $row['rationale'] = is_string($row['rationale'] ?? null) && $row['rationale'] !== ''
                ? json_decode((string) $row['rationale'], true)
                : null;

            return $row;
        }, $stmt->fetchAll());
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['project_id'] = (int) $row['project_id'];
        $row['priority'] = (int) $row['priority'];
        $row['assignee_id'] = $row['assignee_id'] === null ? null : (int) $row['assignee_id'];
        $row['artifact_id'] = isset($row['artifact_id']) && $row['artifact_id'] !== null
            ? (int) $row['artifact_id']
            : null;
        $row['depends_on_task_id'] = isset($row['depends_on_task_id']) && $row['depends_on_task_id'] !== null
            ? (int) $row['depends_on_task_id']
            : null;
        $row['created_by'] = $row['created_by'] === null ? null : (int) $row['created_by'];
        $row['requirements'] = is_string($row['requirements'] ?? null) && $row['requirements'] !== ''
            ? json_decode((string) $row['requirements'], true)
            : null;

        return $row;
    }

    /** Accepts ISO-8601 (with or without zone) and stores UTC DATETIME text. */
    private static function normalizeDateTime(string $value): string
    {
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Invalid date/time: {$value}");
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
